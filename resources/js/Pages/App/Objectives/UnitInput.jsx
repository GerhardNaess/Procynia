import { useRef } from 'react';

// The frame takes the look of the plain KPI input; the input inside it is bare.
const FRAME = 'flex min-h-10 w-full cursor-text items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 shadow-sm focus-within:border-slate-400';
const FIELD = 'min-w-0 flex-1 border-0 bg-transparent p-0 py-2 text-base text-slate-900 focus:outline-none focus:ring-0';

/**
 * A number field with the KPI's unit inside it, to the right of the value: «1 300 000 kr».
 *
 * The unit is a separate element next to the input, never part of its value: what the person
 * types, selects, copies and submits is the number alone. The two sit side by side in one flex
 * row, so a long value or a long unit label can never run into the other — a long label is cut
 * short with an ellipsis and keeps its full text as a tooltip.
 *
 * Screen readers hear the unit as part of the field's description (aria-describedby, first), and
 * a click on the unit or the frame puts the cursor in the input.
 */
export default function UnitInput({ id, suffix = null, describedBy, className = '', ...inputProps }) {
    const input = useRef(null);
    const suffixId = `${id}-unit`;
    const describedByIds = [suffix ? suffixId : null, describedBy].filter(Boolean).join(' ') || undefined;

    const focusInput = (event) => {
        if (event.target !== input.current) {
            input.current?.focus();
        }
    };

    return (
        <div className={`${FRAME} ${className}`} onClick={focusInput} data-testid={`${id}-frame`}>
            <input
                ref={input}
                id={id}
                type="text"
                inputMode="decimal"
                aria-describedby={describedByIds}
                className={FIELD}
                {...inputProps}
            />
            {suffix && (
                <span
                    id={suffixId}
                    title={suffix}
                    className="max-w-[50%] shrink-0 select-none truncate whitespace-nowrap text-base text-slate-500"
                    data-testid={`${id}-suffix`}
                >
                    {suffix}
                </span>
            )}
        </div>
    );
}
