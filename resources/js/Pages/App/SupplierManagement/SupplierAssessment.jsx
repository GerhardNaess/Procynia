import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { formatLongDate } from '../Improvements/improvementStatus';
import RequiredMark from '../Risk/RequiredMark';
import SupplierCriticalityBadge from './SupplierCriticalityBadge';
import {
    ASSESSMENT_CRITERIA,
    RESULT_TONES,
    assessmentNeedsFollowUp,
    criterionLabel,
    criticalityLabel,
    emptyAssessment,
    intervalLabel,
    nextReviewText,
    ratingLabel,
    resultLabel,
} from './supplierManagement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const TERM = 'text-base font-semibold text-slate-600';
const VALUE = 'mt-1 break-words text-base text-slate-900';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LEGEND = 'text-base font-semibold text-slate-900';
const ERROR = 'mt-1 text-base text-rose-700';

/** The four criteria and how each was rated. */
function Ratings({ ratings, tr }) {
    return (
        <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
            {ASSESSMENT_CRITERIA.map((criterion) => (
                <div key={criterion} className="min-w-0">
                    <dt className="text-base text-slate-700">{criterionLabel(criterion, tr)}</dt>
                    <dd className="text-base font-semibold text-slate-900">{ratingLabel(ratings[criterion], tr)}</dd>
                </div>
            ))}
        </dl>
    );
}

/**
 * «Følg opp vurderingen i Avvik og forbedringer» under an assessment whose result calls for it —
 * offered only when the page can hand off (onFollowUp is null otherwise). The form opens under Avvik
 * og forbedringer hos leverandøren; the assessment itself never changes.
 */
function FollowUpButton({ assessment, onFollowUp, tr }) {
    if (! onFollowUp || ! assessmentNeedsFollowUp(assessment)) {
        return null;
    }

    return (
        <button type="button" onClick={() => onFollowUp(assessment)} className={SECONDARY_ACTION} data-testid="assessment-follow-up">
            {tr.cases?.follow_up_assessment ?? 'Følg opp vurderingen i Avvik og forbedringer'}
        </button>
    );
}

/** «Kritikalitet på tidspunktet: Viktig · Hver 24. måned» — the snapshot, never today's value. */
function CriticalityThen({ assessment, tr }) {
    const a = tr.assessment ?? {};

    return (
        <p className="text-base text-slate-700">
            {a.criticality_then ?? 'Kritikalitet på tidspunktet'}:{' '}
            <span className="font-semibold text-slate-900">
                {assessment.criticality ? criticalityLabel(assessment.criticality, tr) : (a.not_classified_then ?? 'Ikke vurdert')}
            </span>
            {assessment.criticality && <> · {intervalLabel(assessment.review_interval_months, tr)}</>}
        </p>
    );
}

