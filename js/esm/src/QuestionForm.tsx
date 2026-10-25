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
 * One interview question in its native, keyboard-accessible form control.
 *
 * @module     mod_sideline/QuestionForm
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {useId, useState} from 'react';
import type {PanelStrings, Question} from './types';

interface Props {
    question: Question;
    strings: PanelStrings;
    onSubmit: (value: string, skip: boolean) => void;
}

/** The form for a due question. */
const QuestionForm = ({question, strings, onSubmit}: Props) => {
    const id = useId();
    const [choice, setChoice] = useState<number | null>(null);
    const [text, setText] = useState('');
    const [slider, setSlider] = useState(question.min);
    const [ratings, setRatings] = useState<(number | null)[]>(question.pairs.map(() => null));

    let control;
    let value = '';
    let complete = false;
    if (question.qtype === 'mc') {
        control = question.options.map((label, index) => (
            <div className="form-check" key={index}>
                <input className="form-check-input" type="radio" name={`${id}-mc`} id={`${id}-mc-${index}`}
                    checked={choice === index} onChange={() => setChoice(index)} />
                <label className="form-check-label" htmlFor={`${id}-mc-${index}`}>{label}</label>
            </div>
        ));
        value = choice === null ? '' : String(choice);
        complete = choice !== null;
    } else if (question.qtype === 'text') {
        const left = question.maxlength - text.length;
        control = (
            <>
                <textarea className="form-control" id={`${id}-text`} aria-labelledby={`${id}-legend`} maxLength={question.maxlength}
                    aria-describedby={`${id}-remaining`} value={text} onChange={(e) => setText(e.target.value)} rows={3} />
                <small id={`${id}-remaining`} className="form-text" aria-live="polite">{left} {strings.textRemaining}</small>
            </>
        );
        value = text;
        complete = text.trim() !== '';
    } else if (question.qtype === 'slider') {
        control = (
            <div className="d-flex align-items-center gap-2">
                <span aria-hidden="true">{question.minlabel}</span>
                <input type="range" className="form-range" min={question.min} max={question.max} step={question.step}
                    value={slider} aria-labelledby={`${id}-legend`}
                    aria-valuetext={`${strings.sliderValue} ${slider}`} onChange={(e) => setSlider(Number(e.target.value))} />
                <span aria-hidden="true">{question.maxlabel}</span>
                <output aria-live="polite">{slider}</output>
            </div>
        );
        value = String(slider);
        complete = true;
    } else {
        control = question.pairs.map((pair, p) => (
            <fieldset key={p} className="mb-2">
                <legend className="fs-6">{pair.left} – {pair.right}</legend>
                <div className="d-flex gap-2 align-items-center">
                    <span aria-hidden="true">{pair.left}</span>
                    {Array.from({length: question.scale}, (_, i) => i + 1).map((point) => (
                        <input key={point} type="radio" className="form-check-input" name={`${id}-sd-${p}`}
                            aria-label={`${pair.left} – ${pair.right}: ${point}/${question.scale}`}
                            checked={ratings[p] === point}
                            onChange={() => setRatings(ratings.map((r, i) => (i === p ? point : r)))} />
                    ))}
                    <span aria-hidden="true">{pair.right}</span>
                </div>
            </fieldset>
        ));
        value = JSON.stringify(ratings);
        complete = ratings.every((r) => r !== null);
    }

    return (
        <form data-region="question" className="mt-3" onSubmit={(e) => {
            e.preventDefault();
            if (complete) {
                onSubmit(value, false);
            }
        }}>
            <fieldset>
                <legend id={`${id}-legend`} className="h6">{strings.questionTitle}: {question.prompt}</legend>
                {control}
            </fieldset>
            <button type="submit" className="btn btn-primary btn-sm me-2 mt-2" disabled={!complete}>{strings.questionSubmit}</button>
            <button type="button" className="btn btn-link btn-sm mt-2" onClick={() => onSubmit('', true)}>{strings.questionSkip}</button>
        </form>
    );
};

export default QuestionForm;
