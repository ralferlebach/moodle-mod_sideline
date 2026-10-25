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
 * Sideline panel for Moodle 4.5 to 5.2 (no complete React pipeline there).
 *
 * Same behaviour, roles and web services as js/esm/src/CommentaryPanel.tsx. Data is only ever
 * written with textContent, never as markup. Bootstrap 4 (Moodle 4.5) and Bootstrap 5 (Moodle 5.x)
 * class names are both set where they differ.
 *
 * @module     mod_sideline/panel
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

const HIDDEN = 'sr-only visually-hidden';
const GAP = 'mr-2 me-2';
let uniq = 0;

/**
 * Call a web service of this plugin.
 *
 * @param {string} name Function name without the mod_sideline_ prefix.
 * @param {Object} args Arguments.
 * @returns {Promise}
 */
const call = (name, args) => Ajax.call([{methodname: 'mod_sideline_' + name, args}])[0];

/**
 * Create an element.
 *
 * @param {string} tag Tag name.
 * @param {Object} attrs Attributes; "text" sets textContent, "on" an object of listeners.
 * @param {Array} children Child nodes.
 * @returns {HTMLElement}
 */
const el = (tag, attrs = {}, children = []) => {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([key, value]) => {
        if (value === undefined || value === null || value === false) {
            return;
        }
        if (key === 'text') {
            node.textContent = value;
        } else if (key === 'on') {
            Object.entries(value).forEach(([event, handler]) => node.addEventListener(event, handler));
        } else if (key === 'className') {
            node.className = value;
        } else {
            node.setAttribute(key, value === true ? '' : String(value));
        }
    });
    children.filter((c) => c).forEach((child) => node.append(child));
    return node;
};

/**
 * Only links that stay on this site are rendered as links.
 *
 * @param {string} url URL from the service.
 * @returns {boolean}
 */
const isSameOrigin = (url) => {
    try {
        return new URL(url, window.location.origin).origin === window.location.origin;
    } catch (e) {
        return false;
    }
};

/**
 * Whether the user asked for reduced motion.
 *
 * @returns {boolean}
 */
const reducedMotion = () => !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

/**
 * A message block: animated visual copy, complete copy for assistive technology.
 *
 * @param {Object} block Block from the service.
 * @param {boolean} typing Typing animation.
 * @returns {HTMLElement}
 */
const renderBlock = (block, typing) => {
    const typed = el('p', {className: 'mb-1', 'aria-hidden': 'true', 'data-region': 'typed'});
    const full = el('p', {className: HIDDEN, 'data-region': 'full', text: block.text});
    const wrapper = el('div', {className: 'mod_sideline-block mb-2', 'data-block-key': block.key}, [typed, full]);
    const finish = () => {
        typed.textContent = block.text;
        if (block.actionurl && isSameOrigin(block.actionurl)) {
            wrapper.append(el('a', {className: 'btn btn-secondary btn-sm', href: block.actionurl, text: block.actionlabel}));
        }
    };
    if (!typing || reducedMotion()) {
        finish();
        return wrapper;
    }
    let shown = 0;
    const timer = window.setInterval(() => {
        shown += 2;
        typed.textContent = block.text.slice(0, shown);
        if (shown >= block.text.length) {
            window.clearInterval(timer);
            finish();
        }
    }, 20);
    return wrapper;
};

/**
 * The form of a due question.
 *
 * @param {Object} q Question.
 * @param {Object} s Strings.
 * @param {Function} submit Callback (value, skip).
 * @returns {HTMLElement}
 */
