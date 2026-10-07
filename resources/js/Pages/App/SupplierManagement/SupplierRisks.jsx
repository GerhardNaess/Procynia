import { useEffect, useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import RequiredMark from '../Risk/RequiredMark';
import { RISK_LEVEL_TONES } from '../Risk/riskLevel';
import { ownersForArea } from '../Risk/riskOwners';
import { RISK_STATUS_TONES } from '../Risk/riskStatus';
import { riskLevelText, riskOriginText, riskStatusLabel } from './supplierManagement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-900';
const HINT = 'mt-1 text-base text-slate-600';
const ERROR = 'mt-1 text-base text-rose-700';
const TERM = 'text-base font-semibold text-slate-600';

// Årsak → hendelse → konsekvens, in Risiko's own words. Always written by the person, never prefilled.
const DESCRIPTION_PARTS = [
    { field: 'cause', label: 'Årsak', hint: 'Hva kan gjøre hendelsen mulig?' },
    { field: 'event', label: 'Hendelse', hint: 'Hva kan skje?' },
    { field: 'consequence', label: 'Konsekvens', hint: 'Hva kan virksomheten bli påvirket av?' },
];

/**
 * «Opprett risiko»: a new risk in Risiko, with Risiko's own fields. Only the title starts as a
 * suggestion (the supplier's name); fagområde, årsak, hendelse, konsekvens and risikoeier are the
 * person's. Nothing is created before they press «Opprett risiko».
 */
function CreateForm({ supplier, handoff, onDone, tr, trRisk }) {
    const r = tr.risks ?? {};
    const ts = trRisk.structured ?? {};
    const ref = useRef(null);
    const form = useForm({
        title: (r.prefill_title ?? 'Leverandør: :name').replace(':name', supplier.name),
        cause: '',
        event: '',
        consequence: '',
        business_area_id: '',
        owner_user_id: '',
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
        form.post(`/app/supplier-management/${supplier.id}/risks`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form ref={ref} onSubmit={submit} className="mt-4 space-y-4 rounded-2xl border border-violet-200 bg-violet-50/40 p-4" data-testid="risk-create-form">
            <div>
                <h3 className="text-lg font-semibold text-slate-950">{r.form_heading ?? 'Opprett risiko'}</h3>
                <p className={HINT}>{r.form_intro ?? 'Det opprettes en ny risiko i Risiko. Der vurderes og behandles den. Leverandøren viser bare at risikoen gjelder den.'}</p>
            </div>

            <div>
                <label htmlFor="supplier-risk-area" className={LABEL}>{trRisk.field_area ?? 'Fagområde'}<RequiredMark /></label>
                <p id="supplier-risk-area-hint" className={HINT}>{r.area_hint ?? 'Fagområdet bestemmer hvem som kan se risikoen i Risiko.'}</p>
                <select id="supplier-risk-area" required aria-required="true" aria-describedby="supplier-risk-area-hint" value={form.data.business_area_id} onChange={(event) => chooseArea(event.target.value)} className={`mt-1 ${INPUT}`}>
                    <option value="">{trRisk.choose_area ?? 'Velg fagområde'}</option>
                    {handoff.area_options.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}
                </select>
                {form.errors.business_area_id && <p className={ERROR}>{form.errors.business_area_id}</p>}
            </div>

            <p className="text-base text-slate-600">{r.prefill_hint ?? 'Tittelen er et forslag. Beskriv selv årsak, hendelse og konsekvens.'}</p>

            <div>
                <label htmlFor="supplier-risk-title" className={LABEL}>{trRisk.field_title ?? 'Tittel'}<RequiredMark /></label>
                <input id="supplier-risk-title" type="text" required aria-required="true" maxLength={255} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} className={`mt-1 ${INPUT}`} />
                {form.errors.title && <p className={ERROR}>{form.errors.title}</p>}
            </div>

            <fieldset className="space-y-4 rounded-2xl border border-slate-200 bg-white p-4">
                <legend className="px-1 text-base font-semibold text-slate-950">{ts.heading ?? 'Risikobeskrivelse'}</legend>
                {DESCRIPTION_PARTS.map(({ field, label, hint }) => (
                    <div key={field}>
                        <label htmlFor={`supplier-risk-${field}`} className={LABEL}>{ts[`field_${field}`] ?? label}<RequiredMark /></label>
                        <p id={`supplier-risk-${field}-hint`} className={HINT}>{ts[`field_${field}_hint`] ?? hint}</p>
                        <textarea id={`supplier-risk-${field}`} rows={2} required aria-required="true" aria-describedby={`supplier-risk-${field}-hint`} maxLength={1000} value={form.data[field]} onChange={(event) => form.setData(field, event.target.value)} className={`mt-1 ${INPUT}`} />
                        {form.errors[field] && <p className={ERROR}>{form.errors[field]}</p>}
                    </div>
                ))}
            </fieldset>

            <div>
                <label htmlFor="supplier-risk-owner" className={LABEL}>{trRisk.field_owner ?? 'Risikoeier'}</label>
                <p id="supplier-risk-owner-hint" className={HINT}>{r.owner_hint ?? 'Valgfritt. Bare personer som kan se risikoer i valgt fagområde, kan velges.'}</p>
                <select id="supplier-risk-owner" aria-describedby="supplier-risk-owner-hint" disabled={form.data.business_area_id === ''} value={form.data.owner_user_id} onChange={(event) => form.setData('owner_user_id', event.target.value)} className={`mt-1 ${INPUT}`}>
                    <option value="">{form.data.business_area_id === '' ? (r.choose_area_first ?? 'Velg fagområde først') : (trRisk.no_owner ?? 'Ingen eier valgt')}</option>
                    {owners.map((owner) => <option key={owner.id} value={owner.id}>{owner.name}</option>)}
                </select>
                {form.errors.owner_user_id && <p className={ERROR}>{form.errors.owner_user_id}</p>}
            </div>

            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (r.submit ?? 'Opprett risiko')}
                </button>
            </div>
        </form>
    );
}

/** Koble til eksisterende risiko: one the person can edit in Risiko, not yet listed here. */
function LinkForm({ supplierId, options, onDone, tr }) {
    const r = tr.risks ?? {};
    const form = useForm({ risk_id: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/supplier-management/${supplierId}/risks/link`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="mt-4 space-y-4 rounded-2xl border border-slate-200 p-4" data-testid="risk-link-form">
            <div>
                <h3 className="text-lg font-semibold text-slate-950">{r.link_heading ?? 'Koble til eksisterende risiko'}</h3>
                <p className={HINT}>{r.link_intro ?? 'For en risiko som allerede er registrert i Risiko. Du kan velge blant risikoer du kan redigere.'}</p>
            </div>
            {options.length === 0 ? (
                <p className="text-base text-slate-800">{r.no_link_options ?? 'Det finnes ingen andre risikoer du kan koble til.'}</p>
            ) : (
                <div>
                    <label htmlFor="supplier-risk-link" className={LABEL}>{r.link_risk ?? 'Risiko'}<RequiredMark /></label>
                    <select id="supplier-risk-link" required aria-required="true" value={form.data.risk_id} onChange={(event) => form.setData('risk_id', event.target.value)} className={`mt-1 ${INPUT}`}>
                        <option value="">{r.choose_risk ?? 'Velg risiko'}</option>
                        {options.map((option) => (
                            <option key={option.id} value={option.id}>{option.area_name ? `${option.title} (${option.area_name})` : option.title}</option>
                        ))}
                    </select>
                    {form.errors.risk_id && <p className={ERROR}>{form.errors.risk_id}</p>}
                </div>
            )}
            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                {options.length > 0 && (
                    <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>{r.link_submit ?? 'Koble til'}</button>
                )}
            </div>
        </form>
    );
}

/**
 * Risikoer som gjelder leverandøren: the risks in Risiko that concern the supplier and that the
 * person can read — title, fagområde, status and level as Risiko has them now, with a link there. A
 * risk the person cannot read is not listed at all. Not rendered when the server sent null: the
 * person cannot read Risiko, so nothing is said about it.
 *
 * The risk is assessed and treated in Risiko; this page only starts it and shows where it stands.
 */
export default function SupplierRisks({ supplier, risks, handoff, hasEditRight, tr, trRisk }) {
    const r = tr.risks ?? {};
    // 'create', 'link' or null — one form at a time.
    const [panel, setPanel] = useState(null);

    if (risks === null || risks === undefined) {
        return null;
    }

    const canCreate = Boolean(handoff) && (handoff.area_options ?? []).length > 0;
    const idle = panel === null;

    const unlink = (entry) => {
        if (! window.confirm(r.unlink_confirm ?? 'Fjerne koblingen mellom risikoen og leverandøren? Risikoen blir værende i Risiko.')) {
            return;
        }

        router.delete(`/app/supplier-management/${supplier.id}/risks/${entry.link_id}`, { preserveScroll: true });
    };

    return (
        <section className={CARD} aria-labelledby="supplier-risks-heading" data-testid="supplier-risks">
            <h2 id="supplier-risks-heading" className="text-xl font-semibold text-slate-950">{r.heading ?? 'Risikoer som gjelder leverandøren'}</h2>
            <p className="mt-1 text-base text-slate-600">{r.intro ?? 'Risikoer i Risiko som gjelder leverandøren. Risikoen vurderes og behandles der – vurdering, tiltak, aksept og revurdering følges opp i Risiko.'}</p>

            {risks.length === 0 ? (
                <p className="mt-4 text-base text-slate-800" data-testid="risks-none">{r.none ?? 'Ingen risikoer i Risiko gjelder leverandøren.'}</p>
            ) : (
                <ul className="mt-4 space-y-3" data-testid="risks-list">
                    {risks.map((entry) => (
                        <li key={entry.id} className="rounded-xl border border-slate-200 px-4 py-3" data-testid="risk-entry">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <Link href={entry.url} className="min-w-0 break-words text-lg font-semibold text-violet-700 hover:text-violet-900">{entry.title}</Link>
                                <StatusBadge tone={RISK_STATUS_TONES[entry.status] ?? 'slate'}>{riskStatusLabel(entry.status, trRisk)}</StatusBadge>
                            </div>
                            <dl className="mt-2 grid gap-2 sm:grid-cols-2">
                                <div className="min-w-0">
                                    <dt className={TERM}>{r.col_area ?? 'Fagområde'}</dt>
                                    <dd className="break-words text-base text-slate-900">{entry.area_name}</dd>
                                </div>
                                <div className="min-w-0">
                                    <dt className={TERM}>{r.col_level ?? 'Risikonivå'}</dt>
                                    <dd className="mt-1">
                                        <StatusBadge tone={RISK_LEVEL_TONES[entry.level?.level] ?? 'slate'}>{riskLevelText(entry.level, trRisk)}</StatusBadge>
                                    </dd>
                                </div>
                            </dl>
                            <p className="mt-2 text-base text-slate-600">{riskOriginText(entry, tr)}</p>
                            {handoff && idle && entry.can_unlink && (
                                <button type="button" onClick={() => unlink(entry)} className={`mt-3 ${DESTRUCTIVE_ACTION}`}>{r.unlink ?? 'Fjern koblingen'}</button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {handoff && idle && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {canCreate && (
                        <button type="button" onClick={() => setPanel('create')} className={PRIMARY_ACTION}>{r.create ?? 'Opprett risiko'}</button>
                    )}
                    <button type="button" onClick={() => setPanel('link')} className={SECONDARY_ACTION}>{r.link ?? 'Koble til eksisterende risiko'}</button>
                </div>
            )}
            {handoff && ! canCreate && idle && (
                <p className="mt-3 text-base text-slate-600" data-testid="risks-no-areas">{r.no_areas ?? 'Du har ikke tilgang til å opprette risikoer i Risiko.'}</p>
            )}
            {! handoff && hasEditRight && supplier.status === 'ended' && (
                <p className="mt-4 text-base text-slate-600" data-testid="risks-read-only">{r.reopen_to_follow_up ?? 'Leverandøren er avsluttet. Gjenåpne den for å opprette eller koble risikoer.'}</p>
            )}

            {canCreate && panel === 'create' && (
                <CreateForm supplier={supplier} handoff={handoff} onDone={() => setPanel(null)} tr={tr} trRisk={trRisk} />
            )}
            {handoff && panel === 'link' && (
                <LinkForm supplierId={supplier.id} options={handoff.link_options ?? []} onDone={() => setPanel(null)} tr={tr} />
            )}
        </section>
    );
}
