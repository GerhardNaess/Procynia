import { RequiredFieldsNote } from '../../../Components/App/FormField';
import { fieldA11y } from '../../../Support/formFieldA11y';

/**
 * The form fields of Leverandøroppfølging, wired the way FormField wires them (A11Y-013, A11Y-121):
 * the label names the control, the hint and the error describe it, an error sets aria-invalid (where
 * useFocusFirstError lands after a rejected submit), and «*» comes with aria-required.
 *
 * This module's forms put the hint between the label and the field; FormField puts it under the
 * field. `hintFirst` keeps the layout people know, with the same wiring. The control is the caller's,
 * through a render function, as with FormField.
 */

export const FIELD_LABEL = 'block text-base font-semibold text-slate-900';
export const FIELD_HINT = 'mt-1 text-base text-slate-600';
export const FIELD_ERROR = 'mt-1 text-base text-rose-700';

/**
 * A submit button while its request runs: aria-disabled rather than disabled, so the button keeps
 * focus (A11Y-143) — the submit handler ignores a second press. Spread after the action class.
 */
export const BUSY = 'aria-disabled:cursor-wait aria-disabled:opacity-60';

/** The «*» after a required field's label; the field's aria-required says it to a screen reader. */
export function RequiredStar() {
    return <span aria-hidden="true" className="ml-0.5 text-rose-700">*</span>;
}

export default function SupplierField({
    id,
    label,
    hint = null,
    error = null,
    required = false,
    hintFirst = true,
    className = '',
    labelClassName = FIELD_LABEL,
    hintClassName = FIELD_HINT,
    errorClassName = FIELD_ERROR,
    children,
}) {
    const { hintId, errorId, controlProps } = fieldA11y({ id, hasHint: Boolean(hint), hasError: Boolean(error), required });
    const hintText = hint ? <p id={hintId} className={hintClassName}>{hint}</p> : null;

    return (
        <div className={className}>
            <label htmlFor={id} className={labelClassName}>
                {label}
                {required ? <RequiredStar /> : null}
            </label>
            {hintFirst ? hintText : null}
            {children(controlProps)}
            {hintFirst ? null : hintText}
            {error ? <p id={errorId} className={errorClassName}>{error}</p> : null}
        </div>
    );
}

/**
 * A group of radios or checkboxes (A11Y-120): the fieldset is described by its hint and error, and
 * every option is aria-invalid while the group has an error — so the first option is where focus goes
 * after a rejected submit, and entering the group says what is wrong. The fieldset keeps its own
 * legend; spread `groupProps` on it and `optionProps` on each <input>.
 *
 * @returns {{ groupProps: object, optionProps: object, hintId: string, errorId: string }}
 */
export function groupA11y(id, { hint = false, error = null } = {}) {
    const { hintId, errorId, controlProps } = fieldA11y({ id, hasHint: Boolean(hint), hasError: Boolean(error) });

    return {
        groupProps: controlProps['aria-describedby'] ? { 'aria-describedby': controlProps['aria-describedby'] } : {},
        optionProps: error ? { 'aria-invalid': true } : {},
        hintId,
        errorId,
    };
}

/** A group's (or a form's) error text, with the id groupA11y described the group by. */
export function FieldError({ id, message, className = FIELD_ERROR, summary = false }) {
    if (! message) {
        return null;
    }

    return (
        <p id={id} className={className} {...(summary ? { 'data-form-error-summary': '' } : {})}>
            {message}
        </p>
    );
}

/** «Felt merket med * må fylles ut.», once per form (A11Y-121). */
export function RequiredNote({ tr, className = 'text-base text-slate-600' }) {
    return <RequiredFieldsNote text={tr?.required_note} className={className} />;
}
