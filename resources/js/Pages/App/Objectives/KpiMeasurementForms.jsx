import { useForm } from '@inertiajs/react';
import { PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION } from '../../../Support/actionStyles';
import RequiredMark from '../Risk/RequiredMark';
import { existingValueFor } from './kpiStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-sm font-semibold text-slate-700';

/**
 * Registrer måling. The period is chosen, never typed: an ended calendar period for a KPI with a
 * frequency (the suggested one preselected), a day up to today for one without. The target is not
 * a field — the server copies the KPI's målverdi onto the measurement.
 *
 * When the chosen period already has a value, the form says this is a correction and the comment
 * becomes required. The server checks the same again.
 */
export function KpiMeasurementForm({ baseUrl, kpi, options, onDone, tr }) {
    const form = useForm({
        period: options.mode === 'period' ? (options.suggested_key ?? '') : '',
        measured_on: options.mode === 'date' ? (options.today ?? '') : '',
        value: '',
        comment: '',
    });

    const existing = existingValueFor(options, form.data);
    const correction = existing !== null;

    const submit = (event) => {
        event.preventDefault();
        form.post(`${baseUrl}/measurements`, { preserveScroll: true, onSuccess: onDone });
    };

    const error = (field) => form.errors[field] && <p className="mt-1 text-sm text-rose-600">{form.errors[field]}</p>;

    return (
        <section className={CARD} aria-labelledby="kpi-measure-heading">
            <h2 id="kpi-measure-heading" className="text-lg font-semibold text-slate-950">{tr.register_heading ?? 'Registrer måling'}</h2>
            <p className="mt-1 text-base text-slate-600">
                {tr.register_intro ?? 'Målet som gjelder nå lagres sammen med verdien, slik at historikken viser hva målet var.'}
            </p>
            <form onSubmit={submit} className="mt-4 space-y-4">
                <div className="grid gap-4 md:grid-cols-2">
                    {options.mode === 'period' ? (
                        <div>
                            <label htmlFor="measurement-period" className={LABEL}>{tr.field_period ?? 'Periode'}<RequiredMark /></label>
                            <select
                                id="measurement-period"
                                required
                                aria-required="true"
                                value={form.data.period}
                                onChange={(event) => form.setData('period', event.target.value)}
                                className={`mt-1 ${INPUT}`}
                            >
                                {(options.period_options ?? []).map((option) => (
                                    <option key={option.key} value={option.key}>
                                        {option.label}
                                        {option.key === options.suggested_key ? ` (${tr.suggested ?? 'foreslått'})` : ''}
                                        {option.current_value_display ? ` – ${tr.has_value ?? 'har verdi'} ${option.current_value_display}` : ''}
                                    </option>
                                ))}
                            </select>
                            {error('period')}
                        </div>
                    ) : (
                        <div>
                            <label htmlFor="measurement-date" className={LABEL}>{tr.field_measured_on ?? 'Dato'}<RequiredMark /></label>
                            <input
                                id="measurement-date"
                                type="date"
                                required
                                aria-required="true"
                                max={options.today}
                                value={form.data.measured_on}
                                onChange={(event) => form.setData('measured_on', event.target.value)}
                                className={`mt-1 ${INPUT}`}
                            />
                            {error('measured_on')}
                        </div>
                    )}

                    <div>
                        <label htmlFor="measurement-value" className={LABEL}>{tr.field_value ?? 'Verdi'}<RequiredMark /></label>
                        <input
                            id="measurement-value"
                            type="text"
                            inputMode="decimal"
                            required
                            aria-required="true"
                            aria-describedby="measurement-value-hint"
                            value={form.data.value}
                            onChange={(event) => form.setData('value', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        />
                        <p id="measurement-value-hint" className="mt-1 text-sm text-slate-500">
                            {kpi.unit_display} · {tr.detail_target ?? 'Målverdi'} {kpi.target_display}
                        </p>
                        {error('value')}
                    </div>
                </div>

                {correction && (
                    <p className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-base text-amber-900" role="status" data-testid="measurement-correction">
                        {(tr.correction_notice ?? 'Denne perioden har allerede en registrert verdi på :value. Den nye målingen erstatter den som gjeldende verdi, men historikken beholdes.')
                            .replace(':value', existing)}
                    </p>
                )}

                <div>
                    <label htmlFor="measurement-comment" className={LABEL}>
                        {tr.field_comment ?? 'Kommentar'}{correction && <RequiredMark />}
                    </label>
                    <p id="measurement-comment-hint" className="text-sm text-slate-600">
                        {correction
                            ? (tr.comment_required_hint ?? 'Påkrevd ved korrigering: forklar hvorfor verdien endres.')
                            : (tr.comment_hint ?? 'Valgfritt.')}
                    </p>
                    <textarea
                        id="measurement-comment"
                        rows={2}
                        required={correction}
                        aria-required={correction ? 'true' : undefined}
                        aria-describedby="measurement-comment-hint"
                        value={form.data.comment}
                        onChange={(event) => form.setData('comment', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {error('comment')}
                </div>

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

    const submit = (event) => {
        event.preventDefault();
        form.post(`${baseUrl}/measurements/${measurement.id}/withdraw`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="mt-3 space-y-3 rounded-2xl border border-amber-200 bg-amber-50 p-4">
            <p className="text-base font-semibold text-slate-900">
                {tr.withdraw_heading ?? 'Trekk tilbake måling'}: {measurement.period_label}, {measurement.value_display}
            </p>
            <p className="text-sm text-slate-700">
                {tr.withdraw_intro ?? 'Målingen blir stående i historikken som tilbaketrukket og teller ikke lenger. Dette kan ikke angres.'}
            </p>
            <div>
                <label htmlFor={id} className={LABEL}>{tr.withdraw_reason_label ?? 'Begrunnelse'}<RequiredMark /></label>
                <textarea
                    id={id}
                    rows={2}
                    required
                    aria-required="true"
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.reason && <p className="mt-1 text-sm text-rose-600">{form.errors.reason}</p>}
            </div>
            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                <button type="submit" disabled={form.processing} className={WARNING_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (tr.withdraw_submit ?? 'Trekk tilbake')}
                </button>
            </div>
        </form>
    );
}
