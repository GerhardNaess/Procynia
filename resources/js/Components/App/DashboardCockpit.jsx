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

function buildCalendarDays(monthStartIso) {
    const monthStart = monthStartIso ? new Date(monthStartIso) : new Date();
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
        monthLabel: new Intl.DateTimeFormat('nb-NO', {
            month: 'long',
            year: 'numeric',
        }).format(firstOfMonth),
        days,
    };
}

const DASHBOARD_INFO_TEXTS = {
    'attention_deadline-soon': 'Viser saker med operative frister som nærmer seg eller er passert.',
    'attention_missing-bid-manager': 'Viser saker som mangler eksplisitt operativt ansvar.',
    'attention_go-no-go-pending': 'Viser saker i beslutningsfase uten endelig utfall.',
    'attention_inactive-seven-days': 'Viser saker uten kommentarer eller innsendinger de siste 7 dagene.',
    attention: 'Viser sakene som bør følges opp først. Tallene samler frister, ansvar, beslutninger og aktivitet som krever handling nå.',
    deadlines: 'Viser markerte operative frister i valgt måned. Bruk kalenderen til å finne frister som nærmer seg eller allerede er passert.',
    portfolio: 'Gir rask status på saksporteføljen. Bruk denne gruppen for å se totalvolum, aktive saker og saker som allerede har fått et registrert utfall.',
    portfolio_total: 'Viser alle saker i porteføljen, både aktive saker og saker som allerede er avsluttet.',
    portfolio_active: 'Viser saker som fortsatt følges opp operativt og ikke har nådd et registrert utfall.',
    portfolio_outcome: 'Viser saker der utfallet er registrert, inkludert vunnet, tapt, No-Go, trukket og arkivert.',
    pipeline_quality: 'Viser hvor sakene stopper opp og hvor raskt de går videre mellom hovedstegene.',
    pipeline_quality_qualifying_to_go_no_go: 'Andel saker som går fra kvalifisering til beslutningsfase.',
    pipeline_quality_go_no_go_to_in_progress: 'Andel saker som går fra beslutning til aktivt arbeid.',
    responsibility_activity: 'Viser hvem som har ansvar, og om porteføljen holdes i gang med jevn aktivitet.',
    responsibility_bid_manager_count: 'Antall saker som har en eksplisitt bid-manager.',
    responsibility_opportunity_owner_count: 'Antall saker som har en eksplisitt kommersiell eier.',
    responsibility_bid_manager_people: 'Hvilke bid-managere som har flest saker akkurat nå.',
    responsibility_opportunity_owner_people: 'Hvilke kommersielle eiere som har flest saker akkurat nå.',
    responsibility_last_comment: 'Hvor nylig det sist ble kommentert på en sak.',
    responsibility_activity_14_days: 'Hvor mange aktiviteter som har skjedd de siste 14 dagene.',
    responsibility_last_activity: 'Hvor nylig det sist var aktivitet i porteføljen.',
    responsibility_inactive_7_days: 'Saker som ikke har hatt aktivitet de siste 7 dagene.',
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

function InfoButton({ infoKey, title, openInfoKey, setOpenInfoKey }) {
    const isOpen = openInfoKey === infoKey;
    const infoText = DASHBOARD_INFO_TEXTS[infoKey];

    if (!infoText) {
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
                    {infoText}
                </div>
            ) : null}
        </span>
    );
}

function InfoTile({
    title,
    infoKey,
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
                    <InfoButton infoKey={infoKey} title={title} openInfoKey={openInfoKey} setOpenInfoKey={setOpenInfoKey} />
                ) : null}
            </div>
            <div className={classNames(dense ? 'mt-1.5' : 'mt-2')}>
                {children}
            </div>
        </div>
    );
}

