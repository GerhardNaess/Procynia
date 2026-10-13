import { Link } from '@inertiajs/react';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const TERM = 'text-base font-semibold text-slate-600';

/**
 * Tidligere koblinger til etterlevelseskrav, under Historikk: the requirements in Etterlevelse og
 * revisjon that were linked to the supplier before that link was retired from the user flow
 * (2026-10-13). Read-only — nothing adds or removes one. Not rendered when there are none, or when
 * the server sent null because the person cannot read Etterlevelse og revisjon; a requirement the
 * person cannot read is not listed at all.
 *
 * Never a compliance status, nor anything suggesting the supplier was controlled against the
 * requirement or meets it. Only the requirement's own lifecycle is shown — a retired one is toned down.
 */
export default function SupplierEarlierComplianceLinks({ links, tr }) {
    const r = tr.requirements ?? {};

    if (! Array.isArray(links) || links.length === 0) {
        return null;
    }

    return (
        <section className={CARD} aria-labelledby="supplier-earlier-compliance-links-heading" data-testid="supplier-earlier-compliance-links">
            <h2 id="supplier-earlier-compliance-links-heading" className="text-xl font-semibold text-slate-950">{r.heading ?? 'Tidligere koblinger til etterlevelseskrav'}</h2>
            <p className="mt-1 text-base text-slate-600">{r.intro ?? 'Krav i Etterlevelse og revisjon som tidligere ble knyttet til leverandøren. Koblingen sier ikke at kravet er kontrollert, dokumentert eller oppfylt. Kontrollkravene til leverandøren står under Krav og kvalifikasjoner.'}</p>

            <ul className="mt-4 space-y-3" data-testid="earlier-compliance-links">
                {links.map((entry) => (
                    <li key={entry.id} className={`rounded-xl border border-slate-200 px-4 py-3 ${entry.retired ? 'bg-slate-50' : ''}`} data-testid="earlier-compliance-link">
                        <Link href={entry.url} className={`min-w-0 break-words text-lg font-semibold ${entry.retired ? 'text-slate-600 hover:text-slate-800' : 'text-violet-700 hover:text-violet-900'}`}>{entry.title}</Link>
                        {entry.retired && <p className="mt-1 text-base text-slate-600">{r.retired ?? 'Utgått i Etterlevelse og revisjon'}</p>}
                        <dl className="mt-2 grid gap-2 sm:grid-cols-2">
                            {entry.reference && (
                                <div className="min-w-0">
                                    <dt className={TERM}>{r.col_reference ?? 'Referanse'}</dt>
                                    <dd className="break-words text-base text-slate-900">{entry.reference}</dd>
                                </div>
                            )}
                            {entry.source_label && (
                                <div className="min-w-0">
                                    <dt className={TERM}>{r.col_source ?? 'Kravkilde'}</dt>
                                    <dd className="break-words text-base text-slate-900">{entry.source_label}</dd>
                                </div>
                            )}
                        </dl>
                    </li>
                ))}
            </ul>
        </section>
    );
}
