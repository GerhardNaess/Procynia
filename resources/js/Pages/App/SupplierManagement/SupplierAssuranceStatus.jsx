import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DISCLOSURE_INLINE, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { formatLongDate } from '../Improvements/improvementStatus';
import RequiredMark from '../Risk/RequiredMark';
import {
    DECISION_TONES,
    applicableText,
    decisionByline,
    decisionFormData,
    decisionLabel,
    decisionMissing,
    decisionOptions,
    historyCount,
    openMandatory,
    snapshotSummary,
    stateNow,
    summaryGroups,
} from './assuranceStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const HINT = 'text-base text-slate-600';
const ERROR = 'text-base text-rose-700';
const LABEL = 'block text-base font-semibold text-slate-900';
const INPUT = 'mt-1 min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LINE_LABEL = 'text-base font-semibold text-slate-600';

/**
 * Registrer beslutning: the person chooses among the decisions the state allows now, says why, and
 * for «Godkjent med oppfølging» what is followed up. A decision the state does not allow is shown,
 * disabled, with the reason — the server refuses it anyway.
 */
function DecisionForm({ supplierId, form: options, onDone, tr }) {
    const a = tr.assurance ?? {};
    const f = a.form ?? {};
    const form = useForm(decisionFormData(options.today));
    const missing = decisionMissing(form.data, options.allowed ?? []);

    const send = (event) => {
        event.preventDefault();
        form.post(`/app/supplier-management/${supplierId}/assurance-decisions`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={send} className="mt-4 min-w-0 space-y-5 rounded-xl border border-slate-200 bg-slate-50 p-4" data-testid="decision-form">
            <div className="min-w-0">
                <h3 className="text-lg font-semibold text-slate-950">{f.heading ?? 'Registrer beslutning'}</h3>
                <p className={HINT}>{f.intro}</p>
            </div>

            <fieldset className="min-w-0">
                <legend className={LABEL}>{f.decision ?? 'Beslutning'}<RequiredMark /></legend>
                <div className="mt-1 space-y-1">
                    {decisionOptions(options.decisions ?? [], options.allowed ?? [], tr).map((option) => (
                        <label key={option.value} className={`flex min-h-10 items-start gap-3 py-1 text-base ${option.allowed ? 'text-slate-900' : 'text-slate-600'}`} data-testid={`decision-option-${option.value}`}>
                            <input
                                type="radio"
                                name={`decision-${supplierId}`}
                                value={option.value}
                                disabled={! option.allowed}
                                checked={form.data.decision === option.value}
                                onChange={() => form.setData('decision', option.value)}
                                className="mt-1 h-5 w-5 shrink-0"
                            />
                            <span className="min-w-0 break-words">
                                <span className="font-semibold">{option.label}</span>
                                <span className="block text-slate-600">{option.hint}</span>
                                {option.reason && <span className="block text-amber-800" data-testid="decision-not-allowed">{option.reason}</span>}
                            </span>
                        </label>
                    ))}
                </div>
                {form.errors.decision && <p className={ERROR}>{form.errors.decision}</p>}
            </fieldset>

            {form.data.decision === 'approved_with_follow_up' && (
                <div>
                    <label htmlFor={`decision-${supplierId}-follow-up`} className={LABEL}>{f.follow_up_note ?? 'Hva følges opp?'}<RequiredMark /></label>
                    <p className={HINT}>{f.follow_up_note_hint}</p>
                    <textarea id={`decision-${supplierId}-follow-up`} rows={3} required aria-required value={form.data.follow_up_note} onChange={(event) => form.setData('follow_up_note', event.target.value)} className={INPUT} />
                    {form.errors.follow_up_note && <p className={ERROR}>{form.errors.follow_up_note}</p>}
                </div>
            )}

            <div>
                <label htmlFor={`decision-${supplierId}-rationale`} className={LABEL}>{f.rationale ?? 'Begrunnelse'}<RequiredMark /></label>
                <p className={HINT}>{f.rationale_hint}</p>
                <textarea id={`decision-${supplierId}-rationale`} rows={4} required aria-required value={form.data.rationale} onChange={(event) => form.setData('rationale', event.target.value)} className={INPUT} />
                {form.errors.rationale && <p className={ERROR}>{form.errors.rationale}</p>}
            </div>

            <div>
                <label htmlFor={`decision-${supplierId}-decided-on`} className={LABEL}>{f.decided_on ?? 'Beslutningsdato'}<RequiredMark /></label>
                <p className={HINT}>{f.decided_on_hint}</p>
                <input
                    id={`decision-${supplierId}-decided-on`}
                    type="date"
                    required
                    max={options.today}
                    value={form.data.decided_on}
                    onChange={(event) => form.setData('decided_on', event.target.value)}
                    className={`${INPUT} sm:max-w-xs`}
                />
                {form.errors.decided_on && <p className={ERROR}>{form.errors.decided_on}</p>}
            </div>

            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing || missing.length > 0} className={PRIMARY_ACTION}>{f.submit ?? 'Registrer beslutning'}</button>
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
            </div>
        </form>
    );
}

