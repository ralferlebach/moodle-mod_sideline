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
 * Playwright configuration for the mod_sideline browser tests.
 *
 * The site and the seeded course come from the environment that seed.php prints (SL_BASE_URL and
 * friends). One browser, one worker: the tests share one seeded course, each with its own learner.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/* eslint-env node */
const {defineConfig, devices} = require('@playwright/test');

module.exports = defineConfig({
    testDir: '.',
    testMatch: '*.spec.js',
    timeout: 60000,
    expect: {timeout: 15000},
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['list'], ['html', {open: 'never', outputFolder: 'playwright-report'}]] : 'list',
    use: {
        baseURL: process.env.SL_BASE_URL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [{name: 'chromium', use: {...devices['Desktop Chrome']}}],
});
