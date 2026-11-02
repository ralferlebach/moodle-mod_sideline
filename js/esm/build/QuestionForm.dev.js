var __defProp = Object.defineProperty;
var __name = (target, value) => __defProp(target, "name", { value, configurable: true });
import { Fragment, jsxDEV } from "react/jsx-dev-runtime";
/**
 * One interview question in its native, keyboard-accessible form control.
 *
 * @module     mod_sideline/QuestionForm
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import { useId, useState } from "react";
const QuestionForm = /* @__PURE__ */ __name(({ question, strings, onSubmit }) => {
  const id = useId();
  const [choice, setChoice] = useState(null);
  const [choices, setChoices] = useState([]);
  const [text, setText] = useState("");
  const [slider, setSlider] = useState(question.min);
  const [ratings, setRatings] = useState(question.pairs.map(() => null));
  let control;
  let value = "";
  let complete = false;
  if (question.qtype === "mc" && question.multiple) {
    const toggle = /* @__PURE__ */ __name((index) => setChoices(
      choices.includes(index) ? choices.filter((c) => c !== index) : [...choices, index].sort((a, b) => a - b)
    ), "toggle");
    control = /* @__PURE__ */ jsxDEV(Fragment, { children: [
      question.options.map((label, index) => /* @__PURE__ */ jsxDEV("div", { className: "form-check", children: [
        /* @__PURE__ */ jsxDEV(
          "input",
          {
            className: "form-check-input",
            type: "checkbox",
            id: `${id}-mc-${index}`,
            "aria-describedby": `${id}-hint`,
            checked: choices.includes(index),
            onChange: () => toggle(index)
          },
          void 0,
          false,
          {
            fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
            lineNumber: 54,
            columnNumber: 25
          }
        ),
        /* @__PURE__ */ jsxDEV("label", { className: "form-check-label", htmlFor: `${id}-mc-${index}`, children: label }, void 0, false, {
          fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
          lineNumber: 56,
          columnNumber: 25
        })
      ] }, index, true, {
        fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
        lineNumber: 53,
        columnNumber: 21
      })),
      /* @__PURE__ */ jsxDEV("small", { id: `${id}-hint`, className: "form-text d-block", children: strings.selectHint.replace("{min}", String(question.minselect)).replace("{max}", String(question.maxselect)) }, void 0, false, {
        fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
        lineNumber: 59,
        columnNumber: 17
      })
    ] }, void 0, true, {
      fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
      lineNumber: 51,
      columnNumber: 13
    });
    value = JSON.stringify(choices);
    complete = choices.length >= question.minselect && choices.length <= question.maxselect;
  } else if (question.qtype === "mc") {
    control = question.options.map((label, index) => /* @__PURE__ */ jsxDEV("div", { className: "form-check", children: [
      /* @__PURE__ */ jsxDEV(
        "input",
        {
          className: "form-check-input",
          type: "radio",
          name: `${id}-mc`,
          id: `${id}-mc-${index}`,
          checked: choice === index,
          onChange: () => setChoice(index)
        },
        void 0,
        false,
        {
          fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
          lineNumber: 69,
          columnNumber: 17
        }
      ),
      /* @__PURE__ */ jsxDEV("label", { className: "form-check-label", htmlFor: `${id}-mc-${index}`, children: label }, void 0, false, {
        fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
        lineNumber: 71,
        columnNumber: 17
      })
    ] }, index, true, {
      fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
      lineNumber: 68,
      columnNumber: 13
    }));
    value = choice === null ? "" : String(choice);
    complete = choice !== null;
  } else if (question.qtype === "text") {
    const left = question.maxlength - text.length;
    control = /* @__PURE__ */ jsxDEV(Fragment, { children: [
      /* @__PURE__ */ jsxDEV(
        "textarea",
        {
          className: "form-control",
          id: `${id}-text`,
          "aria-labelledby": `${id}-legend`,
          maxLength: question.maxlength,
          "aria-describedby": `${id}-remaining`,
          value: text,
          onChange: (e) => setText(e.target.value),
          rows: 3
        },
        void 0,
        false,
        {
          fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
          lineNumber: 80,
          columnNumber: 17
        }
      ),
      /* @__PURE__ */ jsxDEV("small", { id: `${id}-remaining`, className: "form-text", "aria-live": "polite", children: [
        left,
        " ",
        strings.textRemaining
      ] }, void 0, true, {
        fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
        lineNumber: 82,
        columnNumber: 17
      })
    ] }, void 0, true, {
      fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
      lineNumber: 79,
      columnNumber: 13
    });
    value = text;
    complete = text.trim() !== "";
  } else if (question.qtype === "slider") {
    control = /* @__PURE__ */ jsxDEV("div", { className: "d-flex align-items-center gap-2", children: [
      /* @__PURE__ */ jsxDEV("span", { "aria-hidden": "true", children: question.minlabel }, void 0, false, {
        fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
        lineNumber: 90,
        columnNumber: 17
      }),
      /* @__PURE__ */ jsxDEV(
        "input",
        {
          type: "range",
          className: "form-range",
          min: question.min,
          max: question.max,
          step: question.step,
          value: slider,
          "aria-labelledby": `${id}-legend`,
          "aria-valuetext": `${strings.sliderValue} ${slider}`,
          onChange: (e) => setSlider(Number(e.target.value))
        },
        void 0,
        false,
        {
          fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
          lineNumber: 91,
          columnNumber: 17
        }
      ),
      /* @__PURE__ */ jsxDEV("span", { "aria-hidden": "true", children: question.maxlabel }, void 0, false, {
        fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
        lineNumber: 94,
        columnNumber: 17
      }),
      /* @__PURE__ */ jsxDEV("output", { "aria-live": "polite", children: slider }, void 0, false, {
        fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
        lineNumber: 95,
        columnNumber: 17
      })
    ] }, void 0, true, {
      fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
      lineNumber: 89,
      columnNumber: 13
    });
    value = String(slider);
    complete = true;
  } else {
    control = question.pairs.map((pair, p) => /* @__PURE__ */ jsxDEV("fieldset", { className: "mb-2", children: [
      /* @__PURE__ */ jsxDEV("legend", { className: "fs-6", children: [
        pair.left,
        " \u2013 ",
        pair.right
      ] }, void 0, true, {
        fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
        lineNumber: 103,
        columnNumber: 17
      }),
      /* @__PURE__ */ jsxDEV("div", { className: "d-flex gap-2 align-items-center", children: [
        /* @__PURE__ */ jsxDEV("span", { "aria-hidden": "true", children: pair.left }, void 0, false, {
          fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
          lineNumber: 105,
          columnNumber: 21
        }),
        Array.from({ length: question.scale }, (_, i) => i + 1).map((point) => /* @__PURE__ */ jsxDEV(
          "input",
          {
            type: "radio",
            className: "form-check-input",
            name: `${id}-sd-${p}`,
            "aria-label": `${pair.left} \u2013 ${pair.right}: ${point}/${question.scale}`,
            checked: ratings[p] === point,
            onChange: () => setRatings(ratings.map((r, i) => i === p ? point : r))
          },
          point,
          false,
          {
            fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
            lineNumber: 107,
            columnNumber: 25
          }
        )),
        /* @__PURE__ */ jsxDEV("span", { "aria-hidden": "true", children: pair.right }, void 0, false, {
          fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
          lineNumber: 112,
          columnNumber: 21
        })
      ] }, void 0, true, {
        fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
        lineNumber: 104,
        columnNumber: 17
      })
    ] }, p, true, {
      fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
      lineNumber: 102,
      columnNumber: 13
    }));
    value = JSON.stringify(ratings);
    complete = ratings.every((r) => r !== null);
  }
  return /* @__PURE__ */ jsxDEV("form", { "data-region": "question", className: "mt-3", onSubmit: (e) => {
    e.preventDefault();
    if (complete) {
      onSubmit(value, false);
    }
  }, children: [
    /* @__PURE__ */ jsxDEV("fieldset", { children: [
      /* @__PURE__ */ jsxDEV("legend", { id: `${id}-legend`, className: "h6", children: [
        strings.questionTitle,
        ": ",
        question.prompt
      ] }, void 0, true, {
        fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
        lineNumber: 128,
        columnNumber: 17
      }),
      control
    ] }, void 0, true, {
      fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
      lineNumber: 127,
      columnNumber: 13
    }),
    /* @__PURE__ */ jsxDEV("button", { type: "submit", className: "btn btn-primary btn-sm me-2 mt-2", disabled: !complete, children: strings.questionSubmit }, void 0, false, {
      fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
      lineNumber: 131,
      columnNumber: 13
    }),
    /* @__PURE__ */ jsxDEV("button", { type: "button", className: "btn btn-link btn-sm mt-2", onClick: () => onSubmit("", true), children: strings.questionSkip }, void 0, false, {
      fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
      lineNumber: 134,
      columnNumber: 13
    })
  ] }, void 0, true, {
    fileName: "public/mod/sideline/js/esm/src/QuestionForm.tsx",
    lineNumber: 121,
    columnNumber: 9
  });
}, "QuestionForm");
var QuestionForm_default = QuestionForm;
export {
  QuestionForm_default as default
};
//# sourceMappingURL=QuestionForm.dev.js.map
