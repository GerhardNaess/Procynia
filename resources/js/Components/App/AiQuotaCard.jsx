import StatusBadge from './StatusBadge';
import {
    allowance,
    barClass,
    compactLabel,
    consumed,
    headline,
    isNotIncluded,
    isSuspended,
    isUnlimited,
    nextResetLabel,
    percentage,
    periodLabel,
    progressAccessibleLabel,
    remaining,
    remainingLabel,
    showsProgressBar,
    statusDescription,
    statusLabel,
    statusTone,
} from '../../Support/aiQuota';

/**
 * The AI-capacity section of the subscription page.
 *
 * Every state is carried by text first — the badge, the bar and the colours only repeat what the
 * words already say, so the card reads the same to someone who cannot distinguish amber from rose.
 */
export default function AiQuotaCard({ quota, texts = {}, locale = 'nb-NO' }) {
    if (!quota) {
        return null;
    }

    const notIncluded = isNotIncluded(quota);
    const unlimited = isUnlimited(quota);
    const suspended = isSuspended(quota);

    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" data-testid="ai-quota-card">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="text-base font-semibold text-slate-900">{texts.heading ?? 'AI-kapasitet'}</h2>
                <StatusBadge tone={statusTone(quota)}>{statusLabel(quota, texts)}</StatusBadge>
            </div>

            <p className="mt-4 text-lg font-semibold text-slate-900" data-testid="ai-quota-headline">
                {headline(quota, texts)}
            </p>

            {!notIncluded && !unlimited && (
                <p className="mt-1 text-base text-slate-600" data-testid="ai-quota-remaining">
                    {remainingLabel(quota, texts)}
                </p>
            )}

            {showsProgressBar(quota) && (
                <div className="mt-4">
                    <div
                        role="progressbar"
                        aria-valuenow={percentage(quota)}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-label={progressAccessibleLabel(quota, texts)}
                        className="h-2 w-full overflow-hidden rounded-full bg-slate-200"
                    >
                        <div className={`h-full rounded-full ${barClass(quota)}`} style={{ width: `${percentage(quota)}%` }} />
                    </div>
                    <p className="mt-2 text-sm text-slate-600">{progressAccessibleLabel(quota, texts)}</p>
                </div>
            )}

            <p className="mt-4 text-base leading-6 text-slate-700" data-testid="ai-quota-status-description">
                {suspended
                    ? statusDescription(quota, texts)
                    : (notIncluded ? (texts.not_included_description ?? '') : statusDescription(quota, texts))}
            </p>

            {!notIncluded && (
                <dl className="mt-5 grid grid-cols-1 gap-x-8 gap-y-3 text-base md:grid-cols-2">
                    {!unlimited && (
                        <>
                            <dt className="text-slate-600">{texts.used_label ?? 'Brukt'}</dt>
                            <dd className="font-medium text-slate-900">{consumed(quota)}</dd>

                            <dt className="text-slate-600">{texts.included_label ?? 'Inkludert'}</dt>
                            <dd className="font-medium text-slate-900">{quota.included ?? 0}</dd>

                            {Number(quota.extra) > 0 && (
                                <>
                                    <dt className="text-slate-600">{texts.extra_label ?? 'Ekstra kapasitet'}</dt>
                                    <dd className="font-medium text-slate-900">{quota.extra}</dd>
                                </>
                            )}

                            <dt className="text-slate-600">{texts.remaining_label ?? 'Gjenstår'}</dt>
                            <dd className="font-medium text-slate-900">{remaining(quota)}</dd>
                        </>
                    )}

                    {unlimited && (
                        <>
                            <dt className="text-slate-600">{texts.included_label ?? 'Inkludert'}</dt>
                            <dd className="font-medium text-slate-900">{texts.unlimited ?? 'Ubegrenset'}</dd>
                        </>
                    )}

                    <dt className="text-slate-600">{texts.period_label ?? 'Periode'}</dt>
                    <dd className="font-medium text-slate-900">{periodLabel(quota, texts, locale)}</dd>

                    {!unlimited && (
                        <>
                            <dt className="text-slate-600">{texts.next_reset_label ?? 'Ny kapasitet'}</dt>
                            <dd className="font-medium text-slate-900">{nextResetLabel(quota, locale)}</dd>
                        </>
                    )}
                </dl>
            )}

            <p className="mt-5 text-sm leading-5 text-slate-500">{texts.credit_explainer ?? ''}</p>
            <span className="sr-only" data-testid="ai-quota-compact">{compactLabel(quota, texts)}</span>
            <span className="sr-only">{allowance(quota)}</span>
        </section>
    );
}
