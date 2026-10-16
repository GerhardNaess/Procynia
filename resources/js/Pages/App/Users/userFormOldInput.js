// The page's own redirect target always wins; passwords are never put back.
const NEVER_RESTORED = new Set(['password', 'password_confirmation', 'redirect_to']);

/**
 * A user form's starting values after a refused save: what the person had entered, not empty fields.
 *
 * The forms post natively, so a refusal comes back as a fresh page. Laravel flashes the submitted
 * input for that one page and UserController hands it over as `oldInput`; this reads it back into
 * the form's own shape. A native post sends every value as text and leaves unchecked checkboxes
 * out, so ids become numbers again, «1»/«0» become true/false, and a checkbox list that was not sent
 * at all means none was ticked.
 *
 * Passwords are never restored: they are not flashed, and they are skipped here even if they were.
 * The page tells the person to enter the password again instead.
 *
 * @param {object} defaults  the form's values when nothing was refused
 * @param {object|null} oldInput  from the page props; null when the page did not follow a refusal
 * @param {{ arrays?: string[], booleans?: string[], numbers?: string[] }} types
 *        arrays: checkbox lists the form submitted (absent = none ticked); booleans; numeric ids
 */
export function withOldInput(defaults, oldInput, { arrays = [], booleans = [], numbers = [] } = {}) {
    if (! oldInput || typeof oldInput !== 'object') {
        return defaults;
    }

    const restored = { ...defaults };

    for (const key of Object.keys(defaults)) {
        if (NEVER_RESTORED.has(key)) {
            continue;
        }

        if (arrays.includes(key)) {
            restored[key] = (Array.isArray(oldInput[key]) ? oldInput[key] : [])
                .map(Number)
                .filter((value) => Number.isInteger(value));

            continue;
        }

        if (! Object.prototype.hasOwnProperty.call(oldInput, key)) {
            continue;
        }

        const value = oldInput[key];

        if (booleans.includes(key)) {
            restored[key] = value === true || value === 1 || value === '1' || value === 'true' || value === 'on';
        } else if (numbers.includes(key)) {
            restored[key] = value === null || value === '' || ! Number.isFinite(Number(value)) ? '' : Number(value);
        } else {
            restored[key] = value ?? '';
        }
    }

    return restored;
}
