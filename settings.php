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
 * Site administration settings of mod_sideline: general settings, site personas, debug export.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$category = new admin_category('modsideline', new lang_string('pluginname', 'mod_sideline'), $module->is_enabled() === false);
$ADMIN->add('modsettings', $category);

$page = new admin_settingpage(
    $section,
    get_string('settings_general', 'mod_sideline'),
    'moodle/site:config',
    $module->is_enabled() === false
);
if ($ADMIN->fulltree) {
    // AI is off until a site administrator turns it on (L-08, P-31).
    $page->add(new admin_setting_configcheckbox(
        'mod_sideline/aienabled',
        get_string('setting_aienabled', 'mod_sideline'),
        get_string('setting_aienabled_desc', 'mod_sideline'),
        0
    ));
    $page->add(new admin_setting_configtext(
        'mod_sideline/aihourlycap',
        get_string('setting_aihourlycap', 'mod_sideline'),
        get_string('setting_aihourlycap_desc', 'mod_sideline'),
        200,
        PARAM_INT
    ));
    // Fair use and cost limit (ADR-08): tokens per day across the site, 0 means no daily limit.
    $usage = \mod_sideline\local\ai\ai_usage::summary(time());
    $page->add(new admin_setting_configtext(
        'mod_sideline/aidailytokencap',
        get_string('setting_aidailytokencap', 'mod_sideline'),
        get_string('setting_aidailytokencap_desc', 'mod_sideline', (object) $usage),
        0,
        PARAM_INT
    ));
    $page->add(new admin_setting_configcheckbox(
        'mod_sideline/crosscourse',
        get_string('setting_crosscourse', 'mod_sideline'),
        get_string('setting_crosscourse_desc', 'mod_sideline'),
        0
    ));
    $tracing = new admin_setting_configcheckbox(
        'mod_sideline/tracing',
        get_string('setting_tracing', 'mod_sideline'),
        get_string('setting_tracing_desc', 'mod_sideline'),
        0
    );
    $tracing->set_updatedcallback('mod_sideline_tracing_updated');
    $page->add($tracing);
    $duration = new admin_setting_configduration(
        'mod_sideline/tracingduration',
        get_string('setting_tracingduration', 'mod_sideline'),
        get_string('setting_tracingduration_desc', 'mod_sideline'),
        DAYSECS
    );
    $duration->set_updatedcallback('mod_sideline_tracing_updated');
    $page->add($duration);
    // Interview policy for the whole site (P-31): upper bounds for every activity.
    $page->add(new admin_setting_heading(
        'mod_sideline/interviewpolicy',
        get_string('setting_interviewpolicy', 'mod_sideline'),
        get_string('setting_interviewpolicy_desc', 'mod_sideline')
    ));
    $page->add(new admin_setting_configcheckbox(
        'mod_sideline/interviewsenabled',
        get_string('setting_interviewsenabled', 'mod_sideline'),
        get_string('setting_interviewsenabled_desc', 'mod_sideline'),
        1
    ));
    $types = [];
    foreach (\mod_sideline\local\dialog\question_type::TYPES as $type) {
        $types[$type] = get_string('qtype_' . $type, 'mod_sideline');
    }
    $page->add(new admin_setting_configmulticheckbox(
        'mod_sideline/interviewtypes',
        get_string('setting_interviewtypes', 'mod_sideline'),
        get_string('setting_interviewtypes_desc', 'mod_sideline'),
        array_fill_keys(array_keys($types), 1),
        $types
    ));
    $page->add(new admin_setting_configduration(
        'mod_sideline/interviewmingap',
        get_string('setting_interviewmingap', 'mod_sideline'),
        get_string('setting_interviewmingap_desc', 'mod_sideline'),
        DAYSECS
    ));
    $page->add(new admin_setting_configtext(
        'mod_sideline/interviewmaxperweek',
        get_string('setting_interviewmaxperweek', 'mod_sideline'),
        get_string('setting_interviewmaxperweek_desc', 'mod_sideline'),
        3,
        PARAM_INT
    ));
    $page->add(new admin_setting_heading(
        'mod_sideline/retention',
        get_string('setting_retention', 'mod_sideline'),
        ''
    ));
    foreach (
        ['traceretentiondays' => 14, 'auditretentiondays' => 365, 'consentlogretentiondays' => 0,
        'answerretentiondays' => 365, 'cacheretentiondays' => 90] as $name => $default
    ) {
        $page->add(new admin_setting_configtext(
            'mod_sideline/' . $name,
            get_string('setting_' . $name, 'mod_sideline'),
            get_string('setting_' . $name . '_desc', 'mod_sideline'),
            $default,
            PARAM_INT
        ));
    }
}
$ADMIN->add('modsideline', $page);

$ADMIN->add('modsideline', new admin_externalpage(
    'mod_sideline_personas',
    get_string('personas_manage', 'mod_sideline'),
    new moodle_url('/mod/sideline/personas.php'),
    'mod/sideline:managepersonas',
    $module->is_enabled() === false
));
$ADMIN->add('modsideline', new admin_externalpage(
    'mod_sideline_debug',
    get_string('debug_export', 'mod_sideline'),
    new moodle_url('/mod/sideline/debug.php'),
    'moodle/site:config',
    $module->is_enabled() === false
));

// The settings page was added to the category above; tell the caller not to add it again.
$settings = null;
