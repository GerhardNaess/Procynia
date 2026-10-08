import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DISCLOSURE_INLINE, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import RequiredMark from '../Risk/RequiredMark';
import {
    DUE_DILIGENCE_CONCLUSION_TONES,
    DUE_DILIGENCE_STEPS,
    areaRows,
    conclusionLabel,
    dueDiligenceByline,
    dueDiligenceFormData,
    dueDiligenceMissing,
    levelLabel,
    mappingFacts,
    nextText,
    relatedRequirements,
    withConclusion,
} from './dueDiligence';
import { DISPLAY_STATUS_TONES, displayStatusLabel } from './requirementEvaluations';
import { criticalityLabel } from './supplierManagement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const HINT = 'text-base text-slate-600';
const ERROR = 'text-base text-rose-700';
const LABEL = 'block text-base font-semibold text-slate-900';
const INPUT = 'mt-1 min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const TERM = 'text-base font-semibold text-slate-600';

/** The six areas of one assessment, each named with its level in words — never colour alone. */
function AreaList({ areas, tr, testId }) {
    return (
        <ul className="mt-2 grid min-w-0 gap-2 sm:grid-cols-2" data-testid={testId}>
            {areaRows(areas, tr).map((row) => (
                <li key={row.area} className="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2" data-area={row.area} data-level={row.level ?? ''}>
                    <span className="min-w-0 break-words text-base text-slate-900">{row.label}</span>
                    <StatusBadge tone={row.tone}>{row.levelText}</StatusBadge>
                </li>
            ))}
        </ul>
    );
}

/**
 * Registrer aktsomhetsvurdering: the six areas one by one (no default level), what was mapped and
 * investigated, the conclusion the person chooses and why, how soon to assess again, and the date.
 * The interval follows the plan's suggestion until the person picks one.
 */
