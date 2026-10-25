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
 * Data generator of mod_sideline.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Creates Sideline instances for tests and Behat.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_sideline_generator extends testing_module_generator {
    #[\Override]
    public function create_instance($record = null, ?array $options = null) {
        $record = (object) (array) $record;
        $defaults = [
            'scopetype' => 'course', 'scopesection' => 0, 'persona' => 'mentor',
            'aienabled' => 0, 'typing' => 0, 'consentrequired' => 0,
        ];
        foreach ($defaults as $key => $value) {
            if (!isset($record->$key)) {
                $record->$key = $value;
            }
        }
        return parent::create_instance($record, (array) $options);
    }

    /**
     * Create an interview question.
     *
     * @param array $record sidelineid or cmid, qtype, prompt, config, triggerkey, cooldown.
     * @return int Question id.
     */
    public function create_question(array $record): int {
        global $DB;

        if (isset($record['cmid']) && !isset($record['sidelineid'])) {
            $record['sidelineid'] = $DB->get_field('course_modules', 'instance', ['id' => $record['cmid']]);
        }
        $sidelineid = $record['sidelineid'] ?? $DB->get_field('course_modules', 'instance', ['id' => $record['cmid']]);
        return \mod_sideline\local\dialog\dialog_manager::save_question((int) $sidelineid, (object) [
            'qtype' => $record['qtype'] ?? 'mc',
            'prompt' => $record['prompt'] ?? 'How is it going?',
            'config' => $record['config'] ?? ['options' => [['label' => 'Fine', 'effect' => 'none'],
                ['label' => 'Short on time', 'effect' => 'focus_deadlines']]],
            'triggerkey' => $record['triggerkey'] ?? 'any',
            'cooldown' => $record['cooldown'] ?? 0,
            'enabled' => $record['enabled'] ?? 1,
        ]);
    }

    /**
     * Create a custom rule.
     *
     * @param \stdClass $sideline Instance record.
     * @param array $definition Rule definition.
     * @return int Rule id.
     */
    public function create_rule(\stdClass $sideline, array $definition): int {
        return \mod_sideline\local\rules\rule_store::save($sideline, $definition);
    }

    /**
     * Create and approve a site persona.
     *
     * @param array $record shortname, name, style, opener, status.
     * @return int Persona id.
     */
    public function create_persona(array $record): int {
        $id = \mod_sideline\local\persona\persona_manager::save((object) [
            'shortname' => $record['shortname'] ?? 'chess',
            'name' => $record['name'] ?? 'Chess coach',
            'style' => $record['style'] ?? 'You are a calm chess coach. Speak in short, thoughtful sentences.',
            'opener' => $record['opener'] ?? 'Your move.',
        ], get_admin()->id);
        if (($record['status'] ?? 'approved') === 'approved') {
            \mod_sideline\local\persona\persona_manager::set_status($id, 'approved', get_admin()->id);
        }
        return $id;
    }
}
