// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Tests of the AMD panel (Moodle 4.5 to 5.2): the same cases as the React panel.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {init} from '../../amd/src/panel';

const keys = ['region', 'loading', 'error', 'retry', 'consentTitle', 'consentText', 'consentAccept', 'consentDecline',
    'consentWithdraw', 'consentDeclined', 'questionSubmit', 'questionSkip', 'questionTitle', 'answersShow', 'answersHide',
    'answersEmpty', 'answerDelete', 'answerEffect', 'personaLabel', 'personaDefault', 'sharingTitle', 'sharingText',
    'sharingPublish', 'sharingNamed', 'sharingReconsent', 'sharingSave', 'sliderValue', 'textRemaining'];
const strings = Object.fromEntries(keys.map((k) => [k, `«${k}»`]));

let axe = null;
try {
    // eslint-disable-next-line global-require
    axe = require('jest-axe');
} catch (e) {
    axe = null;
}
const itAxe = axe ? it : it.skip;

const ok = (extra = {}, blocks = [{key: 'smalltalk', text: 'Hello', actionlabel: '', actionurl: ''}]) => ({
    status: 'ok', consent: 'not_requested', source: 'template', persona: 'mentor', blocks, question: [], personas: [],
    dialogenabled: false, sharingmode: 'individual', grouplevel: '', sharepublish: false, sharenamed: false,
    sharereconsent: false, ...extra,
});
const question = (q) => ({id: 5, qtype: 'mc', prompt: 'How is it going?', options: [], min: 0, max: 10, step: 1,
    minlabel: 'low', maxlabel: 'high', pairs: [], scale: 7, maxlength: 20, ...q});

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
const settle = async() => {
    for (let i = 0; i < 6; i++) {
        await flush();
    }
};
const mount = async(typing = false) => {
    document.body.innerHTML = '';
    const root = document.createElement('div');
    root.id = 'p1';
    root.dataset.props = JSON.stringify({cmid: 7, typing, strings});
    document.body.append(root);
    init('p1');
    await settle();
    return root;
};
const respond = (...responses) => {
    global.mockCall = jest.fn();
    responses.forEach((r) => global.mockCall.mockImplementationOnce(() =>
        (r instanceof Error ? Promise.reject(r) : Promise.resolve(r))));
    global.mockCall.mockImplementation(() => Promise.resolve(responses[responses.length - 1]));
};
const byText = (tag, text) => [...document.querySelectorAll(tag)].find((n) => n.textContent === text);
const click = async(node) => {
    node.click();
    await settle();
};

