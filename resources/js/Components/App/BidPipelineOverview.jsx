function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function formatCount(value, locale) {
    return new Intl.NumberFormat(locale).format(Number(value ?? 0));
}

function activeStageClassName(count) {
    return count > 0
        ? 'border-violet-200 bg-violet-50/80'
        : 'border-slate-200 bg-white/70';
}

function outcomeStageClassName(key, count) {
    if (count <= 0) {
        return 'border-slate-200 bg-white/70';
    }

    switch (key) {
        case 'won':
            return 'border-emerald-200 bg-emerald-50';
        case 'lost':
        case 'withdrawn':
            return 'border-rose-200 bg-rose-50';
        case 'no_go':
            return 'border-amber-200 bg-amber-50';
        case 'archived':
            return 'border-slate-300 bg-slate-100';
        default:
            return 'border-slate-200 bg-white/70';
    }
}

function stageHelpText(stageKey) {
    switch (stageKey) {
        case 'discovered':
            return 'Nye muligheter som er identifisert, men som ennå ikke er vurdert nærmere.';
        case 'qualifying':
            return 'Saker som er vurdert som relevante og gode nok til å jobbe videre med.';
        case 'go_no_go':
            return 'Saker som står i en aktiv beslutning om dere skal gå videre eller stoppe løpet.';
        case 'in_progress':
            return 'Saker der teamet arbeider aktivt med tilbud, innhold, koordinering og leveranse.';
        case 'submitted':
            return 'Saker der tilbudet er sendt inn og avventer videre behandling hos oppdragsgiver.';
        case 'negotiation':
            return 'Saker der det pågår avklaringer, justeringer eller forhandling etter innsending.';
        case 'won':
            return 'Saker dere har vunnet.';
        case 'lost':
            return 'Saker dere har tapt.';
        case 'no_go':
            return 'Saker dere avsluttet tidlig som No-Go mens de fortsatt var i vurdering.';
        case 'withdrawn':
            return 'Saker som er trukket etter at arbeidet hadde startet.';
        case 'archived':
            return 'Saker som er avsluttet og flyttet ut av aktiv portefølje for historikk og sporbarhet.';
        default:
            return 'Viser hvor mange saker som ligger i denne delen av bid-prosessen akkurat nå.';
    }
}

function InfoIcon({ text, align = 'center' }) {
    const tooltipClassName = align === 'right'
        ? 'right-0'
        : 'left-1/2 -translate-x-1/2';

    return (
        <span className="group relative inline-flex items-center align-middle">
            <button
                type="button"
                className="inline-flex h-4 w-4 items-center justify-center rounded-full border border-slate-300 bg-white text-[10px] font-semibold leading-none text-slate-500 transition hover:border-slate-400 hover:text-slate-700"
                aria-label={text}
            >
                i
            </button>
            <span
                role="tooltip"
                className={classNames(
                    'pointer-events-none absolute top-full z-20 mt-2 hidden w-64 rounded-xl border border-slate-200 bg-slate-950 px-3 py-2 text-left text-xs font-medium leading-5 text-white shadow-lg group-hover:block group-focus-within:block',
                    tooltipClassName,
                )}
            >
                {text}
            </span>
        </span>
    );
}

export default function BidPipelineOverview({ pipeline, locale = 'nb-NO' }) {
    const stages = pipeline?.stages ?? [];
    const outcomes = pipeline?.outcomes ?? [];

    return (
        <section className="overflow-hidden rounded-[26px] border border-slate-200 bg-[linear-gradient(180deg,#ffffff_0%,#f8fafc_100%)] p-6 shadow-[0_10px_30px_rgba(15,23,42,0.05)] sm:p-7">
            <div className="grid gap-3 md:grid-cols-3">
                <div className="rounded-2xl border border-slate-200 bg-white/80 px-4 py-4 shadow-[0_8px_20px_rgba(15,23,42,0.04)]">
                    <div className="inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                        Totalt
                        <InfoIcon
                            text="Totalt antall bid-saker i porteføljen, uavhengig av hvilken status sakene har akkurat nå."
                        />
                    </div>
                    <div className="mt-3 text-3xl font-semibold tracking-tight text-slate-950">
                        {formatCount(pipeline?.total_count ?? 0, locale)}
                    </div>
                    <div className="mt-1 text-sm text-slate-500">
                        bid-saker i porteføljen
                    </div>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white/80 px-4 py-4 shadow-[0_8px_20px_rgba(15,23,42,0.04)]">
                    <div className="inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                        Aktive
                        <InfoIcon
                            text="Totalt antall aktive saker i porteføljen, altså saker som fortsatt er i arbeid, vurdering eller beslutning."
                        />
                    </div>
                    <div className="mt-3 text-3xl font-semibold tracking-tight text-slate-950">
                        {formatCount(pipeline?.active_total_count ?? 0, locale)}
                    </div>
                    <div className="mt-1 text-sm text-slate-500">
                        aktive saker
                    </div>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white/80 px-4 py-4 shadow-[0_8px_20px_rgba(15,23,42,0.04)]">
                    <div className="inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                        Utfall
                        <InfoIcon
                            text="Totalt antall avsluttede saker i porteføljen, altså saker med endelig utfall eller saker som er flyttet ut av aktiv portefølje."
                        />
                    </div>
                    <div className="mt-3 text-3xl font-semibold tracking-tight text-slate-950">
                        {formatCount(pipeline?.outcome_total_count ?? 0, locale)}
                    </div>
                    <div className="mt-1 text-sm text-slate-500">
                        avsluttede saker
                    </div>
                </div>
            </div>

            <div className="mt-5 rounded-[24px] border border-slate-200/80 bg-white/80 p-4 sm:p-5">
                <div className="mb-4">
                    <div>
                        <div className="inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">
                            Aktivt løp
                            <InfoIcon text="Den aktive delen av pipeline viser saker som fortsatt er i arbeid, vurdering eller beslutning." />
                        </div>
                        <div className="mt-1 text-sm text-slate-500">
                            Saker som fortsatt er i arbeid eller nær beslutning.
                        </div>
                    </div>
                </div>

                <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                    {stages.map((stage) => (
                        <div
                            key={stage.key}
                            className={classNames(
                                'rounded-2xl border px-4 py-4 transition',
                                activeStageClassName(stage.count),
                            )}
                        >
                            <div className="inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                {stage.label}
                                <InfoIcon text={stageHelpText(stage.key)} />
                            </div>
                            <div className="mt-3 text-3xl font-semibold tracking-tight text-slate-950">
                                {formatCount(stage.count, locale)}
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            <div className="mt-4 rounded-[24px] border border-slate-200/80 bg-slate-50/80 p-4 sm:p-5">
                <div className="mb-4">
                    <div>
                        <div className="inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">
                            Utfall
                            <InfoIcon text="Utfall viser saker som er avsluttet med et endelig resultat eller er flyttet ut av aktiv portefølje." />
                        </div>
                        <div className="mt-1 text-sm text-slate-500">
                            Avsluttede saker og resultater i porteføljen.
                        </div>
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    {outcomes.map((stage) => (
                        <div
                            key={stage.key}
                            className={classNames(
                                'rounded-2xl border px-4 py-4 transition',
                                outcomeStageClassName(stage.key, stage.count),
                            )}
                        >
                            <div className="inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                {stage.label}
                                <InfoIcon text={stageHelpText(stage.key)} />
                            </div>
                            <div className="mt-3 text-3xl font-semibold tracking-tight text-slate-950">
                                {formatCount(stage.count, locale)}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
