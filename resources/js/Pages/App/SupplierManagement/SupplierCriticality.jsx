import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { formatLongDate } from '../Improvements/improvementStatus';
import RequiredMark from '../Risk/RequiredMark';
import CriticalityFields from './CriticalityFields';
import SupplierCriticalityBadge from './SupplierCriticalityBadge';
import {
    CRITICALITY_QUESTIONS,
    answerLabel,
    describeCriticalityChange,
    describeCriticalityRegistration,
    emptyCriticality,
    intervalLabel,
} from './supplierManagement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const TERM = 'text-base font-semibold text-slate-600';
const VALUE = 'mt-1 break-words text-base text-slate-900';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';

/** The four questions and their answers; with `before`, an answer that changed says what it was. */
function Basis({ classification, before = null, tr }) {
    const c = tr.criticality ?? {};

    return (
        <ul className="space-y-1">
            {CRITICALITY_QUESTIONS.map((question) => {
                const changed = before !== null && before[question] !== classification[question];

                return (
                    <li key={question} className="text-base text-slate-700">
                        {c.questions?.[question] ?? question}{' '}
                        <span className="font-semibold text-slate-900">{answerLabel(classification[question], tr)}</span>
                        {changed && (
                            <span className="text-slate-600"> ({(c.history?.answer_was ?? 'var :answer').replace(':answer', answerLabel(before[question], tr).toLowerCase())})</span>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

function CriticalityForm({ supplierId, current, reviewIntervals, onDone, tr }) {
    const c = tr.criticality ?? {};
    const form = useForm(current
        ? {
            ...current,
            review_interval_months: current.review_interval_months ? String(current.review_interval_months) : '',
            reason: '',
        }
        : { ...emptyCriticality(), reason: '' });

    const send = (event) => {
        event.preventDefault();
        form.post(`/app/supplier-management/${supplierId}/criticality`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={send} className="mt-4 space-y-5 border-t border-slate-100 pt-5" data-testid="criticality-form">
            <div>
                <h3 className="text-lg font-semibold text-slate-950">{c.form_heading ?? 'Hvor kritisk er leverandøren?'}</h3>
                <p className="mt-1 text-base text-slate-600">
                    {c.form_intro ?? 'Svar på de fire spørsmålene, og velg deretter nivået selv. Svarene er grunnlaget for valget ditt og lagres sammen med det.'}
                </p>
            </div>

            <CriticalityFields form={form} reviewIntervals={reviewIntervals} idPrefix="supplier-criticality" tr={tr} />

            <div>
                <label htmlFor="supplier-criticality-reason" className="block text-base font-semibold text-slate-900">
                    {c.reason_label ?? 'Begrunnelse'}<RequiredMark />
                </label>
                <p id="supplier-criticality-reason-hint" className="mt-1 text-base text-slate-600">
                    {c.reason_hint ?? 'Hvorfor er leverandøren vurdert slik?'}
                </p>
                <textarea
                    id="supplier-criticality-reason"
                    rows={3}
                    required
                    aria-required="true"
                    aria-describedby="supplier-criticality-reason-hint"
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.reason && <p className="mt-1 text-base text-rose-700">{form.errors.reason}</p>}
            </div>

            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (c.submit ?? 'Lagre vurdering')}
                </button>
            </div>
        </form>
    );
}

/**
 * Kritikalitet on the supplier page: how important the supplier is — not how well it performs. The
 * current level, review interval, when and by whom it was last decided and why, and the four
 * answers it was decided on; then every earlier change, newest first, ending with the
 * classification the supplier was registered with.
 *
 * «Vurder kritikalitet» (none yet) or «Endre kritikalitet» is offered only when the server says this
 * person may change it, which it never does for an ended supplier.
 */
export default function SupplierCriticality({ supplierId, criticality, canChange, reviewIntervals = [], locale, tr }) {
    const c = tr.criticality ?? {};
    const [open, setOpen] = useState(false);
    const current = criticality?.current ?? null;
    const history = criticality?.history ?? [];
    const registered = criticality?.registered ?? null;

    return (
        <section className={CARD} aria-labelledby="supplier-criticality-heading" data-testid="supplier-criticality">
            <h2 id="supplier-criticality-heading" className="text-xl font-semibold text-slate-950">{c.heading ?? 'Kritikalitet'}</h2>
            <p className="mt-1 text-base text-slate-600">{c.intro ?? 'Hvor viktig leverandøren er for virksomheten – ikke hvor godt den fungerer.'}</p>

            {current ? (
                <>
                    <dl className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-testid="criticality-current">
                        <div className="min-w-0">
                            <dt className={TERM}>{c.level_label ?? 'Kritikalitet'}</dt>
                            <dd className="mt-1"><SupplierCriticalityBadge level={current.criticality} tr={tr} /></dd>
                        </div>
                        <div className="min-w-0">
                            <dt className={TERM}>{c.interval ?? 'Vurderingsintervall'}</dt>
                            <dd className={VALUE}>{intervalLabel(current.review_interval_months, tr)}</dd>
                        </div>
                        <div className="min-w-0">
                            <dt className={TERM}>{c.last_decided ?? 'Sist vurdert'}</dt>
                            <dd className={VALUE}>{formatLongDate(criticality.decided_at, locale)}</dd>
                        </div>
                        <div className="min-w-0">
                            <dt className={TERM}>{c.decided_by ?? 'Vurdert av'}</dt>
                            <dd className={VALUE}>{criticality.decided_by_name ?? (tr.unknown_user ?? 'en tidligere bruker')}</dd>
                        </div>
                    </dl>
                    {criticality.reason && (
                        <div className="mt-4">
                            <p className={TERM}>{c.reason ?? 'Begrunnelse'}</p>
                            <p className={`${VALUE} whitespace-pre-line`} data-testid="criticality-reason">{criticality.reason}</p>
                        </div>
                    )}
                    <div className="mt-4">
                        <p className={TERM}>{c.questions_heading ?? 'Beslutningsgrunnlag'}</p>
                        <div className="mt-1"><Basis classification={current} tr={tr} /></div>
                    </div>
                </>
            ) : (
                <p className="mt-4 text-base text-slate-800" data-testid="criticality-none">{c.not_classified ?? 'Kritikalitet er ikke vurdert ennå.'}</p>
            )}

            {canChange && ! open && (
                <button type="button" onClick={() => setOpen(true)} className={`mt-4 ${current ? SECONDARY_ACTION : PRIMARY_ACTION}`}>
                    {current ? (c.change ?? 'Endre kritikalitet') : (c.assess ?? 'Vurder kritikalitet')}
                </button>
            )}

            {canChange && open && (
                <CriticalityForm supplierId={supplierId} current={current} reviewIntervals={reviewIntervals} onDone={() => setOpen(false)} tr={tr} />
            )}

            {(history.length > 0 || registered) && (
                <div className="mt-6 border-t border-slate-100 pt-4">
                    <h3 className="text-lg font-semibold text-slate-950">{c.history_heading ?? 'Endringer i kritikalitet'}</h3>
                    <ol className="mt-2 divide-y divide-slate-100" data-testid="criticality-history">
                        {history.map((entry) => (
                            <li key={entry.id} className="space-y-1 py-3" data-testid="criticality-history-entry">
                                <p className="text-base text-slate-600">{formatLongDate(entry.changed_at, locale)}</p>
                                <p className="text-base font-semibold text-slate-900">{describeCriticalityChange(entry, tr)}</p>
                                {entry.from && entry.from.review_interval_months !== entry.to.review_interval_months && (
                                    <p className="text-base text-slate-700">
                                        {(c.history?.interval_changed ?? 'Vurderingsintervall: :from → :to')
                                            .replace(':from', intervalLabel(entry.from.review_interval_months, tr))
                                            .replace(':to', intervalLabel(entry.to.review_interval_months, tr))}
                                    </p>
                                )}
                                {! entry.from && <p className="text-base text-slate-700">{intervalLabel(entry.to.review_interval_months, tr)}</p>}
                                <p className="whitespace-pre-line break-words text-base text-slate-800">{entry.reason}</p>
                                <details className="pt-1">
                                    <summary className="cursor-pointer text-base font-semibold text-violet-700">{c.questions_heading ?? 'Beslutningsgrunnlag'}</summary>
                                    <div className="mt-2"><Basis classification={entry.to} before={entry.from} tr={tr} /></div>
                                </details>
                            </li>
                        ))}
                        {registered && (
                            <li className="space-y-1 py-3" data-testid="criticality-history-registered">
                                <p className="text-base text-slate-600">{formatLongDate(registered.at, locale)}</p>
                                <p className="text-base font-semibold text-slate-900">{describeCriticalityRegistration(registered, tr)}</p>
                                <p className="text-base text-slate-700">{intervalLabel(registered.classification.review_interval_months, tr)}</p>
                                <details className="pt-1">
                                    <summary className="cursor-pointer text-base font-semibold text-violet-700">{c.questions_heading ?? 'Beslutningsgrunnlag'}</summary>
                                    <div className="mt-2"><Basis classification={registered.classification} tr={tr} /></div>
                                </details>
                            </li>
                        )}
                    </ol>
                </div>
            )}
        </section>
    );
}
