import{fetchOne as o}from"@moodle/lms/core/ajax";/**
 * Data access for the Sideline panel. The only module that talks to the AJAX layer.
 * Every call acts for the current user only; none of them takes a user id.
 *
 * @module     mod_sideline/repository
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */const m=e=>o({methodname:"mod_sideline_get_commentary",args:{cmid:e}}),a=(e,n)=>o({methodname:"mod_sideline_set_consent",args:{cmid:e,accept:n}}),i=(e,n,s,r)=>o({methodname:"mod_sideline_answer_question",args:{cmid:e,questionid:n,value:s,skip:r}}),d=e=>o({methodname:"mod_sideline_get_my_answers",args:{cmid:e}}),l=(e,n)=>o({methodname:"mod_sideline_delete_answer",args:{cmid:e,questionid:n}}),p=(e,n)=>o({methodname:"mod_sideline_set_persona",args:{cmid:e,persona:n}}),b=(e,n,s)=>o({methodname:"mod_sideline_set_group_sharing",args:{cmid:e,publish:n,named:s}});export{i as answerQuestion,l as deleteAnswer,m as getCommentary,d as getMyAnswers,a as setConsent,b as setGroupSharing,p as setPersona};
