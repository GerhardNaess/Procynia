import { useCallback, useEffect, useRef } from 'react';
import { focusElement, focusableWithin, refocusIfLost } from '../../../Support/focusManagement';
import { panelCloseCandidates, panelTriggerId } from './supplierA11y';

/**
 * Focus for the inline panels in Leverandøroppfølging (A11Y-014): «Rediger», «Kontroller krav»,
 * «Legg til dokumentasjon», «Avslutt leverandør» and the rest open a form in place of the buttons
 * that opened it — the buttons unmount, and without this focus falls to <body>.
 *
 *   - when `openKey` turns from null to a value (or to another value), focus moves to the panel's
 *     first control (or the panel itself);
 *   - when it turns back to null — Avbryt, or a save that closed it — focus goes to the button that
 *     opened it, re-rendered under the same id, or to the first fallback still in the page. Only when
 *     focus really was lost: someone who has moved on is left where they are.
 *
 * The opening button is found from the click itself: give it an id and `data-panel-trigger`, and put
 * the returned handler on a container as onClickCapture. Nothing happens on the first render, so a
 * panel that is already open when the page loads does not take focus.
 *
 * @param {string|null} openKey   What is open, as a string; null when nothing is.
 * @param {object} options
 * @param {string} options.panelId        The open panel's id (one panel at a time).
 * @param {string[]} [options.fallbackIds] Where focus goes when the opening button is gone.
 * @param {boolean} [options.focusOnOpen]  false when the panel moves focus into itself on mount.
 * @returns {(event: Event) => void} The onClickCapture handler that remembers the opening button.
 */
export function useInlinePanelFocus(openKey, { panelId, fallbackIds = [], focusOnOpen = true }) {
    const previous = useRef(openKey);
    const trigger = useRef(null);
    const fallbacks = useRef(fallbackIds);
    fallbacks.current = fallbackIds;

    useEffect(() => {
        if (previous.current === openKey) {
            return;
        }

        previous.current = openKey;

        if (openKey !== null && openKey !== undefined) {
            if (focusOnOpen) {
                const panel = document.getElementById(panelId);
                focusElement(focusableWithin(panel)[0] ?? panel);
            }

            return;
        }

        refocusIfLost(panelCloseCandidates(trigger.current, fallbacks.current));
    }, [openKey, panelId, focusOnOpen]);

    return useCallback((event) => {
        const id = panelTriggerId(event.target);

        if (id) {
            trigger.current = id;
        }
    }, []);
}

/**
 * A form that opens by being mounted — the handoffs to Risiko and Avvik og forbedringer, started from
 * another tab — scrolls into view and puts focus on its first field, once.
 */
export function useFocusOnMount(ref) {
    useEffect(() => {
        const form = ref.current;

        form?.scrollIntoView?.({ block: 'start' });
        focusElement(focusableWithin(form)[0] ?? form, { preventScroll: true });
        // Mount only: this is the moment the person asked for the form.
    }, [ref]);
}
