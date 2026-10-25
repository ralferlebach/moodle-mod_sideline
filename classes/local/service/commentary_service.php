<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_sideline\local\service;

use mod_sideline\local\ai\ai_gateway;
use mod_sideline\local\ai\persona_registry;
use mod_sideline\local\cache\commentary_cache;
use mod_sideline\local\consent\consent_manager;
use mod_sideline\local\debug\trace_recorder;
use mod_sideline\local\dialog\dialog_manager;
use mod_sideline\local\group\group_service;
use mod_sideline\local\model\recommendation;
use mod_sideline\local\persona\persona_resolver;
use mod_sideline\local\rules\rule_store;

/**
 * Produces the commentary for one user in one Sideline instance (blueprint section C).
 *
 * Order of work: consent gate, persona, scope, state, interview effects, group facts, rules,
 * fingerprint, cache, AI phrasing or templates, rechecks, conditional cache write. PHP decides
 * everything; AI only phrases (P-01 of the system limits). Every privacy check happens before AI
 * generation, before the cache is read and before delivery (blueprint addition 4).
 *
 * The fingerprint is computed from the selected recommendations themselves: they fully determine
 * the text, so any change in state, rights, visibility, answers or group facts that changes what
 * is said changes the fingerprint, with or without an event.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class commentary_service {
    /** @var string Result status: commentary is available. */
    public const STATUS_OK = 'ok';
    /** @var string Result status: the learner has to decide on consent first. */
    public const STATUS_CONSENT_REQUIRED = 'consent_required';

    /** @var ai_gateway AI gateway. */
    private ai_gateway $gateway;

    /**
     * Constructor.
     *
     * @param ai_gateway|null $gateway Gateway, injectable for tests.
     */
    public function __construct(?ai_gateway $gateway = null) {
        $this->gateway = $gateway ?? new ai_gateway();
    }

    /**
     * Get the commentary for a user.
     *
     * @param \stdClass $sideline Instance record.
     * @param \stdClass $course Course record.
     * @param \stdClass $user The learner.
     * @param \context_module $context Module context.
     * @param int $now Current time.
     * @param bool $simulate Compute without writing anything and without AI (administrator debug export).
     * @return array status, consent, commentary (array or null), question (array or null), persona, debug (when simulating).
     */
    public function get(
        \stdClass $sideline,
        \stdClass $course,
        \stdClass $user,
        \context_module $context,
        int $now,
        bool $simulate = false,
    ): array {
        global $DB;

        $userid = (int) $user->id;
        $consent = consent_manager::status($sideline, $userid);

        // Before consent nothing personal is computed: this return happens before any state is read (P-45).
        if (!consent_manager::allows_processing($sideline, $userid)) {
            return ['status' => self::STATUS_CONSENT_REQUIRED, 'consent' => $consent, 'commentary' => null,
                'question' => null, 'persona' => ''];
        }

        $lang = current_language();
        $persona = persona_resolver::for_user($sideline, $userid);

        $cms = (new scope_resolver())->resolve($sideline, $userid);
        $collector = new state_collector();
        $states = $collector->collect($course, $userid, $cms, $now);

        $effects = dialog_manager::effects($sideline, $userid);
        [$groupfacts, $contributors] = (new group_service())->facts($sideline, $userid, $cms, $now);

        $row = commentary_cache::row((int) $sideline->id, $userid);
        $ctx = new evaluation_context(
            effects: $effects,
            coursecomplete: $collector->coursecompletion[(int) $course->id] ?? 'unknown',
            customrules: rule_store::definitions((int) $sideline->id),
            hits: rule_store::hits((int) $sideline->id, $userid, $now),
            currentkeys: commentary_cache::keys_of($row),
            groupfacts: $groupfacts,
        );
        $evaluator = new rule_evaluator();
        $candidates = $evaluator->candidates($states, $now, $ctx);
        $recommendations = $evaluator->select($candidates);
        $nexttransition = $evaluator->next_transition($states, $now);

        $aiallowed = !$simulate && $this->gateway->is_allowed($sideline, $userid, $context, $now);
        $fingerprint = $this->fingerprint($sideline, $user, $recommendations, $persona, $lang, $aiallowed);
        $composer = new message_composer();
        $keys = array_map(static fn(recommendation $r): string => $r->key, $recommendations);

        $debug = null;
        if ($simulate) {
            $debug = [
                'states' => array_map(static fn($s) => $s->to_array(), array_values($states)),
                'coursecompletion' => $collector->coursecompletion,
                'failedadapters' => $collector->failedadapters,
                'effects' => $effects,
                'groupfacts' => $groupfacts,
                'candidates' => array_map([$this, 'rec_array'], $candidates),
                'selected' => $keys,
                'persona' => $persona . ' (' . persona_registry::version($persona) . ')',
                'fingerprint' => $fingerprint,
                'nexttransition' => $nexttransition,
            ];
            return $this->result(
                $consent,
                $composer->compose($recommendations, $cms, $persona, $lang),
                $sideline,
                $userid,
                $keys,
                $now,
                $persona
            ) + ['debug' => $debug];
        }

        [$cached, $stamp] = commentary_cache::check($row, $fingerprint, $now);
        if ($cached !== null) {
            return $this->result($consent, $composer->sanitize($cached, $cms), $sideline, $userid, $keys, $now, $persona);
        }

        // Singleflight: only one process generates for a given user and instance at a time (H).
        $lock = \core\lock\lock_config::get_lock_factory('mod_sideline')
            ->get_lock('gen_' . $sideline->id . '_' . $userid, 5);
        if (!$lock) {
            // Could not get the lock in time: serve the template text, do not cache it.
            return $this->result(
                $consent,
                $composer->compose($recommendations, $cms, $persona, $lang),
                $sideline,
                $userid,
                $keys,
                $now,
                $persona
            );
        }
        $sharelock = null;
        try {
            // Someone else may have finished while we waited.
            [$cached, $stamp] = commentary_cache::check(commentary_cache::row((int) $sideline->id, $userid), $fingerprint, $now);
            if ($cached !== null) {
                return $this->result($consent, $composer->sanitize($cached, $cms), $sideline, $userid, $keys, $now, $persona);
            }

            $commentary = null;
            if ($aiallowed) {
                $texts = $this->gateway->phrase($recommendations, $user, $context, $persona, $lang, $now);
                if ($texts !== null) {
                    $commentary = $composer->with_texts(
                        $recommendations,
                        $cms,
                        $persona,
                        $lang,
                        $texts,
                        message_composer::SOURCE_AI
                    );
                }
            }
            // Fallback chain: valid AI text, then localised template text, then the generic text (P-08).
            if ($commentary === null || empty($commentary['blocks'])) {
                $commentary = $composer->compose($recommendations, $cms, $persona, $lang);
            }

            // Rechecks right before storing and delivering (P0-02, L-G06). A withdrawal takes this
            // same lock before it deletes, so either we see it here or it deletes what we store.
            $fresh = $DB->get_record('sideline', ['id' => $sideline->id]);
            if (!$fresh || !consent_manager::allows_processing($fresh, $userid)) {
                return ['status' => self::STATUS_CONSENT_REQUIRED, 'consent' => consent_manager::status($sideline, $userid),
                    'commentary' => null, 'question' => null, 'persona' => ''];
            }
            $namedneeded = (bool) array_filter($groupfacts, static fn($f) => !empty($f['names']));
            // With group statements, recheck and store under the instance sharing lock, so a withdrawal
            // either is seen here or deletes what is stored (L-G06).
            $sharelock = $contributors ? \mod_sideline\local\group\sharing_manager::lock((int) $sideline->id) : null;
            if ($contributors && !$sharelock) {
                $contributors = [-1];
            }
            if (!(new group_service())->contributors_still_share($fresh, $contributors, $namedneeded)) {
                // Somebody stopped sharing while we worked: deliver without any group statement, store nothing.
                $without = array_values(array_filter(
                    $recommendations,
                    static fn(recommendation $r): bool => !str_starts_with($r->key, 'group_')
                ));
                $commentary = $composer->compose(
                    $without ?: [new recommendation('smalltalk', recommendation::CAT_SMALLTALK, 100)],
                    $cms,
                    $persona,
                    $lang
                );
                return $this->result($consent, $commentary, $sideline, $userid, $keys, $now, $persona);
            }

            commentary_cache::put(
                (int) $sideline->id,
                $userid,
                $fingerprint,
                $commentary,
                $nexttransition,
                $now,
                $stamp
            );
            $new = array_filter(
                array_diff($keys, $ctx->currentkeys),
                static fn(string $k): bool => str_starts_with($k, 'custom_')
            );
            if ($new) {
                rule_store::record_hits((int) $sideline->id, $userid, $new, $now);
            }
            trace_recorder::record((int) $sideline->id, $userid, [
                'states' => array_map(static fn($s) => $s->to_fingerprint_array(), array_values($states)),
                'effects' => $effects,
                'candidates' => array_map(static fn(recommendation $r) => $r->key, $candidates),
                'selected' => $keys,
                'source' => $commentary['source'] ?? '',
                'persona' => $persona,
                'groupstatements' => count($groupfacts),
            ], $now);

            return $this->result($consent, $composer->sanitize($commentary, $cms), $sideline, $userid, $keys, $now, $persona);
        } finally {
            if ($sharelock) {
                $sharelock->release();
            }
            $lock->release();
        }
    }

    /**
     * Assemble the result with the question that is due, if any.
     *
     * @param string $consent Consent status.
     * @param array $commentary Commentary.
     * @param \stdClass $sideline Instance.
     * @param int $userid User id.
     * @param string[] $keys Current rule keys.
     * @param int $now Current time.
     * @param string $persona Persona key.
     * @return array
     */
    private function result(
        string $consent,
        array $commentary,
        \stdClass $sideline,
        int $userid,
        array $keys,
        int $now,
        string $persona,
    ): array {
        $question = dialog_manager::due_question($sideline, $userid, $keys, $now);
        unset($commentary['meta']);
        return [
            'status' => self::STATUS_OK,
            'consent' => $consent,
            'commentary' => $commentary,
            'question' => $question ? dialog_manager::export_for_panel($question) : null,
            'persona' => $persona,
        ];
    }

    /**
     * Fingerprint of everything that determines the text (P-27).
     *
     * @param \stdClass $sideline Instance.
     * @param \stdClass $user User.
     * @param recommendation[] $recommendations Selected recommendations.
     * @param string $persona Persona key.
     * @param string $lang Language.
     * @param bool $aiallowed Whether AI is allowed.
     * @return string
     */
    private function fingerprint(
        \stdClass $sideline,
        \stdClass $user,
        array $recommendations,
        string $persona,
        string $lang,
        bool $aiallowed,
    ): string {
        return hash('sha256', json_encode([
            'u' => (int) $user->id,
            'i' => (int) $sideline->id,
            'cfg' => (int) $sideline->configversion,
            'rules' => rule_evaluator::RULES_VERSION,
            'persona' => $persona . ':' . persona_registry::version($persona),
            'lang' => $lang,
            'ai' => $aiallowed ? 1 : 0,
            'name' => sha1((string) $user->firstname),
            'recs' => array_map([$this, 'rec_array'], $recommendations),
        ]));
    }

    /**
     * A recommendation as plain array.
     *
     * @param recommendation $r Recommendation.
     * @return array
     */
    public function rec_array(recommendation $r): array {
        return [
            'key' => $r->key,
            'category' => $r->category,
            'priority' => $r->priority,
            'action' => $r->actiontype,
            'target' => $r->targetcmid,
            'facts' => $r->facts,
        ];
    }
}