describe('AMD panel', () => {
    beforeEach(() => {
        window.matchMedia = (query) => ({matches: false, media: query});
    });

    it('asks only for this activity', async() => {
        respond(ok());
        await mount();
        expect(global.mockCall).toHaveBeenCalledWith({methodname: 'mod_sideline_get_commentary', args: {cmid: 7}});
    });

    it('keeps a complete copy for assistive technology and hides the animated copy', async() => {
        jest.useFakeTimers();
        respond(ok({}, [{key: 's', text: 'Hello there', actionlabel: '', actionurl: ''}]));
        document.body.innerHTML = '<div id="p1"></div>';
        document.getElementById('p1').dataset.props = JSON.stringify({cmid: 7, typing: true, strings});
        init('p1');
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();
        jest.advanceTimersByTime(20);
        expect(document.querySelector('[data-region="full"]').textContent).toBe('Hello there');
        const typed = document.querySelector('[data-region="typed"]');
        expect(typed.getAttribute('aria-hidden')).toBe('true');
        expect(typed.textContent.length).toBeLessThan('Hello there'.length);
        jest.useRealTimers();
    });

    it('respects reduced motion', async() => {
        window.matchMedia = (query) => ({matches: query.includes('reduce'), media: query});
        respond(ok({}, [{key: 's', text: 'Hello there', actionlabel: '', actionurl: ''}]));
        await mount(true);
        expect(document.querySelector('[data-region="typed"]').textContent).toBe('Hello there');
    });

    it('renders text as text, never as markup', async() => {
        respond(ok({}, [{key: 'x', text: '<img src=x onerror=alert(1)>', actionlabel: '', actionurl: ''}]));
        await mount();
        expect(document.querySelector('img')).toBeNull();
    });

    it('renders only same-site links', async() => {
        respond(ok({}, [{key: 'a', text: 'Go', actionlabel: 'Evil', actionurl: 'https://evil.example/x'},
            {key: 'b', text: 'Go', actionlabel: 'Good', actionurl: `${window.location.origin}/mod/quiz/view.php?id=3`}]));
        await mount();
        expect(byText('a', 'Evil')).toBeUndefined();
        expect(byText('a', 'Good')).toBeDefined();
    });

    it('asks for consent, sends it and then offers withdrawal', async() => {
        respond({status: 'consent_required', consent: 'not_requested', source: '', persona: '', blocks: []},
            {status: 'accepted'}, ok({consent: 'accepted'}), {status: 'withdrawn'},
            {status: 'consent_required', consent: 'withdrawn', source: '', persona: '', blocks: []});
        await mount();
        await click(byText('button', '«consentAccept»'));
        expect(global.mockCall).toHaveBeenCalledWith({methodname: 'mod_sideline_set_consent', args: {cmid: 7, accept: true}});
        await click(byText('button', '«consentWithdraw»'));
        expect(global.mockCall).toHaveBeenCalledWith({methodname: 'mod_sideline_set_consent', args: {cmid: 7, accept: false}});
        expect(byText('p', '«consentDeclined»')).toBeDefined();
    });

    it('shows an error with retry', async() => {
        respond(new Error('boom'));
        await mount();
        expect(document.querySelector('[role="alert"]')).not.toBeNull();
        expect(byText('button', '«retry»')).toBeDefined();
    });

    it('answers a multiple choice question and keeps focus in the panel', async() => {
        respond(ok({question: [question({options: ['Fine', 'Busy']})]}), {saved: true}, ok());
        await mount();
        const submit = byText('button', '«questionSubmit»');
        expect(submit.disabled).toBe(true);
        document.querySelectorAll('input[type="radio"]')[1].click();
        expect(submit.disabled).toBe(false);
        await click(submit);
        expect(global.mockCall).toHaveBeenCalledWith({methodname: 'mod_sideline_answer_question',
            args: {cmid: 7, questionid: 5, value: '1', skip: false}});
        expect(document.activeElement.getAttribute('role')).toBe('region');
    });

    it('lets the learner skip', async() => {
        respond(ok({question: [question({options: ['A', 'B']})]}), {saved: true}, ok());
        await mount();
        await click(byText('button', '«questionSkip»'));
        expect(global.mockCall).toHaveBeenCalledWith({methodname: 'mod_sideline_answer_question',
            args: {cmid: 7, questionid: 5, value: '', skip: true}});
    });

    it('speaks the slider value', async() => {
        respond(ok({question: [question({qtype: 'slider'})]}));
        await mount();
        const range = document.querySelector('input[type="range"]');
        range.value = '7';
        range.dispatchEvent(new Event('input'));
        expect(range.getAttribute('aria-valuetext')).toBe('«sliderValue» 7');
    });

    it('needs every pair of the differential', async() => {
        respond(ok({question: [question({qtype: 'semdiff', scale: 5, pairs: [{left: 'tense', right: 'relaxed'},
            {left: 'slow', right: 'fast'}]})]}), {saved: true}, ok());
        await mount();
        const submit = byText('button', '«questionSubmit»');
        document.querySelector('[aria-label="tense – relaxed: 2/5"]').click();
        expect(submit.disabled).toBe(true);
        document.querySelector('[aria-label="slow – fast: 5/5"]').click();
        await click(submit);
        expect(global.mockCall).toHaveBeenCalledWith({methodname: 'mod_sideline_answer_question',
            args: {cmid: 7, questionid: 5, value: '[2,5]', skip: false}});
    });

    it('counts remaining characters', async() => {
        respond(ok({question: [question({qtype: 'text', maxlength: 20})]}));
        await mount();
        const area = document.querySelector('textarea');
        area.value = 'abc';
        area.dispatchEvent(new Event('input'));
        expect(document.querySelector('small').textContent).toBe('17 «textRemaining»');
    });

    it('shows, explains and deletes own answers', async() => {
        respond(ok({dialogenabled: true}),
            {answers: [{questionid: 5, prompt: 'Q?', answer: 'Busy', skipped: false, effect: 'Deadlines first'}]},
            {deleted: true}, ok({dialogenabled: true}), {answers: []});
        await mount();
        await click(byText('button', '«answersShow»'));
        expect(document.body.textContent).toContain('Deadlines first');
        expect(byText('button', '«answersHide»').getAttribute('aria-expanded')).toBe('true');
        await click(byText('button', '«answerDelete»'));
        expect(global.mockCall).toHaveBeenCalledWith({methodname: 'mod_sideline_delete_answer', args: {cmid: 7, questionid: 5}});
        expect(byText('p', '«answersEmpty»')).toBeDefined();
    });

    it('chooses a persona with a labelled select', async() => {
        respond(ok({personas: [{key: 'mentor', name: 'Mentor'}, {key: 'reporter', name: 'Reporter'}]}), {persona: 'reporter'}, ok());
        await mount();
        const select = document.querySelector('select');
        expect(document.querySelector(`label[for="${select.id}"]`).textContent).toBe('«personaLabel»');
        select.value = 'reporter';
        select.dispatchEvent(new Event('change'));
        await settle();
        expect(global.mockCall).toHaveBeenCalledWith({methodname: 'mod_sideline_set_persona', args: {cmid: 7, persona: 'reporter'}});
    });

    it('opts in to sharing; the name needs sharing first', async() => {
        respond(ok({sharingmode: 'closed', grouplevel: 'named'}), {publish: true, named: true}, ok());
        await mount();
        const boxes = document.querySelectorAll('input[type="checkbox"]');
        expect(boxes[1].disabled).toBe(true);
        boxes[0].click();
        expect(boxes[1].disabled).toBe(false);
        boxes[1].click();
        await click(byText('button', '«sharingSave»'));
        expect(global.mockCall).toHaveBeenCalledWith({methodname: 'mod_sideline_set_group_sharing',
            args: {cmid: 7, publish: true, named: true}});
    });

    it('has no hard-coded UI text', async() => {
        respond(ok({dialogenabled: true, consent: 'accepted', sharingmode: 'open', grouplevel: 'named_with_group',
            personas: [{key: 'a', name: 'A'}, {key: 'b', name: 'B'}],
            question: [question({qtype: 'slider', minlabel: 'MIN', maxlabel: 'MAX'})]},
        [{key: 's', text: 'DATA', actionlabel: '', actionurl: ''}]));
        const root = await mount();
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const allowed = /^(«[a-zA-Z]+»|DATA|MIN|MAX|A|B|How is it going\?|[0-9]+|:|–|\s|«[a-zA-Z]+» [0-9]+)*$/;
        let node = walker.nextNode();
        while (node) {
            const text = node.textContent.trim();
            if (text !== '') {
                expect(text).toMatch(allowed);
            }
            node = walker.nextNode();
        }
    });

    itAxe('has no detectable accessibility violations', async() => {
        respond(ok({dialogenabled: true, consent: 'accepted', sharingmode: 'closed', grouplevel: 'named',
            personas: [{key: 'a', name: 'A'}, {key: 'b', name: 'B'}],
            question: [question({qtype: 'semdiff', pairs: [{left: 'tense', right: 'relaxed'}]})]}));
        const root = await mount();
        const results = await axe.axe(root);
        expect(results.violations.map((v) => `${v.id}: ${v.help}`)).toEqual([]);
    });
});
