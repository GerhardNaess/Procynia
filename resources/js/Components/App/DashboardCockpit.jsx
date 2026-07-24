import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import InfoHint from './InfoHint';
import PageHelpButton from './PageHelpButton';

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

function formatDate(value, locale, notAvailableText, options = {}) {
    if (!value) {
        return notAvailableText;
    }

    return new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        ...options,
    }).format(new Date(value));
}

function formatRelativeTime(value, locale = 'nb-NO', noActivityText) {
    if (!value) {
        return noActivityText;
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

// Adapter that preserves the internal API used by InfoTile and Card while delegating
// rendering to the shared InfoHint component. The openInfoKey/setOpenInfoKey props
// are no longer needed but are accepted so existing call sites do not need to change.
function InfoButton({ infoKey, title, infoText, texts = {} }) {
    const resolvedInfoText = infoText ?? texts.info_texts?.[infoKey];
    if (!resolvedInfoText) return null;
    return (
        <InfoHint
            label={`${texts.info_prefix ?? 'Vis forklaring for'} ${title}`}
            text={resolvedInfoText}
        />
    );
}

function InfoTile({
    title,
    infoKey,
    infoText,
    openInfoKey,
    setOpenInfoKey,
    texts = {},
    className = '',
    titleClassName = 'text-xs font-semibold uppercase tracking-[0.12em] text-slate-600',
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
                    <InfoButton infoKey={infoKey} title={title} infoText={infoText} openInfoKey={openInfoKey} setOpenInfoKey={setOpenInfoKey} texts={texts} />
                ) : null}
            </div>
            <div className={classNames(dense ? 'mt-1.5' : 'mt-2')}>
                {children}
            </div>
        </div>
    );
}

function Card({ title, subtitle, infoKey, infoText, action, children, className = '', openInfoKey, setOpenInfoKey, texts = {}, dense = false }) {
    return (
        <section className={classNames('rounded-[24px] border border-slate-200 bg-white/90 shadow-[0_10px_30px_rgba(15,23,42,0.05)]', dense ? 'p-4' : 'p-5', className)}>
            <div className={classNames('flex items-start justify-between gap-4', dense ? 'mb-3' : 'mb-4')}>
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <div className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">
                            {title}
                        </div>
                        {infoKey ? (
                            <InfoButton infoKey={infoKey} title={title} infoText={infoText} openInfoKey={openInfoKey} setOpenInfoKey={setOpenInfoKey} texts={texts} />
                        ) : null}
                    </div>
                    {subtitle ? (
                        <p className={classNames('mt-1 text-base text-slate-600', dense ? 'leading-5' : 'leading-6')}>
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
        <span className={classNames('inline-flex min-w-12 items-center justify-center rounded-full border px-2.5 py-1 text-sm font-semibold', palette[severity] ?? palette.neutral)}>
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

function AttentionCaseRow({ item, texts = {} }) {
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
                <div className="truncate text-base font-semibold text-slate-950">
                    {item.title}
                </div>
                <div className="mt-0.5 text-sm font-semibold text-slate-700">
                    {item.reason}
                </div>
                <div className="mt-0.5 text-sm leading-5 text-slate-600">
                    {item.secondary}
                </div>
            </div>
            <span className="mt-1 text-sm font-semibold uppercase tracking-[0.12em] text-violet-700 opacity-0 transition group-hover:opacity-100">
                {texts.open_case}
            </span>
        </Link>
    );
}

function AttentionCategoryPanel({ item, isOpen, onToggle, texts = {} }) {
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
                    <div className="text-base font-semibold text-slate-950">
                        {item.title}
                    </div>
                    <div className="mt-0.5 text-sm leading-5 text-slate-600">
                        {item.subtitle}
                    </div>
                </div>
                <AttentionPill severity={item.severity} count={item.count} />
                <span
                    className={classNames(
                        'inline-flex h-6 w-6 items-center justify-center rounded-full border border-slate-300 bg-white/80 text-lg leading-none text-slate-500 transition-transform',
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
                                <AttentionCaseRow key={attentionCase.id} item={attentionCase} texts={texts} />
                            ))}
                        </div>
                    ) : (
                        <div className="rounded-xl border border-dashed border-slate-200 bg-white px-3 py-3 text-base text-slate-600">
                            {texts.no_category_items}
                        </div>
                    )}
                </div>
            ) : null}
        </div>
    );
}

