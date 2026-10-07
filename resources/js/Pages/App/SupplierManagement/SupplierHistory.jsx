import { formatLongDate } from '../Improvements/improvementStatus';
import { describeHistoryEntry, describeRegistration } from './supplierManagement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * Historikk: every Ta i bruk, Avslutt and Gjenåpne, newest first, with its begrunnelse — and, last,
 * the registration itself, so the list always says where the supplier started.
 */
export default function SupplierHistory({ entries = [], registered, locale, tr }) {
    return (
        <section className={CARD} aria-labelledby="supplier-history-heading">
            <h2 id="supplier-history-heading" className="text-xl font-semibold text-slate-950">
                {tr.history_heading ?? 'Historikk'}
            </h2>
            <ol className="mt-4 divide-y divide-slate-100" data-testid="supplier-history">
                {entries.map((entry) => (
                    <li key={entry.id} className="py-3" data-testid="supplier-history-entry">
                        <p className="text-base text-slate-600">{formatLongDate(entry.changed_at, locale)}</p>
                        <p className="mt-0.5 text-base font-semibold text-slate-900">{describeHistoryEntry(entry, tr)}</p>
                        {entry.reason && <p className="mt-1 whitespace-pre-line break-words text-base text-slate-700">{entry.reason}</p>}
                    </li>
                ))}
                {registered && (
                    <li className="py-3">
                        <p className="text-base text-slate-600">{formatLongDate(registered.at, locale)}</p>
                        <p className="mt-0.5 text-base font-semibold text-slate-900">{describeRegistration(registered, tr)}</p>
                    </li>
                )}
            </ol>
        </section>
    );
}
