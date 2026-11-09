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

    // 0.2.x to 0.3.0: course branches as sources of a course union (L-19), AI usage for cost control (P-31).
    if ($oldversion < 2026102802) {
        // The teacher whose rights authorise a source course, rechecked on every evaluation (P-43).
        // Existing rows keep 0: they were authorised when saved and are rechecked once a teacher saves again.
        $table = new xmldb_table('sideline_source');
        $field = new xmldb_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // A foreign key is created as an index; only add it once.
        if (!$dbman->index_exists($table, new xmldb_index('usermodified', XMLDB_INDEX_NOTUNIQUE, ['usermodified']))) {
            $dbman->add_key($table, new xmldb_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']));
        }

        $file = __DIR__ . '/install.xml';
        foreach (['sideline_sourcecat', 'sideline_aiusage'] as $name) {
            if (!$dbman->table_exists($name)) {
                $dbman->install_one_table_from_xmldb_file($file, $name);
            }
        }
        // Development builds of 0.3.0 created sideline_sourcecat without the teacher column.
        $table = new xmldb_table('sideline_sourcecat');
        $field = new xmldb_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'categoryid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        if (!$dbman->index_exists($table, new xmldb_index('usermodified', XMLDB_INDEX_NOTUNIQUE, ['usermodified']))) {
            $dbman->add_key($table, new xmldb_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']));
        }
        upgrade_mod_savepoint(true, 2026102802, 'sideline');
    }

    // 0.3.x to 0.4.0: open AI reservations, configurable group event types,
    // course sources without a verifiable teacher.
    if ($oldversion < 2026110300) {
        $table = new xmldb_table('sideline_aiusage');
        $field = new xmldb_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timecreated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            // Every earlier row belongs to a call that has ended.
            $DB->execute('UPDATE {sideline_aiusage} SET timecompleted = timecreated');
        }
        $table = new xmldb_table('sideline');
        $field = new xmldb_field('groupevents', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'completed', 'groupversion');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('groupcircle', XMLDB_TYPE_TEXT, null, null, null, null, null, 'groupevents');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Anonymous aggregates of two people are no longer allowed: raising the minimum
        // narrows sharing, so no new decision of the learners is needed.
        $DB->set_field_select('sideline', 'groupminsize', 3, 'groupminsize < 3');

        // Sources saved before 0.3.0 carry no teacher. They no longer count until a teacher
        // who may manage activities there saves the settings again. Record which instances are affected.
        mod_sideline_upgrade_report_legacy_sources();
        upgrade_mod_savepoint(true, 2026110300, 'sideline');
    }

    // 0.4.2: the day counts of anonymous group statements move from the application cache to a table
    // that withdrawals and privacy requests delete from; the origin of the plugin's AI calls is recorded.
    if ($oldversion < 2026110302) {
        $table = new xmldb_table('sideline_daycount');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('sidelineid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('eventtype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('daystart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('sidelineid', XMLDB_KEY_FOREIGN, ['sidelineid'], 'sideline', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('statement', XMLDB_INDEX_UNIQUE, ['sidelineid', 'daystart', 'groupid', 'cmid', 'eventtype', 'userid']);
        $table->add_index('daystart', XMLDB_INDEX_NOTUNIQUE, ['daystart']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('sideline_aicall');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('sidelineid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('actionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('sidelineid', XMLDB_KEY_FOREIGN, ['sidelineid'], 'sideline', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('actionid', XMLDB_INDEX_UNIQUE, ['actionid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        mod_sideline_upgrade_attribute_ai_calls();
        // The application cache "groupsnapshot" of 0.4.1 held user ids. Its definition is gone from db/caches.php;
        // the purge of all caches that every regular upgrade runs (upgrade_noncore()) removes its entries. An upgrade
        // without maintenance mode requires that purge afterwards, as Moodle itself states.
        upgrade_mod_savepoint(true, 2026110302, 'sideline');
    }

    return true;
}

/**
 * Report course sources and course branches without a verifiable teacher.
 *
 * They are kept, so that a teacher can confirm them by saving the settings, but scope_resolver
 * ignores them from 0.4.0 on. The affected instances get a new configuration version (their cached
 * texts become stale) and are written to the upgrade log, so the change is traceable.
 */
function mod_sideline_upgrade_report_legacy_sources(): void {
    global $DB;

    $ids = [];
    foreach (['sideline_source', 'sideline_sourcecat'] as $table) {
        foreach ($DB->get_fieldset_select($table, 'DISTINCT sidelineid', 'usermodified = 0') as $id) {
            $ids[(int) $id] = true;
        }
    }
    foreach (array_keys($ids) as $id) {
        $DB->execute('UPDATE {sideline} SET configversion = configversion + 1 WHERE id = ?', [$id]);
        $DB->delete_records('sideline_cache', ['sidelineid' => $id]);
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_sideline',
            'Course sources without a verifiable teacher are inactive until re-authorised',
            'sideline instance ' . $id,
        );
    }
}

/**
 * Record the origin of AI calls that releases before 0.4.1 registered in the activity's context.
 *
 * Only entries whose prompt has the form of this plugin's prompts are attributed: the activity's context is
 * also where other placements register calls (for example the AI features of the text editor while a teacher
 * edits the activity), and those are not the instance's to empty. 0.4.1 registered calls in the learner's user
 * context, where the instance cannot be told apart any more; those stay unattributed.
 */
function mod_sideline_upgrade_attribute_ai_calls(): void {
    global $DB;

    if (!$DB->get_manager()->table_exists('ai_action_register')) {
        return;
    }
    $rs = $DB->get_recordset_sql(
        "SELECT r.id, r.userid, r.timecreated, cm.instance, t.prompt
           FROM {ai_action_register} r
           JOIN {ai_action_generate_text} t ON t.id = r.actionid
           JOIN {context} ctx ON ctx.id = r.contextid AND ctx.contextlevel = :level
           JOIN {course_modules} cm ON cm.id = ctx.instanceid
           JOIN {modules} m ON m.id = cm.module AND m.name = :module
          WHERE r.actionname = :action
            AND NOT EXISTS (SELECT 1 FROM {sideline_aicall} a WHERE a.actionid = r.id)",
        ['level' => CONTEXT_MODULE, 'module' => 'sideline', 'action' => 'generate_text']
    );
    foreach ($rs as $row) {
        if (\mod_sideline\local\ai\ai_records::is_plugin_prompt((string) $row->prompt)) {
            $DB->insert_record('sideline_aicall', (object) ['sidelineid' => $row->instance, 'userid' => $row->userid,
                'actionid' => $row->id, 'timecreated' => $row->timecreated], false);
        }
    }
    $rs->close();
}