function formatMetricValue(metric, locale, texts = {}) {
    if (metric?.value === null || metric?.value === undefined) {
        return '—';
    }

    if (metric.unit === '%') {
        return formatPercent(metric.value, locale);
    }

    if (metric.unit === 'dager') {
        return `${formatNumber(metric.value, locale)} ${texts.days}`;
    }

    if (metric.unit === 'saker') {
        return `${formatNumber(metric.value, locale)} ${texts.cases}`;
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

function BidQualityMetricTile({ metric, locale, openInfoKey, setOpenInfoKey, texts = {}, spanFullWidth = false }) {
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
            texts={texts}
            openInfoKey={openInfoKey}
            setOpenInfoKey={setOpenInfoKey}
            className={classNames('px-4 py-3', palette[metric.severity] ?? palette.neutral, spanFullWidth ? 'sm:col-span-2' : '')}
            dense
        >
            <div className="text-2xl font-semibold tracking-tight text-slate-950">
                {formatMetricValue(metric, locale, texts.units)}
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
                        {' '}
                        {texts.connector_of}
                        {' '}
                        {formatNumber(metric.denominator, locale)}
                    </span>
                ) : metric.sample_size !== undefined ? (
                    <span>
                        {formatNumber(metric.sample_size, locale)}
                        {texts.sample_basis_suffix}
                    </span>
                ) : null}
            </div>
            {Array.isArray(metric.breakdown) && metric.breakdown.length > 0 ? (
                <QualityBreakdownChips breakdown={metric.breakdown} locale={locale} />
            ) : null}
        </InfoTile>
    );
}

function BidQualityTrendTile({ metric, locale, openInfoKey, setOpenInfoKey, texts = {}, spanFullWidth = false }) {
    const series = Array.isArray(metric.series) ? metric.series : [];
    const maxMedian = Math.max(...series.map((point) => Number(point.median_days ?? 0)), 1);

    return (
        <InfoTile
            title={metric.title}
            infoKey={`bid_quality_${metric.key}`}
            infoText={metric.definition}
            texts={texts}
            openInfoKey={openInfoKey}
            setOpenInfoKey={setOpenInfoKey}
            className={classNames('px-4 py-3', 'border-slate-200 bg-slate-50/70', spanFullWidth ? 'sm:col-span-2' : '')}
            dense
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="text-sm font-semibold text-slate-950">
                        {metric.subtitle ?? texts.default_trend_subtitle}
                    </div>
                    <div className="mt-0.5 text-[11px] leading-4 text-slate-500">
                        {texts.trend_hint}
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
                                title={`${point.label} ${point.month} · ${texts.tooltips?.median_label} ${formatNumber(point.median_days, locale)} ${texts.units?.days} · ${texts.tooltips?.basis_label} ${formatNumber(point.sample_size, locale)}`}
                            >
                                <div className="mb-1 text-xs font-semibold text-slate-900">
                                    {formatNumber(point.median_days, locale)}
                                    {' '}
                                    {texts.units?.days_short}
                                </div>
                                <div className="flex h-32 w-full items-end rounded-xl bg-slate-100 px-1.5">
                                    <div
                                        className="w-full rounded-t-xl bg-violet-500"
                                        style={{ height: `${barHeight}px` }}
                                    />
                                </div>
                                <div className="mt-1 text-center text-xs font-semibold text-slate-900">
                                    {point.label}
                                </div>
                                <div className="text-center text-xs text-slate-600">
                                    {texts.tooltips?.sample_prefix}
                                    {formatNumber(point.sample_size, locale)}
                                </div>
                            </div>
                        );
                    })}
                </div>
            ) : (
                <div className="mt-4 rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-4 text-base text-slate-600">
                    {texts.no_monthly_points}
                </div>
            )}
        </InfoTile>
    );
}

function DeadlinePopover({ items, locale, texts = {}, commonText = {} }) {
    return (
        <div className="absolute left-0 top-full z-20 mt-2 hidden w-72 rounded-2xl border border-slate-200 bg-white p-3 shadow-[0_20px_40px_rgba(15,23,42,0.12)] group-hover:block group-focus-within:block">
            <div className="mb-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                {texts.title}
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
                            {formatDate(item.date, locale, commonText.not_available, { day: 'numeric', month: 'short' })}
                        </div>
                        <div className="mt-1 text-xs leading-5 text-slate-500">
                            {item.bid_manager_name ?? texts.no_bid_manager}
                            {item.phase_label ? ` · ${item.phase_label}` : ''}
                        </div>
                    </Link>
                ))}
            </div>
        </div>
    );
}

