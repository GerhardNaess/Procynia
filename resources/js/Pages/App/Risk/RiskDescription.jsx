const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

const PARTS = [
    { field: 'cause', label: 'Årsak' },
    { field: 'event', label: 'Hendelse' },
    { field: 'consequence', label: 'Konsekvens' },
];

/**
 * Risikobeskrivelse: årsak → hendelse → konsekvens, each as the person wrote it. No sentence is
 * stitched together from them — the parts are written to stand alone. An older risk without them says so plainly instead of falling back to
 * the free text — that text is shown only as «Utfyllende informasjon», never as the description.
 */
export default function RiskDescription({ risk, tr }) {
    const ts = tr.structured ?? {};

    return (
        <section className={CARD}>
            <h2 className="text-lg font-semibold text-slate-950">{ts.heading ?? 'Risikobeskrivelse'}</h2>

            {risk.has_structured_description ? (
                <dl className="mt-4 grid gap-4 md:grid-cols-3">
                    {PARTS.map(({ field, label }) => (
                        <div key={field} className="rounded-2xl bg-slate-50 p-4">
                            <dt className="text-sm font-semibold text-slate-600">{ts[`field_${field}`] ?? label}</dt>
                            <dd className="mt-1 whitespace-pre-line text-base leading-6 text-slate-900">{risk[field]}</dd>
                        </div>
                    ))}
                </dl>
            ) : (
                <div className="mt-3 rounded-2xl border border-dashed border-amber-300 bg-amber-50 p-4">
                    <p className="text-base font-semibold text-amber-900">{ts.missing_title ?? 'Risikobeskrivelsen er ikke strukturert ennå'}</p>
                    <p className="mt-1 text-sm text-amber-900">
                        {ts.missing_hint ?? 'Årsak, hendelse og konsekvens må fylles ut neste gang risikoen redigeres.'}
                    </p>
                </div>
            )}

            {risk.description && (
                <div className="mt-5">
                    <h3 className="text-sm font-semibold text-slate-600">{tr.field_description ?? 'Utfyllende informasjon'}</h3>
                    <p className="mt-1 whitespace-pre-line text-base leading-6 text-slate-700">{risk.description}</p>
                </div>
            )}
        </section>
    );
}
