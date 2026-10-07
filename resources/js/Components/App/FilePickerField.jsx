import { SECONDARY_COLOURS } from '../../Support/actionStyles';

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
 */

const TRIGGER = `inline-flex min-h-11 shrink-0 cursor-pointer items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition peer-disabled:cursor-not-allowed peer-disabled:opacity-60 ${SECONDARY_COLOURS}`;

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
    return (
        <div className="space-y-1">
            {label ? (
                <span className="block text-base font-semibold text-slate-700">{label}</span>
            ) : null}

            <div className="flex flex-wrap items-center gap-3">
                <input
                    key={inputKey}
                    id={id}
                    type="file"
                    accept={accept}
                    disabled={disabled}
                    onChange={(event) => onChange(event.target.files?.[0] ?? null)}
                    className="peer sr-only"
                />
                <label htmlFor={id} className={TRIGGER}>
                    {buttonLabel}
                </label>
                <span className="min-w-0 break-all text-base text-slate-700">
                    {file?.name ?? emptyLabel}
                </span>
            </div>

            {help ? <span className="block text-base text-slate-500">{help}</span> : null}
            {error ? <span className="block text-base text-rose-600">{error}</span> : null}
        </div>
    );
}