function DueDiligenceForm({ supplierId, options, onDone, tr }) {
    const d = tr.due_diligence ?? {};
    const f = d.form ?? {};
    const form = useForm(dueDiligenceFormData(options.today));
    const [intervalChosen, setIntervalChosen] = useState(false);
    const missing = dueDiligenceMissing(form.data);

    const send = (event) => {
        event.preventDefault();
        form.post(`/app/supplier-management/${supplierId}/due-diligence-assessments`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={send} className="mt-4 min-w-0 space-y-5 rounded-xl border border-slate-200 bg-slate-50 p-4" data-testid="due-diligence-form">
            <div className="min-w-0">
                <h3 className="text-lg font-semibold text-slate-950">{f.heading ?? 'Registrer aktsomhetsvurdering'}</h3>
                <p className={HINT}>{f.intro ?? 'Vurder hvert område for seg. Velg «Ukjent» når dere ikke vet – det er ikke det samme som lav risiko.'}</p>
            </div>

            <div>
                <label htmlFor={`due-diligence-${supplierId}-mapping`} className={LABEL}>{f.supply_chain_description ?? 'Kartlegging av leverandørkjeden'}</label>
                <p className={HINT}>{f.supply_chain_description_hint ?? 'Valgfritt. Produksjonsland, ledd og kjente underleverandører.'}</p>
                <textarea id={`due-diligence-${supplierId}-mapping`} rows={3} value={form.data.supply_chain_description} onChange={(event) => form.setData('supply_chain_description', event.target.value)} className={INPUT} />
                {form.errors.supply_chain_description && <p className={ERROR}>{form.errors.supply_chain_description}</p>}
            </div>

            <fieldset className="min-w-0 space-y-3">
                <legend className={LABEL}>{f.areas ?? 'Risiko per område'}<RequiredMark /></legend>
                {areaRows({}, tr).map((row) => (
                    <div key={row.area} className="min-w-0 rounded-lg border border-slate-200 bg-white p-3" data-testid={`due-diligence-area-${row.area}`}>
                        <p className="break-words text-base font-semibold text-slate-900" id={`due-diligence-${supplierId}-${row.area}`}>{row.label}</p>
                        <div role="radiogroup" aria-labelledby={`due-diligence-${supplierId}-${row.area}`} className="mt-1 flex flex-wrap gap-x-5 gap-y-1">
                            {(options.levels ?? []).map((level) => (
                                <label key={level} className="flex min-h-10 items-center gap-2 text-base text-slate-900">
                                    <input
                                        type="radio"
                                        name={`due-diligence-${supplierId}-${row.area}`}
                                        value={level}
                                        checked={form.data[row.area] === level}
                                        onChange={() => form.setData(row.area, level)}
                                        className="h-5 w-5 shrink-0"
                                    />
                                    {levelLabel(level, tr)}
                                </label>
                            ))}
                        </div>
                        {form.errors[row.area] && <p className={ERROR}>{form.errors[row.area]}</p>}
                    </div>
                ))}
            </fieldset>

            <div>
                <label htmlFor={`due-diligence-${supplierId}-investigation`} className={LABEL}>{f.investigation_summary ?? 'Hva er undersøkt?'}</label>
                <p className={HINT}>{f.investigation_summary_hint ?? 'Valgfritt. Hva som er undersøkt, og hvordan – for eksempel egenerklæring, oversikt over produksjonssteder eller revisjon.'}</p>
                <textarea id={`due-diligence-${supplierId}-investigation`} rows={3} value={form.data.investigation_summary} onChange={(event) => form.setData('investigation_summary', event.target.value)} className={INPUT} />
                {form.errors.investigation_summary && <p className={ERROR}>{form.errors.investigation_summary}</p>}
            </div>

            <fieldset className="min-w-0">
                <legend className={LABEL}>{f.conclusion ?? 'Konklusjon'}<RequiredMark /></legend>
                <p className={HINT}>{f.conclusion_hint ?? 'Du velger konklusjonen. Den regnes ikke ut fra områdene.'}</p>
                <div className="mt-1 space-y-1">
                    {(options.conclusions ?? []).map((conclusion) => (
                        <label key={conclusion} className="flex min-h-10 items-center gap-3 text-base text-slate-900" data-testid={`due-diligence-conclusion-${conclusion}`}>
                            <input
                                type="radio"
                                name={`due-diligence-${supplierId}-conclusion`}
                                value={conclusion}
                                checked={form.data.conclusion === conclusion}
                                onChange={() => form.setData((data) => withConclusion(data, conclusion, intervalChosen))}
                                className="h-5 w-5 shrink-0"
                            />
                            {conclusionLabel(conclusion, tr)}
                        </label>
                    ))}
                </div>
                {form.errors.conclusion && <p className={ERROR}>{form.errors.conclusion}</p>}
            </fieldset>

            <div>
                <label htmlFor={`due-diligence-${supplierId}-rationale`} className={LABEL}>{f.rationale ?? 'Begrunnelse'}<RequiredMark /></label>
                <p className={HINT}>{f.rationale_hint ?? 'Hvorfor denne konklusjonen?'}</p>
                <textarea id={`due-diligence-${supplierId}-rationale`} rows={4} required aria-required value={form.data.rationale} onChange={(event) => form.setData('rationale', event.target.value)} className={INPUT} />
                {form.errors.rationale && <p className={ERROR}>{form.errors.rationale}</p>}
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <label htmlFor={`due-diligence-${supplierId}-interval`} className={LABEL}>{f.review_interval_months ?? 'Ny vurdering om'}<RequiredMark /></label>
                    <p className={HINT}>{f.review_interval_hint ?? 'Foreslått ut fra konklusjonen. Du kan endre det.'}</p>
                    <select
                        id={`due-diligence-${supplierId}-interval`}
                        required
                        value={form.data.review_interval_months}
                        onChange={(event) => { setIntervalChosen(true); form.setData('review_interval_months', event.target.value); }}
                        className={INPUT}
                    >
                        <option value="">{f.choose_interval ?? 'Velg'}</option>
                        {(options.review_intervals ?? []).map((months) => (
                            <option key={months} value={months}>{(f.months ?? ':count måneder').replace(':count', String(months))}</option>
                        ))}
                    </select>
                    {form.errors.review_interval_months && <p className={ERROR}>{form.errors.review_interval_months}</p>}
                </div>
                <div>
                    <label htmlFor={`due-diligence-${supplierId}-assessed-on`} className={LABEL}>{f.assessed_on ?? 'Vurderingsdato'}<RequiredMark /></label>
                    <p className={HINT}>{f.assessed_on_hint ?? 'Kan ikke være fram i tid.'}</p>
                    <input id={`due-diligence-${supplierId}-assessed-on`} type="date" required max={options.today} value={form.data.assessed_on} onChange={(event) => form.setData('assessed_on', event.target.value)} className={INPUT} />
                    {form.errors.assessed_on && <p className={ERROR}>{form.errors.assessed_on}</p>}
                </div>
            </div>

            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing || missing.length > 0} className={PRIMARY_ACTION}>{f.submit ?? 'Registrer aktsomhetsvurdering'}</button>
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
            </div>
            {missing.length > 0 && <p className={HINT} data-testid="due-diligence-missing">{f.missing ?? 'Vurder alle seks områdene, og velg konklusjon og begrunnelse før du registrerer.'}</p>}
        </form>
    );
}

