import { HintTooltipPanel, useHintTooltip } from './hintTooltip';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

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
    // The panel, its clamping, Escape and the id all come from the shared tooltip — the same one
    // ControlHint uses — so an explanation looks and behaves the same wherever it is attached.
    const { isOpen, open, close, toggle, tooltipId, containerRef, tooltipRef, triggerRef } = useHintTooltip(align);

    const content = text ?? children;

    if (!content) {
        return null;
    }

    const buttonSizeClass = size === 'sm'
        ? 'h-5 w-5 text-[10px]'
        : 'h-7 w-7 text-[11px]';

    return (
        <span
            ref={containerRef}
            className="relative inline-flex shrink-0"
            onMouseEnter={open}
            onMouseLeave={close}
        >
            <button
                ref={triggerRef}
                type="button"
                aria-label={label}
                aria-expanded={isOpen}
                aria-describedby={isOpen ? tooltipId : undefined}
                onClick={(event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    toggle();
                }}
                onFocus={open}
                onBlur={close}
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
                <HintTooltipPanel
                    id={tooltipId}
                    panelRef={tooltipRef}
                    size={size}
                    variant={variant}
                    align={align}
                >
                    {content}
                </HintTooltipPanel>
            )}

        </span>
    );
}
