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
 * What a screen reader gets from the panel (issue #25), checked in the browser's accessibility tree, with the
 * React panel (Moodle 5.3) and the AMD panel (4.5 to 5.2): the named region and its content (ARIA snapshot), what
 * live regions announce after each action, where the keyboard focus goes, that the keyboard is never trapped,
 * forced colours (Windows contrast mode) and reduced motion. This is a proxy: it shows what the browser hands to a
 * screen reader, not what NVDA or VoiceOver make of it; the run by hand stays in docs/A11Y_SMOKE.md.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/* eslint-env node, browser */
const {test, expect} = require('@playwright/test');
const {env, login, panel} = require('./helpers');

/**
 * Record what live regions announce: text that appears in an element inside a live region (aria-live polite or
 * assertive, role status, alert or log) and is not hidden from assistive technology.
 *
 * @param {import('@playwright/test').Page} page Page, before its first navigation.
 */
async function recordAnnouncements(page) {
    await page.addInitScript(() => {
        window.slAnnounced = [];
        const LIVE = '[aria-live="polite"], [aria-live="assertive"], [role="status"], [role="alert"], [role="log"]';
        const announce = (node) => {
            const element = node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
            if (!element || !element.closest(LIVE) || element.closest('[aria-hidden="true"]')) {
                return;
            }
            const text = (node.textContent || '').replace(/\s+/g, ' ').trim();
            if (text) {
                window.slAnnounced.push(text);
            }
        };
        new MutationObserver((records) => {
            for (const record of records) {
                (record.type === 'characterData' ? [record.target] : [...record.addedNodes]).forEach(announce);
            }
        }).observe(document, {subtree: true, childList: true, characterData: true});
    });
}

/**
 * What was announced since the last call.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @returns {Promise<string>} The announcements, joined.
 */
async function announced(page) {
    return page.evaluate(() => window.slAnnounced.splice(0).join(' | '));
}

/**
 * Wait until a text has been announced, then forget the announcements so far.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string} text Text.
 */
async function expectAnnounced(page, text) {
    await expect.poll(() => page.evaluate(() => window.slAnnounced.join(' | '))).toContain(text);
    await announced(page);
}

/**
 * Whether the keyboard focus is inside the panel.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @returns {Promise<boolean>}
 */
async function focusInPanel(page) {
    return panel(page).evaluate((el) => el.contains(document.activeElement));
}

/**
 * Press a key until the focus enters or leaves the panel.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string} key Key, such as Tab or Shift+Tab.
 * @param {boolean} inside Wanted state.
 * @returns {Promise<number>} Key presses needed, or -1 after 120 presses.
 */
async function tabUntil(page, key, inside) {
    for (let i = 1; i <= 120; i++) {
        await page.keyboard.press(key);
        if (await focusInPanel(page) === inside) {
            return i;
        }
    }
    return -1;
}

test('a screen reader gets the panel as a named region with the full text once, never the typing', async({browser}) => {
    const context = await browser.newContext({reducedMotion: 'no-preference'});
    const page = await context.newPage();
    await login(page, env.user('student6'));
    await page.goto(`/mod/sideline/view.php?id=${env.typingcmid}`);
    const block = panel(page).locator('[data-region="commentary"] > div').first();
    const full = (await block.locator('[data-region="full"]').textContent({timeout: 15000})).trim();
    // While the text is still being typed, the accessibility tree already holds all of it, and only once.
    const snapshot = await panel(page).ariaSnapshot();
    expect(snapshot).toMatch(/^- region "Sideline Learning Coach"/);
    expect(snapshot.split(full).length - 1).toBe(1);
    await expect(block.locator('[data-region="typed"]')).toHaveText(full);
    expect((await panel(page).ariaSnapshot()).split(full).length - 1).toBe(1);
    await context.close();
});

test('consent, an answer and the withdrawal by keyboard are announced and keep the focus in the panel', async({page}) => {
    await recordAnnouncements(page);
    await login(page, env.user('student7'));
    await page.goto(`/course/view.php?id=${env.courseid}`);
    const coach = panel(page);
    await expect(coach.getByRole('button', {name: 'I agree'})).toBeVisible();
    await expect(coach).toHaveAttribute('aria-live', 'polite');
    await announced(page);

    await coach.getByRole('button', {name: 'I agree'}).focus();
    await page.keyboard.press('Enter');
    await expectAnnounced(page, 'You have completed 0 of 2 activities so far.');
    await expect(coach.getByText('How is it going?')).toBeVisible();
    await expect.poll(() => focusInPanel(page)).toBe(true);

    // An answer without effect changes nothing visible: the panel still says that it was saved.
    await coach.getByRole('radio', {name: 'Fine'}).focus();
    await page.keyboard.press('Space');
    await coach.getByRole('button', {name: 'Answer', exact: true}).focus();
    await page.keyboard.press('Enter');
    await expectAnnounced(page, 'Saved.');
    await expect(coach.getByText('How is it going?')).toHaveCount(0);
    await expect.poll(() => focusInPanel(page)).toBe(true);

    await coach.getByRole('button', {name: 'Withdraw my consent'}).focus();
    await page.keyboard.press('Enter');
    await expectAnnounced(page, 'You decided not to use the coach. You can change your mind at any time.');
    await expect.poll(() => focusInPanel(page)).toBe(true);
});

test('the keyboard reaches the panel, leaves it in both directions and is never trapped', async({page}) => {
    await login(page, env.user('student8'));
    await page.goto(`/mod/sideline/view.php?id=${env.a11ycmid}`);
    await panel(page).getByRole('button', {name: 'I agree'}).click();
    await expect(panel(page).getByRole('checkbox', {name: 'A plan'})).toBeVisible();
    await page.locator('body').focus();
    expect(await tabUntil(page, 'Tab', true)).toBeGreaterThan(0);
    expect(await tabUntil(page, 'Tab', false)).toBeGreaterThan(0);
    expect(await tabUntil(page, 'Shift+Tab', true)).toBeGreaterThan(0);
    expect(await tabUntil(page, 'Shift+Tab', false)).toBeGreaterThan(0);
});

test('with forced colours the keyboard focus and the edges of the controls stay visible', async({browser}) => {
    const context = await browser.newContext({forcedColors: 'active'});
    const page = await context.newPage();
    await login(page, env.user('student8'));
    await page.goto(`/mod/sideline/view.php?id=${env.a11ycmid}`);
    const first = panel(page).getByRole('checkbox', {name: 'A plan'});
    await expect(first).toBeVisible();
    await page.locator('body').focus();
    for (let i = 0; i < 120 && !(await first.evaluate((el) => el === document.activeElement)); i++) {
        await page.keyboard.press('Tab');
    }
    await expect(first).toBeFocused();
    // Forced colours drop box shadows: the focus must be an outline.
    const focus = await first.evaluate((el) => {
        const style = getComputedStyle(el);
        return {style: style.outlineStyle, width: parseFloat(style.outlineWidth)};
    });
    expect(focus.style).not.toBe('none');
    expect(focus.width).toBeGreaterThan(0);
    const edgeless = await panel(page).locator('button, input[type="checkbox"], input[type="radio"]').evaluateAll(
        (elements) => elements.filter((el) => {
            const style = getComputedStyle(el);
            // A native checkbox or radio button draws its own edge; everything else needs a border.
            const native = ['checkbox', 'radio'].includes(el.type) && style.appearance !== 'none';
            return el.getBoundingClientRect().width > 0 && !native
                && (style.borderTopStyle === 'none' || parseFloat(style.borderTopWidth) === 0);
        }).map((el) => el.outerHTML.slice(0, 80)));
    expect(edgeless).toEqual([]);
    await context.close();
});

test('with reduced motion nothing in the panel moves', async({browser}) => {
    const context = await browser.newContext({reducedMotion: 'reduce'});
    const page = await context.newPage();
    await login(page, env.user('student6'));
    await page.goto(`/mod/sideline/view.php?id=${env.typingcmid}`);
    const block = panel(page).locator('[data-region="commentary"] > div').first();
    const full = (await block.locator('[data-region="full"]').textContent({timeout: 15000})).trim();
    expect((await block.locator('[data-region="typed"]').textContent()).trim()).toBe(full);
    const running = await panel(page).evaluate((el) => el.getAnimations({subtree: true})
        .filter((a) => a.playState === 'running').map((a) => a.animationName || a.constructor.name));
    expect(running).toEqual([]);
    await context.close();
});
