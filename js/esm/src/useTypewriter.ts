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
 * Simulated typing animation (L-11).
 *
 * The animation is purely visual. Assistive technology receives the complete text through a
 * separate live region, so nobody waits for the animation to read. Reduced motion, or a disabled
 * setting, shows the full text immediately.
 *
 * @module     mod_sideline/useTypewriter
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {useEffect, useState} from 'react';

/**
 * Whether the user asked their system for reduced motion.
 */
export const prefersReducedMotion = (): boolean => {
    return typeof window !== 'undefined'
        && typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
};

/**
 * Reveal a text character by character.
 *
 * @param text The complete text.
 * @param enabled False to return the complete text at once.
 * @param delayMs Delay between characters.
 * @returns The part of the text to show and whether the animation has finished.
 */
export const useTypewriter = (text: string, enabled: boolean, delayMs = 18): {shown: string; done: boolean} => {
    const animate = enabled && !prefersReducedMotion();
    const [count, setCount] = useState(animate ? 0 : text.length);

    useEffect(() => {
        if (!animate) {
            setCount(text.length);
            return undefined;
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

    return {shown: text.slice(0, count), done: count >= text.length};
};
