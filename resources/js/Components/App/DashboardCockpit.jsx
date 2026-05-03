import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function formatNumber(value, locale) {
    return new Intl.NumberFormat(locale).format(Number(value ?? 0));
}

function formatPercent(value, locale) {
    if (value === null || value === undefined) {
        return '—';
    }

    return `${new Intl.NumberFormat(locale, {
        maximumFractionDigits: 1,
    }).format(Number(value))} %`;
}

function formatDate(value, locale, options = {}) {
    if (!value) {
        return 'Ukjent dato';
    }

    return new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        ...options,
    }).format(new Date(value));
}

function formatRelativeTime(value, locale = 'nb-NO') {
    if (!value) {
        return 'Ingen aktivitet';
    }

    const date = new Date(value);
    const diffMinutes = Math.round((date.getTime() - Date.now()) / 60000);
    const absoluteMinutes = Math.abs(diffMinutes);
    const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

    if (absoluteMinutes < 60) {
        return formatter.format(diffMinutes, 'minute');
    }

    const diffHours = Math.round(diffMinutes / 60);
    if (Math.abs(diffHours) < 24) {
        return formatter.format(diffHours, 'hour');
    }

    const diffDays = Math.round(diffHours / 24);

    return formatter.format(diffDays, 'day');
}

function groupByDate(items) {
    return items.reduce((carry, item) => {
        if (!item?.date_key) {
            return carry;
        }

        if (!carry[item.date_key]) {
            carry[item.date_key] = [];
        }

        carry[item.date_key].push(item);

        return carry;
    }, {});
}

