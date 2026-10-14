import { useRef, useState } from 'react';
import { editableKpiNumber, editFromFormatted, editablePosition, formatKpiNumber, parseKpiNumber } from './kpiNumber';

// The frame takes the look of the plain KPI input; the input inside it is bare.
const FRAME = 'flex min-h-10 w-full cursor-text items-center gap-2 rounded-xl border bg-white px-3 shadow-sm focus-within:border-slate-400';
const FIELD = 'min-w-0 flex-1 border-0 bg-transparent p-0 py-2 text-base text-slate-900 focus:outline-none focus:ring-0';

/**
 * A number field with the KPI's unit inside it, to the right of the value: «1 300 000 kr».
 *
 * `value` is the plain value the form holds and sends («1300000», «12500.5»); the field shows it
 * in the reader's notation (kpiNumber): «1 300 000» in Norwegian, «1,300,000» in English. Being
 * edited, it shows the value without thousands separators («1300000») and then exactly what the
 * person types, never reformatted under the cursor; every change is read back to a plain value for
 * onChange(value, problem). On blur a value that reads is formatted again; one that does not (not
 * a number, or «1.500» that could mean two things) stays as typed, and onChange gave the text with
 * the problem so the form can say so and refuse to send it.
 *
 * The switch to the editable text keeps the cursor or selection on the same digits, and is made
 * where the browser has placed it, never on a timer:
 *  - focus by pointer (click, tap, right-click): the browser places the cursor after the focus
 *    event, so the switch waits for pointerup or click, then maps the cursor it finds — a click
 *    between «300» and «000» edits between them;
 *  - focus by keyboard, a label or script: the switch is made in the focus event with the whole
 *    value selected, as Tab gives it, so typing replaces it; a click on the unit or the frame puts
 *    the cursor at the end instead;
 *  - an edit that still reaches the formatted text (a drag that ends outside the field) loses only
 *    the formatting's own separators (editFromFormatted), never anything typed.
 *
 * The unit is a separate element next to the input, never part of its value: what the person
 * types, selects, copies and submits is the number alone. The two sit side by side in one flex
 * row, so a long value or a long unit label can never run into the other — a long label is cut
 * short with an ellipsis and keeps its full text as a tooltip.
 *
 * Screen readers hear the unit as part of the field's description (aria-describedby, first).
 */
export default function UnitInput({
    id,
    value,
    onChange,
    onBlur,
    locale = 'no',
    suffix = null,
    describedBy,
    invalid = false,
    className = '',
    ...inputProps
}) {
    const input = useRef(null);
    // The text while it is being edited, or kept as typed when it does not read; null shows the value.
    const [text, setText] = useState(null);
    // How the field is being focused: 'pointer' (switch once the cursor is placed), 'end', or null.
    const focusBy = useRef(null);
    const suffixId = `${id}-unit`;
    const describedByIds = [suffix ? suffixId : null, describedBy].filter(Boolean).join(' ') || undefined;

    const isFocused = (field) => field?.ownerDocument.activeElement === field;

    // Puts the text in the field at once — so React, finding it there, leaves it and the selection
    // alone — and the selection on it.
    const write = (field, nextText, start, end = start, direction = 'none') => {
        field.value = nextText;
        field.setSelectionRange(start, end, direction);
        setText(nextText);
    };

    const toEditable = (field, select) => {
        const editable = editableKpiNumber(value, locale);
        const [start, end, direction] = select(field.value, editable);

        write(field, editable, start, end, direction);
    };

    const focusInput = (event) => {
        if (event.target !== input.current && ! isFocused(input.current)) {
            focusBy.current = 'end';
            input.current?.focus();
        }
    };

    const pointerDown = (event) => {
        if (! isFocused(event.currentTarget)) {
            focusBy.current = 'pointer';
        }
    };

    const focus = (event) => {
        const how = focusBy.current;

        if (text !== null || how === 'pointer') {
            return;
        }

        focusBy.current = null;
        toEditable(event.currentTarget, (_shown, editable) => (how === 'end'
            ? [editable.length, editable.length]
            : [0, editable.length]));
    };

    // After a pointer focus: the cursor or selection is where the browser put it in the formatted
    // text; the same digits get it in the editable one.
    const settlePointer = (event) => {
        const field = event.currentTarget;

        if (focusBy.current !== 'pointer' || text !== null || ! isFocused(field)) {
            return;
        }

        focusBy.current = null;
        toEditable(field, (shown) => [
            editablePosition(shown, field.selectionStart, locale),
            editablePosition(shown, field.selectionEnd, locale),
            field.selectionDirection ?? 'none',
        ]);
    };

    const change = (event) => {
        const field = event.currentTarget;
        let typed = field.value;

        if (text === null) {
            const edit = editFromFormatted(formatKpiNumber(value, locale), typed, field.selectionStart, locale);
            typed = edit.text;
            focusBy.current = null;
            write(field, typed, edit.caret);
        } else {
            setText(typed);
        }

        const { value: read, problem } = parseKpiNumber(typed, locale, { suffix });
        onChange?.(read, problem);
    };

    const blur = (event) => {
        focusBy.current = null;

        if (text !== null && parseKpiNumber(text, locale, { suffix }).problem === null) {
            setText(null);
        }

        onBlur?.(event);
    };

    return (
        <div
            className={`${FRAME} ${invalid ? 'border-rose-400' : 'border-slate-200'} ${className}`}
            onClick={focusInput}
            data-testid={`${id}-frame`}
        >
            <input
                ref={input}
                id={id}
                type="text"
                inputMode="decimal"
                autoComplete="off"
                aria-describedby={describedByIds}
                aria-invalid={invalid || undefined}
                className={FIELD}
                {...inputProps}
                value={text ?? formatKpiNumber(value, locale)}
                onPointerDown={pointerDown}
                onFocus={focus}
                onPointerUp={settlePointer}
                onClick={settlePointer}
                onChange={change}
                onBlur={blur}
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
