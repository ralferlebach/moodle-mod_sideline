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
 * Restore on a foreign site, step 2 (P-34): check the restored course on the TARGET site.
 *
 * Prints whether configuration, scope and rule references arrived remapped, and that no personal
 * data (consent, consent log, cache) travelled with the backup.
 *
 * Usage: php tools/foreign_restore_check.php <moodle-root>
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require($argv[1] . '/config.php');
$course = $DB->get_record_select('course', 'fullname = ?', ['Foreign source'], '*', IGNORE_MULTIPLE);
$modinfo = get_fast_modinfo($course);
$cms = [];
foreach ($modinfo->get_cms() as $cm) { $cms[$cm->name] = $cm; }
$sideline = $DB->get_record('sideline', ['id' => $cms['Coach']->instance]);
$scope = $DB->get_fieldset_select('sideline_scope', 'cmid', 'sidelineid = ?', [$sideline->id]);
$rule = json_decode($DB->get_field('sideline_rule', 'definition', ['sidelineid' => $sideline->id]), true);
$out = [
  'site' => $CFG->wwwroot . ' ' . $CFG->release,
  'persona' => $sideline->persona, 'dialog' => (int) $sideline->dialogenabled, 'consentrequired' => (int) $sideline->consentrequired,
  'scope_remapped' => array_map('intval', $scope) === [(int) $cms['Lab report']->id],
  'rule_cmid_remapped' => (int) $rule['conditions'][0]['cmid'] === (int) $cms['Lab report']->id,
  'rule_templates' => $rule['templates'], 'rule_templateversion' => $rule['templateversion'] ?? null,
  'questions' => $DB->count_records('sideline_question', ['sidelineid' => $sideline->id]),
  'consents' => $DB->count_records('sideline_consent', ['sidelineid' => $sideline->id]),
  'consentlog' => $DB->count_records('sideline_consent_log', ['sidelineid' => $sideline->id]),
  'cache' => $DB->count_records('sideline_cache', ['sidelineid' => $sideline->id]),
];
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