/** One assessment in the history: who, when, the six areas, the conclusion and why — as it was. */
function HistoryEntry({ entry, formatDate, tr }) {
    const d = tr.due_diligence ?? {};

    return (
        <li className="min-w-0 border-l-2 border-slate-200 pl-3" data-testid="due-diligence-history-entry">
            <div className="flex flex-wrap items-center gap-2">
                <StatusBadge tone={DUE_DILIGENCE_CONCLUSION_TONES[entry.conclusion] ?? 'slate'}>{conclusionLabel(entry.conclusion, tr)}</StatusBadge>
                <span className="text-base text-slate-600">{dueDiligenceByline(entry, tr, formatDate)}</span>
            </div>
            <AreaList areas={entry.areas} tr={tr} testId="due-diligence-history-areas" />
            {entry.supply_chain_description && <p className="mt-2 whitespace-pre-line break-words text-base text-slate-900">{d.supply_chain_label ?? 'Kartlegging'}: {entry.supply_chain_description}</p>}
            {entry.investigation_summary && <p className="mt-1 whitespace-pre-line break-words text-base text-slate-900">{d.investigation_label ?? 'Undersøkt'}: {entry.investigation_summary}</p>}
            <p className="mt-1 whitespace-pre-line break-words text-base text-slate-900">{d.rationale_label ?? 'Begrunnelse'}: {entry.rationale}</p>
            <p className="mt-1 text-base text-slate-600">
                {(d.interval_label ?? 'Ny vurdering etter :count måneder').replace(':count', String(entry.review_interval_months))}
                {entry.snapshot?.criticality && ` · ${(d.snapshot_criticality ?? 'Kritikalitet da: :level').replace(':level', criticalityLabel(entry.snapshot.criticality, tr))}`}
            </p>
            {entry.recorded_at && <p className="text-base text-slate-600">{(d.registered_at ?? 'Registrert :date').replace(':date', formatDate(entry.recorded_at))}</p>}
        </li>
    );
}

/**
 * Aktsomhet og bærekraft (docs/supplier-assurance-v2-plan.md §11, §22.1): a structured assessment of
 * risk and follow-up in the supply chain. Not a score — six areas, each named with its level, and a
 * conclusion a person chose. Shown also when the profile does not make it relevant; then it says so.
 *
 * The six steps are guidance, not a wizard: the profile maps, the assessment records the risk,
 * control requirements investigate, Avvik and Risiko carry the measures, and the next assessment
 * falls due.
 */
