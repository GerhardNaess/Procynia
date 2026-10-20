import { Link, router, useForm, usePage } from '@inertiajs/react';
import { PRIMARY_ACTION, PRIMARY_COLOURS, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import ActionDialog, { DialogTitleBar } from '../../../Components/App/ActionDialog';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import ScrollRegion from '../../../Components/App/ScrollRegion';
import { focusElement, refocusIfLost } from '../../../Support/focusManagement';
import { useTransitionAnnouncement } from '../../../Support/statusAnnouncement';
import { useRunStatusAnnouncements } from './wikiRunAnnouncements';
import { validateSourceFile } from './wikiSourceUpload';
import {
    RUN_TIMELINE_STEPS,
    matchesFindingsLocalFilter,
    getRunTimelineState,
    getEscalationCopy,
    getRunFailureLines,
    runStatusKey,
    RUN_STATUS_FILTER_KEYS,
    getTransientFailureCopy,
    isRunStalled,
    getRunCompactSummary,
    formatFindingUserId,
    isActiveWikiRun,
    hasActiveWikiRunForTab,
    resolveFocusedFinding,
    focusedFindingLocalFilter,
} from './runFindingsLogic';

function formatDate(value, locale) {
    if (!value) return '—';
    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}

function formatTime(value, locale) {
    if (!value) return null;
    return new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

const BADGE = 'inline-flex items-center rounded-full px-2.5 py-0.5 text-base font-semibold leading-5 whitespace-nowrap';
// Fixed widths only where the content is known (ID, counts, dates, the one action); the status
// column holds a badge and one short line, so document and status share what is left.
const RUNS_GRID_TEMPLATE = 'minmax(0,2.5rem) minmax(0,1.3fr) minmax(0,1.7fr) minmax(0,6.5rem) minmax(0,3rem) minmax(0,3rem) minmax(0,5.5rem) minmax(0,5.5rem) minmax(0,8rem)';
// BADGE never wraps; «Sidestruktur opprettet» must, or it runs into the Sider column.
const WRAPPING_BADGE = 'inline-flex max-w-full items-center rounded-xl px-2 py-0.5 text-sm font-semibold leading-5 break-words';
// A run's status in Kjøringer: the size of BADGE, but it wraps inside the status column instead of
// running into the next one.
const RUN_STATUS_BADGE = 'inline-flex max-w-full items-center rounded-xl px-2.5 py-0.5 text-base font-semibold leading-5 break-words';

// Shared row-action button styles — deliberately distinct from BADGE (status chips: h-6, no
// border, no hover/focus state) so an action always reads as a clickable control, never as a
// status label. Base carries layout + a11y (focus ring, disabled state, no word-wrapping inside
// the label); variants only change color/emphasis.
const ACTION_BUTTON_BASE = 'inline-flex h-10 items-center gap-1.5 whitespace-nowrap rounded-lg border px-3.5 text-base font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50';
const ACTION_BUTTON_PRIMARY = `${ACTION_BUTTON_BASE} shadow-sm ${PRIMARY_COLOURS}`;
const ACTION_BUTTON_SECONDARY = `${ACTION_BUTTON_BASE} border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50 focus-visible:ring-slate-300`;

const ACTION_BUTTON_DESTRUCTIVE_WRAP = 'inline-flex h-10 max-w-full items-center justify-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 text-sm font-semibold leading-5 text-rose-700 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 hover:border-rose-400 hover:bg-rose-50 focus-visible:ring-rose-400 disabled:cursor-not-allowed disabled:opacity-50 whitespace-normal break-words text-center';

const STATUS_STYLES = {
    approved: 'bg-emerald-100 text-emerald-700',
    pending_review: 'bg-amber-100 text-amber-700',
    draft: 'bg-slate-200 text-slate-600',
    rejected: 'bg-rose-100 text-rose-700',
    archived: 'bg-slate-200 text-slate-700',
};

function StatusBadge({ status, label }) {
    const cls = STATUS_STYLES[status] ?? 'bg-slate-200 text-slate-600';
    return <span className={`${BADGE} ${cls}`}>{label}</span>;
}

const PAGE_TYPE_STYLES = {
    article: 'bg-violet-100 text-violet-700',
    summary: 'bg-sky-100 text-sky-700',
    concept: 'bg-teal-100 text-teal-700',
    entity: 'bg-orange-100 text-orange-700',
};

function PageTypeBadge({ type, label }) {
    const cls = PAGE_TYPE_STYLES[type] ?? 'bg-slate-100 text-slate-600';
    return <span className={`${BADGE} ${cls}`}>{label}</span>;
}

const DOCUMENT_OWNER_SUMMARY_STYLES = {
    awaiting_sync: 'bg-slate-100 text-slate-600',
    qa_review_open: 'bg-slate-100 text-slate-600',
    missing_owner: 'bg-rose-100 text-rose-700',
    pending: 'bg-amber-100 text-amber-700',
    mixed: 'bg-violet-100 text-violet-700',
    approved: 'bg-emerald-100 text-emerald-700',
    rejected: 'bg-rose-100 text-rose-700',
};

function DocumentOwnerSummaryBadge({ summary, fallbackLabel }) {
    const label = summary?.label ?? fallbackLabel;
    const cls = DOCUMENT_OWNER_SUMMARY_STYLES[summary?.state] ?? 'bg-slate-100 text-slate-600';

    return <span className={`${BADGE} ${cls}`}>{label}</span>;
}

const SOURCE_STATUS_STYLES = {
    extracted: 'bg-emerald-100 text-emerald-700',
    pending: 'bg-amber-100 text-amber-700',
    failed: 'bg-rose-100 text-rose-700',
};

function SourceStatusBadge({ status, label }) {
    const cls = SOURCE_STATUS_STYLES[status] ?? 'bg-slate-200 text-slate-600';
    return <span className={`${BADGE} ${cls}`}>{label}</span>;
}

const SEVERITY_STYLES = {
    error: 'bg-rose-100 text-rose-700',
    warning: 'bg-amber-100 text-amber-700',
    info: 'bg-slate-100 text-slate-700',
};

const RUN_TIMELINE_DISPLAY_LABELS = {
    queued: 'Kø',
    maintainer_decision: 'Sideplanlegging',
    applying: 'Sidestruktur',
    generating_pages: 'Sider',
    verification_linking: 'Verifisering',
    qa: 'QA',
};

const IN_PROGRESS_STATUSES = [
    'queued',
    'running',
    'sections_planned',
    'maintainer_decision',
    'applying',
    'generating_pages',
    'generating_concept_entity_pages',
    'verifying_claims',
    'post_claim_verification',
    'verification_linking',
    'qa',
    'decision_only',
];

// ACTIVE_WIKI_RUN_STATUSES / isActiveWikiRun now live in runFindingsLogic.js — the single shared,
// unit-testable source for "should we keep polling for this run" (see that file's docblock).

function formatRelativeProgress(value, locale) {
    if (!value) return null;

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return null;

    const diffMinutes = Math.round((Date.now() - date.getTime()) / 60000);
    const absMinutes = Math.abs(diffMinutes);

    if (absMinutes < 60) {
        return new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }).format(-diffMinutes, 'minute');
    }

    const diffHours = Math.round(diffMinutes / 60);
    if (Math.abs(diffHours) < 24) {
        return new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }).format(-diffHours, 'hour');
    }

    return `kl. ${formatTime(value, locale)}`;
}

function TimelineStatusIcon({ state }) {
    if (state === 'done') {
        return (
            <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="2.2" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 10.2l3.1 3.1L15.6 5.3" />
            </svg>
        );
    }

    if (state === 'active' || state === 'waiting') {
        return (
            <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" d="M6 3.5h8v2l-2.2 3.2L14 12v2.5H6V12l2.2-3.3L6 5.5z" />
            </svg>
        );
    }

    if (state === 'error') {
        return <span className="h-2 w-2 rounded-full bg-current" aria-hidden="true" />;
    }

    return <span className="h-2 w-2 rounded-full bg-current" aria-hidden="true" />;
}

function getTimelineToneClasses(state) {
    if (state === 'done') {
        return {
            icon: 'border-emerald-200 bg-emerald-50 text-emerald-600',
            text: 'text-emerald-700',
            line: 'bg-emerald-300',
        };
    }

    if (state === 'active' || state === 'waiting') {
        return {
            icon: 'border-amber-200 bg-amber-50 text-amber-700',
            text: 'text-amber-800',
            line: 'bg-amber-300',
        };
    }

    if (state === 'error') {
        return {
            icon: 'border-rose-200 bg-rose-50 text-rose-600',
            text: 'text-rose-700',
            line: 'bg-rose-300',
        };
    }

    return {
        icon: 'border-slate-300 bg-slate-50 text-slate-500',
        text: 'text-slate-600',
        line: 'bg-slate-200',
    };
}

/**
 * The words for a step's state (A11Y-024): the icon and the colour said it to a sighted user only.
 * Read after the step's name, «Sider, pågår».
 */
function timelineStateText(state, ta) {
    return {
        done: ta.step_completed ?? 'fullført',
        active: ta.wiki_step_active ?? 'pågår',
        waiting: ta.wiki_step_waiting ?? 'venter',
        error: ta.wiki_step_failed ?? 'stoppet',
    }[state] ?? (ta.step_upcoming ?? 'gjenstår');
}

function RunTimeline({ run, tw, ta = {} }) {
    if (!run || run.status === 'decision_only') {
        return null;
    }

    return (
        <div className="min-w-0 max-w-full">
            <ol
                aria-label={(ta.wiki_run_progress_label ?? 'Fremdrift for kjøring :id').replace(':id', String(run.id))}
                className="flex min-w-max items-center gap-0 md:min-w-0 md:flex-nowrap"
            >
            {RUN_TIMELINE_STEPS.map((step, index) => {
                const state = getRunTimelineState(run, index);
                const tone = getTimelineToneClasses(state);
                const isLast = index === RUN_TIMELINE_STEPS.length - 1;
                const label = RUN_TIMELINE_DISPLAY_LABELS[step.key] ?? tw[step.labelKey] ?? step.fallback;
                const isCurrent = state === 'active' || state === 'waiting' || state === 'error';

                return (
                    <li key={step.key} aria-current={isCurrent ? 'step' : undefined} className="flex min-w-0 items-center">
                        <div
                            data-progress-step
                            data-progress-state={state}
                            className={`inline-flex shrink-0 items-center gap-2 whitespace-nowrap text-sm font-semibold leading-5 ${tone.text}`}
                        >
                            <span
                                className={`inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border ${tone.icon}`}
                                aria-hidden="true"
                            >
                                <TimelineStatusIcon state={state} />
                            </span>
                            <span className="whitespace-nowrap">{label}</span>
                            <span className="sr-only">, {timelineStateText(state, ta)}</span>
                        </div>
                        {!isLast && (
                            <span
                                data-progress-connector
                                aria-hidden="true"
                                className={`mx-3 h-0.5 w-10 flex-none rounded-full ${tone.line} md:w-12`}
                            />
                        )}
                    </li>
                );
            })}
            </ol>
        </div>
    );
}

function defaultRunDetailSection(run) {
    const findings = run.lint_count ?? 0;
    const needsAttention = (run.findings_open_blocking_count ?? 0) > 0 || ['escalated', 'failed'].includes(run.status);

    if (findings > 0 && needsAttention) return 'findings';
    if ((run.pages_count ?? 0) > 0) return 'pages';
    if (findings > 0) return 'findings';

    return 'overview';
}

const CHEVRON_PATH = 'M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z';

