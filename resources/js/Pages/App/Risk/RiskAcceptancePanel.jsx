import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-sm font-semibold text-slate-700';

const formatDate = (value) => (value ? new Date(value).toLocaleDateString('nb-NO') : '—');
// valid_until is a calendar day (Y-m-d); parsed as local time so it never shifts a day across time zones.
const formatDay = (value) => (value ? new Date(`${value}T00:00:00`).toLocaleDateString('nb-NO') : '—');

/**
 * Risikobeslutning: whether the residual risk in the latest assessment has been accepted, by whom,
 * when and why. Which acceptance applies and whether it has expired are the server's answers
 * (current, is_expired), not computed here. Accept and revoke are offered only when the server
 * said this person holds risk.accept for the risk's area; earlier acceptances sit folded beneath.
 */
export default function RiskAcceptancePanel({ riskId, decision, canAccept, tr }) {
    const tx = tr.acceptance ?? {};
    const [accepting, setAccepting] = useState(false);
    const { latest_assessment_id: latestId, has_residual: hasResidual, current, history = [] } = decision ?? {};

    const revoke = () => {
        if (! window.confirm(tx.revoke_confirm ?? 'Trekke tilbake aksepten? Den blir stående i historikken.')) {
            return;
        }

        router.post(`/app/risk/risks/${riskId}/acceptances/${current.id}/revoke`, {}, { preserveScroll: true });
    };

    const canOfferAccept = canAccept && latestId && hasResidual && ! current && ! accepting;

    return (
        <section className={CARD}>
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="text-lg font-semibold text-slate-950">{tx.title ?? 'Risikobeslutning'}</h2>
                    <p className="mt-1 text-sm text-slate-600">
                        {tx.description ?? 'Aksept av restrisiko i siste vurdering. Aksept endrer ikke status eller vurdering — risikoen følges fortsatt opp.'}
                    </p>
                </div>
                {canOfferAccept && (
                    <button type="button" onClick={() => setAccepting(true)} className={PRIMARY_ACTION}>
                        {tx.accept ?? 'Aksepter restrisiko'}
                    </button>
                )}
            </div>

            <div className="mt-4">
                {! latestId && <p className="text-base text-slate-600">{tx.no_assessment ?? 'Risikoen er ikke vurdert ennå.'}</p>}
                {latestId && ! hasResidual && (
                    <p className="text-base text-slate-600">{tx.no_residual ?? 'Siste vurdering har ikke vurdert restrisiko.'}</p>
                )}
                {latestId && hasResidual && ! current && ! accepting && (
                    <p className="text-base text-slate-700">{tx.not_accepted ?? 'Restrisikoen i siste vurdering er ikke akseptert.'}</p>
                )}
                {current && (
                    <CurrentAcceptance acceptance={current} canRevoke={canAccept} onRevoke={revoke} tx={tx} />
                )}
            </div>

            {accepting && (
                <AcceptForm riskId={riskId} assessmentId={latestId} onDone={() => setAccepting(false)} tx={tx} />
            )}

            {history.length > 0 && (
                <details className="mt-6 rounded-2xl bg-slate-50 px-4 py-2">
                    <summary className="cursor-pointer py-1 text-sm font-semibold text-slate-600">
                        {(tx.history ?? 'Tidligere aksepter (:count)').replace(':count', history.length)}
                    </summary>
                    <ul className="divide-y divide-slate-200">
                        {history.map((acceptance) => (
                            <li key={acceptance.id} className="space-y-1 py-3 text-sm text-slate-600">
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusBadge tone="slate">
                                        {acceptance.state === 'revoked'
                                            ? (tx.state_revoked ?? 'Trukket tilbake')
                                            : (tx.state_superseded ?? 'Gjaldt en tidligere vurdering')}
                                    </StatusBadge>
                                    <span>
                                        {(tx.accepted_by ?? 'Akseptert av :name').replace(':name', acceptance.accepted_by_name ?? (tx.unknown_user ?? 'ukjent bruker'))}
                                        {' · '}{formatDate(acceptance.accepted_at)}
                                    </span>
                                    <span>{(tx.for_assessment ?? 'Vurdering :date').replace(':date', formatDate(acceptance.assessment_assessed_at))}</span>
                                    {acceptance.valid_until && (
                                        <span>{tx.valid_until ?? 'Gyldig til'} {formatDay(acceptance.valid_until)}</span>
                                    )}
                                </div>
                                <p className="whitespace-pre-line text-slate-700">{acceptance.rationale}</p>
                                {acceptance.revoked_at && (
                                    <p>
                                        {(tx.revoked_by ?? 'Trukket tilbake :date av :name')
                                            .replace(':date', formatDate(acceptance.revoked_at))
                                            .replace(':name', acceptance.revoked_by_name ?? (tx.unknown_user ?? 'ukjent bruker'))}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </section>
    );
}

function CurrentAcceptance({ acceptance, canRevoke, onRevoke, tx }) {
    const expired = acceptance.is_expired;

    return (
        <div className={`space-y-3 rounded-2xl border p-4 ${expired ? 'border-rose-200 bg-rose-50' : 'border-emerald-200 bg-emerald-50'}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge tone="emerald">{tx.accepted ?? 'Akseptert'}</StatusBadge>
                    {expired && <StatusBadge tone="rose">{tx.expired ?? 'Aksepten er utløpt'}</StatusBadge>}
                </div>
                {canRevoke && (
                    <button type="button" onClick={onRevoke} className={SECONDARY_ACTION}>
                        {tx.revoke ?? 'Trekk tilbake aksept'}
                    </button>
                )}
            </div>
            <dl className="grid gap-3 sm:grid-cols-3">
                <div>
                    <dt className="text-sm font-semibold text-slate-600">{tx.accepted_by_label ?? 'Akseptert av'}</dt>
                    <dd className="mt-1 text-base text-slate-900">{acceptance.accepted_by_name ?? (tx.unknown_user ?? 'ukjent bruker')}</dd>
                </div>
                <div>
                    <dt className="text-sm font-semibold text-slate-600">{tx.accepted_on ?? 'Dato'}</dt>
                    <dd className="mt-1 text-base text-slate-900">{formatDate(acceptance.accepted_at)}</dd>
                </div>
                <div>
                    <dt className="text-sm font-semibold text-slate-600">{tx.valid_until ?? 'Gyldig til'}</dt>
                    <dd className={`mt-1 text-base ${expired ? 'font-semibold text-rose-700' : 'text-slate-900'}`}>
                        {acceptance.valid_until ? formatDay(acceptance.valid_until) : (tx.no_valid_until ?? 'Ingen sluttdato')}
                    </dd>
                </div>
            </dl>
            <div>
                <p className="text-sm font-semibold text-slate-600">{tx.rationale ?? 'Begrunnelse'}</p>
                <p className="mt-1 whitespace-pre-line text-base text-slate-800">{acceptance.rationale}</p>
            </div>
        </div>
    );
}

function AcceptForm({ riskId, assessmentId, onDone, tx }) {
    const form = useForm({ assessment_id: assessmentId, rationale: '', valid_until: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/risk/risks/${riskId}/acceptances`, {
            preserveScroll: true,
            onSuccess: () => { form.reset(); onDone(); },
        });
    };

    return (
        <form onSubmit={submit} className="mt-4 space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <h3 className="text-base font-semibold text-slate-950">{tx.form_heading ?? 'Aksepter restrisiko'}</h3>
            <div>
                <label htmlFor="risk-acceptance-rationale" className={LABEL}>{tx.field_rationale ?? 'Begrunnelse'}</label>
                <textarea
                    id="risk-acceptance-rationale"
                    rows={3}
                    required
                    value={form.data.rationale}
                    onChange={(event) => form.setData('rationale', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                <p className="mt-1 text-sm text-slate-500">{tx.field_rationale_hint ?? 'Hvorfor kan restrisikoen aksepteres?'}</p>
                {form.errors.rationale && <p className="mt-1 text-sm text-rose-700">{form.errors.rationale}</p>}
            </div>
            <div className="max-w-xs">
                <label htmlFor="risk-acceptance-valid-until" className={LABEL}>{tx.field_valid_until ?? 'Gyldig til (valgfritt)'}</label>
                <input
                    id="risk-acceptance-valid-until"
                    type="date"
                    value={form.data.valid_until}
                    onChange={(event) => form.setData('valid_until', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                <p className="mt-1 text-sm text-slate-500">{tx.field_valid_until_hint ?? 'Aksepten gjelder til og med denne dagen.'}</p>
                {form.errors.valid_until && <p className="mt-1 text-sm text-rose-700">{form.errors.valid_until}</p>}
            </div>
            {form.errors.assessment_id && <p className="text-sm text-rose-700">{form.errors.assessment_id}</p>}
            <p className="text-sm text-slate-500">{tx.immutable_note ?? 'En aksept kan ikke endres.'}</p>
            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>{tx.save ?? 'Aksepter'}</button>
                <button type="button" onClick={() => { form.reset(); form.clearErrors(); onDone(); }} className={SECONDARY_ACTION}>
                    {tx.cancel ?? 'Avbryt'}
                </button>
            </div>
        </form>
    );
}
