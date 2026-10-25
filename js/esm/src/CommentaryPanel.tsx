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
 * Root component of the Sideline Learning Coach panel.
 *
 * Mounted by core/react_autoinit through
 * data-react-component="@moodle/lms/mod_sideline/CommentaryPanel", on the view page or inline on
 * the course page. Several panels on one page are independent: each loads only its own activity.
 *
 * @module     mod_sideline/CommentaryPanel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {useCallback, useEffect, useId, useRef, useState} from 'react';
import {
    answerQuestion, deleteAnswer, getCommentary, getMyAnswers, setConsent, setGroupSharing, setPersona,
} from './repository';
import {useTypewriter} from './useTypewriter';
import QuestionForm from './QuestionForm';
import type {Answer, CommentaryBlock, CommentaryResponse, PanelProps} from './types';

/**
 * Only links that stay on this site are rendered as links. The server resolves the URL; this is
 * a second, independent check on the client.
 *
 * @param url The URL delivered by the service.
 */
const isSameOriginUrl = (url: string): boolean => {
    try {
        return new URL(url, window.location.origin).origin === window.location.origin;
    } catch {
        return false;
    }
};

/** A single message block, with the typing animation on the visible copy only. */
const Block = ({block, typing}: {block: CommentaryBlock; typing: boolean}) => {
    const {shown, done} = useTypewriter(block.text, typing);
    return (
        <div className="mod_sideline-block mb-2" data-block-key={block.key}>
            {/* Visual copy, animated. Hidden from assistive technology. */}
            <p className="mb-1" aria-hidden="true" data-region="typed">{shown}</p>
            {/* Complete text for assistive technology, immediately available. */}
            <p className="visually-hidden" data-region="full">{block.text}</p>
            {done && block.actionurl !== '' && isSameOriginUrl(block.actionurl) && (
                <a className="btn btn-secondary btn-sm" href={block.actionurl}>{block.actionlabel}</a>
            )}
        </div>
    );
};

/**
 * The panel.
 *
 * @param props cmid, typing flag and localised strings.
 */
