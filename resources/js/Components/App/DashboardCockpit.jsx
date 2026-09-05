import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import InfoHint from './InfoHint';
import PageHelpButton from './PageHelpButton';
import {
    followUpSignals as buildFollowUpSignals,
    hasAnyOutcome as outcomesHaveAnyCount,
    managementRows as buildManagementRows,
    visibleOutcomes as buildVisibleOutcomes,
    winRateMetric,
} from './dashboardLogic';

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

// Adapter that preserves the internal API used by Card while delegating
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

function getDashboardHelpSections(texts = {}) {
    return [
        {
            title: texts.page_help_section_widgets ?? 'Hva du ser her',
            items: [
                {
                    title: texts.page_help_item_follow_up_title ?? 'Krever oppfølging',
                    text: texts.page_help_item_follow_up_text ?? 'Signaler som venter på en handling. Tallet viser hvor mange saker som ligger bak signalet, og du kan velge et signal for å se hvilke saker det gjelder.',
                },
                {
                    title: texts.page_help_item_pipeline_title ?? 'Pipeline',
                    text: texts.page_help_item_pipeline_text ?? 'Hvor mange aktive saker som står i hver av de seks fasene. Gir et raskt bilde av hvor porteføljen ligger akkurat nå.',
                },
                {
                    title: texts.page_help_item_management_title ?? 'Styring',
                    text: texts.page_help_item_management_text ?? 'Sekundær oversikt over ansvar og aktivitet: hvor mange aktive saker som har kommersiell eier og Bid Manager, og hvor mange aktiviteter som er registrert de siste 14 dagene.',
                },
                {
                    title: texts.page_help_item_results_title ?? 'Resultater',
                    text: texts.page_help_item_results_text ?? 'Avsluttede saker fordelt på vunnet, tapt, No-Go og trukket. Win rate vises bare når noen saker faktisk er avsluttet.',
                },
                {
                    title: texts.page_help_item_calendar_title ?? 'Bidkalender',
                    text: texts.page_help_item_calendar_text ?? 'Registrerte frister og Business Reviews i valgt måned.',
                },
            ],
        },
    ];
}

const OUTCOME_TONE = {
    won: 'text-emerald-700',
    lost: 'text-rose-700',
    no_go: 'text-amber-700',
    withdrawn: 'text-slate-600',
    archived: 'text-slate-600',
};

function SectionHeading({ title, subtitle, infoKey, infoText, openInfoKey, setOpenInfoKey, texts = {} }) {
    return (
        <div className="flex items-start justify-between gap-4">
            <div className="min-w-0">
                <div className="flex items-center gap-2">
                    <h2 className="text-xl font-semibold tracking-tight text-slate-950">{title}</h2>
                    {infoKey ? (
                        <InfoButton infoKey={infoKey} title={title} infoText={infoText} openInfoKey={openInfoKey} setOpenInfoKey={setOpenInfoKey} texts={texts} />
                    ) : null}
                </div>
                {subtitle ? (
                    <p className="mt-1 text-base leading-6 text-slate-600">{subtitle}</p>
                ) : null}
            </div>
        </div>
    );
}

/**
 * One follow-up signal: a single number and a one-line reason. A signal at zero stays on the board
 * — it is still a management answer — but recedes visually so a real one stands out.
 */
function FollowUpSignal({ signal, locale, isOpen, onToggle, actionTexts = {} }) {
    const isActive = Number(signal.count ?? 0) > 0;
    const tone = {
        danger: 'border-rose-200 bg-rose-50/70',
        warning: 'border-amber-200 bg-amber-50/70',
        neutral: 'border-slate-200 bg-white',
    };

    return (
        <button
            type="button"
            aria-expanded={isOpen}
            onClick={onToggle}
            className={classNames(
                'flex h-full w-full flex-col rounded-[24px] border px-4 py-4 text-left transition',
                isActive ? (tone[signal.severity] ?? tone.neutral) : 'border-slate-200 bg-white/60',
                isOpen ? 'ring-2 ring-violet-200' : 'hover:border-slate-300',
            )}
        >
            <div className={classNames(
                'text-xs font-semibold uppercase tracking-[0.14em]',
                isActive ? 'text-slate-700' : 'text-slate-500',
            )}>
                {signal.label}
            </div>
            <div className={classNames(
                'mt-2 text-4xl font-semibold tabular-nums',
                isActive ? 'text-slate-950' : 'text-slate-400',
            )}>
                {formatNumber(signal.count, locale)}
            </div>
            <p className={classNames(
                'mt-2 text-base leading-6',
                isActive ? 'text-slate-700' : 'text-slate-500',
            )}>
                {signal.description}
            </p>
            {isActive ? (
                <span className="mt-3 inline-flex items-center gap-1 text-base font-semibold text-violet-700">
                    {isOpen ? actionTexts.hide : actionTexts.show}
                </span>
            ) : null}
        </button>
    );
}

