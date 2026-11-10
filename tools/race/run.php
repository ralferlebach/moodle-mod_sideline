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

/**
 * Concurrency races with real parallel processes (issues #3 and #8). Development and CI sites only.
 *
 * The PHPUnit tests produce interleavings inside one process. This script starts real PHP
 * processes against the site database, which all wait for a common start time:
 *
 * 1. budget: 20 processes of 20 different instances race for the last AI call of the hour, then
 *    for the last tokens of the day. Exactly one may get it.
 * 2. withdrawal: a learner withdraws consent while a generation with a slow provider is in flight.
 *    Nothing may be delivered, and no text may be left in the cache.
 * 3. invalidation: the learner's state changes while a generation is in flight. The text may not be
 *    stored as fresh; the generation computes again.
 * 4. privacy deletion (issue #28): a deletion request of the learner runs while a generation through Moodle's AI
 *    subsystem is in flight, and once after it. Core stores prompt and answer only after the answer; in the end
 *    no entry of the instance may hold text, the entry of another plugin for the same person stays.
 * 5. the same prompt (issue #28): while the generation is in flight, another plugin sends exactly its prompt for
 *    the same person in the same context. Only the coach's own entry may be recorded; a withdrawal keeps the other.
 * 6. day counts on two nodes (issue #30): one process states the group's progress (and counts the members of the
 *    day) while another one stops a member's sharing, five times. Afterwards the member is never counted.
 *
 * Usage: php tools/race/run.php   (exit code 0 when every race held, 1 otherwise)
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
if (file_exists($CFG->libdir . '/testing/classes/util.php')) {
    require_once($CFG->libdir . '/testing/classes/util.php');
}

require_once(__DIR__ . '/slow_ai_manager.php');

if ($CFG->wwwroot === 'https://moodle.example.org' || !empty($CFG->sideline_race_disabled)) {
    cli_error('Development and CI sites only.');
}
\core\session\manager::set_user(get_admin());

/**
 * Start processes together and collect their JSON outcomes.
 *
 * @param array[] $jobs Options per process.
 * @return array[] Outcome per process, in the order of the jobs.
 */
function sideline_race(array $jobs): array {
    $start = microtime(true) + 2;
    $procs = [];
    foreach ($jobs as $i => $job) {
        $args = [PHP_BINARY, __DIR__ . '/worker.php', '--start=' . $start];
        foreach ($job as $name => $value) {
            $args[] = '--' . $name . '=' . $value;
        }
        $procs[$i] = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $results = [];
    foreach ($procs as $i => $proc) {
        $out = stream_get_contents($pipes[$i][1]);
        $err = stream_get_contents($pipes[$i][2]);
        proc_close($proc);
        $lines = array_filter(explode("\n", trim($out)));
        $results[$i] = json_decode((string) end($lines), true) ?? ['error' => trim($out . ' ' . $err)];
    }
    return $results;
}

$failures = 0;
$check = static function (string $name, bool $ok, string $detail) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ': ' . $detail . "\n";
    $failures += $ok ? 0 : 1;
};

$gen = new testing_data_generator();
set_config('enablecompletion', 1);
set_config('aienabled', 1, 'mod_sideline');
$stamp = time() . '_' . random_int(1000, 9999);
$course = $gen->create_course(['enablecompletion' => 1, 'fullname' => 'Race ' . $stamp, 'shortname' => 'race_' . $stamp]);
$student = $gen->create_and_enrol($course, 'student', ['username' => 'race_' . $stamp]);
$gen->create_module('page', ['course' => $course->id, 'name' => 'Race unit', 'completion' => COMPLETION_TRACKING_MANUAL]);
$instance = $gen->create_module('sideline', ['course' => $course->id, 'aienabled' => 1, 'consentrequired' => 1]);
$sideline = $DB->get_record('sideline', ['id' => $instance->id]);
$context = context_module::instance($instance->cmid);

