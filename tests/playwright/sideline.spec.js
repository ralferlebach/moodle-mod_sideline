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
 * Browser journeys of mod_sideline: consent, commentary, interview, keyboard use, reduced motion and
 * the absence of a teacher view. Works with the React panel (Moodle 5.3) and the AMD panel (4.5 to 5.2).
 *
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
const {test, expect} = require('@playwright/test');
const {env, login, panel, expectAccessible} = require('./helpers');

test('a learner decides on consent first, gets commentary and can withdraw', async({page}) => {
    await login(page, env.user('student1'));
    await page.goto(`/course/view.php?id=${env.courseid}`);
    const coach = panel(page);
    await expect(coach.getByText('May the coach look at your progress?')).toBeVisible();
    await expect(coach.locator('[data-region="commentary"]')).toHaveCount(0);
    await expectAccessible(page);

    await coach.getByRole('button', {name: 'I agree'}).click();
    await expect(coach.getByText('You have completed 0 of 2 activities so far.').first()).toBeAttached();
    await expectAccessible(page);

    await coach.getByRole('button', {name: 'Withdraw my consent'}).click();
    await expect(coach.getByText('You decided not to use the coach. You can change your mind at any time.')).toBeVisible();
});

test('a learner answers the question with the keyboard, sees its effect and deletes the answer', async({page}) => {
    await login(page, env.user('student2'));
    await page.goto(`/course/view.php?id=${env.courseid}`);
    const coach = panel(page);
    await coach.getByRole('button', {name: 'I agree'}).click();
    await expect(coach.getByText('How is it going?')).toBeVisible();
    await expectAccessible(page);

    // Keyboard only: focus the choice, select it with the space bar, submit with Enter.
    const choice = coach.getByRole('radio', {name: 'Overwhelmed'});
    await choice.focus();
    await page.keyboard.press('Space');
    await expect(choice).toBeChecked();
    await coach.getByRole('button', {name: 'Answer', exact: true}).focus();
    await page.keyboard.press('Enter');
    await expect(coach.getByText('Small steps count').first()).toBeAttached();

    await coach.getByRole('button', {name: 'Show my answers'}).click();
    await expect(coach.getByText('One small, concrete next step is suggested.')).toBeVisible();
    await coach.getByRole('button', {name: 'Delete answer', exact: true}).click();
    await expect(coach.getByText('You have not answered any question yet.')).toBeVisible();
});

test('with reduced motion the text is complete at once, otherwise it is typed but readable for screen readers',
    async({browser}) => {
        for (const reducedMotion of ['reduce', 'no-preference']) {
            const context = await browser.newContext({reducedMotion});
            const page = await context.newPage();
            await login(page, env.user('student3'));
            await page.goto(`/mod/sideline/view.php?id=${env.typingcmid}`);
            const block = panel(page).locator('[data-region="commentary"] > div').first();
            const full = await block.locator('[data-region="full"]').textContent({timeout: 15000});
            expect(full.length).toBeGreaterThan(10);
            const typed = block.locator('[data-region="typed"]');
            await expect(typed).toHaveAttribute('aria-hidden', 'true');
            if (reducedMotion === 'reduce') {
                expect(await typed.textContent()).toBe(full);
            } else {
                // Typed progressively, complete in the end.
                await expect(typed).toHaveText(full);
            }
            await context.close();
        }
    });

test('a teacher gets no view of learner data', async({page}) => {
    await login(page, env.user('teacher'));
    await page.goto(`/course/view.php?id=${env.courseid}`);
    // The teacher sees the coach for themselves only: the consent gate of their own, nothing about learners.
    await expect(panel(page).getByText('May the coach look at your progress?')).toBeVisible();
    for (const name of ['Student1', 'Student2', 'Student3']) {
        await expect(panel(page)).not.toContainText(name);
    }
});
