import { useEffect, useRef } from 'react';

/**
 * Focus-trapping confirmation dialog for a single action. Unlike PageHelpPanel the
 * page behind it is inert: the dialog keeps focus, Escape and backdrop click close it,
 * and focus returns to the trigger when it unmounts.
 *
 * @param {boolean}  isOpen
 * @param {function} onClose
 * @param {boolean}  [closeDisabled]   Blocks Escape and backdrop close while a request is running.
 * @param {string}   titleId           Id of the heading inside `children`, used for aria-labelledby.
 * @param {object}   [initialFocusRef] Element to focus on open. Defaults to the first focusable child.
 * @param {object}   [returnFocusRef]  Element to focus on close. Defaults to whatever had focus on open.
 */
export default function ActionDialog({ isOpen, onClose, closeDisabled = false, titleId, initialFocusRef, returnFocusRef, children }) {
    const dialogRef = useRef(null);
    const previousFocusRef = useRef(null);
    const onCloseRef = useRef(onClose);
    const closeDisabledRef = useRef(closeDisabled);

    // Keep the latest handlers reachable without re-running the focus effect on
    // every render — otherwise its cleanup would steal focus back to the trigger
    // on each keystroke inside the dialog.
    useEffect(() => {
        onCloseRef.current = onClose;
        closeDisabledRef.current = closeDisabled;
    });

    useEffect(() => {
        if (!isOpen) {
            return undefined;
        }

        previousFocusRef.current = document.activeElement;

        const focusInitialElement = () => {
            const dialog = dialogRef.current;

            if (!dialog) {
                return;
            }

            const focusTarget = initialFocusRef?.current
                ?? dialog.querySelector('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');

            (focusTarget ?? dialog).focus();
        };

        const handleKeyDown = (event) => {
            if (event.key === 'Escape' && !closeDisabledRef.current) {
                event.preventDefault();
                onCloseRef.current();

                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            const focusableElements = Array.from(dialogRef.current?.querySelectorAll(
                'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            ) ?? []);

            if (focusableElements.length === 0) {
                event.preventDefault();
                dialogRef.current?.focus();

                return;
            }

            const firstElement = focusableElements[0];
            const lastElement = focusableElements[focusableElements.length - 1];

            if (event.shiftKey && document.activeElement === firstElement) {
                event.preventDefault();
                lastElement.focus();
            } else if (!event.shiftKey && document.activeElement === lastElement) {
                event.preventDefault();
                firstElement.focus();
            }
        };

        focusInitialElement();
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('keydown', handleKeyDown);

            const returnFocusTarget = returnFocusRef?.current ?? previousFocusRef.current;

            if (returnFocusTarget instanceof HTMLElement && document.contains(returnFocusTarget)) {
                returnFocusTarget.focus();
            }
        };
    }, [isOpen, initialFocusRef, returnFocusRef]);

    if (!isOpen) {
        return null;
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4"
            onMouseDown={(event) => {
                if (event.target === event.currentTarget && !closeDisabled) {
                    onClose();
                }
            }}
        >
            <div
                ref={dialogRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                tabIndex={-1}
                className="max-h-[calc(100vh-2rem)] w-full max-w-xl overflow-y-auto rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl"
            >
                {children}
            </div>
        </div>
    );
}