/** One decision in the history: what, when, by whom, why — and the state it was taken on, as it was. */
function DecisionEntry({ entry, locale, tr }) {
    const a = tr.assurance ?? {};
    const snapshot = snapshotSummary(entry.snapshot, tr);

    return (
        <li className="min-w-0 border-l-2 border-slate-200 pl-3" data-testid="decision-history-entry">
            <div className="flex flex-wrap items-center gap-2">
                <StatusBadge tone={DECISION_TONES[entry.decision] ?? 'slate'}>{decisionLabel(entry.decision, tr)}</StatusBadge>
                <span className="text-base text-slate-600">{decisionByline(entry, tr, locale)}</span>
            </div>
            <p className="mt-1 whitespace-pre-line break-words text-base text-slate-900">{tr.control?.reason_label ?? 'Begrunnelse'}: {entry.rationale}</p>
            {entry.follow_up_note && (
                <p className="mt-1 whitespace-pre-line break-words text-base text-slate-900">{a.follow_up ?? 'Følges opp'}: {entry.follow_up_note}</p>
            )}
            <div className="mt-2 rounded-lg border border-slate-200 bg-white p-3" data-testid="decision-snapshot">
                <p className="text-base font-semibold text-slate-900">{a.snapshot_heading ?? 'Tilstand da beslutningen ble tatt'}</p>
                {snapshot.state && <p className="break-words text-base text-slate-800">{snapshot.state}</p>}
                <p className="break-words text-base text-slate-700">
                    {[snapshot.applicable, ...snapshot.groups.map((group) => group.text)].join(' · ')}
                </p>
                {snapshot.unmet.length > 0 ? (
                    <>
                        <p className="mt-1 text-base text-slate-700">{a.snapshot_unmet ?? 'Obligatoriske og viktige krav som ikke var dokumentert:'}</p>
                        <ul className="list-disc pl-5 text-base text-slate-800">
                            {snapshot.unmet.map((row) => <li key={row.id} className="break-words">{row.text}</li>)}
                        </ul>
                    </>
                ) : (
                    <p className="mt-1 text-base text-slate-700">{a.snapshot_none_unmet ?? 'Alle obligatoriske og viktige krav var dokumentert.'}</p>
                )}
            </div>
            {entry.recorded_at && <p className="mt-1 text-base text-slate-600">{(a.registered_at ?? 'Registrert :date').replace(':date', formatLongDate(entry.recorded_at, locale))}</p>}
        </li>
    );
}

/**
 * Kontrollstatus (docs/supplier-assurance-v2-plan.md §9.5): two labelled lines that are never merged.
 * «Beslutning» is the decision in force — a person's, history. «Tilstand nå» is computed by the
 * server from the requirements and controls now, with how many apply, the groups, and the mandatory
 * requirements not accepted. When the state worsens after a decision, both show it: the decision is
 * never rewritten. No percentage, no «14/18», no progress bar.
 */
