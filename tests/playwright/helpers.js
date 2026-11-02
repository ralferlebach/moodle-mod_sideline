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
 * Shared helpers for the mod_sideline browser tests.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/* eslint-env node */
const {expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

/** Values printed by seed.php. */
const env = {
    courseid: process.env.SL_COURSEID,
    typingcmid: process.env.SL_TYPING_CMID,
    multicmid: process.env.SL_MULTI_CMID,
    groupcmid: process.env.SL_GROUP_CMID,
    password: process.env.SL_PASSWORD,
    user: (name) => process.env['SL_' + name.toUpperCase()],
};

/**
 * Log in through the login form.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string} username Username.
 */
async function login(page, username) {
    // Wait until the page's JavaScript has settled: Moodle 5.x enhances the password field after load,
    // and a value typed before that can be lost (the login then fails with an invalid token or password).
    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.locator('#username').fill(username);
    await page.locator('#password').fill(env.password);
    await expect(page.locator('#password')).toHaveValue(env.password);
    await page.locator('#loginbtn').click();
    await expect(page).not.toHaveURL(/login\/index\.php/);
}

/**
 * The coach panel on the current page.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @returns {import('@playwright/test').Locator}
 */
function panel(page) {
    return page.getByRole('region', {name: 'Sideline Learning Coach'}).first();
}

/**
 * Accessibility check of the coach panel with axe: no serious or critical violation (WCAG 2.2 A/AA).
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function expectAccessible(page) {
    const results = await new AxeBuilder({page})
        .include('[data-region="commentary"], [data-region="consent"], [data-region="question"]')
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
    const serious = results.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
    expect(serious.map((v) => `${v.id}: ${v.help}`)).toEqual([]);
}

module.exports = {env, login, panel, expectAccessible};
