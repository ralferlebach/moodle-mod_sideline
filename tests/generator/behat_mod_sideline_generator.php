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
 * Behat data generator of mod_sideline.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_sideline_generator extends behat_generator_base {
    #[\Override]
    protected function get_creatable_entities(): array {
        return [
            'questions' => [
                'singular' => 'question',
                'datagenerator' => 'question',
                'required' => ['activity', 'prompt', 'options'],
                'switchids' => ['activity' => 'cmid'],
            ],
            'rules' => [
                'singular' => 'rule',
                'datagenerator' => 'rule',
                'required' => ['activity', 'key', 'template'],
                'switchids' => ['activity' => 'cmid'],
            ],
        ];
    }

    /**
     * Activity name or idnumber to course module id.
     *
     * @param string $name Activity name or idnumber.
     * @return int
     */
    protected function get_activity_id(string $name): int {
        return (int) $this->get_cm_by_activity_name('sideline', $name)->id;
    }

    /**
     * Create a multiple choice question; options as "label:effect;label:effect".
     *
     * @param array $data Data.
     */
    protected function process_question(array $data): void {
        $options = [];
        foreach (explode(';', $data['options']) as $option) {
            [$label, $effect] = array_map('trim', explode(':', $option . ':none'));
            $options[] = ['label' => $label, 'effect' => $effect];
        }
        // Optional: "multiple" with "minselect" and "maxselect" for several selections (issue #11).
        $config = ['options' => $options];
        if (!empty($data['multiple'])) {
            $config += ['multiple' => 1, 'minselect' => (int) ($data['minselect'] ?? 1),
                'maxselect' => (int) ($data['maxselect'] ?? count($options))];
        }
        $this->datagenerator->get_plugin_generator('mod_sideline')->create_question([
            'cmid' => $data['cmid'], 'qtype' => 'mc', 'prompt' => $data['prompt'], 'config' => $config,
        ]);
    }

    /**
     * Create a custom rule that fires while any observed activity is not completed.
     *
     * @param array $data activity, key, template (English).
     */
    protected function process_rule(array $data): void {
        global $DB;
        $sideline = $DB->get_record('sideline', ['id' => $DB->get_field('course_modules', 'instance', ['id' => $data['cmid']])]);
        $this->datagenerator->get_plugin_generator('mod_sideline')->create_rule($sideline, [
            'key' => $data['key'], 'category' => 'safety', 'priority' => 1, 'match' => 'all',
            'conditions' => [['type' => 'activity', 'cmid' => 0, 'field' => 'completion', 'is' => 'no']],
            'action' => 'none', 'templates' => ['en' => $data['template']],
        ]);
    }
}