export default function DashboardCockpit({ cockpit, locale = 'nb-NO', texts = {}, commonText = {} }) {
    const dashboardText = texts ?? {};
    const sharedText = commonText ?? {};
    const {
        page_title: pageTitle,
        page_subtitle: pageSubtitle,
        sections: sectionsText = {},
        calendar: calendarText = {},
        actions: actionsText = {},
        empty_states: emptyStatesText = {},
        metrics: metricsText = {},
        units: unitsText = {},
        popovers: popoversText = {},
    } = dashboardText;
    const [openInfoKey, setOpenInfoKey] = useState(null);
    const [openAttentionKey, setOpenAttentionKey] = useState(null);
    const portfolio = cockpit?.portfolio ?? { total: 0, active: 0, outcome: 0 };
    const attentionItems = cockpit?.attention?.items ?? [];
    const bidQuality = cockpit?.bid_quality ?? {
        title: sectionsText.bid_quality?.title,
        subtitle: sectionsText.bid_quality?.subtitle,
        items: [],
    };
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
                <div className="flex items-center gap-3">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                        {pageTitle}
                    </h1>
                    <PageHelpButton
                        buttonLabel={texts.page_help_button ?? 'Hjelp'}
                        title={texts.page_help_title ?? 'Om dashboardet'}
                        intro={texts.page_help_intro ?? 'Dashboardet gir deg et daglig cockpit-blikk over portefølje, frister, ansvar og bid-fremdrift.'}
                        sections={[
                            {
                                title: texts.page_help_section_widgets ?? 'Hva du ser her',
                                items: [
                                    {
                                        title: texts.page_help_item_pipeline_title ?? 'Pipeline-stadier',
                                        text: texts.page_help_item_pipeline_text ?? 'Viser antall saker i hvert trinn av bid-prosessen.',
                                    },
                                    {
                                        title: texts.page_help_item_attention_title ?? 'Oppmerksomhet nå',
                                        text: texts.page_help_item_attention_text ?? 'Saker som krever rask handling: nær frist, mangler ansvarlig eller ikke startet.',
                                    },
                                    {
                                        title: texts.page_help_item_calendar_title ?? 'Bidkalender',
                                        text: texts.page_help_item_calendar_text ?? 'Planlagte frister og Business Reviews sortert per måned.',
                                    },
                                ],
                            },
                        ]}
                    />
                </div>
                <p className="max-w-4xl text-base leading-7 text-slate-600">
                    {pageSubtitle}
                </p>
            </section>

            <div className="grid gap-5 xl:grid-cols-12 xl:items-stretch">
                <div className="xl:col-span-6 h-full flex flex-col">
                    <Card
                        title={sectionsText.attention?.title}
                        subtitle={sectionsText.attention?.subtitle}
                        infoKey="attention"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full border-rose-200 bg-rose-50/70"
                        dense
                        texts={dashboardText}
                    >
                        <div className="space-y-2">
                            {attentionItems.length > 0 ? attentionItems.map((item) => (
                                <AttentionCategoryPanel
                                    key={item.key}
                                    item={item}
                                    isOpen={openAttentionKey === item.key}
                                    onToggle={() => setOpenAttentionKey((current) => (current === item.key ? null : item.key))}
                                    texts={{
                                        open_case: actionsText.open_case,
                                        no_category_items: emptyStatesText.no_category_items,
                                    }}
                                />
                            )) : (
                                <div className="rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-4 text-sm text-slate-500">
                                    {emptyStatesText.attention}
                                </div>
                            )}
                        </div>
                    </Card>
                </div>

                <div className="xl:col-span-6 h-full flex flex-col">
                    <Card
                        title={sectionsText.deadlines?.title}
                        subtitle={`${sectionsText.deadlines?.subtitle_prefix} ${calendar.monthLabel}`}
                        infoKey="deadlines"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full border-violet-200 bg-violet-50/60"
                        dense
                        texts={dashboardText}
                    >
                        <div className="space-y-3">
                            <div className="rounded-2xl border border-slate-200 bg-white p-2.5">
                                <div className="flex flex-col gap-2.5">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="flex items-center gap-2">
                                            <button
                                                type="button"
                                                aria-label={calendarText.previous_month}
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
                                                aria-label={calendarText.next_month}
                                                onClick={() => setVisibleMonthStart((current) => addMonths(current, 1))}
                                                className="inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-600 transition hover:border-violet-300 hover:text-violet-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-300"
                                            >
                                                →
                                            </button>
                                        </div>
                                        <div className="text-xs text-slate-600">
                                            {calendarText.hover_hint}
                                        </div>
                                    </div>

                                    <div className="grid gap-2 md:grid-cols-[minmax(0,0.9fr)_minmax(0,0.8fr)_minmax(0,1.1fr)]">
                                        <label className="block">
                                            <span className="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                {calendarText.month_label}
                                            </span>
                                            <select
                                                value={String(visibleMonthStart.getMonth())}
                                                onChange={(event) => {
                                                    const nextMonth = Number(event.target.value);
                                                    setVisibleMonthStart(new Date(visibleMonthStart.getFullYear(), nextMonth, 1));
                                                }}
                                                className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-700 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-200"
                                            >
                                                {monthOptions.map((option) => (
                                                    <option key={option.value} value={option.value}>
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>

                                        <label className="block">
                                            <span className="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                {calendarText.year_label}
                                            </span>
                                            <select
                                                value={calendar.yearValue}
                                                onChange={(event) => {
                                                    const nextYear = Number(event.target.value);
                                                    setVisibleMonthStart(new Date(nextYear, visibleMonthStart.getMonth(), 1));
                                                }}
                                                className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-700 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-200"
                                            >
                                                {calendarYearOptions.map((year) => (
                                                    <option key={year} value={String(year)}>
                                                        {year}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>

                                        <div>
                                            <label className="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-600" htmlFor="deadline-date-jump">
                                                {calendarText.jump_label}
                                            </label>
                                            <div className="flex gap-2">
                                                <input
                                                    id="deadline-date-jump"
                                                    type="date"
                                                    value={jumpDateValue}
                                                    onChange={(event) => setJumpDateValue(event.target.value)}
                                                    className="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-700 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-200"
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
                                                    className="inline-flex shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-base font-medium text-slate-700 transition hover:border-violet-300 hover:bg-violet-50 hover:text-violet-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-200"
                                                >
                                                    {actionsText.show}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div className="mt-2 grid grid-cols-7 gap-0.5 text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">
                                    {calendarText.weekdays?.map((label) => (
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
                                                    day.inCurrentMonth ? 'border-slate-200 bg-white' : 'border-slate-100 bg-slate-50/70 text-slate-400',
                                                    day.isToday ? 'ring-2 ring-violet-200' : '',
                                                    isSelected ? 'border-violet-400 bg-violet-50' : '',
                                                )}
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="text-xs font-semibold">
                                                        {day.dayOfMonth}
                                                    </div>
                                                    {items.length > 0 ? (
                                                        <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-violet-100 px-1 text-xs font-semibold text-violet-700">
                                                            {items.length}
                                                        </span>
                                                    ) : null}
                                                </div>

                                                {items.length > 0 ? (
                                                    <DeadlinePopover items={items} locale={locale} texts={{
                                                        title: popoversText.deadlines_title,
                                                        no_bid_manager: emptyStatesText.no_bid_manager,
                                                    }} commonText={sharedText} />
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
                        title={sectionsText.portfolio?.title}
                        subtitle={sectionsText.portfolio?.subtitle}
                        infoKey="portfolio"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full"
                        texts={dashboardText}
                    >
                        <div className="grid gap-3">
                            {[
                                {
                                    label: metricsText.portfolio_total_title,
                                    description: metricsText.portfolio_total_description,
                                    value: portfolio.total,
                                    infoKey: 'portfolio_total',
                                },
                                {
                                    label: metricsText.portfolio_active_title,
                                    description: metricsText.portfolio_active_description,
                                    value: portfolio.active,
                                    infoKey: 'portfolio_active',
                                },
                                {
                                    label: metricsText.portfolio_outcome_title,
                                    description: metricsText.portfolio_outcome_description,
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
                        title={bidQuality.title}
                        subtitle={bidQuality.subtitle}
                        infoKey="bid_quality"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full"
                        dense
                        texts={dashboardText}
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
                                        texts={dashboardText}
                                        spanFullWidth
                                    />
                                ) : (
                                    <BidQualityMetricTile
                                        key={metric.key}
                                        metric={metric}
                                        locale={locale}
                                        openInfoKey={openInfoKey}
                                        setOpenInfoKey={setOpenInfoKey}
                                        texts={dashboardText}
                                    />
                                )
                            ))}
                        </div>

                        {(bidQuality.items ?? []).length === 0 ? (
                            <div className="mt-2 rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-4 text-sm text-slate-500">
                                {emptyStatesText.no_bid_quality}
                            </div>
                        ) : null}
                    </Card>
                </div>

                <div className="xl:col-span-4">
                    <Card
                        title={sectionsText.responsibility_activity?.title}
                        subtitle={sectionsText.responsibility_activity?.subtitle}
                        infoKey="responsibility_activity"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full"
                        texts={dashboardText}
                    >
                        <div className="space-y-3">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <InfoTile
                                    title={metricsText.responsibility_bid_manager_cases}
                                    infoKey="responsibility_bid_manager_cases"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-slate-50/70 px-4 py-3"
                                    texts={dashboardText}
                                >
                                    <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                        {formatNumber(responsibility.bid_manager_cases_count ?? 0, locale)}
                                    </div>
                                </InfoTile>
                                <InfoTile
                                    title={metricsText.responsibility_opportunity_owner_cases}
                                    infoKey="responsibility_opportunity_owner_cases"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-slate-50/70 px-4 py-3"
                                    texts={dashboardText}
                                >
                                    <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                        {formatNumber(responsibility.opportunity_owner_cases_count ?? 0, locale)}
                                    </div>
                                </InfoTile>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <InfoTile
                                    title={metricsText.responsibility_saved_watch_lists}
                                    infoKey="responsibility_saved_watch_lists"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-slate-50/70 px-4 py-3"
                                    texts={dashboardText}
                                >
                                    <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                        {formatNumber(responsibility.saved_watch_lists_count ?? 0, locale)}
                                    </div>
                                </InfoTile>
                                <InfoTile
                                    title={metricsText.responsibility_contributor_cases}
                                    infoKey="responsibility_contributor_cases"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-slate-50/70 px-4 py-3"
                                    texts={dashboardText}
                                >
                                    <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                        {formatNumber(responsibility.contributor_cases_count ?? 0, locale)}
                                    </div>
                                </InfoTile>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <InfoTile
                                    title={metricsText.responsibility_last_activity}
                                    infoKey="responsibility_last_activity"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-violet-50/70 px-4 py-3 sm:col-span-2"
                                    texts={dashboardText}
                                >
                                    <div className="mt-1 text-base font-semibold text-slate-950">
                                        {formatRelativeTime(responsibility.activity.last_activity_at, locale, emptyStatesText.no_activity)}
                                    </div>
                                </InfoTile>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <InfoTile
                                    title={metricsText.responsibility_activity_14_days}
                                    infoKey="responsibility_activity_14_days"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-violet-50/70 px-4 py-3"
                                    texts={dashboardText}
                                >
                                    <div className="mt-1 text-2xl font-semibold tracking-tight text-slate-950">
                                        {formatNumber(responsibility.activity.activity_count_14_days ?? 0, locale)}
                                    </div>
                                </InfoTile>
                                <InfoTile
                                    title={metricsText.responsibility_inactive_7_days}
                                    infoKey="responsibility_inactive_7_days"
                                    openInfoKey={openInfoKey}
                                    setOpenInfoKey={setOpenInfoKey}
                                    className="border-slate-200 bg-amber-50/70 px-4 py-3"
                                    texts={dashboardText}
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
                        title={sectionsText.pipeline_stages?.title}
                        subtitle={sectionsText.pipeline_stages?.subtitle}
                        infoKey="pipeline_stages"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full"
                        dense
                        texts={dashboardText}
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
                                        texts={dashboardText}
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <div>
                                                <div className="text-[11px] leading-4 text-slate-500">
                                                    {metricsText.pipeline_average_age_label}
                                                </div>
                                            </div>
                                            <div className="text-right">
                                                <div className="text-base font-semibold text-slate-950">
                                                    {formatNumber(stage.count, locale)}
                                                </div>
                                                <div className="text-[11px] leading-4 text-slate-500">
                                                    {stage.average_age_hours === null ? emptyStatesText.no_measurement : `${formatNumber(stage.average_age_hours, locale)} ${unitsText.hours_short} ${unitsText.average_suffix}`}
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
                        title={sectionsText.outcomes?.title}
                        subtitle={sectionsText.outcomes?.subtitle}
                        infoKey="outcomes"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        className="h-full"
                        dense
                        texts={dashboardText}
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
