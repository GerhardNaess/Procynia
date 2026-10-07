import { formatLongDate } from '../../Improvements/improvementStatus';
import { describeHistoryEntry } from './complianceRequirement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * Statushistorikk: every Sett som utgått and Gjenåpne, newest first, with its begrunnelse. Nothing
 * is shown before the first change — a requirement that has only ever been active has no history.
 */
export default function RequirementHistory({ entries = [], locale, tr }) {
    if (entries.length === 0) {
        return null;
    }

    return (
        <section className={CARD} aria-labelledby="compliance-history-heading">
            <h2 id="compliance-history-heading" className="text-xl font-semibold text-slate-950">
                {tr.history_heading ?? 'Statushistorikk'}
            </h2>
            <ol className="mt-4 divide-y divide-slate-100" data-testid="compliance-history">
                {entries.map((entry) => (
                    <li key={entry.id} className="py-3">
                        <p className="text-base text-slate-600">{formatLongDate(entry.changed_at, locale)}</p>
                        <p className="mt-0.5 text-base font-semibold text-slate-900">{describeHistoryEntry(entry, tr)}</p>
                        <p className="mt-1 whitespace-pre-line break-words text-base text-slate-700">{entry.note}</p>
                    </li>
                ))}
            </ol>
        </section>
    );
}
