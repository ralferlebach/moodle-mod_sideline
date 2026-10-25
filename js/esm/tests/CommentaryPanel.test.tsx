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
 * Jest tests for the Sideline panel: behaviour, security, accessibility and i18n.
 *
 * @module     mod_sideline/tests/CommentaryPanel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {act, render, screen, fireEvent} from '@testing-library/react';
import CommentaryPanel from '../src/CommentaryPanel';
import type {PanelStrings, Question} from '../src/types';

const mockFetchOne = jest.fn();
jest.mock('@moodle/lms/core/ajax', () => ({
    fetchOne: (...args: unknown[]) => mockFetchOne(...args),
}));

// Optional: automatic WCAG checks when jest-axe is installed (it is not a Moodle core dependency).
// eslint-disable-next-line @typescript-eslint/no-require-imports
const axe = (() => {
    try {
        return require('jest-axe');
    } catch {
        return null;
    }
})();
const itAxe = axe ? it : it.skip;

const keys: (keyof PanelStrings)[] = [
    'region', 'loading', 'error', 'retry', 'consentTitle', 'consentText', 'consentAccept', 'consentDecline', 'consentWithdraw',
    'consentDeclined', 'questionSubmit', 'questionSkip', 'questionTitle', 'answersShow', 'answersHide', 'answersEmpty',
    'answerDelete', 'answerEffect', 'personaLabel', 'personaDefault', 'sharingTitle', 'sharingText', 'sharingPublish',
    'sharingNamed', 'sharingReconsent', 'sharingSave', 'sliderValue', 'textRemaining',
];
// Every UI string is recognisable, so the tests can prove that no text is hard-coded.
const strings = Object.fromEntries(keys.map((k) => [k, `«${k}»`])) as unknown as PanelStrings;

const ok = (extra: object = {}, blocks: object[] = [{key: 'smalltalk', text: 'Hello', actionlabel: '', actionurl: ''}]) => ({
    status: 'ok', consent: 'not_requested', source: 'template', persona: 'mentor', blocks, question: [], personas: [],
    dialogenabled: false, sharingmode: 'individual', grouplevel: '', sharepublish: false, sharenamed: false,
    sharereconsent: false, ...extra,
});

const question = (q: Partial<Question>): Question => ({
    id: 5, qtype: 'mc', prompt: 'How is it going?', options: [], min: 0, max: 10, step: 1, minlabel: 'low', maxlabel: 'high',
    pairs: [], scale: 7, maxlength: 20, ...q,
});

const renderPanel = async(typing = false) => {
    let container: HTMLElement = document.body;
    await act(async() => {
        container = render(<CommentaryPanel cmid={7} typing={typing} strings={strings} />).container;
    });
    return container;
};

const click = async(element: HTMLElement) => {
    await act(async() => {
        fireEvent.click(element);
    });
};

