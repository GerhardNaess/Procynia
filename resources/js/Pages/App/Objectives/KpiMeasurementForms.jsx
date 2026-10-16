import { useRef } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import FormField, { RequiredFieldsNote } from '../../../Components/App/FormField';
import { PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION } from '../../../Support/actionStyles';
import { useFocusFirstError } from '../../../Support/focusManagement';
import { fieldA11y } from '../../../Support/formFieldA11y';
import { ERROR, INPUT, LABEL, TEXTAREA } from '../../../Support/formStyles';
import { useTransitionAnnouncement } from '../../../Support/statusAnnouncement';
import { existingValueFor } from './kpiStatus';
import { unitProblem, unitProblemMessage } from './kpiTarget';
import { kpiUnitSuffix } from './kpiUnitSuffix';
import UnitInput from './UnitInput';
import { useKpiNumberFields } from './useKpiNumberFields';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const STAR = <span aria-hidden="true" className="ml-0.5 text-rose-700">*</span>;

/**
 * Registrer måling. The period is chosen, never typed: an ended calendar period for a KPI with a
 * frequency (the suggested one preselected), a day up to today for one without. The target is not
 * a field — the server copies the KPI's målverdi onto the measurement.
 *
 * The value field shows the KPI's unit inside it (UnitInput) and the number in the reader's
 * notation; only the plain number is sent, and a value that does not read stops the form.
 *
 * When the chosen period already has a value, the form says this is a correction and the comment
 * becomes required. «Korriger» in the history opens the form on that row's period (initialChoice).
 * The value must fit the KPI's unit — a whole number of saker, a share up to 100 % — as the server
 * checks again (KpiUnitRules).
 *
 * Fields are tied to their hints and errors; a refused registration puts focus on the first field
 * that is wrong (A11Y-013). The correction notice is said once when a choice turns the registration
 * into a correction, and describes the period field so it is heard again from there (4.1.3).
 */