export default function SupplierAssuranceStatus({ supplierId, data, locale = 'no', tr }) {
    const a = tr.assurance ?? {};
    const [deciding, setDeciding] = useState(false);
    const state = data.state;
    const now = stateNow(state, tr);
    const groups = summaryGroups(state?.counts ?? {}, tr);
    const open = openMandatory(state?.open_mandatory ?? [], tr);
    const history = data.history ?? [];

    return (
        <section className={CARD} aria-labelledby="supplier-assurance-heading" data-testid="supplier-assurance-status">
            <h2 id="supplier-assurance-heading" className="text-xl font-semibold text-slate-950">{a.heading ?? 'Kontrollstatus'}</h2>
            <p className={`mt-2 ${HINT}`}>{a.intro}</p>

            <dl className="mt-4 grid min-w-0 gap-4 md:grid-cols-2">
                <div className="min-w-0 rounded-xl border border-slate-200 p-4" data-testid="assurance-decision">
                    <dt className={LINE_LABEL}>{a.decision ?? 'Beslutning'}</dt>
                    <dd className="mt-1 min-w-0">
                        {data.decision ? (
                            <>
                                <StatusBadge tone={DECISION_TONES[data.decision.decision] ?? 'slate'}>{decisionLabel(data.decision.decision, tr)}</StatusBadge>
                                <p className="mt-1 break-words text-base text-slate-700" data-testid="assurance-decision-byline">{decisionByline(data.decision, tr, locale)}</p>
                            </>
                        ) : (
                            <p className="text-base text-slate-800">{a.no_decision ?? 'Ingen beslutning registrert'}</p>
                        )}
                    </dd>
                </div>

                <div className="min-w-0 rounded-xl border border-slate-200 p-4" data-testid="assurance-state">
                    <dt className={LINE_LABEL}>{a.state_now ?? 'Tilstand nå'}</dt>
                    <dd className="mt-1 min-w-0">
                        {now ? (
                            <>
                                <span data-testid="assurance-state-label"><StatusBadge tone={now.tone}>{now.label}</StatusBadge></span>
                                {state.decision_required && <p className="mt-1 break-words text-base text-slate-800">{a.decision_required_text ?? 'Det finnes et forhold som krever at noen tar stilling.'}</p>}
                                <p className="mt-2 text-base font-semibold text-slate-900" data-testid="assurance-applicable">{applicableText(state.applicable_count, tr)}</p>
                                {groups.length > 0 && (
                                    <ul className="mt-1 flex min-w-0 flex-col gap-1 text-base text-slate-800 sm:flex-row sm:flex-wrap sm:gap-x-3" data-testid="assurance-summary">
                                        {groups.map((group) => <li key={group.key} data-testid={`assurance-count-${group.key}`}>{group.text}</li>)}
                                    </ul>
                                )}
                                {open && (
                                    <div className="mt-2" data-testid="assurance-open-mandatory">
                                        <p className="break-words text-base font-semibold text-slate-900">{open.heading}</p>
                                        <ul className="list-disc pl-5 text-base text-slate-800">
                                            {open.items.map((item) => <li key={item.id} className="break-words">{item.text}</li>)}
                                        </ul>
                                    </div>
                                )}
                            </>
                        ) : (
                            <p className="text-base text-slate-800">{a.no_requirements ?? 'Ingen kontrollkrav gjelder leverandøren nå.'}</p>
                        )}
                    </dd>
                </div>
            </dl>

            {data.permissions?.can_decide && data.form && ! deciding && (
                <div className="mt-4">
                    <button type="button" onClick={() => setDeciding(true)} className={PRIMARY_ACTION}>{a.register ?? 'Registrer beslutning'}</button>
                </div>
            )}
            {deciding && data.form && <DecisionForm supplierId={supplierId} form={data.form} onDone={() => setDeciding(false)} tr={tr} />}
            {data.permissions?.ended && <p className={`mt-4 ${HINT}`}>{a.ended}</p>}

            {history.length > 0 && (
                <details className="mt-5 min-w-0" data-testid="decision-history">
                    <summary className={`${DISCLOSURE_INLINE} cursor-pointer`}>{a.history_heading ?? 'Beslutningshistorikk'} ({historyCount(history.length, tr)})</summary>
                    <ol className="mt-3 space-y-4">
                        {history.map((entry) => <DecisionEntry key={entry.id} entry={entry} locale={locale} tr={tr} />)}
                    </ol>
                </details>
            )}
        </section>
    );
}
