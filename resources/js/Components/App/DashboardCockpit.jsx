import { Link } from '@inertiajs/react';
import { Fragment, useMemo, useState } from 'react';
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

function Icon({ path, className = 'h-5 w-5' }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d={path} />
        </svg>
    );
}

const ICON_PATHS = {
    clipboard: 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
    user: 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
    clock: 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    chat: 'M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.74 1.04.586 1.641a4.483 4.483 0 0 1-.923 1.785A5.969 5.969 0 0 0 6 21c1.282 0 2.47-.402 3.445-1.087.81.22 1.668.337 2.555.337Z',
    chevronRight: 'm8.25 4.5 7.5 7.5-7.5 7.5',
};

/**
 * Each follow-up signal gets its own mark, so the four are told apart before they are read. The
 * tile keeps its colour whatever the count is — it identifies the signal, it does not report it.
 * Severity is carried by the card and the number, which is what actually changes.
 */
const SIGNAL_MARKS = {
    'go-no-go-pending': { path: ICON_PATHS.clipboard, tile: 'bg-indigo-50 text-indigo-600' },
    'missing-bid-manager': { path: ICON_PATHS.user, tile: 'bg-violet-50 text-violet-600' },
    'deadline-soon': { path: ICON_PATHS.clock, tile: 'bg-amber-50 text-amber-600' },
    'inactive-seven-days': { path: ICON_PATHS.chat, tile: 'bg-emerald-50 text-emerald-600' },
};

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

/**
 * A titled region of the cockpit.
 *
 * Every region used to be a card: a 24px radius, a drop shadow and a 12px uppercase title. Five of
 * them stacked, several holding cards of their own, which left the page reading as a report form —
 * and the titles were not headings at all, so a screen reader found exactly one <h2> on the whole
 * page. The title is now a real heading at the section level, and `bare` drops the frame for the
 * regions that only needed grouping, not a surface of their own.
 *
 * `sub` is for a region nested inside another one (the follow-up drill-down): a level down in the
 * document outline, and a step down in size, so it cannot outrank the section holding it.
 */
