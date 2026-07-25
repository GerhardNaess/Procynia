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
 * Small circular "i" button that reveals a tooltip explaining a field, section,
 * or concept inline. Manages its own open/closed state — no external state wiring needed.
 *
 * Supports hover, focus, click, Escape, and click-outside. Multiple instances on the
 * same page are independent of each other. The panel keeps itself inside the viewport
 * horizontally regardless of `align`, so it never clips at a screen edge.
 *
 * Usage (plain text):
 *   <InfoHint label="Vis forklaring for Bid Manager" text="Bid Manager er ansvarlig for..." />
 *
 * Usage (rich content):
 *   <InfoHint label="Vis forklaring for Bid Manager">
 *       <strong>Bid Manager</strong> er ansvarlig for...
 *   </InfoHint>
 *
 * @param {string}           label      Accessible button label (aria-label). Should describe
 *                                      the action, e.g. "Vis forklaring for Bid Manager".
 * @param {string}           [text]     Tooltip text. Pass either text or children, not both.
 * @param {React.ReactNode}  [children] Tooltip content when rich markup is needed.
 * @param {'sm'|'md'}        [size='md']          'sm' = h-4 w-4 for tight headers; 'md' = h-6 w-6 standard.
 * @param {'light'|'dark'}   [variant='light']    'light' = white tooltip; 'dark' = slate-950 tooltip.
 * @param {'right'|'center'|'left'} [align='right'] Preferred tooltip alignment relative to the
 *                                                   button — used as the starting position; the panel
 *                                                   is nudged back on screen if that would clip.
 */
export default function InfoHint({
    label,
    text,
    children,
    size = 'md',
    variant = 'light',
    align = 'right',
}) {
    const [isOpen, setIsOpen] = useState(false);
    const rawId = useId();
    // useId returns strings like ":r0:" — strip colons for a valid HTML id
    const tooltipId = `infohint-${rawId.replace(/:/g, '')}`;
    const containerRef = useRef(null);
    const buttonRef = useRef(null);
    const tooltipRef = useRef(null);

    const content = text ?? children;

    // Close tooltip when Escape is pressed anywhere on the page
    useEffect(() => {
        if (!isOpen) return undefined;

        function onKeyDown(event) {
            if (event.key === 'Escape') {
                setIsOpen(false);
                buttonRef.current?.focus();
            }
        }

        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [isOpen]);

    // Close on a click anywhere outside the hint (covers input that doesn't blur the
    // trigger, e.g. a click landing on a non-focusable ancestor).
    useEffect(() => {
        if (!isOpen) return undefined;

        function onDocumentMouseDown(event) {
            if (!containerRef.current?.contains(event.target)) {
                setIsOpen(false);
            }
        }

        document.addEventListener('mousedown', onDocumentMouseDown);
        return () => document.removeEventListener('mousedown', onDocumentMouseDown);
    }, [isOpen]);

    // Keep the panel inside the viewport horizontally, regardless of the requested
    // `align` — a page can render this hint anywhere (near the left edge, right edge,
    // inside a narrow card), so a single static alignment class would clip.
    const recalculatePosition = useCallback(() => {
        const el = tooltipRef.current;
        if (!el) return;

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
        if (!isOpen) return undefined;

        recalculatePosition();
        window.addEventListener('resize', recalculatePosition);
        window.addEventListener('scroll', recalculatePosition, true);

        return () => {
            window.removeEventListener('resize', recalculatePosition);
            window.removeEventListener('scroll', recalculatePosition, true);
        };
    }, [isOpen, recalculatePosition]);

    if (!content) {
        return null;
    }

    const buttonSizeClass = size === 'sm'
        ? 'h-5 w-5 text-[10px]'
        : 'h-7 w-7 text-[11px]';

    const tooltipWidthClass = size === 'sm' ? 'w-64' : 'w-72';

    const tooltipColorClass = variant === 'dark'
        ? 'border-slate-800 bg-slate-950 text-white'
        : 'border-slate-200 bg-white text-slate-700';

    const tooltipAlignClass = align === 'left'
        ? 'left-0'
        : align === 'center'
            ? 'left-1/2'
            : 'right-0';

    return (
        <span
            ref={containerRef}
            className="relative inline-flex shrink-0"
            onMouseEnter={() => setIsOpen(true)}
            onMouseLeave={() => setIsOpen(false)}
        >
            <button
                ref={buttonRef}
                type="button"
                aria-label={label}
                aria-expanded={isOpen}
                aria-describedby={isOpen ? tooltipId : undefined}
                onClick={(event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    setIsOpen((current) => !current);
                }}
                onFocus={() => setIsOpen(true)}
                onBlur={() => setIsOpen(false)}
                className={classNames(
                    'inline-flex items-center justify-center rounded-full border border-slate-300 bg-white font-semibold leading-none text-slate-500 transition',
                    'hover:border-violet-300 hover:text-violet-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-300',
                    isOpen ? 'border-violet-300 text-violet-700 shadow-sm' : '',
                    buttonSizeClass,
                )}
            >
                i
            </button>

            {isOpen && (
                <div
                    ref={tooltipRef}
                    id={tooltipId}
                    role="tooltip"
                    className={classNames(
                        'absolute top-full z-30 mt-2 max-w-[calc(100vw-2rem)] rounded-2xl border p-4 font-sans text-base font-normal leading-7 tracking-normal normal-case shadow-[0_20px_40px_rgba(15,23,42,0.12)]',
                        tooltipWidthClass,
                        tooltipColorClass,
                        tooltipAlignClass,
                    )}
                >
                    {content}
                </div>
            )}
        </span>
    );
}
