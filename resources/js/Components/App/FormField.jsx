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
 * @param {string}   [id]          The control's id. Generated when omitted.
 * @param {string}   label
 * @param {node}     [info]        An InfoHint (or similar) shown beside the label, outside it.
 * @param {string}   [hint]
 * @param {string}   [error]
 * @param {boolean}  [required]
 * @param {string[]} [describedBy] Extra description ids, e.g. a unit shown inside the field.
 * @param {string}   [className]   For the wrapper (grid spans and the like).
 * @param {function} children      (controlProps) => the control.
 */
export default function FormField({ id, label, info = null, hint = null, error = null, required = false, describedBy = [], className = '', children }) {
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
            <div className="flex items-center gap-1.5">
                <label htmlFor={controlId} className={LABEL}>
                    {label}
                    {required ? <span aria-hidden="true" className="ml-0.5 text-rose-700">*</span> : null}
                </label>
                {info}
            </div>

            <div className="mt-1">{children(controlProps)}</div>

            {hint ? <p id={hintId} className={HINT}>{hint}</p> : null}
            {error ? <p id={errorId} className={ERROR}>{error}</p> : null}
        </div>
    );
}