function AssessmentForm({ supplierId, supplier, criteria, ratings, results, today, onDone, tr }) {
    const a = tr.assessment ?? {};
    const form = useForm(emptyAssessment(today));

    const send = (event) => {
        event.preventDefault();
        form.post(`/app/supplier-management/${supplierId}/assessments`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={send} className="mt-4 space-y-5 border-t border-slate-100 pt-5" data-testid="assessment-form">
            <div>
                <h3 className="text-lg font-semibold text-slate-950">{a.form_heading ?? 'Vurder leverandør'}</h3>
                <p className="mt-1 text-base text-slate-600">
                    {a.form_intro ?? 'Vurder de fire områdene, og velg deretter samlet vurdering selv. Områdene er grunnlaget for valget ditt – de regnes ikke om til et resultat.'}
                </p>
            </div>

            {/* Context only: the assessment never changes the criticality. */}
            <div className="rounded-xl bg-slate-50 px-4 py-3" data-testid="assessment-criticality-context">
                <p className="text-base font-semibold text-slate-700">{a.context_heading ?? 'Kritikalitet nå'}</p>
                <div className="mt-1 flex flex-wrap items-center gap-2">
                    <SupplierCriticalityBadge level={supplier.criticality} tr={tr} />
                    {supplier.criticality && <span className="text-base text-slate-700">{intervalLabel(supplier.review_interval_months, tr)}</span>}
                </div>
                <p className="mt-1 text-base text-slate-600">{a.context_hint ?? 'Kritikaliteten endres ikke av vurderingen. Den endres under Kritikalitet.'}</p>
            </div>

            <fieldset className="space-y-3">
                <legend className={LEGEND}>{a.criteria_heading ?? 'Hvordan fungerer leverandøren?'}</legend>
                {criteria.map((criterion) => (
                    <fieldset key={criterion} className="relative rounded-xl border border-slate-200 px-4 py-3" data-testid={`assessment-criterion-${criterion}`}>
                        <legend className="sr-only">{criterionLabel(criterion, tr)}</legend>
                        <p className="text-base font-semibold text-slate-900" aria-hidden="true">{criterionLabel(criterion, tr)}<RequiredMark /></p>
                        <p className="text-base text-slate-600">{a.criteria_hints?.[criterion] ?? ''}</p>
                        <div className="mt-2 flex flex-wrap gap-x-6 gap-y-2">
                            {ratings.map((rating) => (
                                <label key={rating} className="flex min-h-10 items-center gap-2 text-base text-slate-900">
                                    <input
                                        type="radio"
                                        name={`assessment-${criterion}`}
                                        checked={form.data[criterion] === rating}
                                        onChange={() => form.setData(criterion, rating)}
                                        className="h-5 w-5 shrink-0"
                                    />
                                    {ratingLabel(rating, tr)}
                                </label>
                            ))}
                        </div>
                        {form.errors[criterion] && <p className={ERROR}>{form.errors[criterion]}</p>}
                    </fieldset>
                ))}
            </fieldset>

            <fieldset className="space-y-2" data-testid="assessment-result">
                <legend className={LEGEND}>{a.result_label ?? 'Samlet vurdering'}<RequiredMark /></legend>
                {results.map((result) => (
                    <label key={result} className="flex min-h-10 items-center gap-3 rounded-xl border border-slate-200 px-3 py-2 text-base font-semibold text-slate-900">
                        <input
                            type="radio"
                            name="assessment-overall-result"
                            checked={form.data.overall_result === result}
                            onChange={() => form.setData('overall_result', result)}
                            className="h-5 w-5 shrink-0"
                        />
                        {resultLabel(result, tr)}
                    </label>
                ))}
                {form.errors.overall_result && <p className={ERROR}>{form.errors.overall_result}</p>}
            </fieldset>

            <div>
                <label htmlFor="supplier-assessment-rationale" className={`block ${LEGEND}`}>{a.rationale_label ?? 'Begrunnelse'}<RequiredMark /></label>
                <p id="supplier-assessment-rationale-hint" className="mt-1 text-base text-slate-600">{a.rationale_hint ?? 'Hvorfor fikk leverandøren denne vurderingen?'}</p>
                <textarea
                    id="supplier-assessment-rationale"
                    rows={4}
                    required
                    aria-required="true"
                    aria-describedby="supplier-assessment-rationale-hint"
                    value={form.data.rationale}
                    onChange={(event) => form.setData('rationale', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.rationale && <p className={ERROR}>{form.errors.rationale}</p>}
            </div>

            <div>
                <label htmlFor="supplier-assessment-date" className={`block ${LEGEND}`}>{a.assessed_on_label ?? 'Vurderingsdato'}<RequiredMark /></label>
                <p id="supplier-assessment-date-hint" className="mt-1 text-base text-slate-600">{a.assessed_on_hint ?? 'Dagen vurderingen gjelder. Kan ikke være fram i tid.'}</p>
                <input
                    id="supplier-assessment-date"
                    type="date"
                    required
                    aria-required="true"
                    aria-describedby="supplier-assessment-date-hint"
                    max={today}
                    value={form.data.assessed_on}
                    onChange={(event) => form.setData('assessed_on', event.target.value)}
                    className={`mt-1 ${INPUT} md:max-w-xs`}
                />
                {form.errors.assessed_on && <p className={ERROR}>{form.errors.assessed_on}</p>}
            </div>

            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (a.submit ?? 'Lagre vurdering')}
                </button>
            </div>
        </form>
    );
}

/**
 * Leverandørvurdering on the supplier page: how the supplier performs now — the current assessment
 * (result, date, who, the four criteria, why, and the criticality at that time), Neste vurdering,
 * and every earlier assessment, newest first. Separate from Kritikalitet, which it never changes.
 *
 * «Vurder leverandør» is offered only when the server says this person may assess this supplier;
 * someone with the right is told why it is missing for a supplier not in use or ended.
 */
export default function SupplierAssessment({ supplier, assessments = [], permissions = {}, criteria = [], ratings = [], results = [], today, onFollowUp = null, locale, tr }) {
    const a = tr.assessment ?? {};
    const [open, setOpen] = useState(false);
    const [current, ...earlier] = assessments;
    const date = (iso) => formatLongDate(iso, locale);

    return (
        <section className={CARD} aria-labelledby="supplier-assessment-heading" data-testid="supplier-assessment">
            <h2 id="supplier-assessment-heading" className="text-xl font-semibold text-slate-950">{a.heading ?? 'Leverandørvurdering'}</h2>
            <p className="mt-1 text-base text-slate-600">{a.intro ?? 'Hvordan leverandøren fungerer nå – ikke hvor viktig den er.'}</p>

            {current ? (
                <div className="mt-4 space-y-4" data-testid="assessment-current">
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="min-w-0">
                            <dt className={TERM}>{a.result_label ?? 'Samlet vurdering'}</dt>
                            <dd className="mt-1"><StatusBadge tone={RESULT_TONES[current.overall_result] ?? 'slate'}>{resultLabel(current.overall_result, tr)}</StatusBadge></dd>
                        </div>
                        <div className="min-w-0">
                            <dt className={TERM}>{a.last_assessed ?? 'Sist vurdert'}</dt>
                            <dd className={VALUE}>{date(current.assessed_on)}</dd>
                        </div>
                        <div className="min-w-0">
                            <dt className={TERM}>{a.assessed_by ?? 'Vurdert av'}</dt>
                            <dd className={VALUE}>{current.assessed_by_name ?? (tr.unknown_user ?? 'en tidligere bruker')}</dd>
                        </div>
                        <div className="min-w-0">
                            <dt className={TERM}>{a.next_review ?? 'Neste vurdering'}</dt>
                            <dd className={VALUE} data-testid="assessment-next-review">{nextReviewText(supplier, tr, date)}</dd>
                        </div>
                    </dl>
                    <Ratings ratings={current.ratings} tr={tr} />
                    <div>
                        <p className={TERM}>{a.rationale_label ?? 'Begrunnelse'}</p>
                        <p className={`${VALUE} whitespace-pre-line`} data-testid="assessment-rationale">{current.rationale}</p>
                    </div>
                    <CriticalityThen assessment={current} tr={tr} />
                    <FollowUpButton assessment={current} onFollowUp={onFollowUp} tr={tr} />
                </div>
            ) : (
                <p className="mt-4 text-base text-slate-800" data-testid="assessment-none">{a.none ?? 'Leverandøren er ikke vurdert ennå.'}</p>
            )}

            {permissions.can_assess && ! open && (
                <button type="button" onClick={() => setOpen(true)} className={`mt-4 ${PRIMARY_ACTION}`}>{a.assess ?? 'Vurder leverandør'}</button>
            )}
            {! permissions.can_assess && permissions.has_assess_right && (
                <p className="mt-4 text-base text-slate-600">
                    {supplier.status === 'ended'
                        ? (a.reopen_to_assess ?? 'Leverandøren er avsluttet. Gjenåpne den for å vurdere den.')
                        : (a.only_active ?? 'Leverandøren kan vurderes når den er tatt i bruk.')}
                </p>
            )}

            {permissions.can_assess && open && (
                <AssessmentForm
                    supplierId={supplier.id}
                    supplier={supplier}
                    criteria={criteria}
                    ratings={ratings}
                    results={results}
                    today={today}
                    onDone={() => setOpen(false)}
                    tr={tr}
                />
            )}

            {earlier.length > 0 && (
                <div className="mt-6 border-t border-slate-100 pt-4">
                    <h3 className="text-lg font-semibold text-slate-950">{a.history_heading ?? 'Tidligere vurderinger'}</h3>
                    <ol className="mt-2 divide-y divide-slate-100" data-testid="assessment-history">
                        {earlier.map((entry) => (
                            <li key={entry.id} className="space-y-1 py-3" data-testid="assessment-history-entry">
                                <p className="text-base text-slate-600">
                                    {(a.history_entry ?? 'Vurdert :date av :name')
                                        .replace(':date', date(entry.assessed_on))
                                        .replace(':name', entry.assessed_by_name ?? (tr.unknown_user ?? 'en tidligere bruker'))}
                                </p>
                                <p className="text-base text-slate-900">
                                    {a.result_label ?? 'Samlet vurdering'}: <span className="font-semibold">{resultLabel(entry.overall_result, tr)}</span>
                                </p>
                                <CriticalityThen assessment={entry} tr={tr} />
                                <p className="whitespace-pre-line break-words text-base text-slate-800">{entry.rationale}</p>
                                <FollowUpButton assessment={entry} onFollowUp={onFollowUp} tr={tr} />
                                <details className="pt-1">
                                    <summary className="cursor-pointer text-base font-semibold text-violet-700">{a.criteria_heading ?? 'Hvordan fungerer leverandøren?'}</summary>
                                    <div className="mt-2"><Ratings ratings={entry.ratings} tr={tr} /></div>
                                </details>
                            </li>
                        ))}
                    </ol>
                </div>
            )}
        </section>
    );
}