const renderQuestion = (q, s, submit) => {
    const id = 'mod_sideline_q' + (++uniq);
    const legend = el('legend', {id: id + '-legend', className: 'h6', text: s.questionTitle + ': ' + q.prompt});
    const fieldset = el('fieldset', {}, [legend]);
    const submitButton = el('button', {type: 'submit', className: 'btn btn-primary btn-sm mt-2 ' + GAP, text: s.questionSubmit,
        disabled: true});
    let value = () => '';
    let complete = () => false;
    const update = () => {
        submitButton.disabled = !complete();
    };

    if (q.qtype === 'mc') {
        q.options.forEach((label, index) => {
            const input = el('input', {className: 'form-check-input', type: 'radio', name: id, id: id + '-' + index, value: index,
                on: {change: update}});
            fieldset.append(el('div', {className: 'form-check'}, [input,
                el('label', {className: 'form-check-label', "for": id + '-' + index, text: label})]));
        });
        const checked = () => fieldset.querySelector('input:checked');
        value = () => (checked() ? checked().value : '');
        complete = () => !!checked();
    } else if (q.qtype === 'text') {
        const remaining = el('small', {id: id + '-remaining', className: 'form-text', 'aria-live': 'polite'});
        const area = el('textarea', {className: 'form-control', rows: 3, maxlength: q.maxlength, 'aria-labelledby': id + '-legend',
            'aria-describedby': id + '-remaining'});
        const count = () => {
            remaining.textContent = (q.maxlength - area.value.length) + ' ' + s.textRemaining;
            update();
        };
        area.addEventListener('input', count);
        fieldset.append(area, remaining);
        count();
        value = () => area.value;
        complete = () => area.value.trim() !== '';
    } else if (q.qtype === 'slider') {
        const output = el('output', {'aria-live': 'polite', text: q.min});
        const range = el('input', {type: 'range', className: 'form-range custom-range', min: q.min, max: q.max, step: q.step,
            value: q.min, 'aria-labelledby': id + '-legend', 'aria-valuetext': s.sliderValue + ' ' + q.min});
        range.addEventListener('input', () => {
            output.textContent = range.value;
            range.setAttribute('aria-valuetext', s.sliderValue + ' ' + range.value);
        });
        fieldset.append(el('div', {className: 'd-flex align-items-center'}, [
            el('span', {'aria-hidden': 'true', className: GAP, text: q.minlabel}), range,
            el('span', {'aria-hidden': 'true', className: 'ml-2 ms-2 ' + GAP, text: q.maxlabel}), output]));
        value = () => range.value;
        complete = () => true;
    } else {
        const ratings = q.pairs.map(() => null);
        q.pairs.forEach((pair, p) => {
            const row = el('div', {className: 'd-flex align-items-center'}, [
                el('span', {'aria-hidden': 'true', className: GAP, text: pair.left})]);
            for (let point = 1; point <= q.scale; point++) {
                row.append(el('input', {type: 'radio', className: 'form-check-input position-static ' + GAP, name: id + '-' + p,
                    'aria-label': pair.left + ' – ' + pair.right + ': ' + point + '/' + q.scale,
                    on: {change: () => {
                        ratings[p] = point;
                        update();
                    }}}));
            }
            row.append(el('span', {'aria-hidden': 'true', text: pair.right}));
            const pairlegend = el('legend', {className: 'h6', text: pair.left + ' – ' + pair.right});
            fieldset.append(el('fieldset', {className: 'mb-2'}, [pairlegend, row]));
        });
        value = () => JSON.stringify(ratings);
        complete = () => ratings.every((r) => r !== null);
    }
    update();

    const form = el('form', {className: 'mt-3', 'data-region': 'question'}, [fieldset, submitButton,
        el('button', {type: 'button', className: 'btn btn-link btn-sm mt-2', text: s.questionSkip,
            on: {click: () => submit('', true)}})]);
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        if (complete()) {
            submit(value(), false);
        }
    });
    return form;
};

/**
 * Mount a panel.
 *
 * @param {string} rootid Id of the mount element.
 */
