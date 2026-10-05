import { formatLongDate } from './objectiveStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * Historikk: every closing and reopening, newest first, as they were made. Nothing is shown before
 * the first change — an objective that has only ever been active has no history to tell.
 *
 * A KPI's retirements and reopenings use the same list; it passes its own describe(entry, who).
 */
export default function ObjectiveHistory({ entries = [], statusLabels = {}, locale, tr, describe = null, testId = 'objective-history' }) {
    if (entries.length === 0) {
        return null;
    }

    const who = (entry) => entry.changed_by_name ?? (tr.unknown_user ?? 'en tidligere bruker');

    return (
        <section className={CARD} aria-labelledby={`${testId}-heading`}>
            <h2 id={`${testId}-heading`} className="text-lg font-semibold text-slate-950">
                {tr.history_heading ?? 'Historikk'}
            </h2>
            <ol className="mt-4 divide-y divide-slate-100" data-testid={testId}>
                {entries.map((entry) => (
                    <li key={entry.id} className="py-3">
                        <p className="text-sm text-slate-500">{formatLongDate(entry.changed_at, locale)}</p>
                        <p className="mt-0.5 text-base font-semibold text-slate-900">
                            {describe ? describe(entry, who(entry)) : entry.to_status === 'active'
                                ? (tr.history_reopened ?? 'Gjenåpnet av :name').replace(':name', who(entry))
                                : (tr.history_closed ?? 'Lukket som :status av :name')
                                    .replace(':status', statusLabels[entry.to_status] ?? entry.to_status)
                                    .replace(':name', who(entry))}
                        </p>
                        {entry.note && <p className="mt-1 whitespace-pre-line text-base text-slate-700">{entry.note}</p>}
                    </li>
                ))}
            </ol>
        </section>
    );
}
