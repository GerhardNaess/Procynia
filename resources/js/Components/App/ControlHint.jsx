import { cloneElement } from 'react';
import { HintTooltipPanel, useHintTooltip } from './hintTooltip';

/**
 * An explanation attached to a control that already exists.
 *
 * InfoHint is for a field or a heading, where the "i" button is the only reason to have a control
 * at all. This is for the other case: the bell and "Oppfølging" already are buttons, and putting a
 * second one beside each would add two things to press to explain two things you can press.
 *
 * So the trigger keeps its own job and gains a description. The text arrives on hover and on
 * keyboard focus alike — a tooltip only mouse users can reach is not an explanation, it is
 * decoration — and it is wired with aria-describedby, which is what a screen reader reads as
 * "and here is more about it" rather than replacing the control's own name.
 *
 * Deliberately no `title`. The browser renders that as a second, native tooltip on top of this
 * one, in its own styling, after its own delay; two explanations of the same control disagreeing
 * about when to appear is worse than one.
 *
 * `suppressed` is for a trigger that opens something of its own. The bell's panel and its
 * explanation would otherwise sit on screen at once, and the explanation is about the closed
 * state — what the badge means — so it gets out of the way once the panel answers for itself.
 */
export default function ControlHint({ text, children, align = 'right', suppressed = false, className = '' }) {
    const { isOpen, open, close, tooltipId, containerRef, tooltipRef, triggerRef } = useHintTooltip(align);
    const visible = isOpen && ! suppressed && Boolean(text);

    if (! text) {
        return children;
    }

    const trigger = cloneElement(children, {
        ref: triggerRef,
        'aria-describedby': visible ? tooltipId : undefined,
        onFocus: (event) => {
            children.props.onFocus?.(event);
            open();
        },
        onBlur: (event) => {
            children.props.onBlur?.(event);
            close();
        },
    });

    return (
        <span
            ref={containerRef}
            className={['relative inline-flex shrink-0', className].filter(Boolean).join(' ')}
            onMouseEnter={open}
            onMouseLeave={close}
        >
            {trigger}

            {visible ? (
                <HintTooltipPanel id={tooltipId} panelRef={tooltipRef} align={align}>
                    {text}
                </HintTooltipPanel>
            ) : null}
        </span>
    );
}
