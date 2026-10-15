import { useId } from 'react';
import { domSafeId, fieldA11y } from '../../Support/formFieldA11y';
import { ERROR, HINT, LABEL } from '../../Support/formStyles';

/**
 * One form field: label, optional explanation button, the control, hint and error — wired together.
 *
 * What it guarantees (docs/wcag-accessibility-plan.md, A11Y-001 and A11Y-013):
 *   - the <label> names the control through for/id, and nothing else sits inside it. An InfoHint is a
 *     <button>, and a button inside a <label> becomes the label's control: the field it was meant to
 *     explain ends up with no name. Pass it as `info` and it renders beside the label instead;
 *   - the hint and the error are tied to the control with aria-describedby;
 *   - an error sets aria-invalid, so focusFirstInvalidField can find the field after a rejected submit;
 *   - `required` shows the usual «*» and sets aria-required.
 *
 * The control is the caller's, via a render function, so a select, a textarea or a UnitInput all work
 * without this component knowing about them:
 *
 *   <FormField label="Tittel" error={form.errors.title} required>
 *       {(field) => <input {...field} className={INPUT} value={…} onChange={…} />}
 *   </FormField>
 *
 * Older pages have their own type scale. The class props let such a page take over the wiring without
 * changing how it looks (Fase 3); new code leaves them alone and gets formStyles.
 *
 * @param {string}   [id]          The control's id. Generated when omitted.
 * @param {string}   label
 * @param {node}     [info]        An InfoHint (or similar) shown beside the label, outside it.
 * @param {node}     [hint]
 * @param {string}   [error]
 * @param {boolean}  [required]
 * @param {string[]} [describedBy] Extra description ids, e.g. a unit shown inside the field.
 * @param {string}   [className]   For the wrapper (grid spans and the like).
 * @param {string}   [labelClassName]
 * @param {string}   [labelRowClassName]
 * @param {string}   [controlClassName] Wrapper of the control; '' when the wrapper spaces its children.
 * @param {string}   [hintClassName]
 * @param {string}   [errorClassName]
 * @param {function} children      (controlProps) => the control.
 */
export default function FormField({
    id,
    label,
    info = null,
    hint = null,
    error = null,
    required = false,
    describedBy = [],
    className = '',
    labelClassName = LABEL,
    labelRowClassName = 'flex items-center gap-1.5',
    controlClassName = 'mt-1',
    hintClassName = HINT,
    errorClassName = ERROR,
    children,
}) {
    const generatedId = `field-${domSafeId(useId())}`;
    const controlId = id ?? generatedId;
    const { hintId, errorId, controlProps } = fieldA11y({
        id: controlId,
        hasHint: Boolean(hint),
        hasError: Boolean(error),
        required,
        describedBy,
    });

    return (
        <div className={className}>
            <div className={labelRowClassName}>
                <label htmlFor={controlId} className={labelClassName}>
                    {label}
                    {required ? <span aria-hidden="true" className="ml-0.5 text-rose-700">*</span> : null}
                </label>
                {info}
            </div>

            {controlClassName ? <div className={controlClassName}>{children(controlProps)}</div> : children(controlProps)}

            {hint ? <p id={hintId} className={hintClassName}>{hint}</p> : null}
            {error ? <p id={errorId} className={errorClassName}>{error}</p> : null}
        </div>
    );
}

/**
 * A group of checkboxes or radios under one question (1.3.1, A11Y-120). The group is a <fieldset>
 * named by the question through aria-labelledby rather than a <legend>: an explanation button may
 * sit beside the question, and inside a <legend> its name would become part of the group's name.
 * The hint and the error describe the group, and an error marks every option invalid — that is
 * where focusFirstInvalidField lands, and a screen reader entering the group hears the question,
 * the hint and the error before the first option.
 *
 * `children` is a render function receiving the props each option's <input> needs.
 */
export function FieldsetField({
    id,
    legend,
    info = null,
    hint = null,
    error = null,
    className = '',
    legendClassName = LABEL,
    legendRowClassName = 'flex items-center gap-1.5',
    hintClassName = HINT,
    errorClassName = ERROR,
    children,
}) {
    const generatedId = `group-${domSafeId(useId())}`;
    const groupId = id ?? generatedId;
    const legendId = `${groupId}-legend`;
    const { hintId, errorId, controlProps } = fieldA11y({ id: groupId, hasHint: Boolean(hint), hasError: Boolean(error) });
    const optionProps = error ? { 'aria-invalid': true } : {};

    return (
        <fieldset id={groupId} aria-labelledby={legendId} aria-describedby={controlProps['aria-describedby']} className={className}>
            <div className={legendRowClassName}>
                <span id={legendId} className={legendClassName}>{legend}</span>
                {info}
            </div>
            {children(optionProps)}
            {hint ? <p id={hintId} className={hintClassName}>{hint}</p> : null}
            {error ? <p id={errorId} className={errorClassName}>{error}</p> : null}
        </fieldset>
    );
}

/** «Felt merket med * må fylles ut» — the key to the asterisk, said once per form (3.3.2, A11Y-121). */
export function RequiredFieldsNote({ text, className = 'text-sm text-slate-600' }) {
    return (
        <p className={className}>
            {text ?? 'Felt merket med * må fylles ut.'}
        </p>
    );
}
