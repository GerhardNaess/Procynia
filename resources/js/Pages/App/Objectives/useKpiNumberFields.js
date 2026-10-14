import { useRef } from 'react';
import { kpiNumberProblemMessage } from './kpiNumber';

/**
 * Wires a form's KPI number fields to UnitInput: the form data holds the plain value, a field
 * whose text does not read («abc», or «1.500» that could mean two things) gets its message on
 * blur, and checkBeforeSubmit() refuses to send while one is left — the text is never sent for
 * the server to guess at.
 *
 * @param {object} form    the Inertia form
 * @param {object} tr      translations.objectives.kpi (number_input)
 * @param {string} locale  the reader's locale
 */
export function useKpiNumberFields(form, tr, locale) {
    // field → { problem, typed } while its text does not read.
    const unread = useRef({});

    const report = (field) => {
        const entry = unread.current[field];

        if (entry) {
            form.setError(field, kpiNumberProblemMessage(entry.problem, entry.typed, tr, locale));
        }
    };

    const bind = (field) => ({
        locale,
        value: form.data[field],
        invalid: Boolean(form.errors[field]),
        onChange: (value, problem) => {
            unread.current[field] = problem ? { problem, typed: value } : null;
            form.setData(field, value);

            if (! problem && form.errors[field]) {
                form.clearErrors(field);
            }
        },
        onBlur: () => report(field),
    });

    /** False, with the message on each field that does not read, when the form must not be sent. */
    const checkBeforeSubmit = () => {
        const fields = Object.keys(unread.current).filter((field) => unread.current[field]);
        fields.forEach(report);

        return fields.length === 0;
    };

    return { bind, checkBeforeSubmit };
}
