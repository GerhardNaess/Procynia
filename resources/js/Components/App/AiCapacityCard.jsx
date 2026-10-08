import StatusBadge from './StatusBadge';
import {
    barClass,
    exhaustedNotice,
    formatUnits,
    headline,
    isConfigured,
    percentage,
    periodLabel,
    progressLabel,
    reservationNotice,
    statusLabel,
    statusTone,
} from '../../Support/aiCapacity';

/**
 * The shared AI capacity on the subscription page: one pool for every Procynia module, in AI units.
 * A block of its own — AI capacity is separate from Basis and the options, and never sized by them.
 *
 * Meant to be understood in a few seconds — a headline, one bar, three facts. Every state is carried
 * by text first; the badge and the bar colour only repeat what the words already say.
 */
export default function AiCapacityCard({ capacity, texts = {}, locale = 'nb-NO' }) {
    if (!capacity) {
        return null;
    }

    const heading = <h2 className="text-base font-semibold text-slate-900">{texts.heading ?? 'AI-kapasitet'}</h2>;
    const explainer = (
        <p className="mt-5 text-base leading-6 text-slate-600">
            {texts.explainer ?? 'AI-kapasiteten er separat fra Basis og opsjonene. Alle AI-funksjoner i Procynia bruker den samme kapasiteten, uansett hvilke opsjoner som er aktive.'}
        </p>
    );

    if (!isConfigured(capacity)) {
        return (
            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" data-testid="ai-capacity-card">
                {heading}
                <p className="mt-4 text-lg font-semibold text-slate-900" data-testid="ai-capacity-not-configured">
                    {texts.not_configured ?? 'AI-kapasitet er ikke konfigurert ennå.'}
                </p>
                <p className="mt-2 text-base leading-6 text-slate-700">
                    {texts.not_configured_detail ?? 'AI-bruk registreres, men det er ikke satt en kommersiell kapasitetsgrense. Ta kontakt med Procynia hvis dere har spørsmål om AI-kapasitet.'}
                </p>
                {/* Usage is still recorded without a limit, so it is shown — never as "0 av 0". */}
                <dl className="mt-5 grid grid-cols-1 gap-x-8 gap-y-3 text-base sm:grid-cols-2">
                    <div>
                        <dt className="text-slate-600">{texts.not_configured_used ?? 'Brukt i perioden'}</dt>
                        <dd className="font-medium text-slate-900" data-testid="ai-capacity-used">{formatUnits(capacity.used, locale)}</dd>
                    </div>
                    <div>
                        <dt className="text-slate-600">{texts.period_label ?? 'Periode'}</dt>
                        <dd className="font-medium text-slate-900" data-testid="ai-capacity-period">{periodLabel(capacity, locale)}</dd>
                    </div>
                </dl>
                {explainer}
            </section>
        );
    }

    const reserved = reservationNotice(capacity, texts, locale);
    const exhausted = exhaustedNotice(capacity, texts, locale);
    const percent = percentage(capacity);

    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" data-testid="ai-capacity-card">
            <div className="flex flex-wrap items-center justify-between gap-3">
                {heading}
                <StatusBadge tone={statusTone(capacity)}>
                    <span data-testid="ai-capacity-status">{statusLabel(capacity, texts)}</span>
                </StatusBadge>
            </div>

            {capacity.tier_name && (
                <p className="mt-2 text-base text-slate-700" data-testid="ai-capacity-tier">
                    {texts.tier_label ?? 'Kapasitetsnivå'}: <span className="font-medium text-slate-900">{capacity.tier_name}</span>
                </p>
            )}

            <p className="mt-4 text-lg font-semibold text-slate-900" data-testid="ai-capacity-headline">
                {headline(capacity, texts, locale)}
            </p>

            <div className="mt-3 flex items-center gap-3">
                <div
                    role="progressbar"
                    aria-valuenow={percent}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-label={progressLabel(capacity, texts)}
                    className="h-3 min-w-0 flex-1 overflow-hidden rounded-full bg-slate-200"
                    data-testid="ai-capacity-progress"
                >
                    <div className={`h-full rounded-full ${barClass(capacity)}`} style={{ width: `${percent}%` }} />
                </div>
                <span className="shrink-0 text-base font-medium text-slate-700" aria-hidden="true">{percent} %</span>
            </div>

            {exhausted && (
                <p className="mt-4 rounded-lg bg-rose-50 px-4 py-3 text-base leading-6 text-rose-900" data-testid="ai-capacity-exhausted">
                    {exhausted}
                </p>
            )}

            {reserved && (
                <p className="mt-4 text-base leading-6 text-slate-700" data-testid="ai-capacity-reserved">
                    {reserved}
                </p>
            )}

            {capacity.is_provisional && (
                <p className="mt-4 text-base leading-6 text-slate-600" data-testid="ai-capacity-provisional">
                    {texts.provisional_note ?? 'AI-kapasiteten er under innfasing, og nivået kan bli justert.'}
                </p>
            )}

            <dl className="mt-5 grid grid-cols-1 gap-x-8 gap-y-3 text-base sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt className="text-slate-600">{texts.used_label ?? 'Brukt'}</dt>
                    <dd className="font-medium text-slate-900" data-testid="ai-capacity-used">{formatUnits(capacity.used, locale)}</dd>
                </div>
                <div>
                    <dt className="text-slate-600">{texts.remaining_label ?? 'Gjenstår'}</dt>
                    <dd className="font-medium text-slate-900" data-testid="ai-capacity-remaining">{formatUnits(capacity.remaining, locale)}</dd>
                </div>
                <div>
                    <dt className="text-slate-600">{texts.included_label ?? 'Inkludert'}</dt>
                    <dd className="font-medium text-slate-900" data-testid="ai-capacity-included">{formatUnits(capacity.included, locale)}</dd>
                </div>
                <div>
                    <dt className="text-slate-600">{texts.period_label ?? 'Periode'}</dt>
                    <dd className="font-medium text-slate-900" data-testid="ai-capacity-period">{periodLabel(capacity, locale)}</dd>
                </div>
            </dl>

            {explainer}
        </section>
    );
}