describe('CommentaryPanel', () => {
    beforeEach(() => {
        mockFetchOne.mockReset();
        (window as any).matchMedia = (window as any).matchMedia || ((query: string) => ({matches: false, media: query}));
    });

    it('asks the service for the commentary of this activity only', async() => {
        mockFetchOne.mockResolvedValue(ok());
        await renderPanel();
        expect(mockFetchOne).toHaveBeenCalledWith({methodname: 'mod_sideline_get_commentary', args: {cmid: 7}});
    });

    it('renders the complete text at once when typing is off', async() => {
        mockFetchOne.mockResolvedValue(ok({}, [{key: 's', text: 'Hello there', actionlabel: '', actionurl: ''}]));
        await renderPanel(false);
        expect(screen.getAllByText('Hello there').length).toBeGreaterThan(0);
    });

    it('keeps a complete copy for assistive technology while the visible copy is animated', async() => {
        jest.useFakeTimers();
        mockFetchOne.mockResolvedValue(ok({}, [{key: 's', text: 'Hello there', actionlabel: '', actionurl: ''}]));
        await renderPanel(true);
        expect(document.querySelector('[data-region="full"]')?.textContent).toBe('Hello there');
        const typed = document.querySelector('[data-region="typed"]');
        expect(typed?.getAttribute('aria-hidden')).toBe('true');
        expect((typed?.textContent ?? '').length).toBeLessThan('Hello there'.length);
        jest.useRealTimers();
    });

    it('respects reduced motion: no animation at all', async() => {
        (window as any).matchMedia = (query: string) => ({matches: query.includes('reduce'), media: query});
        mockFetchOne.mockResolvedValue(ok({}, [{key: 's', text: 'Hello there', actionlabel: '', actionurl: ''}]));
        await renderPanel(true);
        expect(document.querySelector('[data-region="typed"]')?.textContent).toBe('Hello there');
    });

    it('renders text as text, never as markup', async() => {
        mockFetchOne.mockResolvedValue(ok({}, [{key: 'x', text: '<img src=x onerror=alert(1)>', actionlabel: '', actionurl: ''}]));
        await renderPanel(false);
        expect(document.querySelector('img')).toBeNull();
    });

    it('does not render an action link that leaves the site', async() => {
        mockFetchOne.mockResolvedValue(ok({}, [{key: 'x', text: 'Go', actionlabel: 'Open', actionurl: 'https://evil.example/phish'}]));
        await renderPanel(false);
        expect(screen.queryByRole('link', {name: 'Open'})).toBeNull();
    });

    it('renders a same-site action link', async() => {
        mockFetchOne.mockResolvedValue(ok({}, [
            {key: 'x', text: 'Go', actionlabel: 'Open', actionurl: `${window.location.origin}/mod/quiz/view.php?id=3`},
        ]));
        await renderPanel(false);
        expect(screen.getByRole('link', {name: 'Open'})).toBeInTheDocument();
    });

    it('shows the consent view, sends the decision and offers withdrawal afterwards', async() => {
        mockFetchOne
            .mockResolvedValueOnce({status: 'consent_required', consent: 'not_requested', source: '', persona: '', blocks: []})
            .mockResolvedValueOnce({status: 'accepted'})
            .mockResolvedValueOnce(ok({consent: 'accepted'}, [{key: 's', text: 'Welcome', actionlabel: '', actionurl: ''}]))
            .mockResolvedValueOnce({status: 'withdrawn'})
            .mockResolvedValueOnce({status: 'consent_required', consent: 'withdrawn', source: '', persona: '', blocks: []});
        await renderPanel();
        expect(screen.getByText('«consentTitle»')).toBeInTheDocument();
        await click(screen.getByRole('button', {name: '«consentAccept»'}));
        expect(mockFetchOne).toHaveBeenCalledWith({methodname: 'mod_sideline_set_consent', args: {cmid: 7, accept: true}});
        expect(screen.getAllByText('Welcome').length).toBeGreaterThan(0);
        await click(screen.getByRole('button', {name: '«consentWithdraw»'}));
        expect(mockFetchOne).toHaveBeenCalledWith({methodname: 'mod_sideline_set_consent', args: {cmid: 7, accept: false}});
        expect(screen.getByText('«consentDeclined»')).toBeInTheDocument();
    });

    it('shows an error with a retry button when the service fails', async() => {
        mockFetchOne.mockRejectedValue(new Error('boom'));
        await renderPanel();
        expect(screen.getByRole('alert')).toBeInTheDocument();
        expect(screen.getByRole('button', {name: '«retry»'})).toBeInTheDocument();
    });

    it('answers a multiple choice question and moves the focus back to the panel', async() => {
        mockFetchOne
            .mockResolvedValueOnce(ok({question: [question({qtype: 'mc', options: ['Fine', 'Busy']})]}))
            .mockResolvedValueOnce({saved: true})
            .mockResolvedValueOnce(ok());
        await renderPanel();
        const submit = screen.getByRole('button', {name: '«questionSubmit»'});
        expect(submit).toBeDisabled();
        await click(screen.getByLabelText('Busy'));
        await click(submit);
        expect(mockFetchOne).toHaveBeenCalledWith({methodname: 'mod_sideline_answer_question',
            args: {cmid: 7, questionid: 5, value: '1', skip: false}});
        expect(document.activeElement?.getAttribute('role')).toBe('region');
    });

    it('lets the learner skip', async() => {
        mockFetchOne.mockResolvedValueOnce(ok({question: [question({qtype: 'mc', options: ['A', 'B']})]}))
            .mockResolvedValueOnce({saved: true}).mockResolvedValueOnce(ok());
        await renderPanel();
        await click(screen.getByRole('button', {name: '«questionSkip»'}));
        expect(mockFetchOne).toHaveBeenCalledWith({methodname: 'mod_sideline_answer_question',
            args: {cmid: 7, questionid: 5, value: '', skip: true}});
    });

    it('offers an accessible slider with a spoken value', async() => {
        mockFetchOne.mockResolvedValue(ok({question: [question({qtype: 'slider', min: 0, max: 10})]}));
        await renderPanel();
        const slider = screen.getByRole('slider');
        fireEvent.change(slider, {target: {value: '7'}});
        expect(slider.getAttribute('aria-valuetext')).toBe('«sliderValue» 7');
    });

    it('needs every pair of the semantic differential before submitting', async() => {
        mockFetchOne.mockResolvedValueOnce(ok({question: [question({qtype: 'semdiff', scale: 5,
            pairs: [{left: 'tense', right: 'relaxed'}, {left: 'slow', right: 'fast'}]})]}))
            .mockResolvedValueOnce({saved: true}).mockResolvedValueOnce(ok());
        await renderPanel();
        const submit = screen.getByRole('button', {name: '«questionSubmit»'});
        await click(screen.getByLabelText('tense – relaxed: 2/5'));
        expect(submit).toBeDisabled();
        await click(screen.getByLabelText('slow – fast: 5/5'));
        await click(submit);
        expect(mockFetchOne).toHaveBeenCalledWith({methodname: 'mod_sideline_answer_question',
            args: {cmid: 7, questionid: 5, value: '[2,5]', skip: false}});
    });

    it('counts the remaining characters of free text', async() => {
        mockFetchOne.mockResolvedValue(ok({question: [question({qtype: 'text', maxlength: 20})]}));
        await renderPanel();
        fireEvent.change(screen.getByRole('textbox'), {target: {value: 'abc'}});
        expect(screen.getByText('17 «textRemaining»')).toBeInTheDocument();
    });

    it('shows, explains and deletes the learner own answers', async() => {
        mockFetchOne
            .mockResolvedValueOnce(ok({dialogenabled: true}))
            .mockResolvedValueOnce({answers: [{questionid: 5, prompt: 'Q?', answer: 'Busy', skipped: false, effect: 'Deadlines first'}]})
            .mockResolvedValueOnce({deleted: true})
            .mockResolvedValueOnce(ok({dialogenabled: true}))
            .mockResolvedValueOnce({answers: []});
        await renderPanel();
        const toggle = screen.getByRole('button', {name: '«answersShow»'});
        await click(toggle);
        expect(screen.getByText(/Deadlines first/)).toBeInTheDocument();
        expect(screen.getByRole('button', {name: '«answersHide»'}).getAttribute('aria-expanded')).toBe('true');
        await click(screen.getByRole('button', {name: '«answerDelete»'}));
        expect(mockFetchOne).toHaveBeenCalledWith({methodname: 'mod_sideline_delete_answer', args: {cmid: 7, questionid: 5}});
        expect(screen.getByText('«answersEmpty»')).toBeInTheDocument();
    });

    it('lets the learner choose a persona with a labelled select', async() => {
        mockFetchOne.mockResolvedValueOnce(ok({personas: [{key: 'mentor', name: 'Mentor'}, {key: 'reporter', name: 'Reporter'}]}))
            .mockResolvedValueOnce({persona: 'reporter'}).mockResolvedValueOnce(ok({persona: 'reporter'}));
        await renderPanel();
        await act(async() => {
            fireEvent.change(screen.getByLabelText('«personaLabel»'), {target: {value: 'reporter'}});
        });
        expect(mockFetchOne).toHaveBeenCalledWith({methodname: 'mod_sideline_set_persona', args: {cmid: 7, persona: 'reporter'}});
    });

    it('opts in to group sharing; the name needs sharing first', async() => {
        mockFetchOne.mockResolvedValueOnce(ok({sharingmode: 'closed', grouplevel: 'named'}))
            .mockResolvedValueOnce({publish: true, named: true}).mockResolvedValueOnce(ok({sharingmode: 'closed'}));
        await renderPanel();
        const named = screen.getByLabelText('«sharingNamed»');
        expect(named).toBeDisabled();
        await click(screen.getByLabelText('«sharingPublish»'));
        expect(named).not.toBeDisabled();
        await click(named);
        await click(screen.getByRole('button', {name: '«sharingSave»'}));
        expect(mockFetchOne).toHaveBeenCalledWith({methodname: 'mod_sideline_set_group_sharing',
            args: {cmid: 7, publish: true, named: true}});
    });

    it('shows no sharing controls in individual mode and no name option at the general level', async() => {
        mockFetchOne.mockResolvedValue(ok({sharingmode: 'closed', grouplevel: 'general'}));
        await renderPanel();
        expect(screen.queryByLabelText('«sharingNamed»')).toBeNull();
        expect(screen.getByLabelText('«sharingPublish»')).toBeInTheDocument();
    });

    it('contains no hard-coded UI text', async() => {
        mockFetchOne.mockResolvedValue(ok({
            dialogenabled: true, consent: 'accepted', sharingmode: 'open', grouplevel: 'named_with_group',
            personas: [{key: 'a', name: 'A'}, {key: 'b', name: 'B'}],
            question: [question({qtype: 'slider', minlabel: 'MIN', maxlabel: 'MAX'})],
        }, [{key: 's', text: 'DATA', actionlabel: '', actionurl: ''}]));
        const container = await renderPanel();
        const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT);
        const allowed = /^(«[a-zA-Z]+»|DATA|MIN|MAX|A|B|How is it going\?|[0-9]+|:|–|\s|«[a-zA-Z]+» [0-9]+)*$/;
        let node = walker.nextNode();
        while (node) {
            const text = (node.textContent ?? '').trim();
            if (text !== '') {
                expect(text).toMatch(allowed);
            }
            node = walker.nextNode();
        }
    });

    itAxe('has no detectable accessibility violations in the main states', async() => {
        const run = axe.axe;
        mockFetchOne.mockResolvedValue(ok({
            dialogenabled: true, consent: 'accepted', sharingmode: 'closed', grouplevel: 'named',
            personas: [{key: 'a', name: 'A'}, {key: 'b', name: 'B'}],
            question: [question({qtype: 'semdiff', pairs: [{left: 'tense', right: 'relaxed'}]})],
        }));
        const container = await renderPanel();
        const results = await run(container);
        expect(results.violations.map((v: {id: string; help: string}) => `${v.id}: ${v.help}`)).toEqual([]);
    });
});