export function KpiMeasurementForm({ baseUrl, kpi, options, onDone, tr, initialChoice = null }) {
    const form = useForm({
        period: options.mode === 'period' ? (initialChoice ?? options.suggested_key ?? '') : '',
        measured_on: options.mode === 'date' ? (initialChoice ?? options.today ?? '') : '',
        value: '',
        comment: '',
    });

    const { locale = 'no' } = usePage().props;
    const rules = options.unit_rules ?? {};
    const numbers = useKpiNumberFields(form, tr, locale, {
        ruleProblem: (field, value) => unitProblemMessage(unitProblem(value, kpi.unit, rules), kpi.unit, rules, tr),
    });
    const existing = existingValueFor(options, form.data);
    const correction = existing !== null;
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);
    const correctionText = (tr.correction_notice ?? 'Denne perioden har allerede en registrert verdi på :value. Den nye målingen erstatter den som gjeldende verdi, men historikken beholdes.')
        .replace(':value', existing);

    useTransitionAnnouncement(correction ? `correction-${existing}` : 'new', (previous, next) => (next === 'new' ? null : { message: correctionText }));

    const submit = (event) => {
        event.preventDefault();

        if (! numbers.checkBeforeSubmit(['value'])) {
            focusFirstError();

            return;
        }

        form.post(`${baseUrl}/measurements`, { preserveScroll: true, onSuccess: onDone, onError: focusFirstError });
    };

    const comment = fieldA11y({ id: 'measurement-comment', hasHint: true, hasError: Boolean(form.errors.comment), required: correction });

    return (
        <section className={CARD} aria-labelledby="kpi-measure-heading">
            <h2 id="kpi-measure-heading" className="text-lg font-semibold text-slate-950">
                {initialChoice ? (tr.correct ?? 'Korriger') : (tr.register_heading ?? 'Registrer måling')}
            </h2>
            <p className="mt-1 text-base text-slate-600">
                {tr.register_intro ?? 'Målverdien som gjelder nå, lagres sammen med verdien, slik at historikken viser hva målverdien var.'}
            </p>
            <form ref={formRef} onSubmit={submit} className="mt-4 space-y-4">
                <div className="grid gap-4 md:grid-cols-2">
                    {options.mode === 'period' ? (
                        <FormField
                            id="measurement-period"
                            label={tr.field_period ?? 'Periode'}
                            error={form.errors.period}
                            required
                            describedBy={correction ? ['measurement-correction-notice'] : []}
                        >
                            {(field) => (
                                <select
                                    {...field}
                                    required
                                    value={form.data.period}
                                    onChange={(event) => form.setData('period', event.target.value)}
                                    className={INPUT}
                                >
                                    {(options.period_options ?? []).map((option) => (
                                        <option key={option.key} value={option.key}>
                                            {option.label}
                                            {option.key === options.suggested_key ? ` (${tr.suggested ?? 'foreslått'})` : ''}
                                            {option.current_value_display ? ` – ${tr.has_value ?? 'har verdi'} ${option.current_value_display}` : ''}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </FormField>
                    ) : (
                        <FormField
                            id="measurement-date"
                            label={tr.field_measured_on ?? 'Dato'}
                            error={form.errors.measured_on}
                            required
                            describedBy={correction ? ['measurement-correction-notice'] : []}
                        >
                            {(field) => (
                                <input
                                    {...field}
                                    type="date"
                                    required
                                    max={options.today}
                                    value={form.data.measured_on}
                                    onChange={(event) => form.setData('measured_on', event.target.value)}
                                    className={INPUT}
                                />
                            )}
                        </FormField>
                    )}

                    <FormField
                        id="measurement-value"
                        label={tr.field_value ?? 'Verdi'}
                        hint={`${kpi.unit_display} · ${tr.detail_target ?? 'Målverdi'} ${kpi.target_display}`}
                        error={form.errors.value}
                        required
                    >
                        {(field) => (
                            <UnitInput
                                id={field.id}
                                suffix={kpiUnitSuffix(kpi, tr, form.data.value)}
                                required
                                aria-required={field['aria-required']}
                                describedBy={field['aria-describedby'] ?? null}
                                {...numbers.bind('value')}
                            />
                        )}
                    </FormField>
                </div>

                {correction && (
                    <p id="measurement-correction-notice" className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-base text-amber-900" data-testid="measurement-correction">
                        {correctionText}
                    </p>
                )}

                <div>
                    <label htmlFor="measurement-comment" className={LABEL}>
                        {tr.field_comment ?? 'Kommentar'}{correction && STAR}
                    </label>
                    <p id={comment.hintId} className="text-base text-slate-600">
                        {correction
                            ? (tr.comment_required_hint ?? 'Påkrevd ved korrigering: forklar hvorfor verdien endres.')
                            : (tr.comment_hint ?? 'Valgfritt.')}
                    </p>
                    <textarea
                        {...comment.controlProps}
                        rows={2}
                        required={correction}
                        value={form.data.comment}
                        onChange={(event) => form.setData('comment', event.target.value)}
                        className={`mt-1 ${TEXTAREA}`}
                    />
                    {form.errors.comment && <p id={comment.errorId} className={ERROR}>{form.errors.comment}</p>}
                </div>

                <RequiredFieldsNote text={tr.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />

                <div className="flex flex-wrap justify-end gap-3">
                    <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                    <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                        {form.processing ? (tr.saving ?? 'Lagrer...') : (tr.submit ?? 'Registrer måling')}
                    </button>
                </div>
            </form>
        </section>
    );
}

/**
 * Trekk tilbake: once, with a reason. The measurement stays in the history and stops counting.
 */
export function KpiWithdrawForm({ baseUrl, measurement, onDone, tr }) {
    const form = useForm({ reason: '' });
    const id = `withdraw-reason-${measurement.id}`;
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);

    const submit = (event) => {
        event.preventDefault();
        form.post(`${baseUrl}/measurements/${measurement.id}/withdraw`, { preserveScroll: true, onSuccess: onDone, onError: focusFirstError });
    };

    return (
        <form ref={formRef} onSubmit={submit} className="mt-3 space-y-3 rounded-2xl border border-amber-200 bg-amber-50 p-4">
            <p className="text-base font-semibold text-slate-900">
                {tr.withdraw_heading ?? 'Trekk tilbake måling'}: {measurement.period_label}, {measurement.value_display}
            </p>
            <p className="text-base text-slate-700">
                {tr.withdraw_intro ?? 'Målingen blir stående i historikken som tilbaketrukket og teller ikke lenger. Dette kan ikke angres.'}
            </p>
            <FormField id={id} label={tr.withdraw_reason_label ?? 'Begrunnelse'} error={form.errors.reason} required>
                {(field) => (
                    <textarea
                        {...field}
                        rows={2}
                        required
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                        className={TEXTAREA}
                    />
                )}
            </FormField>
            <RequiredFieldsNote text={tr.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-700" />
            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                <button type="submit" disabled={form.processing} className={WARNING_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (tr.withdraw_submit ?? 'Trekk tilbake')}
                </button>
            </div>
        </form>
    );
}
