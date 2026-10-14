import { SECONDARY_COLOURS } from '../../Support/actionStyles';
import { fieldA11y } from '../../Support/formFieldA11y';

/**
 * A file field that reads as a Procynia control rather than a native browser one.
 *
 * The browser's own file input renders its button with the OS chrome and its own geometry, so a form
 * that otherwise uses the app's buttons gets one control that belongs to no design at all. The input
 * itself is kept — it is still the thing that carries the file and the validation — but it is
 * visually hidden and driven by a label styled as a secondary action: the picker opens a dialog, it
 * does not commit anything, so it must not compete with the form's own submit.
 *
 * The label is the field's caption, not a wrapping <label>, so the markup stays valid next to the
 * <label htmlFor> that fronts the input.
 *
 * Accessibility (docs/wcag-accessibility-plan.md, A11Y-011). The real input is visually hidden, so
 * everything a keyboard or screen-reader user needs has to be carried over explicitly:
 *   - focus: the input has focus but is invisible, so the visible trigger draws the ring (peer-focus-visible);
 *   - name: caption + button text («Excel-fil (.xlsx) Velg fil»), not just «Velg fil»;
 *   - description: the chosen file, the hint and the error, in that order;
 *   - error: aria-invalid on the input, and the message is an alert so a rejected upload is heard.
 */

const TRIGGER = `inline-flex min-h-11 shrink-0 cursor-pointer items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition peer-disabled:cursor-not-allowed peer-disabled:opacity-60 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-violet-600 ${SECONDARY_COLOURS}`;

export default function FilePickerField({
    id,
    label,
    accept,
    disabled = false,
    inputKey,
    file = null,
    buttonLabel = 'Velg fil',
    emptyLabel = 'Ingen fil valgt',
    help = null,
    error = null,
    onChange,
}) {
    const captionId = `${id}-caption`;
    const triggerId = `${id}-trigger`;
    const fileNameId = `${id}-file`;
    const { hintId, errorId, controlProps } = fieldA11y({
        id,
        hasHint: Boolean(help),
        hasError: Boolean(error),
        describedBy: [fileNameId],
    });

    return (
        <div className="space-y-1">
            {label ? (
                <span id={captionId} className="block text-base font-semibold text-slate-700">{label}</span>
            ) : null}

            <div className="flex flex-wrap items-center gap-3">
                <input
                    key={inputKey}
                    {...controlProps}
                    aria-labelledby={label ? `${captionId} ${triggerId}` : undefined}
                    type="file"
                    accept={accept}
                    disabled={disabled}
                    onChange={(event) => onChange(event.target.files?.[0] ?? null)}
                    className="peer sr-only"
                />
                <label id={triggerId} htmlFor={id} className={TRIGGER}>
                    {buttonLabel}
                </label>
                <span id={fileNameId} className="min-w-0 break-all text-base text-slate-700">
                    {file?.name ?? emptyLabel}
                </span>
            </div>

            {help ? <span id={hintId} className="block text-base text-slate-600">{help}</span> : null}
            {error ? <span id={errorId} role="alert" className="block text-base text-rose-700">{error}</span> : null}
        </div>
    );
}
