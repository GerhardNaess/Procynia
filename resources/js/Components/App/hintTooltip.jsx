import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from 'react';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

const ALIGN_BASE_TRANSFORM = {
    left: '',
    right: '',
    center: 'translateX(-50%)',
};

/**
 * The tooltip behind both hint components, in one place.
 *
 * InfoHint has always brought its own "i" button, which is right where the explanation is the only
 * reason for the control to exist. It is wrong where the control is already there and already does
 * something — a bell, a menu item — because a second button beside it says "press me" about a
 * sentence. ControlHint covers that case, and the two share this so a tooltip means one thing
 * wherever it appears: same panel, same clamping, same Escape, same ARIA.
 *
 * `align` is a starting position, not a promise. A hint can render near either edge of the
 * viewport, so the panel is nudged back on screen after layout rather than trusting a static class.
 */
export function useHintTooltip(align = 'right') {
    const [isOpen, setIsOpen] = useState(false);
    const rawId = useId();
    // useId returns strings like ":r0:" — strip colons for a valid HTML id
    const tooltipId = `hint-${rawId.replace(/:/g, '')}`;
    const containerRef = useRef(null);
    const tooltipRef = useRef(null);
    const triggerRef = useRef(null);

    const close = useCallback(() => setIsOpen(false), []);
    const open = useCallback(() => setIsOpen(true), []);
    const toggle = useCallback(() => setIsOpen((current) => ! current), []);

    // Escape closes and hands focus back, so a keyboard user is never left inside a hint.
    useEffect(() => {
        if (! isOpen) {
            return undefined;
        }

        function onKeyDown(event) {
            if (event.key === 'Escape') {
                setIsOpen(false);
                triggerRef.current?.focus();
            }
        }

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [isOpen]);

    // Covers input that does not blur the trigger — a click landing on a non-focusable ancestor.
    useEffect(() => {
        if (! isOpen) {
            return undefined;
        }

        function onDocumentMouseDown(event) {
            if (! containerRef.current?.contains(event.target)) {
                setIsOpen(false);
            }
        }

        document.addEventListener('mousedown', onDocumentMouseDown);

        return () => document.removeEventListener('mousedown', onDocumentMouseDown);
    }, [isOpen]);

    const recalculatePosition = useCallback(() => {
        const el = tooltipRef.current;

        if (! el) {
            return;
        }

        const baseTransform = ALIGN_BASE_TRANSFORM[align] ?? '';
        el.style.transform = baseTransform;

        const rect = el.getBoundingClientRect();
        const margin = 8;
        const viewportWidth = window.innerWidth;
        let shift = 0;

        if (rect.left < margin) {
            shift = margin - rect.left;
        } else if (rect.right > viewportWidth - margin) {
            shift = (viewportWidth - margin) - rect.right;
        }

        if (shift !== 0) {
            el.style.transform = `${baseTransform} translateX(${shift}px)`.trim();
        }
    }, [align]);

    useLayoutEffect(() => {
        if (! isOpen) {
            return undefined;
        }

        recalculatePosition();
        window.addEventListener('resize', recalculatePosition);
        window.addEventListener('scroll', recalculatePosition, true);

        return () => {
            window.removeEventListener('resize', recalculatePosition);
            window.removeEventListener('scroll', recalculatePosition, true);
        };
    }, [isOpen, recalculatePosition]);

    return { isOpen, open, close, toggle, setIsOpen, tooltipId, containerRef, tooltipRef, triggerRef };
}

/**
 * The panel itself. 16px at leading-7 in slate-700 is the readable-text standard this app holds
 * tooltips to, and an e2e test measures it — it is the one place a short explanation is easy to
 * set too small.
 */
export function HintTooltipPanel({ id, panelRef, size = 'md', variant = 'light', align = 'right', children }) {
    return (
        <div
            ref={panelRef}
            id={id}
            role="tooltip"
            className={classNames(
                'absolute top-full z-30 mt-2 max-w-[calc(100vw-2rem)] rounded-2xl border p-4 font-sans text-base font-normal leading-7 tracking-normal normal-case shadow-[0_20px_40px_rgba(15,23,42,0.12)]',
                size === 'sm' ? 'w-64' : 'w-72',
                variant === 'dark'
                    ? 'border-slate-800 bg-slate-950 text-white'
                    : 'border-slate-200 bg-white text-slate-700',
                align === 'left' ? 'left-0' : align === 'center' ? 'left-1/2' : 'right-0',
            )}
        >
            {children}
        </div>
    );
}
