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
use Behat\Mink\Exception\DriverException;

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
     * A learner gives or withdraws consent in another browser tab or device, also for scenarios without
     * JavaScript, in which the panel cannot be used.
     *
     * @Given /^"(?P<username>[^"]*)" (?P<decision>gives|withdraws) the consent for "(?P<activity>[^"]*)" elsewhere$/
     * @param string $username User name.
     * @param string $decision gives or withdraws.
     * @param string $activity Activity name or idnumber.
     */
    public function withdraws_the_consent_elsewhere(string $username, string $decision, string $activity): void {
        global $DB;
        $cm = $this->get_cm_by_activity_name('sideline', $activity);
        $sideline = $DB->get_record('sideline', ['id' => $cm->instance], '*', MUST_EXIST);
        $userid = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        \mod_sideline\local\consent\consent_manager::record_decision(
            $sideline,
            $userid,
            $decision === 'gives',
            \context_module::instance($cm->id)->id,
            time()
        );
    }

    /** @var string[] Language packs created by a scenario, removed after it. */
    private static array $languages = [];

    /**
     * A minimal language pack, as the CI sites have only English: the language is installed, but the pack
     * carries no texts of its own, like a pack of the Moodle language server without this plugin. The
     * plugin's own translations (lang/de) apply to it (issue #17).
     *
     * @Given /^the language pack "(?P<lang>[a-z_]+)" with the direction "(?P<dir>ltr|rtl)" is installed for testing$/
     * @param string $lang Language code.
     * @param string $dir Text direction.
     */
    public function the_language_pack_is_installed_for_testing(string $lang, string $dir): void {
        global $CFG;
        $folder = $CFG->dataroot . '/lang/' . $lang;
        check_dir_exists($folder);
        $strings = ['thislanguage' => $lang, 'thislanguageint' => $lang, 'parentlanguage' => '', 'thisdirection' => $dir,
            'locale' => 'en_AU.UTF-8', 'strftimedatetimeshort' => '%d.%m.%y, %H:%M'];
        $php = "<?php\n";
        foreach ($strings as $key => $value) {
            $php .= '$string[' . var_export($key, true) . '] = ' . var_export($value, true) . ";\n";
        }
        file_put_contents($folder . '/langconfig.php', $php);
        self::$languages[] = $folder;
        get_string_manager()->reset_caches();
    }

    /**
     * Remove the language packs of the scenario.
     *
     * @AfterScenario
     */
    public function remove_test_languages(): void {
        foreach (self::$languages as $folder) {
            remove_dir($folder);
        }
        if (self::$languages) {
            self::$languages = [];
            get_string_manager()->reset_caches();
        }
    }

    /**
     * The language a user has chosen in the profile.
     *
     * @Given /^the preferred language of "(?P<username>[^"]*)" is "(?P<lang>[a-z_]+)"$/
     * @param string $username User name.
     * @param string $lang Language code.
     */
    public function the_preferred_language_of_is(string $username, string $lang): void {
        global $DB;
        $DB->set_field('user', 'lang', $lang, ['username' => $username]);
    }

    /**
     * Unenrol a user from a course with the enrolment API, so that the events of Moodle are triggered.
     *
     * @Given /^"(?P<username>[^"]*)" is unenrolled from the course "(?P<course>[^"]*)"$/
     * @param string $username User name.
     * @param string $course Course short name.
     */
    public function is_unenrolled_from_the_course(string $username, string $course): void {
        global $DB;
        $userid = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $courseid = (int) $DB->get_field('course', 'id', ['shortname' => $course], MUST_EXIST);
        foreach (enrol_get_instances($courseid, false) as $instance) {
            if ($DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid])) {
                enrol_get_plugin($instance->enrol)->unenrol_user($instance, $userid);
            }
        }
    }

    /**
     * Take back a role in a category with the role API, so that the events of Moodle are triggered.
     *
     * @Given /^the role "(?P<role>[^"]*)" of "(?P<username>[^"]*)" in the category "(?P<category>[^"]*)" is unassigned$/
     * @param string $role Role short name.
     * @param string $username User name.
     * @param string $category Category id number.
     */
    public function the_role_in_the_category_is_unassigned(string $role, string $username, string $category): void {
        global $DB;
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $role], MUST_EXIST);
        $userid = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $categoryid = (int) $DB->get_field('course_categories', 'id', ['idnumber' => $category], MUST_EXIST);
        role_unassign($roleid, $userid, \context_coursecat::instance($categoryid)->id);
    }

    /**
     * Move a course to another category with the course API, as an administrator does it.
     *
     * @Given /^the course "(?P<course>[^"]*)" is moved to the category "(?P<category>[^"]*)"$/
     * @param string $course Course short name.
     * @param string $category Category id number.
     */
    public function the_course_is_moved_to_the_category(string $course, string $category): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $courseid = (int) $DB->get_field('course', 'id', ['shortname' => $course], MUST_EXIST);
        $categoryid = (int) $DB->get_field('course_categories', 'id', ['idnumber' => $category], MUST_EXIST);
        \core\session\manager::set_user(get_admin());
        move_courses([$courseid], $categoryid);
    }

    /**
     * Check the HTTP response of the current page: a file download of a content type, with a file name
     * ("*" stands for any text), outside JavaScript scenarios.
     *
     * @Then /^the response should be a "(?P<type>[^"]*)" download named "(?P<name>[^"]*)"$/
     * @param string $type Content type.
     * @param string $name File name pattern.
     */
    public function the_response_should_be_a_download_named(string $type, string $name): void {
        $this->check_download($this->response_headers(), $type, $name);
    }

    /**
     * Check that the current response is no file download (a refused request shows a page instead).
     *
     * @Then /^the response should not be a download$/
     */
    public function the_response_should_not_be_a_download(): void {
        $headers = $this->response_headers();
        if (
            stripos($headers['content-disposition'] ?? '', 'attachment') !== false
                || str_starts_with($headers['content-type'] ?? '', 'application/json')
        ) {
            throw new ExpectationException('The response is a download', $this->getSession());
        }
    }

    /**
     * Check the raw content of the current response, for downloads.
     *
     * @Then /^the downloaded content should (?P<not>not )?contain "(?P<text>(?:[^"]|\\")*)"$/
     * @param string $not "not " or empty.
     * @param string $text Text.
     */
    public function the_downloaded_content_should_contain(string $not, string $text): void {
        $this->check_contains($this->getSession()->getPage()->getContent(), $not, $text);
    }

    /** @var array|null Last response of "I download the ... export ... over HTTP": status, headers, body. */
    private static ?array $http = null;

    /**
     * Request the configuration export over HTTP with the session of the browser, as the export button
     * sends it, and keep the response for the steps "the HTTP download should ...".
     *
     * @When /^I download the configuration export of "([^"]*)"( without a session key)? over HTTP$/
     * @param string $activity Activity name or idnumber.
     * @param string $nokey Not empty: without the session key.
     */
    public function i_download_the_configuration_export_over_http(string $activity, string $nokey = ''): void {
        $cm = $this->get_cm_by_activity_name('sideline', $activity);
        $params = ['id' => $cm->id, 'export' => 1] + ($nokey === '' ? ['sesskey' => $this->current_sesskey()] : []);
        self::$http = $this->http_request((new moodle_url('/mod/sideline/transfer.php', $params))->out(false));
    }

    /**
     * Post the debug export form over HTTP with the session of the browser, as the browser sends it, and
     * keep the response for the steps "the HTTP download should ...".
     *
     * @When /^I download the debug export of "([^"]*)" for "([^"]*)" with the purpose "([^"]*)" over HTTP$/
     * @param string $activity Activity name or idnumber.
     * @param string $username Learner.
     * @param string $purpose Purpose of the export.
     */
    public function i_download_the_debug_export_over_http(string $activity, string $username, string $purpose): void {
        global $DB;
        $cm = $this->get_cm_by_activity_name('sideline', $activity);
        // The debug form: its hidden fields from the page, then the values as the browser posts them.
        $form = $this->http_request((new moodle_url('/mod/sideline/debug.php'))->out(false));
        preg_match_all('/<input[^>]*type="hidden"[^>]*>/', $form['body'], $inputs);
        $fields = [];
        foreach ($inputs[0] as $input) {
            if (preg_match('/name="([^"]+)"/', $input, $n) && preg_match('/value="([^"]*)"/', $input, $v)) {
                $fields[html_entity_decode($n[1])] = html_entity_decode($v[1]);
            }
        }
        $fields['cmid'] = $cm->id;
        $fields['userid'] = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $fields['purpose'] = $purpose;
        foreach (['timefrom' => time() - WEEKSECS, 'timeto' => time() + HOURSECS] as $name => $time) {
            foreach (['day' => '%d', 'month' => '%m', 'year' => '%Y', 'hour' => '%H', 'minute' => '%M'] as $part => $format) {
                $fields[$name . '[' . $part . ']'] = (int) userdate($time, $format, 99, false);
            }
        }
        $fields['submitbutton'] = get_string('debug_download', 'mod_sideline');
        self::$http = $this->http_request((new moodle_url('/mod/sideline/debug.php'))->out(false), $fields);
    }

    /**
     * Check the response of "I download ... over HTTP": a file download of a content type, with a file name
     * ("*" stands for any text), not cached by proxies.
     *
     * @Then /^the HTTP download should be a "(?P<type>[^"]*)" file named "(?P<name>[^"]*)"$/
     * @param string $type Content type.
     * @param string $name File name pattern.
     */
    public function the_http_download_should_be_a_file_named(string $type, string $name): void {
        $response = $this->http_response();
        if (!str_contains($response['status'], ' 200')) {
            throw new ExpectationException('HTTP status ' . $response['status'], $this->getSession());
        }
        $this->check_download($response['headers'], $type, $name);
    }

    /**
     * Check that "I download ... over HTTP" got no file: a refused request shows a page instead.
     *
     * @Then /^the HTTP download should be refused$/
     */
    public function the_http_download_should_be_refused(): void {
        $headers = $this->http_response()['headers'];
        if (
            stripos($headers['content-disposition'] ?? '', 'attachment') !== false
                || str_starts_with($headers['content-type'] ?? '', 'application/json')
        ) {
            throw new ExpectationException('The response is a download', $this->getSession());
        }
    }

    /**
     * Check the content of the response of "I download ... over HTTP".
     *
     * @Then /^the HTTP download should (?P<not>not )?contain "(?P<text>(?:[^"]|\\")*)"$/
     * @param string $not "not " or empty.
     * @param string $text Text.
     */
    public function the_http_download_should_contain(string $not, string $text): void {
        $this->check_contains($this->http_response()['body'], $not, $text);
    }

    /**
     * Keep the response of "I download ... over HTTP" as a file for an upload.
     *
     * @Given /^I keep the HTTP download as "(?P<file>[a-z0-9_.-]+)"$/
     * @param string $file File name.
     */
    public function i_keep_the_http_download_as(string $file): void {
        global $CFG;
        check_dir_exists($CFG->dataroot . '/sideline_behat');
        file_put_contents($CFG->dataroot . '/sideline_behat/' . $file, $this->http_response()['body']);
    }

    /**
     * Upload a file kept by "I keep the HTTP download" to a file picker.
     *
     * @Given /^I upload the kept file "(?P<file>[a-z0-9_.-]+)" to "(?P<field>[^"]*)" filemanager$/
     * @param string $file File name.
     * @param string $field Label of the file picker.
     */
    public function i_upload_the_kept_file(string $file, string $field): void {
        global $CFG;
        $this->execute('behat_repository_upload::i_upload_file_to_filemanager', [
            $CFG->dataroot . '/sideline_behat/' . $file,
            $field,
        ]);
    }

    /**
     * Set a text field to the id of a course module or of a user.
     *
     * @Given /^I set the field "(?P<field>[^"]*)" to the (?P<kind>course module|user) id of "(?P<name>[^"]*)"$/
     * @param string $field Field label.
     * @param string $kind course module or user.
     * @param string $name Activity idnumber or user name.
     */
    public function i_set_the_field_to_the_id_of(string $field, string $kind, string $name): void {
        global $DB;
        $id = $kind === 'user' ? (int) $DB->get_field('user', 'id', ['username' => $name], MUST_EXIST)
            : (int) $this->get_cm_by_activity_name('sideline', $name)->id;
        $this->execute('behat_forms::i_set_the_field_to', [$field, (string) $id]);
    }

    /**
     * An HTTP request with the session cookie of the browser.
     *
     * @param string $url Absolute URL.
     * @param array|null $post Form fields to post, null for a GET request.
     * @return array status, headers (lower-case names), body.
     */
    private function http_request(string $url, ?array $post = null): array {
        global $CFG;
        $cookie = 'MoodleSession' . ($CFG->sessioncookie ?? '');
        $header = 'Cookie: ' . $cookie . '=' . $this->getSession()->getCookie($cookie) . "\r\n";
        $options = ['header' => $header, 'ignore_errors' => true, 'follow_location' => 0];
        if ($post !== null) {
            // An explicit separator: Moodle 4.5 to 5.1 set arg_separator.output to "&amp;".
            $options += ['method' => 'POST', 'content' => http_build_query($post, '', '&')];
            $options['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
        }
        $body = file_get_contents($url, false, stream_context_create(['http' => $options]));
        $headers = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
                $headers[strtolower($m[1])] = $m[2];
            }
        }
        return ['status' => (string) ($http_response_header[0] ?? ''), 'headers' => $headers, 'body' => (string) $body];
    }

    /**
     * The kept HTTP response.
     *
     * @return array
     */
    private function http_response(): array {
        if (self::$http === null) {
            throw new ExpectationException('No HTTP download was requested', $this->getSession());
        }
        return self::$http;
    }

    /**
     * Session key of the current page, in scenarios with and without JavaScript.
     *
     * @return string
     */
    private function current_sesskey(): string {
        if ($this->running_javascript()) {
            return (string) $this->evaluate_script('return M.cfg.sesskey;');
        }
        return $this->page_sesskey();
    }

    /**
     * Check download headers.
     *
     * @param string[] $headers Headers, lower-case names.
     * @param string $type Content type.
     * @param string $name File name pattern.
     */
    private function check_download(array $headers, string $type, string $name): void {
        $contenttype = $headers['content-type'] ?? '';
        $disposition = $headers['content-disposition'] ?? '';
        $cache = strtolower($headers['cache-control'] ?? '');
        if (!str_starts_with($contenttype, $type)) {
            throw new ExpectationException(
                "Content type \"$contenttype\", expected \"$type\"" . $this->page_summary(),
                $this->getSession()
            );
        }
        if (!preg_match('/^attachment;\s*filename="?([^";]+)"?/i', $disposition, $m) || !fnmatch($name, $m[1])) {
            throw new ExpectationException("Content disposition \"$disposition\", expected \"$name\"", $this->getSession());
        }
        if (!str_contains($cache, 'no-store') && !str_contains($cache, 'private')) {
            throw new ExpectationException("Cache control \"$cache\" lets proxies keep the file", $this->getSession());
        }
    }

    /**
     * The visible messages of the last HTTP response, for failure messages.
     *
     * @return string
     */
    private function page_summary(): string {
        if (self::$http === null) {
            return '';
        }
        $body = preg_replace('~<(script|style)\b.*?</\1>~s', '', self::$http['body']);
        preg_match_all(
            '~<div[^>]*class="[^"]*(alert|error|errorbox|form-control-feedback|invalid-feedback)[^"]*"[^>]*>(.*?)</div>~s',
            $body,
            $m
        );
        $messages = array_filter(array_map(static fn($t) => trim(html_entity_decode(strip_tags($t))), $m[2]));
        return $messages ? ' (page says: ' . implode(' | ', $messages) . ')' : '';
    }

    /**
     * Check that a text is or is not in a content.
     *
     * @param string $content Content.
     * @param string $not "not " or empty.
     * @param string $text Text, with escaped quotes.
     */
    private function check_contains(string $content, string $not, string $text): void {
        $text = str_replace('\\"', '"', $text);
        $found = str_contains($content, $text);
        if ($found === ($not !== '')) {
            throw new ExpectationException(
                'The content ' . ($found ? 'contains' : 'does not contain') . " \"$text\"",
                $this->getSession()
            );
        }
    }

    /**
     * Session key of the current page.
     *
     * @return string
     */
    private function page_sesskey(): string {
        if (!preg_match('/"sesskey":"([A-Za-z0-9]+)"/', $this->getSession()->getPage()->getContent(), $m)) {
            throw new ExpectationException('No session key on the current page', $this->getSession());
        }
        return $m[1];
    }

    /**
     * Response headers of the current page, lower-case names, outside JavaScript scenarios.
     *
     * @return string[]
     */
    private function response_headers(): array {
        try {
            $raw = $this->getSession()->getResponseHeaders();
        } catch (DriverException $e) {
            throw new ExpectationException('Response headers need a scenario without @javascript', $this->getSession());
        }
        $headers = [];
        foreach ($raw as $name => $values) {
            $headers[strtolower($name)] = is_array($values) ? implode(', ', $values) : (string) $values;
        }
        return $headers;
    }

    /**
     * A learner completed an activity yesterday, or completes it again today: Moodle stamps a completion that is
     * ticked off and on with the current time. Every cached text of the course is computed anew afterwards, so that
     * the next statement comes from the data and the day count, not from a cached text.
     *
     * @Given /^"(?P<username>[^"]*)" completed "(?P<activity>[^"]*)" (?P<when>yesterday|again today)$/
     * @param string $username User name.
     * @param string $activity Activity name.
     * @param string $when yesterday or again today.
     */
    public function completed_the_activity(string $username, string $activity, string $when): void {
        global $DB;
        $cm = $this->get_cm_by_activity_name('page', $activity);
        $userid = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $time = $when === 'yesterday' ? \mod_sideline\local\group\group_service::day_start(time()) - HOURSECS : time();
        $row = $DB->get_record('course_modules_completion', ['coursemoduleid' => $cm->id, 'userid' => $userid]);
        if ($row) {
            $DB->set_field('course_modules_completion', 'timemodified', $time, ['id' => $row->id]);
        } else {
            $DB->insert_record('course_modules_completion', (object) ['coursemoduleid' => $cm->id, 'userid' => $userid,
                'completionstate' => COMPLETION_COMPLETE, 'viewed' => 0, 'timemodified' => $time]);
        }
        \cache::make('core', 'completion')->purge();
        \mod_sideline\local\cache\commentary_cache::mark_course_dirty((int) $cm->course);
    }

    /**
     * A learner starts or stops sharing completions with the group, in another tab or device.
     *
     * @Given /^"(?P<username>[^"]*)" (?P<decision>shares|stops sharing) completions in "(?P<activity>[^"]*)" elsewhere$/
     * @param string $username User name.
     * @param string $decision shares or stops sharing.
     * @param string $activity Activity name or idnumber.
     */
    public function shares_elsewhere(string $username, string $decision, string $activity): void {
        global $DB;
        $cm = $this->get_cm_by_activity_name('sideline', $activity);
        $sideline = $DB->get_record('sideline', ['id' => $cm->instance], '*', MUST_EXIST);
        $userid = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        \mod_sideline\local\group\sharing_manager::record(
            $sideline,
            $userid,
            $decision === 'shares',
            false,
            \context_module::instance($cm->id)->id,
            time()
        );
    }

    /**
     * A learner shares with the group and allows statements with the first name, in another tab or device.
     *
     * @Given /^"(?P<username>[^"]*)" shares by name completions in "(?P<activity>[^"]*)" elsewhere$/
     * @param string $username User name.
     * @param string $activity Activity name or idnumber.
     */
    public function shares_by_name_elsewhere(string $username, string $activity): void {
        global $DB;
        $cm = $this->get_cm_by_activity_name('sideline', $activity);
        $sideline = $DB->get_record('sideline', ['id' => $cm->instance], '*', MUST_EXIST);
        $userid = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        \mod_sideline\local\group\sharing_manager::record(
            $sideline,
            $userid,
            true,
            true,
            \context_module::instance($cm->id)->id,
            time()
        );
    }

    /**
     * Whether the day count of anonymous group statements of an instance holds a learner (issue #30).
     *
     * @Then /^the day count of "(?P<activity>[^"]*)" should (?P<not>not )?include "(?P<username>[^"]*)"$/
     * @param string $activity Activity name or idnumber.
     * @param string $not "not " or empty.
     * @param string $username User name.
     */
    public function the_day_count_should_include(string $activity, string $not, string $username): void {
        global $DB;
        $cm = $this->get_cm_by_activity_name('sideline', $activity);
        $userid = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $found = $DB->record_exists('sideline_daycount', ['sidelineid' => $cm->instance, 'userid' => $userid]);
        if ($found === ($not !== '')) {
            throw new \Behat\Mink\Exception\ExpectationException(
                "The day count of \"$activity\" " . ($found ? 'includes' : 'does not include') . " \"$username\"",
                $this->getSession()
            );
        }
    }

    /**
     * A learner's grade in a graded activity rose yesterday, as the grade history records it: an earlier lower grade
     * three days ago and a higher one yesterday.
     *
     * @Given /^"(?P<username>[^"]*)" improved the result in "(?P<activity>[^"]*)" yesterday$/
     * @param string $username User name.
     * @param string $activity Name of an assignment.
     */
    public function improved_the_result(string $username, string $activity): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $cm = $this->get_cm_by_activity_name('assign', $activity);
        $userid = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $cm->instance,
            'itemnumber' => 0, 'courseid' => $cm->course]);
        $yesterday = \mod_sideline\local\group\group_service::day_start(time()) - HOURSECS;
        foreach ([$yesterday - 2 * DAYSECS => 45.0, $yesterday => 80.0] as $time => $grade) {
            $DB->insert_record('grade_grades_history', (object) ['action' => 2, 'oldid' => 0, 'source' => 'mod/assign',
                'timemodified' => $time, 'loggeduser' => 2, 'itemid' => $item->id, 'userid' => $userid,
                'finalgrade' => $grade, 'rawgrademax' => 100, 'rawgrademin' => 0, 'hidden' => 0, 'locked' => 0,
                'locktime' => 0, 'exported' => 0, 'overridden' => 0, 'excluded' => 0, 'informationformat' => 0,
                'feedbackformat' => 0]);
        }
        $DB->insert_record('grade_grades', (object) ['itemid' => $item->id, 'userid' => $userid, 'finalgrade' => 80.0,
            'rawgrademax' => 100, 'rawgrademin' => 0, 'hidden' => 0, 'timemodified' => $yesterday]);
        \mod_sideline\local\cache\commentary_cache::mark_course_dirty((int) $cm->course);
    }
}
