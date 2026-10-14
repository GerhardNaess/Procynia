import { useRef } from 'react';
import { kpiNumberProblemMessage } from './kpiNumber';

/**
 * Wires a form's KPI number fields to UnitInput: the form data holds the plain value, a field
 * whose text does not read («abc», or «1.500» that could mean two things) gets its message on
 * blur, and checkBeforeSubmit() refuses to send while one is left — the text is never sent for
 * the server to guess at.
 *
 * A value that reads can still break the unit's rules (a share above 100 %, half a sak):
 * ruleProblem(field, value) returns that message, shown on blur and checked before sending, the
 * same rules the server applies again.
 *
 * checkBeforeSubmit(fields) checks only the fields the form shows: a field hidden by the chosen
 * måltype is not sent as part of the målverdi, so what was left in it never stops the form.
 *
 * @param {object} form    the Inertia form
 * @param {object} tr      translations.objectives.kpi (number_input)
 * @param {string} locale  the reader's locale
 * @param {{ruleProblem?: (field: string, value: string) => string|null}} options
 */
export function useKpiNumberFields(form, tr, locale, { ruleProblem = null } = {}) {
    // field → { problem, typed } while its text does not read.
    const unread = useRef({});

    const messageFor = (field, value = form.data[field]) => {
        const entry = unread.current[field];

        if (entry) {
            return kpiNumberProblemMessage(entry.problem, entry.typed, tr, locale);
        }

        return ruleProblem && value !== '' && value !== null && value !== undefined ? ruleProblem(field, String(value)) : null;
    };

    const report = (field) => {
        const message = messageFor(field);

        if (message) {
            form.setError(field, message);
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

    /**
     * False, with the message on each shown field that does not read or does not fit the unit,
     * when the form must not be sent.
     *
     * @param {string[]|null} fields  the number fields the form shows; null for every bound one
     */
    const checkBeforeSubmit = (fields = null) => {
        const candidates = fields ?? Object.keys(unread.current);
        const failing = candidates.filter((field) => messageFor(field));
        failing.forEach(report);

        return failing.length === 0;
    };

    return { bind, checkBeforeSubmit };
}
