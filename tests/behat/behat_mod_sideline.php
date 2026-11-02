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
 * Behat steps and page resolution of mod_sideline.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Behat context of mod_sideline.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_sideline extends behat_base {
    /**
     * Pages of an instance: "View", "Questions", "Rules", "Transfer".
     *
     * @param string $type Page type.
     * @param string $identifier Activity name or idnumber.
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        $cm = $this->get_cm_by_activity_name('sideline', $identifier);
        $pages = ['view' => 'view', 'questions' => 'questions', 'rules' => 'rules', 'transfer' => 'transfer'];
        $key = strtolower($type);
        if (!isset($pages[$key])) {
            throw new Exception('Unknown mod_sideline page type "' . $type . '"');
        }
        return new moodle_url('/mod/sideline/' . $pages[$key] . '.php', ['id' => $cm->id]);
    }

    /**
     * Use the deterministic stand-in of the AI provider (issue #4): texts start with "Simulated coach:".
     *
     * @Given /^the Sideline AI is simulated$/
     */
    public function the_sideline_ai_is_simulated(): void {
        set_config('aienabled', 1, 'mod_sideline');
        set_config('behatfakeai', 1, 'mod_sideline');
        set_config('behatfakeaicalls', 0, 'mod_sideline');
    }

    /**
     * Check how many prompts the simulated provider received.
     *
     * @Then /^the simulated Sideline AI should have received (\d+) prompts?$/
     * @param int $count Expected number.
     */
    public function the_simulated_sideline_ai_should_have_received(int $count): void {
        $calls = (int) get_config('mod_sideline', 'behatfakeaicalls');
        if ($calls !== $count) {
            throw new ExpectationException("The simulated AI received $calls prompts, expected $count", $this->getSession());
        }
    }

    /**
     * A learner withdraws consent in another browser tab or device while the panel is open (issue #3).
     *
     * @Given /^"(?P<username>[^"]*)" withdraws the consent for "(?P<activity>[^"]*)" elsewhere$/
     * @param string $username User name.
     * @param string $activity Activity name or idnumber.
     */
    public function withdraws_the_consent_elsewhere(string $username, string $activity): void {
        global $DB;
        $cm = $this->get_cm_by_activity_name('sideline', $activity);
        $sideline = $DB->get_record('sideline', ['id' => $cm->instance], '*', MUST_EXIST);
        $userid = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        \mod_sideline\local\consent\consent_manager::record_decision(
            $sideline,
            $userid,
            false,
            \context_module::instance($cm->id)->id,
            time()
        );
    }
}
