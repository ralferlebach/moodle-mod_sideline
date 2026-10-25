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
 * Upgrade steps for mod_sideline.
 *
 * Never declare DEFAULT="" on a CHAR NOT NULL column: XMLDB emits a debugging message for it,
 * and moodle-plugin-ci treats any debugging message during PHPUnit initialisation as a failure.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the mod_sideline plugin.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_sideline_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // 0.1.0 (2026102400) to 0.2.0: inline display, scope, personas, dialog, rules, group mode,
    // tracing and audit. Existing rows keep their values; every new field has a safe default.
    if ($oldversion < 2026102500) {
        $instancefields = [
            new xmldb_field('display', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'inline'),
            new xmldb_field('scopesubsections', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1'),
            new xmldb_field('personas', XMLDB_TYPE_TEXT, null, null, null, null, null),
            new xmldb_field('personachoice', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('dialogenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('dialogcooldown', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '86400'),
            new xmldb_field('sharingmode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'individual'),
            new xmldb_field('grouppublish', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'general'),
            new xmldb_field('groupminsize', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '3'),
            new xmldb_field('groupversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1'),
        ];
        $table = new xmldb_table('sideline');
        foreach ($instancefields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $table = new xmldb_table('sideline_cache');
        $field = new xmldb_field('stamp', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('sideline_consent_log');
        $field = new xmldb_field('kind', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'analysis');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Instances created with 0.1.0 were shown on their own page: keep that behaviour.
        $DB->set_field('sideline', 'display', 'page');

        $file = __DIR__ . '/install.xml';
        foreach (
            ['sideline_source', 'sideline_group', 'sideline_persona', 'sideline_pref', 'sideline_question',
            'sideline_answer', 'sideline_rule', 'sideline_rulehit', 'sideline_publish', 'sideline_trace',
            'sideline_debuglog'] as $name
        ) {
            if (!$dbman->table_exists($name)) {
                $dbman->install_one_table_from_xmldb_file($file, $name);
            }
        }

        upgrade_mod_savepoint(true, 2026102500, 'sideline');
    }

    return true;
}
