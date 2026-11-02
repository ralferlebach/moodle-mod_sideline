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
 * One process of a concurrency race (tools/race/run.php). Development and CI sites only.
 *
 * Waits until the common start time, performs one action and prints its outcome as JSON.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/slow_text_generator.php');

[$options] = cli_get_params(['action' => '', 'start' => 0, 'delay' => 0, 'instance' => 0, 'user' => 0, 'slot' => 0,
    'signal' => '', 'waitfor' => '']);

// All processes start together: the race is real, not sequential.
while (microtime(true) < (float) $options['start']) {
    usleep(1000);
}
if ($options['delay']) {
    usleep((int) $options['delay'] * 1000);
}
// Act only once the other process is inside the provider call: the interleaving does not depend on load.
if ($options['waitfor'] !== '') {
    $until = microtime(true) + 30;
    while (!file_exists($options['waitfor']) && microtime(true) < $until) {
        usleep(5000);
    }
}

$sideline = $DB->get_record('sideline', ['id' => (int) $options['instance']]);
$result = [];
switch ($options['action']) {
    case 'reserve':
        // An independent instance per process (slot): different users and instances race for the last call.
        $id = \mod_sideline\local\ai\ai_usage::reserve((int) $options['slot'], 'generate', str_repeat('x', 200), time());
        $result = ['reserved' => $id !== null];
        break;
    case 'generate':
        $user = \core_user::get_user((int) $options['user'], '*', MUST_EXIST);
        \core\session\manager::set_user($user);
        [$course, $cm] = get_course_and_cm_from_instance($sideline->id, 'sideline');
        $fake = new \mod_sideline\tools\slow_text_generator(1500, (string) $options['signal']);
        $service = new \mod_sideline\local\service\commentary_service(new \mod_sideline\local\ai\ai_gateway($fake));
        $out = $service->get($sideline, $course, $user, context_module::instance($cm->id), time());
        $result = ['status' => $out['status'], 'source' => $out['commentary']['source'] ?? '', 'attempts' => $service->attempts,
            'calls' => $fake->calls];
        break;
    case 'withdraw':
        [, $cm] = get_course_and_cm_from_instance($sideline->id, 'sideline');
        $status = \mod_sideline\local\consent\consent_manager::record_decision(
            $sideline,
            (int) $options['user'],
            false,
            context_module::instance($cm->id)->id,
            time()
        );
        $result = ['status' => $status];
        break;
    case 'invalidate':
        \mod_sideline\local\cache\commentary_cache::mark_user_dirty((int) $sideline->course, (int) $options['user']);
        $result = ['invalidated' => true];
        break;
}
echo json_encode($result) . "\n";
