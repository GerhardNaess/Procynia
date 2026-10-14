import { useEffect, useRef } from 'react';
import { firstConnected, focusableWithin } from '../../Support/focusManagement';

const SIZES = {
    md: 'max-w-xl',
    lg: 'max-w-2xl',
    xl: 'max-w-3xl',
};

/**
 * Focus-trapping confirmation dialog for a single action. Unlike PageHelpPanel the
 * page behind it is inert: the dialog keeps focus, Escape and backdrop click close it,
 * and focus returns to the trigger when it unmounts.
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
 * @param {string}   [size]            'md' (default), 'lg' or 'xl' — the panel's maximum width.
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

            if (!dialog || dialog.contains(event.target)) {
                return;
            }

            const focusableElements = focusableWithin(dialog);
            const target = lastTabWasBackward ? focusableElements[focusableElements.length - 1] : focusableElements[0];

            (target ?? dialog).focus();
        };

        focusInitialElement();
        document.addEventListener('keydown', handleKeyDown);
        document.addEventListener('focusin', handleFocusIn);

        return () => {
            document.removeEventListener('keydown', handleKeyDown);
            // Before focus goes back to the trigger, which is outside the dialog.
            document.removeEventListener('focusin', handleFocusIn);

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
