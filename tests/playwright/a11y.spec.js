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
 * Accessibility checks of the panel in the real browser path (issue #25), with the React panel (Moodle 5.3)
 * and the AMD panel (4.5 to 5.2): reflow at 400 % and 200 % zoom, target sizes, a visible keyboard focus,
 * right-to-left layout and a touch device. Screen readers are checked by hand (docs/A11Y_SMOKE.md).
 *
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/* eslint-env node, browser */
const {test, expect, devices} = require('@playwright/test');
const {env, login, panel, expectAccessible} = require('./helpers');

/**
 * Open the accessibility coach as a learner in a browser context of its own and agree to the coach.
 *
 * @param {import('@playwright/test').Browser} browser Browser.
 * @param {string} name Learner.
 * @param {object} options Context options.
 * @returns {Promise<{context: import('@playwright/test').BrowserContext, page: import('@playwright/test').Page}>}
 */
async function open(browser, name, options) {
    const context = await browser.newContext(options);
    const page = await context.newPage();
    await login(page, env.user(name));
    await page.goto(`/mod/sideline/view.php?id=${env.a11ycmid}`);
    const agree = panel(page).getByRole('button', {name: 'I agree'});
    const question = panel(page).getByRole('checkbox', {name: 'A plan'});
    // Wait until the panel has loaded: either the consent question or, after an earlier consent, the question.
    await expect(agree.or(question)).toBeVisible();
    if (await agree.isVisible()) {
        await agree.click();
    }
    await expect(question).toBeVisible();
    return {context, page};
}

/**
 * WCAG 1.4.10: no horizontal scrolling of the page, the panel within the viewport and without overflow of its own.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function expectReflow(page) {
    const width = page.viewportSize().width;
    const box = await panel(page).boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(width + 1);
    const overflow = await panel(page).evaluate((el) => ({
        panel: el.scrollWidth - el.clientWidth,
        page: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    }));
    expect(overflow.panel).toBeLessThanOrEqual(1);
    expect(overflow.page).toBeLessThanOrEqual(1);
}

/**
 * WCAG 2.5.8: every button, checkbox and radio button of the panel at least 24 by 24 CSS pixels.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function expectTargetSize(page) {
    const small = await panel(page).locator('button, input[type="checkbox"], input[type="radio"]').evaluateAll((elements) =>
        elements.filter((e) => {
            const r = e.getBoundingClientRect();
            return r.width > 0 && (r.width < 24 || r.height < 24);
        }).map((e) => e.outerHTML.slice(0, 80)));
    expect(small).toEqual([]);
}

for (const [zoom, width] of [['400 %', 320], ['200 %', 640]]) {
    test(`at ${zoom} zoom the panel reflows, keeps its target sizes and shows the keyboard focus`, async({browser}) => {
        // A viewport of 320 CSS pixels is what a window of 1280 pixels shows at 400 % (WCAG 1.4.10).
        const {context, page} = await open(browser, 'student4', {viewport: {width, height: 800}});
        await expectReflow(page);
        await expectTargetSize(page);
        await expectAccessible(page);

        // Keyboard only: tab to the first checkbox of the panel, it shows a focus indicator.
        const first = panel(page).getByRole('checkbox', {name: 'A plan'});
        for (let i = 0; i < 80 && !(await first.evaluate((el) => el === document.activeElement)); i++) {
            await page.keyboard.press('Tab');
        }
        await expect(first).toBeFocused();
        const indicator = await first.evaluate((el) => {
            const style = getComputedStyle(el);
            return (style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0) || style.boxShadow !== 'none';
        });
        expect(indicator).toBe(true);
        await page.keyboard.press('Space');
        await expect(first).toBeChecked();
        await context.close();
    });
}

test('in a right-to-left language the panel is mirrored and every checkbox stays beside its label', async({browser}) => {
    const {context, page} = await open(browser, 'student5', {viewport: {width: 1280, height: 900}});
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    const boxes = panel(page).locator('.form-check');
    const count = await boxes.count();
    expect(count).toBeGreaterThan(1);
    for (let i = 0; i < count; i++) {
        const check = boxes.nth(i);
        const input = await check.locator('input').boundingBox();
        const label = await check.locator('label').boundingBox();
        const own = await check.boundingBox();
        // Right to left: the box is on the right of its label, within its own row, and they do not overlap.
        expect(input.x).toBeGreaterThanOrEqual(label.x + label.width - 1);
        expect(input.x + input.width).toBeLessThanOrEqual(own.x + own.width + 1);
    }
    await expectReflow(page);
    await expectAccessible(page);
    await context.close();
});

test('on a touch device the consent and the question work by tapping', async({browser}) => {
    const {context, page} = await open(browser, 'student4', {...devices['Pixel 7']});
    const box = panel(page).getByRole('checkbox', {name: 'A break'});
    await box.tap();
    await expect(box).toBeChecked();
    await expectReflow(page);
    await expectTargetSize(page);
    await context.close();
});
