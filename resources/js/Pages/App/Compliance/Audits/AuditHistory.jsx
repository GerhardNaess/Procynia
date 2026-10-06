import StatusBadge from '../../../../Components/App/StatusBadge';
import { formatLongDate } from '../../Improvements/improvementStatus';
import { AUDIT_STATUS_TONES, auditStatusLabel, describeAuditHistoryEntry } from './complianceAudit';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * Statushistorikk: every Start, Fullfør, Avbryt and Gjenåpne, newest first — who, when, the status
 * it led to and the begrunnelse where one was given. Always drawn, so an audit that has not moved
 * yet says so instead of leaving a gap.
 */
export default function AuditHistory({ entries = [], locale, ta }) {
    return (
        <section className={CARD} aria-labelledby="compliance-audit-history-heading">
            <h2 id="compliance-audit-history-heading" className="text-xl font-semibold text-slate-950">
                {ta.history_heading ?? 'Statushistorikk'}
            </h2>
            {entries.length === 0 ? (
                <p className="mt-3 text-base text-slate-700">{ta.history_empty ?? 'Ingen statusendringer ennå.'}</p>
            ) : (
                <ol className="mt-4 divide-y divide-slate-100" data-testid="compliance-audit-history">
                    {entries.map((entry) => (
                        <li key={entry.id} className="space-y-1 py-3">
                            <p className="text-base text-slate-600">{formatLongDate(entry.changed_at, locale)}</p>
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="text-base font-semibold text-slate-900">{describeAuditHistoryEntry(entry, ta)}</p>
                                <StatusBadge tone={AUDIT_STATUS_TONES[entry.to_status] ?? 'slate'}>{auditStatusLabel(entry.to_status, ta)}</StatusBadge>
                            </div>
                            {entry.reason && <p className="whitespace-pre-line break-words text-base text-slate-700">{entry.reason}</p>}
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}
