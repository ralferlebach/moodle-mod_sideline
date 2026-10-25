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
 * Data access for the Sideline panel. The only module that talks to the AJAX layer.
 * Every call acts for the current user only; none of them takes a user id.
 *
 * @module     mod_sideline/repository
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {fetchOne} from '@moodle/lms/core/ajax';
import type {Answer, CommentaryResponse, ConsentResponse} from './types';

/**
 * Fetch the commentary of the current user for one Sideline activity.
 *
 * @param cmid Course module id.
 */
export const getCommentary = (cmid: number): Promise<CommentaryResponse> =>
    fetchOne<CommentaryResponse>({methodname: 'mod_sideline_get_commentary', args: {cmid}});

/**
 * Record the consent decision of the current user.
 *
 * @param cmid Course module id.
 * @param accept True to accept, false to decline or withdraw.
 */
export const setConsent = (cmid: number, accept: boolean): Promise<ConsentResponse> =>
    fetchOne<ConsentResponse>({methodname: 'mod_sideline_set_consent', args: {cmid, accept}});

/**
 * Answer or skip a question.
 *
 * @param cmid Course module id.
 * @param questionid Question id.
 * @param value Value: option index, number, text or a JSON list.
 * @param skip Whether to skip.
 */
export const answerQuestion = (cmid: number, questionid: number, value: string, skip: boolean): Promise<{saved: boolean}> =>
    fetchOne<{saved: boolean}>({methodname: 'mod_sideline_answer_question', args: {cmid, questionid, value, skip}});

/**
 * The current user's own answers.
 *
 * @param cmid Course module id.
 */
export const getMyAnswers = (cmid: number): Promise<{answers: Answer[]}> =>
    fetchOne<{answers: Answer[]}>({methodname: 'mod_sideline_get_my_answers', args: {cmid}});

/**
 * Delete one of the current user's answers.
 *
 * @param cmid Course module id.
 * @param questionid Question id.
 */
export const deleteAnswer = (cmid: number, questionid: number): Promise<{deleted: boolean}> =>
    fetchOne<{deleted: boolean}>({methodname: 'mod_sideline_delete_answer', args: {cmid, questionid}});

/**
 * Choose a persona.
 *
 * @param cmid Course module id.
 * @param persona Persona key, empty for the default.
 */
export const setPersona = (cmid: number, persona: string): Promise<{persona: string}> =>
    fetchOne<{persona: string}>({methodname: 'mod_sideline_set_persona', args: {cmid, persona}});

/**
 * Opt in to or out of group sharing.
 *
 * @param cmid Course module id.
 * @param publish Share completions.
 * @param named Allow the first name.
 */
export const setGroupSharing = (cmid: number, publish: boolean, named: boolean): Promise<{publish: boolean; named: boolean}> =>
    fetchOne<{publish: boolean; named: boolean}>({methodname: 'mod_sideline_set_group_sharing', args: {cmid, publish, named}});
