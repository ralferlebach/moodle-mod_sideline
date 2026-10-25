var __defProp = Object.defineProperty;
var __name = (target, value) => __defProp(target, "name", { value, configurable: true });
/**
 * Data access for the Sideline panel. The only module that talks to the AJAX layer.
 * Every call acts for the current user only; none of them takes a user id.
 *
 * @module     mod_sideline/repository
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import { fetchOne } from "@moodle/lms/core/ajax";
const getCommentary = /* @__PURE__ */ __name((cmid) => fetchOne({ methodname: "mod_sideline_get_commentary", args: { cmid } }), "getCommentary");
const setConsent = /* @__PURE__ */ __name((cmid, accept) => fetchOne({ methodname: "mod_sideline_set_consent", args: { cmid, accept } }), "setConsent");
const answerQuestion = /* @__PURE__ */ __name((cmid, questionid, value, skip) => fetchOne({ methodname: "mod_sideline_answer_question", args: { cmid, questionid, value, skip } }), "answerQuestion");
const getMyAnswers = /* @__PURE__ */ __name((cmid) => fetchOne({ methodname: "mod_sideline_get_my_answers", args: { cmid } }), "getMyAnswers");
const deleteAnswer = /* @__PURE__ */ __name((cmid, questionid) => fetchOne({ methodname: "mod_sideline_delete_answer", args: { cmid, questionid } }), "deleteAnswer");
const setPersona = /* @__PURE__ */ __name((cmid, persona) => fetchOne({ methodname: "mod_sideline_set_persona", args: { cmid, persona } }), "setPersona");
const setGroupSharing = /* @__PURE__ */ __name((cmid, publish, named) => fetchOne({ methodname: "mod_sideline_set_group_sharing", args: { cmid, publish, named } }), "setGroupSharing");
export {
  answerQuestion,
  deleteAnswer,
  getCommentary,
  getMyAnswers,
  setConsent,
  setGroupSharing,
  setPersona
};
//# sourceMappingURL=repository.dev.js.map
