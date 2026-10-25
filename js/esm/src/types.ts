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
 * Types of the Sideline panel.
 *
 * @module     mod_sideline/types
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Localised UI strings. Every visible text comes from here: no hard-coded strings in the UI. */
export interface PanelStrings {
    region: string;
    loading: string;
    error: string;
    retry: string;
    consentTitle: string;
    consentText: string;
    consentAccept: string;
    consentDecline: string;
    consentWithdraw: string;
    consentDeclined: string;
    questionSubmit: string;
    questionSkip: string;
    questionTitle: string;
    answersShow: string;
    answersHide: string;
    answersEmpty: string;
    answerDelete: string;
    answerEffect: string;
    personaLabel: string;
    personaDefault: string;
    sharingTitle: string;
    sharingText: string;
    sharingPublish: string;
    sharingNamed: string;
    sharingReconsent: string;
    sharingSave: string;
    sliderValue: string;
    textRemaining: string;
}

/** Props the PHP side passes in through data-react-props. */
export interface PanelProps {
    cmid: number;
    typing: boolean;
    inline?: boolean;
    strings: PanelStrings;
}

/** One message block of the commentary. */
export interface CommentaryBlock {
    key: string;
    text: string;
    actionlabel: string;
    actionurl: string;
}

/** A due interview question. */
export interface Question {
    id: number;
    qtype: 'mc' | 'text' | 'slider' | 'semdiff';
    prompt: string;
    options: string[];
    min: number;
    max: number;
    step: number;
    minlabel: string;
    maxlabel: string;
    pairs: {left: string; right: string}[];
    scale: number;
    maxlength: number;
}

/** Response of mod_sideline_get_commentary. */
export interface CommentaryResponse {
    status: 'ok' | 'consent_required';
    consent: string;
    source: string;
    persona: string;
    blocks: CommentaryBlock[];
    question?: Question[];
    personas?: {key: string; name: string}[];
    dialogenabled?: boolean;
    sharingmode?: string;
    grouplevel?: string;
    sharepublish?: boolean;
    sharenamed?: boolean;
    sharereconsent?: boolean;
}

/** One of the learner's own answers. */
export interface Answer {
    questionid: number;
    prompt: string;
    answer: string;
    skipped: boolean;
    effect: string;
}

/** Response of mod_sideline_set_consent. */
export interface ConsentResponse {
    status: string;
}
