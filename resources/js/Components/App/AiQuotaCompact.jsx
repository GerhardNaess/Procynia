import StatusBadge from './StatusBadge';
import { compactLabel, isNotIncluded, isSuspended, isUnlimited, remainingLabel, statusLabel, statusTone } from '../../Support/aiQuota';

/**
 * One line of AI capacity where a bid manager actually starts AI work.
 *
 * Deliberately text and a badge rather than a chart: the workspace question is only "can I start
 * another one", and a second progress bar here would compete with the case's own content.
 */
export default function AiQuotaCompact({ quota, texts = {}, billingUrl = null, className = '' }) {
    if (!quota) {
        return null;
    }

    const showRemaining = !isUnlimited(quota) && !isNotIncluded(quota) && !isSuspended(quota);

    return (
        <div
            className={['flex flex-wrap items-center gap-x-3 gap-y-1 text-base text-slate-700', className].filter(Boolean).join(' ')}
            data-testid="ai-quota-compact"
        >
            <StatusBadge tone={statusTone(quota)}>{statusLabel(quota, texts)}</StatusBadge>
            <span>{compactLabel(quota, texts)}</span>
            {showRemaining && <span className="text-slate-500">{remainingLabel(quota, texts)}</span>}
            {billingUrl && (
                <a href={billingUrl} className="font-medium text-blue-700 underline hover:text-blue-800">
                    {texts.notifications?.action_billing ?? 'Se Abonnement'}
                </a>
            )}
        </div>
    );
}
