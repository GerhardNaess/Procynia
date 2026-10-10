import { Link } from '@inertiajs/react';
import { fill } from './reviewSections';

/**
 * One section's basis, as the server worded it (BasisFormatter): the numbers per group — results in
 * the period, the period before, status now or at finalization — then the lists, then the known
 * limitations of the source data. Nothing here decides what the reader may see; the server already
 * narrowed every number and row.
 */
export default function SectionBasis({ basis, t, attentionKeys = [] }) {
    const ts = t.section ?? {};

    if (! basis) {
        return null;
    }

    return (
        <div className="space-y-6" data-testid="mr-section-basis">
            {basis.empty && (
                <p className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-base text-slate-700" data-testid="mr-section-empty">
                    {t.states?.empty ?? 'Ingen registrerte forhold.'}
                </p>
            )}

            {basis.groups.map((group) => (
                <div key={group.key}>
                    <h4 className="text-base font-semibold text-slate-700">{group.label}</h4>
                    <dl className="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-3" data-testid={`mr-group-${group.key}`}>
                        {group.metrics.map((metric) => {
                            const flagged = attentionKeys.includes(metric.key) && metric.value > 0;

                            return (
                                <div
                                    key={metric.key}
                                    className={`flex items-baseline justify-between gap-3 rounded-2xl border px-4 py-3 ${flagged ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white'}`}
                                    data-metric={metric.key}
                                >
                                    <dt className="min-w-0 break-words text-base text-slate-700">{metric.label}</dt>
                                    <dd className={`shrink-0 text-xl font-semibold ${flagged ? 'text-amber-900' : 'text-slate-950'}`}>{metric.value}</dd>
                                </div>
                            );
                        })}
                    </dl>
                </div>
            ))}

            {basis.lists.filter((list) => list.rows.length > 0).map((list) => (
                <div key={list.key} data-testid={`mr-list-${list.key}`}>
                    <h4 className="text-base font-semibold text-slate-700">{list.label} ({list.total})</h4>
                    <ul className="mt-2 divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white">
                        {list.rows.map((row) => (
                            <li key={`${list.key}-${row.id}`} className="space-y-1 px-4 py-3">
                                {row.url ? (
                                    <Link href={row.url} className="break-words text-base font-semibold text-violet-700 hover:text-violet-900">{row.title}</Link>
                                ) : (
                                    <span className="break-words text-base font-semibold text-slate-900">{row.title}</span>
                                )}
                                <dl className="flex flex-wrap gap-x-5 gap-y-1 text-base">
                                    {list.columns.filter((column) => row.cells?.[column.key]).map((column) => (
                                        <div key={column.key} className="flex min-w-0 gap-1">
                                            <dt className="text-slate-600">{column.label}:</dt>
                                            <dd className="min-w-0 break-words text-slate-900">{row.cells[column.key]}</dd>
                                        </div>
                                    ))}
                                </dl>
                                {row.case && (
                                    <p className="break-words text-base text-slate-700">
                                        {ts.case ?? 'Sak'}:{' '}
                                        {row.case.url ? <Link href={row.case.url} className="font-semibold text-violet-700 hover:text-violet-900">{row.case.title}</Link> : row.case.title}
                                        {' · '}{Object.values(row.case.cells ?? {}).filter(Boolean).join(' · ')}
                                    </p>
                                )}
                                {row.case_hidden && <p className="text-base text-slate-600">{ts.case_hidden ?? 'Oppfølgingen skjer i Avvik og forbedringer, i et fagområde du ikke har tilgang til.'}</p>}
                            </li>
                        ))}
                    </ul>
                    {list.total > list.shown && (
                        <p className="mt-1 text-base text-slate-600">{fill(ts.truncated ?? 'Viser :shown av :total.', { shown: list.shown, total: list.total })}</p>
                    )}
                </div>
            ))}

            {basis.notes.length > 0 && (
                <ul className="space-y-2" data-testid="mr-section-limitations">
                    {basis.notes.map((note) => (
                        <li key={note} className="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-base text-sky-900">{note}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}