// 1. Budget: the last call of the hour, then the last tokens of the day.
$DB->delete_records('sideline_aiusage');
set_config('aihourlycap', 1, 'mod_sideline');
set_config('aidailytokencap', 0, 'mod_sideline');
$jobs = [];
for ($i = 1; $i <= 20; $i++) {
    $jobs[] = ['action' => 'reserve', 'slot' => 1000 + $i];
}
$won = count(array_filter(sideline_race($jobs), static fn($r) => !empty($r['reserved'])));
$check('budget hourly', $won === 1 && $DB->count_records('sideline_aiusage') === 1, "$won of 20 processes got the last call");

$DB->delete_records('sideline_aiusage');
set_config('aihourlycap', 1000, 'mod_sideline');
// One worst case (50 + 1024 tokens) fits, two do not.
set_config('aidailytokencap', 1500, 'mod_sideline');
$won = count(array_filter(sideline_race($jobs), static fn($r) => !empty($r['reserved'])));
$check('budget tokens', $won === 1, "$won of 20 processes got the last tokens");
$DB->delete_records('sideline_aiusage');
set_config('aihourlycap', 1000, 'mod_sideline');
set_config('aidailytokencap', 0, 'mod_sideline');

// 2. Withdrawal while the generation is in flight: the provider takes 1.5 s, the withdrawal starts as soon as the
// generating process is inside the provider call.
\mod_sideline\local\consent\consent_manager::record_decision($sideline, (int) $student->id, true, $context->id, time());
$signal = make_request_directory() . '/in_provider';
$results = sideline_race([
    ['action' => 'generate', 'instance' => $sideline->id, 'user' => $student->id, 'signal' => $signal],
    ['action' => 'withdraw', 'instance' => $sideline->id, 'user' => $student->id, 'waitfor' => $signal],
]);
$left = $DB->count_records('sideline_cache', ['sidelineid' => $sideline->id, 'userid' => $student->id]);
$check(
    'withdrawal during generation',
    ($results[0]['status'] ?? '') === 'consent_required' && $left === 0,
    'generation returned ' . json_encode($results[0]) . ", $left cached rows left"
);

// The same with the withdrawal after the generation finished: delivered before, deleted after.
\mod_sideline\local\consent\consent_manager::record_decision($sideline, (int) $student->id, true, $context->id, time());
$results = sideline_race([
    ['action' => 'generate', 'instance' => $sideline->id, 'user' => $student->id],
    ['action' => 'withdraw', 'instance' => $sideline->id, 'user' => $student->id, 'delay' => 4000],
]);
$left = $DB->count_records('sideline_cache', ['sidelineid' => $sideline->id, 'userid' => $student->id]);
$check('withdrawal after generation', $left === 0, 'generation returned ' . json_encode($results[0]) . ", $left cached rows left");

// 3. Invalidation while the generation is in flight: the text is computed again, never stored stale.
\mod_sideline\local\consent\consent_manager::record_decision($sideline, (int) $student->id, true, $context->id, time());
$DB->delete_records('sideline_cache', ['sidelineid' => $sideline->id]);
// A row must exist, so that the invalidation has something to mark.
$DB->insert_record('sideline_cache', (object) ['sidelineid' => $sideline->id, 'userid' => $student->id, 'fingerprint' => 'old',
    'source' => 'template', 'commentary' => '{}', 'dirty' => 1, 'nexttransition' => 0, 'stamp' => 0, 'timecreated' => time(),
    'timemodified' => time()]);
$signal = make_request_directory() . '/in_provider';
$results = sideline_race([
    ['action' => 'generate', 'instance' => $sideline->id, 'user' => $student->id, 'signal' => $signal],
    ['action' => 'invalidate', 'instance' => $sideline->id, 'user' => $student->id, 'waitfor' => $signal],
]);
$row = $DB->get_record('sideline_cache', ['sidelineid' => $sideline->id, 'userid' => $student->id]);
$check(
    'invalidation during generation',
    ($results[0]['attempts'] ?? 0) === 2 && $row && ($row->fingerprint !== 'old'),
    'generation returned ' . json_encode($results[0]) . ', cache ' . ($row ? 'dirty=' . $row->dirty : 'empty')
);

