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
 * Operational privacy check of the Sideline coach on the target system (issue #14, docs/privacy/DATENSCHUTZ.md).
 *
 * Prints what the plugin keeps, for how long, and whether retention and deletion work: counts, settings and paths,
 * never content (to recognise entries of 0.4.1 it reads the prompts of AI entries from before the upgrade to 0.4.3).
 * Exits 1 when a check fails, so it can run from a monitoring job.
 *
 * Usage: php mod/sideline/cli/privacy_check.php [--empty-unattributed] [--now=<unix time>]
 *
 * --empty-unattributed  Empty the prompts and answers that 0.4.1 left in the AI subsystem without an instance
 *                       (a withdrawal cannot reach them); the decision is the controller's, see DATENSCHUTZ.md.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use mod_sideline\local\ai\ai_records;
use mod_sideline\local\privacy\operational_check;

[$options, $unrecognised] = cli_get_params(
    ['empty-unattributed' => false, 'now' => 0, 'help' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}
if ($options['help']) {
    echo get_string('cli_privacy_check', 'mod_sideline') . PHP_EOL
        . 'php mod/sideline/cli/privacy_check.php [--empty-unattributed] [--now=<unix time>]' . PHP_EOL;
    exit(0);
}

if ($options['empty-unattributed']) {
    $emptied = ai_records::forget_unattributed();
    echo "emptied $emptied entries of 0.4.1 without an instance" . PHP_EOL;
}

$now = (int) $options['now'] ?: time();
$failed = false;
foreach (operational_check::run($now) as [$name, $result, $detail]) {
    printf('%-5s %-28s %s' . PHP_EOL, strtoupper($result), $name, $detail);
    $failed = $failed || $result === operational_check::FAIL;
}
exit($failed ? 1 : 0);
