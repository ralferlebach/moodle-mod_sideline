var __defProp = Object.defineProperty;
var __name = (target, value) => __defProp(target, "name", { value, configurable: true });
import { Fragment, jsxDEV } from "react/jsx-dev-runtime";
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
import { useCallback, useEffect, useId, useRef, useState } from "react";
import {
  answerQuestion,
  deleteAnswer,
  getCommentary,
  getMyAnswers,
  setConsent,
  setGroupSharing,
  setPersona
} from "./repository";
import { useTypewriter } from "./useTypewriter";
import QuestionForm from "./QuestionForm";
const isSameOriginUrl = /* @__PURE__ */ __name((url) => {
  try {
    return new URL(url, window.location.origin).origin === window.location.origin;
  } catch {
    return false;
  }
}, "isSameOriginUrl");
const Block = /* @__PURE__ */ __name(({ block, typing }) => {
  const { shown, done } = useTypewriter(block.text, typing);
  return /* @__PURE__ */ jsxDEV("div", { className: "mod_sideline-block mb-2", "data-block-key": block.key, children: [
    /* @__PURE__ */ jsxDEV("p", { className: "mb-1", "aria-hidden": "true", "data-region": "typed", children: shown }, void 0, false, {
      fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
      lineNumber: 56,
      columnNumber: 13
    }),
    /* @__PURE__ */ jsxDEV("p", { className: "visually-hidden", "data-region": "full", children: block.text }, void 0, false, {
      fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
      lineNumber: 58,
      columnNumber: 13
    }),
    done && block.actionurl !== "" && isSameOriginUrl(block.actionurl) && /* @__PURE__ */ jsxDEV("a", { className: "btn btn-secondary btn-sm", href: block.actionurl, children: block.actionlabel }, void 0, false, {
      fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
      lineNumber: 60,
      columnNumber: 17
    })
  ] }, void 0, true, {
    fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
    lineNumber: 54,
    columnNumber: 9
  });
}, "Block");
const CommentaryPanel = /* @__PURE__ */ __name(({ cmid, typing, strings }) => {
  const id = useId();
  const region = useRef(null);
  const [data, setData] = useState(null);
  const [failed, setFailed] = useState(false);
  const [answers, setAnswers] = useState(null);
  const [publish, setPublish] = useState(false);
  const [named, setNamed] = useState(false);
  const load = useCallback(async () => {
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
  const act = /* @__PURE__ */ __name(async (action) => {
    try {
      await action();
      await load();
      region.current?.focus();
    } catch {
      setFailed(true);
    }
  }, "act");
  const toggleAnswers = /* @__PURE__ */ __name(async () => {
    if (answers !== null) {
      setAnswers(null);
      return;
    }
    try {
      setAnswers((await getMyAnswers(cmid)).answers);
    } catch {
      setFailed(true);
    }
  }, "toggleAnswers");
  let body;
  if (failed) {
    body = /* @__PURE__ */ jsxDEV("div", { role: "alert", children: [
      /* @__PURE__ */ jsxDEV("p", { children: strings.error }, void 0, false, {
        fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
        lineNumber: 123,
        columnNumber: 17
      }),
      /* @__PURE__ */ jsxDEV("button", { type: "button", className: "btn btn-secondary btn-sm", onClick: load, children: strings.retry }, void 0, false, {
        fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
        lineNumber: 124,
        columnNumber: 17
      })
    ] }, void 0, true, {
      fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
      lineNumber: 122,
      columnNumber: 13
    });
  } else if (data === null) {
    body = /* @__PURE__ */ jsxDEV("p", { role: "status", children: strings.loading }, void 0, false, {
      fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
      lineNumber: 128,
      columnNumber: 16
    });
  } else if (data.status === "consent_required") {
    body = /* @__PURE__ */ jsxDEV("div", { "data-region": "consent", children: [
      /* @__PURE__ */ jsxDEV("h3", { className: "h5", children: strings.consentTitle }, void 0, false, {
        fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
        lineNumber: 132,
        columnNumber: 17
      }),
      /* @__PURE__ */ jsxDEV("p", { children: strings.consentText }, void 0, false, {
        fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
        lineNumber: 133,
        columnNumber: 17
      }),
      data.consent === "declined" || data.consent === "withdrawn" ? /* @__PURE__ */ jsxDEV("p", { children: strings.consentDeclined }, void 0, false, {
        fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
        lineNumber: 134,
        columnNumber: 80
      }) : null,
      /* @__PURE__ */ jsxDEV("button", { type: "button", className: "btn btn-primary btn-sm me-2", onClick: () => act(() => setConsent(cmid, true)), children: strings.consentAccept }, void 0, false, {
        fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
        lineNumber: 135,
        columnNumber: 17
      }),
      data.consent === "not_requested" || data.consent === "reconsent_required" ? /* @__PURE__ */ jsxDEV("button", { type: "button", className: "btn btn-secondary btn-sm", onClick: () => act(() => setConsent(cmid, false)), children: strings.consentDecline }, void 0, false, {
        fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
        lineNumber: 139,
        columnNumber: 21
      }) : null
    ] }, void 0, true, {
      fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
      lineNumber: 131,
      columnNumber: 13
    });
  } else {
    const question = data.question?.[0];
    const personas = data.personas ?? [];
    const groupmode = data.sharingmode ?? "individual";
    const namesallowed = data.grouplevel === "named" || data.grouplevel === "named_with_group";
    body = /* @__PURE__ */ jsxDEV(Fragment, { children: [
      /* @__PURE__ */ jsxDEV("div", { "data-region": "commentary", children: data.blocks.map((block) => /* @__PURE__ */ jsxDEV(Block, { block, typing }, block.key, false, {
        fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
        lineNumber: 153,
        columnNumber: 49
      })) }, void 0, false, {
        fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
        lineNumber: 152,
        columnNumber: 17
      }),
      question ? /* @__PURE__ */ jsxDEV(
        QuestionForm,
        {
          question,
          strings,
          onSubmit: (value, skip) => act(() => answerQuestion(cmid, question.id, value, skip))
        },
        question.id,
        false,
        {
          fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
          lineNumber: 157,
          columnNumber: 21
        }
      ) : null,
      personas.length > 1 ? /* @__PURE__ */ jsxDEV("div", { className: "mt-3", "data-region": "persona", children: [
        /* @__PURE__ */ jsxDEV("label", { htmlFor: `${id}-persona`, className: "form-label me-2", children: strings.personaLabel }, void 0, false, {
          fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
          lineNumber: 163,
          columnNumber: 25
        }),
        /* @__PURE__ */ jsxDEV(
          "select",
          {
            id: `${id}-persona`,
            className: "form-select form-select-sm d-inline-block w-auto",
            value: data.persona,
            onChange: (e) => act(() => setPersona(cmid, e.target.value)),
            children: personas.map((p) => /* @__PURE__ */ jsxDEV("option", { value: p.key, children: p.name }, p.key, false, {
              fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
              lineNumber: 166,
              columnNumber: 50
            }))
          },
          void 0,
          false,
          {
            fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
            lineNumber: 164,
            columnNumber: 25
          }
        )
      ] }, void 0, true, {
        fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
        lineNumber: 162,
        columnNumber: 21
      }) : null,
      groupmode !== "individual" ? /* @__PURE__ */ jsxDEV("form", { className: "mt-3", "data-region": "sharing", onSubmit: (e) => {
        e.preventDefault();
        act(() => setGroupSharing(cmid, publish, publish && named));
      }, children: [
        /* @__PURE__ */ jsxDEV("fieldset", { children: [
          /* @__PURE__ */ jsxDEV("legend", { className: "h6", children: strings.sharingTitle }, void 0, false, {
            fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
            lineNumber: 177,
            columnNumber: 29
          }),
          /* @__PURE__ */ jsxDEV("p", { className: "small", children: strings.sharingText }, void 0, false, {
            fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
            lineNumber: 178,
            columnNumber: 29
          }),
          data.sharereconsent ? /* @__PURE__ */ jsxDEV("p", { className: "small", role: "status", children: strings.sharingReconsent }, void 0, false, {
            fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
            lineNumber: 179,
            columnNumber: 52
          }) : null,
          /* @__PURE__ */ jsxDEV("div", { className: "form-check", children: [
            /* @__PURE__ */ jsxDEV(
              "input",
              {
                className: "form-check-input",
                type: "checkbox",
                id: `${id}-publish`,
                checked: publish,
                onChange: (e) => setPublish(e.target.checked)
              },
              void 0,
              false,
              {
                fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
                lineNumber: 181,
                columnNumber: 33
              }
            ),
            /* @__PURE__ */ jsxDEV("label", { className: "form-check-label", htmlFor: `${id}-publish`, children: strings.sharingPublish }, void 0, false, {
              fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
              lineNumber: 183,
              columnNumber: 33
            })
          ] }, void 0, true, {
            fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
            lineNumber: 180,
            columnNumber: 29
          }),
          namesallowed ? /* @__PURE__ */ jsxDEV("div", { className: "form-check", children: [
            /* @__PURE__ */ jsxDEV(
              "input",
              {
                className: "form-check-input",
                type: "checkbox",
                id: `${id}-named`,
                checked: named,
                disabled: !publish,
                onChange: (e) => setNamed(e.target.checked)
              },
              void 0,
              false,
              {
                fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
                lineNumber: 187,
                columnNumber: 37
              }
            ),
            /* @__PURE__ */ jsxDEV("label", { className: "form-check-label", htmlFor: `${id}-named`, children: strings.sharingNamed }, void 0, false, {
              fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
              lineNumber: 189,
              columnNumber: 37
            })
          ] }, void 0, true, {
            fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
            lineNumber: 186,
            columnNumber: 33
          }) : null
        ] }, void 0, true, {
          fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
          lineNumber: 176,
          columnNumber: 25
        }),
        /* @__PURE__ */ jsxDEV("button", { type: "submit", className: "btn btn-secondary btn-sm mt-1", children: strings.sharingSave }, void 0, false, {
          fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
          lineNumber: 193,
          columnNumber: 25
        })
      ] }, void 0, true, {
        fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
        lineNumber: 172,
        columnNumber: 21
      }) : null,
      /* @__PURE__ */ jsxDEV("div", { className: "mt-3 d-flex flex-wrap gap-2", children: [
        data.dialogenabled ? /* @__PURE__ */ jsxDEV(
          "button",
          {
            type: "button",
            className: "btn btn-link btn-sm p-0",
            "aria-expanded": answers !== null,
            "aria-controls": `${id}-answers`,
            onClick: toggleAnswers,
            children: answers !== null ? strings.answersHide : strings.answersShow
          },
          void 0,
          false,
          {
            fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
            lineNumber: 199,
            columnNumber: 25
          }
        ) : null,
        data.consent === "accepted" ? /* @__PURE__ */ jsxDEV("button", { type: "button", className: "btn btn-link btn-sm p-0", onClick: () => act(() => setConsent(cmid, false)), children: strings.consentWithdraw }, void 0, false, {
          fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
          lineNumber: 205,
          columnNumber: 25
        }) : null
      ] }, void 0, true, {
        fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
        lineNumber: 197,
        columnNumber: 17
      }),
      answers !== null ? /* @__PURE__ */ jsxDEV("div", { id: `${id}-answers`, "data-region": "answers", className: "mt-2", children: answers.length === 0 ? /* @__PURE__ */ jsxDEV("p", { className: "small", children: strings.answersEmpty }, void 0, false, {
        fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
        lineNumber: 212,
        columnNumber: 49
      }) : /* @__PURE__ */ jsxDEV("ul", { className: "list-unstyled small", children: answers.map((a) => /* @__PURE__ */ jsxDEV("li", { className: "mb-2", children: [
        /* @__PURE__ */ jsxDEV("strong", { children: a.prompt }, void 0, false, {
          fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
          lineNumber: 216,
          columnNumber: 41
        }),
        " ",
        a.skipped ? "\u2013" : a.answer,
        /* @__PURE__ */ jsxDEV("br", {}, void 0, false, {
          fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
          lineNumber: 217,
          columnNumber: 41
        }),
        /* @__PURE__ */ jsxDEV("span", { children: [
          strings.answerEffect,
          ": ",
          a.effect
        ] }, void 0, true, {
          fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
          lineNumber: 217,
          columnNumber: 47
        }),
        " ",
        /* @__PURE__ */ jsxDEV("button", { type: "button", className: "btn btn-link btn-sm p-0", onClick: async () => {
          await act(() => deleteAnswer(cmid, a.questionid));
          setAnswers((await getMyAnswers(cmid)).answers);
        }, children: strings.answerDelete }, void 0, false, {
          fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
          lineNumber: 218,
          columnNumber: 41
        })
      ] }, a.questionid, true, {
        fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
        lineNumber: 215,
        columnNumber: 37
      })) }, void 0, false, {
        fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
        lineNumber: 213,
        columnNumber: 29
      }) }, void 0, false, {
        fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
        lineNumber: 211,
        columnNumber: 21
      }) : null
    ] }, void 0, true, {
      fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
      lineNumber: 151,
      columnNumber: 13
    });
  }
  return /* @__PURE__ */ jsxDEV(
    "section",
    {
      ref: region,
      tabIndex: -1,
      className: "mod_sideline-panel card card-body",
      role: "region",
      "aria-label": strings.region,
      "aria-live": "polite",
      children: body
    },
    void 0,
    false,
    {
      fileName: "public/mod/sideline/js/esm/src/CommentaryPanel.tsx",
      lineNumber: 233,
      columnNumber: 9
    }
  );
}, "CommentaryPanel");
var CommentaryPanel_default = CommentaryPanel;
export {
  CommentaryPanel_default as default
};
//# sourceMappingURL=CommentaryPanel.dev.js.map
