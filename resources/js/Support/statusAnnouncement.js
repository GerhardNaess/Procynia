import { useEffect, useRef } from 'react';
import { announce } from './announcer.js';

/**
 * Speaking a status *change*, not a status (WCAG 4.1.3; docs/wcag-accessibility-plan.md A11Y-112).
 *
 * Background work in Procynia is watched by polling: a requirement extraction reloads the page's
 * documents every few seconds until it is done. A live region around the status text would talk on
 * every reload that changed a number («3 av 10 deler ferdig», «4 av 10 …»), and one that mounts
 * together with its text is often not read at all. Instead the page reduces what it shows to a small
 * state key («processing», «failed», …) and this hook speaks once when that key changes — through the
 * app's permanent regions in announcer.js, never a new one.
 *
 * What is never spoken: the state the page opened with (the user can read it; nothing changed while
 * they were there), and a reload that leaves the key as it was.
 */

/**
 * The message for going from `previous` to `next`, or null. Pure, so it is unit-tested.
 *
 * @param {string|undefined} previous The key last seen; undefined before the first observation.
 * @param {string} next
 * @param {(previous: string, next: string) => ({ message: string, politeness?: 'polite'|'assertive' }|null)} describe
 */
export function transitionAnnouncement(previous, next, describe) {
    if (previous === undefined || previous === next) {
        return null;
    }

    const result = describe(previous, next);

    return result && typeof result.message === 'string' && result.message.trim() !== '' ? result : null;
}

/**
 * Announces `describe(previous, next)` whenever `stateKey` changes after the first render.
 * `describe` may close over the page's current values (a count, a file name): it is read when the
 * change is seen, not when the hook was set up.
 */
export function useTransitionAnnouncement(stateKey, describe) {
    const previousRef = useRef(undefined);
    const describeRef = useRef(describe);
    describeRef.current = describe;

    useEffect(() => {
        const result = transitionAnnouncement(previousRef.current, stateKey, (...args) => describeRef.current(...args));
        previousRef.current = stateKey;

        if (result) {
            announce(result.message, { politeness: result.politeness ?? 'polite' });
        }
    }, [stateKey]);
}
