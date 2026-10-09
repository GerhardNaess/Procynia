import { router } from '@inertiajs/react';
import { useState } from 'react';
import AiCapacityLevelDialog from './AiCapacityLevelDialog';
import StatusBadge from './StatusBadge';
import { SECONDARY_ACTION } from '../../Support/actionStyles';
import {
    barClass,
    canChangeLevel,
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
 * Meant to be understood in a few seconds, read top to bottom: the heading, the level with «Endre nivå» right
 * beside it, a headline, one bar, four facts. The status badge sits apart on the heading row, so it never
 * reads as belonging to the button. Every state is carried by text first; the badge and the bar colour only
 * repeat what the words already say.
 *
 * The customer changes its level here (`levels` carries what each would include for it). Under a
 * Procynia-set override the level does not size the capacity, so the choice is shown disabled.
 */
export default function AiCapacityCard({ capacity, levels = [], texts = {}, locale = 'nb-NO' }) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    if (!capacity) {
        return null;
    }

    const selectLevel = (level) => {
        router.post('/app/billing/ai-capacity/level', { level }, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setDialogOpen(false);
            },
        });
    };

    const heading = <h2 className="text-[1.375rem] font-semibold leading-8 tracking-tight text-slate-950">{texts.heading ?? 'AI-kapasitet'}</h2>;
    const card = 'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6';
    const factLabel = 'text-base text-slate-600';
    const factValue = 'mt-0.5 text-lg font-semibold text-slate-900';

    if (!isConfigured(capacity)) {
        return (
            <section className={card} data-testid="ai-capacity-card">
                {heading}
                <p className="mt-3 text-lg font-semibold text-slate-900" data-testid="ai-capacity-not-configured">
                    {texts.not_configured ?? 'AI-kapasitet er ikke konfigurert ennå.'}
                </p>
                <p className="mt-1 text-base leading-7 text-slate-600">
                    {texts.not_configured_detail ?? 'AI-bruk registreres, men det er ikke satt en kommersiell kapasitetsgrense.'}
                </p>
                {/* Usage is still recorded without a limit, so it is shown — never as "0 av 0". */}
                <dl className="mt-5 grid grid-cols-1 gap-x-8 gap-y-4 border-t border-slate-100 pt-4 sm:grid-cols-2">
                    <div>
                        <dt className={factLabel}>{texts.not_configured_used ?? 'Brukt i perioden'}</dt>
                        <dd className={factValue} data-testid="ai-capacity-used">{formatUnits(capacity.used, locale)}</dd>
                    </div>
                    <div>
                        <dt className={factLabel}>{texts.period_label ?? 'Periode'}</dt>
                        <dd className={factValue} data-testid="ai-capacity-period">{periodLabel(capacity, locale)}</dd>
                    </div>
                </dl>
            </section>
        );
    }

    const reserved = reservationNotice(capacity, texts, locale);
    const exhausted = exhaustedNotice(capacity, texts, locale);
    const percent = percentage(capacity);
    const levelChangeable = canChangeLevel(capacity, levels);

    return (
        <section className={card} data-testid="ai-capacity-card">
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                {heading}
                <StatusBadge tone={statusTone(capacity)}>
                    <span data-testid="ai-capacity-status">{statusLabel(capacity, texts)}</span>
                </StatusBadge>
            </div>

            {/* The level and its action are one group: on a narrow screen the button wraps to just below
                the level, never across to the far side of the card. */}
            <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2" data-testid="ai-capacity-level-group">
                {capacity.tier_name ? (
                    <p className="text-lg font-semibold text-slate-900" data-testid="ai-capacity-tier">{capacity.tier_name}</p>
                ) : (
                    <p className="text-base leading-7 text-slate-600" data-testid="ai-capacity-override">
                        {texts.override_note ?? 'AI-kapasiteten er særskilt konfigurert for virksomheten.'}
                    </p>
                )}
                {levels.length > 1 && (
                    <button
                        type="button"
                        disabled={!levelChangeable}
                        aria-describedby={levelChangeable ? undefined : 'ai-capacity-override-hint'}
                        onClick={() => setDialogOpen(true)}
                        className={`${SECONDARY_ACTION} focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600`}
                        data-testid="ai-capacity-change-level"
                    >
                        {texts.change_level ?? 'Endre nivå'}
                    </button>
                )}
            </div>
            {!levelChangeable && levels.length > 1 && (
                <span id="ai-capacity-override-hint" className="sr-only">
                    {texts.override_note ?? 'AI-kapasiteten er særskilt konfigurert for virksomheten.'}
                </span>
            )}

            <p className="mt-5 text-xl font-semibold text-slate-900" data-testid="ai-capacity-headline">
                {headline(capacity, texts, locale)}
            </p>

            <div className="mt-2 flex items-center gap-3">
                <div
                    role="progressbar"
                    aria-valuenow={percent}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuetext={`${percent} %`}
                    aria-label={progressLabel(capacity, texts)}
                    className="h-3 min-w-0 flex-1 overflow-hidden rounded-full bg-slate-200"
                    data-testid="ai-capacity-progress"
                >
                    <div className={`h-full rounded-full ${barClass(capacity)}`} style={{ width: `${percent}%` }} />
                </div>
                <span className="shrink-0 text-base font-semibold tabular-nums text-slate-900" aria-hidden="true">{percent} %</span>
            </div>

            {exhausted && (
                <p className="mt-4 rounded-lg bg-rose-50 px-4 py-3 text-base leading-6 text-rose-900" data-testid="ai-capacity-exhausted">
                    {exhausted}
                </p>
            )}

            {reserved && (
                <p className="mt-3 text-base leading-7 text-slate-600" data-testid="ai-capacity-reserved">
                    {reserved}
                </p>
            )}

            {capacity.is_provisional && (
                <p className="mt-3 text-base leading-7 text-slate-600" data-testid="ai-capacity-provisional">
                    {texts.provisional_note ?? 'AI-kapasiteten er under innfasing, og nivåene kan bli justert.'}
                </p>
            )}

            <dl className="mt-5 grid grid-cols-2 gap-x-6 gap-y-4 border-t border-slate-100 pt-4 lg:grid-cols-4" data-testid="ai-capacity-facts">
                <div>
                    <dt className={factLabel}>{texts.used_label ?? 'Brukt'}</dt>
                    <dd className={factValue} data-testid="ai-capacity-used">{formatUnits(capacity.used, locale)}</dd>
                </div>
                <div>
                    <dt className={factLabel}>{texts.remaining_label ?? 'Gjenstår'}</dt>
                    <dd className={factValue} data-testid="ai-capacity-remaining">{formatUnits(capacity.remaining, locale)}</dd>
                </div>
                <div>
                    <dt className={factLabel}>{texts.included_label ?? 'Inkludert'}</dt>
                    <dd className={factValue} data-testid="ai-capacity-included">{formatUnits(capacity.included, locale)}</dd>
                </div>
                <div>
                    <dt className={factLabel}>{texts.period_label ?? 'Periode'}</dt>
                    <dd className={factValue} data-testid="ai-capacity-period">{periodLabel(capacity, locale)}</dd>
                </div>
            </dl>

            <AiCapacityLevelDialog
                isOpen={dialogOpen && levelChangeable}
                levels={levels}
                texts={texts}
                locale={locale}
                processing={processing}
                onSelect={selectLevel}
                onClose={() => setDialogOpen(false)}
            />
        </section>
    );
}