export const init = (rootid) => {
    const root = document.getElementById(rootid);
    if (!root || root.dataset.mounted) {
        return;
    }
    root.dataset.mounted = '1';
    const props = JSON.parse(root.dataset.props || '{}');
    const s = props.strings || {};
    const cmid = props.cmid;
    const section = el('section', {className: 'mod_sideline-panel card card-body', role: 'region', 'aria-label': s.region,
        'aria-live': 'polite', tabindex: '-1'});
    root.append(section);
    let answersOpen = false;
    const uid = 'mod_sideline_p' + (++uniq);

    const show = (...nodes) => {
        section.replaceChildren(...nodes);
    };
    const fail = () => show(el('div', {role: 'alert'}, [el('p', {text: s.error}),
        el('button', {type: 'button', className: 'btn btn-secondary btn-sm', text: s.retry, on: {click: () => load()}})]));
    const act = (promise) => promise.then(() => load()).then(() => section.focus()).catch(fail);

    const deleteAnswer = (questionid) => call('delete_answer', {cmid, questionid}).then(() => load()).catch(fail);

    const answerList = (container, answers) => {
        container.replaceChildren();
        if (!answers.length) {
            container.append(el('p', {className: 'small', text: s.answersEmpty}));
            return container;
        }
        const list = el('ul', {className: 'list-unstyled small'});
        answers.forEach((a) => {
            list.append(el('li', {className: 'mb-2'}, [
                el('strong', {text: a.prompt}), document.createTextNode(' ' + (a.skipped ? '–' : a.answer)), el('br'),
                el('span', {text: s.answerEffect + ': ' + a.effect}), document.createTextNode(' '),
                el('button', {type: 'button', className: 'btn btn-link btn-sm p-0', text: s.answerDelete,
                    on: {click: () => deleteAnswer(a.questionid)}}),
            ]));
        });
        container.append(list);
        return container;
    };

    const renderAnswers = (container) => call('get_my_answers', {cmid}).then((result) => answerList(container, result.answers));

    const render = (data) => {
        if (data.status === 'consent_required') {
            const nodes = [el('h3', {className: 'h5', text: s.consentTitle}), el('p', {text: s.consentText})];
            if (data.consent === 'declined' || data.consent === 'withdrawn') {
                nodes.push(el('p', {text: s.consentDeclined}));
            }
            nodes.push(el('button', {type: 'button', className: 'btn btn-primary btn-sm ' + GAP, text: s.consentAccept,
                on: {click: () => act(call('set_consent', {cmid, accept: true}))}}));
            if (data.consent === 'not_requested' || data.consent === 'reconsent_required') {
                nodes.push(el('button', {type: 'button', className: 'btn btn-secondary btn-sm', text: s.consentDecline,
                    on: {click: () => act(call('set_consent', {cmid, accept: false}))}}));
            }
            show(el('div', {'data-region': 'consent'}, nodes));
            return;
        }

        const nodes = [el('div', {'data-region': 'commentary'}, data.blocks.map((b) => renderBlock(b, !!props.typing)))];
        const question = (data.question || [])[0];
        if (question) {
            nodes.push(renderQuestion(question, s, (value, skip) =>
                act(call('answer_question', {cmid, questionid: question.id, value, skip}))));
        }
        if ((data.personas || []).length > 1) {
            const select = el('select', {id: uid + '-persona',
                className: 'custom-select form-select form-select-sm w-auto d-inline-block',
                on: {change: () => act(call('set_persona', {cmid, persona: select.value}))}});
            data.personas.forEach((p) => {
                select.append(el('option', {value: p.key, text: p.name, selected: p.key === data.persona}));
            });
            nodes.push(el('div', {className: 'mt-3', 'data-region': 'persona'}, [
                el('label', {"for": uid + '-persona', className: 'form-label ' + GAP, text: s.personaLabel}), select]));
        }
        if (data.sharingmode && data.sharingmode !== 'individual') {
            const named = data.grouplevel === 'named' || data.grouplevel === 'named_with_group';
            const publish = el('input', {className: 'form-check-input', type: 'checkbox', id: uid + '-publish',
                checked: !!data.sharepublish});
            const name = el('input', {className: 'form-check-input', type: 'checkbox', id: uid + '-named',
                checked: !!data.sharenamed,
                disabled: !data.sharepublish});
            publish.addEventListener('change', () => {
                name.disabled = !publish.checked;
            });
            const fields = [el('legend', {className: 'h6', text: s.sharingTitle}),
                el('p', {className: 'small', text: s.sharingText})];
            if (data.sharereconsent) {
                fields.push(el('p', {className: 'small', role: 'status', text: s.sharingReconsent}));
            }
            fields.push(el('div', {className: 'form-check'}, [publish,
                el('label', {className: 'form-check-label', "for": uid + '-publish', text: s.sharingPublish})]));
            if (named) {
                fields.push(el('div', {className: 'form-check'}, [name,
                    el('label', {className: 'form-check-label', "for": uid + '-named', text: s.sharingNamed})]));
            }
            const form = el('form', {className: 'mt-3', 'data-region': 'sharing'}, [el('fieldset', {}, fields),
                el('button', {type: 'submit', className: 'btn btn-secondary btn-sm mt-1', text: s.sharingSave})]);
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                act(call('set_group_sharing', {cmid, publish: publish.checked, named: named && publish.checked && name.checked}));
            });
            nodes.push(form);
        }

        const links = el('div', {className: 'mt-3'});
        const answers = el('div', {id: uid + '-answers', 'data-region': 'answers', className: 'mt-2'});
        if (data.dialogenabled) {
            const toggle = el('button', {type: 'button', className: 'btn btn-link btn-sm p-0 ' + GAP,
                'aria-controls': uid + '-answers',
                'aria-expanded': answersOpen ? 'true' : 'false', text: answersOpen ? s.answersHide : s.answersShow});
            toggle.addEventListener('click', () => {
                answersOpen = !answersOpen;
                toggle.setAttribute('aria-expanded', answersOpen ? 'true' : 'false');
                toggle.textContent = answersOpen ? s.answersHide : s.answersShow;
                if (answersOpen) {
                    renderAnswers(answers).catch(fail);
                } else {
                    answers.replaceChildren();
                }
            });
            links.append(toggle);
            if (answersOpen) {
                renderAnswers(answers).catch(fail);
            }
        }
        if (data.consent === 'accepted') {
            links.append(el('button', {type: 'button', className: 'btn btn-link btn-sm p-0', text: s.consentWithdraw,
                on: {click: () => act(call('set_consent', {cmid, accept: false}))}}));
        }
        nodes.push(links, answers);
        show(...nodes);
    };

    const load = () => call('get_commentary', {cmid}).then(render).catch(fail);
    show(el('p', {role: 'status', text: s.loading}));
    load();
};
