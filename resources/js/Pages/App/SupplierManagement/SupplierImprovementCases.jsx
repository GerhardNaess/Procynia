import { useEffect, useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { IMPROVEMENT_STATUS_TONES, formatLongDate } from '../Improvements/improvementStatus';
import RequiredMark from '../Risk/RequiredMark';
import { ownersForArea } from '../Risk/riskOwners';
import {
    caseHandoffPrefill,
    caseOriginText,
    caseStatusLabel,
    caseTypeLabel,
    newHandoffKey,
} from './supplierManagement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-900';
const HINT = 'mt-1 text-base text-slate-600';
const ERROR = 'mt-1 text-base text-rose-700';
const TERM = 'text-base font-semibold text-slate-600';

/**
 * «Følg opp i Avvik og forbedringer»: a new case there, from the supplier or from one of its
 * assessments. Title and description start as a visible suggestion; the person chooses the type, the
 * fagområde, the ansvarlig and the frist. Nothing is created before they press «Opprett sak».
 */
function HandoffForm({ supplier, assessment, evaluation, dueDiligence, handoff, onDone, locale, tr, ti }) {
    const c = tr.cases ?? {};
    const ref = useRef(null);
    const prefill = caseHandoffPrefill(supplier, assessment, tr, (iso) => formatLongDate(iso, locale), evaluation, dueDiligence);
    const form = useForm({
        type: '',
        title: prefill.title,
        description: prefill.description,
        business_area_id: '',
        owner_user_id: '',
        due_date: '',
        supplier_assessment_id: assessment?.id ?? null,
        supplier_requirement_evaluation_id: evaluation?.id ?? null,
        supplier_due_diligence_assessment_id: dueDiligence?.id ?? null,
        handoff_key: newHandoffKey(),
    });
    const owners = ownersForArea(handoff.owner_options ?? [], form.data.business_area_id);

    useEffect(() => {
        ref.current?.scrollIntoView?.({ block: 'start' });
    }, []);

    const chooseArea = (value) => {
        const stillAllowed = ownersForArea(handoff.owner_options ?? [], value).some((owner) => String(owner.id) === String(form.data.owner_user_id));
        form.setData((data) => ({ ...data, business_area_id: value, owner_user_id: stillAllowed ? data.owner_user_id : '' }));
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/supplier-management/${supplier.id}/improvement-cases`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form ref={ref} onSubmit={submit} className="mt-4 space-y-4 rounded-2xl border border-violet-200 bg-violet-50/40 p-4" data-testid="case-handoff-form">
            <div>
                <h3 className="text-lg font-semibold text-slate-950">{c.form_heading ?? 'Følg opp i Avvik og forbedringer'}</h3>
                <p className={HINT}>{c.form_intro ?? 'Det opprettes en ny sak i Avvik og forbedringer. Der følges saken opp med tiltak, status og lukking. Leverandøren viser bare at saken gjelder den.'}</p>
            </div>

            <fieldset>
                <legend className={LABEL}>{c.type ?? 'Sakstype'}<RequiredMark /></legend>
                <p className={HINT}>{c.type_hint ?? 'Avvik når noe ikke er som avtalt eller påkrevd; forbedring når noe kan bli bedre.'}</p>
                <div className="mt-2 flex flex-wrap gap-x-6 gap-y-2" data-testid="case-handoff-type">
                    {(handoff.types ?? []).map((type) => (
                        <label key={type} className="flex min-h-10 items-center gap-2 text-base text-slate-900">
                            <input type="radio" name="case-handoff-type" checked={form.data.type === type} onChange={() => form.setData('type', type)} className="h-5 w-5 shrink-0" />
                            {caseTypeLabel(type, ti)}
                        </label>
                    ))}
                </div>
                {form.errors.type && <p className={ERROR}>{form.errors.type}</p>}
            </fieldset>

            <p className="text-base text-slate-600">{c.prefill_hint ?? 'Tittel og beskrivelse er et forslag. Endre dem gjerne før du oppretter saken.'}</p>

            <div>
                <label htmlFor="case-handoff-title" className={LABEL}>{c.title ?? 'Tittel på saken'}<RequiredMark /></label>
                <input id="case-handoff-title" type="text" required aria-required="true" maxLength={255} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} className={`mt-1 ${INPUT}`} />
                {form.errors.title && <p className={ERROR}>{form.errors.title}</p>}
            </div>

            <div>
                <label htmlFor="case-handoff-description" className={LABEL}>{c.description ?? 'Beskrivelse av saken'}<RequiredMark /></label>
                <textarea id="case-handoff-description" rows={5} required aria-required="true" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} className={`mt-1 ${INPUT}`} />
                {form.errors.description && <p className={ERROR}>{form.errors.description}</p>}
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <label htmlFor="case-handoff-area" className={LABEL}>{c.area ?? 'Fagområde'}<RequiredMark /></label>
                    <p id="case-handoff-area-hint" className={HINT}>{c.area_hint ?? 'Fagområdet bestemmer hvem som kan se saken i Avvik og forbedringer.'}</p>
                    <select id="case-handoff-area" required aria-required="true" aria-describedby="case-handoff-area-hint" value={form.data.business_area_id} onChange={(event) => chooseArea(event.target.value)} className={`mt-1 ${INPUT}`}>
                        <option value="">{c.choose_area ?? 'Velg fagområde'}</option>
                        {handoff.area_options.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}
                    </select>
                    {form.errors.business_area_id && <p className={ERROR}>{form.errors.business_area_id}</p>}
                </div>
                <div>
                    <label htmlFor="case-handoff-owner" className={LABEL}>{c.owner ?? 'Ansvarlig'}<RequiredMark /></label>
                    <p id="case-handoff-owner-hint" className={HINT}>{c.owner_hint ?? 'Bare personer som kan se saker i valgt fagområde, kan velges.'}</p>
                    <select id="case-handoff-owner" required aria-required="true" aria-describedby="case-handoff-owner-hint" disabled={form.data.business_area_id === ''} value={form.data.owner_user_id} onChange={(event) => form.setData('owner_user_id', event.target.value)} className={`mt-1 ${INPUT}`}>
                        <option value="">{form.data.business_area_id === '' ? (c.choose_area_first ?? 'Velg fagområde først') : (c.choose_owner ?? 'Velg ansvarlig')}</option>
                        {owners.map((owner) => <option key={owner.id} value={owner.id}>{owner.name}</option>)}
                    </select>
                    {form.errors.owner_user_id && <p className={ERROR}>{form.errors.owner_user_id}</p>}
                </div>
            </div>

            <div className="sm:max-w-xs">
                <label htmlFor="case-handoff-due" className={LABEL}>{c.due ?? 'Frist'}</label>
                <p id="case-handoff-due-hint" className={HINT}>{c.due_hint ?? 'Valgfritt. Når saken bør være behandlet.'}</p>
                <input id="case-handoff-due" type="date" aria-describedby="case-handoff-due-hint" value={form.data.due_date} onChange={(event) => form.setData('due_date', event.target.value)} className={`mt-1 ${INPUT}`} />
                {form.errors.due_date && <p className={ERROR}>{form.errors.due_date}</p>}
            </div>

            {form.errors.supplier_assessment_id && <p className={ERROR}>{form.errors.supplier_assessment_id}</p>}
            {form.errors.supplier_requirement_evaluation_id && <p className={ERROR}>{form.errors.supplier_requirement_evaluation_id}</p>}
            {form.errors.supplier_due_diligence_assessment_id && <p className={ERROR}>{form.errors.supplier_due_diligence_assessment_id}</p>}

            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (c.submit ?? 'Opprett sak')}
                </button>
            </div>
        </form>
    );
}

/** Koble til eksisterende sak: one of the person's own visible cases not yet listed here. */
function LinkForm({ supplierId, options, onDone, tr, ti }) {
    const c = tr.cases ?? {};
    const form = useForm({ improvement_case_id: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/supplier-management/${supplierId}/improvement-cases/link`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="mt-4 space-y-4 rounded-2xl border border-slate-200 p-4" data-testid="case-link-form">
            <div>
                <h3 className="text-lg font-semibold text-slate-950">{c.link_heading ?? 'Koble til eksisterende sak'}</h3>
                <p className={HINT}>{c.link_intro ?? 'For en sak som allerede er registrert i Avvik og forbedringer.'}</p>
            </div>
            {options.length === 0 ? (
                <p className="text-base text-slate-800">{c.no_link_options ?? 'Det finnes ingen andre saker du kan koble til.'}</p>
            ) : (
                <div>
                    <label htmlFor="case-link-case" className={LABEL}>{c.link_case ?? 'Sak'}<RequiredMark /></label>
                    <select id="case-link-case" required aria-required="true" value={form.data.improvement_case_id} onChange={(event) => form.setData('improvement_case_id', event.target.value)} className={`mt-1 ${INPUT}`}>
                        <option value="">{c.choose_case ?? 'Velg sak'}</option>
                        {options.map((option) => (
                            <option key={option.id} value={option.id}>{`${option.title} (${caseTypeLabel(option.type, ti)}, ${caseStatusLabel(option.status, ti)})`}</option>
                        ))}
                    </select>
                    {form.errors.improvement_case_id && <p className={ERROR}>{form.errors.improvement_case_id}</p>}
                </div>
            )}
            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                {options.length > 0 && (
                    <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>{c.link_submit ?? 'Koble til'}</button>
                )}
            </div>
        </form>
    );
}

/**
 * Avvik og forbedringer hos leverandøren: the cases in that module that concern the supplier and
 * that the person can read — type, title, status and frist as Avvik og forbedringer has them now,
 * with a link there. A case the person cannot read is not listed at all. Not rendered when the
 * server sent null: the person cannot read Avvik og forbedringer, so nothing is said about it.
 *
 * The case is worked in Avvik og forbedringer; this page only starts it (`followUp` — from the
 * supplier, the assessment Vurderinger asked to follow up, or the control Krav og kvalifikasjoner
 * asked to follow up, or an aktsomhetsvurdering concluding «Tiltak kreves») and shows where it
 * stands. Starting from the supplier and linking are supplier.edit (`handoff.can_from_supplier`);
 * from a control or an aktsomhetsvurdering it is supplier.assure.
 */
export default function SupplierImprovementCases({ supplier, cases, handoff, followUp, setFollowUp, hasEditRight, locale, tr, ti }) {
    const c = tr.cases ?? {};
    const [linking, setLinking] = useState(false);

    if (cases === null || cases === undefined) {
        return null;
    }

    const canHandOff = Boolean(handoff) && (handoff.area_options ?? []).length > 0;
    const fromSupplier = Boolean(handoff?.can_from_supplier);
    const idle = followUp === null && ! linking;
    const date = (iso) => formatLongDate(iso, locale);

    const unlink = (entry) => {
        if (! window.confirm(c.unlink_confirm ?? 'Fjerne koblingen mellom saken og leverandøren? Saken blir værende i Avvik og forbedringer.')) {
            return;
        }

        router.delete(`/app/supplier-management/${supplier.id}/improvement-cases/${entry.link_id}`, { preserveScroll: true });
    };

    return (
        <section className={CARD} aria-labelledby="supplier-cases-heading" data-testid="supplier-cases">
            <h2 id="supplier-cases-heading" className="text-xl font-semibold text-slate-950">{c.heading ?? 'Avvik og forbedringer hos leverandøren'}</h2>
            <p className="mt-1 text-base text-slate-600">{c.intro ?? 'Saker i Avvik og forbedringer som gjelder leverandøren. Saken behandles der – status, tiltak og lukking følges opp i Avvik og forbedringer.'}</p>

            {cases.length === 0 ? (
                <p className="mt-4 text-base text-slate-800" data-testid="cases-none">{c.none ?? 'Ingen saker i Avvik og forbedringer gjelder leverandøren.'}</p>
            ) : (
                <ul className="mt-4 space-y-3" data-testid="cases-list">
                    {cases.map((entry) => (
                        <li key={entry.id} className="rounded-xl border border-slate-200 px-4 py-3" data-testid="case-entry">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="text-base font-semibold text-slate-600">{caseTypeLabel(entry.type, ti)}</p>
                                    <Link href={entry.url} className="break-words text-lg font-semibold text-violet-700 hover:text-violet-900">{entry.title}</Link>
                                </div>
                                <StatusBadge tone={IMPROVEMENT_STATUS_TONES[entry.status] ?? 'slate'}>{caseStatusLabel(entry.status, ti)}</StatusBadge>
                            </div>
                            <dl className="mt-2 grid gap-2 sm:grid-cols-2">
                                <div className="min-w-0">
                                    <dt className={TERM}>{c.col_due ?? 'Frist'}</dt>
                                    <dd className="text-base text-slate-900">{entry.due_date ? date(entry.due_date) : (c.no_due ?? 'Ingen frist')}</dd>
                                </div>
                            </dl>
                            <p className="mt-2 text-base text-slate-600">{caseOriginText(entry, tr, date)}</p>
                            {fromSupplier && idle && entry.origin === 'linked' && (
                                <button type="button" onClick={() => unlink(entry)} className={`mt-3 ${DESTRUCTIVE_ACTION}`}>{c.unlink ?? 'Fjern koblingen'}</button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {fromSupplier && idle && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {canHandOff && (
                        <button type="button" onClick={() => setFollowUp({ assessment: null })} className={PRIMARY_ACTION}>{c.follow_up ?? 'Følg opp i Avvik og forbedringer'}</button>
                    )}
                    <button type="button" onClick={() => setLinking(true)} className={SECONDARY_ACTION}>{c.link ?? 'Koble til eksisterende sak'}</button>
                </div>
            )}
            {fromSupplier && ! canHandOff && idle && (
                <p className="mt-3 text-base text-slate-600" data-testid="cases-no-areas">{c.no_areas ?? 'Du har ikke tilgang til å opprette saker i Avvik og forbedringer.'}</p>
            )}
            {! handoff && hasEditRight && supplier.status === 'ended' && (
                <p className="mt-4 text-base text-slate-600" data-testid="cases-read-only">{c.reopen_to_follow_up ?? 'Leverandøren er avsluttet. Gjenåpne den for å opprette eller koble saker.'}</p>
            )}

            {canHandOff && followUp !== null && (
                <HandoffForm
                    key={followUp.dueDiligence ? `due-diligence-${followUp.dueDiligence.id}` : followUp.evaluation ? `evaluation-${followUp.evaluation.id}` : (followUp.assessment?.id ?? 'supplier')}
                    supplier={supplier}
                    assessment={followUp.assessment ?? null}
                    evaluation={followUp.evaluation ?? null}
                    dueDiligence={followUp.dueDiligence ?? null}
                    handoff={handoff}
                    onDone={() => setFollowUp(null)}
                    locale={locale}
                    tr={tr}
                    ti={ti}
                />
            )}
            {handoff && linking && (
                <LinkForm supplierId={supplier.id} options={handoff.link_options ?? []} onDone={() => setLinking(false)} tr={tr} ti={ti} />
            )}
        </section>
    );
}