function toDateKey(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function startOfMonth(date) {
    return new Date(date.getFullYear(), date.getMonth(), 1);
}

function addMonths(date, amount) {
    return new Date(date.getFullYear(), date.getMonth() + amount, 1);
}

function buildCalendarDays(monthStartValue, locale = 'nb-NO') {
    const monthStart = monthStartValue ? new Date(monthStartValue) : new Date();
    const firstOfMonth = new Date(monthStart.getFullYear(), monthStart.getMonth(), 1);
    const offset = (firstOfMonth.getDay() + 6) % 7;
    const firstCell = new Date(firstOfMonth);
    firstCell.setDate(firstOfMonth.getDate() - offset);

    const days = [];

    for (let index = 0; index < 42; index += 1) {
        const date = new Date(firstCell);
        date.setDate(firstCell.getDate() + index);

        days.push({
            date,
            dateKey: toDateKey(date),
            dayOfMonth: date.getDate(),
            inCurrentMonth: date.getMonth() === firstOfMonth.getMonth(),
            isToday: toDateKey(date) === toDateKey(new Date()),
        });
    }

    return {
        monthLabel: new Intl.DateTimeFormat(locale, {
            month: 'long',
            year: 'numeric',
        }).format(firstOfMonth),
        monthValue: `${firstOfMonth.getFullYear()}-${String(firstOfMonth.getMonth() + 1).padStart(2, '0')}`,
        yearValue: String(firstOfMonth.getFullYear()),
        days,
    };
}

function buildCalendarYearOptions(deadlineItems, fallbackDate) {
    const years = new Set();

    deadlineItems.forEach((item) => {
        if (!item?.date) {
            return;
        }

        years.add(new Date(item.date).getFullYear());
    });

    const fallbackYear = fallbackDate.getFullYear();

    for (let year = fallbackYear - 2; year <= fallbackYear + 2; year += 1) {
        years.add(year);
    }

    return Array.from(years).sort((left, right) => left - right);
}

function buildMonthOptions(locale = 'nb-NO') {
    return Array.from({ length: 12 }, (_, index) => {
        const date = new Date(2026, index, 1);

        return {
            value: String(index),
            label: new Intl.DateTimeFormat(locale, { month: 'long' }).format(date),
        };
    });
}

const DASHBOARD_INFO_TEXTS = {
    'attention_deadline-soon': 'Viser saker med operative frister som nærmer seg eller er passert. Åpne kategorien for å se konkrete saker direkte.',
    'attention_missing-bid-manager': 'Viser saker som mangler eksplisitt operativt ansvar. Åpne kategorien for å gå direkte til sakene.',
    'attention_go-no-go-pending': 'Viser saker i beslutningsfase uten endelig utfall. Åpne kategorien for å gå direkte til sakene.',
    'attention_inactive-seven-days': 'Viser saker uten kommentarer eller innsendinger de siste 7 dagene. Åpne kategorien for å se konkrete saker direkte.',
    attention: 'Viser konkrete saker som bør følges opp først. Åpne en kategori for å se og åpne sakene direkte.',
    deadlines: 'Viser markerte operative frister og Business Reviews i valgt måned. Bruk kalenderen til å finne datoer som nærmer seg eller allerede er passert.',
    portfolio: 'Gir rask status på saksporteføljen. Bruk denne gruppen for å se totalvolum, aktive saker og saker som allerede har fått et registrert utfall.',
    portfolio_total: 'Viser alle saker i porteføljen, både aktive saker og saker som allerede er avsluttet.',
    portfolio_active: 'Viser saker som fortsatt følges opp operativt og ikke har nådd et registrert utfall.',
    portfolio_outcome: 'Viser saker der utfallet er registrert, inkludert vunnet, tapt, No-Go, trukket og arkivert.',
    bid_quality: 'Viser objektive styringsmål for ansvar, flyt og utfall i aktive og avsluttede saker.',
    responsibility_activity: 'Viser sakene i cockpit-skopet, watch lists og siste aktivitet.',
    responsibility_bid_manager_cases: 'Antall saker i cockpit-skopet med bid-manager.',
    responsibility_opportunity_owner_cases: 'Antall saker i cockpit-skopet med kommersiell eier.',
    responsibility_saved_watch_lists: 'Antall watch lists du har lagret.',
    responsibility_contributor_cases: 'Antall saker i cockpit-skopet med aktiv bidragsyter-tilgang.',
    responsibility_activity_14_days: 'Hvor mange aktiviteter som har skjedd på saker i cockpit-skopet de siste 14 dagene.',
    responsibility_last_activity: 'Siste aktivitet viser siste brukerhandling på saken, som kommentar, statusendring, ansvar eller innsending.',
    responsibility_inactive_7_days: 'Saker i cockpit-skopet som ikke har hatt aktivitet de siste 7 dagene.',
    pipeline_stages: 'Viser volum og gjennomsnittlig tempo i hver fase.',
    stage_discovered: 'Saker som nettopp er oppdaget og ennå ikke er startet opp.',
    stage_qualifying: 'Saker som vurderes før videre beslutning.',
    stage_go_no_go: 'Saker som ligger i beslutningsfasen og venter på utfall.',
    stage_in_progress: 'Saker som er i aktivt arbeid.',
    stage_submitted: 'Saker som er levert og nå venter på respons eller videre avklaring.',
    stage_negotiation: 'Saker som er i dialog eller forhandling etter levering.',
    outcomes: 'Viser hvordan porteføljen er avsluttet fordelt på utfall.',
    outcome_won: 'Saker som er vunnet.',
    outcome_lost: 'Saker som er tapt.',
    outcome_no_go: 'Saker som er stoppet tidlig med No-Go.',
    outcome_withdrawn: 'Saker som er trukket etter at arbeidet har startet.',
    outcome_archived: 'Saker som er arkivert og avsluttet uten aktivt arbeid.',
};

function InfoButton({ infoKey, title, infoText, openInfoKey, setOpenInfoKey }) {
    const isOpen = openInfoKey === infoKey;
    const resolvedInfoText = infoText ?? DASHBOARD_INFO_TEXTS[infoKey];

    if (!resolvedInfoText) {
        return null;
    }

    return (
        <span
            className="relative inline-flex shrink-0"
            onMouseEnter={() => setOpenInfoKey(infoKey)}
            onMouseLeave={() => setOpenInfoKey((current) => (current === infoKey ? null : current))}
        >
            <button
                type="button"
                aria-label={`Vis forklaring for ${title}`}
                aria-expanded={isOpen}
                aria-describedby={isOpen ? `${infoKey}-tooltip` : undefined}
                onClick={(event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    setOpenInfoKey((current) => (current === infoKey ? null : infoKey));
                }}
                onFocus={() => setOpenInfoKey(infoKey)}
                onBlur={() => setOpenInfoKey((current) => (current === infoKey ? null : current))}
                className={classNames(
                    'inline-flex h-6 w-6 items-center justify-center rounded-full border border-slate-300 bg-white text-[10px] font-semibold leading-none text-slate-500 transition',
                    'hover:border-violet-300 hover:text-violet-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-300',
                    isOpen ? 'border-violet-300 text-violet-700 shadow-sm' : '',
                )}
            >
                i
            </button>

            {isOpen ? (
                <div
                    id={`${infoKey}-tooltip`}
                    role="tooltip"
                    className="absolute right-0 top-full z-30 mt-2 w-72 max-w-[calc(100vw-2rem)] rounded-2xl border border-slate-200 bg-white p-3 text-xs leading-5 text-slate-600 shadow-[0_20px_40px_rgba(15,23,42,0.12)]"
                >
                    {resolvedInfoText}
                </div>
            ) : null}
        </span>
    );
}

function InfoTile({
    title,
    infoKey,
    infoText,
    openInfoKey,
    setOpenInfoKey,
    className = '',
    titleClassName = 'text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500',
    dense = false,
    children,
}) {
    return (
        <div className={classNames('rounded-2xl border border-slate-200 bg-white', className)}>
            <div className={classNames('flex items-start justify-between', dense ? 'gap-2' : 'gap-3')}>
                <div className="min-w-0">
                    <div className={titleClassName}>
                        {title}
                    </div>
                </div>
                {infoKey ? (
                    <InfoButton infoKey={infoKey} title={title} infoText={infoText} openInfoKey={openInfoKey} setOpenInfoKey={setOpenInfoKey} />
                ) : null}
            </div>
            <div className={classNames(dense ? 'mt-1.5' : 'mt-2')}>
                {children}
            </div>
        </div>
    );
}

function Card({ title, subtitle, infoKey, infoText, action, children, className = '', openInfoKey, setOpenInfoKey, dense = false }) {
    return (
        <section className={classNames('rounded-[24px] border border-slate-200 bg-white/90 shadow-[0_10px_30px_rgba(15,23,42,0.05)]', dense ? 'p-4' : 'p-5', className)}>
            <div className={classNames('flex items-start justify-between gap-4', dense ? 'mb-3' : 'mb-4')}>
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <div className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">
                            {title}
                        </div>
                        {infoKey ? (
                            <InfoButton infoKey={infoKey} title={title} infoText={infoText} openInfoKey={openInfoKey} setOpenInfoKey={setOpenInfoKey} />
                        ) : null}
                    </div>
                    {subtitle ? (
                        <p className={classNames('mt-1 text-sm text-slate-500', dense ? 'leading-5' : 'leading-6')}>
                            {subtitle}
                        </p>
                    ) : null}
                </div>
                <div className="flex items-start gap-2">
                    {action}
                </div>
            </div>
            {children}
        </section>
    );
}

function AttentionPill({ severity, count }) {
    const palette = {
        danger: 'border-rose-200 bg-rose-50 text-rose-700',
        warning: 'border-amber-200 bg-amber-50 text-amber-800',
        neutral: 'border-slate-200 bg-slate-50 text-slate-600',
    };

    return (
        <span className={classNames('inline-flex min-w-12 items-center justify-center rounded-full border px-2.5 py-1 text-xs font-semibold', palette[severity] ?? palette.neutral)}>
            {count}
        </span>
    );
}

function SeverityDot({ severity }) {
    const palette = {
        danger: 'bg-rose-500',
        warning: 'bg-amber-500',
        neutral: 'bg-slate-400',
    };

    return <span className={classNames('mt-1.5 h-2.5 w-2.5 rounded-full', palette[severity] ?? palette.neutral)} />;
}

function AttentionCaseRow({ item }) {
    return (
        <Link
            href={item.show_url}
            className={classNames(
                'group flex items-start gap-3 rounded-xl border bg-white px-3 py-2.5 transition',
                item.severity === 'danger'
                    ? 'border-rose-200 hover:border-rose-300 hover:bg-rose-50/80'
                    : item.severity === 'warning'
                        ? 'border-amber-200 hover:border-amber-300 hover:bg-amber-50/80'
                        : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50',
            )}
        >
            <SeverityDot severity={item.severity} />
            <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-semibold text-slate-950">
                    {item.title}
                </div>
                <div className="mt-0.5 text-[11px] font-semibold text-slate-700">
                    {item.reason}
                </div>
                <div className="mt-0.5 text-[11px] leading-4 text-slate-500">
                    {item.secondary}
                </div>
            </div>
            <span className="mt-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-violet-700 opacity-0 transition group-hover:opacity-100">
                Åpne sak
            </span>
        </Link>
    );
}

function AttentionCategoryPanel({ item, isOpen, onToggle }) {
    const palette = {
        danger: 'border-rose-200 bg-rose-50/60',
        warning: 'border-amber-200 bg-amber-50/60',
        neutral: 'border-slate-200 bg-slate-50/60',
    };

    return (
        <div className={classNames('overflow-hidden rounded-2xl border transition', palette[item.severity] ?? palette.neutral)}>
            <button
                type="button"
                aria-expanded={isOpen}
                onClick={onToggle}
                className="flex w-full items-center gap-3 px-3 py-3 text-left"
            >
                <SeverityDot severity={item.severity} />
                <div className="min-w-0 flex-1">
                    <div className="text-sm font-semibold text-slate-950">
                        {item.title}
                    </div>
                    <div className="mt-0.5 text-[11px] leading-4 text-slate-500">
                        {item.subtitle}
                    </div>
                </div>
                <AttentionPill severity={item.severity} count={item.count} />
                <span
                    className={classNames(
                        'inline-flex h-6 w-6 items-center justify-center rounded-full border border-slate-300 bg-white/80 text-lg leading-none text-slate-400 transition-transform',
                        isOpen ? 'rotate-90 border-slate-400 text-slate-700' : '',
                    )}
                    aria-hidden="true"
                >
                    ›
                </span>
            </button>

            {isOpen ? (
                <div className="border-t border-white/70 px-3 py-3">
                    {item.items.length > 0 ? (
                        <div className="space-y-2">
                            {item.items.map((attentionCase) => (
                                <AttentionCaseRow key={attentionCase.id} item={attentionCase} />
                            ))}
                        </div>
                    ) : (
                        <div className="rounded-xl border border-dashed border-slate-200 bg-white px-3 py-3 text-sm text-slate-500">
                            Ingen saker matcher denne kategorien akkurat nå.
                        </div>
                    )}
                </div>
            ) : null}
        </div>
    );
}

function formatMetricValue(metric, locale) {
    if (metric?.value === null || metric?.value === undefined) {
        return '—';
    }

    if (metric.unit === '%') {
        return formatPercent(metric.value, locale);
    }

    if (metric.unit === 'dager') {
        return `${formatNumber(metric.value, locale)} dager`;
    }

    if (metric.unit === 'saker') {
        return `${formatNumber(metric.value, locale)} saker`;
    }

    return `${formatNumber(metric.value, locale)}${metric.unit ? ` ${metric.unit}` : ''}`;
}

function QualityBreakdownChips({ breakdown, locale }) {
    return (
        <div className="mt-3 flex flex-wrap gap-2">
            {breakdown.map((item) => (
                <span
                    key={item.key}
                    className="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-600"
                >
                    {item.label}
                    <span className="font-semibold text-slate-900">
                        {formatNumber(item.count ?? 0, locale)}
                    </span>
                    {item.share !== null && item.share !== undefined ? (
                        <span className="text-slate-400">
                            ({formatPercent(item.share, locale)})
                        </span>
                    ) : null}
                </span>
            ))}
        </div>
    );
}

function BidQualityMetricTile({ metric, locale, openInfoKey, setOpenInfoKey, spanFullWidth = false }) {
    const palette = {
        success: 'border-emerald-200 bg-emerald-50/70',
        warning: 'border-amber-200 bg-amber-50/70',
        danger: 'border-rose-200 bg-rose-50/70',
        neutral: 'border-slate-200 bg-slate-50/70',
    };

    return (
        <InfoTile
            title={metric.title}
            infoKey={`bid_quality_${metric.key}`}
            infoText={metric.definition}
            openInfoKey={openInfoKey}
            setOpenInfoKey={setOpenInfoKey}
            className={classNames('px-4 py-3', palette[metric.severity] ?? palette.neutral, spanFullWidth ? 'sm:col-span-2' : '')}
            dense
        >
            <div className="text-2xl font-semibold tracking-tight text-slate-950">
                {formatMetricValue(metric, locale)}
            </div>
            <div className="mt-1 text-sm leading-5 text-slate-500">
                {metric.subtitle}
            </div>
            <div className="mt-2 flex items-center justify-between gap-3 text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                <span>
                    {metric.trend_basis}
                </span>
                {metric.numerator !== undefined && metric.denominator !== undefined ? (
                    <span>
                        {formatNumber(metric.numerator, locale)}
                        {' av '}
                        {formatNumber(metric.denominator, locale)}
                    </span>
                ) : metric.sample_size !== undefined ? (
                    <span>
                        {formatNumber(metric.sample_size, locale)}
                        {' i grunnlag'}
                    </span>
                ) : null}
            </div>
            {Array.isArray(metric.breakdown) && metric.breakdown.length > 0 ? (
                <QualityBreakdownChips breakdown={metric.breakdown} locale={locale} />
            ) : null}
        </InfoTile>
    );
}

function BidQualityTrendTile({ metric, locale, openInfoKey, setOpenInfoKey, spanFullWidth = false }) {
    const series = Array.isArray(metric.series) ? metric.series : [];
    const maxMedian = Math.max(...series.map((point) => Number(point.median_days ?? 0)), 1);

    return (
        <InfoTile
            title={metric.title}
            infoKey={`bid_quality_${metric.key}`}
            infoText={metric.definition}
            openInfoKey={openInfoKey}
            setOpenInfoKey={setOpenInfoKey}
            className={classNames('px-4 py-3', 'border-slate-200 bg-slate-50/70', spanFullWidth ? 'sm:col-span-2' : '')}
            dense
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="text-sm font-semibold text-slate-950">
                        {metric.subtitle ?? 'Utvikling siste 12 måneder'}
                    </div>
                    <div className="mt-0.5 text-[11px] leading-4 text-slate-500">
                        Median dager per måned. Grunnlag vises per datapunkt.
                    </div>
                </div>
                <div className="text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                    {metric.period}
                </div>
            </div>

            {series.length > 0 ? (
                <div className="mt-4 grid gap-2" style={{ gridTemplateColumns: `repeat(${series.length}, minmax(0, 1fr))` }}>
                    {series.map((point) => {
                        const medianDays = Number(point.median_days ?? 0);
                        const barHeight = Math.max(12, Math.round((medianDays / maxMedian) * 96));

                        return (
                            <div
                                key={point.month}
                                className="flex min-w-0 flex-col items-center"
                                title={`${point.label} ${point.month} · median ${formatNumber(point.median_days, locale)} dager · grunnlag ${formatNumber(point.sample_size, locale)}`}
                            >
                                <div className="mb-1 text-[11px] font-semibold text-slate-900">
                                    {formatNumber(point.median_days, locale)}
                                    {' '}
                                    d
                                </div>
                                <div className="flex h-32 w-full items-end rounded-xl bg-slate-100 px-1.5">
                                    <div
                                        className="w-full rounded-t-xl bg-violet-500"
                                        style={{ height: `${barHeight}px` }}
                                    />
                                </div>
                                <div className="mt-1 text-center text-[11px] font-semibold text-slate-900">
                                    {point.label}
                                </div>
                                <div className="text-center text-[10px] text-slate-400">
                                    n=
                                    {formatNumber(point.sample_size, locale)}
                                </div>
                            </div>
                        );
                    })}
                </div>
            ) : (
                <div className="mt-4 rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-4 text-sm text-slate-500">
                    Ingen månedlige datapunkter med gyldig grunnlag.
                </div>
            )}
        </InfoTile>
    );
}

function DeadlinePopover({ items, locale }) {
    return (
        <div className="absolute left-0 top-full z-20 mt-2 hidden w-72 rounded-2xl border border-slate-200 bg-white p-3 shadow-[0_20px_40px_rgba(15,23,42,0.12)] group-hover:block group-focus-within:block">
            <div className="mb-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                Frister denne dagen
            </div>
            <div className="space-y-2">
                {items.map((item) => (
                    <Link
                        key={item.id}
                        href={item.show_url}
                        className="block rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-left transition hover:border-violet-200 hover:bg-violet-50/80"
                    >
                        <div className="text-sm font-semibold text-slate-950">
                            {item.title}
                        </div>
                        <div className="mt-1 text-xs leading-5 text-slate-500">
                            {item.deadline_type_label}
                            {' · '}
                            {formatDate(item.date, locale, { day: 'numeric', month: 'short' })}
                        </div>
                        <div className="mt-1 text-xs leading-5 text-slate-500">
                            {item.bid_manager_name ?? 'Ingen bid-manager'}
                            {item.phase_label ? ` · ${item.phase_label}` : ''}
                        </div>
                    </Link>
                ))}
            </div>
        </div>
    );
}

export default function DashboardCockpit({ cockpit, locale = 'nb-NO' }) {
    const [openInfoKey, setOpenInfoKey] = useState(null);
    const [openAttentionKey, setOpenAttentionKey] = useState(null);
    const portfolio = cockpit?.portfolio ?? { total: 0, active: 0, outcome: 0 };
    const attentionItems = cockpit?.attention?.items ?? [];
    const bidQuality = cockpit?.bid_quality ?? { title: 'Bid-kvalitet og styring', subtitle: '', items: [] };
    const deadlines = cockpit?.deadlines ?? { month_start: null, month_label: '', items: [], upcoming: [] };
    const pipeline = cockpit?.pipeline ?? { stages: [], outcomes: [] };
    const responsibility = cockpit?.responsibility_activity ?? {
        bid_manager_cases_count: 0,
        opportunity_owner_cases_count: 0,
        saved_watch_lists_count: 0,
        contributor_cases_count: 0,
        activity: {
            last_activity_at: null,
            activity_count_14_days: 0,
            inactive_7_days_count: 0,
        },
    };
    const outcomes = cockpit?.outcomes ?? [];
    const deadlineGroups = useMemo(() => groupByDate(deadlines.items ?? []), [deadlines.items]);
    const initialCalendarDate = useMemo(() => {
        if (deadlines.month_start) {
            return startOfMonth(new Date(deadlines.month_start));
        }

        return startOfMonth(new Date());
    }, [deadlines.month_start]);
    const [visibleMonthStart, setVisibleMonthStart] = useState(initialCalendarDate);
    const [selectedDateKey, setSelectedDateKey] = useState(null);
    const [jumpDateValue, setJumpDateValue] = useState('');
    const calendar = useMemo(() => buildCalendarDays(visibleMonthStart, locale), [visibleMonthStart, locale]);
    const monthOptions = useMemo(() => buildMonthOptions(locale), [locale]);
    const calendarYearOptions = useMemo(
        () => buildCalendarYearOptions(deadlines.items ?? [], initialCalendarDate),
        [deadlines.items, initialCalendarDate],
    );
    const pipelineStages = pipeline.stages ?? [];
    const stageMax = Math.max(...pipelineStages.map((stage) => Number(stage.count ?? 0)), 1);

    return (
        <div className="space-y-5">
            <section className="space-y-1.5">
                <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                    Bid Status
                </h1>
                <p className="max-w-4xl text-[15px] leading-7 text-slate-500">
                    Porteføljeoversikten gir deg et samlet cockpit-blikk på hvor sakene ligger i bid-prosessen akkurat nå.
                </p>
            </section>

            <div className="grid gap-5 xl:grid-cols-12 xl:items-stretch">
                <div className="xl:col-span-6 h-full flex flex-col">
                    <Card
                        title="Oppmerksomhet nå"
                        subtitle="Klikk en kategori for å se konkrete saker og åpne dem direkte."
                        infoKey="attention"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full border-rose-200 bg-rose-50/70"
                        dense
                    >
                        <div className="space-y-2">
                            {attentionItems.length > 0 ? attentionItems.map((item) => (
                                <AttentionCategoryPanel
                                    key={item.key}
                                    item={item}
                                    isOpen={openAttentionKey === item.key}
                                    onToggle={() => setOpenAttentionKey((current) => (current === item.key ? null : item.key))}
                                />
                            )) : (
                                <div className="rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-4 text-sm text-slate-500">
                                    Ingen saker krever umiddelbar oppmerksomhet.
                                </div>
                            )}
                        </div>
                    </Card>
                </div>

                <div className="xl:col-span-6 h-full flex flex-col">
                    <Card
                        title="Bidkalender"
                        subtitle={`Markerte frister og Business Reviews for ${calendar.monthLabel}`}
                        infoKey="deadlines"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full border-violet-200 bg-violet-50/60"
                        dense
                    >
                        <div className="space-y-3">
                            <div className="rounded-2xl border border-slate-200 bg-white p-2.5">
                                <div className="flex flex-col gap-2.5">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="flex items-center gap-2">
                                            <button
                                                type="button"
                                                aria-label="Forrige måned"
                                                onClick={() => setVisibleMonthStart((current) => addMonths(current, -1))}
                                                className="inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-600 transition hover:border-violet-300 hover:text-violet-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-300"
                                            >
                                                ←
                                            </button>
                                            <div className="text-[15px] font-semibold capitalize text-slate-900">
                                                {calendar.monthLabel}
                                            </div>
                                            <button
                                                type="button"
                                                aria-label="Neste måned"
                                                onClick={() => setVisibleMonthStart((current) => addMonths(current, 1))}
                                                className="inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-600 transition hover:border-violet-300 hover:text-violet-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-300"
                                            >
                                                →
                                            </button>
                                        </div>
                                        <div className="text-[11px] text-slate-500">
                                            Hold over markerte dager for detaljer
                                        </div>
                                    </div>

                                    <div className="grid gap-2 md:grid-cols-[minmax(0,0.9fr)_minmax(0,0.8fr)_minmax(0,1.1fr)]">
                                        <label className="block">
                                            <span className="mb-1 block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                Måned
                                            </span>
                                            <select
                                                value={String(visibleMonthStart.getMonth())}
                                                onChange={(event) => {
                                                    const nextMonth = Number(event.target.value);
                                                    setVisibleMonthStart(new Date(visibleMonthStart.getFullYear(), nextMonth, 1));
                                                }}
                                                className="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-200"
                                            >
                                                {monthOptions.map((option) => (
                                                    <option key={option.value} value={option.value}>
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>

                                        <label className="block">
                                            <span className="mb-1 block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                År
                                            </span>
                                            <select
                                                value={calendar.yearValue}
                                                onChange={(event) => {
                                                    const nextYear = Number(event.target.value);
                                                    setVisibleMonthStart(new Date(nextYear, visibleMonthStart.getMonth(), 1));
                                                }}
                                                className="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-200"
                                            >
                                                {calendarYearOptions.map((year) => (
                                                    <option key={year} value={String(year)}>
                                                        {year}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>

                                        <div>
                                            <label className="mb-1 block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500" htmlFor="deadline-date-jump">
                                                Gå til dato
                                            </label>
                                            <div className="flex gap-2">
                                                <input
                                                    id="deadline-date-jump"
                                                    type="date"
                                                    value={jumpDateValue}
                                                    onChange={(event) => setJumpDateValue(event.target.value)}
                                                    className="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-200"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        if (!jumpDateValue) {
                                                            return;
                                                        }

                                                        const nextDate = new Date(`${jumpDateValue}T00:00:00`);
                                                        setVisibleMonthStart(startOfMonth(nextDate));
                                                        setSelectedDateKey(toDateKey(nextDate));
                                                    }}
                                                    className="inline-flex shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:border-violet-300 hover:bg-violet-50 hover:text-violet-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-200"
                                                >
                                                    Vis
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div className="mt-2 grid grid-cols-7 gap-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">
                                    {['Man', 'Tir', 'Ons', 'Tor', 'Fre', 'Lør', 'Søn'].map((label) => (
                                        <div key={label} className="px-1 py-0.5 text-center">
                                            {label}
                                        </div>
                                    ))}
                                </div>

                                <div className="mt-1 grid grid-cols-7 gap-0.5">
                                    {calendar.days.map((day) => {
                                        const items = deadlineGroups[day.dateKey] ?? [];
                                        const isSelected = selectedDateKey === day.dateKey;

                                        return (
                                            <button
                                                key={day.dateKey}
                                                type="button"
                                                onClick={() => setSelectedDateKey(day.dateKey)}
                                                className={classNames(
                                                    'group relative min-h-10 rounded-xl border px-1 py-0.5 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-300',
                                                    day.inCurrentMonth ? 'border-slate-200 bg-white' : 'border-slate-100 bg-slate-50/70 text-slate-300',
                                                    day.isToday ? 'ring-2 ring-violet-200' : '',
                                                    isSelected ? 'border-violet-400 bg-violet-50' : '',
                                                )}
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="text-[11px] font-semibold">
                                                        {day.dayOfMonth}
                                                    </div>
                                                    {items.length > 0 ? (
                                                        <span className="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-violet-100 px-1 text-[9px] font-semibold text-violet-700">
                                                            {items.length}
                                                        </span>
                                                    ) : null}
                                                </div>

                                                {items.length > 0 ? (
                                                    <DeadlinePopover items={items} locale={locale} />
                                                ) : null}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>
                    </Card>
                </div>
            </div>

            <div className="grid gap-4 xl:grid-cols-12">
                <div className="xl:col-span-3">
                    <Card
                        title="Porteføljeoversikt"
                        subtitle="Viser hvor mange saker dere har totalt, hvor mange som fortsatt er aktive, og hvor mange som allerede har fått et registrert utfall."
                        infoKey="portfolio"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full"
                    >
                        <div className="grid gap-3">
                            {[
                                {
                                    label: 'Saker totalt',
                                    description: 'Alle saker i porteføljen.',
                                    value: portfolio.total,
                                    infoKey: 'portfolio_total',
                                },
                                {
                                    label: 'Aktive saker',
                                    description: 'Saker som fortsatt følges opp.',
                                    value: portfolio.active,
                                    infoKey: 'portfolio_active',
                                },
                                {
                                    label: 'Saker med registrert utfall',
                                    description: 'Saker der resultatet allerede er registrert.',
                                    value: portfolio.outcome,
                                    infoKey: 'portfolio_outcome',
                                },
                            ].map((item) => (
                                <InfoTile
                                    key={item.label}
                                    title={item.label}
                                    infoKey={item.infoKey}
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-slate-50/70 px-4 py-3"
                                >
                                    <div className="text-3xl font-semibold tracking-tight text-slate-950">
                                        {formatNumber(item.value, locale)}
                                    </div>
                                    <div className="mt-1 text-sm leading-5 text-slate-500">
                                        {item.description}
                                    </div>
                                </InfoTile>
                            ))}
                        </div>
                    </Card>
                </div>

                <div className="xl:col-span-5">
                    <Card
                        title={bidQuality.title ?? 'Bid-kvalitet og styring'}
                        subtitle={bidQuality.subtitle ?? 'Objektive styringsmål for ansvar, flyt og utfall i cockpit-skopet.'}
                        infoKey="bid_quality"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full"
                        dense
                    >
                        <div className="grid gap-2 sm:grid-cols-2">
                            {(bidQuality.items ?? []).map((metric) => (
                                Array.isArray(metric.series) ? (
                                    <BidQualityTrendTile
                                        key={metric.key}
                                        metric={metric}
                                        locale={locale}
                                        openInfoKey={openInfoKey}
                                        setOpenInfoKey={setOpenInfoKey}
                                        spanFullWidth
                                    />
                                ) : (
                                    <BidQualityMetricTile
                                        key={metric.key}
                                        metric={metric}
                                        locale={locale}
                                        openInfoKey={openInfoKey}
                                        setOpenInfoKey={setOpenInfoKey}
                                    />
                                )
                            ))}
                        </div>

                        {(bidQuality.items ?? []).length === 0 ? (
                            <div className="mt-2 rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-4 text-sm text-slate-500">
                                Ingen bid-kvalitetsmålinger er tilgjengelige akkurat nå.
                            </div>
                        ) : null}
                    </Card>
                </div>

                <div className="xl:col-span-4">
                    <Card
                        title="Ansvar & Aktivitet"
                        subtitle="Saker i cockpit-skopet, watch lists og siste aktivitet."
                        infoKey="responsibility_activity"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full"
                    >
                        <div className="space-y-3">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <InfoTile
                                    title="Saker med bid-manager"
                                    infoKey="responsibility_bid_manager_cases"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-slate-50/70 px-4 py-3"
                                >
                                    <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                        {formatNumber(responsibility.bid_manager_cases_count ?? 0, locale)}
                                    </div>
                                </InfoTile>
                                <InfoTile
                                    title="Saker med kommersiell eier"
                                    infoKey="responsibility_opportunity_owner_cases"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-slate-50/70 px-4 py-3"
                                >
                                    <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                        {formatNumber(responsibility.opportunity_owner_cases_count ?? 0, locale)}
                                    </div>
                                </InfoTile>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <InfoTile
                                    title="Lagrede watch lists"
                                    infoKey="responsibility_saved_watch_lists"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-slate-50/70 px-4 py-3"
                                >
                                    <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                        {formatNumber(responsibility.saved_watch_lists_count ?? 0, locale)}
                                    </div>
                                </InfoTile>
                                <InfoTile
                                    title="Saker med bidragsyter"
                                    infoKey="responsibility_contributor_cases"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-slate-50/70 px-4 py-3"
                                >
                                    <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                        {formatNumber(responsibility.contributor_cases_count ?? 0, locale)}
                                    </div>
                                </InfoTile>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <InfoTile
                                    title="Siste aktivitet"
                                    infoKey="responsibility_last_activity"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-violet-50/70 px-4 py-3 sm:col-span-2"
                                >
                                    <div className="mt-1 text-base font-semibold text-slate-950">
                                        {formatRelativeTime(responsibility.activity.last_activity_at, locale)}
                                    </div>
                                </InfoTile>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <InfoTile
                                    title="Aktivitet siste 14 dager"
                                    infoKey="responsibility_activity_14_days"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-violet-50/70 px-4 py-3"
                                >
                                    <div className="mt-1 text-2xl font-semibold tracking-tight text-slate-950">
                                        {formatNumber(responsibility.activity.activity_count_14_days ?? 0, locale)}
                                    </div>
                                </InfoTile>
                                <InfoTile
                                    title="Uten aktivitet 7 dager"
                                    infoKey="responsibility_inactive_7_days"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-amber-50/70 px-4 py-3"
                                >
                                    <div className="mt-1 text-2xl font-semibold tracking-tight text-slate-950">
                                        {formatNumber(responsibility.activity.inactive_7_days_count ?? 0, locale)}
                                    </div>
                                </InfoTile>
                            </div>
                        </div>
                    </Card>
                </div>
            </div>

            <div className="grid gap-4 xl:grid-cols-12">
                <div className="xl:col-span-8">
                    <Card
                        title="Pipeline-stadier"
                        subtitle="Flyt, tempo og hvor sakene stopper opp."
                        infoKey="pipeline_stages"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full"
                        dense
                    >
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            {pipelineStages.map((stage) => {
                                const width = Math.max(6, Math.round((Number(stage.count ?? 0) / stageMax) * 100));

                                return (
                                    <InfoTile
                                        key={stage.key}
                                        title={stage.label}
                                        infoKey={`stage_${stage.key}`}
                                        openInfoKey={openInfoKey}
                                        setOpenInfoKey={setOpenInfoKey}
                                        className="h-full border-slate-200 bg-slate-50/70 px-3 py-2.5"
                                        titleClassName="text-sm font-semibold text-slate-950"
                                        dense
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <div>
                                                <div className="text-[11px] leading-4 text-slate-500">
                                                    Gjennomsnittlig alder i fase
                                                </div>
                                            </div>
                                            <div className="text-right">
                                                <div className="text-base font-semibold text-slate-950">
                                                    {formatNumber(stage.count, locale)}
                                                </div>
                                                <div className="text-[11px] leading-4 text-slate-500">
                                                    {stage.average_age_hours === null ? 'Ingen måling' : `${formatNumber(stage.average_age_hours, locale)} t i snitt`}
                                                </div>
                                            </div>
                                        </div>

                                        <div className="mt-2 h-1.5 rounded-full bg-slate-200">
                                            <div
                                                className="h-1.5 rounded-full bg-violet-500"
                                                style={{ width: `${width}%` }}
                                            />
                                        </div>
                                    </InfoTile>
                                );
                            })}
                        </div>
                    </Card>
                </div>

                <div className="xl:col-span-4">
                    <Card
                        title="Utfall"
                        subtitle="Avsluttede saker og historikk."
                        infoKey="outcomes"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full"
                        dense
                    >
                        <div className="grid gap-2 sm:grid-cols-2">
                            {(outcomes ?? []).map((item) => {
                                const palette = {
                                    won: 'border-emerald-200 bg-emerald-50 text-emerald-700',
                                    lost: 'border-rose-200 bg-rose-50 text-rose-700',
                                    no_go: 'border-amber-200 bg-amber-50 text-amber-800',
                                    withdrawn: 'border-slate-200 bg-slate-50 text-slate-700',
                                    archived: 'border-slate-200 bg-slate-100 text-slate-600',
                                };

                                return (
                                    <InfoTile
                                        key={item.key}
                                        title={item.label}
                                        infoKey={`outcome_${item.key}`}
                                        openInfoKey={openInfoKey}
                                        setOpenInfoKey={setOpenInfoKey}
                                        className={classNames('px-3 py-3', palette[item.key] ?? palette.archived)}
                                        titleClassName="text-[11px] font-semibold uppercase tracking-[0.12em]"
                                        dense
                                    >
                                        <div className="mt-1.5 text-2xl font-semibold tracking-tight">
                                            {formatNumber(item.count, locale)}
                                        </div>
                                    </InfoTile>
                                );
                            })}
                        </div>
                    </Card>
                </div>
            </div>
        </div>
    );
}
