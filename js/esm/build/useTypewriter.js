import{useEffect as a,useState as c}from"react";/**
 * Simulated typing animation (L-11).
 *
 * The animation is purely visual. Assistive technology receives the complete text through a
 * separate live region, so nobody waits for the animation to read. Reduced motion, or a disabled
 * setting, shows the full text immediately.
 *
 * @module     mod_sideline/useTypewriter
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */const u=()=>typeof window<"u"&&typeof window.matchMedia=="function"&&window.matchMedia("(prefers-reduced-motion: reduce)").matches,f=(e,s,r=18)=>{const n=s&&!u(),[i,o]=c(n?0:e.length);return a(()=>{if(!n){o(e.length);return}o(0);const d=window.setInterval(()=>{o(t=>t>=e.length?(window.clearInterval(d),t):t+1)},r);return()=>window.clearInterval(d)},[e,n,r]),{shown:e.slice(0,i),done:i>=e.length}};export{u as prefersReducedMotion,f as useTypewriter};
