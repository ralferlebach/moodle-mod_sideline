var __defProp = Object.defineProperty;
var __name = (target, value) => __defProp(target, "name", { value, configurable: true });
/**
 * Simulated typing animation (L-11).
 *
 * The animation is purely visual. Assistive technology receives the complete text through a
 * separate live region, so nobody waits for the animation to read. Reduced motion, or a disabled
 * setting, shows the full text immediately.
 *
 * @module     mod_sideline/useTypewriter
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import { useEffect, useState } from "react";
const prefersReducedMotion = /* @__PURE__ */ __name(() => {
  return typeof window !== "undefined" && typeof window.matchMedia === "function" && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}, "prefersReducedMotion");
const useTypewriter = /* @__PURE__ */ __name((text, enabled, delayMs = 18) => {
  const animate = enabled && !prefersReducedMotion();
  const [count, setCount] = useState(animate ? 0 : text.length);
  useEffect(() => {
    if (!animate) {
      setCount(text.length);
      return void 0;
    }
    setCount(0);
    const timer = window.setInterval(() => {
      setCount((current) => {
        if (current >= text.length) {
          window.clearInterval(timer);
          return current;
        }
        return current + 1;
      });
    }, delayMs);
    return () => window.clearInterval(timer);
  }, [text, animate, delayMs]);
  return { shown: text.slice(0, count), done: count >= text.length };
}, "useTypewriter");
export {
  prefersReducedMotion,
  useTypewriter
};
//# sourceMappingURL=useTypewriter.dev.js.map
