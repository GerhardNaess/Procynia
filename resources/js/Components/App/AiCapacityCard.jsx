import { router } from '@inertiajs/react';
import { useState } from 'react';
import AiCapacityLevelDialog from './AiCapacityLevelDialog';
import StatusBadge from './StatusBadge';
import { SECONDARY_COLOURS } from '../../Support/actionStyles';
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
 * Meant to be understood in a few seconds — the level, a headline, one bar, four facts. Every state is carried
 * by text first; the badge and the bar colour only repeat what the words already say.
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

    const heading = <h2 className="text-base font-semibold text-slate-900">{texts.heading ?? 'AI-kapasitet'}</h2>;

    if (!isConfigured(capacity)) {
        return (
            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" data-testid="ai-capacity-card">
                {heading}
                <p className="mt-4 text-lg font-semibold text-slate-900" data-testid="ai-capacity-not-configured">
                    {texts.not_configured ?? 'AI-kapasitet er ikke konfigurert ennå.'}
                </p>
                <p className="mt-2 text-base leading-6 text-slate-700">
                    {texts.not_configured_detail ?? 'AI-bruk registreres, men det er ikke satt en kommersiell kapasitetsgrense.'}
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
            </section>
        );
    }

    const reserved = reservationNotice(capacity, texts, locale);
    const exhausted = exhaustedNotice(capacity, texts, locale);
    const percent = percentage(capacity);
    const levelChangeable = canChangeLevel(capacity, levels);

    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" data-testid="ai-capacity-card">
            <div className="flex flex-wrap items-center justify-between gap-3">
                {heading}
                <StatusBadge tone={statusTone(capacity)}>
                    <span data-testid="ai-capacity-status">{statusLabel(capacity, texts)}</span>
                </StatusBadge>
            </div>

            <div className="mt-2 flex flex-wrap items-center justify-between gap-3">
                {capacity.tier_name ? (
                    <p className="text-lg font-semibold text-slate-900" data-testid="ai-capacity-tier">{capacity.tier_name}</p>
                ) : (
                    <p className="text-base leading-6 text-slate-700" data-testid="ai-capacity-override">
                        {texts.override_note ?? 'AI-kapasiteten er særskilt konfigurert for virksomheten.'}
                    </p>
                )}
                {levels.length > 1 && (
                    <button
                        type="button"
                        disabled={!levelChangeable}
                        aria-describedby={levelChangeable ? undefined : 'ai-capacity-override-hint'}
                        onClick={() => setDialogOpen(true)}
                        className={`rounded-lg px-4 py-2 text-base font-medium disabled:cursor-not-allowed disabled:opacity-60 ${SECONDARY_COLOURS}`}
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

            <p className="mt-3 text-lg font-semibold text-slate-900" data-testid="ai-capacity-headline">
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
                    {texts.provisional_note ?? 'AI-kapasiteten er under innfasing, og nivåene kan bli justert.'}
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
