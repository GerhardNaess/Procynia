import { useEffect, useRef } from 'react';
import { firstConnected, focusableWithin } from '../../Support/focusManagement';
import { inertOutside, isTopModal, pushModal } from '../../Support/modalInert';

const SIZES = {
    xs: 'max-w-md',
    sm: 'max-w-lg',
    md: 'max-w-xl',
    lg: 'max-w-2xl',
    xl: 'max-w-3xl',
};

/**
 * Focus-trapping confirmation dialog for a single action. Unlike PageHelpPanel the
 * page behind it is inert: the dialog keeps focus, Escape and backdrop click close it,
 * and focus returns to the trigger when it unmounts.
 *
 * "Inert" is literal (A11Y-020): while the dialog is open everything outside it carries the
 * `inert` attribute (Support/modalInert.js), so the header, the page and anything a screen reader
 * could reach in browse mode are out of reach for pointer, keyboard and assistive technology alike.
 * A dialog opened from inside another one is the top of a stack: only it answers Escape and Tab.
 *
 * @param {boolean}  isOpen
 * @param {function} onClose
 * @param {boolean}  [closeDisabled]   Blocks Escape and backdrop close while a request is running.
 * @param {string}   titleId           Id of the heading inside `children`, used for aria-labelledby.
 * @param {string}   [descriptionId]   Id of the text that says what the action does, used for
 *                                      aria-describedby: focus lands on a button, so without it a
 *                                      screen reader hears the title and the button but not the consequence.
 * @param {object}   [initialFocusRef] Element to focus on open. Defaults to the first focusable child.
 * @param {object}   [returnFocusRef]  Element to focus on close. Defaults to whatever had focus on open.
 * @param {object}   [fallbackFocusRef] Where focus goes on close when the return target is gone — e.g. the
 *                                      action deleted the row that held the trigger. Without it focus
 *                                      would drop to <body> and the next Tab would start at the top.
 * @param {string}   [size]            'xs', 'sm', 'md' (default), 'lg' or 'xl' — the panel's maximum width.
 * @param {boolean}  [padded]          false when the content draws its own header, body and footer bands.
 *
 * Focus stays inside the dialog: content portaled out of it (a dropdown panel drawn into <body>)
 * cannot take focus while it is open, so render such panels inline here.
 *
 * This is the one dialog in the app. A wider form, a dialog with its own header bar and close
 * button, or a confirmation are all this component with different children — not a new modal.
 */
export default function ActionDialog({
    isOpen,
    onClose,
    closeDisabled = false,
    titleId,
    descriptionId,
    initialFocusRef,
    returnFocusRef,
    fallbackFocusRef,
    size = 'md',
    padded = true,
    children,
}) {
    const backdropRef = useRef(null);
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

        // <body> is where focus sits when nothing has it — not a place to return to.
        previousFocusRef.current = document.activeElement === document.body ? null : document.activeElement;

        const token = {};
        const popModal = pushModal(token);
        const restoreBackground = inertOutside(backdropRef.current);

        const focusInitialElement = () => {
            const dialog = dialogRef.current;

            if (!dialog) {
                return;
            }

            const focusTarget = initialFocusRef?.current ?? focusableWithin(dialog)[0];

            (focusTarget ?? dialog).focus();
        };

        let lastTabWasBackward = false;

        const handleKeyDown = (event) => {
            // A dialog opened on top of this one owns the keyboard until it closes.
            if (!isTopModal(token)) {
                return;
            }

            if (event.key === 'Tab') {
                lastTabWasBackward = event.shiftKey;
            }

            if (event.key === 'Escape' && !closeDisabledRef.current) {
                event.preventDefault();
                onCloseRef.current();

                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            const focusableElements = focusableWithin(dialogRef.current);

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

        // The keydown check above only catches Tab on what it sees as the last element. Safari, unless
        // the user has turned on full keyboard access, skips buttons and links on Tab — so from a text
        // field it moves focus straight to the next field outside the dialog. Whatever lands outside
        // while the dialog is open is brought back in, at the end the user was heading for.
        const handleFocusIn = (event) => {
            const dialog = dialogRef.current;

            if (!dialog || dialog.contains(event.target) || !isTopModal(token)) {
                return;
            }

            const focusableElements = focusableWithin(dialog);
            const target = lastTabWasBackward ? focusableElements[focusableElements.length - 1] : focusableElements[0];

            (target ?? dialog).focus();
        };

        // Nothing may throw between marking the background inert and returning the cleanup that undoes
        // it, or the page would stay unusable after the dialog is gone.
        try {
            focusInitialElement();
        } catch (error) {
            restoreBackground();
            popModal();
            throw error;
        }

        document.addEventListener('keydown', handleKeyDown);
        document.addEventListener('focusin', handleFocusIn);

        return () => {
            document.removeEventListener('keydown', handleKeyDown);
            // Before focus goes back to the trigger, which is outside the dialog — and the background
            // live again first: an inert trigger cannot take focus.
            document.removeEventListener('focusin', handleFocusIn);
            restoreBackground();
            popModal();

            const returnFocusTarget = firstConnected([
                returnFocusRef?.current,
                previousFocusRef.current,
                fallbackFocusRef?.current,
            ]);

            if (returnFocusTarget instanceof HTMLElement) {
                returnFocusTarget.focus();
            }
        };
    }, [isOpen, initialFocusRef, returnFocusRef, fallbackFocusRef]);

    if (!isOpen) {
        return null;
    }

    return (
        <div
            ref={backdropRef}
            // z-[70]: above the sticky app header (z-[60]). Below it, the header stayed clickable over the
            // backdrop of a modal dialog and could cover the top of a tall one.
            className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/45 p-4"
            // The backdrop is a pointer shortcut for closing; keyboard users close with Escape, which
            // the dialog handles itself. It carries no meaning of its own.
            role="presentation"
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
                aria-describedby={descriptionId}
                tabIndex={-1}
                className={`max-h-[calc(100vh-2rem)] w-full ${SIZES[size] ?? SIZES.md} overflow-y-auto rounded-3xl border border-slate-200 bg-white ${padded ? 'p-6' : ''} shadow-2xl`}
            >
                {children}
            </div>
        </div>
    );
}

/**
 * The title band of a dialog that draws its own header and footer (`padded={false}`): the heading
 * that names the dialog, an optional line saying what it is for, and a close button with a name.
 * A bare «×» is read as "times" or not at all; `closeLabel` is what a screen reader says instead.
 */
export function DialogTitleBar({ titleId, title, descriptionId, description = null, onClose, closeLabel, closeDisabled = false }) {
    return (
        <div className="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
            <div className="min-w-0 space-y-1">
                <h2 id={titleId} className="text-xl font-semibold text-slate-950">{title}</h2>
                {description ? <p id={descriptionId} className="text-base leading-6 text-slate-600">{description}</p> : null}
            </div>
            <button
                type="button"
                onClick={onClose}
                disabled={closeDisabled}
                aria-label={closeLabel}
                className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:border-slate-300 hover:text-slate-900 disabled:cursor-not-allowed"
            >
                <span aria-hidden="true">×</span>
            </button>
        </div>
    );
}
