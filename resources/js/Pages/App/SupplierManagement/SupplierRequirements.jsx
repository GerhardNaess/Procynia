import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import RequiredMark from '../Risk/RequiredMark';
import { filterRequirementOptions, requirementOptionLabel } from './supplierManagement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-900';
const HINT = 'mt-1 text-base text-slate-600';
const ERROR = 'mt-1 text-base text-rose-700';
const TERM = 'text-base font-semibold text-slate-600';

/** Legg til krav: an active requirement in Etterlevelse og revisjon the person can read, not yet listed here. */
function AddForm({ supplierId, options, onDone, tr }) {
    const r = tr.requirements ?? {};
    const form = useForm({ requirement_id: '' });
    const [search, setSearch] = useState('');
    const matches = filterRequirementOptions(options, search);

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/supplier-management/${supplierId}/requirements`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="mt-4 space-y-4 rounded-2xl border border-slate-200 p-4" data-testid="requirement-add-form">
            <div>
                <h3 className="text-lg font-semibold text-slate-950">{r.add_heading ?? 'Legg til krav'}</h3>
                <p className={HINT}>{r.add_intro ?? 'Velg et krav som allerede er registrert i Etterlevelse og revisjon. Nye krav registreres der.'}</p>
            </div>
            {options.length === 0 ? (
                <p className="text-base text-slate-800">{r.no_options ?? 'Det finnes ingen andre aktive krav du kan legge til.'}</p>
            ) : (
                <>
                    <div>
                        <label htmlFor="supplier-requirement-search" className={LABEL}>{r.search ?? 'Søk etter krav'}</label>
                        <p id="supplier-requirement-search-hint" className={HINT}>{r.search_hint ?? 'Søk på referanse, tittel eller kravkilde.'}</p>
                        <input id="supplier-requirement-search" type="search" aria-describedby="supplier-requirement-search-hint" value={search} onChange={(event) => setSearch(event.target.value)} className={`mt-1 ${INPUT}`} />
                    </div>
                    <div>
                        <label htmlFor="supplier-requirement-link" className={LABEL}>{r.requirement ?? 'Krav'}<RequiredMark /></label>
                        <select id="supplier-requirement-link" required aria-required="true" value={form.data.requirement_id} onChange={(event) => form.setData('requirement_id', event.target.value)} className={`mt-1 ${INPUT}`}>
                            <option value="">{matches.length === 0 ? (r.no_matches ?? 'Ingen krav passer søket.') : (r.choose_requirement ?? 'Velg krav')}</option>
                            {matches.map((option) => <option key={option.id} value={option.id}>{requirementOptionLabel(option)}</option>)}
                        </select>
                        {form.errors.requirement_id && <p className={ERROR}>{form.errors.requirement_id}</p>}
                    </div>
                </>
            )}
            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                {options.length > 0 && (
                    <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>{r.submit ?? 'Legg til'}</button>
                )}
            </div>
        </form>
    );
}

/**
 * Krav som gjelder leverandøren: the requirements in Etterlevelse og revisjon that apply to the
 * supplier and that the person can read — reference, title and kravkilde, with a link there. A
 * requirement the person cannot read is not listed at all. Not rendered when the server sent null:
 * the person cannot read Etterlevelse og revisjon, so nothing is said about it.
 *
 * Never a compliance status: that a requirement applies to the supplier does not say whether the
 * supplier meets it. Only the requirement's own lifecycle is shown — a retired one is toned down.
 */
export default function SupplierRequirements({ supplier, requirements, linking, hasEditRight, tr }) {
    const r = tr.requirements ?? {};
    const [adding, setAdding] = useState(false);

    if (requirements === null || requirements === undefined) {
        return null;
    }

    const remove = (entry) => {
        if (! window.confirm(r.remove_confirm ?? 'Fjerne kravet fra leverandøren? Kravet blir værende i Etterlevelse og revisjon.')) {
            return;
        }

        router.delete(`/app/supplier-management/${supplier.id}/requirements/${entry.link_id}`, { preserveScroll: true });
    };

    return (
        <section className={CARD} aria-labelledby="supplier-requirements-heading" data-testid="supplier-requirements">
            <h2 id="supplier-requirements-heading" className="text-xl font-semibold text-slate-950">{r.heading ?? 'Krav som gjelder leverandøren'}</h2>
            <p className="mt-1 text-base text-slate-600">{r.intro ?? 'Krav i Etterlevelse og revisjon som gjelder leverandøren. Kravet håndteres videre der – vurdering av etterlevelse og revisjon følges opp i Etterlevelse og revisjon.'}</p>
            <p className="mt-1 text-base text-slate-600">{r.not_status ?? 'At et krav gjelder leverandøren, sier ikke om leverandøren oppfyller det. Det vurderer du i leverandørvurderingen.'}</p>

            {requirements.length === 0 ? (
                <p className="mt-4 text-base text-slate-800" data-testid="requirements-none">{r.none ?? 'Ingen krav i Etterlevelse og revisjon er lagt til for leverandøren.'}</p>
            ) : (
                <ul className="mt-4 space-y-3" data-testid="requirements-list">
                    {requirements.map((entry) => (
                        <li key={entry.id} className={`rounded-xl border border-slate-200 px-4 py-3 ${entry.retired ? 'bg-slate-50' : ''}`} data-testid="requirement-entry">
                            <Link href={entry.url} className={`min-w-0 break-words text-lg font-semibold ${entry.retired ? 'text-slate-600 hover:text-slate-800' : 'text-violet-700 hover:text-violet-900'}`}>{entry.title}</Link>
                            {entry.retired && <p className="mt-1 text-base text-slate-600">{r.retired ?? 'Utgått i Etterlevelse og revisjon'}</p>}
                            <dl className="mt-2 grid gap-2 sm:grid-cols-2">
                                {entry.reference && (
                                    <div className="min-w-0">
                                        <dt className={TERM}>{r.col_reference ?? 'Referanse'}</dt>
                                        <dd className="break-words text-base text-slate-900">{entry.reference}</dd>
                                    </div>
                                )}
                                <div className="min-w-0">
                                    <dt className={TERM}>{r.col_source ?? 'Kravkilde'}</dt>
                                    <dd className="break-words text-base text-slate-900">{entry.source_label}</dd>
                                </div>
                            </dl>
                            {linking && ! adding && (
                                <button type="button" onClick={() => remove(entry)} className={`mt-3 ${DESTRUCTIVE_ACTION}`}>{r.remove ?? 'Fjern krav'}</button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {linking && ! adding && (
                <div className="mt-4 flex flex-wrap gap-2">
                    <button type="button" onClick={() => setAdding(true)} className={SECONDARY_ACTION}>{r.add ?? 'Legg til krav'}</button>
                </div>
            )}
            {! linking && hasEditRight && supplier.status === 'ended' && (
                <p className="mt-4 text-base text-slate-600" data-testid="requirements-read-only">{r.reopen_to_follow_up ?? 'Leverandøren er avsluttet. Gjenåpne den for å legge til eller fjerne krav.'}</p>
            )}

            {linking && adding && (
                <AddForm supplierId={supplier.id} options={linking.link_options ?? []} onDone={() => setAdding(false)} tr={tr} />
            )}
        </section>
    );
}