function RunListItem({
    run,
    tw,
    ta = {},
    locale,
    activePanel,
    panelId,
    onCancelClick,
    onToggleDetails,
    onTogglePanel,
    onSelectSection,
    runPagesState,
    runFindingsState,
    onRetryMaintainerDecision,
    onRetryFailedPages,
    onDismissRun = null,
    onFetchRunPages,
    onFetchRunFindings,
    focusFindingId,
}) {
    const statusCls = runStatusClass(run);
    const statusBadgeClass = `${RUN_STATUS_BADGE} ${statusCls}`;
    const seemsStalled = isRunStalled(run);
    // The collapsed row answers «what is the status» and «does it need follow-up»: the badge and at
    // most one short line. Everything else is in the row's one detail view.
    const compactSummary = getRunCompactSummary(run, tw);
    // activePanel: null (closed) | 'overview' | 'pages' | 'findings' — the last two are the open
    // view with that section of «Sider og QA-funn» shown.
    const detailsOpen = activePanel !== null;
    const documentId = `run-${run.id}-document`;

    const renderStatusCell = () => (
        <div className="min-w-0 max-w-full space-y-0.5">
            <div className="flex flex-wrap items-center gap-1.5">
                <span
                    className={statusBadgeClass}
                    title={run.status === 'escalated' ? (run.failure_summary?.title || run.findings_explanation || undefined) : undefined}
                >
                    {runStatusLabel(run, tw)}
                </span>
                {seemsStalled && (
                    <span className="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold leading-5 text-amber-700">
                        {tw.ingest_activity_stalled ?? 'Ser ut til å stå stille'}
                    </span>
                )}
            </div>
            {compactSummary && (
                <p data-run-follow-up className="break-words text-base font-medium leading-5 text-slate-700">
                    {compactSummary}
                </p>
            )}
        </div>
    );

    const renderDecisionBadge = () => {
        if (run.maintainer_decision_status === 'applied') {
            return (
                <span className={`${WRAPPING_BADGE} bg-emerald-100 text-emerald-700`}>
                    {tw.run_decision_applied ?? 'Sidestruktur opprettet'}
                </span>
            );
        }

        if (run.maintainer_decision_status === 'pending') {
            return (
                <span className={`${WRAPPING_BADGE} bg-slate-100 text-slate-600`}>
                    {tw.run_decision_pending ?? 'Venter'}
                </span>
            );
        }

        return <span className="text-slate-500">—</span>;
    };

    // The counts open the same detail view as the chevron, on their own section.
    const renderPagesCell = (compact = false) => {
        if (run.pages_count > 0) {
            return (
                <button
                    type="button"
                    onClick={() => onTogglePanel(run, 'pages', true)}
                    aria-expanded={activePanel === 'pages'}
                    aria-controls={panelId}
                    aria-label={(ta.wiki_run_pages_toggle ?? 'Vis :count berørte Wiki-sider').replace(':count', String(run.pages_count))}
                    className={`inline-flex items-center gap-1 rounded-md py-1 font-semibold text-violet-700 transition hover:bg-violet-50 hover:text-violet-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-400 ${compact ? 'pr-2 text-sm' : 'min-w-[2.75rem] justify-center px-2'}`}
                >
                    {run.pages_count}
                    <svg className={`h-3.5 w-3.5 transition-transform ${activePanel === 'pages' ? 'rotate-180' : ''}`} viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fillRule="evenodd" d={CHEVRON_PATH} clipRule="evenodd" />
                    </svg>
                </button>
            );
        }

        return <span className="text-slate-600" title={tw.runs_pages_none ?? 'Ingen Wiki-sider'}>0</span>;
    };

    const renderFindingsCell = (compact = false) => {
        if (run.lint_count > 0) {
            return (
                <button
                    type="button"
                    onClick={() => onTogglePanel(run, 'findings', true)}
                    aria-expanded={activePanel === 'findings'}
                    aria-controls={panelId}
                    aria-label={(run.findings_open_blocking_count ?? 0) > 0
                        ? (tw.runs_findings_toggle_open_with_blocking ?? 'Vis :count kvalitetsfunn, hvorav :blocking blokkerende')
                            .replace(':count', run.lint_count)
                            .replace(':blocking', run.findings_open_blocking_count)
                        : (tw.runs_findings_toggle_open ?? 'Vis :count kvalitetsfunn').replace(':count', run.lint_count)}
                    title={(run.findings_open_blocking_count ?? 0) > 0
                        ? (tw.runs_findings_count_tooltip_blocking ?? ':total funn totalt, :blocking av dem blokkerer fullføring')
                            .replace(':total', run.lint_count)
                            .replace(':blocking', run.findings_open_blocking_count)
                        : undefined}
                    className={`inline-flex items-center gap-1 rounded-md py-1 font-semibold transition focus:outline-none focus-visible:ring-2 ${findingsCountToneClass(run)} ${compact ? 'pr-2 text-sm' : 'min-w-[2.75rem] justify-center px-2'}`}
                >
                    {run.lint_count}
                    <svg className={`h-3.5 w-3.5 transition-transform ${activePanel === 'findings' ? 'rotate-180' : ''}`} viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fillRule="evenodd" d={CHEVRON_PATH} clipRule="evenodd" />
                    </svg>
                </button>
            );
        }

        return <span className="text-slate-600" title={tw.runs_findings_none ?? 'Ingen funn'}>0</span>;
    };

    // «Vis detaljer» is the row's one way into its detail view (the Sider/Funn counts open it on
    // their own section).
    const renderDetailsButton = () => (
        <button
            type="button"
            data-run-details-toggle
            onClick={() => onToggleDetails(run)}
            aria-expanded={detailsOpen}
            aria-controls={panelId}
            aria-describedby={run.source_id ? documentId : undefined}
            className="inline-flex h-9 items-center justify-center whitespace-nowrap rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold leading-5 text-slate-700 transition hover:border-violet-300 hover:text-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-400 focus-visible:ring-offset-1"
        >
            {detailsOpen
                ? (tw.run_details_button_hide ?? 'Skjul detaljer')
                : (tw.run_details_button_show ?? 'Vis detaljer')}
        </button>
    );

    const renderActionCell = () => (
        <div className="flex max-w-full flex-col items-end gap-1.5">
            {run.can_cancel && (
                <button
                    type="button"
                    onClick={(event) => onCancelClick(run, event.currentTarget)}
                    aria-describedby={documentId}
                    className={`${ACTION_BUTTON_DESTRUCTIVE_WRAP} text-sm leading-5`}
                >
                    {tw.run_cancel_button ?? 'Avbryt kjøring'}
                </button>
            )}
            {renderDetailsButton()}
        </div>
    );

    const documentName = run.source_document_filename ?? '—';

    return (
        <div data-run-item data-run-id={run.id} data-run-open={detailsOpen || undefined} className="border-b border-slate-200 last:border-b-0">
            <div
                data-run-main-row
                className={`hidden items-center gap-x-3 px-6 py-2.5 lg:grid ${detailsOpen ? 'bg-slate-50/70' : ''}`}
                style={{ gridTemplateColumns: RUNS_GRID_TEMPLATE }}
            >
                <div className="min-w-0 text-right text-sm text-slate-600 tabular-nums">{run.id}</div>
                <div className="min-w-0">
                    {run.source_id ? (
                        <Link
                            id={documentId}
                            href="/app/wiki?tab=sources"
                            className="block truncate text-sm font-medium text-slate-900 hover:text-violet-700 hover:underline"
                            title={documentName}
                        >
                            {documentName}
                        </Link>
                    ) : (
                        <span className="text-slate-500">—</span>
                    )}
                </div>
                <div className="min-w-0 max-w-full">
                    {renderStatusCell()}
                </div>
                <div className="min-w-0 max-w-full">
                    {renderDecisionBadge()}
                </div>
                <div className="text-center text-sm font-semibold tabular-nums text-violet-700">
                    {renderPagesCell()}
                </div>
                <div className="text-center text-sm font-semibold tabular-nums text-amber-700">
                    {renderFindingsCell()}
                </div>
                <div className="text-sm leading-5 text-slate-600">
                    {formatDate(run.created_at, locale)}
                </div>
                <div className="text-sm text-slate-600">
                    {run.finished_at ? formatDate(run.finished_at, locale) : '—'}
                </div>
                <div className="flex justify-end">
                    {renderActionCell()}
                </div>
            </div>

            <div data-run-mobile-card className={`space-y-3 px-4 py-3 lg:hidden ${detailsOpen ? 'bg-slate-50/70' : ''}`}>
                <div className="flex items-start gap-2">
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <span>ID</span>
                            <span className="font-mono text-sm text-slate-600 tabular-nums">{run.id}</span>
                        </div>
                        {run.source_id ? (
                            <Link
                                href="/app/wiki?tab=sources"
                                className="block truncate text-sm font-medium text-slate-900 hover:text-violet-700 hover:underline"
                                title={documentName}
                            >
                                {documentName}
                            </Link>
                        ) : (
                            <span className="text-sm text-slate-500">—</span>
                        )}
                    </div>
                    <div className="shrink-0">{renderActionCell()}</div>
                </div>

                {renderStatusCell()}

                <dl className="grid grid-cols-3 gap-x-3 gap-y-2 text-sm leading-5 text-slate-600">
                    <div className="min-w-0">
                        <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{tw.runs_col_decision ?? 'Beslutning'}</dt>
                        <dd className="mt-1">{renderDecisionBadge()}</dd>
                    </div>
                    <div className="min-w-0">
                        <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{tw.runs_col_pages ?? 'Sider'}</dt>
                        <dd className="mt-1">{renderPagesCell(true)}</dd>
                    </div>
                    <div className="min-w-0">
                        <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{tw.runs_col_lint ?? 'Funn'}</dt>
                        <dd className="mt-1">{renderFindingsCell(true)}</dd>
                    </div>
                    <div className="min-w-0">
                        <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{tw.runs_col_created ?? 'Opprettet'}</dt>
                        <dd className="mt-1">{formatDate(run.created_at, locale)}</dd>
                    </div>
                    <div className="col-span-2 min-w-0">
                        <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{tw.runs_col_finished ?? 'Fullført'}</dt>
                        <dd className="mt-1">{run.finished_at ? formatDate(run.finished_at, locale) : '—'}</dd>
                    </div>
                </dl>
            </div>

            {detailsOpen && (
                <div
                    id={panelId}
                    data-run-detail-panel
                    className="divide-y divide-slate-200 border-t border-slate-200 bg-slate-50/70 px-4 lg:px-6"
                >
                    <RunStatusFollowUp
                        run={run}
                        tw={tw}
                        locale={locale}
                        headingId={`${panelId}-status`}
                        onRetryMaintainerDecision={onRetryMaintainerDecision}
                        onRetryFailedPages={onRetryFailedPages}
                        onDismiss={onDismissRun}
                    />

                    {run.status !== 'decision_only' && (
                        <section data-run-progress-row aria-labelledby={`${panelId}-progress`} className="py-4">
                            <h3 id={`${panelId}-progress`} className="mb-2 text-base font-semibold leading-6 text-slate-900">
                                {tw.run_detail_progress_heading ?? 'Fremdrift'}
                            </h3>
                            {/* Scrolls sideways on a narrow screen; reachable from the keyboard while it does. */}
                            <ScrollRegion
                                data-progress-scroll-area=""
                                label={(ta.wiki_run_progress_label ?? 'Fremdrift for kjøring :id').replace(':id', String(run.id))}
                                className="min-w-0 max-w-full"
                            >
                                <RunTimeline run={run} tw={tw} ta={ta} />
                            </ScrollRegion>
                            {/* The timeline is how the run itself ended — an escalation stays marked at
                                QA. Said once, here, when the follow-up done since then is confirmed. */}
                            {run.status === 'escalated' && run.follow_up_complete === true && (
                                <p data-run-progress-history-note className="mt-2 text-base leading-6 text-slate-600">
                                    {tw.run_progress_history_note ?? 'Fremdriften viser resultatet fra den opprinnelige kjøringen. QA-oppfølgingen er senere fullført.'}
                                </p>
                            )}
                        </section>
                    )}

                    {(run.pages_count > 0 || run.lint_count > 0) && (
                        <section data-run-pages-findings aria-labelledby={`${panelId}-sections`} className="space-y-3 py-4">
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                                <h3 id={`${panelId}-sections`} className="text-base font-semibold leading-6 text-slate-900">
                                    {tw.run_detail_sections_heading ?? 'Sider og QA-funn'}
                                </h3>
                                <div className="flex flex-wrap gap-2">
                                    {[
                                        ['pages', tw.runs_col_pages ?? 'Sider', run.pages_count],
                                        ['findings', tw.runs_col_lint ?? 'Funn', run.lint_count],
                                    ].map(([section, label, count]) => (
                                        <button
                                            key={section}
                                            type="button"
                                            data-run-section={section}
                                            onClick={() => onSelectSection(run, section)}
                                            aria-pressed={activePanel === section}
                                            aria-disabled={count > 0 ? undefined : true}
                                            className="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-300 bg-white px-3 text-base font-semibold leading-6 text-slate-700 transition hover:border-violet-300 hover:text-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-400 aria-pressed:border-violet-300 aria-pressed:bg-violet-50 aria-pressed:text-violet-700 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                                        >
                                            {label}
                                            <span className="tabular-nums">{count}</span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                            {activePanel === 'pages' && (
                                <RunAffectedPagesPanel
                                    panelId={`${panelId}-pages`}
                                    state={runPagesState[run.id]}
                                    onRetry={() => onFetchRunPages(run.id)}
                                    tw={tw}
                                />
                            )}
                            {activePanel === 'findings' && (
                                <RunFindingsPanel
                                    panelId={`${panelId}-findings`}
                                    state={runFindingsState[run.id]}
                                    onRetry={() => onFetchRunFindings(run.id)}
                                    tw={tw}
                                    ta={ta}
                                    locale={locale}
                                    focusFindingId={focusFindingId}
                                />
                            )}
                        </section>
                    )}
                </div>
            )}
        </div>
    );
}

const FAILED_PAGES_KIND_COPY = {
    transient: ['run_failed_pages_kind_transient', 'Feilen ser ut til å være midlertidig.'],
    unknown: ['run_failed_pages_kind_unknown', 'Årsaken er ikke kjent, og et nytt forsøk kan gi samme resultat.'],
    plan_defect: ['run_failed_pages_kind_plan_defect', 'Feilen skyldes sideplanen eller kildegrunnlaget, ikke et midlertidig problem. Et nytt forsøk ville gitt samme resultat, og tilbys derfor ikke.'],
};

const RUN_DETAIL_ACTION_BUTTON = 'inline-flex items-center gap-1.5 rounded-lg border border-violet-300 bg-violet-50 px-3 py-1.5 text-base font-semibold leading-6 text-violet-700 transition hover:border-violet-400 hover:bg-violet-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-400';

/**
 * «Status og oppfølging» in a run's detail view: what happened, what needs follow-up, and the
 * actions for it. Every sentence comes from data the row already carries (escalation reasons,
 * failed_pages, findings counts, the transient-failure classification) — nothing is derived anew.
 * Raw error text sits behind «Vis tekniske detaljer» only — never the default explanation.
 */
function RunStatusFollowUp({ run, tw, locale, headingId, onRetryMaintainerDecision = null, onRetryFailedPages = null, onDismiss = null }) {
    const [showTechnicalDetails, setShowTechnicalDetails] = useState(false);

    const isEscalated = run.status === 'escalated';
    const isTransientMaintainerFailure = run.status === 'failed'
        && run.failed_phase === 'maintainer_decision'
        && !!run.transient_failure;
    const escalation = isEscalated ? getEscalationCopy(run, tw) : null;
    // The translated explanation of a stopped run; its stored message stays behind «Vis tekniske
    // detaljer» below (EnterpriseWikiRunFailurePresenter).
    const failureLines = getRunFailureLines(run);
    const transientFailure = isTransientMaintainerFailure ? getTransientFailureCopy(run, tw) : null;
    const failedPages = run.failed_pages?.count ? run.failed_pages : null;
    const blockingCount = run.findings_open_blocking_count ?? 0;
    const totalFindings = run.lint_count ?? 0;
    const seemsStalled = isRunStalled(run);
    const progressAt = run.last_progress_at ?? run.updated_at ?? run.started_at ?? run.created_at;

    // Each line: [tone, text]. Tone 'primary' is the cause, 'alert' needs action, 'note' explains.
    const lines = [];

    if (escalation && failureLines) {
        lines.push(...failureLines);
        if (run.findings_explanation) lines.push(['note', run.findings_explanation]);
    } else if (escalation) {
        lines.push(['primary', escalation.primaryReason]);
        if (escalation.secondaryReason) lines.push(['note', escalation.secondaryReason]);
    } else if (transientFailure) {
        lines.push(['alert', transientFailure.primaryMessage]);
        lines.push(['note', [transientFailure.failedInPhase, transientFailure.attemptSummary, transientFailure.documentPreservedNote].filter(Boolean).join(' ')]);
    } else if (run.status === 'failed') {
        lines.push(...(failureLines ?? [['alert', tw.run_compact_failed ?? 'Stoppet med feil']]));
    }

    // A recognised failure already names the pages and why; the failed-pages sentence would say it
    // again. Its retry action below stays.
    if (failedPages && !(failureLines && run.failure_summary?.kind !== 'unknown')) {
        const [kindKey, kindFallback] = FAILED_PAGES_KIND_COPY[failedPages.failure_kind] ?? FAILED_PAGES_KIND_COPY.unknown;
        lines.push(['alert', `${(tw.run_failed_pages_summary ?? ':count side(r) kunne ikke genereres og er ufullstendige.').replace(':count', String(failedPages.count))} ${tw[kindKey] ?? kindFallback}`]);
    }

    if (blockingCount > 0) {
        lines.push(['alert', escalation?.blockingSummary ?? (tw.ingest_activity_escalated_blocking ?? ':count av :total funn er fortsatt åpne og blokkerer fullføring.')
            .replace(':count', blockingCount)
            .replace(':total', totalFindings)]);
    } else if (isEscalated && totalFindings > 0) {
        lines.push(['note', (tw.run_detail_findings_not_blocking ?? ':count QA-funn er registrert. Ingen av dem blokkerer fullføring.').replace(':count', String(totalFindings))]);
    } else if (isEscalated && !run.findings_explanation) {
        // findings_explanation, when present, is already the cause above and says this same thing.
        lines.push(['note', escalation.blockingSummary]);
    }

    if (seemsStalled) {
        lines.push(['warning', (tw.ingest_activity_stalled_explanation ?? 'Ingen registrert fremdrift siden :time. Kjøringen kan vente på en handling og er ikke nødvendigvis feilet.')
            .replace(':time', `${formatDate(progressAt, locale)} ${formatTime(progressAt, locale) ?? ''}`.trim())]);
    } else if (!run.finished_at && progressAt && isActiveWikiRun(run)) {
        lines.push(['note', `${tw.ingest_activity_last_progress ?? 'Siste fremdrift'} ${formatRelativeProgress(progressAt, locale)}`]);
    }

    if (lines.length === 0) {
        lines.push(['note', tw.run_detail_no_follow_up ?? 'Ingen oppfølging er nødvendig.']);
    }

    // Raw text not already shown above, e.g. run 963's qa_last_error behind its friendly escalation.
    const shownTexts = new Set(lines.map(([, text]) => text));
    const technicalDetails = [...new Set([run.error_message, run.qa_last_error].filter(Boolean))]
        .filter((text) => !shownTexts.has(text));

    const toneClass = {
        primary: 'font-medium text-slate-900',
        alert: 'font-medium text-rose-800',
        warning: 'text-amber-800',
        note: 'text-slate-600',
    };

    const canRetryFailedPages = failedPages?.can_retry && onRetryFailedPages;
    const canRetryDecision = isTransientMaintainerFailure && run.can_retry_maintainer_decision && onRetryMaintainerDecision;
    // Offered only when the server says this run is finished, nothing about it is left to follow
    // up, and this person may manage its document (can_dismiss — checked again on the request).
    const canDismiss = run.can_dismiss === true && onDismiss;

    return (
        // Not a live region (A11Y-112): it was re-rendered on every 5 s poll. A status change is said
        // once by useRunStatusAnnouncements in the list; the text here is there to be read.
        <section data-run-status-details aria-labelledby={headingId} className="min-w-0 max-w-full space-y-2 py-4">
            <h3 id={headingId} className="text-base font-semibold leading-6 text-slate-900">
                {tw.run_detail_status_heading ?? 'Status og oppfølging'}
            </h3>
            <ul className="max-w-3xl space-y-1" data-run-failed-pages={failedPages?.count || undefined}>
                {lines.map(([tone, text]) => (
                    <li key={text} className={`break-words text-base leading-6 ${toneClass[tone]}`}>{text}</li>
                ))}
            </ul>

            {(canRetryFailedPages || canRetryDecision || technicalDetails.length > 0 || canDismiss) && (
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2 pt-1">
                    {canRetryFailedPages && (
                        <button
                            type="button"
                            onClick={(event) => onRetryFailedPages(run, event.currentTarget, 'failed_pages')}
                            className={RUN_DETAIL_ACTION_BUTTON}
                        >
                            {tw.run_retry_failed_pages_button ?? 'Prøv igjen for feilede sider'}
                        </button>
                    )}
                    {canRetryDecision && (
                        <button
                            type="button"
                            onClick={(event) => onRetryMaintainerDecision(run, event.currentTarget)}
                            className={RUN_DETAIL_ACTION_BUTTON}
                        >
                            {tw.run_retry_maintainer_decision_button ?? 'Prøv sideplanleggingen på nytt'}
                        </button>
                    )}
                    {technicalDetails.length > 0 && (
                        <button
                            type="button"
                            onClick={() => setShowTechnicalDetails((current) => !current)}
                            aria-expanded={showTechnicalDetails}
                            className="rounded text-base font-semibold leading-6 text-slate-600 underline decoration-dotted underline-offset-2 hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-400"
                        >
                            {tw.run_technical_details_toggle ?? 'Vis tekniske detaljer'}
                        </button>
                    )}
                    {canDismiss && (
                        <button
                            type="button"
                            data-run-dismiss
                            onClick={(event) => onDismiss(run, event.currentTarget)}
                            className="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-base font-semibold leading-6 text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-400"
                        >
                            {tw.run_dismiss_button ?? 'Fjern fra oversikten'}
                        </button>
                    )}
                </div>
            )}

            {showTechnicalDetails && technicalDetails.map((text) => (
                <p key={text} className="max-w-3xl break-all rounded-lg border border-slate-200 bg-white px-3 py-2 font-mono text-sm leading-6 text-slate-700">
                    {text}
                </p>
            ))}
        </section>
    );
}

function getWikiPagesHelpSections(tw) {
    return [
        {
            title: tw.page_help_section_workflow ?? 'Arbeidsflyten',
            items: [
                {
                    title: tw.page_help_item_workflow_source_title ?? 'Kildedokumentet kommer først',
                    text: tw.page_help_item_workflow_source_text ?? 'Et kildedokument lastes opp og blir grunnlaget for en ny Wiki-side.',
                },
                {
                    title: tw.page_help_item_workflow_owner_title ?? 'Dokumenteier følger dokumentet',
                    text: tw.page_help_item_workflow_owner_text ?? 'Dokumentet får en Dokumenteier som har faglig ansvar for kildegrunnlaget. Den samme personen kan eie flere dokumenter.',
                },
                {
                    title: tw.page_help_item_workflow_wiki_title ?? 'Wiki-siden bygges og kontrolleres',
                    text: tw.page_help_item_workflow_wiki_text ?? 'Procynia genererer Wiki-sider og påstander, kobler dem til riktige kilder og kjører claims, kvalitetskontroll og QA før materialet tas i bruk.',
                },
                {
                    title: tw.page_help_item_workflow_blocked_title ?? '«Behandles fortsatt» betyr at innhold ikke kunne bekreftes',
                    text: tw.page_help_item_workflow_blocked_text ?? 'Hvis systemet finner påstander som ikke støttes av kildegrunnlaget, eller en intern genereringsfeil, stoppes siden før Dokumenteiergodkjenning. Dette løses teknisk eller ved en kontrollert revisjon — Dokumenteieren trenger ikke gjøre noe før siden er klar.',
                },
            ],
        },
        {
            title: tw.page_help_section_owner ?? 'Hva er en Dokumenteier?',
            items: [
                {
                    title: tw.page_help_item_owner_definition_title ?? 'Faglig ansvar for kildedokumentet',
                    text: tw.page_help_item_owner_definition_text ?? 'Dokumenteieren er personen som har det faglige ansvaret for et kildedokument. Dokumenteieren kan være en annen person enn den som lastet opp dokumentet.',
                },
                {
                    title: tw.page_help_item_owner_page_title ?? 'Godkjenner den konkrete sideversjonen',
                    text: tw.page_help_item_owner_page_text ?? 'Dokumenteieren vurderer den konkrete Wiki-sideversjonen som bygger på dokumentet. Godkjenningen betyr ikke at alt materiale er riktig automatisk.',
                },
                {
                    title: tw.page_help_item_owner_scope_title ?? 'Dokumenteiergodkjenning er ikke det samme som QA',
                    text: tw.page_help_item_owner_scope_text ?? 'Claim-verifikasjon sjekker om en påstand har kildestøtte. QA kontrollerer kvaliteten på tvers av Wiki-materialet. Dokumenteiergodkjenning er den faglige vurderingen fra personen som eier kildedokumentet.',
                },
            ],
        },
        {
            title: tw.page_help_section_multiple ?? 'Hvorfor kan en side ha flere eiere?',
            items: [
                {
                    title: tw.page_help_item_multiple_sources_title ?? 'En side kan bygge på flere dokumenter',
                    text: tw.page_help_item_multiple_sources_text ?? 'Når en Wiki-side bygger på flere kildedokumenter, får siden flere Dokumenteiere fordi hvert dokument har sin egen ansvarlige eier.',
                },
                {
                    title: tw.page_help_item_multiple_one_title ?? 'Én eier per dokument',
                    text: tw.page_help_item_multiple_one_text ?? 'Når samme person eier flere dokumenter som støtter samme side, får personen normalt én samlet godkjenningsoppgave. Sporbarheten til hvert dokument beholdes.',
                },
                {
                    title: tw.page_help_item_multiple_status_title ?? 'Samlet status viser hvor langt siden har kommet',
                    text: tw.page_help_item_multiple_status_text ?? 'Hvis en side har flere eiere, kan noen være godkjent og andre fortsatt avvente. Siden blir ikke fullt eiergodkjent før alle nødvendige eiere har godkjent sitt kildegrunnlag.',
                },
            ],
        },
        {
            title: tw.page_help_section_statuses ?? 'Hva betyr statusene?',
            items: [
                {
                    title: tw.page_help_item_status_article_title ?? 'Artikkelstatus og Dokumenteierstatus er forskjellige',
                    text: tw.page_help_item_status_article_text ?? 'Artikkelstatus beskriver selve Wiki-siden. Dokumenteierstatus beskriver om de ansvarlige for kildedokumentene har godkjent materialet. En side kan være Godkjent som artikkel og samtidig vente på Dokumenteiergodkjenning.',
                },
                {
                    title: tw.page_help_item_status_draft_title ?? 'Utkast',
                    text: tw.page_help_item_status_draft_text ?? 'Siden er opprettet, men ikke ferdig behandlet ennå.',
                },
                {
                    title: tw.page_help_item_status_approved_title ?? 'Publisert',
                    text: tw.page_help_item_status_approved_text ?? 'Siden er publisert og er den versjonen lesere og tilbudsgenerering får.',
                },
                {
                    title: tw.page_help_item_status_sync_title ?? 'Avventer synkronisering',
                    text: tw.page_help_item_status_sync_text ?? 'Kildedokumentet har Dokumenteier, men godkjenningsoppgaven for Wiki-siden er ennå ikke ferdig opprettet eller oppdatert. Dette er en teknisk mellomtilstand.',
                },
                {
                    title: tw.page_help_item_status_missing_owner_title ?? 'Mangler Dokumenteier',
                    text: tw.page_help_item_status_missing_owner_text ?? 'Siden bruker minst ett kildedokument som ikke har en gyldig, aktiv Dokumenteier.',
                },
                {
                    title: tw.page_help_item_status_rejected_title ?? 'Avvist',
                    text: tw.page_help_item_status_rejected_text ?? 'Minst én nødvendig Dokumenteier har avvist materialet. Materialet kan ikke fullføres før avviket er behandlet.',
                },
                {
                    title: tw.page_help_item_status_visibility_title ?? 'Hvem kan se en side, uansett status?',
                    text: tw.page_help_item_status_visibility_text ?? 'Alle autoriserte Wiki-brukere hos kunden kan åpne og lese en side uansett status — status styrer kun hvem som kan godkjenne, avvise, redigere eller publisere. Wiki-grafen bruker samme regel, slik at en side aldri vises i grafen uten også å kunne åpnes her.',
                },
            ],
        },
        {
            title: tw.page_help_section_verification ?? 'Verifikasjonsgrunnlag',
            items: [
                {
                    title: tw.page_help_item_verification_claims_title ?? 'Påstander må kontrolleres mot kildegrunnlaget',
                    text: tw.page_help_item_verification_claims_text ?? 'Verifikasjonsgrunnlag viser påstander som må kontrolleres, sammen med kildene som støtter dem. Her behandler du påstander frem til de er godkjent, avvist eller ellers ferdig vurdert.',
                },
                {
                    title: tw.page_help_item_verification_sources_title ?? 'Kildegrunnlag viser hvor påstanden kommer fra',
                    text: tw.page_help_item_verification_sources_text ?? 'Hver påstand kan vise hvilket dokument og hvilket utdrag som støtter den. Det gjør det enklere å vurdere om påstanden er godt nok dokumentert.',
                },
                {
                    title: tw.page_help_item_verification_structure_title ?? 'Strukturelle Wiki-funn vises separat',
                    text: tw.page_help_item_verification_structure_text ?? 'Feil i Wiki-strukturen, som manglende lenker mellom sider, vises i en egen del. De er ikke det samme som claim-verifikasjon.',
                },
            ],
        },
        {
            title: tw.page_help_section_changes ?? 'Når innhold eller eier endrer seg',
            items: [
                {
                    title: tw.page_help_item_changes_content_title ?? 'Vesentlige endringer kan kreve ny godkjenning',
                    text: tw.page_help_item_changes_content_text ?? 'En godkjenning gjelder en konkret Wiki-sideversjon. Hvis innholdet endrer seg vesentlig, kan siden trenge ny vurdering.',
                },
                {
                    title: tw.page_help_item_changes_owner_title ?? 'Nytt kildegrunnlag kan gi nye godkjenningsoppgaver',
                    text: tw.page_help_item_changes_owner_text ?? 'Hvis et dokument får ny eier eller nytt innhold, oppdateres hvem som skal godkjenne de sidene som faktisk bruker dokumentet.',
                },
                {
                    title: tw.page_help_item_changes_history_title ?? 'Historikken beholdes',
                    text: tw.page_help_item_changes_history_text ?? 'Tidligere godkjenninger blir ikke slettet. Gamle beslutninger skal fortsatt være synlige som historikk.',
                },
            ],
        },
        {
            title: tw.page_help_section_actions ?? 'Hva bør du gjøre?',
            items: [
                {
                    title: tw.page_help_item_actions_sources_title ?? 'Kontroller kildedokumentene først',
                    text: tw.page_help_item_actions_sources_text ?? 'Gå til Kildedokumenter, kontroller at hvert dokument har riktig Dokumenteier, og rett dokumenter som mangler eier.',
                },
                {
                    title: tw.page_help_item_actions_pages_title ?? 'Bruk Wiki-sider for å godkjenne materialet',
                    text: tw.page_help_item_actions_pages_text ?? 'Åpne Wiki-sider, kontroller innhold og kilder, og velg Godkjenn eller Avvis når du vurderer materialet som bygger på dokumentet.',
                },
                {
                    title: tw.page_help_item_actions_qa_title ?? 'La QA og Dokumenteier gjøre ulike jobber',
                    text: tw.page_help_item_actions_qa_text ?? 'QA kontrollerer kvaliteten på tvers av Wiki-materialet. Dokumenteiergodkjenning bekrefter at materialet er riktig forankret i det aktuelle kildegrunnlaget. Den ene erstatter ikke den andre.',
                },
            ],
        },
    ];
}

function getWikiSourcesHelpSections(tw) {
    return [
        {
            title: tw.sources_page_help_section_what ?? 'Hva er kildedokumenter?',
            items: [
                {
                    title: tw.sources_page_help_item_what_title ?? 'Faglig grunnlag for Wiki',
                    text: tw.sources_page_help_item_what_text ?? 'Kildedokumenter er dokumenter Procynia bruker som faglig grunnlag for Wiki-sider, påstander og kildehenvisninger. Dokumentet beholdes som sporbar kilde og blir ikke automatisk tatt i bruk bare fordi det er lastet opp.',
                },
                {
                    title: tw.sources_page_help_item_what_ai_title ?? 'Teksten trekkes ut og analyseres',
                    text: tw.sources_page_help_item_what_ai_text ?? 'Når et dokument lastes opp, leser Procynia teksten, analyserer innholdet og gjør det klart for videre Wiki-behandling. Bare materiale som faktisk bruker dokumentet, får det som kilde.',
                },
            ],
        },
        {
            title: tw.sources_page_help_section_workflow ?? 'Arbeidsflyten',
            items: [
                {
                    title: tw.sources_page_help_item_workflow_upload_title ?? 'Last opp dokumentet',
                    text: tw.sources_page_help_item_workflow_upload_text ?? 'Du laster opp et kildedokument og velger Dokumenteier hvis du har rettigheter til det.',
                },
                {
                    title: tw.sources_page_help_item_workflow_process_title ?? 'Dokumentet behandles først',
                    text: tw.sources_page_help_item_workflow_process_text ?? 'Procynia trekker ut og analyserer dokumentet før det kan brukes som grunnlag for nye Wiki-sider.',
                },
                {
                    title: tw.sources_page_help_item_workflow_wiki_title ?? 'Deretter kan Wiki-materiale genereres',
                    text: tw.sources_page_help_item_workflow_wiki_text ?? 'Når dokumentet er klart, kan du starte Wiki-utkast. De genererte sidene kobles til de faktiske kildene og går videre til claims, kvalitetskontroll, QA og Dokumenteiergodkjenning.',
                },
            ],
        },
        {
            title: tw.sources_page_help_section_owner ?? 'Dokumenteier',
            items: [
                {
                    title: tw.sources_page_help_item_owner_definition_title ?? 'Faglig ansvar for dokumentet',
                    text: tw.sources_page_help_item_owner_definition_text ?? 'Dokumenteieren er personen som har det faglige ansvaret for kildedokumentet. Dokumenteieren kan være en annen person enn den som lastet opp dokumentet.',
                },
                {
                    title: tw.sources_page_help_item_owner_scope_title ?? 'Én eier per dokument',
                    text: tw.sources_page_help_item_owner_scope_text ?? 'Ett kildedokument har én Dokumenteier. Én person kan eie flere dokumenter, og bare aktive og kvalifiserte brukere i samme kundemiljø kan velges.',
                },
                {
                    title: tw.sources_page_help_item_owner_approval_title ?? 'Eierskap er ikke godkjenning',
                    text: tw.sources_page_help_item_owner_approval_text ?? 'Å velge Dokumenteier godkjenner ikke Wiki-materialet automatisk. Dokumenteieren må senere vurdere den konkrete Wiki-sideversjonen som bygger på dokumentet.',
                },
            ],
        },
        {
            title: tw.sources_page_help_section_columns ?? 'Kolonnene i listen',
            items: [
                {
                    title: tw.sources_page_help_item_columns_filename_title ?? 'Filnavn',
                    text: tw.sources_page_help_item_columns_filename_text ?? 'Navnet på det opplastede kildedokumentet.',
                },
                {
                    title: tw.sources_page_help_item_columns_status_title ?? 'Status',
                    text: tw.sources_page_help_item_columns_status_text ?? 'Viser hvor langt selve dokumentbehandlingen har kommet, for eksempel Behandles, Ekstrahert eller Feilet.',
                },
                {
                    title: tw.sources_page_help_item_columns_wiki_title ?? 'Wiki-status',
                    text: tw.sources_page_help_item_columns_wiki_text ?? 'Viser status og fremdrift for Wiki-behandlingen som er knyttet til dokumentet, for eksempel I kø, Kjører, Genererer sider, Verifisering og lenking, QA eller Fullført / bestått.',
                },
                {
                    title: tw.sources_page_help_item_columns_owner_title ?? 'Dokumenteier',
                    text: tw.sources_page_help_item_columns_owner_text ?? 'Viser hvem som har det faglige ansvaret for dokumentet.',
                },
                {
                    title: tw.sources_page_help_item_columns_actions_title ?? 'Handlinger',
                    text: tw.sources_page_help_item_columns_actions_text ?? 'Her finner du handlinger som å lage Wiki-utkast, åpne kjøringer, se originaldokumentet eller slette dokumentet.',
                },
            ],
        },
        {
            title: tw.sources_page_help_section_statuses ?? 'Hva betyr statusene?',
            items: [
                {
                    title: tw.sources_page_help_item_status_uploaded_title ?? 'Lastet opp',
                    text: tw.sources_page_help_item_status_uploaded_text ?? 'Dokumentet er mottatt, men behandlingen er ikke ferdig.',
                },
                {
                    title: tw.sources_page_help_item_status_processing_title ?? 'Behandles',
                    text: tw.sources_page_help_item_status_processing_text ?? 'Procynia trekker ut eller analyserer innholdet.',
                },
                {
                    title: tw.sources_page_help_item_status_extracted_title ?? 'Ekstrahert',
                    text: tw.sources_page_help_item_status_extracted_text ?? 'Teksten er hentet ut, og dokumentet er klart for videre Wiki-behandling.',
                },
                {
                    title: tw.sources_page_help_item_status_completed_title ?? 'Fullført',
                    text: tw.sources_page_help_item_status_completed_text ?? 'Den aktuelle behandlingen er ferdig.',
                },
                {
                    title: tw.sources_page_help_item_status_failed_title ?? 'Feilet',
                    text: tw.sources_page_help_item_status_failed_text ?? 'Behandlingen stoppet på grunn av en feil. Åpne kjøringsinformasjonen for en forklaring og eventuell ny handling.',
                },
            ],
        },
        {
            title: tw.sources_page_help_section_changes ?? 'Når dokumentet endrer seg',
            items: [
                {
                    title: tw.sources_page_help_item_changes_owner_title ?? 'Ny eier gir nye oppgaver',
                    text: tw.sources_page_help_item_changes_owner_text ?? 'Når Dokumenteier endres, oppdateres hvem som står som ansvarlig for de Wiki-sidene som faktisk bruker dokumentet. Historikken beholdes.',
                },
                {
                    title: tw.sources_page_help_item_changes_delete_title ?? 'Sletting viser konsekvenser først',
                    text: tw.sources_page_help_item_changes_delete_text ?? 'Før du sletter et dokument, viser Procynia hvilke Wiki-sider, claims og lagrede Wiki-svar som kan bli påvirket. Et dokument kan være én av flere kilder, så ikke alt materiale forsvinner nødvendigvis.',
                },
                {
                    title: tw.sources_page_help_item_delete_full_title ?? '«Slett dokument og generert Wiki-innhold»',
                    text: tw.sources_page_help_item_delete_full_text ?? 'Denne handlingen sletter kildedokumentet og alt Enterprise Wiki-innhold som kun er generert fra det — dokumentversjoner, kjøringer, claims, kildehenvisninger og funn. Wiki-sider som også bygger på andre kildedokumenter blir ikke slettet, bare koblingen til dette dokumentet fjernes fra dem. Handlingen kan ikke angres. Dokumentet kan ikke slettes mens en Wiki-kjøring pågår for det. Sletting påvirker ikke kunden, andre brukere eller andre kildedokumenter.',
                },
            ],
        },
        {
            title: tw.sources_page_help_section_actions ?? 'Hva bør du gjøre?',
            items: [
                {
                    title: tw.sources_page_help_item_actions_upload_title ?? 'Ved nytt dokument',
                    text: tw.sources_page_help_item_actions_upload_text ?? 'Last opp dokumentet, velg riktig Dokumenteier og følg behandlingen under Kjøringer til det er klart for Wiki-generering.',
                },
                {
                    title: tw.sources_page_help_item_actions_fail_title ?? 'Når behandlingen feiler',
                    text: tw.sources_page_help_item_actions_fail_text ?? 'Åpne kjøringen, les den forståelige feilteksten og kontroller dokumentformat og innhold før du prøver igjen.',
                },
                {
                    title: tw.sources_page_help_item_actions_delete_title ?? 'Når dokumentet skal slettes',
                    text: tw.sources_page_help_item_actions_delete_text ?? 'Les konsekvensanalysen nøye og kontroller hvilke Wiki-sider og svar som berøres før du bekrefter slettingen.',
                },
            ],
        },
    ];
}

function getWikiRunsHelpSections(tw) {
    return [
        {
            title: tw.runs_page_help_section_what ?? 'Hva er en behandlingskjøring?',
            items: [
                {
                    title: tw.runs_page_help_item_what_title ?? 'En samlet prosess',
                    text: tw.runs_page_help_item_what_text ?? 'En behandlingskjøring er en samlet prosess som behandler ett eller flere kildedokumenter og bygger eller oppdaterer Enterprise Wiki-materiale. En kjøring opprettes når Wiki-behandling startes, og hver kjøring har egen status og fremdrift.',
                },
                {
                    title: tw.runs_page_help_item_what_separate_title ?? 'Skilt fra vanlige svarutkast',
                    text: tw.runs_page_help_item_what_separate_text ?? 'Kjøringer er separat fra den vanlige svarmotoren og vanlige svarutkast. De produserer Wiki-materiale, claims og kontrollgrunnlag, ikke ordinære kravsvar.',
                },
            ],
        },
        {
            title: tw.runs_page_help_section_when ?? 'Når opprettes en kjøring?',
            items: [
                {
                    title: tw.runs_page_help_item_when_start_title ?? 'Når Wiki-behandling startes',
                    text: tw.runs_page_help_item_when_start_text ?? 'En kjøring kan opprettes når brukeren velger Lag Wiki for et kildedokument, eller når eksisterende Wiki-materiale må behandles på nytt eller gjenopprettes gjennom eksisterende UI.',
                },
                {
                    title: tw.runs_page_help_item_when_scope_title ?? 'Én kjøring kan dekke mer enn ett steg',
                    text: tw.runs_page_help_item_when_scope_text ?? 'En kjøring kan være knyttet til ett bestemt dokument eller en større Wiki-behandling. Hvilke steg som vises, avhenger av hvilken behandling kjøringen utfører.',
                },
            ],
        },
        {
            title: tw.runs_page_help_section_progress ?? 'Hvordan lese fremdriften?',
            items: [
                {
                    title: tw.runs_page_help_item_progress_status_title ?? 'Status',
                    text: tw.runs_page_help_item_progress_status_text ?? 'Status viser den overordnede tilstanden til kjøringen, for eksempel Venter, Behandles, Genererer Wiki-sider, Avventer QA, Fullført eller Feilet.',
                },
                {
                    title: tw.runs_page_help_item_progress_activity_title ?? 'Aktivitet',
                    text: tw.runs_page_help_item_progress_activity_text ?? 'Aktivitet viser hva kjøringen arbeider med akkurat nå. Den er mer nyttig enn status alene når behandlingen er i gang.',
                },
                {
                    title: tw.runs_page_help_item_progress_bar_title ?? 'Fremdrift og siste fremdrift',
                    text: tw.runs_page_help_item_progress_bar_text ?? 'Fremdrift viser hvor mye av den kjente behandlingen som er fullført. Siste fremdrift viser når kjøringen sist rapporterte faktisk fremgang.',
                },
            ],
        },
        {
            title: tw.runs_page_help_section_statuses ?? 'Hva betyr statusene?',
            items: [
                {
                    title: tw.runs_page_help_item_status_queued_title ?? 'Venter',
                    text: tw.runs_page_help_item_status_queued_text ?? 'Kjøringen er opprettet, men behandlingen har ikke startet ennå.',
                },
                {
                    title: tw.runs_page_help_item_status_running_title ?? 'I arbeid',
                    text: tw.runs_page_help_item_status_running_text ?? 'Procynia arbeider med kjøringen. Fremdriften i detaljene viser hvilket steg den er i.',
                },
                {
                    title: tw.runs_page_help_item_status_pages_title ?? 'Genererer Wiki-sider',
                    text: tw.runs_page_help_item_status_pages_text ?? 'Wiki-sider eller sideversjoner bygges fra kildedokumentene.',
                },
                {
                    title: tw.runs_page_help_item_status_verify_title ?? 'Kontrollerer påstander',
                    text: tw.runs_page_help_item_status_verify_text ?? 'Påstander og kildegrunnlag verifiseres, og lenker og relasjoner bygges.',
                },
                {
                    title: tw.runs_page_help_item_status_qa_title ?? 'Kvalitetskontroll',
                    text: tw.runs_page_help_item_status_qa_text ?? 'Systemet kontrollerer struktur, dekning, kildegrunnlag og øvrige kvalitetskrav.',
                },
                {
                    title: tw.runs_page_help_item_status_missing_owner_title ?? 'Mangler Dokumenteier',
                    text: tw.runs_page_help_item_status_missing_owner_text ?? 'Et brukt kildedokument mangler en gyldig, aktiv Dokumenteier.',
                },
                {
                    title: tw.runs_page_help_item_status_completed_title ?? 'Fullført',
                    text: tw.runs_page_help_item_status_completed_text ?? 'Alle nødvendige behandlingssteg og ferdigkontroller er fullført.',
                },
                {
                    title: tw.runs_page_help_item_status_failed_title ?? 'Feilet',
                    text: tw.runs_page_help_item_status_failed_text ?? 'Behandlingen stoppet på grunn av en feil. Feilmeldingen skal beskrive problemet på vanlig språk, uten at interne jobb- eller exception-navn er nødvendig.',
                },
                {
                    title: tw.runs_page_help_item_status_escalated_title ?? 'Krever oppfølging',
                    text: tw.runs_page_help_item_status_escalated_text ?? 'Kjøringen ble stoppet for kontroll, og noe må følges opp før den kan regnes som avsluttet — for eksempel åpne QA-funn eller sider som ikke ble generert.',
                },
                {
                    title: tw.runs_page_help_item_status_completed_with_remarks_title ?? 'Oppfølging fullført',
                    text: tw.runs_page_help_item_status_completed_with_remarks_text ?? 'Kjøringen ble stoppet for kontroll underveis, men all oppfølging er gjort. Merknadene står i detaljene.',
                },
                {
                    title: tw.runs_page_help_item_status_decision_only_title ?? 'Beslutning lagret',
                    text: tw.runs_page_help_item_status_decision_only_text ?? 'Kjøringen er brukt til å registrere en beslutning uten å starte den fulle behandlingsflyten.',
                },
                {
                    title: tw.runs_page_help_item_status_cancelled_title ?? 'Avbrutt',
                    text: tw.runs_page_help_item_status_cancelled_text ?? 'Kjøringen ble manuelt avbrutt av en bruker, vanligvis for å gjøre kildedokumentet slettbart. Wiki-innhold som allerede var generert, er beholdt.',
                },
            ],
        },
        {
            title: tw.runs_page_help_section_pages ?? 'Berørte Wiki-sider',
            items: [
                {
                    title: tw.runs_page_help_item_pages_what_title ?? 'Sider viser berørte Wiki-sider',
                    text: tw.runs_page_help_item_pages_what_text ?? '«Sider» viser hvor mange Wiki-sider dokumentet opprettet eller oppdaterte. Klikk på tallet for å se hvilke sider det gjelder, uten å forlate kjøringslisten.',
                },
                {
                    title: tw.runs_page_help_item_pages_created_updated_title ?? 'Opprettet og Oppdatert',
                    text: tw.runs_page_help_item_pages_created_updated_text ?? '«Opprettet» betyr at kjøringen laget en helt ny Wiki-side. «Oppdatert» betyr at en side som allerede fantes, fikk en ny versjon.',
                },
                {
                    title: tw.runs_page_help_item_pages_owner_status_title ?? 'Dokumenteierstatus',
                    text: tw.runs_page_help_item_pages_owner_status_text ?? 'Dokumenteierstatus vises per sideversjon — den versjonen kjøringen faktisk produserte. Listen viser hvilken side eller handling som gjenstår før kjøringen kan anses som ferdig.',
                },
                {
                    title: tw.runs_page_help_item_pages_stalled_title ?? '«Ser ut til å stå stille»',
                    text: tw.runs_page_help_item_pages_stalled_text ?? '«Ser ut til å stå stille» betyr at ingen ny fremdrift er registrert på en stund. Det betyr ikke nødvendigvis at noe har feilet — kjøringen kan vente på en handling, for eksempel en Dokumenteiergodkjenning.',
                },
            ],
        },
        {
            title: tw.runs_page_help_section_findings ?? 'Kvalitetsfunn',
            items: [
                {
                    title: tw.runs_page_help_item_findings_what_title ?? 'Funn viser alle kvalitetsfunn',
                    text: tw.runs_page_help_item_findings_what_text ?? '«Funn» viser alle kvalitetsfunn som ble registrert under kjøringen. Klikk på tallet for å se hva funnene gjelder.',
                },
                {
                    title: tw.runs_page_help_item_findings_not_error_title ?? 'Ikke nødvendigvis en feil',
                    text: tw.runs_page_help_item_findings_not_error_text ?? 'Et funn er ikke nødvendigvis en feil som blokkerer. Funn kan være åpne, løste, aksepterte eller informative, og en kjøring kan være bestått selv om historiske eller ikke-blokkerende funn finnes.',
                },
                {
                    title: tw.runs_page_help_item_findings_action_title ?? 'Hva krever handling?',
                    text: tw.runs_page_help_item_findings_action_text ?? 'Panelet viser hvilke funn som faktisk krever handling, og hvilken side og sideversjon funnet gjelder når dette finnes.',
                },
                {
                    title: tw.runs_page_help_item_findings_best_practice_title ?? 'Forslag basert på beste praksis',
                    text: tw.runs_page_help_item_findings_best_practice_text ?? 'Beste-praksis-tekst er et forslag, ikke en feil. Forslaget kommer ikke direkte fra kundens kildedokument, og blokkerer ikke teknisk QA. Klikk Åpne og vurder for å gå direkte til teksten og godkjenne, redigere eller avvise den. Reelle udokumenterte faktapåstander behandles fortsatt som kvalitetsfeil.',
                },
            ],
        },
        {
            title: tw.runs_page_help_section_workflow ?? 'Aktiv behandling eller venter på en person?',
            items: [
                {
                    title: tw.runs_page_help_item_workflow_active_title ?? 'Aktiv behandling',
                    text: tw.runs_page_help_item_workflow_active_text ?? 'Procynia arbeider med analyse, generering, claims, lenker, kildekontroll og kvalitetskontroll. Mens en kjøring er aktiv, oppdaterer Procynia visningen automatisk slik at ny fremdrift blir synlig uten at siden må lastes på nytt.',
                },
                {
                    title: tw.runs_page_help_item_workflow_waiting_title ?? 'Venter på menneskelig handling',
                    text: tw.runs_page_help_item_workflow_waiting_text ?? 'Kjøringen kan vente på QA, Dokumenteiergodkjenning eller tildeling av manglende Dokumenteier. Da er den ikke nødvendigvis stoppet av en feil, men venter på neste kontroll eller beslutning.',
                },
            ],
        },
        {
            title: tw.runs_page_help_section_actions ?? 'Hva skal jeg gjøre?',
            items: [
                {
                    title: tw.runs_page_help_item_actions_active_title ?? 'Når en kjøring er aktiv',
                    text: tw.runs_page_help_item_actions_active_text ?? 'Kontroller aktivitet og siste fremdrift, og la behandlingen fortsette dersom tallene fortsatt oppdateres.',
                },
                {
                    title: tw.runs_page_help_item_actions_qa_title ?? 'Når kjøringen venter på QA',
                    text: tw.runs_page_help_item_actions_qa_text ?? 'Åpne relevant kvalitets- eller QA-visning og fullfør den autoriserte kontrollen.',
                },
                {
                    title: tw.runs_page_help_item_actions_owner_title ?? 'Når kjøringen venter på Dokumenteier',
                    text: tw.runs_page_help_item_actions_owner_text ?? 'Dokumenteieren åpner den aktuelle Wiki-siden, kontrollerer materiale og kilder, og velger Godkjenn eller Avvis.',
                },
                {
                    title: tw.runs_page_help_item_actions_fail_title ?? 'Når kjøringen feiler',
                    text: tw.runs_page_help_item_actions_fail_text ?? 'Les den brukerrettede feilforklaringen, kontroller hvilket dokument og steg som er berørt, og bruk eventuell eksisterende ny behandling i UI-et dersom det finnes.',
                },
                {
                    title: tw.runs_page_help_item_actions_cancel_title ?? 'Avbryt kjøring',
                    text: tw.runs_page_help_item_actions_cancel_text ?? 'Hvis en kjøring venter lenge uten å gå videre — for eksempel på en Dokumenteier som ikke er tilgjengelig — kan Systemansvarlig eller kildedokumentets Dokumenteier bruke Avbryt kjøring for å stoppe den. Allerede generert Wiki-innhold beholdes, og kildedokumentet blir deretter slettbart.',
                },
            ],
        },
    ];
}

// The status words a run is shown with (runStatusKey() in runFindingsLogic.js decides which).
const RUN_STATUS_FALLBACK_LABELS = {
    waiting: 'Venter',
    in_progress: 'I arbeid',
    completed: 'Fullført',
    completed_with_remarks: 'Oppfølging fullført',
    needs_follow_up: 'Krever oppfølging',
    failed: 'Feilet',
    cancelled: 'Avbrutt',
    decision_only: 'Sideplan lagret',
    unknown: 'Ukjent status',
};

// Krever oppfølging is the warning; Oppfølging fullført is calm, but not the plain success
// green; Feilet stays red and Fullført green.
const RUN_STATUS_STYLES = {
    waiting: 'bg-slate-100 text-slate-700',
    in_progress: 'bg-sky-100 text-sky-700',
    completed: 'bg-emerald-100 text-emerald-700',
    completed_with_remarks: 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-300',
    needs_follow_up: 'bg-amber-100 text-amber-800',
    failed: 'bg-rose-100 text-rose-700',
    cancelled: 'bg-slate-200 text-slate-600',
    decision_only: 'bg-violet-100 text-violet-700',
    unknown: 'bg-slate-100 text-slate-600',
};

function runStatusKeyLabel(key, tw = {}) {
    return tw[`run_status_${key}`] ?? RUN_STATUS_FALLBACK_LABELS[key] ?? RUN_STATUS_FALLBACK_LABELS.unknown;
}

function runStatusLabel(run, tw = {}) {
    return runStatusKeyLabel(runStatusKey(run), tw);
}

function runStatusClass(run) {
    return RUN_STATUS_STYLES[runStatusKey(run)] ?? RUN_STATUS_STYLES.unknown;
}

const SOURCE_INLINE_LINK = 'rounded text-base font-semibold leading-6 text-violet-700 underline decoration-violet-300 underline-offset-2 transition hover:text-violet-900 hover:decoration-violet-500 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600';

/**
 * The Wiki-status cell of one source document: the run's status, at most one short line saying what
 * it means, and the way on to where the rest is.
 *
 * The full account of a run — the backend's own error text, failed pages, findings, retries — lives
 * on Kjøringer, filtered to this document. It used to be repeated here, so a failed run put a long
 * English exception into the middle of the document list. The list now says what happened in a
 * sentence and «Se detaljer» opens the run where that information is explained and can be acted on.
 */
function IngestStatusBadge({ run, label, notStartedLabel, locale, onReload, tw, onViewDecision, detailsHref, describedById }) {
    if (!run) {
        return <span className={`${BADGE} bg-slate-100 text-slate-600`}>{notStartedLabel}</span>;
    }

    if (run.status === 'decision_only') {
        return (
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span className={`${BADGE} whitespace-normal bg-violet-100 text-violet-700`}>{label}</span>
                {run.maintainer_decision_json && onViewDecision && (
                    <button
                        type="button"
                        onClick={(event) => onViewDecision(run, event.currentTarget)}
                        aria-describedby={describedById}
                        className={SOURCE_INLINE_LINK}
                    >
                        {tw.decision_panel_view_button ?? 'Vis beslutning'}
                    </button>
                )}
            </div>
        );
    }

    const cls = runStatusClass(run);
    const isInProgress = IN_PROGRESS_STATUSES.includes(run.status);
    const seemsStalled = isRunStalled(run);
    const progressAt = run.last_progress_at ?? run.updated_at ?? run.started_at ?? run.created_at;

    // One line at most, and never the backend's own error text (see the docblock).
    let summary = null;
    let summaryTone = 'text-slate-600';

    if (run.status === 'failed') {
        summary = tw.source_run_summary_failed ?? 'Kjøringen stoppet før Wiki-innholdet var ferdig.';
        summaryTone = 'text-rose-700';
    } else if (run.status === 'escalated') {
        summary = tw.source_run_summary_escalated ?? 'Krever oppfølging.';
        summaryTone = 'text-amber-800';
    } else if (seemsStalled) {
        summary = tw.ingest_activity_stalled ?? 'Ser ut til å stå stille';
        summaryTone = 'text-amber-800';
    } else if (run.status === 'queued') {
        const queuedSince = formatTime(run.created_at, locale);
        summary = queuedSince
            ? (tw.ingest_activity_queued_since ?? 'I kø siden :time').replace(':time', queuedSince)
            : null;
    } else if (isInProgress && progressAt) {
        summary = `${tw.ingest_activity_last_progress ?? 'Siste fremdrift'} ${formatRelativeProgress(progressAt, locale)}`;
    }

    const needsAttention = run.status === 'failed' || run.status === 'escalated' || seemsStalled;

    return (
        <div className="min-w-0 space-y-0.5">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span className={`${BADGE} whitespace-normal ${cls}`}>{label}</span>
                {isInProgress && (
                    <button
                        type="button"
                        onClick={onReload}
                        className={SOURCE_INLINE_LINK}
                    >
                        {tw.ingest_activity_reload ?? 'Oppdater'}
                    </button>
                )}
            </div>
            {summary && (
                <p className={`text-base leading-6 ${summaryTone}`}>{summary}</p>
            )}
            {needsAttention && detailsHref && (
                <Link href={detailsHref} aria-describedby={describedById} className={SOURCE_INLINE_LINK}>
                    {tw.source_run_details_link ?? 'Se detaljer'}
                </Link>
            )}
        </div>
    );
}

/**
 * The Wiki pages a document has produced, behind a disclosure: the count is what the list needs to
 * show, and the titles one at a time made every finished row as tall as its page count.
 */
function SourceGeneratedPages({ pages, panelId, describedById, tw }) {
    const [isOpen, setIsOpen] = useState(false);

    if (!pages || pages.length === 0) {
        return null;
    }

    const toggleLabel = pages.length === 1
        ? (tw.source_generated_pages_one ?? '1 Wiki-side')
        : (tw.source_generated_pages_many ?? ':count Wiki-sider').replace(':count', String(pages.length));

    return (
        <div className="mt-1">
            <button
                type="button"
                aria-expanded={isOpen}
                aria-controls={panelId}
                aria-describedby={describedById}
                onClick={() => setIsOpen((open) => !open)}
                className={`inline-flex items-center gap-1 ${SOURCE_INLINE_LINK}`}
            >
                {toggleLabel}
                <svg className={`h-4 w-4 shrink-0 transition ${isOpen ? 'rotate-180' : ''}`} viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fillRule="evenodd" d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z" clipRule="evenodd" />
                </svg>
            </button>
            {isOpen && (
                <ul id={panelId} className="mt-1 space-y-0.5">
                    {pages.map((p) => (
                        <li key={p.id}>
                            <Link
                                href={`/app/wiki/${p.slug}`}
                                className="block wrap-break-word text-base leading-6 text-violet-700 hover:text-violet-900 hover:underline"
                            >
                                {p.title}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * The document owner as a name, with a small pencil for whoever may change it. Changing it opens
 * a dialog (SourcesTab) rather than putting a select and a save button in every row.
 */
function SourceOwner({ source, canEdit, onEdit, describedById, tw }) {
    const editLabel = tw.source_owner_edit ?? 'Endre dokumenteier';

    return (
        <div className="flex min-w-0 items-center gap-1">
            <span className={`min-w-0 wrap-break-word text-base leading-6 ${source.owner_name ? 'text-slate-700' : 'font-medium text-amber-800'}`}>
                {source.owner_name ?? (tw.document_owner_missing ?? 'Mangler Dokumenteier')}
            </span>
            {canEdit && (
                <button
                    type="button"
                    onClick={(event) => onEdit(source, event.currentTarget)}
                    aria-label={editLabel}
                    aria-describedby={describedById}
                    title={editLabel}
                    className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-slate-500"
                >
                    <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="m5.433 13.917 1.262-3.155A4 4 0 0 1 7.58 9.42l6.92-6.918a2.121 2.121 0 0 1 3 3l-6.92 6.918c-.383.383-.84.685-1.343.886l-3.154 1.262a.5.5 0 0 1-.65-.65Z" />
                        <path d="M3.5 5.75c0-.69.56-1.25 1.25-1.25H10A.75.75 0 0 0 10 3H4.75A2.75 2.75 0 0 0 2 5.75v9.5A2.75 2.75 0 0 0 4.75 18h9.5A2.75 2.75 0 0 0 17 15.25V10a.75.75 0 0 0-1.5 0v5.25c0 .69-.56 1.25-1.25 1.25h-9.5c-.69 0-1.25-.56-1.25-1.25v-9.5Z" />
                    </svg>
                </button>
            )}
        </div>
    );
}

/**
 * «Lag Wiki» as the row's one visible action, and «Last ned» / «Slett» behind a `⋯` disclosure.
 *
 * The disclosure follows QualityItemActions (A11Y-103): not `role="menu"`, which would promise
 * arrow-key navigation; Tab reaches the items, Escape closes and returns focus to the trigger, and
 * so does Tab-ing out of the panel. The panel is `fixed`, measured off the trigger, because the list
 * sits in a scroll box that would clip an absolutely positioned one; it is re-measured on scroll and
 * resize. It also names the whole document, which the row clips to two lines.
 */
function SourceRowActions({
    source,
    panelId,
    describedById,
    canIngest,
    ingesting,
    ingestDisabled,
    ingestDisabledTitle,
    onIngest,
    canDelete,
    deleteBlocked,
    onDelete,
    showNotAvailableNote,
    tw,
}) {
    const [isOpen, setIsOpen] = useState(false);
    const [position, setPosition] = useState(null);
    const triggerRef = useRef(null);
    const panelRef = useRef(null);

    // A layout effect: the panel needs its position to render at all, and measured after paint it
    // appeared one frame after aria-expanded did — a Tab pressed in between skipped past it.
    useLayoutEffect(() => {
        if (!isOpen) {
            return undefined;
        }

        const place = () => {
            const rect = triggerRef.current?.getBoundingClientRect();

            if (!rect) return;

            // Follows the trigger while the page or the list scrolls (focusing the trigger from the
            // keyboard can itself scroll it into view after the panel opened); closes only once
            // the trigger has left the screen.
            if (rect.bottom < 0 || rect.top > window.innerHeight) {
                setIsOpen(false);
                return;
            }

            // Opens upwards when there is no room below — the last rows of the list sit at the
            // bottom of the viewport — and from the trigger's left edge when ending at its right
            // edge would push the panel off the screen (the cards on a phone).
            const width = Math.min(256, window.innerWidth - 16);
            const openUp = rect.bottom + 200 > window.innerHeight && rect.top > 200;
            const alignLeft = rect.right - width < 8;
            setPosition({
                ...(alignLeft
                    ? { left: Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)) }
                    : { right: Math.max(8, window.innerWidth - rect.right) }),
                ...(openUp ? { bottom: window.innerHeight - rect.top + 6 } : { top: rect.bottom + 6 }),
            });
        };

        const handlePointerDown = (event) => {
            if (!panelRef.current?.contains(event.target) && !triggerRef.current?.contains(event.target)) {
                setIsOpen(false);
            }
        };

        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                setIsOpen(false);
                triggerRef.current?.focus();
            }
        };

        place();
        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);
        window.addEventListener('resize', place);
        window.addEventListener('scroll', place, true);

        return () => {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
            window.removeEventListener('resize', place);
            window.removeEventListener('scroll', place, true);
        };
    }, [isOpen]);

    const handlePanelBlur = (event) => {
        const next = event.relatedTarget;

        if (next && !panelRef.current?.contains(next) && !triggerRef.current?.contains(next)) {
            setIsOpen(false);
        }
    };

    const menuLabel = tw.source_more_actions ?? 'Flere handlinger';
    const activeRunTitle = tw.document_has_active_run ?? 'Dokumentet har en aktiv kjøring';
    const panelItem = 'flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-base font-semibold transition focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2';

    return (
        <div className="flex flex-col items-start gap-1">
            <div className="flex items-center gap-1.5">
                {canIngest && (
                    <button
                        type="button"
                        disabled={ingestDisabled}
                        title={ingestDisabledTitle}
                        aria-describedby={describedById}
                        onClick={onIngest}
                        className={ACTION_BUTTON_PRIMARY}
                    >
                        {ingesting ? (tw.source_ingest_starting ?? 'Starter...') : (tw.source_ingest_button ?? 'Lag Wiki')}
                    </button>
                )}
                <button
                    ref={triggerRef}
                    type="button"
                    aria-expanded={isOpen}
                    aria-controls={isOpen ? panelId : undefined}
                    aria-label={menuLabel}
                    aria-describedby={describedById}
                    title={menuLabel}
                    onClick={() => setIsOpen((open) => !open)}
                    className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-500"
                >
                    <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M4 10a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Zm4.5 0a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Zm4.5 0a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Z" />
                    </svg>
                </button>
            </div>
            {showNotAvailableNote && (
                <span className="text-base leading-6 text-slate-600">
                    {tw.source_ingest_not_available ?? 'Wiki-generering er ikke aktivert ennå.'}
                </span>
            )}

            {isOpen && position && (
                <div
                    ref={panelRef}
                    id={panelId}
                    onBlur={handlePanelBlur}
                    style={{ top: position.top, bottom: position.bottom, left: position.left, right: position.right }}
                    className="fixed z-40 w-64 max-w-[calc(100vw-1rem)] rounded-2xl border border-slate-200 bg-white p-1.5 text-left shadow-lg"
                >
                    <p className="break-all px-3 pb-1.5 pt-1 text-base leading-6 text-slate-600">{source.original_filename}</p>
                    <a
                        href={`/app/wiki/sources/${source.id}/download`}
                        target="_blank"
                        rel="noreferrer"
                        aria-describedby={describedById}
                        onClick={() => setIsOpen(false)}
                        className={`${panelItem} text-slate-700 hover:bg-slate-50 focus-visible:outline-slate-500`}
                    >
                        <svg className="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.614L6.295 8.235a.75.75 0 1 0-1.09 1.03l4.25 4.5a.75.75 0 0 0 1.09 0l4.25-4.5a.75.75 0 0 0-1.09-1.03l-2.955 3.129V2.75Z" />
                            <path d="M3.5 12.75a.75.75 0 0 0-1.5 0v2.5A2.75 2.75 0 0 0 4.75 18h10.5A2.75 2.75 0 0 0 18 15.25v-2.5a.75.75 0 0 0-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5Z" />
                        </svg>
                        {tw.source_download_button ?? 'Last ned'}
                    </a>
                    {canDelete && (
                        <>
                            {/* Disabled while the pipeline is still working on the document; the
                                reason is said in the panel, not only in a tooltip. */}
                            <button
                                type="button"
                                disabled={deleteBlocked}
                                title={deleteBlocked ? activeRunTitle : undefined}
                                aria-describedby={describedById}
                                onClick={() => {
                                    setIsOpen(false);
                                    onDelete(source, triggerRef.current);
                                }}
                                className={`${panelItem} text-rose-700 hover:bg-rose-50 focus-visible:outline-rose-500 disabled:cursor-not-allowed disabled:text-slate-400 disabled:hover:bg-transparent`}
                            >
                                <svg className="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fillRule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.519.149.022a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 3.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 7.5a.75.75 0 1 0 1.5-.06l-.3-7.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 7.5a.75.75 0 1 0 1.5.06l.3-7.5Z" clipRule="evenodd" />
                                </svg>
                                {tw.source_delete_button ?? 'Slett'}
                            </button>
                            {deleteBlocked && (
                                <p className="px-3 pb-1 text-base leading-6 text-slate-600">{activeRunTitle}</p>
                            )}
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

function DecisionPageEntry({ entry, tw }) {
    if (!entry) return <span className="text-sm text-slate-600">{tw.decision_no_items ?? 'Ingen'}</span>;
    return (
        <dl className="space-y-0.5 text-sm">
            <div className="flex flex-wrap gap-1.5">
                <dt className="text-slate-600">{tw.decision_action_label ?? 'Handling'}:</dt>
                <dd className="font-mono font-semibold text-violet-700">{entry.action}</dd>
            </div>
            {entry.title && (
                <div className="flex flex-wrap gap-1.5">
                    <dt className="text-slate-600">{tw.decision_title_label ?? 'Tittel'}:</dt>
                    <dd className="text-slate-800">{entry.title}</dd>
                </div>
            )}
            {entry.proposed_slug && (
                <div className="flex flex-wrap gap-1.5">
                    <dt className="text-slate-600">{tw.decision_slug_label ?? 'Slug'}:</dt>
                    <dd className="break-all font-mono text-slate-700">{entry.proposed_slug}</dd>
                </div>
            )}
            {entry.reason && (
                <div className="flex flex-wrap gap-1.5">
                    <dt className="shrink-0 text-slate-600">{tw.decision_reason_label ?? 'Begrunnelse'}:</dt>
                    <dd className="text-slate-700">{entry.reason}</dd>
                </div>
            )}
        </dl>
    );
}

// ─── Pages tab ───────────────────────────────────────────────────────────────

/**
 * The Wiki page list's own controls: one height for everything on the toolbar row, and 16px text,
 * so a filter reads like something you operate rather than fine print.
 */
const PAGES_CONTROL_CLS = 'h-11 rounded-lg border border-slate-200 bg-white px-3 text-base text-slate-700 shadow-sm transition focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100';

// max-w-full: a native select is as wide as its longest option, so one long document or owner
// name in a filter would otherwise push the row past a phone's edge.
const SELECT_CLS = 'h-11 rounded-lg max-w-full border border-slate-200 bg-white px-3 text-base text-slate-700 shadow-sm transition focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100';

/**
 * How far the Wiki has come, in one line, plus the one thing worth saying when it has not started.
 *
 * Counted by the backend over every visible page, not the current filter — a filtered count would
 * answer a different question than the one a reader of this strip is asking.
 */
function PublicationSummary({ summary, tw }) {
    if (! summary || (summary.total ?? 0) === 0) {
        return null;
    }

    // The number leads and the label explains it, which is the order somebody reads a count in.
    // Colour is carried by the number alone and kept close to the text weight of the page — four
    // filled cards would make a status summary louder than the pages it summarises.
    const parts = [
        [summary.total, tw.publication_summary_total ?? 'sider', 'text-slate-900'],
        [summary.published, tw.publication_summary_published ?? 'publisert', 'text-emerald-700'],
        [summary.in_review, tw.publication_summary_in_review ?? 'til gjennomgang', 'text-amber-700'],
        [summary.draft, tw.publication_summary_draft ?? 'utkast', 'text-slate-500'],
        // Only when they exist: a zero here would read as a problem rather than an absence.
        ...((summary.changes_requested ?? 0) > 0
            ? [[summary.changes_requested, tw.publication_summary_changes_requested ?? 'endringer kreves', 'text-rose-700']]
            : []),
        ...((summary.unpublished_changes ?? 0) > 0
            ? [[summary.unpublished_changes, tw.publication_summary_unpublished_changes ?? 'med upubliserte endringer', 'text-sky-700']]
            : []),
    ];

    return (
        <div className="space-y-3">
            <div
                className="flex flex-wrap items-stretch divide-x divide-slate-200 rounded-2xl border border-slate-200 bg-white"
                data-testid="wiki-publication-summary"
            >
                {parts.map(([value, label, tone]) => (
                    <div key={label} className="flex min-w-[9rem] flex-1 flex-col gap-0.5 px-5 py-4">
                        <span className={`text-[22px] font-semibold leading-none ${tone}`}>{value ?? 0}</span>
                        <span className="text-base leading-6 text-slate-500">{label}</span>
                    </div>
                ))}
            </div>

            {/* Deliberately about tender drafting only. Spør Wiki grounds in the current working
                versions, so saying "the Wiki cannot be used" would be false. */}
            {(summary.published ?? 0) === 0 && (
                <div
                    className="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4"
                    data-testid="wiki-none-published-notice"
                >
                    <p className="text-base font-semibold text-amber-900">
                        {tw.publication_none_published_title ?? 'Ingen Wiki-sider er publisert ennå.'}
                    </p>
                    <p className="mt-1 text-base text-amber-900">
                        {tw.publication_none_published_body
                            ?? 'Tilbudsgenerering bruker publiserte Wiki-sider som kunnskapsgrunnlag. Spør Wiki bruker gjeldende arbeidsversjoner og påvirkes ikke.'}
                    </p>
                </div>
            )}
        </div>
    );
}

/** The one sentence that says what has to happen to this page next, and what is stopping it. */
function PageNextStep({ publication, tw }) {
    if (! publication || publication.next_step === 'none') {
        return null;
    }

    return (
        <div className="mt-1 space-y-0.5" data-testid="wiki-page-next-step">
            <div className="text-sm text-slate-500">{publication.next_step_label}</div>
            {(publication.blocking_reasons ?? []).map((reason) => (
                <div key={reason} className="text-sm text-amber-700">{reason}</div>
            ))}
        </div>
    );
}

function PagesTab({ pages, pagesMeta, pagesFilters, pagesDocumentOwnerOptions = [], publicationSummary = null, tw, ta = {}, locale }) {
    const filters = pagesFilters ?? {};
    const meta = pagesMeta ?? { current_page: 1, last_page: 1, total: 0, per_page: 25 };

    // The number of pages a search or filter left, said once when the filters change (4.1.3). Keyed on
    // the filters, not the result: the 5 s poll while a run works reloads the list with the same
    // filters and stays quiet, and focus stays in the control the user changed.
    useTransitionAnnouncement(JSON.stringify([
        filters.search ?? '', filters.page_type ?? '', filters.status ?? '', filters.lint ?? '',
        filters.document_owner ?? '', filters.sort ?? '', meta.current_page ?? 1,
    ]), () => ({
        message: meta.total > 0
            ? (ta.wiki_pages_result ?? ':count sider.').replace(':count', String(meta.total))
            : (tw.empty_title ?? 'Ingen wiki-sider ennå'),
    }));

    const [searchInput, setSearchInput] = useState(filters.search ?? '');

    const navigate = (overrides) => {
        router.get('/app/wiki', {
            tab: 'pages',
            search: filters.search ?? '',
            page_type: filters.page_type ?? '',
            status: filters.status ?? '',
            lint: filters.lint ?? '',
            // Carried on every navigation so changing type/status/sort keeps the chosen owner.
            document_owner: filters.document_owner ?? '',
            sort: filters.sort ?? 'updated_at_desc',
            ...overrides,
        }, { preserveState: true, preserveScroll: true });
    };

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        navigate({ search: searchInput, page: 1 });
    };

    const statusLabel = (status) => ({
        approved: tw.status_approved ?? 'Publisert',
        pending_review: tw.status_pending_review ?? 'Til gjennomgang',
        draft: tw.status_draft ?? 'Utkast',
        rejected: tw.status_rejected ?? 'Avvist',
        archived: tw.status_archived ?? 'Arkivert',
    }[status] ?? status);

    const pageTypeLabel = (type) => ({
        article: tw.page_type_article ?? 'Kildeartikkel',
        summary: tw.page_type_summary ?? 'Sammendrag',
        concept: tw.page_type_concept ?? 'Konsept',
        entity: tw.page_type_entity ?? 'Entitet',
    }[type] ?? type);

    const hasActiveFilters = !!(filters.search || filters.page_type || filters.status || filters.lint || filters.document_owner);

    return (
        <div className="space-y-4">
            <h2 className="sr-only">{ta.wiki_pages_list_label ?? 'Wiki-sider'}</h2>
            <PublicationSummary summary={publicationSummary} tw={tw} />

            {/* One toolbar rather than a row of loose controls: search leads, the filters sit
                beside it as the secondary things they are, and the count stays out of the way on
                the right. Everything on one height so the row reads as a single surface. */}
            <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-3">
                <form role="search" aria-label={ta.wiki_pages_search_label ?? 'Søk i Wiki-sider'} onSubmit={handleSearchSubmit} className="flex min-w-0 flex-1 items-center gap-2 sm:flex-none">
                    <label htmlFor="wiki-pages-search" className="sr-only">{ta.wiki_pages_search_label ?? 'Søk i Wiki-sider'}</label>
                    <input
                        id="wiki-pages-search"
                        type="search"
                        value={searchInput}
                        onChange={(e) => setSearchInput(e.target.value)}
                        placeholder={tw.filter_search_placeholder ?? 'Søk...'}
                        className={PAGES_CONTROL_CLS + ' w-full min-w-0 sm:w-72'}
                    />
                    <button
                        type="submit"
                        aria-label={tw.filter_search_placeholder ?? 'Søk...'}
                        className="inline-flex h-11 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white px-3 text-base font-semibold text-slate-600 shadow-sm transition hover:border-slate-300 hover:text-slate-900"
                    >
                        <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fillRule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.452 4.391l3.328 3.329a.75.75 0 1 1-1.06 1.06l-3.329-3.328A7 7 0 0 1 2 9Z" clipRule="evenodd" />
                        </svg>
                    </button>
                </form>

                {/* Each filter is named by what it filters (A11Y-048); its first option alone («Alle»)
                    said nothing about which list it narrowed. */}
                <select
                    aria-label={ta.wiki_filter_page_type ?? 'Sidetype'}
                    value={filters.page_type ?? ''}
                    onChange={(e) => navigate({ page_type: e.target.value, page: 1 })}
                    className={PAGES_CONTROL_CLS}
                >
                    <option value="">{tw.filter_page_type_all ?? 'Alle typer'}</option>
                    <option value="article">{tw.page_type_article ?? 'Kildeartikkel'}</option>
                    <option value="summary">{tw.page_type_summary ?? 'Sammendrag'}</option>
                    <option value="concept">{tw.page_type_concept ?? 'Konsept'}</option>
                    <option value="entity">{tw.page_type_entity ?? 'Entitet'}</option>
                </select>

                <select
                    aria-label={ta.wiki_filter_status ?? 'Status'}
                    value={filters.status ?? ''}
                    onChange={(e) => navigate({ status: e.target.value, page: 1 })}
                    className={PAGES_CONTROL_CLS}
                >
                    <option value="">{tw.filter_status_all ?? 'Alle statuser'}</option>
                    <option value="approved">{tw.status_approved ?? 'Godkjent'}</option>
                    <option value="pending_review">{tw.status_pending_review ?? 'Til gjennomgang'}</option>
                    <option value="draft">{tw.status_draft ?? 'Utkast'}</option>
                    <option value="rejected">{tw.status_rejected ?? 'Avvist'}</option>
                </select>

                {pagesDocumentOwnerOptions.length > 0 && (
                    <select
                        aria-label={ta.wiki_filter_document_owner ?? 'Dokumenteier'}
                        value={filters.document_owner ?? ''}
                        onChange={(e) => navigate({ document_owner: e.target.value, page: 1 })}
                        className={PAGES_CONTROL_CLS}
                    >
                        <option value="">{tw.filter_document_owner_all ?? 'Alle dokumenteiere'}</option>
                        {pagesDocumentOwnerOptions.map((owner) => (
                            <option key={owner.id} value={owner.id}>{owner.label}</option>
                        ))}
                    </select>
                )}

                <select
                    aria-label={ta.wiki_filter_lint ?? 'Kvalitetsfunn'}
                    value={filters.lint ?? ''}
                    onChange={(e) => navigate({ lint: e.target.value, page: 1 })}
                    className={PAGES_CONTROL_CLS}
                >
                    <option value="">{tw.filter_lint_all ?? 'Alle'}</option>
                    <option value="errors">{tw.filter_lint_errors ?? 'Har feil'}</option>
                    <option value="warnings">{tw.filter_lint_warnings ?? 'Har advarsler'}</option>
                    <option value="ok">{tw.filter_lint_ok ?? 'Ingen funn'}</option>
                </select>

                <select
                    aria-label={ta.wiki_filter_sort ?? 'Sortering'}
                    value={filters.sort ?? 'updated_at_desc'}
                    onChange={(e) => navigate({ sort: e.target.value, page: 1 })}
                    className={PAGES_CONTROL_CLS}
                >
                    <option value="updated_at_desc">{tw.filter_sort_updated_at_desc ?? 'Nyeste oppdatering'}</option>
                    <option value="title_asc">{tw.filter_sort_title_asc ?? 'Tittel A–Å'}</option>
                    <option value="created_at_desc">{tw.filter_sort_created_at_desc ?? 'Nyeste opprettet'}</option>
                </select>

                {hasActiveFilters && (
                    <button
                        type="button"
                        onClick={() => { setSearchInput(''); navigate({ search: '', page_type: '', status: '', lint: '', document_owner: '', sort: 'updated_at_desc', page: 1 }); }}
                        className="inline-flex h-11 items-center gap-1.5 rounded-lg px-3 text-base font-medium text-slate-600 transition hover:text-slate-800"
                    >
                        <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                        </svg>
                        {tw.filter_clear ?? 'Nullstill'}
                    </button>
                )}

                {meta.total > 0 && (
                    <span className="ml-auto whitespace-nowrap pr-1 text-base text-slate-500">
                        {meta.total} {tw.pages_total_label ?? 'sider totalt'}
                    </span>
                )}
            </div>

            {pages.length === 0 ? (
                <EmptyStateBox
                    title={tw.empty_title ?? 'Ingen wiki-sider ennå'}
                    description={tw.empty_description ?? 'Wiki-sider opprettes automatisk fra godkjente kunnskapsdokumenter.'}
                />
            ) : (
                <>
                    {/* Mobile cards. The capped box scrolls on its own; it is a named, focusable region
                        while it does, so it can be scrolled from the keyboard too (ScrollRegion). */}
                    <ScrollRegion
                        axis="y"
                        label={ta.wiki_pages_list_label ?? 'Wiki-sider'}
                        className="grid max-h-[min(60vh,44rem)] gap-3 overflow-y-auto pr-1 md:hidden"
                    >
                        {pages.map((page) => (
                            <article
                                key={page.id}
                                aria-labelledby={`wiki-page-card-${page.id}`}
                                className="rounded-2xl border border-slate-200 bg-white p-5"
                            >
                                <div className="space-y-3">
                                    <div>
                                        <h3 id={`wiki-page-card-${page.id}`} className="text-base font-semibold text-slate-950">{page.title}</h3>
                                        <div className="mt-1 text-sm text-slate-600">
                                            {tw.updated ?? 'Oppdatert'} {formatDate(page.updated_at, locale)}
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusBadge status={page.status} label={statusLabel(page.status)} />
                                        {page.page_type && (
                                            <PageTypeBadge type={page.page_type} label={pageTypeLabel(page.page_type)} />
                                        )}
                                        <DocumentOwnerSummaryBadge
                                            summary={page.document_owner_summary}
                                            fallbackLabel={tw.document_owner_sync_pending ?? 'Avventer synkronisering'}
                                        />
                                        <span className="text-sm text-slate-600">
                                            {page.claims_count} {tw.claims ?? 'påstander'}
                                        </span>
                                    </div>
                                    <PageNextStep publication={page.publication} tw={tw} />
                                    <Link
                                        href={`/app/wiki/${page.slug}`}
                                        className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-950"
                                    >
                                        {tw.open ?? 'Åpne'}
                                        <span className="sr-only"> {page.title}</span>
                                    </Link>
                                </div>
                            </article>
                        ))}
                    </ScrollRegion>

                    {/* Desktop table */}
                    <section className="hidden overflow-hidden rounded-2xl border border-slate-200 bg-white md:block">
                        <ScrollRegion axis="both" label={ta.wiki_pages_list_label ?? 'Wiki-sider'} className="max-h-[min(60vh,44rem)] overflow-auto">
                            <table className="min-w-full divide-y divide-slate-200">
                                <caption className="sr-only">{ta.wiki_pages_list_label ?? 'Wiki-sider'}</caption>
                                {/* Column names stay in small caps — that is what a column head is
                                    — but at 14px rather than 12px, which is below this app's floor
                                    for anything a person actually reads. */}
                                <thead className="sticky top-0 z-10 bg-slate-50 shadow-[0_1px_0_rgba(226,232,240,1)]">
                                    <tr className="text-left text-sm font-semibold uppercase tracking-[0.08em] text-slate-500">
                                        <th scope="col" className="px-6 py-3.5">{tw.pages_col_title ?? 'Tittel'}</th>
                                        <th scope="col" className="px-6 py-3.5">{tw.pages_col_type ?? 'Type'}</th>
                                        <th scope="col" className="px-6 py-3.5">{tw.pages_col_status ?? 'Status'}</th>
                                        <th scope="col" className="px-6 py-3.5">{tw.document_owner_column ?? 'Dokumenteier'}</th>
                                        <th scope="col" className="px-6 py-3.5">{tw.claims_approved_column ?? 'Påstander kvalitetssikret'}</th>
                                        <th scope="col" className="px-6 py-3.5">{tw.updated ?? 'Oppdatert'}</th>
                                        <th scope="col" className="px-6 py-3.5"><span className="sr-only">{ta.wiki_actions_column ?? 'Handlinger'}</span></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {pages.map((page) => (
                                        <tr key={page.id} className="text-base text-slate-700 transition hover:bg-slate-50/70">
                                            <td className="px-6 py-5 font-semibold text-slate-950">{page.title}</td>
                                            <td className="px-6 py-5">
                                                {page.page_type && (
                                                    <PageTypeBadge type={page.page_type} label={pageTypeLabel(page.page_type)} />
                                                )}
                                            </td>
                                            <td className="px-6 py-5">
                                                <StatusBadge status={page.status} label={statusLabel(page.status)} />
                                                <PageNextStep publication={page.publication} tw={tw} />
                                            </td>
                                            <td className="px-6 py-5">
                                                <DocumentOwnerSummaryBadge
                                                    summary={page.document_owner_summary}
                                                    fallbackLabel={tw.document_owner_sync_pending ?? 'Avventer synkronisering'}
                                                />
                                            </td>
                                            {/* Approved out of total, both counted from the same
                                                (current) version — a bare total said nothing about
                                                how much of it anyone had actually checked. */}
                                            <td className="px-6 py-5 text-slate-600">
                                                {(tw.claims_approved_of_total ?? ':approved av :total')
                                                    .replace(':approved', page.claims_approved_count ?? 0)
                                                    .replace(':total', page.claims_count ?? 0)}
                                            </td>
                                            <td className="px-6 py-5 text-slate-600">{formatDate(page.updated_at, locale)}</td>
                                            <td className="px-6 py-5">
                                                <Link
                                                    href={`/app/wiki/${page.slug}`}
                                                    className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-950"
                                                >
                                                    {tw.open ?? 'Åpne'}
                                                    <span className="sr-only"> {page.title}</span>
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </ScrollRegion>
                    </section>
                </>
            )}

            {/* Pagination */}
            {meta.last_page > 1 && (
                <nav aria-label={ta.pagination ?? 'Sidevalg'} className="flex items-center justify-between">
                    <button
                        type="button"
                        disabled={meta.current_page <= 1}
                        onClick={() => navigate({ page: meta.current_page - 1 })}
                        className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 shadow-sm transition hover:border-slate-300 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        {tw.pagination_prev ?? 'Forrige'}
                    </button>
                    <span className="text-sm text-slate-600">
                        {meta.current_page} {tw.pagination_of ?? 'av'} {meta.last_page}
                    </span>
                    <button
                        type="button"
                        disabled={meta.current_page >= meta.last_page}
                        onClick={() => navigate({ page: meta.current_page + 1 })}
                        className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 shadow-sm transition hover:border-slate-300 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        {tw.pagination_next ?? 'Neste'}
                    </button>
                </nav>
            )}
        </div>
    );
}

// ─── Sources tab ─────────────────────────────────────────────────────────────

function SourcesTab({
    sources,
    sourcesFilters,
    sourcesStoreUrl,
    documentOwnerOptions = [],
    wikiGenerationAvailable,
    canManageSources = false,
    tw,
    ta = {},
    locale,
}) {
    const srcFilters = sourcesFilters ?? {};
    const [srcSearchInput, setSrcSearchInput] = useState(srcFilters.search ?? '');

    // Sent with every write request from this tab so the backend redirects back here (with the
    // active filters, where present) instead of falling back to the default Wiki-sider tab — see
    // App\Http\Controllers\Concerns\RedirectsToWikiIndexTab.
    const tabReturnParams = () => ({
        tab: 'sources',
        src_q: srcFilters.search ?? '',
        src_status: srcFilters.status ?? '',
        src_owner: srcFilters.document_owner ?? '',
    });
    const { auth = {} } = usePage().props;
    const currentUser = auth.user ?? {};
    const canAssignDocumentOwner = canManageSources
        && Boolean(currentUser.can_assign_enterprise_wiki_document_owner);
    const ownerOptions = documentOwnerOptions ?? [];

    const navigateSources = (overrides) => {
        router.get('/app/wiki', {
            tab: 'sources',
            src_q: srcFilters.search ?? '',
            src_status: srcFilters.status ?? '',
            src_owner: srcFilters.document_owner ?? '',
            ...overrides,
        }, { preserveState: true, preserveScroll: true });
    };

    const handleSrcSearchSubmit = (e) => {
        e.preventDefault();
        navigateSources({ src_q: srcSearchInput });
    };

    const uploadForm = useForm({
        file: null,
        owner_user_id: String(currentUser.id ?? ''),
    });
    /**
     * Whether the "what happens next" dialog is open.
     *
     * Upload starts the moment a file is chosen, so the document appears in the list without the
     * user ever confirming anything — easy to read as "it is in the wiki now". It is not: nothing
     * is ingested until "Lag Wiki" is pressed. A notice in the page can be scrolled past, and
     * flash.success is a toast CustomerAppLayout clears after three seconds, so neither can
     * establish that the user saw it. A dialog only its own button can close can.
     *
     * Component state is enough: Inertia keeps this component mounted across the redirect back, and
     * nothing here needs to outlive the page view.
     */
    const [isUploadedDialogOpen, setIsUploadedDialogOpen] = useState(false);
    const uploadedDialogConfirmRef = useRef(null);
    const fileInputRef = useRef(null);
    const sourcesHeadingRef = useRef(null);
    const decisionTriggerRef = useRef(null);
    const deleteCancelRef = useRef(null);
    const deleteTriggerRef = useRef(null);
    const ownerTriggerRef = useRef(null);
    const ownerSelectRef = useRef(null);
    const [ingestingIds, setIngestingIds] = useState(new Set());
    const [decisionView, setDecisionView] = useState(null);
    // The owner dialog: { source, value, saving, error } while open, null otherwise.
    const [ownerEdit, setOwnerEdit] = useState(null);

    const handleSourceReload = () => router.reload({ only: ['sources'] });

    // A document's run moving to another status is said once, not on every 5 s poll (A11Y-112).
    useRunStatusAnnouncements(
        sources
            .filter((source) => source.latest_ingest_run)
            .map((source) => ({
                id: `source-${source.id}`,
                statusKey: `${source.latest_ingest_run.id}:${source.latest_ingest_run.status}:${source.latest_ingest_run.qa_status ?? ''}`,
                name: source.original_filename,
                statusLabel: runStatusLabel(source.latest_ingest_run, tw),
            })),
        ta.wiki_run_status_changed ?? 'Kjøring for :name: :status.',
    );

    // «Laster opp …» is on screen next to the field; heard too, once, when the upload starts.
    useTransitionAnnouncement(uploadForm.processing ? 'uploading' : 'idle', (previous, next) => (
        next === 'uploading' ? { message: tw.sources_uploading ?? 'Laster opp...' } : null
    ));

    const handleOwnerEditOpen = (source, trigger) => {
        if (!canAssignDocumentOwner) return;

        ownerTriggerRef.current = trigger;
        setOwnerEdit({ source, value: String(source.owner_user_id ?? ''), saving: false, error: null });
    };

    const handleOwnerEditClose = () => setOwnerEdit(null);

    /**
     * Saving stays explicit — the select never submits on change — and the dialog stays open until
     * the backend has answered: a refusal is shown at the field, a success closes the dialog and the
     * layout's toast says «Dokumenteier oppdatert.».
     */
    const handleOwnerSave = (event) => {
        event.preventDefault();

        if (!ownerEdit || ownerEdit.saving || !canAssignDocumentOwner) return;

        const { source } = ownerEdit;
        const normalized = String(ownerEdit.value ?? '').trim();

        if (normalized === String(source.owner_user_id ?? '')) {
            setOwnerEdit(null);
            return;
        }

        setOwnerEdit((prev) => (prev ? { ...prev, saving: true, error: null } : prev));

        router.patch(
            `/app/wiki/sources/${source.id}/owner`,
            { owner_user_id: normalized === '' ? null : Number(normalized), ...tabReturnParams() },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    // The controller answers a refusal with a flash error and a redirect, which
                    // Inertia reports as a success; only a real success closes the dialog.
                    const flashError = page?.props?.flash?.error ?? null;

                    if (flashError) {
                        setOwnerEdit((prev) => (prev ? { ...prev, saving: false, error: flashError } : prev));
                        return;
                    }

                    setOwnerEdit(null);
                },
                onError: (errors) => {
                    setOwnerEdit((prev) => (prev ? {
                        ...prev,
                        saving: false,
                        error: errors?.owner_user_id ?? (tw.source_owner_save_failed ?? 'Kunne ikke lagre dokumenteier. Prøv igjen.'),
                    } : prev));
                    focusElement(ownerSelectRef.current);
                },
                onFinish: () => {
                    setOwnerEdit((prev) => (prev && prev.saving ? { ...prev, saving: false } : prev));
                },
            },
        );
    };

    const [deletePreview, setDeletePreview] = useState(null);

    const handleDeleteClick = (source, trigger = null) => {
        deleteTriggerRef.current = trigger;
        setDeletePreview({ source, loading: true, blocked: false, data: null, error: null });
        fetch(`/app/wiki/sources/${source.id}/delete-preview`, {
            headers: { 'Accept': 'application/json' },
        })
            .then((res) => res.json())
            .then((json) => {
                setDeletePreview((prev) => prev
                    ? { ...prev, loading: false, blocked: json.blocked, data: json }
                    : prev
                );
            })
            .catch(() => {
                setDeletePreview((prev) => prev
                    ? { ...prev, loading: false, error: true }
                    : prev
                );
            });
    };

    const handleDeleteConfirm = () => {
        if (!deletePreview?.source) return;
        const sourceId = deletePreview.source.id;
        setDeletePreview(null);
        // The row — and its «Slett» — is gone afterwards; continue from the section heading.
        router.delete(`/app/wiki/sources/${sourceId}`, {
            preserveScroll: true,
            data: tabReturnParams(),
            onSuccess: () => refocusIfLost(['wiki-sources-heading']),
        });
    };

    const handleDeleteCancel = () => setDeletePreview(null);

    // Separate from RunsTab's ordinary "Avbryt kjøring" action — this cancels whichever run(s) are
    // still genuinely under automatic processing and blocking THIS document's deletion. A run only
    // waiting on Document Owner approval no longer needs this step at all: delete() ends it
    // automatically as part of deletion. See WikiSourceController::cancelBlockingRunsForDeletion()
    // and EnterpriseWikiDocumentDeletionService::hasActiveRun().
    const handleCancelBlockingRunsForDeletion = () => {
        if (!deletePreview?.source) return;
        const sourceId = deletePreview.source.id;
        setDeletePreview(null);
        router.patch(`/app/wiki/sources/${sourceId}/cancel-blocking-runs`, tabReturnParams(), {
            preserveScroll: true,
            onSuccess: () => refocusIfLost(['wiki-sources-heading']),
        });
    };

    /**
     * Upload starts as soon as a file is chosen — picking the document WAS the intent, and the
     * separate "Last opp kilde" click only asked the user to confirm what they had just done.
     *
     * The File is passed down and applied through transform() rather than read back off form state.
     * setData() is asynchronous, so a post() issued in the same tick would send the PREVIOUS value —
     * null on the first upload. setData still runs, but only so the filename renders; the request
     * carries the file this handler was given.
     */
    const startUpload = (file) => {
        uploadForm.transform((data) => ({ ...data, file, ...tabReturnParams() }));
        uploadForm.post(sourcesStoreUrl, {
            forceFormData: true,
            // Only a completed upload earns the notice. A failed request leaves it as it was and
            // shows the existing field error instead.
            onSuccess: () => {
                setIsUploadedDialogOpen(true);
                uploadForm.reset();
                uploadForm.setData('owner_user_id', String(currentUser.id ?? ''));
            },
            // The field was disabled while the file went up, which drops keyboard focus; a refused
            // upload puts it back on the field, whose error is now its description (and an alert).
            onError: () => refocusIfLost(['wiki-source-file']),
        });
    };

    const handleFileSelected = (event) => {
        const input = event.target;
        const file = input.files?.[0] ?? null;

        // Cancelling the picker leaves nothing selected. Silence is the whole behaviour here: no
        // request, no error, no change to what is already on screen.
        if (file === null) {
            return;
        }

        // Clear the native value immediately, so choosing the SAME file again still fires `change`.
        // Without this, a failed upload could not be retried with the file that failed — the input
        // would consider it unchanged. The File object above is already captured and unaffected.
        input.value = '';

        if (uploadForm.processing) {
            return;
        }

        uploadForm.setData('file', file);

        const error = validateSourceFile(file, tw);

        if (error !== null) {
            uploadForm.setError('file', error);

            return;
        }

        uploadForm.clearErrors('file');
        startUpload(file);
    };

    const sourceStatusLabel = (status) => ({
        extracted: tw.source_status_extracted ?? 'Ekstrahert',
        pending: tw.source_status_pending ?? 'Behandles',
        failed: tw.source_status_failed ?? 'Feilet',
    }[status] ?? status);

    const notStartedLabel = tw.ingest_status_not_started ?? 'Ikke startet';

    const startIngest = (source) => {
        if (ingestingIds.has(source.id)) return;
        setIngestingIds((prev) => new Set(prev).add(source.id));
        router.post(
            `/app/wiki/sources/${source.id}/ingest`,
            tabReturnParams(),
            {
                onFinish: () =>
                    setIngestingIds((prev) => {
                        const next = new Set(prev);
                        next.delete(source.id);
                        return next;
                    }),
            },
        );
    };

    // The three cells both layouts share — the table from 1280px, the cards below it — so a row
    // cannot say or allow something different depending on the screen it is read on. `variant`
    // keeps the ids of the two copies apart.
    const renderWikiStatus = (source, nameId, variant) => (
        <>
            <IngestStatusBadge
                run={source.latest_ingest_run}
                label={source.latest_ingest_run ? runStatusLabel(source.latest_ingest_run, tw) : null}
                notStartedLabel={notStartedLabel}
                locale={locale}
                onReload={handleSourceReload}
                tw={tw}
                onViewDecision={(run, trigger) => {
                    decisionTriggerRef.current = trigger;
                    setDecisionView(run);
                }}
                detailsHref={`/app/wiki?tab=runs&run_src=${source.id}`}
                describedById={nameId}
            />
            <SourceGeneratedPages
                pages={source.generated_pages}
                panelId={`wiki-source-${source.id}-pages-${variant}`}
                describedById={nameId}
                tw={tw}
            />
        </>
    );

    const renderOwner = (source, nameId) => (
        <SourceOwner
            source={source}
            canEdit={canAssignDocumentOwner}
            onEdit={handleOwnerEditOpen}
            describedById={nameId}
            tw={tw}
        />
    );

    const renderActions = (source, nameId, variant) => {
        const isInProgress = !!(source.latest_ingest_run && IN_PROGRESS_STATUSES.includes(source.latest_ingest_run.status));
        const canIngest = canManageSources && source.document_status === 'extracted';
        const ingesting = ingestingIds.has(source.id);

        return (
            <SourceRowActions
                source={source}
                panelId={`wiki-source-${source.id}-actions-${variant}`}
                describedById={nameId}
                canIngest={canIngest}
                ingesting={ingesting}
                ingestDisabled={ingesting || isInProgress || !wikiGenerationAvailable}
                ingestDisabledTitle={!wikiGenerationAvailable
                    ? (tw.source_ingest_not_available ?? 'Wiki-generering er ikke aktivert ennå.')
                    : (isInProgress ? (tw.document_has_active_run ?? 'Dokumentet har en aktiv kjøring') : undefined)}
                onIngest={() => startIngest(source)}
                // The frontend only reflects authorization and state; the backend
                // (deletePreview/destroy) re-checks both. Deliberately narrower than isInProgress:
                // a run merely awaiting document-owner approval has finished all automatic work and
                // does not block deletion — mirrors EnterpriseWikiDocumentDeletionService::hasActiveRun().
                canDelete={!!source.can_delete}
                deleteBlocked={!!source.latest_ingest_run?.expects_automatic_progress}
                onDelete={handleDeleteClick}
                showNotAvailableNote={canIngest && !wikiGenerationAvailable}
                tw={tw}
            />
        );
    };

    return (
        <>
            <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                <div className="space-y-5">
                    <div className="space-y-1">
                        <h2 ref={sourcesHeadingRef} id="wiki-sources-heading" tabIndex={-1} className="text-base font-semibold text-slate-950">
                            {tw.sources_title ?? 'Kildedokumenter'}
                        </h2>
                        <p className="max-w-2xl text-base leading-6 text-slate-600">
                            {canManageSources
                                ? (tw.sources_description ?? 'Last opp kildedokumenter direkte til Enterprise Wiki. Dokumentet lagres og tekst ekstraheres før det kan brukes til å generere wiki-innhold.')
                                : (tw.sources_description_read_only ?? 'Kildedokumentene Enterprise Wiki er bygget på. Du har lesetilgang til listen, men ikke tilgang til å administrere kilder.')}
                        </p>
                    </div>

                    {/* Uploading is wiki.source.manage. Hidden rather than disabled for someone
                        without it: a greyed-out file picker reads as something temporarily
                        unavailable, and this is not their job at all. The list below stays. */}
                    {canManageSources && (
                    <div className="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                        {/* No <form>: there is no submit step left to trigger, and a form with no
                            button would still submit on Enter from the owner select. */}
                        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(15rem,20rem)] lg:items-end">
                            <div className="space-y-1.5">
                                <div className="flex flex-wrap items-center gap-3">
                                    {/* The input is visually hidden and the label is what is seen, so the
                                        label draws the focus ring (the peer-focus-visible classes had been
                                        lost, A11Y-011). The chosen file, the allowed formats and an error are
                                        the field's description. */}
                                    <input
                                        ref={fileInputRef}
                                        id="wiki-source-file"
                                        type="file"
                                        accept=".pdf,.docx"
                                        // Unavailable while uploading, but still focusable (A11Y-156):
                                        // `disabled` dropped focus to <body> for the whole request.
                                        // The click is refused so the file dialog does not open, and
                                        // handleFileSelected ignores a choice made meanwhile.
                                        aria-disabled={uploadForm.processing || undefined}
                                        onClick={(event) => {
                                            if (uploadForm.processing) {
                                                event.preventDefault();
                                            }
                                        }}
                                        onChange={handleFileSelected}
                                        aria-invalid={uploadForm.errors.file ? true : undefined}
                                        aria-describedby={['wiki-source-file-name', 'wiki-source-file-hint', uploadForm.errors.file ? 'wiki-source-file-error' : null].filter(Boolean).join(' ')}
                                        className="peer sr-only"
                                    />
                                    <label
                                        htmlFor="wiki-source-file"
                                        className={`inline-flex h-11 shrink-0 cursor-pointer items-center justify-center rounded-full px-5 text-base font-semibold shadow-sm transition peer-disabled:cursor-not-allowed peer-disabled:opacity-50 peer-aria-disabled:cursor-not-allowed peer-aria-disabled:opacity-50 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-violet-600 ${PRIMARY_COLOURS}`}
                                    >
                                        {tw.sources_file_label ?? 'Velg fil'}
                                    </label>
                                    <span id="wiki-source-file-name" className="min-w-0 break-all text-base text-slate-700">
                                        {uploadForm.data.file?.name ?? (tw.sources_no_file_selected ?? 'Ingen fil valgt')}
                                    </span>
                                    {uploadForm.processing ? (
                                        <span className="text-base font-semibold text-slate-600">
                                            {tw.sources_uploading ?? 'Laster opp...'}
                                        </span>
                                    ) : null}
                                </div>
                                <p id="wiki-source-file-hint" className="text-base text-slate-600">
                                    {tw.sources_file_hint ?? 'PDF eller DOCX · Maks 20 MB'}
                                </p>
                                {/* An alert: the upload starts on choosing the file, so a refusal comes
                                    while the person is still on the field, with nothing else to tell them. */}
                                {uploadForm.errors.file ? (
                                    <p id="wiki-source-file-error" role="alert" className="text-base text-rose-700">{uploadForm.errors.file}</p>
                                ) : null}
                            </div>

                            {canAssignDocumentOwner ? (
                                <div className="space-y-1.5">
                                    <label className="block text-base font-semibold uppercase tracking-wide text-slate-600" htmlFor="wiki-source-owner">
                                        {tw.document_owner_label ?? 'Dokumenteier'}
                                    </label>
                                    <select
                                        id="wiki-source-owner"
                                        value={uploadForm.data.owner_user_id}
                                        onChange={(event) => uploadForm.setData('owner_user_id', event.target.value)}
                                        aria-invalid={uploadForm.errors.owner_user_id ? true : undefined}
                                        aria-describedby={uploadForm.errors.owner_user_id ? 'wiki-source-owner-error' : undefined}
                                        className={`${RUNS_SELECT_CLS} w-full`}
                                    >
                                        <option value="">{tw.document_owner_choose ?? 'Velg dokumenteier'}</option>
                                        {ownerOptions.map((option) => (
                                            <option key={option.id} value={option.id}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                    {uploadForm.errors.owner_user_id ? (
                                        <p id="wiki-source-owner-error" className="text-base text-rose-700">{uploadForm.errors.owner_user_id}</p>
                                    ) : null}
                                </div>
                            ) : (
                                <div className="space-y-1.5">
                                    <span className="block text-base font-semibold uppercase tracking-wide text-slate-600">
                                        {tw.document_owner_label ?? 'Dokumenteier'}
                                    </span>
                                    <p className="flex h-9 items-center text-base text-slate-700">
                                        {currentUser.name ?? (tw.document_owner_current_user ?? 'Deg')}
                                    </p>
                                    <input type="hidden" name="owner_user_id" value={uploadForm.data.owner_user_id} />
                                </div>
                            )}

                        </div>
                    </div>
                    )}

                    {/* The step the user still has to take. A dialog rather than a notice in the
                        page: this exists because the document appearing in the list below reads as
                        "done", and only something the user has to dismiss can rule out their having
                        missed it. closeDisabled blocks both Escape and backdrop click, so the
                        confirm button is the single way out — and it only closes the dialog. */}
                    <ActionDialog
                        isOpen={isUploadedDialogOpen}
                        onClose={() => setIsUploadedDialogOpen(false)}
                        closeDisabled
                        titleId="wiki-source-uploaded-title"
                        initialFocusRef={uploadedDialogConfirmRef}
                        returnFocusRef={fileInputRef}
                        fallbackFocusRef={sourcesHeadingRef}
                    >
                        <h2 id="wiki-source-uploaded-title" className="text-xl font-semibold tracking-tight text-slate-950">
                            {tw.sources_uploaded_title ?? 'Kildedokumentet er lastet opp'}
                        </h2>
                        <p className="mt-2 text-base leading-6 text-slate-600">
                            {/* The action is named from the button's own label, so the instruction
                                cannot drift from what the button actually says. */}
                            {(tw.sources_uploaded_next_step ?? 'For å ta dokumentet inn i wikien må du nå trykke «:action».')
                                .split(':action')
                                .flatMap((part, index) => (index === 0
                                    ? [part]
                                    : [
                                        <strong key={`action-${index}`} className="font-semibold text-slate-800">
                                            {tw.source_ingest_button ?? 'Lag Wiki'}
                                        </strong>,
                                        part,
                                    ]))}
                        </p>

                        <div className="mt-6 flex flex-wrap gap-3">
                            <button
                                ref={uploadedDialogConfirmRef}
                                type="button"
                                onClick={() => setIsUploadedDialogOpen(false)}
                                className={`inline-flex h-11 items-center justify-center rounded-full px-5 text-base font-semibold shadow-sm transition ${PRIMARY_COLOURS}`}
                            >
                                {tw.sources_uploaded_confirm ?? 'OK, jeg forstår'}
                            </button>
                        </div>
                    </ActionDialog>

                    {/* Filter bar */}
                    <div className="flex flex-wrap items-end gap-2">
                        <form role="search" aria-label={ta.wiki_sources_search_label ?? 'Søk i kildedokumenter'} onSubmit={handleSrcSearchSubmit} className="flex max-w-full items-center gap-1.5">
                            <label htmlFor="wiki-sources-search" className="sr-only">{ta.wiki_sources_search_label ?? 'Søk i kildedokumenter'}</label>
                            <input
                                id="wiki-sources-search"
                                type="search"
                                value={srcSearchInput}
                                onChange={(e) => setSrcSearchInput(e.target.value)}
                                placeholder={tw.sources_search_placeholder ?? 'Søk i filnavn...'}
                                className={SELECT_CLS + ' w-52 min-w-0'}
                            />
                            {/* An icon-only button needs a name of its own (4.1.2); it had none. */}
                            <button
                                type="submit"
                                aria-label={ta.wiki_search_button ?? 'Søk'}
                                className="inline-flex h-11 items-center justify-center rounded-lg border border-slate-200 bg-white px-3 text-base font-semibold text-slate-600 shadow-sm transition hover:border-slate-300 hover:text-slate-900"
                            >
                                <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fillRule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.452 4.391l3.328 3.329a.75.75 0 1 1-1.06 1.06l-3.329-3.328A7 7 0 0 1 2 9Z" clipRule="evenodd" />
                                </svg>
                            </button>
                        </form>

                        <select
                            aria-label={ta.wiki_filter_status ?? 'Status'}
                            value={srcFilters.status ?? ''}
                            onChange={(e) => navigateSources({ src_status: e.target.value })}
                            className={SELECT_CLS}
                        >
                            <option value="">{tw.sources_status_all ?? 'Alle statuser'}</option>
                            <option value="extracted">{tw.source_status_extracted ?? 'Ekstrahert'}</option>
                            <option value="pending">{tw.source_status_pending ?? 'Behandles'}</option>
                            <option value="failed">{tw.source_status_failed ?? 'Feilet'}</option>
                        </select>

                        {/* Same owner list the row's own owner select uses — one definition of who
                            can be a document owner, so the filter can never offer someone the row
                            cannot assign. */}
                        {ownerOptions.length > 0 && (
                            <select
                                value={srcFilters.document_owner ?? ''}
                                onChange={(e) => navigateSources({ src_owner: e.target.value })}
                                className={SELECT_CLS}
                                aria-label={ta.wiki_filter_document_owner ?? 'Dokumenteier'}
                            >
                                <option value="">{tw.filter_document_owner_all ?? 'Alle dokumenteiere'}</option>
                                {ownerOptions.map((owner) => (
                                    <option key={owner.id} value={owner.id}>{owner.label}</option>
                                ))}
                            </select>
                        )}

                        {(srcFilters.search || srcFilters.status || srcFilters.document_owner) && (
                            <button
                                type="button"
                                onClick={() => { setSrcSearchInput(''); navigateSources({ src_q: '', src_status: '', src_owner: '' }); }}
                                className="inline-flex h-11 items-center gap-1 rounded-lg px-3 text-base font-medium leading-6 text-slate-600 transition hover:text-slate-800"
                            >
                                <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                                </svg>
                                {tw.filter_clear ?? 'Nullstill'}
                            </button>
                        )}
                    </div>

                    {sources.length === 0 ? (
                        <p className="text-base text-slate-600">
                            {tw.sources_list_empty ?? 'Ingen kildedokumenter lastet opp ennå.'}
                        </p>
                    ) : (
                        <>
                            {/* From 1280px: a table with five columns that fits a laptop without
                                scrolling sideways. «Lag Wiki» used to be the last of six columns in a
                                table wider than its box — behind a horizontal scroll macOS does not
                                show — and a later fix pinned a 420px-wide action column instead. The
                                upload date now sits under the filename, «Last ned» and «Slett» are in
                                the row's ⋯ panel, and the owner is a name with a pencil, so every
                                column is narrow enough for Filnavn to keep the room it needs. */}
                            <ScrollRegion
                                axis="both"
                                label={tw.sources_title ?? 'Kildedokumenter'}
                                className="hidden max-h-[min(64vh,48rem)] overflow-auto rounded-2xl border border-slate-100 xl:block"
                            >
                                <table className="w-full table-fixed divide-y divide-slate-100">
                                    <caption className="sr-only">{tw.sources_title ?? 'Kildedokumenter'}</caption>
                                    <colgroup>
                                        <col />
                                        <col style={{ width: '7.5rem' }} />
                                        <col style={{ width: '10rem' }} />
                                        <col style={{ width: '11rem' }} />
                                        <col style={{ width: '10.5rem' }} />
                                    </colgroup>
                                    <thead className="sticky top-0 z-10 bg-slate-50 shadow-[0_1px_0_rgba(226,232,240,1)]">
                                        <tr className="text-left text-base font-semibold leading-6 text-slate-600">
                                            <th scope="col" className="px-3 py-2.5">{tw.source_col_filename ?? 'Filnavn'}</th>
                                            <th scope="col" className="px-3 py-2.5">{tw.source_col_status ?? 'Status'}</th>
                                            <th scope="col" className="px-3 py-2.5">{tw.ingest_col_wiki_status ?? 'Wiki-status'}</th>
                                            <th scope="col" className="px-3 py-2.5">{tw.source_col_owner ?? 'Dokumenteier'}</th>
                                            <th scope="col" className="px-3 py-2.5">{tw.source_col_actions ?? 'Handlinger'}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 bg-white">
                                        {sources.map((source) => {
                                            // The row's name, for the controls that act on it: «Slett» in a
                                            // list of twenty rows says nothing about which document goes (2.4.6).
                                            const nameId = `wiki-source-${source.id}-name`;

                                            return (
                                                <tr key={source.id} className="text-base leading-6 text-slate-700">
                                                    <th scope="row" className="px-3 py-2.5 text-left align-top font-normal">
                                                        <span id={nameId} className="line-clamp-2 wrap-break-word font-medium text-slate-900" title={source.original_filename}>
                                                            {source.original_filename}
                                                        </span>
                                                        <span className="mt-0.5 block text-base leading-6 text-slate-600">
                                                            {tw.source_col_uploaded ?? 'Lastet opp'} {formatDate(source.created_at, locale)}
                                                        </span>
                                                    </th>
                                                    <td className="px-3 py-2.5 align-top">
                                                        <SourceStatusBadge
                                                            status={source.document_status}
                                                            label={sourceStatusLabel(source.document_status)}
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2.5 align-top">
                                                        {renderWikiStatus(source, nameId, 'table')}
                                                    </td>
                                                    <td className="px-3 py-2.5 align-top">
                                                        {renderOwner(source, nameId)}
                                                    </td>
                                                    <td className="px-3 py-2.5 align-top">
                                                        {renderActions(source, nameId, 'table')}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </ScrollRegion>

                            {/* Below 1280px the five columns no longer fit, so each document is a
                                card: name and status first, then Wiki-status and owner, then the
                                actions — all of it inside the viewport, nothing sideways. */}
                            <ul
                                aria-labelledby="wiki-sources-heading"
                                className="divide-y divide-slate-100 rounded-2xl border border-slate-100 xl:hidden"
                            >
                                {sources.map((source) => {
                                    const nameId = `wiki-source-${source.id}-card-name`;

                                    return (
                                        <li key={source.id} className="space-y-3 px-4 py-3 text-base leading-6 text-slate-700">
                                            <div className="space-y-1">
                                                <h3 id={nameId} className="line-clamp-2 wrap-break-word text-base font-medium text-slate-900" title={source.original_filename}>
                                                    {source.original_filename}
                                                </h3>
                                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                                    <SourceStatusBadge
                                                        status={source.document_status}
                                                        label={sourceStatusLabel(source.document_status)}
                                                    />
                                                    <span className="text-slate-600">
                                                        {tw.source_col_uploaded ?? 'Lastet opp'} {formatDate(source.created_at, locale)}
                                                    </span>
                                                </div>
                                            </div>
                                            <dl className="grid gap-x-4 gap-y-2 sm:grid-cols-[9rem_minmax(0,1fr)]">
                                                <dt className="font-semibold text-slate-600">{tw.ingest_col_wiki_status ?? 'Wiki-status'}</dt>
                                                <dd className="min-w-0">{renderWikiStatus(source, nameId, 'card')}</dd>
                                                <dt className="font-semibold text-slate-600">{tw.source_col_owner ?? 'Dokumenteier'}</dt>
                                                <dd className="min-w-0">{renderOwner(source, nameId)}</dd>
                                            </dl>
                                            {renderActions(source, nameId, 'card')}
                                        </li>
                                    );
                                })}
                            </ul>
                        </>
                    )}

                </div>
            </section>
            <DecisionModal
                run={decisionView}
                tw={tw}
                ta={ta}
                isOpen={decisionView !== null}
                onClose={() => setDecisionView(null)}
                returnFocusRef={decisionTriggerRef}
            />

            {/* Changing a document's owner. The select only drafts; «Lagre eier» commits, and the
                dialog stays open with the reason at the field if the backend refuses. */}
            <ActionDialog
                isOpen={ownerEdit !== null}
                onClose={handleOwnerEditClose}
                closeDisabled={!!ownerEdit?.saving}
                titleId="wiki-source-owner-edit-title"
                descriptionId="wiki-source-owner-edit-file"
                initialFocusRef={ownerSelectRef}
                returnFocusRef={ownerTriggerRef}
                fallbackFocusRef={sourcesHeadingRef}
                size="xs"
            >
                {ownerEdit && (
                    <form onSubmit={handleOwnerSave} noValidate>
                        <h2 id="wiki-source-owner-edit-title" className="text-xl font-semibold tracking-tight text-slate-950">
                            {tw.source_owner_dialog_title ?? 'Endre dokumenteier'}
                        </h2>
                        <p id="wiki-source-owner-edit-file" className="mt-2 break-all text-base leading-6 text-slate-600">
                            {ownerEdit.source.original_filename}
                        </p>
                        <div className="mt-5 space-y-1.5">
                            <label htmlFor="wiki-source-owner-edit" className="block text-base font-semibold text-slate-700">
                                {tw.document_owner_label ?? 'Dokumenteier'}
                            </label>
                            <select
                                ref={ownerSelectRef}
                                id="wiki-source-owner-edit"
                                value={ownerEdit.value}
                                onChange={(event) => {
                                    const { value } = event.target;
                                    setOwnerEdit((prev) => (prev ? { ...prev, value, error: null } : prev));
                                }}
                                aria-invalid={ownerEdit.error ? true : undefined}
                                aria-describedby={ownerEdit.error ? 'wiki-source-owner-edit-error' : undefined}
                                className={`${RUNS_SELECT_CLS} w-full`}
                            >
                                <option value="">{tw.document_owner_missing ?? 'Mangler Dokumenteier'}</option>
                                {ownerOptions.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name ?? option.label}
                                    </option>
                                ))}
                            </select>
                            {ownerEdit.error && (
                                <p id="wiki-source-owner-edit-error" role="alert" className="text-base text-rose-700">{ownerEdit.error}</p>
                            )}
                        </div>
                        <div className="mt-6 flex flex-wrap gap-3">
                            {/* Busy, not disabled: focus stays on the button while the request runs. */}
                            <button
                                type="submit"
                                aria-disabled={ownerEdit.saving || undefined}
                                className={PRIMARY_ACTION}
                            >
                                {ownerEdit.saving
                                    ? (tw.document_owner_saving ?? 'Lagrer eier...')
                                    : (tw.document_owner_save ?? 'Lagre eier')}
                            </button>
                            <button
                                type="button"
                                aria-disabled={ownerEdit.saving || undefined}
                                onClick={() => { if (!ownerEdit.saving) handleOwnerEditClose(); }}
                                className={SECONDARY_ACTION}
                            >
                                {tw.delete_cancel_button ?? 'Avbryt'}
                            </button>
                        </div>
                    </form>
                )}
            </ActionDialog>

            {/* Deleting a source document (A11Y-002): a modal dialog with a title and a description,
                focus on «Avbryt» (the safe choice), Escape, a trapped Tab and focus back on the row's
                ⋯ button that «Slett» was chosen from. The preview loads inside it; a failure to load
                is an alert there. */}
            <ActionDialog
                isOpen={deletePreview !== null}
                onClose={handleDeleteCancel}
                titleId="wiki-source-delete-title"
                descriptionId="wiki-source-delete-description"
                initialFocusRef={deleteCancelRef}
                returnFocusRef={deleteTriggerRef}
                fallbackFocusRef={sourcesHeadingRef}
                size="xs"
            >
                {deletePreview && (
                    <>
                        <h2 id="wiki-source-delete-title" className="mb-4 text-base font-semibold text-slate-900">
                            {tw.delete_preview_title ?? 'Bekreft sletting av kildedokument'}
                        </h2>

                        <div id="wiki-source-delete-description" aria-busy={deletePreview.loading || undefined}>
                            <p className="mb-3 break-all text-base font-medium text-slate-800">
                                {deletePreview.source?.original_filename}
                            </p>

                            {deletePreview.loading && (
                                <p className="text-base text-slate-600">{tw.delete_preview_loading ?? 'Laster forhåndsvisning...'}</p>
                            )}

                            {!deletePreview.loading && deletePreview.error && (
                                <p role="alert" className="text-base text-rose-700">{tw.sources_delete_preview_failed ?? 'Kunne ikke laste forhåndsvisning. Prøv igjen.'}</p>
                            )}

                            {!deletePreview.loading && !deletePreview.error && deletePreview.blocked && (
                                <div className="space-y-2">
                                    <p className="text-base text-rose-700">
                                        {tw.delete_preview_blocked_in_progress ?? 'Dokumentet kan ikke slettes mens en Wiki-kjøring pågår. Vent til kjøringen er ferdig eller stopp den først.'}
                                    </p>
                                    <p className="text-base text-slate-600">
                                        {tw.cancel_blocking_runs_confirm_body ?? 'Dette avbryter kjøringen(e) som blokkerer sletting av dette dokumentet, slik at dokumentet kan slettes. Wiki-innhold som allerede er generert blir beholdt. Handlingen kan ikke angres.'}
                                    </p>
                                </div>
                            )}

                            {!deletePreview.loading && !deletePreview.error && !deletePreview.blocked && deletePreview.data && (
                                <div className="space-y-3">
                                    <p className="text-base text-slate-600">
                                        {tw.delete_preview_intro ?? 'Dette sletter kildedokumentet og Enterprise Wiki-innhold som kun er generert fra dette dokumentet. Handlingen kan ikke angres.'}
                                    </p>
                                    {deletePreview.data.pending_approval_run_count > 0 && (
                                        <p className="text-base font-medium text-amber-800">
                                            {tw.delete_preview_pending_approval ?? 'Dokumentet har en åpen godkjenningsflyt som venter på dokumenteiergodkjenning. Denne avsluttes automatisk dersom du sletter dokumentet nå.'}
                                        </p>
                                    )}
                                    <dl className="space-y-1.5 rounded-xl border border-slate-100 bg-slate-50 px-4 py-3 text-base">
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-slate-600">{tw.document_owner_label ?? 'Dokumenteier'}</dt>
                                            <dd className="font-semibold text-slate-800">
                                                {deletePreview.data.document_owner_name ?? (tw.document_owner_missing ?? 'Mangler Dokumenteier')}
                                            </dd>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-slate-600">{tw.delete_preview_runs ?? 'Kjøringer som slettes'}</dt>
                                            <dd className="font-semibold text-slate-800">{deletePreview.data.run_count}</dd>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-slate-600">{tw.delete_preview_sole_source_pages ?? 'Sider som bare tilhører dokumentet'}</dt>
                                            <dd className="font-semibold text-rose-700">{deletePreview.data.sole_source_page_count}</dd>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-slate-600">{tw.delete_preview_page_versions ?? 'Sideversjoner som slettes'}</dt>
                                            <dd className="font-semibold text-slate-800">{deletePreview.data.page_version_count}</dd>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-slate-600">{tw.delete_preview_claims ?? 'Claims som slettes'}</dt>
                                            <dd className="font-semibold text-slate-800">{deletePreview.data.claim_count}</dd>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-slate-600">{tw.delete_preview_findings ?? 'Funn som slettes'}</dt>
                                            <dd className="font-semibold text-slate-800">{deletePreview.data.finding_count}</dd>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-slate-600">{tw.delete_preview_shared_pages ?? 'Delte sider som beholdes'}</dt>
                                            <dd className="font-semibold text-emerald-700">{deletePreview.data.shared_page_count}</dd>
                                        </div>
                                    </dl>
                                    {deletePreview.data.sole_source_page_count === 0 && (
                                        <p className="text-base text-slate-600">{tw.delete_preview_no_pages ?? 'Ingen wiki-sider vil bli slettet.'}</p>
                                    )}
                                    {deletePreview.data.shared_page_count > 0 && (
                                        <p className="text-base text-emerald-700">
                                            {(tw.delete_preview_shared_pages_note ?? '{count} sider beholdes fordi de også bygger på andre kildedokumenter.')
                                                .replace('{count}', deletePreview.data.shared_page_count)}
                                        </p>
                                    )}
                                    {!deletePreview.data.storage_file_exists && (
                                        <p className="text-base text-amber-800">
                                            {tw.delete_preview_storage_missing ?? 'Dokumentfilen finnes ikke lenger i lagring.'}
                                        </p>
                                    )}
                                    <p className="text-base font-semibold text-rose-700">
                                        {tw.delete_preview_irreversible ?? 'Handlingen kan ikke angres.'}
                                    </p>
                                </div>
                            )}
                        </div>

                        <div className="mt-5 flex flex-wrap justify-end gap-3">
                            <button
                                ref={deleteCancelRef}
                                type="button"
                                onClick={handleDeleteCancel}
                                className="inline-flex min-h-11 items-center rounded-full border border-slate-300 px-4 py-2 text-left text-base font-semibold text-slate-600 transition hover:border-slate-400 hover:text-slate-900"
                            >
                                {tw.delete_cancel_button ?? 'Avbryt'}
                            </button>
                            {!deletePreview.loading && !deletePreview.error && !deletePreview.blocked && (
                                <button
                                    type="button"
                                    onClick={handleDeleteConfirm}
                                    className="inline-flex min-h-11 items-center rounded-full bg-rose-700 px-4 py-2 text-left text-base font-semibold text-white transition hover:bg-rose-800"
                                >
                                    {deletePreview.data?.pending_approval_run_count > 0
                                        ? (tw.delete_confirm_button_pending_approval ?? 'Avbryt godkjenningsflyt og slett dokument')
                                        : (tw.delete_confirm_button ?? 'Slett dokument og Wiki-innhold')}
                                </button>
                            )}
                            {!deletePreview.loading && !deletePreview.error && deletePreview.blocked && (
                                <button
                                    type="button"
                                    onClick={handleCancelBlockingRunsForDeletion}
                                    className="inline-flex min-h-11 items-center rounded-full bg-amber-700 px-4 py-2 text-left text-base font-semibold text-white transition hover:bg-amber-800"
                                >
                                    {tw.cancel_blocking_runs_button ?? 'Avbryt kjøring og fortsett sletting'}
                                </button>
                            )}
                        </div>
                    </>
                )}
            </ActionDialog>
        </>
    );
}

// ─── Runs tab ────────────────────────────────────────────────────────────────

function findingsCountToneClass(run) {
    // v0.10 (docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat — v0.10"): only a genuinely
    // blocking (technical) lint finding gets the alarming rose tone. Open, voluntary claim QA
    // signals never block the Wiki and share the neutral amber "worth a look" tone with ordinary
    // non-blocking findings.
    if ((run.findings_open_blocking_count ?? 0) > 0) {
        return 'text-rose-700 hover:bg-rose-50 focus-visible:ring-rose-400';
    }

    if ((run.findings_open_non_blocking_count ?? 0) > 0 || (run.findings_open_qa_review_count ?? 0) > 0) {
        return 'text-amber-700 hover:bg-amber-50 focus-visible:ring-amber-400';
    }

    return 'text-slate-600 hover:bg-slate-100 focus-visible:ring-slate-400';
}

// The Kjøringer tab's select style; same size as SELECT_CLS, without its max-w-full.
const RUNS_SELECT_CLS = 'h-11 rounded-lg border border-slate-200 bg-white px-3 text-base text-slate-700 shadow-sm transition focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100';

/**
 * «Vis beslutning» — the maintainer's page plan for a run, read-only. A modal dialog (A11Y-002): it
 * has a title and a named close button, keeps focus while open, closes on Escape and gives focus back
 * to the button that opened it. Was a bare overlay with none of that.
 */
function DecisionModal({ run, tw, ta = {}, isOpen, onClose, returnFocusRef }) {
    const d = run?.maintainer_decision_json ?? {};
    const sectionHeading = 'text-sm font-semibold uppercase tracking-widest text-slate-600';

    return (
        <ActionDialog
            isOpen={isOpen}
            onClose={onClose}
            titleId="wiki-decision-title"
            size="lg"
            padded={false}
            returnFocusRef={returnFocusRef}
        >
            <DialogTitleBar
                titleId="wiki-decision-title"
                title={tw.decision_panel_heading ?? 'Sideplanlegging'}
                onClose={onClose}
                closeLabel={ta.wiki_decision_close ?? 'Lukk sideplanleggingen'}
            />

            <div className="space-y-5 px-6 py-5">
                <section className="space-y-2">
                    <h3 className={sectionHeading}>
                        {tw.decision_source_article ?? 'Kildeartikkel'}
                    </h3>
                    <DecisionPageEntry entry={d.source_article} tw={tw} />
                </section>

                <section className="space-y-2">
                    <h3 className={sectionHeading}>
                        {tw.decision_source_summary ?? 'Sammendrag'}
                    </h3>
                    <DecisionPageEntry entry={d.source_summary} tw={tw} />
                </section>

                <section className="space-y-2">
                    <h3 className={sectionHeading}>
                        {tw.decision_concept_pages ?? 'Konseptsider'}
                    </h3>
                    {(d.concept_pages ?? []).length === 0 ? (
                        <span className="text-sm text-slate-600">{tw.decision_no_items ?? 'Ingen'}</span>
                    ) : (
                        <ul className="space-y-3">
                            {d.concept_pages.map((p, i) => (
                                <li key={i} className="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
                                    <DecisionPageEntry entry={p} tw={tw} />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="space-y-2">
                    <h3 className={sectionHeading}>
                        {tw.decision_entity_pages ?? 'Entitetssider'}
                    </h3>
                    {(d.entity_pages ?? []).length === 0 ? (
                        <span className="text-sm text-slate-600">{tw.decision_no_items ?? 'Ingen'}</span>
                    ) : (
                        <ul className="space-y-3">
                            {d.entity_pages.map((p, i) => (
                                <li key={i} className="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
                                    <DecisionPageEntry entry={p} tw={tw} />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {(d.warnings ?? []).length > 0 && (
                    <section className="space-y-2">
                        <h3 className="text-sm font-semibold uppercase tracking-widest text-amber-800">
                            {tw.decision_warnings ?? 'Advarsler'}
                        </h3>
                        <ul className="space-y-1">
                            {d.warnings.map((w, i) => (
                                <li key={i} className="text-sm text-amber-800">{w}</li>
                            ))}
                        </ul>
                    </section>
                )}

                {d.no_action_reason && (
                    <section className="space-y-1">
                        <h3 className={sectionHeading}>
                            {tw.decision_no_action_reason ?? 'Ingen handling – begrunnelse'}
                        </h3>
                        <p className="text-sm text-slate-700">{d.no_action_reason}</p>
                    </section>
                )}
            </div>

            <div className="border-t border-slate-100 px-6 py-4 text-right">
                <button
                    type="button"
                    onClick={onClose}
                    className="inline-flex min-h-9 items-center justify-center rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:text-slate-950"
                >
                    {tw.decision_modal_close ?? 'Lukk'}
                </button>
            </div>
        </ActionDialog>
    );
}

function RunsTab({ runs, runsFilters, tw, ta = {}, locale }) {
    const filters = runsFilters ?? {};
    const cancelTriggerRef = useRef(null);
    const retryTriggerRef = useRef(null);
    const cancelDismissRef = useRef(null);
    const dismissTriggerRef = useRef(null);
    const dismissCancelRef = useRef(null);
    const retryDismissRef = useRef(null);

    // A run moving to another status is said once, not on every 5 s poll (A11Y-112).
    useRunStatusAnnouncements(
        runs.map((run) => ({
            id: run.id,
            statusKey: `${run.status}:${run.qa_status ?? ''}`,
            name: run.source_document_filename ?? `#${run.id}`,
            statusLabel: runStatusLabel(run, tw),
        })),
        ta.wiki_run_status_changed ?? 'Kjøring for :name: :status.',
    );
    // expandedRuns[runId] is null (closed) | 'overview' | 'pages' | 'findings'. Anything but null is
    // the row's one detail view open; 'pages'/'findings' says which part of «Sider og QA-funn» it shows.
    const [expandedRuns, setExpandedRuns] = useState({});
    const [runPagesState, setRunPagesState] = useState({});
    const [runFindingsState, setRunFindingsState] = useState({});
    const [cancelTarget, setCancelTarget] = useState(null);

    const handleCancelClick = (run, trigger = null) => {
        cancelTriggerRef.current = trigger;
        setCancelTarget(run);
    };
    const handleCancelDismiss = () => setCancelTarget(null);
    const handleCancelConfirm = () => {
        if (!cancelTarget) return;
        const runId = cancelTarget.id;
        setCancelTarget(null);
        // Sent so the backend redirects back to Kjøringer (with the active filters, where
        // present) instead of falling back to the default Wiki-sider tab — see
        // App\Http\Controllers\Concerns\RedirectsToWikiIndexTab.
        router.patch(`/app/wiki/runs/${runId}/cancel`, {
            tab: 'runs',
            run_status: filters.status ?? '',
            run_decision: filters.decision ?? '',
            run_src: filters.src_id ?? '',
        }, {
            preserveScroll: true,
            // «Avbryt kjøring» is gone once the run is cancelled; continue from the list heading.
            onSuccess: () => refocusIfLost(['wiki-runs-heading']),
        });
    };

    // Wiki run-592: mirrors cancelTarget above exactly (same confirm-then-router.patch shape) —
    // retryTarget/retryInFlight is intentionally its own pair rather than reusing cancelTarget so
    // the two confirmation modals (and their in-flight guards) never collide for the same run.
    // One confirm dialog for both retry actions: retryKind says which one it is confirming
    // ('maintainer_decision' — «Prøv sideplanleggingen på nytt»; 'failed_pages' — arbeidspakke A4's
    // «Prøv igjen for feilede sider»).
    const [retryTarget, setRetryTarget] = useState(null);
    const [retryKind, setRetryKind] = useState('maintainer_decision');
    const [retryInFlight, setRetryInFlight] = useState(false);

    const handleRetryClick = (run, trigger = null, kind = 'maintainer_decision') => {
        retryTriggerRef.current = trigger;
        setRetryKind(kind);
        setRetryTarget(run);
    };
    const handleRetryDismiss = () => {
        if (retryInFlight) return;
        setRetryTarget(null);
    };
    const handleRetryConfirm = () => {
        if (!retryTarget || retryInFlight) return;
        const runId = retryTarget.id;
        setRetryInFlight(true);
        const retryPath = retryKind === 'failed_pages' ? 'retry-failed-pages' : 'retry-maintainer-decision';
        router.patch(`/app/wiki/runs/${runId}/${retryPath}`, {
            tab: 'runs',
            run_status: filters.status ?? '',
            run_decision: filters.decision ?? '',
            run_src: filters.src_id ?? '',
        }, {
            preserveScroll: true,
            onFinish: () => {
                setRetryInFlight(false);
                setRetryTarget(null);
                refocusIfLost(['wiki-runs-heading']);
            },
        });
    };

    // «Fjern fra oversikten»: confirm first, and the run leaves the list only when the server has
    // cleared it — the reload that follows a success no longer carries it. A refusal stays in the
    // dialog, in words, with the run still in the list; nothing is removed ahead of the answer.
    const [dismissTarget, setDismissTarget] = useState(null);
    const [dismissInFlight, setDismissInFlight] = useState(false);
    const [dismissError, setDismissError] = useState(null);

    const handleDismissClick = (run, trigger = null) => {
        dismissTriggerRef.current = trigger;
        setDismissError(null);
        setDismissTarget(run);
    };
    const handleDismissCancel = () => {
        if (dismissInFlight) return;
        setDismissTarget(null);
        setDismissError(null);
    };
    const dismissErrorMessage = (status) => ({
        403: tw.run_dismiss_forbidden ?? 'Du har ikke tilgang til å fjerne denne kjøringen fra oversikten.',
        404: tw.run_dismiss_not_found ?? 'Fant ikke kjøringen. Last siden på nytt.',
        419: tw.run_dismiss_expired ?? 'Økten din er utløpt. Last siden på nytt og logg inn igjen.',
    })[status] ?? (tw.run_dismiss_failed ?? 'Kjøringen ble ikke fjernet fra oversikten. Prøv igjen.');
    const handleDismissConfirm = () => {
        if (!dismissTarget || dismissInFlight) return;
        const runId = dismissTarget.id;
        setDismissInFlight(true);
        setDismissError(null);

        try {
            router.patch(`/app/wiki/runs/${runId}/dismiss`, {
                tab: 'runs',
                run_status: filters.status ?? '',
                run_decision: filters.decision ?? '',
                run_src: filters.src_id ?? '',
            }, {
                preserveScroll: true,
                onSuccess: () => {
                    setDismissTarget(null);
                    // The run and its «Fjern fra oversikten» are gone; continue from the list heading.
                    refocusIfLost(['wiki-runs-heading']);
                },
                onError: (errors) => setDismissError(errors?.run ?? Object.values(errors ?? {})[0] ?? dismissErrorMessage(null)),
                // Returning false keeps Inertia's raw error overlay away: the reason is in the dialog.
                onHttpException: (response) => {
                    setDismissError(dismissErrorMessage(response?.status));

                    return false;
                },
                onNetworkError: () => {
                    setDismissError(dismissErrorMessage(null));

                    return false;
                },
                onFinish: () => setDismissInFlight(false),
            });
        } catch (error) {
            setDismissInFlight(false);
            setDismissError(dismissErrorMessage(null));
        }
    };

    const navigate = (overrides) => {
        router.get('/app/wiki', {
            tab: 'runs',
            run_status: filters.status ?? '',
            run_decision: filters.decision ?? '',
            run_src: filters.src_id ?? '',
            ...overrides,
        }, { preserveState: true, preserveScroll: true });
    };

    const hasActiveFilter = !!(filters.status || filters.decision || filters.src_id);

    const fetchRunPages = (runId) => {
        setRunPagesState((current) => ({ ...current, [runId]: { status: 'loading', data: null } }));

        fetch(`/app/wiki/runs/${runId}/pages`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((res) => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return res.json();
            })
            .then((data) => setRunPagesState((current) => ({ ...current, [runId]: { status: 'ready', data } })))
            .catch(() => setRunPagesState((current) => ({ ...current, [runId]: { status: 'error', data: null } })));
    };

    const fetchRunFindings = (runId, lintCount) => {
        setRunFindingsState((current) => ({ ...current, [runId]: { status: 'loading', data: null, fetchedForLintCount: lintCount } }));

        fetch(`/app/wiki/runs/${runId}/findings`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((res) => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return res.json();
            })
            .then((data) => setRunFindingsState((current) => ({ ...current, [runId]: { status: 'ready', data, fetchedForLintCount: lintCount } })))
            .catch(() => setRunFindingsState((current) => ({ ...current, [runId]: { status: 'error', data: null, fetchedForLintCount: lintCount } })));
    };

    const openPanel = (run, nextPanel) => {
        setExpandedRuns((current) => ({ ...current, [run.id]: nextPanel }));

        if (nextPanel === 'pages' && !runPagesState[run.id]) {
            fetchRunPages(run.id);
        }

        // Re-fetch whenever the row's own live lint_count (refreshed every 5s by the runs poll
        // below while a run is active) has moved past what this panel was last fetched for — a
        // findings panel opened early in a still-running run must never keep showing a stale
        // snapshot from before verification/QA produced its real findings.
        if (nextPanel === 'findings' && runFindingsState[run.id]?.fetchedForLintCount !== run.lint_count) {
            fetchRunFindings(run.id, run.lint_count);
        }
    };

    // The Sider/Funn counts in the row: open the detail view on that section, or close it again
    // when it already shows it.
    const togglePanel = (run, panel, hasData) => {
        if (!hasData) return;

        openPanel(run, (expandedRuns[run.id] ?? null) === panel ? null : panel);
    };

    // The row's chevron: opens on the findings when they need attention, otherwise on the pages.
    const toggleDetails = (run) => {
        if ((expandedRuns[run.id] ?? null) !== null) {
            openPanel(run, null);
            return;
        }

        openPanel(run, defaultRunDetailSection(run));
    };

    // The section switch inside an open detail view never closes it.
    const selectSection = (run, section) => {
        const count = section === 'pages' ? run.pages_count : run.lint_count;

        if ((count ?? 0) > 0) {
            openPanel(run, section);
        }
    };

    // "Tilbake til funn" deep link (EnterpriseWikiRunFindingsService::returnUrlForFinding()):
    // ?tab=runs&run_src=…&focus_run=…&focus_finding=… reopens the run's Funn panel by itself, so
    // returning from an article draft lands on the finding rather than on the document row. Read
    // from the URL via props, never from history state, so a refresh reproduces it.
    //
    // The ref makes it fire ONCE per deep link: without it, closing the panel again would be undone
    // on the next runs poll. A focus_run matching no row in this customer's list is simply ignored.
    const appliedFocusRef = useRef(null);
    const focusRunId = filters.focus_run ?? null;
    const focusFindingId = filters.focus_finding ?? null;

    useEffect(() => {
        if (focusRunId === null || focusFindingId === null) {
            return;
        }

        const focusKey = `${focusRunId}:${focusFindingId}`;

        if (appliedFocusRef.current === focusKey) {
            return;
        }

        const run = runs.find((candidate) => String(candidate.id) === String(focusRunId));

        if (!run) {
            return;
        }

        appliedFocusRef.current = focusKey;
        setExpandedRuns((current) => ({ ...current, [run.id]: 'findings' }));

        if (runFindingsState[run.id]?.fetchedForLintCount !== run.lint_count) {
            fetchRunFindings(run.id, run.lint_count);
        }
        // Deliberately not depending on the fetch helpers or panel state: see appliedFocusRef above.
    }, [focusRunId, focusFindingId, runs]);

    // Keeps an OPEN findings panel live: the runs poll refreshes run.lint_count every 5s while a
    // run is active, but that alone never triggers fetchRunFindings (a separate fetch(), not an
    // Inertia prop) — without this effect, a panel left open across a poll tick would keep
    // rendering whatever snapshot it fetched on the last toggle, even as the row's own badge count
    // moves on.
    useEffect(() => {
        runs.forEach((run) => {
            if (expandedRuns[run.id] === 'findings' && runFindingsState[run.id]?.fetchedForLintCount !== run.lint_count) {
                fetchRunFindings(run.id, run.lint_count);
            }
        });
        // Deliberately keyed on `runs` alone: the poll is what should trigger a refetch.
    }, [runs]);

    return (
        <div className="space-y-4">
            <h2 id="wiki-runs-heading" tabIndex={-1} className="sr-only">{ta.wiki_runs_heading ?? 'Kjøringer'}</h2>
            {/* Filter bar */}
            <div className="flex flex-wrap items-center gap-2">
                <select
                    aria-label={ta.wiki_filter_status ?? 'Status'}
                    value={filters.status ?? ''}
                    onChange={(e) => navigate({ run_status: e.target.value })}
                    className={`${RUNS_SELECT_CLS} max-w-full`}
                >
                    <option value="">{tw.runs_filter_status_all ?? 'Alle statuser'}</option>
                    {RUN_STATUS_FILTER_KEYS.map((key) => (
                        <option key={key} value={key}>{runStatusKeyLabel(key, tw)}</option>
                    ))}
                </select>

                <select
                    aria-label={ta.wiki_filter_decision ?? 'Beslutning'}
                    value={filters.decision ?? ''}
                    onChange={(e) => navigate({ run_decision: e.target.value })}
                    className={`${RUNS_SELECT_CLS} max-w-full`}
                >
                    <option value="">{tw.runs_filter_decision_all ?? 'Alle beslutninger'}</option>
                    <option value="pending">{tw.run_decision_pending ?? 'Venter'}</option>
                    <option value="applied">{tw.run_decision_applied ?? 'Sidestruktur opprettet'}</option>
                    <option value="none">{tw.runs_filter_decision_none ?? 'Ingen beslutning'}</option>
                </select>

                {filters.src_id && (
                    <span className="inline-flex h-11 items-center gap-1.5 rounded-lg border border-violet-200 bg-violet-50 px-3 text-base leading-6 text-violet-700">
                        {tw.runs_filter_src_active ?? 'Filtrert på dokument'}
                        <button
                            type="button"
                            onClick={() => navigate({ run_src: '' })}
                            className="ml-0.5 text-violet-700 hover:text-violet-900"
                            aria-label={tw.runs_filter_src_clear ?? 'Fjern kildefilter'}
                        >
                            <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                            </svg>
                        </button>
                    </span>
                )}

                {hasActiveFilter && (
                    <button
                        type="button"
                        onClick={() => navigate({ run_status: '', run_decision: '', run_src: '' })}
                        className="inline-flex h-11 items-center gap-1 rounded-lg px-3 text-base font-medium leading-6 text-slate-600 transition hover:text-slate-800"
                    >
                        <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                        </svg>
                        {tw.runs_filter_clear ?? 'Nullstill'}
                    </button>
                )}
            </div>

            {runs.length === 0 ? (
                <EmptyStateBox
                    title={tw.runs_empty ?? 'Ingen kjøringer ennå'}
                    description=""
                />
            ) : (
                <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    {/* Column heads for the eye; each row also states its own labels on small
                        screens. Hidden from assistive technology, which reads the rows as they are. */}
                    <div data-run-header aria-hidden="true" className="hidden items-center gap-x-3 border-b border-slate-200 bg-slate-50/70 px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-600 lg:grid" style={{ gridTemplateColumns: RUNS_GRID_TEMPLATE }}>
                        <div className="text-right">{tw.runs_col_id ?? 'ID'}</div>
                        <div>{tw.runs_col_document ?? 'Dokument'}</div>
                        <div>{tw.runs_col_status ?? 'Status'}</div>
                        <div>{tw.runs_col_decision ?? 'Beslutning'}</div>
                        <div className="text-center">{tw.runs_col_pages ?? 'Sider'}</div>
                        <div className="text-center">{tw.runs_col_lint ?? 'Funn'}</div>
                        <div>{tw.runs_col_created ?? 'Opprettet'}</div>
                        <div>{tw.runs_col_finished ?? 'Fullført'}</div>
                        <div className="text-right">{tw.runs_col_actions ?? 'Handlinger'}</div>
                    </div>
                    <div>
                        {runs.map((run) => {
                            const activePanel = expandedRuns[run.id] ?? null;
                            const panelId = `run-panel-${run.id}`;

                            return (
                                <RunListItem
                                    key={run.id}
                                    run={run}
                                    tw={tw}
                                    ta={ta}
                                    locale={locale}
                                    activePanel={activePanel}
                                    panelId={panelId}
                                    onCancelClick={handleCancelClick}
                                    onToggleDetails={toggleDetails}
                                    onTogglePanel={togglePanel}
                                    onSelectSection={selectSection}
                                    runPagesState={runPagesState}
                                    runFindingsState={runFindingsState}
                                    onRetryMaintainerDecision={handleRetryClick}
                                    onRetryFailedPages={handleRetryClick}
                                    onDismissRun={handleDismissClick}
                                    onFetchRunPages={fetchRunPages}
                                    onFetchRunFindings={fetchRunFindings}
                                    focusFindingId={String(focusRunId ?? '') === String(run.id) ? focusFindingId : null}
                                />
                            );
                        })}
                    </div>
                </section>
            )}

            {/* Cancelling and retrying a run (A11Y-002): modal dialogs with a title and a description,
                focus on the harmless choice, Escape, a trapped Tab and focus back on the button. */}
            <ActionDialog
                isOpen={cancelTarget !== null}
                onClose={handleCancelDismiss}
                titleId="wiki-run-cancel-title"
                descriptionId="wiki-run-cancel-description"
                initialFocusRef={cancelDismissRef}
                returnFocusRef={cancelTriggerRef}
                size="xs"
            >
                {cancelTarget && (
                    <>
                        <h2 id="wiki-run-cancel-title" className="mb-4 text-base font-semibold text-slate-900">
                            {tw.run_cancel_confirm_title ?? 'Bekreft avbrutt kjøring'}
                        </h2>
                        <div id="wiki-run-cancel-description">
                            <p className="break-all text-base font-medium leading-6 text-slate-800">
                                {cancelTarget.source_document_filename}
                            </p>
                            <p className="mt-2 text-base leading-6 text-slate-600">
                                {tw.run_cancel_confirm_body ?? 'Dette stopper kjøringen. Wiki-innhold som allerede er generert blir beholdt, men kjøringen kan ikke fortsette eller fullføres etterpå. Handlingen kan ikke angres.'}
                            </p>
                        </div>
                        <div className="mt-5 flex flex-wrap justify-end gap-3">
                            <button
                                ref={cancelDismissRef}
                                type="button"
                                onClick={handleCancelDismiss}
                                className="inline-flex h-11 items-center rounded-full border border-slate-300 px-4 text-base font-semibold leading-6 text-slate-600 transition hover:border-slate-400 hover:text-slate-900"
                            >
                                {tw.run_cancel_dismiss_button ?? 'Lukk'}
                            </button>
                            <button
                                type="button"
                                onClick={handleCancelConfirm}
                                className="inline-flex h-11 items-center rounded-full bg-rose-700 px-4 text-base font-semibold leading-6 text-white transition hover:bg-rose-800"
                            >
                                {tw.run_cancel_confirm_button ?? 'Avbryt kjøringen'}
                            </button>
                        </div>
                    </>
                )}
            </ActionDialog>

            <ActionDialog
                isOpen={retryTarget !== null}
                onClose={handleRetryDismiss}
                closeDisabled={retryInFlight}
                titleId="wiki-run-retry-title"
                descriptionId="wiki-run-retry-description"
                initialFocusRef={retryDismissRef}
                returnFocusRef={retryTriggerRef}
                size="xs"
            >
                {retryTarget && (
                    <>
                        <h2 id="wiki-run-retry-title" className="mb-4 text-base font-semibold text-slate-900">
                            {retryKind === 'failed_pages'
                                ? (tw.run_retry_failed_pages_confirm_title ?? 'Prøv igjen for feilede sider?')
                                : (tw.run_retry_maintainer_decision_confirm_title ?? 'Prøv sideplanleggingen på nytt?')}
                        </h2>
                        <div id="wiki-run-retry-description">
                            <p className="break-all text-base font-medium leading-6 text-slate-800">
                                {retryTarget.source_document_filename}
                            </p>
                            <p className="mt-2 text-base leading-6 text-slate-600">
                                {retryKind === 'failed_pages'
                                    ? (tw.run_retry_failed_pages_confirm_body ?? 'Bare sidene som feilet, genereres på nytt. Sider som allerede er generert, beholdes uendret. Deretter fortsetter kjøringen med lenker, kontroll og QA som vanlig.')
                                    : (tw.run_retry_maintainer_decision_confirm_body ?? 'Dokumentet og kildegrunnlaget er bevart. Sideplanleggingen startes på nytt med samme kjøring — du trenger ikke laste opp dokumentet igjen.')}
                            </p>
                        </div>
                        <div className="mt-5 flex flex-wrap justify-end gap-3">
                            <button
                                ref={retryDismissRef}
                                type="button"
                                onClick={handleRetryDismiss}
                                aria-disabled={retryInFlight || undefined}
                                className="inline-flex h-11 items-center rounded-full border border-slate-300 px-4 text-base font-semibold leading-6 text-slate-600 transition hover:border-slate-400 hover:text-slate-900 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                            >
                                {tw.run_retry_maintainer_decision_dismiss_button ?? 'Lukk'}
                            </button>
                            <button
                                type="button"
                                onClick={handleRetryConfirm}
                                aria-disabled={retryInFlight || undefined}
                                className={`inline-flex h-11 items-center rounded-full px-4 text-base font-semibold leading-6 transition aria-disabled:cursor-not-allowed aria-disabled:opacity-50 ${PRIMARY_COLOURS}`}
                            >
                                {retryKind === 'failed_pages'
                                    ? (tw.run_retry_failed_pages_confirm_button ?? 'Prøv igjen')
                                    : (tw.run_retry_maintainer_decision_confirm_button ?? 'Prøv på nytt')}
                            </button>
                        </div>
                    </>
                )}
            </ActionDialog>

            <ActionDialog
                isOpen={dismissTarget !== null}
                onClose={handleDismissCancel}
                closeDisabled={dismissInFlight}
                titleId="wiki-run-dismiss-title"
                descriptionId="wiki-run-dismiss-description"
                initialFocusRef={dismissCancelRef}
                returnFocusRef={dismissTriggerRef}
                size="xs"
            >
                {dismissTarget && (
                    <div data-run-dismiss-dialog>
                        <h2 id="wiki-run-dismiss-title" className="mb-4 text-base font-semibold text-slate-900">
                            {tw.run_dismiss_confirm_title ?? 'Fjerne kjøringen fra oversikten?'}
                        </h2>
                        <div id="wiki-run-dismiss-description">
                            <p className="break-all text-base font-medium leading-6 text-slate-800">
                                {dismissTarget.source_document_filename}
                            </p>
                            <p className="mt-2 text-base leading-6 text-slate-600">
                                {tw.run_dismiss_confirm_body ?? 'Kjøringen fjernes fra Kjøringer. Wiki-artikler, kildedokumentet og historiske data beholdes.'}
                            </p>
                        </div>
                        {dismissError && (
                            <p role="alert" data-run-dismiss-error className="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-base leading-6 text-rose-800">
                                {dismissError}
                            </p>
                        )}
                        <div className="mt-5 flex flex-wrap justify-end gap-3">
                            <button
                                ref={dismissCancelRef}
                                type="button"
                                onClick={handleDismissCancel}
                                aria-disabled={dismissInFlight || undefined}
                                className="inline-flex h-11 items-center rounded-full border border-slate-300 px-4 text-base font-semibold leading-6 text-slate-600 transition hover:border-slate-400 hover:text-slate-900 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                            >
                                {tw.run_dismiss_cancel_button ?? 'Avbryt'}
                            </button>
                            <button
                                type="button"
                                onClick={handleDismissConfirm}
                                aria-disabled={dismissInFlight || undefined}
                                className={`inline-flex h-11 items-center rounded-full px-4 text-base font-semibold leading-6 transition aria-disabled:cursor-not-allowed aria-disabled:opacity-50 ${PRIMARY_COLOURS}`}
                            >
                                {tw.run_dismiss_confirm_button ?? 'Fjern fra oversikten'}
                            </button>
                        </div>
                    </div>
                )}
            </ActionDialog>
        </div>
    );
}

const OWNER_STATUS_STYLES = {
    approved: 'bg-emerald-100 text-emerald-700',
    rejected: 'bg-rose-100 text-rose-700',
    pending: 'bg-amber-100 text-amber-700',
    mixed: 'bg-amber-100 text-amber-700',
    missing_owner: 'bg-rose-100 text-rose-700',
    awaiting_sync: 'bg-slate-100 text-slate-700',
    qa_review_open: 'bg-slate-100 text-slate-700',
    processing: 'bg-slate-100 text-slate-700',
    processing_failed: 'bg-rose-100 text-rose-700',
    superseded: 'bg-slate-100 text-slate-600',
};

/**
 * Lazily-fetched detail shown inline under an expanded Kjøringer row (Del 1-9) — never a
 * separate admin page/side panel, so active filters and scroll position on the runs table are
 * untouched. `state` is undefined while nothing has been fetched yet, `{status:'loading'}` while
 * the request is in flight, `{status:'error'}` on failure, or `{status:'ready', data}` once the
 * GET /app/wiki/runs/{run}/pages response has arrived.
 */
function RunAffectedPagesPanel({ panelId, state, onRetry, tw }) {
    if (!state || state.status === 'loading') {
        return (
            <div id={panelId} className="flex items-center gap-2 py-2 text-base leading-6 text-slate-600">
                <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-slate-300 border-t-violet-500" aria-hidden="true" />
                {tw.runs_pages_loading ?? 'Henter berørte Wiki-sider …'}
            </div>
        );
    }

    if (state.status === 'error') {
        return (
            <div id={panelId} className="flex items-center justify-between gap-3 py-2 text-base leading-6 text-rose-700">
                <span role="alert">{tw.runs_pages_error ?? 'Kunne ikke hente Wiki-sidene. Prøv igjen.'}</span>
                <button
                    type="button"
                    onClick={onRetry}
                    className="shrink-0 rounded-md border border-slate-200 px-3.5 py-2 text-base font-medium leading-6 text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                >
                    {tw.runs_pages_retry ?? 'Prøv igjen'}
                </button>
            </div>
        );
    }

    const pages = state.data?.pages ?? [];
    const summary = state.data?.summary ?? {};
    const stallExplanation = state.data?.stall_explanation;

    const summaryParts = [
        (tw.runs_pages_summary_done ?? ':done av :total ferdigbehandlet')
            .replace(':done', summary.done ?? 0)
            .replace(':total', summary.total ?? 0),
    ];

    if ((summary.awaiting_document_owner ?? 0) > 0) {
        summaryParts.push(
            (tw.runs_pages_summary_awaiting ?? ':count venter på Dokumenteier').replace(':count', summary.awaiting_document_owner),
        );
    }

    if ((summary.pending_review ?? 0) > 0) {
        summaryParts.push(
            (tw.runs_pages_summary_pending_review ?? ':count under behandling eller med åpne QA-punkter').replace(':count', summary.pending_review),
        );
    }

    return (
        <div id={panelId} className="space-y-3">
            <div>
                <h4 className="text-base font-semibold text-slate-800">{tw.runs_pages_heading ?? 'Berørte Wiki-sider'}</h4>
                <p className="mt-1 text-base leading-6 text-slate-600">{summaryParts.join(' · ')}</p>
                {stallExplanation && (
                    <p className="mt-1 text-base font-medium leading-6 text-amber-700">{stallExplanation}</p>
                )}
            </div>

            <ScrollRegion label={tw.runs_pages_heading ?? 'Berørte Wiki-sider'} edgeRoom={false} className="rounded-xl border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-100 text-base leading-6">
                    <caption className="sr-only">{tw.runs_pages_heading ?? 'Berørte Wiki-sider'}</caption>
                    <thead className="bg-slate-50 text-base font-semibold uppercase tracking-wide text-slate-600">
                        <tr className="text-left">
                            <th scope="col" className="px-3 py-2.5">{tw.runs_pages_col_page ?? 'Wiki-side'}</th>
                            <th scope="col" className="px-3 py-2.5">{tw.runs_pages_col_result ?? 'Resultat'}</th>
                            <th scope="col" className="px-3 py-2.5">{tw.runs_pages_col_version ?? 'Sideversjon'}</th>
                            <th scope="col" className="px-3 py-2.5">{tw.runs_pages_col_owner_status ?? 'Dokumenteierstatus'}</th>
                            <th scope="col" className="px-3 py-2.5 text-right">{tw.runs_pages_col_action ?? 'Handling'}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {pages.map((page) => (
                            <tr key={page.page_id} className="text-slate-700">
                                <td className="max-w-[240px] px-3 py-2.5">
                                    <span className="block truncate font-medium text-slate-900" title={page.title}>
                                        {page.title}
                                    </span>
                                </td>
                                <td className="px-3 py-2.5">
                                    <span className={`${BADGE} ${page.action === 'created' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>
                                        {page.action === 'created'
                                            ? (tw.runs_pages_result_created ?? 'Opprettet')
                                            : (tw.runs_pages_result_updated ?? 'Oppdatert')}
                                    </span>
                                </td>
                                <td className="px-3 py-2.5 whitespace-nowrap text-slate-600">
                                    {page.version_number
                                        ? (tw.runs_pages_version_label ?? 'Versjon :number').replace(':number', page.version_number)
                                        : '—'}
                                </td>
                                <td className="px-3 py-2.5">
                                    <span className={`${BADGE} ${OWNER_STATUS_STYLES[page.document_owner_status?.state] ?? 'bg-slate-100 text-slate-600'}`}>
                                        {page.document_owner_status?.label ?? '—'}
                                    </span>
                                </td>
                                <td className="px-3 py-2.5 text-right whitespace-nowrap">
                                    <Link href={page.url} className="text-base font-medium leading-6 text-violet-700 hover:underline">
                                        {page.can_handle
                                            ? (tw.runs_pages_action_open_and_handle ?? 'Åpne og behandle')
                                            : (tw.runs_pages_action_open ?? 'Åpne side')}
                                        <span className="sr-only"> {page.title}</span>
                                    </Link>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </ScrollRegion>
        </div>
    );
}

// Readability pass (low-vision user report): a dedicated, larger badge for this findings list
// only — the shared BADGE constant above (h-6/text-xs) stays untouched everywhere else in this
// file (Kjøringer status chips, Quality tab, page-type/source badges, etc.) so this fix stays
// scoped to the Funn/Wiki-side/Alvorlighet/Status/Handling list, not a site-wide badge redesign.
const FINDING_BADGE = 'inline-flex max-w-full items-center rounded-full px-2.5 py-0.5 text-base font-semibold leading-5';

const FINDING_SEVERITY_STYLES = {
    critical: 'bg-rose-100 text-rose-700',
    error: 'bg-rose-100 text-rose-700',
    warning: 'bg-amber-100 text-amber-700',
    suggestion: 'bg-sky-100 text-sky-700',
    // slate-500 measured ~4.6:1 on white/slate-100 — bumped to slate-700 (~10:1) so the "info"
    // variant is never the least legible badge in the row (readability pass).
    info: 'bg-slate-100 text-slate-700',
};

const FINDING_STATUS_STYLES = {
    requires_action: 'bg-rose-100 text-rose-700',
    open: 'bg-amber-100 text-amber-700',
    resolved: 'bg-emerald-100 text-emerald-700',
    informative: 'bg-slate-100 text-slate-700',
    superseded: 'bg-slate-100 text-slate-600',
    // Best-practice suggestion statuses — deliberately neutral/positive, never the rose/critical
    // styling used for real quality defects (Del 1).
    pending_review: 'bg-sky-100 text-sky-700',
    approved: 'bg-emerald-100 text-emerald-700',
    approved_edited: 'bg-emerald-100 text-emerald-700',
    rejected: 'bg-slate-100 text-slate-700',
    // A claim finding whose deviation a reviewer explicitly accepted — handled, like the others.
    accepted_deviation: 'bg-emerald-100 text-emerald-700',
    // Voluntary claim QA signals (v0.10, docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat —
    // v0.10") — deliberately the same neutral sky tone as best-practice suggestions, never the
    // rose/critical styling reserved for a genuinely blocking (requires_action) technical finding.
    open_for_qa_review: 'bg-sky-100 text-sky-700',
    flagged_for_review: 'bg-sky-100 text-sky-700',
};

const FINDINGS_LOCAL_FILTERS = ['all', 'open', 'blocking', 'resolved', 'informative'];

function findingsLocalFilterLabel(filterKey, tw) {
    return {
        all: tw.runs_findings_filter_all ?? 'Alle',
        open: tw.runs_findings_filter_open ?? 'Åpne',
        blocking: tw.runs_findings_filter_blocking ?? 'Blokkerende',
        resolved: tw.runs_findings_filter_resolved ?? 'Løst',
        informative: tw.runs_findings_filter_informative ?? 'Informasjon',
    }[filterKey] ?? filterKey;
}

/**
 * Kjøringer "Funn" detail — same inline-under-the-row placement and lazy-fetch pattern as
 * RunAffectedPagesPanel (Del 7); shares the same expansion row, switching in as an alternative to
 * it rather than stacking a second independent panel. Local status/blocking filters apply only
 * within this open panel and never touch the Kjøringer tab's own filter bar.
 *
 * One compact row per finding: what it is, where, how serious, its status and the way to handle
 * it. The explanation, the claim, the source excerpts and the decision live in the behandlings-
 * visning the row links to (Show.jsx, ?claim_id= / ?finding_id=), so none of it is repeated here;
 * nor is the run's own explanation, which «Status og oppfølging» above already gives.
 */
function RunFindingsPanel({ panelId, state, onRetry, tw, ta = {}, locale, focusFindingId = null }) {
    // Defaults to 'open' so a run with many historically-resolved findings doesn't bury the
    // ones that still need attention — 'resolved' (Løst) remains one click away via the filter
    // chips below, unchanged.
    const [localFilter, setLocalFilter] = useState('open');
    // The row a "Tilbake til funn" deep link points at: scrolled to, focused, and briefly ringed.
    const [focusedRowId, setFocusedRowId] = useState(null);
    const focusRowRef = useRef(null);
    const appliedFocusRef = useRef(null);

    const findingsData = state?.status === 'ready' ? (state.data?.findings ?? []) : null;

    // Runs once the findings have actually arrived — the panel fetches them lazily, so at deep-link
    // time there is usually nothing to focus yet. Widening the filter is conditional
    // (focusedFindingLocalFilter): an already-approved best-practice finding is invisible under the
    // default 'open' chip, and returning to a list that looks empty is the bug this whole flow
    // exists to avoid.
    useEffect(() => {
        if (findingsData === null || !focusFindingId || appliedFocusRef.current === focusFindingId) {
            return;
        }

        const finding = resolveFocusedFinding(findingsData, focusFindingId);

        if (!finding) {
            return;
        }

        appliedFocusRef.current = focusFindingId;
        setLocalFilter((current) => focusedFindingLocalFilter(finding, current));
        setFocusedRowId(finding.id);
    }, [findingsData, focusFindingId]);

    // Scroll only after the row exists in the DOM — the filter change above has to render first,
    // otherwise the target is still filtered away and there is nothing to scroll to. The ring is
    // temporary: it marks where the user landed without becoming permanent UI state that later
    // has to be cleared by hand.
    useEffect(() => {
        if (focusedRowId === null || !focusRowRef.current) {
            return undefined;
        }

        focusRowRef.current.scrollIntoView({ behavior: 'smooth', block: 'center' });
        // The deep link lands focus on the finding too (A11Y-015), not only the scroll position:
        // a keyboard or screen-reader user continues from there.
        focusElement(focusRowRef.current, { preventScroll: true });

        const timer = setTimeout(() => setFocusedRowId(null), 2600);

        return () => clearTimeout(timer);
    }, [focusedRowId]);

    if (!state || state.status === 'loading') {
        return (
            <div id={panelId} className="flex items-center gap-2 py-2 text-base leading-6 text-slate-600">
                <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-slate-300 border-t-rose-500" aria-hidden="true" />
                {tw.runs_findings_loading ?? 'Henter kvalitetsfunn …'}
            </div>
        );
    }

    if (state.status === 'error') {
        return (
            <div id={panelId} className="flex items-center justify-between gap-3 py-2 text-base leading-6 text-rose-700">
                <span role="alert">{tw.runs_findings_error ?? 'Kunne ikke hente kvalitetsfunnene. Prøv igjen.'}</span>
                <button
                    type="button"
                    onClick={onRetry}
                    className="shrink-0 rounded-md border border-slate-200 px-3.5 py-2 text-base font-medium leading-6 text-slate-700 transition hover:border-slate-300 hover:text-slate-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-500"
                >
                    {tw.runs_pages_retry ?? 'Prøv igjen'}
                </button>
            </div>
        );
    }

    const findings = state.data?.findings ?? [];
    const visibleFindings = findings.filter((f) => matchesFindingsLocalFilter(f, localFilter));

    return (
        <div id={panelId} className="space-y-3">
            {/* Filter chips are toggle buttons in a named group; the pressed one is said, not only
                shown by its dark fill (A11Y-117). Each carries its own count, which replaces the
                separate summary sentence that repeated the same numbers. */}
            {findings.length > 0 && (
                <div role="group" aria-label={ta.wiki_findings_filter_label ?? 'Vis funn'} className="flex flex-wrap gap-2">
                    {FINDINGS_LOCAL_FILTERS.map((filterKey) => (
                        <button
                            key={filterKey}
                            type="button"
                            data-findings-filter={filterKey}
                            aria-pressed={localFilter === filterKey}
                            onClick={() => setLocalFilter(filterKey)}
                            className={`inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-base font-semibold leading-6 transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-500 ${
                                localFilter === filterKey
                                    ? 'bg-slate-800 text-white'
                                    : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                            }`}
                        >
                            {findingsLocalFilterLabel(filterKey, tw)}
                            <span className="tabular-nums">{findings.filter((f) => matchesFindingsLocalFilter(f, filterKey)).length}</span>
                        </button>
                    ))}
                </div>
            )}

            <ScrollRegion label={tw.runs_findings_heading ?? 'Kvalitetsfunn'} edgeRoom={false} className="rounded-xl border border-slate-200 bg-white">
                <table data-run-findings-table className="w-full min-w-[52rem] table-fixed lg:min-w-0 divide-y divide-slate-100 text-base leading-6">
                    <caption className="sr-only">{tw.runs_findings_heading ?? 'Kvalitetsfunn'}</caption>
                    <thead className="bg-slate-50 text-base font-semibold uppercase tracking-wide text-slate-600">
                        <tr className="text-left">
                            <th scope="col" className="w-[26%] px-4 py-2.5">{tw.runs_findings_col_finding ?? 'Funn'}</th>
                            <th scope="col" className="px-4 py-2.5">{tw.runs_pages_col_page ?? 'Wiki-side'}</th>
                            <th scope="col" className="w-[8.5rem] px-3 py-2.5">{tw.quality_col_severity ?? 'Alvorlighet'}</th>
                            <th scope="col" className="w-40 px-3 py-2.5">{tw.runs_findings_col_status ?? 'Status'}</th>
                            <th scope="col" className="w-32 px-2 py-2.5 text-right">{tw.runs_pages_col_action ?? 'Handling'}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {visibleFindings.length === 0 && (
                            <tr>
                                <td colSpan={5} className="px-4 py-3 text-slate-600">
                                    {tw.runs_findings_filter_empty ?? 'Ingen funn i dette utvalget.'}
                                </td>
                            </tr>
                        )}
                        {visibleFindings.map((finding) => {
                            const isFocused = focusedRowId === finding.id;
                            const label = finding.category_label || finding.title;

                            return (
                                <tr
                                    key={finding.id}
                                    ref={isFocused ? focusRowRef : null}
                                    data-finding-row={finding.id}
                                    className={`align-middle text-slate-700 transition-colors duration-500 ${
                                        isFocused ? 'bg-violet-50 ring-2 ring-inset ring-violet-300' : ''
                                    }`}
                                >
                                    <td className="px-4 py-2">
                                        <span className="block break-words font-semibold text-slate-900" title={finding.title && finding.title !== label ? finding.title : undefined}>
                                            <span className="font-mono text-sm text-slate-600">#{formatFindingUserId(finding.id)}</span>
                                            {label && <> · {label}</>}
                                        </span>
                                        {/* Without a behandlingsvisning to open, the row is the only place
                                            the explanation can be read — kept, but held to two lines. */}
                                        {!finding.url && finding.explanation && (
                                            <span className="line-clamp-2 text-slate-600" title={finding.explanation}>{finding.explanation}</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2">
                                        {finding.scope === 'run' ? (
                                            <span className="text-slate-600">{tw.runs_findings_scope_run ?? 'Gjelder hele kjøringen'}</span>
                                        ) : (
                                            <span className="line-clamp-3 break-words" title={finding.page_title ?? ''}>{finding.page_title ?? '—'}</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        <span className={`${FINDING_BADGE} ${FINDING_SEVERITY_STYLES[finding.severity] ?? 'bg-slate-100 text-slate-700'}`}>
                                            {finding.severity_label}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2">
                                        <span className={`${FINDING_BADGE} ${FINDING_STATUS_STYLES[finding.status] ?? 'bg-slate-100 text-slate-700'}`}>
                                            {finding.status_label}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2 text-right">
                                        {finding.url && finding.action ? (
                                            <Link
                                                href={finding.url}
                                                aria-label={`${finding.action_label}: ${(tw.runs_findings_id_label ?? 'Funn #:id').replace(':id', formatFindingUserId(finding.id))}`}
                                                className="inline-block rounded-lg px-2 py-0.5 text-right text-base font-semibold leading-6 text-violet-700 underline decoration-violet-300 decoration-2 underline-offset-2 transition hover:bg-violet-50 hover:text-violet-800 hover:decoration-violet-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600"
                                            >
                                                {finding.action_label}
                                            </Link>
                                        ) : (
                                            <span className="text-slate-600">—</span>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </ScrollRegion>
        </div>
    );
}

// ─── Quality tab ─────────────────────────────────────────────────────────────

const PAGE_TYPE_LABELS = {
    article: 'Article',
    summary: 'Summary',
    concept: 'Concept',
    entity: 'Entity',
    index: 'Index',
    backlinks: 'Backlinks',
};

// ─── Main export ─────────────────────────────────────────────────────────────

export default function WikiIndex({
    active_tab: activeTab = 'pages',
    pages = [],
    pages_meta: pagesMeta = null,
    pages_filters: pagesFilters = null,
    pages_document_owner_options: pagesDocumentOwnerOptions = [],
    publication_summary: publicationSummary = null,
    sources = [],
    sources_filters: sourcesFilters = null,
    document_owner_options: documentOwnerOptions = [],
    runs = [],
    runs_filters: runsFilters = null,
    sources_store_url: sourcesStoreUrl = '/app/wiki/sources',
    wiki_generation_available: wikiGenerationAvailable = false,
    has_active_wiki_run: hasActiveWikiRunAnyTab = false,
    // What the customer's own roles let this person do in the Wiki. Presentation only: the page
    // hides what it would be refused, and every route below enforces the same answer again.
    permissions = {},
}) {
    const { translations = {} } = usePage().props;
    const tw = translations?.wiki ?? {};
    const ta = translations?.a11y ?? {};
    const locale = document.documentElement.lang || 'no';
    // Wiki run-4: hasActiveWikiRunForTab() (runFindingsLogic.js) picks the right run-like objects
    // per tab — a source object has no top-level `status` of its own (it has `document_status`, a
    // different lifecycle); the ingest run status lives nested at source.latest_ingest_run. The
    // previous inline check read `source.status` directly (always undefined), so this was
    // permanently false whenever the Kilder tab was open and the polling effect below never
    // started — a run could keep progressing (post_claim_verification -> qa ->
    // awaiting_document_owner_approval) in the database while its Kilder-tab badge kept showing
    // whatever status was rendered on the last full page load, forever, until a manual refresh.
    const hasActiveWikiRun = hasActiveWikiRunForTab(activeTab, { sources, runs });
    const wikiHelpContent = activeTab === 'pages'
        ? {
            title: tw.page_help_title ?? 'Slik fungerer Wiki-sider',
            intro: tw.page_help_intro ?? 'Wiki-sidene bygges fra virksomhetens kildedokumenter. Dokumenteiere, kildegrunnlag, godkjenninger og kvalitetskontroll sørger for at innholdet er faglig forankret og sporbarheten beholdes.',
            sections: getWikiPagesHelpSections(tw),
        }
        : activeTab === 'sources'
            ? {
                title: tw.sources_page_help_title ?? 'Slik fungerer Kildedokumenter',
                intro: tw.sources_page_help_intro ?? 'Kildedokumenter er grunnlaget for Enterprise Wiki. Her laster du opp dokumenter, velger Dokumenteier, følger behandlingen og ser hvilke Wiki-sider som bruker dokumentet.',
                sections: getWikiSourcesHelpSections(tw),
            }
                : activeTab === 'runs'
                    ? {
                        title: tw.runs_page_help_title ?? 'Slik fungerer Kjøringer',
                        intro: tw.runs_page_help_intro ?? 'Kjøringer viser hvordan kildedokumenter behandles og blir til Wiki-materiale. Her kan du følge fremdrift, se hvilket steg behandlingen er på, og finne forståelige forklaringer dersom noe stopper eller venter på godkjenning.',
                        sections: getWikiRunsHelpSections(tw),
                    }
                    : null;

    useEffect(() => {
        // The Pages tab never loads a `runs` prop of its own (see WikiController::index()'s
        // match on active_tab), so it can't tell locally whether a run is still working — it
        // relies on the always-computed has_active_wiki_run prop instead. Sources/Runs keep
        // using their own already-loaded run list, which is more precise (exact run statuses,
        // not just "some run is non-terminal somewhere for this customer").
        const only = activeTab === 'sources' ? ['sources'] : activeTab === 'runs' ? ['runs'] : activeTab === 'pages' ? ['pages'] : null;
        const shouldPoll = only !== null && (activeTab === 'pages' ? hasActiveWikiRunAnyTab : hasActiveWikiRun);

        if (!shouldPoll) {
            return undefined;
        }

        const interval = window.setInterval(() => {
            router.reload({ only, preserveScroll: true });
        }, 5000);

        return () => window.clearInterval(interval);
    }, [activeTab, hasActiveWikiRun, hasActiveWikiRunAnyTab]);

    return (
        <CustomerAppLayout title={tw.index_title ?? 'Wiki'} showPageTitle={false}>
            <div className="space-y-6">
                {/* No card. The page title is the page, not an object on it — a border and a
                    shadow around it made the header compete with the content it introduces. */}
                <header className="flex flex-wrap items-start justify-between gap-4 overflow-visible">
                    <div className="min-w-0 space-y-2">
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                            {tw.index_title ?? 'Wiki'}
                        </h1>
                        <p className="max-w-3xl text-base leading-6 text-slate-600">
                            {tw.index_description ?? 'Strukturert kunnskap om virksomheten, generert fra godkjent innhold.'}
                        </p>
                    </div>
                    {wikiHelpContent && (
                        <PageHelpButton
                            buttonLabel={tw.page_help_button ?? 'Hjelp'}
                            title={wikiHelpContent.title}
                            intro={wikiHelpContent.intro}
                            sections={wikiHelpContent.sections}
                        />
                    )}
                </header>

                {activeTab === 'pages' && (
                    <PagesTab
                        pages={pages}
                        pagesMeta={pagesMeta}
                        pagesFilters={pagesFilters}
                        pagesDocumentOwnerOptions={pagesDocumentOwnerOptions}
                        publicationSummary={publicationSummary}
                        tw={tw}
                        ta={ta}
                        locale={locale}
                    />
                )}
                {activeTab === 'sources' && (
                    <SourcesTab
                        sources={sources}
                        sourcesFilters={sourcesFilters}
                        sourcesStoreUrl={sourcesStoreUrl}
                        documentOwnerOptions={documentOwnerOptions}
                        wikiGenerationAvailable={wikiGenerationAvailable}
                        canManageSources={permissions.can_manage_sources ?? false}
                        tw={tw}
                        ta={ta}
                        locale={locale}
                    />
                )}
                {activeTab === 'runs' && (
                    <RunsTab runs={runs} runsFilters={runsFilters} tw={tw} ta={ta} locale={locale} />
                )}
            </div>
        </CustomerAppLayout>
    );
}
