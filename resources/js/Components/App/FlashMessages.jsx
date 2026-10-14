import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { liveRegion } from '../../Support/announcer';
import { focusElement } from '../../Support/focusManagement';

/**
 * The flash messages a redirect brings — success, error and warning — for both layouts
 * (WCAG 4.1.3 Status Messages, 2.2.1 Timing Adjustable, 2.4.11 Focus Not Obscured;
 * docs/wcag-accessibility-plan.md A11Y-012, A11Y-020).
 *
 * Success is a toast. Its text is portalled into the app-wide polite live region
 * (Support/announcer.js), which exists from page load, so a screen reader reads it once, without
 * interrupting, and what is heard is exactly what is shown. The toast no longer disappears on a timer:
 * it stays until the user closes it (the × button, or Escape when no dialog or menu is open) or the
 * next page replaces it. It floats above the sticky header, and lets clicks through to whatever lies
 * under it, so it never covers a control for good. The close button lives beside the live region,
 * not in it, so its name is not read out with every message.
 *
 * Error and warning are banners at the top of the page content, role="alert": both mean the action
 * did not go as asked (or needs attention now) and are announced the moment they appear. They stay
 * until the next page.
 *
 * @param {object} props
 * @param {{ success?: string, error?: string, warning?: string }} props.flash The shared `flash` prop.
 * @param {string} props.closeLabel Accessible name of the toast's close button.
 * @param {string} [props.bannerClassName] Spacing of the banners in the host layout.
 */
export default function FlashMessages({ flash, closeLabel, bannerClassName = 'mb-6' }) {
    const [toast, setToast] = useState(null);
    const returnFocusRef = useRef(null);

    // Keyed on the flash object rather than its text: every visit brings a new one, so the same
    // «Lagret» after a second save is shown (and read) again.
    useEffect(() => {
        if (flash?.success) {
            setToast({ message: flash.success });
        }
    }, [flash]);

    const close = ({ restoreFocus = false } = {}) => {
        setToast(null);

        if (restoreFocus) {
            const previous = returnFocusRef.current;
            focusElement(previous?.isConnected ? previous : document.getElementById('main-content'), { preventScroll: true });
        }

        returnFocusRef.current = null;
    };

    useEffect(() => {
        if (! toast) {
            return undefined;
        }

        const onKeyDown = (event) => {
            // A dialog, the help panel or an open header menu owns Escape while it is there.
            if (event.key !== 'Escape' || event.defaultPrevented
                || document.querySelector('[aria-modal="true"], [role="dialog"], [data-escape-layer]')) {
                return;
            }

            close();
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [toast]);

    const messageRegion = liveRegion('toast');
    const actionsRegion = liveRegion('toastActions');

    return (
        <>
            {toast && messageRegion ? createPortal(
                <div
                    data-testid="flash-success"
                    className="rounded-2xl border border-emerald-200 bg-emerald-50 py-3 pl-6 pr-14 text-base text-emerald-800 shadow-lg"
                >
                    {toast.message}
                </div>,
                messageRegion,
            ) : null}
            {toast && actionsRegion ? createPortal(
                <button
                    type="button"
                    onClick={() => close({ restoreFocus: true })}
                    // Where the user was before they tabbed to the button, to go back to after it.
                    onFocus={(event) => {
                        if (event.relatedTarget) {
                            returnFocusRef.current = event.relatedTarget;
                        }
                    }}
                    aria-label={closeLabel}
                    title={closeLabel}
                    data-testid="flash-success-close"
                    className="mr-2 inline-flex h-9 w-9 items-center justify-center rounded-lg text-emerald-800 transition hover:bg-emerald-100"
                >
                    <svg className="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="M6 6l8 8M14 6l-8 8" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
                    </svg>
                </button>,
                actionsRegion,
            ) : null}

            {flash?.error ? (
                <div role="alert" className={`${bannerClassName} rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-base text-rose-800 shadow-sm`}>
                    {flash.error}
                </div>
            ) : null}
            {/* A warning is shown only when an action needs attention — an AI quota nearly used up, an
                assessment that failed for some requirements — so it is announced like an error. */}
            {flash?.warning ? (
                <div
                    role="alert"
                    className={`${bannerClassName} rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-base text-amber-900 shadow-sm`}
                    data-testid="flash-warning"
                >
                    {flash.warning}
                </div>
            ) : null}
        </>
    );
}