// 4. Privacy deletion against a generation through Moodle's AI subsystem, with real processes.
$aicore = \core\di::get(\core_ai\manager::class);
if (method_exists($aicore, 'create_provider_instance')) {
    if (!$aicore->get_provider_instances(['name' => 'race'])) {
        $aicore->create_provider_instance(
            classname: '\aiprovider_openai\provider',
            name: 'race',
            enabled: true,
            config: ['apikey' => 'race', 'enableuserratelimit' => false, 'enableglobalratelimit' => false],
            actionconfig: [\core_ai\aiactions\generate_text::class => ['enabled' => true, 'settings' => [
                'model' => 'gpt-4o', 'endpoint' => 'https://api.openai.com/v1/chat/completions', 'systeminstruction' => '',
            ]]],
        );
    }
} else {
    set_config('apikey', 'race', 'aiprovider_openai');
    set_config('enableuserratelimit', 0, 'aiprovider_openai');
    set_config('enableglobalratelimit', 0, 'aiprovider_openai');
    set_config('action_generate_text_model', 'gpt-4o', 'aiprovider_openai');
    set_config('action_generate_text_endpoint', 'https://api.openai.com/v1/chat/completions', 'aiprovider_openai');
    \core\plugininfo\aiprovider::enable_plugin('openai', 1);
    \core_ai\manager::set_action_state('aiprovider_openai', 'generate_text', 1);
}
\core_ai\manager::user_policy_accepted((int) $student->id, $context->id);
$usercontext = context_user::instance($student->id);
// An entry of another plugin for the same person in the same user context.
$foreign = new \core_ai\aiactions\generate_text(
    contextid: $usercontext->id,
    userid: (int) $student->id,
    prompttext: 'Prompt of another plugin in the race'
);
$racemanager = new \mod_sideline\tools\slow_ai_manager($DB);
$racemanager->millis = 0;
$racemanager->process_action($foreign);
$foreignid = (int) $DB->get_field_select(
    'ai_action_generate_text',
    'id',
    $DB->sql_compare_text('prompt') . ' = ?',
    ['Prompt of another plugin in the race']
);
$texts = static function () use ($DB, $student, $usercontext, $foreignid): int {
    return $DB->count_records_sql(
        "SELECT COUNT(1)
           FROM {ai_action_register} r
           JOIN {ai_action_generate_text} t ON t.id = r.actionid
          WHERE r.userid = ? AND r.contextid = ? AND t.id <> ?
                AND " . $DB->sql_isnotempty('ai_action_generate_text', 't.generatedcontent', true, true),
        [$student->id, $usercontext->id, $foreignid]
    );
};
foreach (['during' => 0, 'after' => 4000] as $when => $delay) {
    \mod_sideline\local\consent\consent_manager::record_decision($sideline, (int) $student->id, true, $context->id, time());
    $DB->delete_records('sideline_cache', ['sidelineid' => $sideline->id]);
    $before = $DB->count_records('ai_action_register', ['userid' => $student->id]);
    $signal = make_request_directory() . '/in_provider';
    $results = sideline_race([
        ['action' => 'generate_core', 'instance' => $sideline->id, 'user' => $student->id, 'signal' => $signal],
        ['action' => 'privacy_delete', 'instance' => $sideline->id, 'user' => $student->id,
            'waitfor' => $delay ? '' : $signal, 'delay' => $delay],
    ]);
    $calls = $DB->count_records('ai_action_register', ['userid' => $student->id]) - $before;
    $left = $texts();
    $check(
        "privacy deletion $when a generation through the AI subsystem",
        $calls >= 1 && $left === 0 && !$DB->record_exists('sideline_aicall', ['sidelineid' => $sideline->id])
            && !$DB->record_exists('sideline_cache', ['sidelineid' => $sideline->id, 'userid' => $student->id])
            && $DB->get_field('ai_action_generate_text', 'generatedcontent', ['id' => $foreignid]) === 'Answer of the provider',
        "$calls call(s) stored by core, $left with text left; generation returned " . json_encode($results[0])
    );
}

