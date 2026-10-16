/**
 * How a form control is tied to the text around it, for assistive technology.
 *
 * A sighted user sees the hint under a field and the red error beside it. A screen-reader user only
 * hears what is wired to the control: the label through for/id, the hint and the error through
 * aria-describedby, the fact that it is wrong through aria-invalid (WCAG 1.3.1, 3.3.1, 4.1.2).
 * Rendering the error as a loose <p> — the pattern in most of the app today — shows it but never says
 * it. This one function decides the ids and attributes; FormField and FilePickerField render them.
 *
 * @param {object}   options
 * @param {string}   options.id                The control's id. Hint and error ids derive from it, so they are stable.
 * @param {boolean}  [options.hasHint]
 * @param {boolean}  [options.hasError]
 * @param {boolean}  [options.required]        Adds aria-required. Native `required` is left to the caller.
 * @param {string[]} [options.describedBy]     Further ids that describe the control (e.g. a unit, a selected file name).
 * @returns {{ hintId: string, errorId: string, controlProps: object }}
 */
export function fieldA11y({ id, hasHint = false, hasError = false, required = false, describedBy = [] }) {
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;

    // Hint before error: the hint is read every time, the error is the last thing heard before input.
    const description = [
        ...describedBy.filter(Boolean),
        ...(hasHint ? [hintId] : []),
        ...(hasError ? [errorId] : []),
    ];

    const controlProps = { id };

    if (description.length > 0) {
        controlProps['aria-describedby'] = description.join(' ');
    }

    if (hasError) {
        controlProps['aria-invalid'] = true;
    }

    if (required) {
        controlProps['aria-required'] = true;
    }

    return { hintId, errorId, controlProps };
}

/** React's useId() returns «:r1:»; colons are legal in ids but not in a CSS #selector. */
export function domSafeId(rawId) {
    return String(rawId).replace(/[^A-Za-z0-9_-]/g, '');
}
