import { DISCLOSURE_INLINE } from '../../../Support/actionStyles';
import { formatDateTime } from '../Compliance/Requirements/complianceRequirement';
import { statusChangeByline, statusChangeText } from './controlRequirements';

/**
 * Endringshistorikk on one control requirement: every Sett som utgått and Ta i bruk igjen, newest
 * first, with when and by whom — folded away, and apart from the controls, which are the
 * requirement's results, not its lifecycle. Nothing when the requirement has never changed status.
 */
export default function RequirementStatusHistory({ entries = [], locale = 'no', tr }) {
    const h = tr.control?.status_history ?? {};

    if (entries.length === 0) {
        return null;
    }

    return (
        <details className="mt-3 min-w-0" data-testid="requirement-status-history">
            <summary className={`${DISCLOSURE_INLINE} cursor-pointer`}>{h.heading ?? 'Endringshistorikk'} ({entries.length})</summary>
            <ol className="mt-3 space-y-3">
                {entries.map((entry) => (
                    <li key={entry.id} className="min-w-0 border-l-2 border-slate-200 pl-3" data-testid="requirement-status-history-entry">
                        <p className="text-base font-semibold text-slate-900">{statusChangeText(entry, tr)}</p>
                        <p className="break-words text-base text-slate-600">{statusChangeByline(entry, tr, (iso) => formatDateTime(iso, locale))}</p>
                    </li>
                ))}
            </ol>
        </details>
    );
}