function Card({ title, subtitle, infoKey, infoText, action, children, className = '', openInfoKey, setOpenInfoKey, texts = {}, dense = false, bare = false, sub = false }) {
    const Heading = sub ? 'h3' : 'h2';

    return (
        <section className={classNames(
            bare ? '' : classNames('rounded-3xl border border-slate-200 bg-white', dense ? 'p-5' : 'p-6'),
            className,
        )}>
            <div className={classNames('flex items-start justify-between gap-4', dense ? 'mb-3' : 'mb-4')}>
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <Heading className={classNames(
                            'font-semibold tracking-tight text-slate-950',
                            sub ? 'text-lg' : 'text-xl',
                        )}>
                            {title}
                        </Heading>
                        {infoKey ? (
                            <InfoButton infoKey={infoKey} title={title} infoText={infoText} openInfoKey={openInfoKey} setOpenInfoKey={setOpenInfoKey} texts={texts} />
                        ) : null}
                    </div>
                    {subtitle ? (
                        <p className="mt-1 max-w-3xl text-base leading-6 text-slate-600">
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
                <div className="mt-0.5 text-base font-semibold leading-6 text-slate-700">
                    {item.reason}
                </div>
                <div className="mt-0.5 text-sm leading-5 text-slate-600">
                    {item.secondary}
                </div>
            </div>
            <span className="mt-1 shrink-0 text-base font-semibold text-violet-700 opacity-0 transition group-hover:opacity-100">
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
    const mark = SIGNAL_MARKS[signal.key] ?? { path: ICON_PATHS.clipboard, tile: 'bg-slate-100 text-slate-500' };
    // Tint only, and only when there is something to act on: the signal already says what it is in
    // words, so colour repeats it quietly rather than carrying it.
    const tone = {
        danger: 'border-rose-200 bg-rose-50/50',
        warning: 'border-amber-200 bg-amber-50/50',
        neutral: 'border-slate-200 bg-white',
    };

    return (
        <button
            type="button"
            aria-expanded={isOpen}
            onClick={onToggle}
            className={classNames(
                'flex h-full w-full items-start gap-4 rounded-2xl border px-5 py-5 text-left transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600',
                isActive ? (tone[signal.severity] ?? tone.neutral) : 'border-slate-200 bg-white',
                isOpen ? 'ring-2 ring-violet-200' : 'hover:border-slate-300',
            )}
        >
            <span className={classNames(
                'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl',
                mark.tile,
            )}>
                <Icon path={mark.path} />
            </span>

            <span className="min-w-0 flex-1">
                <span className={classNames(
                    'block text-[30px] font-semibold leading-none tabular-nums',
                    isActive ? 'text-slate-950' : 'text-slate-400',
                )}>
                    {formatNumber(signal.count, locale)}
                </span>
                <span className="mt-2 block text-base font-semibold leading-6 text-slate-900">
                    {signal.label}
                </span>
                <span className={classNames(
                    'mt-1.5 block text-base leading-6',
                    isActive ? 'text-slate-700' : 'text-slate-500',
                )}>
                    {signal.description}
                </span>
                {isActive ? (
                    <span className="mt-3 inline-flex items-center gap-1 text-base font-semibold text-violet-700">
                        {isOpen ? actionTexts.hide : actionTexts.show}
                    </span>
                ) : null}
            </span>
        </button>
    );
}

/**
 * One phase inside the pipeline strip.
 *
 * These were six bordered cards inside a seventh, which made the pipeline read as six unrelated
 * numbers rather than one portfolio. They are segments of a single strip now, joined by chevrons
 * so the strip reads left to right as the route a case actually takes. A phase holding cases is
 * tinted; the violet fill still shows its share of the busiest phase.
 */
function PipelineStage({ stage, locale, stageMax }) {
    const count = Number(stage.count ?? 0);
    const width = stageMax > 0 ? Math.round((count / stageMax) * 100) : 0;
    const hasCases = count > 0;

    return (
        <div className={classNames(
            'flex min-w-0 flex-1 flex-col gap-2 px-4 py-4',
            hasCases ? 'bg-violet-50' : '',
        )}>
            <div className="text-sm font-semibold uppercase tracking-[0.06em] text-slate-500">
                {stage.label}
            </div>
            <div className={classNames(
                'text-[26px] font-semibold leading-none tabular-nums',
                hasCases ? 'text-slate-950' : 'text-slate-400',
            )}>
                {formatNumber(count, locale)}
            </div>
            <div className={classNames('mt-0.5 h-1.5 rounded-full', hasCases ? 'bg-violet-200' : 'bg-slate-200')}>
                <div
                    className="h-1.5 rounded-full bg-violet-600 transition-all"
                    style={{ width: `${Math.max(hasCases ? 6 : 0, width)}%` }}
                />
            </div>
        </div>
    );
}

/** The join between two phases. Decoration, so it is hidden from assistive technology. */
function PipelineChevron() {
    return (
        <span className="hidden shrink-0 items-center text-slate-300 sm:flex" aria-hidden="true">
            <Icon path={ICON_PATHS.chevronRight} className="h-4 w-4" />
        </span>
    );
}
/**
 * The calendar's toolbar shares one height, the way the Wiki list's does, so month, year and the
 * date jump read as one control rather than three stacked rows.
 */
const CALENDAR_CONTROL_CLS = 'h-11 rounded-lg border border-slate-200 bg-white px-3 text-base text-slate-700 transition focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100';
const CALENDAR_LABEL_CLS = 'text-sm font-medium text-slate-500';
const CALENDAR_ARROW_CLS = 'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-200';

function DeadlinePopover({ items, locale, texts = {}, commonText = {} }) {
    return (
        <div className="absolute left-0 top-full z-20 mt-2 hidden w-72 rounded-2xl border border-slate-200 bg-white p-3 shadow-[0_20px_40px_rgba(15,23,42,0.12)] group-hover:block group-focus-within:block">
            <div className="mb-2 text-sm font-semibold text-slate-600">
                {texts.title}
            </div>
            <div className="space-y-2">
                {items.map((item) => (
                    <Link
                        key={item.id}
                        href={item.show_url}
                        className="block rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-left transition hover:border-violet-200 hover:bg-violet-50/80"
                    >
                        <div className="text-base font-semibold leading-6 text-slate-950">
                            {item.title}
                        </div>
                        <div className="mt-1 text-sm leading-5 text-slate-600">
                            {item.deadline_type_label}
                            {' · '}
                            {formatDate(item.date, locale, commonText.not_available, { day: 'numeric', month: 'short' })}
                        </div>
                        <div className="mt-1 text-sm leading-5 text-slate-600">
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
        <div className="space-y-8">
            <header className="space-y-2">
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
                <p className="max-w-3xl text-base leading-6 text-slate-600">
                    {pageSubtitle}
                </p>
            </header>

            <section className="space-y-4">
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
                    <p className="text-base leading-6 text-slate-600">
                        {redesignText.follow_up_clear}
                    </p>
                )}

                {openSignal ? (
                    <Card
                        sub
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
                            <p className="text-base leading-6 text-slate-600">
                                {emptyStatesText.no_category_items}
                            </p>
                        )}
                    </Card>
                ) : null}
            </section>

            <div className="grid gap-8 xl:grid-cols-12 xl:gap-6">
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
                        {/* Stacked below sm, where six segments in a row would be unreadable. */}
                        <div className="flex flex-col divide-y divide-slate-200 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 sm:flex-row sm:items-stretch sm:divide-y-0">
                            {pipelineStages.map((stage, index) => (
                                <Fragment key={stage.key}>
                                    {index > 0 ? <PipelineChevron /> : null}
                                    <PipelineStage
                                        stage={stage}
                                        locale={locale}
                                        stageMax={stageMax}
                                    />
                                </Fragment>
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
                        <dl className="divide-y divide-slate-200">
                            {managementRows.map((row) => (
                                <div
                                    key={row.key}
                                    className="flex items-baseline justify-between gap-4 py-3.5 first:pt-0 last:pb-0"
                                >
                                    <dt className="min-w-0 text-base leading-6 text-slate-600">{row.label}</dt>
                                    <dd className="shrink-0 text-base font-semibold tabular-nums text-slate-950">{row.value}</dd>
                                </div>
                            ))}
                        </dl>
                    </Card>
                </div>
            </div>

            <div className="grid gap-8 xl:grid-cols-12 xl:items-start xl:gap-6">
                <div className="xl:col-span-8">
                    <Card
                        title={sectionsText.deadlines?.title}
                        subtitle={`${sectionsText.deadlines?.subtitle_prefix ?? ''} ${calendar.monthLabel}`.trim()}
                        infoKey="deadlines"
                        openInfoKey={openInfoKey}
                        setOpenInfoKey={setOpenInfoKey}
                        texts={dashboardText}
                    >
                        {/* One toolbar, one control height. The month arrows, the two selects and
                            the date jump used to sit in three separate rows at three sizes, with
                            12px uppercase labels above them — the calendar's own chrome outweighed
                            the month it was showing. */}
                        <div className="flex flex-wrap items-end gap-3">
                            {/* Month, and the two steps either side of it, as one control. */}
                            <div className="flex h-11 shrink-0 items-center gap-1 rounded-xl border border-slate-200 bg-white px-1">
                                <button
                                    type="button"
                                    aria-label={calendarText.previous_month}
                                    onClick={() => setVisibleMonthStart((current) => addMonths(current, -1))}
                                    className={CALENDAR_ARROW_CLS}
                                >
                                    <Icon path={ICON_PATHS.chevronRight} className="h-4 w-4 rotate-180" />
                                </button>
                                <span className="min-w-36 px-1 text-center text-base font-semibold capitalize text-slate-900">
                                    {calendar.monthLabel}
                                </span>
                                <button
                                    type="button"
                                    aria-label={calendarText.next_month}
                                    onClick={() => setVisibleMonthStart((current) => addMonths(current, 1))}
                                    className={CALENDAR_ARROW_CLS}
                                >
                                    <Icon path={ICON_PATHS.chevronRight} className="h-4 w-4" />
                                </button>
                            </div>

                            <label className="flex min-w-0 flex-1 basis-32 flex-col gap-1">
                                <span className={CALENDAR_LABEL_CLS}>{calendarText.month_label}</span>
                                <select
                                    value={String(visibleMonthStart.getMonth())}
                                    onChange={(event) => {
                                        const nextMonth = Number(event.target.value);
                                        setVisibleMonthStart(new Date(visibleMonthStart.getFullYear(), nextMonth, 1));
                                    }}
                                    className={classNames(CALENDAR_CONTROL_CLS, 'min-w-0')}
                                >
                                    {monthOptions.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </label>

                            <label className="flex min-w-0 flex-1 basis-24 flex-col gap-1">
                                <span className={CALENDAR_LABEL_CLS}>{calendarText.year_label}</span>
                                <select
                                    value={calendar.yearValue}
                                    onChange={(event) => {
                                        const nextYear = Number(event.target.value);
                                        setVisibleMonthStart(new Date(nextYear, visibleMonthStart.getMonth(), 1));
                                    }}
                                    className={classNames(CALENDAR_CONTROL_CLS, 'min-w-0')}
                                >
                                    {calendarYearOptions.map((year) => (
                                        <option key={year} value={String(year)}>
                                            {year}
                                        </option>
                                    ))}
                                </select>
                            </label>

                            <div className="flex w-full min-w-0 flex-col gap-1 sm:w-auto sm:flex-1 sm:basis-56">
                                <label className={CALENDAR_LABEL_CLS} htmlFor="deadline-date-jump">
                                    {calendarText.jump_label}
                                </label>
                                <div className="flex min-w-0 gap-2">
                                    <input
                                        id="deadline-date-jump"
                                        type="date"
                                        value={jumpDateValue}
                                        onChange={(event) => setJumpDateValue(event.target.value)}
                                        className={classNames(CALENDAR_CONTROL_CLS, 'min-w-0 flex-1')}
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
                                        className="inline-flex h-11 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white px-4 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-200"
                                    >
                                        {actionsText.show}
                                    </button>
                                </div>
                            </div>

                            {calendarText.hover_hint ? (
                                <p className="max-w-44 text-sm leading-5 text-slate-400">
                                    {calendarText.hover_hint}
                                </p>
                            ) : null}
                        </div>

                        <div className="mt-5 grid grid-cols-7 gap-1 text-sm font-semibold uppercase tracking-[0.06em] text-slate-500">
                            {calendarText.weekdays?.map((label) => (
                                <div key={label} className="px-1 pb-1 text-center">
                                    {label}
                                </div>
                            ))}
                        </div>

                        <div className="grid grid-cols-7 gap-1">
                            {calendar.days.map((day) => {
                                const items = deadlineGroups[day.dateKey] ?? [];
                                const isSelected = selectedDateKey === day.dateKey;

                                return (
                                    <button
                                        key={day.dateKey}
                                        type="button"
                                        onClick={() => setSelectedDateKey(day.dateKey)}
                                        className={classNames(
                                            'group relative min-h-11 rounded-lg border px-2 py-1.5 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-300',
                                            day.inCurrentMonth
                                                ? 'border-slate-200 bg-white text-slate-800 hover:border-slate-300 hover:bg-slate-50'
                                                : 'border-slate-100 bg-slate-50/70 text-slate-400',
                                            day.isToday ? 'ring-2 ring-violet-200' : '',
                                            isSelected ? 'border-violet-400 bg-violet-50' : '',
                                        )}
                                    >
                                        <div className="flex items-start justify-between gap-1">
                                            <div className="text-sm font-semibold leading-5 tabular-nums">
                                                {day.dayOfMonth}
                                            </div>
                                            {items.length > 0 ? (
                                                <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-violet-100 px-1 text-sm font-semibold tabular-nums text-violet-700">
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
                            <div className="grid grid-cols-2 overflow-hidden rounded-2xl border border-slate-200 bg-white">
                                {visibleOutcomes.map((outcome, index) => (
                                    <div
                                        key={outcome.key}
                                        className={classNames(
                                            'flex flex-col gap-1.5 px-5 py-4',
                                            index % 2 === 1 ? 'border-l border-slate-200' : '',
                                            index > 1 ? 'border-t border-slate-200' : '',
                                        )}
                                    >
                                        <div className={classNames(
                                            'text-base font-semibold leading-6',
                                            OUTCOME_TONE[outcome.key] ?? 'text-slate-600',
                                        )}>
                                            {outcome.label}
                                        </div>
                                        <div className="text-[26px] font-semibold leading-none tabular-nums text-slate-950">
                                            {formatNumber(outcome.count, locale)}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="flex flex-col items-center gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-10 text-center">
                                <span className="flex h-14 w-14 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                                    <Icon path={ICON_PATHS.clipboard} className="h-6 w-6" />
                                </span>
                                <p className="text-base leading-6 text-slate-500">
                                    {redesignText.results_none}
                                </p>
                            </div>
                        )}

                        {winRate ? (
                            <div className="mt-4 flex items-baseline justify-between gap-4 border-t border-slate-200 pt-4">
                                <div className="min-w-0">
                                    <div className="text-base font-medium leading-6 text-slate-700">{redesignText.results_win_rate}</div>
                                    <div className="text-sm leading-5 text-slate-600">
                                        {winRate.numerator}/{winRate.denominator} {redesignText.results_win_rate_basis}
                                    </div>
                                </div>
                                <div className="shrink-0 text-[26px] font-semibold leading-none tabular-nums text-slate-950">
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
