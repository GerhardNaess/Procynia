import { useForm } from '@inertiajs/react';
import StatusBadge from '../../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import RequiredMark from '../../Risk/RequiredMark';
import { formatDay } from '../../Improvements/improvementStatus';
import { COMPLIANCE_STATUS_TONES, complianceStatusLabel, formatDateTime } from './complianceRequirement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';
const TERM = 'text-base font-semibold text-slate-600';
const VALUE = 'mt-1 break-words text-base text-slate-900';

/**
 * Vurder etterlevelse: a result and a begrunnelse, nothing else. The date is the server's, and the
 * server checks the permission, the requirement's status and both fields again.
 */
function AssessmentForm({ requirementId, results, onDone, tr }) {
    const ta = tr.assessment ?? {};
    const form = useForm({ result: '', rationale: '' });

    const send = (event) => {
        event.preventDefault();
        form.post(`/app/compliance/requirements/${requirementId}/assessments`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={send} className="mt-4 space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-4" aria-labelledby="compliance-assess-heading" data-testid="compliance-assessment-form">
            <div>
                <h3 id="compliance-assess-heading" className="text-lg font-semibold text-slate-950">{ta.form_heading ?? 'Vurder etterlevelse'}</h3>
                <p className="mt-1 text-base text-slate-600">{ta.form_intro ?? 'Vurderingen lagres med dagens dato og kan ikke endres etterpå. Er noe feil, registrerer du en ny vurdering.'}</p>
            </div>
            <fieldset>
                <legend className={LABEL}>{ta.field_result ?? 'Resultat'}<RequiredMark /></legend>
                <div className="mt-2 grid gap-2 sm:grid-cols-2">
                    {results.map((value) => (
                        <label key={value} htmlFor={`compliance-assessment-result-${value}`} className="flex min-h-11 cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900">
                            <input
                                id={`compliance-assessment-result-${value}`}
                                type="radio"
                                name="result"
                                value={value}
                                required
                                checked={form.data.result === value}
                                onChange={() => form.setData('result', value)}
                                className="h-5 w-5"
                            />
                            {complianceStatusLabel(value, tr)}
                        </label>
                    ))}
                </div>
                {form.errors.result && <p className="mt-1 text-base text-rose-700">{form.errors.result}</p>}
            </fieldset>
            <div>
                <label htmlFor="compliance-assessment-rationale" className={LABEL}>{ta.field_rationale ?? 'Begrunnelse'}<RequiredMark /></label>
                <p id="compliance-assessment-rationale-hint" className="mt-1 text-base text-slate-600">
                    {ta.field_rationale_hint ?? 'Beskriv hvorfor virksomheten anses å oppfylle, delvis oppfylle eller ikke oppfylle kravet.'}
                </p>
                <textarea
                    id="compliance-assessment-rationale"
                    rows={4}
                    required
                    aria-required="true"
                    aria-describedby="compliance-assessment-rationale-hint"
                    value={form.data.rationale}
                    onChange={(event) => form.setData('rationale', event.target.value)}
                    className={`mt-2 ${INPUT}`}
                />
                {form.errors.rationale && <p className="mt-1 text-base text-rose-700">{form.errors.rationale}</p>}
            </div>
            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (ta.submit ?? 'Lagre vurdering')}
                </button>
            </div>
        </form>
    );
}

/** One past assessment, with the requirement as it read then behind «Vis krav slik det var». */
function AssessmentEntry({ entry, locale, tr }) {
    const ta = tr.assessment ?? {};
    const snapshot = entry.snapshot ?? {};
    const source = snapshot.source_version ? `${snapshot.source_name} (${snapshot.source_version})` : snapshot.source_name;
    const author = entry.assessed_by_name ?? (tr.unknown_user ?? 'en tidligere bruker');

    return (
        <li className="space-y-2 py-4">
            <div className="flex flex-wrap items-center gap-2">
                <StatusBadge tone={COMPLIANCE_STATUS_TONES[entry.result] ?? 'slate'}>{complianceStatusLabel(entry.result, tr)}</StatusBadge>
                <span className="text-base text-slate-600">{formatDateTime(entry.assessed_at, locale)}</span>
            </div>
            <p className="text-base font-semibold text-slate-900">{(ta.history_entry ?? 'Vurdert av :name').replace(':name', author)}</p>
            <p className="whitespace-pre-line break-words text-base text-slate-700">{entry.rationale}</p>
            <details className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                <summary className="cursor-pointer text-base font-semibold text-violet-700">{ta.show_snapshot ?? 'Vis krav slik det var'}</summary>
                <dl className="mt-3 space-y-2" data-testid="compliance-assessment-snapshot">
                    <div>
                        <dt className={TERM}>{ta.snapshot_source ?? 'Kravkilde'}</dt>
                        <dd className={VALUE}>{source}</dd>
                    </div>
                    {snapshot.reference && (
                        <div>
                            <dt className={TERM}>{ta.snapshot_reference ?? 'Referanse'}</dt>
                            <dd className={VALUE}>{snapshot.reference}</dd>
                        </div>
                    )}
                    <div>
                        <dt className={TERM}>{ta.snapshot_title ?? 'Tittel'}</dt>
                        <dd className={VALUE}>{snapshot.title}</dd>
                    </div>
                    <div>
                        <dt className={TERM}>{ta.snapshot_text ?? 'Kravtekst'}</dt>
                        <dd className={`${VALUE} whitespace-pre-line`}>{snapshot.requirement_text}</dd>
                    </div>
                </dl>
            </details>
        </li>
    );
}