// 5. Another plugin sends the very same prompt while the generation is in flight.
\mod_sideline\local\consent\consent_manager::record_decision($sideline, (int) $student->id, true, $context->id, time());
$DB->delete_records('sideline_cache', ['sidelineid' => $sideline->id]);
$after = (int) $DB->get_field_sql('SELECT MAX(id) FROM {ai_action_register}');
$signal = make_request_directory() . '/prompt';
$results = sideline_race([
    ['action' => 'generate_core', 'instance' => $sideline->id, 'user' => $student->id, 'signal' => $signal],
    ['action' => 'foreign_same_prompt', 'instance' => $sideline->id, 'user' => $student->id, 'waitfor' => $signal],
]);
$entries = $DB->get_records_sql(
    'SELECT r.id, t.id AS textid, t.prompt FROM {ai_action_register} r
       JOIN {ai_action_generate_text} t ON t.id = r.actionid
      WHERE r.id > ? AND r.userid = ? ORDER BY r.id',
    [$after, $student->id]
);
$prompts = array_unique(array_map(static fn($e) => (string) $e->prompt, $entries));
$attributed = array_map('intval', $DB->get_fieldset_select('sideline_aicall', 'actionid', 'actionid > ?', [$after]));
$foreignentry = $entries ? reset($entries) : null;
\mod_sideline\local\consent\consent_manager::record_decision($sideline, (int) $student->id, false, $context->id, time());
$check(
    'another plugin sends the same prompt during a generation',
    count($entries) === 2 && count($prompts) === 1 && count($attributed) === 1
        && !in_array((int) $foreignentry->id, $attributed, true)
        && $DB->get_field('ai_action_generate_text', 'generatedcontent', ['id' => $foreignentry->textid]) !== '',
    count($entries) . ' entries with ' . count($prompts) . ' prompt(s), recorded ' . json_encode($attributed)
        . ', other plugin ' . json_encode($results[1]) . ', generation ' . json_encode($results[0])
);

// 6. Day counts: a statement on one node, the end of sharing on another, at the same time.
$groupcourse = $gen->create_course(['enablecompletion' => 1, 'shortname' => 'race_group_' . $stamp]);
$unit = $gen->create_module('page', ['course' => $groupcourse->id, 'name' => 'Race group unit',
    'completion' => COMPLETION_TRACKING_MANUAL]);
$group = $gen->create_group(['courseid' => $groupcourse->id]);
$groupinstance = $gen->create_module('sideline', ['course' => $groupcourse->id, 'sharingmode' => 'closed',
    'grouppublish' => 'general', 'groupminsize' => 3]);
$groupsideline = $DB->get_record('sideline', ['id' => $groupinstance->id]);
$groupcontext = context_module::instance($groupinstance->cmid);
$recipient = $gen->create_and_enrol($groupcourse, 'student');
$gen->create_group_member(['groupid' => $group->id, 'userid' => $recipient->id]);
$members = [];
for ($i = 0; $i < 5; $i++) {
    $member = $gen->create_and_enrol($groupcourse, 'student');
    $gen->create_group_member(['groupid' => $group->id, 'userid' => $member->id]);
    \mod_sideline\local\group\sharing_manager::record($groupsideline, (int) $member->id, true, false, $groupcontext->id, time());
    if ($i < 4) {
        $DB->insert_record('course_modules_completion', (object) ['coursemoduleid' => $unit->cmid, 'userid' => $member->id,
            'completionstate' => COMPLETION_COMPLETE, 'viewed' => 0,
            'timemodified' => \mod_sideline\local\group\group_service::day_start(time()) - HOURSECS]);
    }
    $members[] = $member;
}
$leaver = $members[0];
$counted = 0;
$left = 0;
for ($round = 0; $round < 5; $round++) {
    \mod_sideline\local\group\sharing_manager::record($groupsideline, (int) $leaver->id, true, false, $groupcontext->id, time());
    $results = sideline_race([
        ['action' => 'groupfacts', 'instance' => $groupsideline->id, 'user' => $recipient->id],
        ['action' => 'stopsharing', 'instance' => $groupsideline->id, 'user' => $leaver->id],
    ]);
    $counted += (int) ($results[0]['statements'] ?? 0);
    $left += $DB->count_records('sideline_daycount', ['sidelineid' => $groupsideline->id, 'userid' => $leaver->id]);
}
$check(
    'day counts on two nodes: the end of sharing during a statement',
    $left === 0,
    "$left rows of the member who stopped sharing after 5 rounds; $counted statements made"
);

echo $failures ? "$failures race(s) failed.\n" : "All races held.\n";
exit($failures ? 1 : 0);
