import { describeHistoryEntry, formatLongDate } from './improvementStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * Historikk: every status change, newest first, as it was made. Nothing is shown before the first
 * change — a case that has only ever been open has no history to tell.
 */
export default function ImprovementHistory({ entries = [], locale, tr }) {
    if (entries.length === 0) {
        return null;
    }

    return (
        <section className={CARD} aria-labelledby="improvement-history-heading">
            <h2 id="improvement-history-heading" className="text-xl font-semibold text-slate-950">
                {tr.history_heading ?? 'Historikk'}
            </h2>
            <ol className="mt-4 divide-y divide-slate-100" data-testid="improvement-history">
                {entries.map((entry) => (
                    <li key={entry.id} className="py-3">
                        <p className="text-base text-slate-600">{formatLongDate(entry.changed_at, locale)}</p>
                        <p className="mt-0.5 text-base font-semibold text-slate-900">{describeHistoryEntry(entry, tr)}</p>
                        {entry.note && <p className="mt-1 whitespace-pre-line break-words text-base text-slate-700">{entry.note}</p>}
                    </li>
                ))}
            </ol>
        </section>
    );
}
