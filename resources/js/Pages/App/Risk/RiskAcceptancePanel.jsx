import { useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { RequiredFieldsNote } from '../../../Components/App/FormField';
import StatusBadge from '../../../Components/App/StatusBadge';
import RequiredMark from './RequiredMark';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { refocusIfLost, useFocusFirstError } from '../../../Support/focusManagement';
import { fieldA11y } from '../../../Support/formFieldA11y';
import { ERROR, HINT, INPUT, LABEL } from '../../../Support/formStyles';
import useInlinePanelFocus from './useInlinePanelFocus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

const formatDate = (value) => (value ? new Date(value).toLocaleDateString('nb-NO') : '—');
// valid_until is a calendar day (Y-m-d); parsed as local time so it never shifts a day across time zones.
const formatDay = (value) => (value ? new Date(`${value}T00:00:00`).toLocaleDateString('nb-NO') : '—');

/**
 * Aksept av restrisiko: whether the residual risk in the latest assessment has been accepted, by whom,
 * when and why. Which acceptance applies and whether it has expired are the server's answers
 * (current, is_expired), not computed here. Accept and revoke are offered only when the server
 * said this person holds risk.accept for the risk's area; earlier acceptances sit folded beneath.
 */
export default function RiskAcceptancePanel({ riskId, decision, canAccept, tr }) {
    const tx = tr.acceptance ?? {};
    const [accepting, setAccepting] = useState(false);
    const { latest_assessment_id: latestId, has_residual: hasResidual, current, history = [] } = decision ?? {};
    // «Aksepter restrisiko» is hidden while its form is open. Avbryt returns to it; a saved
    // acceptance replaces it with «Trekk tilbake aksept», and a revoke the other way round — focus
    // follows to whichever is there, else the heading (A11Y-014).
    const formPanelRef = useRef(null);
    useInlinePanelFocus(accepting, { panelRef: formPanelRef, returnIds: ['risk-acceptance-accept', 'risk-acceptance-revoke', 'risk-acceptance-heading'] });

    const revoke = () => {
        if (! window.confirm(tx.revoke_confirm ?? 'Trekke tilbake aksepten? Den blir stående i historikken.')) {
            return;
        }

        router.post(`/app/risk/risks/${riskId}/acceptances/${current.id}/revoke`, {}, {
            preserveScroll: true,
            onSuccess: () => refocusIfLost(['risk-acceptance-accept', 'risk-acceptance-heading']),
        });
    };

    const canOfferAccept = canAccept && latestId && hasResidual && ! current && ! accepting;

    return (
        <section className={CARD}>
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 id="risk-acceptance-heading" className="text-lg font-semibold text-slate-950">{tx.title ?? 'Aksept av restrisiko'}</h2>
                    <p className="mt-1 text-base text-slate-600">
                        {tx.description ?? 'Formell beslutning om at restrisikoen i siste vurdering kan aksepteres. Behandlingsvalget «Akseptere» er bare retningen — det er denne beslutningen som gjelder. Risikoen følges fortsatt opp.'}
                    </p>
                </div>
                {canOfferAccept && (
                    <button id="risk-acceptance-accept" type="button" onClick={() => setAccepting(true)} className={PRIMARY_ACTION}>
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
                <AcceptForm formRef={formPanelRef} riskId={riskId} assessmentId={latestId} onDone={() => setAccepting(false)} tx={tx} tr={tr} />
            )}

            {history.length > 0 && (
                <details className="mt-6 rounded-2xl bg-slate-50 px-4 py-2">
                    <summary className="cursor-pointer py-1 text-base font-semibold text-slate-600">
                        {(tx.history ?? 'Tidligere aksepter (:count)').replace(':count', history.length)}
                    </summary>
                    <ul className="divide-y divide-slate-200">
                        {history.map((acceptance) => (
                            <li key={acceptance.id} className="space-y-1 py-3 text-base text-slate-600">
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
                    <button id="risk-acceptance-revoke" type="button" onClick={onRevoke} className={SECONDARY_ACTION}>
                        {tx.revoke ?? 'Trekk tilbake aksept'}
                    </button>
                )}
            </div>
            <dl className="grid gap-3 sm:grid-cols-3">
                <div>
                    <dt className="text-base font-semibold text-slate-600">{tx.accepted_by_label ?? 'Akseptert av'}</dt>
                    <dd className="mt-1 text-base text-slate-900">{acceptance.accepted_by_name ?? (tx.unknown_user ?? 'ukjent bruker')}</dd>
                </div>
                <div>
                    <dt className="text-base font-semibold text-slate-600">{tx.accepted_on ?? 'Dato'}</dt>
                    <dd className="mt-1 text-base text-slate-900">{formatDate(acceptance.accepted_at)}</dd>
                </div>
                <div>
                    <dt className="text-base font-semibold text-slate-600">{tx.valid_until ?? 'Gyldig til'}</dt>
                    <dd className={`mt-1 text-base ${expired ? 'font-semibold text-rose-700' : 'text-slate-900'}`}>
                        {acceptance.valid_until ? formatDay(acceptance.valid_until) : (tx.no_valid_until ?? 'Ingen sluttdato')}
                    </dd>
                </div>
            </dl>
            <div>
                <p className="text-base font-semibold text-slate-600">{tx.rationale ?? 'Begrunnelse'}</p>
                <p className="mt-1 whitespace-pre-line text-base text-slate-800">{acceptance.rationale}</p>
            </div>
        </div>
    );
}

function AcceptForm({ formRef, riskId, assessmentId, onDone, tx, tr }) {
    const form = useForm({ assessment_id: assessmentId, rationale: '', valid_until: '' });
    const focusFirstError = useFocusFirstError(formRef);

    const submit = (event) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        form.post(`/app/risk/risks/${riskId}/acceptances`, {
            preserveScroll: true,
            onSuccess: () => { form.reset(); onDone(); },
            onError: () => focusFirstError(),
        });
    };

    const rationale = fieldA11y({ id: 'risk-acceptance-rationale', hasHint: true, hasError: Boolean(form.errors.rationale), required: true });
    const validUntil = fieldA11y({ id: 'risk-acceptance-valid-until', hasHint: true, hasError: Boolean(form.errors.valid_until) });

    return (
        <form ref={formRef} onSubmit={submit} className="mt-4 space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <h3 className="text-base font-semibold text-slate-950">{tx.form_heading ?? 'Aksepter restrisiko'}</h3>
            <div>
                <label htmlFor="risk-acceptance-rationale" className={LABEL}>{tx.field_rationale ?? 'Begrunnelse'}<RequiredMark /></label>
                <textarea
                    {...rationale.controlProps}
                    rows={3}
                    required
                    value={form.data.rationale}
                    onChange={(event) => form.setData('rationale', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                <p id={rationale.hintId} className={HINT}>{tx.field_rationale_hint ?? 'Hvorfor kan restrisikoen aksepteres?'}</p>
                {form.errors.rationale && <p id={rationale.errorId} className={ERROR}>{form.errors.rationale}</p>}
            </div>
            <div className="max-w-xs">
                <label htmlFor="risk-acceptance-valid-until" className={LABEL}>{tx.field_valid_until ?? 'Gyldig til (valgfritt)'}</label>
                <input
                    {...validUntil.controlProps}
                    type="date"
                    value={form.data.valid_until}
                    onChange={(event) => form.setData('valid_until', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                <p id={validUntil.hintId} className={HINT}>{tx.field_valid_until_hint ?? 'Aksepten gjelder til og med denne dagen.'}</p>
                {form.errors.valid_until && <p id={validUntil.errorId} className={ERROR}>{form.errors.valid_until}</p>}
            </div>
            {/* Not about a field (the assessment changed meanwhile): useFocusFirstError lands here. */}
            {form.errors.assessment_id && <p data-form-error-summary="" className="text-base text-rose-700">{form.errors.assessment_id}</p>}
            <p className="text-base text-slate-600">{tx.immutable_note ?? 'En aksept kan ikke endres.'}</p>
            <RequiredFieldsNote text={tr?.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />
            <div className="flex flex-wrap gap-2">
                <button type="submit" aria-disabled={form.processing || undefined} className={PRIMARY_ACTION}>{tx.save ?? 'Aksepter'}</button>
                <button type="button" onClick={() => { form.reset(); form.clearErrors(); onDone(); }} className={SECONDARY_ACTION}>
                    {tx.cancel ?? 'Avbryt'}
                </button>
            </div>
        </form>
    );
}