function Card({ title, subtitle, infoKey, action, children, className = '', openInfoKey, setOpenInfoKey, dense = false }) {
    return (
        <section className={classNames('rounded-[24px] border border-slate-200 bg-white/90 shadow-[0_10px_30px_rgba(15,23,42,0.05)]', dense ? 'p-4' : 'p-5', className)}>
            <div className={classNames('flex items-start justify-between gap-4', dense ? 'mb-3' : 'mb-4')}>
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <div className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">
                            {title}
                        </div>
                        {infoKey ? (
                            <InfoButton infoKey={infoKey} title={title} openInfoKey={openInfoKey} setOpenInfoKey={setOpenInfoKey} />
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
    const portfolio = cockpit?.portfolio ?? { total: 0, active: 0, outcome: 0 };
    const attentionItems = cockpit?.attention?.items ?? [];
    const deadlines = cockpit?.deadlines ?? { month_start: null, month_label: '', items: [], upcoming: [] };
    const pipelineQuality = cockpit?.pipeline_quality ?? { conversions: [], stages: [], warning: null };
    const responsibility = cockpit?.responsibility_activity ?? {
        bid_managers: { assigned_count: 0, people: [] },
        opportunity_owners: { assigned_count: 0, people: [] },
        activity: {
            last_comment_at: null,
            last_activity_at: null,
            activity_count_14_days: 0,
            inactive_7_days_count: 0,
        },
    };
    const outcomes = cockpit?.outcomes ?? [];
    const deadlineGroups = useMemo(() => groupByDate(deadlines.items ?? []), [deadlines.items]);
    const calendar = useMemo(() => buildCalendarDays(deadlines.month_start), [deadlines.month_start]);
    const stageMax = Math.max(...(pipelineQuality.stages ?? []).map((stage) => Number(stage.count ?? 0)), 1);

    return (
        <div className="space-y-5">
            <section className="space-y-1.5">
                <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                    Oversikt
                </h1>
                <p className="max-w-4xl text-[15px] leading-7 text-slate-500">
                    Porteføljeoversikten gir deg et samlet cockpit-blikk på hvor sakene ligger i bid-prosessen akkurat nå.
                </p>
            </section>

            <div className="grid gap-6 xl:grid-cols-12 xl:items-stretch">
                <div className="xl:col-span-6 h-full flex flex-col">
                    <Card
                        title="Oppmerksomhet nå"
                        subtitle="Det som krever oppfølging først."
                        infoKey="attention"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full border-rose-200 bg-rose-50/70"
                    >
                        <div className="space-y-2.5">
                            {attentionItems.length > 0 ? attentionItems.map((item) => (
                                <Link
                                    key={item.key}
                                    href={item.href}
                                    className={classNames(
                                        'group flex items-center gap-4 rounded-2xl border px-4 py-3 transition',
                                        item.severity === 'danger'
                                            ? 'border-rose-200 bg-white hover:border-rose-300 hover:bg-rose-50'
                                            : item.severity === 'warning'
                                                ? 'border-amber-200 bg-white hover:border-amber-300 hover:bg-amber-50'
                                                : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50',
                                    )}
                                >
                                    <SeverityDot severity={item.severity} />
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-sm font-semibold text-slate-950">
                                            {item.title}
                                        </div>
                                        <div className="mt-0.5 text-xs leading-5 text-slate-500">
                                            {item.subtitle}
                                        </div>
                                    </div>
                                    <InfoButton
                                        infoKey={`attention_${item.key}`}
                                        title={item.title}
                                        openInfoKey={openInfoKey}
                                        setOpenInfoKey={setOpenInfoKey}
                                    />
                                    <AttentionPill severity={item.severity} count={item.count} />
                                    <span className="text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-slate-600">
                                        →
                                    </span>
                                </Link>
                            )) : (
                                <div className="rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-5 text-sm text-slate-500">
                                    Ingen saker krever umiddelbar oppmerksomhet.
                                </div>
                            )}
                        </div>
                    </Card>
                </div>

                <div className="xl:col-span-6 h-full flex flex-col">
                    <Card
                        title="Fristkalender"
                        subtitle={`Markerte frister for ${calendar.monthLabel}`}
                        infoKey="deadlines"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full border-violet-200 bg-violet-50/60"
                    >
                        <div className="space-y-4">
                            <div className="rounded-2xl border border-slate-200 bg-white p-3">
                                <div className="mb-2 flex items-center justify-between gap-3">
                                    <div className="text-sm font-semibold text-slate-900">
                                        {calendar.monthLabel}
                                    </div>
                                    <div className="text-xs text-slate-500">
                                        Hover markerte dager for detaljer
                                    </div>
                                </div>

                                <div className="grid grid-cols-7 gap-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">
                                    {['Man', 'Tir', 'Ons', 'Tor', 'Fre', 'Lør', 'Søn'].map((label) => (
                                        <div key={label} className="px-1 py-1 text-center">
                                            {label}
                                        </div>
                                    ))}
                                </div>

                                <div className="mt-1 grid grid-cols-7 gap-0.5">
                                    {calendar.days.map((day) => {
                                        const items = deadlineGroups[day.dateKey] ?? [];

                                        return (
                                            <div
                                                key={day.dateKey}
                                                className={classNames(
                                                    'group relative min-h-12 rounded-2xl border px-1 py-1 transition',
                                                    day.inCurrentMonth ? 'border-slate-200 bg-white' : 'border-slate-100 bg-slate-50/70 text-slate-300',
                                                    day.isToday ? 'ring-2 ring-violet-200' : '',
                                                )}
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="text-[11px] font-semibold">
                                                        {day.dayOfMonth}
                                                    </div>
                                                    {items.length > 0 ? (
                                                        <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-violet-100 px-1 text-[10px] font-semibold text-violet-700">
                                                            {items.length}
                                                        </span>
                                                    ) : null}
                                                </div>

                                                {items.length > 0 ? (
                                                    <DeadlinePopover items={items} locale={locale} />
                                                ) : null}
                                            </div>
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
                        title="Pipeline-kvalitet"
                        subtitle="Konvertering mellom hovedsteg."
                        infoKey="pipeline_quality"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full"
                        dense
                    >
                        <div className="grid gap-2 sm:grid-cols-2">
                            {(pipelineQuality.conversions ?? []).map((conversion) => (
                                <InfoTile
                                    key={conversion.key}
                                    title={conversion.label}
                                    infoKey={`pipeline_quality_${conversion.key}`}
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-white px-3 py-3"
                                    dense
                                >
                                    <div className="text-2xl font-semibold tracking-tight text-slate-950">
                                        {formatPercent(conversion.value, locale)}
                                    </div>
                                </InfoTile>
                            ))}
                        </div>

                        {pipelineQuality.warning ? (
                            <div className="mt-2 rounded-2xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900">
                                {pipelineQuality.warning.label}
                                {pipelineQuality.warning.count ? ` (${formatNumber(pipelineQuality.warning.count, locale)})` : ''}
                            </div>
                        ) : null}
                    </Card>
                </div>

                <div className="xl:col-span-4">
                    <Card
                        title="Ansvar & Aktivitet"
                        subtitle="Hvem som eier tempo og oppfølging."
                        infoKey="responsibility_activity"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full"
                    >
                        <div className="space-y-4">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <InfoTile
                                    title="Bid-manager"
                                    infoKey="responsibility_bid_manager_count"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-slate-50/70 px-4 py-3"
                                >
                                    <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                        {formatNumber(responsibility.bid_managers.assigned_count ?? 0, locale)}
                                    </div>
                                </InfoTile>
                                <InfoTile
                                    title="Kommersiell eier"
                                    infoKey="responsibility_opportunity_owner_count"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-slate-50/70 px-4 py-3"
                                >
                                    <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                        {formatNumber(responsibility.opportunity_owners.assigned_count ?? 0, locale)}
                                    </div>
                                </InfoTile>
                            </div>

                            <div className="grid gap-3 lg:grid-cols-2">
                                <InfoTile
                                    title="Ansvarlige bid-managere"
                                    infoKey="responsibility_bid_manager_people"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-white px-4 py-4"
                                >
                                    <div className="mt-3 space-y-2">
                                        {(responsibility.bid_managers.people ?? []).length > 0 ? responsibility.bid_managers.people.map((person) => (
                                            <div key={`bid-manager-${person.id}`} className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2">
                                                <div className="min-w-0">
                                                    <div className="truncate text-sm font-medium text-slate-950">
                                                        {person.name}
                                                    </div>
                                                    <div className="text-xs text-slate-500">
                                                        {person.count} saker
                                                    </div>
                                                </div>
                                                <div className="text-sm font-semibold text-slate-700">
                                                    {formatNumber(person.count, locale)}
                                                </div>
                                            </div>
                                        )) : (
                                            <div className="rounded-xl border border-dashed border-slate-200 px-3 py-4 text-sm text-slate-500">
                                                Ingen bid-managere med saker akkurat nå.
                                            </div>
                                        )}
                                    </div>
                                </InfoTile>

                                <InfoTile
                                    title="Kommersielle eiere"
                                    infoKey="responsibility_opportunity_owner_people"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-white px-4 py-4"
                                >
                                    <div className="mt-3 space-y-2">
                                        {(responsibility.opportunity_owners.people ?? []).length > 0 ? responsibility.opportunity_owners.people.map((person) => (
                                            <div key={`owner-${person.id}`} className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2">
                                                <div className="min-w-0">
                                                    <div className="truncate text-sm font-medium text-slate-950">
                                                        {person.name}
                                                    </div>
                                                    <div className="text-xs text-slate-500">
                                                        {person.count} saker
                                                    </div>
                                                </div>
                                                <div className="text-sm font-semibold text-slate-700">
                                                    {formatNumber(person.count, locale)}
                                                </div>
                                            </div>
                                        )) : (
                                            <div className="rounded-xl border border-dashed border-slate-200 px-3 py-4 text-sm text-slate-500">
                                                Ingen kommersielle eiere med saker akkurat nå.
                                            </div>
                                        )}
                                    </div>
                                </InfoTile>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-4">
                                <InfoTile
                                    title="Siste kommentar"
                                    infoKey="responsibility_last_comment"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-violet-50/70 px-4 py-3"
                                >
                                    <div className="mt-1 text-base font-semibold text-slate-950">
                                        {formatRelativeTime(responsibility.activity.last_comment_at, locale)}
                                    </div>
                                </InfoTile>
                                <InfoTile
                                    title="Aktivitet 14 dager"
                                    infoKey="responsibility_activity_14_days"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-violet-50/70 px-4 py-3"
                                >
                                    <div className="mt-1 text-base font-semibold text-slate-950">
                                        {formatNumber(responsibility.activity.activity_count_14_days ?? 0, locale)}
                                    </div>
                                </InfoTile>
                                <InfoTile
                                    title="Siste aktivitet"
                                    infoKey="responsibility_last_activity"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-violet-50/70 px-4 py-3"
                                >
                                    <div className="mt-1 text-base font-semibold text-slate-950">
                                        {formatRelativeTime(responsibility.activity.last_activity_at, locale)}
                                    </div>
                                </InfoTile>
                                <InfoTile
                                    title="Uten aktivitet 7 dager"
                                    infoKey="responsibility_inactive_7_days"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-amber-50/70 px-4 py-3"
                                >
                                    <div className="mt-1 text-base font-semibold text-slate-950">
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
                        title="Pipeline-kvalitet"
                        subtitle="Flyt, tempo og hvor sakene stopper opp."
                        infoKey="pipeline_stages"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full"
                        dense
                    >
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            {(pipelineQuality.stages ?? []).map((stage) => {
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