function PipelineStage({ stage, locale, stageMax }) {
    const count = Number(stage.count ?? 0);
    const width = stageMax > 0 ? Math.round((count / stageMax) * 100) : 0;

    return (
        <div className="rounded-2xl border border-slate-200 bg-slate-50/70 px-3 py-3">
            <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">
                {stage.label}
            </div>
            <div className={classNames(
                'mt-1 text-2xl font-semibold tabular-nums',
                count > 0 ? 'text-slate-950' : 'text-slate-400',
            )}>
                {formatNumber(count, locale)}
            </div>
            <div className="mt-2 h-1.5 rounded-full bg-slate-200">
                <div
                    className="h-1.5 rounded-full bg-violet-500 transition-all"
                    style={{ width: `${Math.max(count > 0 ? 6 : 0, width)}%` }}
                />
            </div>
        </div>
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
        popovers: popoversText = {},
    } = dashboardText;
    const [openInfoKey, setOpenInfoKey] = useState(null);
    const [openAttentionKey, setOpenAttentionKey] = useState(null);
    const attentionItems = cockpit?.attention?.items ?? [];
    // Only the coverage metrics are read from this now; the old card's title is gone with it.
    const bidQuality = cockpit?.bid_quality ?? { items: [] };
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
    const redesignText = dashboardText.redesign ?? {};
    const infoTextsText = dashboardText.info_texts ?? {};

    // The four signals that carry an action, in priority order. They already arrive from the
    // backend with a title, a one-line reason and their own drill-down list.
    // Short display labels; the backend's longer titles stay as the explanatory line.
    const followUpSignals = buildFollowUpSignals(
        attentionItems,
        {
            'go-no-go-pending': redesignText.signal_go_no_go,
            'missing-bid-manager': redesignText.signal_missing_bid_manager,
            'deadline-soon': redesignText.signal_deadline_soon,
            'inactive-seven-days': redesignText.signal_inactive,
        },
        {
            'go-no-go-pending': redesignText.signal_go_no_go_description,
            'missing-bid-manager': redesignText.signal_missing_bid_manager_description,
            'deadline-soon': redesignText.signal_deadline_soon_description,
            'inactive-seven-days': redesignText.signal_inactive_description,
        },
    );
    const openSignal = followUpSignals.find((signal) => signal.key === openAttentionKey) ?? null;
    const managementRows = buildManagementRows(
        bidQuality,
        responsibility,
        {
            opportunity_owner: redesignText.management_opportunity_owner,
            bid_manager: redesignText.management_bid_manager,
            activity_14_days: redesignText.management_activity_14_days,
        },
        emptyStatesText.no_measurement,
    );
    const visibleOutcomes = buildVisibleOutcomes(outcomes);
    const hasAnyOutcome = outcomesHaveAnyCount(outcomes);
    const winRate = winRateMetric(bidQuality);

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
                        intro={texts.page_help_intro ?? 'Dashboardet svarer på tre spørsmål: hva krever oppfølging nå, hvor ligger sakene, og hva ble resultatet.'}
                        sections={getDashboardHelpSections(texts)}
                    />
                </div>
                <p className="max-w-4xl text-base leading-7 text-slate-600">
                    {pageSubtitle}
                </p>
            </section>

            <div className="space-y-3">
                <SectionHeading
                    title={redesignText.follow_up_title}
                    subtitle={redesignText.follow_up_subtitle}
                    infoKey="attention"
                    infoText={infoTextsText.attention}
                    openInfoKey={openInfoKey}
                    setOpenInfoKey={setOpenInfoKey}
                    texts={dashboardText}
                />

                {followUpSignals.length > 0 ? (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {followUpSignals.map((signal) => (
                            <FollowUpSignal
                                key={signal.key}
                                signal={signal}
                                locale={locale}
                                isOpen={openAttentionKey === signal.key}
                                onToggle={() => setOpenAttentionKey(openAttentionKey === signal.key ? null : signal.key)}
                                actionTexts={actionsText}
                            />
                        ))}
                    </div>
                ) : (
                    <p className="rounded-[24px] border border-dashed border-slate-200 bg-white/70 px-5 py-6 text-base text-slate-600">
                        {redesignText.follow_up_clear}
                    </p>
                )}

                {openSignal ? (
                    <Card
                        title={openSignal.title}
                        subtitle={openSignal.subtitle}
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        texts={dashboardText}
                        action={(
                            <button
                                type="button"
                                onClick={() => setOpenAttentionKey(null)}
                                className="inline-flex min-h-9 items-center justify-center rounded-full border border-slate-200 bg-white px-3 py-1.5 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                            >
                                {commonText.close ?? 'Lukk'}
                            </button>
                        )}
                    >
                        {openSignal.items.length > 0 ? (
                            <div className="space-y-2">
                                {openSignal.items.map((attentionCase) => (
                                    <AttentionCaseRow key={attentionCase.id} item={attentionCase} texts={actionsText} />
                                ))}
                            </div>
                        ) : (
                            <div className="rounded-xl border border-dashed border-slate-200 bg-white px-3 py-3 text-base text-slate-600">
                                {emptyStatesText.no_category_items}
                            </div>
                        )}
                    </Card>
                ) : null}
            </div>

            <div className="grid gap-4 xl:grid-cols-12">
                <div className="xl:col-span-8">
                    <Card
                        title={redesignText.pipeline_title}
                        subtitle={redesignText.pipeline_subtitle}
                        infoKey="pipeline_stages"
                        infoText={infoTextsText.pipeline_stages}
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        texts={dashboardText}
                    >
                        <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 xl:grid-cols-6">
                            {pipelineStages.map((stage) => (
                                <PipelineStage
                                    key={stage.key}
                                    stage={stage}
                                    locale={locale}
                                    stageMax={stageMax}
                                />
                            ))}
                        </div>
                    </Card>
                </div>

                <div className="xl:col-span-4">
                    <Card
                        title={redesignText.management_title}
                        infoKey="management"
                        infoText={infoTextsText.management}
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        texts={dashboardText}
                    >
                        <dl className="space-y-1">
                            {managementRows.map((row) => (
                                <div
                                    key={row.key}
                                    className="flex items-baseline justify-between gap-3 border-b border-slate-100 py-2 last:border-b-0"
                                >
                                    <dt className="min-w-0 text-base text-slate-600">{row.label}</dt>
                                    <dd className="shrink-0 text-base font-semibold text-slate-950">{row.value}</dd>
                                </div>
                            ))}
                        </dl>
                    </Card>
                </div>
            </div>

            <div className="grid gap-5 xl:grid-cols-12 xl:items-stretch">
                <div className="xl:col-span-8 h-full flex flex-col">
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

                <div className="xl:col-span-4">
                    <Card
                        title={redesignText.results_title}
                        subtitle={redesignText.results_subtitle}
                        infoKey="outcomes"
                        infoText={infoTextsText.outcomes}
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        texts={dashboardText}
                    >
                        {hasAnyOutcome ? (
                            <div className="grid grid-cols-2 gap-2.5">
                                {visibleOutcomes.map((outcome) => (
                                    <div
                                        key={outcome.key}
                                        className="rounded-2xl border border-slate-200 bg-slate-50/70 px-3 py-3"
                                    >
                                        <div className={classNames(
                                            'text-xs font-semibold uppercase tracking-[0.12em]',
                                            OUTCOME_TONE[outcome.key] ?? 'text-slate-600',
                                        )}>
                                            {outcome.label}
                                        </div>
                                        <div className="mt-1 text-2xl font-semibold text-slate-950">
                                            {formatNumber(outcome.count, locale)}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="rounded-2xl border border-dashed border-slate-200 bg-white px-3 py-4 text-base text-slate-600">
                                {redesignText.results_none}
                            </p>
                        )}

                        {winRate ? (
                            <div className="mt-3 flex items-baseline justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-3 py-3">
                                <div className="min-w-0">
                                    <div className="text-base font-medium text-slate-700">{redesignText.results_win_rate}</div>
                                    <div className="text-sm leading-5 text-slate-600">
                                        {winRate.numerator}/{winRate.denominator} {redesignText.results_win_rate_basis}
                                    </div>
                                </div>
                                <div className="shrink-0 text-2xl font-semibold text-slate-950">
                                    {formatPercent(winRate.value, locale)}
                                </div>
                            </div>
                        ) : null}
                    </Card>
                </div>
            </div>
        </div>
    );
}