/**
 * Etterlevelse: the requirement's current result as ComplianceStatusResolver computed it, when and
 * by whom it was assessed and why, the next review and whether it is overdue, whether the
 * requirement has changed since, and every assessment, newest first. Nothing here is stored as a
 * status; «Ikke vurdert» is simply no assessment.
 */
export default function RequirementCompliance({ item, compliance, assessments = [], results = [], canAssess, assessing, onAssess, onDone, locale, tr }) {
    const ta = tr.assessment ?? {};
    const status = compliance?.status ?? 'not_assessed';
    const assessed = status !== 'not_assessed';
    const active = item.status === 'active';

    return (
        <section className={CARD} aria-labelledby="compliance-assessment-heading" data-testid="compliance-assessment">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <h2 id="compliance-assessment-heading" className="text-xl font-semibold text-slate-950">{ta.heading ?? 'Etterlevelse'}</h2>
                {canAssess && (
                    <button type="button" onClick={onAssess} className={PRIMARY_ACTION}>{ta.assess ?? 'Vurder etterlevelse'}</button>
                )}
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-2" data-testid="compliance-current">
                <StatusBadge tone={active ? (COMPLIANCE_STATUS_TONES[status] ?? 'slate') : 'slate'}>{complianceStatusLabel(status, tr)}</StatusBadge>
                {compliance?.is_overdue && <StatusBadge tone="amber">{ta.overdue ?? 'Revurdering forfalt'}</StatusBadge>}
            </div>

            {! active && assessed && (
                <p className="mt-3 text-base text-slate-700">{ta.retired_note ?? 'Kravet er utgått. Siste vurdering vises som historikk.'}</p>
            )}

            {compliance?.changed_since_assessment && (
                <div role="status" className="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3" data-testid="compliance-changed-since">
                    <p className="text-base font-semibold text-amber-900">{ta.changed_since ?? 'Kravet er endret siden siste etterlevelsesvurdering.'}</p>
                    <p className="mt-1 text-base text-amber-900">{ta.changed_since_hint ?? ''}</p>
                </div>
            )}

            {assessed ? (
                <>
                    <dl className="mt-4 grid gap-4 sm:grid-cols-3">
                        <div>
                            <dt className={TERM}>{ta.last_assessed ?? 'Sist vurdert'}</dt>
                            <dd className={VALUE}>{formatDateTime(compliance.assessed_at, locale)}</dd>
                        </div>
                        <div>
                            <dt className={TERM}>{ta.assessed_by ?? 'Vurdert av'}</dt>
                            <dd className={VALUE}>{compliance.assessed_by_name ?? (tr.unknown_user ?? 'en tidligere bruker')}</dd>
                        </div>
                        {active && (
                            <div>
                                <dt className={TERM}>{ta.next_review ?? 'Neste vurdering'}</dt>
                                <dd className={VALUE} data-testid="compliance-next-review">
                                    {compliance.next_review_on ? formatDay(compliance.next_review_on) : (ta.no_next_review ?? 'Ingen fast revurdering')}
                                </dd>
                            </div>
                        )}
                    </dl>
                    <div className="mt-4">
                        <p className={TERM}>{ta.rationale ?? 'Begrunnelse'}</p>
                        <p className="mt-1 whitespace-pre-line break-words text-base text-slate-800" data-testid="compliance-current-rationale">{compliance.rationale}</p>
                    </div>
                </>
            ) : (
                <p className="mt-3 text-base text-slate-700">{ta.not_assessed_text ?? 'Kravet er ikke vurdert ennå.'}</p>
            )}

            {assessing && <AssessmentForm requirementId={item.id} results={results} onDone={onDone} tr={tr} />}

            {assessments.length > 0 && (
                <div className="mt-6 border-t border-slate-100 pt-4">
                    <h3 className="text-lg font-semibold text-slate-950">{ta.history_heading ?? 'Vurderingshistorikk'}</h3>
                    <ol className="divide-y divide-slate-100" data-testid="compliance-assessment-history">
                        {assessments.map((entry) => <AssessmentEntry key={entry.id} entry={entry} locale={locale} tr={tr} />)}
                    </ol>
                </div>
            )}
        </section>
    );
}