export default function SupplierDueDiligence({ supplierId, data, applicable = [], onFollowUp = null, onCreateRisk = null, formatDate = (date) => date, tr }) {
    const d = tr.due_diligence ?? {};
    const [assessing, setAssessing] = useState(false);

    if (! data) {
        return null;
    }

    const current = data.history?.[0] ?? null;
    const facts = mappingFacts(data.mapping, tr);
    const related = relatedRequirements(applicable);
    const next = nextText(data, tr, formatDate);
    const canAct = data.permissions?.can_assess && ! assessing;

    return (
        <section className={CARD} aria-labelledby="supplier-due-diligence-heading" data-testid="supplier-due-diligence">
            <h2 id="supplier-due-diligence-heading" className="text-xl font-semibold text-slate-950">{d.heading ?? 'Aktsomhet og bærekraft'}</h2>
            <p className={`mt-1 ${HINT}`}>{d.intro ?? 'En strukturert vurdering av risiko og oppfølging i leverandørkjeden. Vurderingen gjøres av en person; systemet regner ikke ut noen samlet risiko.'}</p>

            <div className="mt-4 rounded-xl border border-slate-200 p-4" data-testid="due-diligence-relevance">
                {data.relevant ? (
                    <>
                        <p className="text-base font-semibold text-slate-900">{d.relevant ?? 'Aktsomhetsvurdering er relevant for leverandøren'}</p>
                        {data.because?.text && <p className="mt-1 break-words text-base text-slate-800">{data.because.text}</p>}
                        {(data.because?.also ?? []).length > 0 && (
                            <p className="mt-1 break-words text-base text-slate-700">{(d.also ?? 'Også fordi :reasons').replace(':reasons', data.because.also.join('; '))}</p>
                        )}
                    </>
                ) : (
                    <p className="text-base text-slate-800">{d.not_relevant ?? 'Ikke påkrevd ut fra profilen. Du kan likevel registrere en vurdering.'}</p>
                )}
                <dl className="mt-3 grid min-w-0 gap-3 sm:grid-cols-2" data-testid="due-diligence-mapping">
                    {facts.map((fact) => (
                        <div key={fact.key} className="min-w-0">
                            <dt className={TERM}>{fact.label}</dt>
                            <dd className={`break-words text-base ${fact.uncertain ? 'text-amber-800' : 'text-slate-900'}`}>{fact.text}</dd>
                        </div>
                    ))}
                </dl>
                {data.mapping?.profile_empty && <p className={`mt-2 ${HINT}`}>{d.profile_empty ?? 'Leverandørprofilen er ikke fylt ut. Ubesvarte forhold er ikke vurdert som lav risiko.'}</p>}
            </div>

            <div className="mt-4" data-testid="due-diligence-current">
                <h3 className="text-lg font-semibold text-slate-950">{d.current_heading ?? 'Siste vurdering'}</h3>
                {current ? (
                    <>
                        <div className="mt-1 flex flex-wrap items-center gap-2">
                            <span data-testid="due-diligence-conclusion"><StatusBadge tone={DUE_DILIGENCE_CONCLUSION_TONES[current.conclusion] ?? 'slate'}>{conclusionLabel(current.conclusion, tr)}</StatusBadge></span>
                            <span className="text-base text-slate-600">{dueDiligenceByline(current, tr, formatDate)}</span>
                        </div>
                        {next && <p className={`mt-1 text-base ${data.overdue ? 'font-semibold text-amber-800' : 'text-slate-800'}`} data-testid="due-diligence-next">{next}</p>}
                        <AreaList areas={current.areas} tr={tr} testId="due-diligence-current-areas" />
                        <p className="mt-2 whitespace-pre-line break-words text-base text-slate-900">{d.rationale_label ?? 'Begrunnelse'}: {current.rationale}</p>
                    </>
                ) : (
                    <p className="mt-1 text-base text-slate-800" data-testid="due-diligence-none">{d.none ?? 'Ingen aktsomhetsvurdering er registrert.'}</p>
                )}
            </div>

            {related.length > 0 && (
                <div className="mt-4" data-testid="due-diligence-requirements">
                    <h3 className="text-lg font-semibold text-slate-950">{d.requirements_heading ?? 'Kontrollkrav om menneskerettigheter, arbeidsforhold og miljø'}</h3>
                    <ul className="mt-2 space-y-2">
                        {related.map((row) => (
                            <li key={row.id} className="flex min-w-0 flex-wrap items-center justify-between gap-2">
                                <a href={`#control-requirement-${row.id}`} className="min-w-0 break-words text-base font-semibold text-violet-700 hover:text-violet-900">{row.title}</a>
                                <StatusBadge tone={DISPLAY_STATUS_TONES[row.display_status] ?? 'slate'}>{displayStatusLabel(row.display_status, tr)}</StatusBadge>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {canAct && (
                <div className="mt-4 flex flex-wrap gap-2">
                    <button type="button" onClick={() => setAssessing(true)} className={PRIMARY_ACTION}>{d.register ?? 'Registrer aktsomhetsvurdering'}</button>
                    {current?.conclusion === 'measures_required' && onFollowUp && (
                        <button type="button" onClick={() => onFollowUp(current)} className={SECONDARY_ACTION} data-testid="due-diligence-follow-up">{d.follow_up ?? 'Følg opp i Avvik og forbedringer'}</button>
                    )}
                    {current && onCreateRisk && (
                        <button type="button" onClick={() => onCreateRisk(current)} className={SECONDARY_ACTION} data-testid="due-diligence-create-risk">{d.create_risk ?? 'Opprett risiko'}</button>
                    )}
                </div>
            )}
            {assessing && <DueDiligenceForm supplierId={supplierId} options={data.options ?? {}} onDone={() => setAssessing(false)} tr={tr} />}
            {data.permissions?.ended && <p className={`mt-4 ${HINT}`}>{d.ended ?? 'Leverandøren er avsluttet. Gjenåpne den for å registrere en ny aktsomhetsvurdering.'}</p>}

            <details className="mt-5 min-w-0" data-testid="due-diligence-process">
                <summary className={`${DISCLOSURE_INLINE} cursor-pointer`}>{d.process_heading ?? 'Slik følges aktsomhet opp'}</summary>
                <ol className="mt-3 list-decimal space-y-2 pl-6">
                    {DUE_DILIGENCE_STEPS.map((step) => (
                        <li key={step} className="break-words text-base text-slate-800" data-step={step}>
                            <span className="font-semibold text-slate-900">{d.steps?.[step]?.title ?? step}</span>
                            {d.steps?.[step]?.text ? ` – ${d.steps[step].text}` : ''}
                        </li>
                    ))}
                </ol>
            </details>

            {(data.history ?? []).length > 0 && (
                <details className="mt-4 min-w-0" data-testid="due-diligence-history">
                    <summary className={`${DISCLOSURE_INLINE} cursor-pointer`}>{(d.history_heading ?? 'Tidligere aktsomhetsvurderinger (:count)').replace(':count', String(data.history.length))}</summary>
                    <ol className="mt-3 space-y-4">
                        {data.history.map((entry) => <HistoryEntry key={entry.id} entry={entry} formatDate={formatDate} tr={tr} />)}
                    </ol>
                </details>
            )}
        </section>
    );
}

