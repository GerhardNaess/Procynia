import StatusBadge from '../../../Components/App/StatusBadge';
import { CRITICALITY_TONES, criticalityLabel } from './supplierManagement';

/** Standard, Viktig or Kritisk as a badge — or «Ikke vurdert» as plain text when there is none yet. */
export default function SupplierCriticalityBadge({ level, tr }) {
    if (! level) {
        return <span className="text-base text-slate-600">{tr.not_classified ?? 'Ikke vurdert'}</span>;
    }

    return <StatusBadge tone={CRITICALITY_TONES[level] ?? 'slate'}>{criticalityLabel(level, tr)}</StatusBadge>;
}