const CommentaryPanel = ({cmid, typing, strings}: PanelProps) => {
    const id = useId();
    const region = useRef<HTMLElement>(null);
    const [data, setData] = useState<CommentaryResponse | null>(null);
    const [failed, setFailed] = useState(false);
    const [answers, setAnswers] = useState<Answer[] | null>(null);
    const [publish, setPublish] = useState(false);
    const [named, setNamed] = useState(false);

    const load = useCallback(async() => {
        setFailed(false);
        try {
            const result = await getCommentary(cmid);
            setData(result);
            setPublish(!!result.sharepublish);
            setNamed(!!result.sharenamed);
        } catch {
            setFailed(true);
        }
    }, [cmid]);

    useEffect(() => {
        load();
    }, [load]);

    /** Run an action, reload, and keep the keyboard focus inside the panel. */
    const act = async(action: () => Promise<unknown>) => {
        try {
            await action();
            await load();
            region.current?.focus();
        } catch {
            setFailed(true);
        }
    };

    const toggleAnswers = async() => {
        if (answers !== null) {
            setAnswers(null);
            return;
        }
        try {
            setAnswers((await getMyAnswers(cmid)).answers);
        } catch {
            setFailed(true);
        }
    };

    let body;
    if (failed) {
        body = (
            <div role="alert">
                <p>{strings.error}</p>
                <button type="button" className="btn btn-secondary btn-sm" onClick={load}>{strings.retry}</button>
            </div>
        );
    } else if (data === null) {
        body = <p role="status">{strings.loading}</p>;
    } else if (data.status === 'consent_required') {
        body = (
            <div data-region="consent">
                <h3 className="h5">{strings.consentTitle}</h3>
                <p>{strings.consentText}</p>
                {data.consent === 'declined' || data.consent === 'withdrawn' ? <p>{strings.consentDeclined}</p> : null}
                <button type="button" className="btn btn-primary btn-sm me-2" onClick={() => act(() => setConsent(cmid, true))}>
                    {strings.consentAccept}
                </button>
                {data.consent === 'not_requested' || data.consent === 'reconsent_required' ? (
                    <button type="button" className="btn btn-secondary btn-sm" onClick={() => act(() => setConsent(cmid, false))}>
                        {strings.consentDecline}
                    </button>
                ) : null}
            </div>
        );
    } else {
        const question = data.question?.[0];
        const personas = data.personas ?? [];
        const groupmode = data.sharingmode ?? 'individual';
        const namesallowed = data.grouplevel === 'named' || data.grouplevel === 'named_with_group';
        body = (
            <>
                <div data-region="commentary">
                    {data.blocks.map((block) => <Block key={block.key} block={block} typing={typing} />)}
                </div>

                {question ? (
                    <QuestionForm key={question.id} question={question} strings={strings}
                        onSubmit={(value, skip) => act(() => answerQuestion(cmid, question.id, value, skip))} />
                ) : null}

                {personas.length > 1 ? (
                    <div className="mt-3" data-region="persona">
                        <label htmlFor={`${id}-persona`} className="form-label me-2">{strings.personaLabel}</label>
                        <select id={`${id}-persona`} className="form-select form-select-sm d-inline-block w-auto" value={data.persona}
                            onChange={(e) => act(() => setPersona(cmid, e.target.value))}>
                            {personas.map((p) => <option key={p.key} value={p.key}>{p.name}</option>)}
                        </select>
                    </div>
                ) : null}

                {groupmode !== 'individual' ? (
                    <form className="mt-3" data-region="sharing" onSubmit={(e) => {
                        e.preventDefault();
                        act(() => setGroupSharing(cmid, publish, publish && named));
                    }}>
                        <fieldset>
                            <legend className="h6">{strings.sharingTitle}</legend>
                            <p className="small">{strings.sharingText}</p>
                            {data.sharereconsent ? <p className="small" role="status">{strings.sharingReconsent}</p> : null}
                            <div className="form-check">
                                <input className="form-check-input" type="checkbox" id={`${id}-publish`} checked={publish}
                                    onChange={(e) => setPublish(e.target.checked)} />
                                <label className="form-check-label" htmlFor={`${id}-publish`}>{strings.sharingPublish}</label>
                            </div>
                            {namesallowed ? (
                                <div className="form-check">
                                    <input className="form-check-input" type="checkbox" id={`${id}-named`} checked={named}
                                        disabled={!publish} onChange={(e) => setNamed(e.target.checked)} />
                                    <label className="form-check-label" htmlFor={`${id}-named`}>{strings.sharingNamed}</label>
                                </div>
                            ) : null}
                        </fieldset>
                        <button type="submit" className="btn btn-secondary btn-sm mt-1">{strings.sharingSave}</button>
                    </form>
                ) : null}

                <div className="mt-3 d-flex flex-wrap gap-2">
                    {data.dialogenabled ? (
                        <button type="button" className="btn btn-link btn-sm p-0" aria-expanded={answers !== null}
                            aria-controls={`${id}-answers`} onClick={toggleAnswers}>
                            {answers !== null ? strings.answersHide : strings.answersShow}
                        </button>
                    ) : null}
                    {data.consent === 'accepted' ? (
                        <button type="button" className="btn btn-link btn-sm p-0" onClick={() => act(() => setConsent(cmid, false))}>
                            {strings.consentWithdraw}
                        </button>
                    ) : null}
                </div>
                {answers !== null ? (
                    <div id={`${id}-answers`} data-region="answers" className="mt-2">
                        {answers.length === 0 ? <p className="small">{strings.answersEmpty}</p> : (
                            <ul className="list-unstyled small">
                                {answers.map((a) => (
                                    <li key={a.questionid} className="mb-2">
                                        <strong>{a.prompt}</strong> {a.skipped ? '–' : a.answer}
                                        <br /><span>{strings.answerEffect}: {a.effect}</span>{' '}
                                        <button type="button" className="btn btn-link btn-sm p-0" onClick={async() => {
                                            await act(() => deleteAnswer(cmid, a.questionid));
                                            setAnswers((await getMyAnswers(cmid)).answers);
                                        }}>{strings.answerDelete}</button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                ) : null}
            </>
        );
    }

    return (
        <section ref={region} tabIndex={-1} className="mod_sideline-panel card card-body" role="region"
            aria-label={strings.region} aria-live="polite">
            {body}
        </section>
    );
};

export default CommentaryPanel;
