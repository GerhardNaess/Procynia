import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';

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

const BADGE = 'inline-flex h-6 items-center rounded-full px-3 text-xs font-semibold whitespace-nowrap';

const STATUS_STYLES = {
    approved: 'bg-emerald-100 text-emerald-700',
    pending_review: 'bg-amber-100 text-amber-700',
    draft: 'bg-slate-200 text-slate-600',
    rejected: 'bg-rose-100 text-rose-700',
    archived: 'bg-slate-200 text-slate-500',
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

const SOURCE_STATUS_STYLES = {
    extracted: 'bg-emerald-100 text-emerald-700',
    pending: 'bg-amber-100 text-amber-700',
    failed: 'bg-rose-100 text-rose-700',
};

function SourceStatusBadge({ status, label }) {
    const cls = SOURCE_STATUS_STYLES[status] ?? 'bg-slate-200 text-slate-600';
    return <span className={`${BADGE} ${cls}`}>{label}</span>;
}

const INGEST_STATUS_STYLES = {
    queued: 'bg-amber-100 text-amber-700',
    running: 'bg-blue-100 text-blue-700',
    sections_planned: 'bg-blue-100 text-blue-700',
    completed: 'bg-emerald-100 text-emerald-700',
    failed: 'bg-rose-100 text-rose-700',
    decision_only: 'bg-violet-100 text-violet-700',
};

const SEVERITY_STYLES = {
    error: 'bg-rose-100 text-rose-700',
    warning: 'bg-amber-100 text-amber-700',
    info: 'bg-slate-100 text-slate-600',
};

const IN_PROGRESS_STATUSES = ['queued', 'running', 'sections_planned'];

function IngestStatusBadge({ run, label, notStartedLabel, locale, onReload, tw, onViewDecision }) {
    if (!run) {
        return <span className={`${BADGE} bg-slate-100 text-slate-500`}>{notStartedLabel}</span>;
    }

    if (run.status === 'decision_only') {
        const generatedAt = run.maintainer_decision_generated_at
            ? formatDate(run.maintainer_decision_generated_at, locale)
            : null;
        return (
            <div className="space-y-1.5">
                <span className={`${BADGE} bg-violet-100 text-violet-700`}>{label}</span>
                {generatedAt && (
                    <p className="text-[11px] text-slate-400">
                        {(tw ?? {}).decision_panel_generated ?? 'Generert'} {generatedAt}
                    </p>
                )}
                {run.maintainer_decision_json && onViewDecision && (
                    <button
                        type="button"
                        onClick={() => onViewDecision(run)}
                        className="inline-flex h-6 items-center rounded-full border border-violet-200 bg-violet-50 px-3 text-[11px] font-semibold text-violet-700 transition hover:bg-violet-100"
                    >
                        {(tw ?? {}).decision_panel_view_button ?? 'Vis beslutning'}
                    </button>
                )}
            </div>
        );
    }

    const cls = INGEST_STATUS_STYLES[run.status] ?? 'bg-slate-100 text-slate-600';
    const isInProgress = IN_PROGRESS_STATUSES.includes(run.status);
    const queuedSince = run.status === 'queued' ? formatTime(run.created_at, locale) : null;
    const startedAt = (run.status === 'running' || run.status === 'sections_planned') ? formatTime(run.started_at, locale) : null;
    return (
        <div className="space-y-1.5">
            <div className="flex items-center gap-2">
                <span className={`${BADGE} ${cls}`}>{label}</span>
                {isInProgress && (
                    <button
                        type="button"
                        onClick={onReload}
                        className="text-[11px] text-slate-400 underline hover:text-slate-600"
                    >
                        Oppdater
                    </button>
                )}
            </div>
            {run.status === 'queued' && (
                <p className="text-[11px] leading-4 text-slate-400">
                    {queuedSince ? `I kø siden ${queuedSince} · ` : ''}Sjekk at{' '}
                    <span className="font-mono">enterprise-wiki</span> worker kjører.
                </p>
            )}
            {startedAt && (
                <p className="text-[11px] text-slate-400">Startet {startedAt}</p>
            )}
            {run.status === 'failed' && run.error_message && (
                <p
                    className="line-clamp-2 wrap-break-word text-[11px] leading-4 text-rose-500"
                    title={run.error_message}
                >
                    {run.error_message}
                </p>
            )}
        </div>
    );
}

function DecisionPageEntry({ entry, tw }) {
    if (!entry) return <span className="text-[11px] text-slate-400">{tw.decision_no_items ?? 'Ingen'}</span>;
    return (
        <dl className="space-y-0.5 text-[11px]">
            <div className="flex gap-1.5">
                <dt className="text-slate-400">{tw.decision_action_label ?? 'Handling'}:</dt>
                <dd className="font-mono font-semibold text-violet-700">{entry.action}</dd>
            </div>
            {entry.title && (
                <div className="flex gap-1.5">
                    <dt className="text-slate-400">{tw.decision_title_label ?? 'Tittel'}:</dt>
                    <dd className="text-slate-800">{entry.title}</dd>
                </div>
            )}
            {entry.proposed_slug && (
                <div className="flex gap-1.5">
                    <dt className="text-slate-400">{tw.decision_slug_label ?? 'Slug'}:</dt>
                    <dd className="font-mono text-slate-600">{entry.proposed_slug}</dd>
                </div>
            )}
            {entry.reason && (
                <div className="flex gap-1.5">
                    <dt className="shrink-0 text-slate-400">{tw.decision_reason_label ?? 'Begrunnelse'}:</dt>
                    <dd className="text-slate-700">{entry.reason}</dd>
                </div>
            )}
        </dl>
    );
}

function DecisionModal({ run, tw, onClose }) {
    const d = run.maintainer_decision_json ?? {};
    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
        >
            <div className="relative w-full max-w-2xl overflow-hidden rounded-3xl bg-white shadow-2xl">
                <div className="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 className="text-base font-semibold text-slate-950">
                        {tw.decision_panel_heading ?? 'Vedlikeholdersbeslutning'}
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                        aria-label={tw.decision_modal_close ?? 'Lukk'}
                    >
                        <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                        </svg>
                    </button>
                </div>

                <div className="max-h-[70vh] overflow-y-auto px-6 py-5 space-y-5">
                    <section className="space-y-2">
                        <h3 className="text-[11px] font-semibold uppercase tracking-widest text-slate-400">
                            {tw.decision_source_article ?? 'Kildeartikkel'}
                        </h3>
                        <DecisionPageEntry entry={d.source_article} tw={tw} />
                    </section>

                    <section className="space-y-2">
                        <h3 className="text-[11px] font-semibold uppercase tracking-widest text-slate-400">
                            {tw.decision_source_summary ?? 'Sammendrag'}
                        </h3>
                        <DecisionPageEntry entry={d.source_summary} tw={tw} />
                    </section>

                    <section className="space-y-2">
                        <h3 className="text-[11px] font-semibold uppercase tracking-widest text-slate-400">
                            {tw.decision_concept_pages ?? 'Konseptsider'}
                        </h3>
                        {(d.concept_pages ?? []).length === 0 ? (
                            <span className="text-[11px] text-slate-400">{tw.decision_no_items ?? 'Ingen'}</span>
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
                        <h3 className="text-[11px] font-semibold uppercase tracking-widest text-slate-400">
                            {tw.decision_entity_pages ?? 'Entitetssider'}
                        </h3>
                        {(d.entity_pages ?? []).length === 0 ? (
                            <span className="text-[11px] text-slate-400">{tw.decision_no_items ?? 'Ingen'}</span>
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
                            <h3 className="text-[11px] font-semibold uppercase tracking-widest text-amber-500">
                                {tw.decision_warnings ?? 'Advarsler'}
                            </h3>
                            <ul className="space-y-1">
                                {d.warnings.map((w, i) => (
                                    <li key={i} className="text-[11px] text-amber-700">{w}</li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {d.no_action_reason && (
                        <section className="space-y-1">
                            <h3 className="text-[11px] font-semibold uppercase tracking-widest text-slate-400">
                                {tw.decision_no_action_reason ?? 'Ingen handling – begrunnelse'}
                            </h3>
                            <p className="text-[11px] text-slate-700">{d.no_action_reason}</p>
                        </section>
                    )}
                </div>

                <div className="border-t border-slate-100 px-6 py-4 text-right">
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex min-h-9 items-center justify-center rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                    >
                        {tw.decision_modal_close ?? 'Lukk'}
                    </button>
                </div>
            </div>
        </div>
    );
}

function LintHealthBar({ health, tw }) {
    if (health.total === 0) {
        return (
            <div className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fillRule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clipRule="evenodd" />
                </svg>
                {tw.lint_health_ok ?? 'Ingen åpne helsefunn'}
            </div>
        );
    }
    return (
        <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs font-semibold text-slate-500">
                {tw.lint_health_title ?? 'Wiki-helse'}:
            </span>
            {health.error > 0 && (
                <span className={`${BADGE} ${SEVERITY_STYLES.error}`}>
                    {health.error} {tw.lint_severity_error ?? 'Feil'}
                </span>
            )}
            {health.warning > 0 && (
                <span className={`${BADGE} ${SEVERITY_STYLES.warning}`}>
                    {health.warning} {tw.lint_severity_warning ?? 'Advarsel'}
                </span>
            )}
            {health.info > 0 && (
                <span className={`${BADGE} ${SEVERITY_STYLES.info}`}>
                    {health.info} {tw.lint_severity_info ?? 'Info'}
                </span>
            )}
        </div>
    );
}

// ─── Tab bar ────────────────────────────────────────────────────────────────

function TabBar({ activeTab, lintHealth, tw }) {
    const tabs = [
        { key: 'pages', label: tw.tab_pages ?? 'Wiki-sider', href: '/app/wiki?tab=pages' },
        { key: 'sources', label: tw.tab_sources ?? 'Kildedokumenter', href: '/app/wiki?tab=sources' },
        { key: 'runs', label: tw.tab_runs ?? 'Kjøringer', href: '/app/wiki?tab=runs' },
        { key: 'quality', label: tw.tab_quality ?? 'Kvalitet', href: '/app/wiki?tab=quality', badge: lintHealth.total > 0 ? lintHealth.total : null },
    ];

    return (
        <div className="flex items-center gap-1 border-b border-slate-200">
            {tabs.map((tab) => {
                const isActive = activeTab === tab.key;
                return (
                    <Link
                        key={tab.key}
                        href={tab.href}
                        className={[
                            'relative flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold transition',
                            isActive
                                ? 'text-violet-700 after:absolute after:inset-x-0 after:bottom-0 after:h-0.5 after:bg-violet-600'
                                : 'text-slate-500 hover:text-slate-800',
                        ].join(' ')}
                    >
                        {tab.label}
                        {tab.badge != null && (
                            <span className={`inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[11px] font-bold ${lintHealth.error > 0 ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700'}`}>
                                {tab.badge}
                            </span>
                        )}
                    </Link>
                );
            })}
            <Link
                href="/app/wiki/graph"
                className="ml-auto flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-slate-500 transition hover:text-slate-800"
            >
                <svg className="h-3.5 w-3.5 text-violet-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M13 4.5a2.5 2.5 0 1 1 .702 1.737L6.97 9.604a2.518 2.518 0 0 1 0 .792l6.733 3.367a2.5 2.5 0 1 1-.671 1.341l-6.733-3.367a2.5 2.5 0 1 1 0-3.474l6.733-3.367A2.5 2.5 0 0 1 13 4.5Z" />
                </svg>
                {tw.tab_graph ?? 'Grafvisning'}
            </Link>
        </div>
    );
}

// ─── Pages tab ───────────────────────────────────────────────────────────────

const SELECT_CLS = 'h-9 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 shadow-sm transition focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100';

function PagesTab({ pages, pagesMeta, pagesFilters, tw, locale }) {
    const filters = pagesFilters ?? {};
    const meta = pagesMeta ?? { current_page: 1, last_page: 1, total: 0, per_page: 25 };

    const [searchInput, setSearchInput] = useState(filters.search ?? '');

    const navigate = (overrides) => {
        router.get('/app/wiki', {
            tab: 'pages',
            search: filters.search ?? '',
            page_type: filters.page_type ?? '',
            status: filters.status ?? '',
            lint: filters.lint ?? '',
            sort: filters.sort ?? 'updated_at_desc',
            ...overrides,
        }, { preserveState: true, preserveScroll: true });
    };

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        navigate({ search: searchInput, page: 1 });
    };

    const statusLabel = (status) => ({
        approved: tw.status_approved ?? 'Godkjent',
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

    const hasActiveFilters = !!(filters.search || filters.page_type || filters.status || filters.lint);

    return (
        <div className="space-y-4">
            {/* Filter bar */}
            <div className="flex flex-wrap items-end gap-2">
                <form onSubmit={handleSearchSubmit} className="flex items-center gap-1.5">
                    <input
                        type="search"
                        value={searchInput}
                        onChange={(e) => setSearchInput(e.target.value)}
                        placeholder={tw.filter_search_placeholder ?? 'Søk...'}
                        className={SELECT_CLS + ' w-52'}
                    />
                    <button
                        type="submit"
                        className="inline-flex h-9 items-center justify-center rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-600 shadow-sm transition hover:border-slate-300 hover:text-slate-900"
                    >
                        <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fillRule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.452 4.391l3.328 3.329a.75.75 0 1 1-1.06 1.06l-3.329-3.328A7 7 0 0 1 2 9Z" clipRule="evenodd" />
                        </svg>
                    </button>
                </form>

                <select
                    value={filters.page_type ?? ''}
                    onChange={(e) => navigate({ page_type: e.target.value, page: 1 })}
                    className={SELECT_CLS}
                >
                    <option value="">{tw.filter_page_type_all ?? 'Alle typer'}</option>
                    <option value="article">{tw.page_type_article ?? 'Kildeartikkel'}</option>
                    <option value="summary">{tw.page_type_summary ?? 'Sammendrag'}</option>
                    <option value="concept">{tw.page_type_concept ?? 'Konsept'}</option>
                    <option value="entity">{tw.page_type_entity ?? 'Entitet'}</option>
                </select>

                <select
                    value={filters.status ?? ''}
                    onChange={(e) => navigate({ status: e.target.value, page: 1 })}
                    className={SELECT_CLS}
                >
                    <option value="">{tw.filter_status_all ?? 'Alle statuser'}</option>
                    <option value="approved">{tw.status_approved ?? 'Godkjent'}</option>
                    <option value="pending_review">{tw.status_pending_review ?? 'Til gjennomgang'}</option>
                    <option value="draft">{tw.status_draft ?? 'Utkast'}</option>
                    <option value="rejected">{tw.status_rejected ?? 'Avvist'}</option>
                </select>

                <select
                    value={filters.lint ?? ''}
                    onChange={(e) => navigate({ lint: e.target.value, page: 1 })}
                    className={SELECT_CLS}
                >
                    <option value="">{tw.filter_lint_all ?? 'Alle'}</option>
                    <option value="errors">{tw.filter_lint_errors ?? 'Har feil'}</option>
                    <option value="warnings">{tw.filter_lint_warnings ?? 'Har advarsler'}</option>
                    <option value="ok">{tw.filter_lint_ok ?? 'Ingen funn'}</option>
                </select>

                <select
                    value={filters.sort ?? 'updated_at_desc'}
                    onChange={(e) => navigate({ sort: e.target.value, page: 1 })}
                    className={SELECT_CLS}
                >
                    <option value="updated_at_desc">{tw.filter_sort_updated_at_desc ?? 'Nyeste oppdatering'}</option>
                    <option value="title_asc">{tw.filter_sort_title_asc ?? 'Tittel A–Å'}</option>
                    <option value="created_at_desc">{tw.filter_sort_created_at_desc ?? 'Nyeste opprettet'}</option>
                </select>

                {hasActiveFilters && (
                    <button
                        type="button"
                        onClick={() => { setSearchInput(''); navigate({ search: '', page_type: '', status: '', lint: '', sort: 'updated_at_desc', page: 1 }); }}
                        className="inline-flex h-9 items-center gap-1 rounded-lg px-3 text-sm font-medium text-slate-500 transition hover:text-slate-800"
                    >
                        <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                        </svg>
                        {tw.filter_clear ?? 'Nullstill'}
                    </button>
                )}

                {meta.total > 0 && (
                    <span className="ml-auto text-sm text-slate-400">
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
                    {/* Mobile cards */}
                    <section className="grid gap-3 md:hidden">
                        {pages.map((page) => (
                            <article
                                key={page.id}
                                className="rounded-[20px] border border-slate-200 bg-white p-5 shadow-[0_8px_22px_rgba(15,23,42,0.04)]"
                            >
                                <div className="space-y-3">
                                    <div>
                                        <div className="text-base font-semibold text-slate-950">{page.title}</div>
                                        <div className="mt-1 text-sm text-slate-400">
                                            {tw.updated ?? 'Oppdatert'} {formatDate(page.updated_at, locale)}
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusBadge status={page.status} label={statusLabel(page.status)} />
                                        {page.page_type && (
                                            <PageTypeBadge type={page.page_type} label={pageTypeLabel(page.page_type)} />
                                        )}
                                        <span className="text-xs text-slate-400">
                                            {page.claims_count} {tw.claims ?? 'påstander'}
                                        </span>
                                    </div>
                                    <Link
                                        href={`/app/wiki/${page.slug}`}
                                        className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                    >
                                        {tw.open ?? 'Åpne'}
                                    </Link>
                                </div>
                            </article>
                        ))}
                    </section>

                    {/* Desktop table */}
                    <section className="hidden overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)] md:block">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200">
                                <thead className="bg-slate-50">
                                    <tr className="text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                        <th className="px-6 py-4">Tittel</th>
                                        <th className="px-6 py-4">Type</th>
                                        <th className="px-6 py-4">Status</th>
                                        <th className="px-6 py-4">{tw.claims ?? 'Påstander'}</th>
                                        <th className="px-6 py-4">{tw.updated ?? 'Oppdatert'}</th>
                                        <th className="px-6 py-4"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {pages.map((page) => (
                                        <tr key={page.id} className="text-sm text-slate-700">
                                            <td className="px-6 py-4 font-medium text-slate-950">{page.title}</td>
                                            <td className="px-6 py-4">
                                                {page.page_type && (
                                                    <PageTypeBadge type={page.page_type} label={pageTypeLabel(page.page_type)} />
                                                )}
                                            </td>
                                            <td className="px-6 py-4">
                                                <StatusBadge status={page.status} label={statusLabel(page.status)} />
                                            </td>
                                            <td className="px-6 py-4 text-slate-500">{page.claims_count}</td>
                                            <td className="px-6 py-4 text-slate-500">{formatDate(page.updated_at, locale)}</td>
                                            <td className="px-6 py-4">
                                                <Link
                                                    href={`/app/wiki/${page.slug}`}
                                                    className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                >
                                                    {tw.open ?? 'Åpne'}
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </>
            )}

            {/* Pagination */}
            {meta.last_page > 1 && (
                <div className="flex items-center justify-between">
                    <button
                        type="button"
                        disabled={meta.current_page <= 1}
                        onClick={() => navigate({ page: meta.current_page - 1 })}
                        className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 shadow-sm transition hover:border-slate-300 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        {tw.pagination_prev ?? 'Forrige'}
                    </button>
                    <span className="text-sm text-slate-500">
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
                </div>
            )}
        </div>
    );
}

// ─── Sources tab ─────────────────────────────────────────────────────────────

function SourcesTab({ sources, sourcesFilters, sourcesStoreUrl, wikiGenerationAvailable, tw, locale }) {
    const srcFilters = sourcesFilters ?? {};
    const [srcSearchInput, setSrcSearchInput] = useState(srcFilters.search ?? '');

    const navigateSources = (overrides) => {
        router.get('/app/wiki', {
            tab: 'sources',
            src_q: srcFilters.search ?? '',
            src_status: srcFilters.status ?? '',
            ...overrides,
        }, { preserveState: true, preserveScroll: true });
    };

    const handleSrcSearchSubmit = (e) => {
        e.preventDefault();
        navigateSources({ src_q: srcSearchInput });
    };

    const fileInputRef = useRef(null);
    const uploadForm = useForm({ file: null });
    const [ingestingIds, setIngestingIds] = useState(new Set());
    const [decisionView, setDecisionView] = useState(null);

    const handleSourceReload = () => router.reload({ only: ['sources'] });

    const handleDelete = (source) => {
        if (!window.confirm(tw.source_delete_confirm ?? 'Er du sikker på at du vil slette kildedokumentet? Dette kan ikke angres.')) {
            return;
        }
        router.delete(`/app/wiki/sources/${source.id}`, { preserveScroll: true });
    };

    const submitUpload = (event) => {
        event.preventDefault();
        if (!uploadForm.data.file || uploadForm.processing) return;
        uploadForm.post(sourcesStoreUrl, {
            forceFormData: true,
            onSuccess: () => {
                uploadForm.reset();
                if (fileInputRef.current) fileInputRef.current.value = '';
            },
        });
    };

    const sourceStatusLabel = (status) => ({
        extracted: tw.source_status_extracted ?? 'Ekstrahert',
        pending: tw.source_status_pending ?? 'Behandles',
        failed: tw.source_status_failed ?? 'Feilet',
    }[status] ?? status);

    const ingestStatusLabel = (status) => ({
        queued: tw.ingest_status_queued ?? 'I kø',
        running: tw.ingest_status_running ?? 'Kjører',
        sections_planned: tw.ingest_status_running ?? 'Kjører',
        completed: tw.ingest_status_completed ?? 'Fullført',
        failed: tw.ingest_status_failed ?? 'Feilet',
        decision_only: tw.ingest_status_decision_only ?? 'Beslutning lagret',
    }[status] ?? status);

    const notStartedLabel = tw.ingest_status_not_started ?? 'Ikke startet';

    return (
        <>
            <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                <div className="space-y-5">
                    <div className="space-y-1">
                        <h2 className="text-base font-semibold text-slate-950">
                            {tw.sources_title ?? 'Kildedokumenter'}
                        </h2>
                        <p className="max-w-2xl text-sm leading-6 text-slate-500">
                            {tw.sources_description ?? 'Last opp kildedokumenter direkte til Enterprise Wiki. Dokumentet lagres og tekst ekstraheres før det kan brukes til å generere wiki-innhold.'}
                        </p>
                    </div>

                    {/* Filter bar */}
                    <div className="flex flex-wrap items-end gap-2">
                        <form onSubmit={handleSrcSearchSubmit} className="flex items-center gap-1.5">
                            <input
                                type="search"
                                value={srcSearchInput}
                                onChange={(e) => setSrcSearchInput(e.target.value)}
                                placeholder={tw.sources_search_placeholder ?? 'Søk i filnavn...'}
                                className={SELECT_CLS + ' w-52'}
                            />
                            <button
                                type="submit"
                                className="inline-flex h-9 items-center justify-center rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-600 shadow-sm transition hover:border-slate-300 hover:text-slate-900"
                            >
                                <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fillRule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.452 4.391l3.328 3.329a.75.75 0 1 1-1.06 1.06l-3.329-3.328A7 7 0 0 1 2 9Z" clipRule="evenodd" />
                                </svg>
                            </button>
                        </form>

                        <select
                            value={srcFilters.status ?? ''}
                            onChange={(e) => navigateSources({ src_status: e.target.value })}
                            className={SELECT_CLS}
                        >
                            <option value="">{tw.sources_status_all ?? 'Alle statuser'}</option>
                            <option value="extracted">{tw.source_status_extracted ?? 'Ekstrahert'}</option>
                            <option value="pending">{tw.source_status_pending ?? 'Behandles'}</option>
                            <option value="failed">{tw.source_status_failed ?? 'Feilet'}</option>
                        </select>

                        {(srcFilters.search || srcFilters.status) && (
                            <button
                                type="button"
                                onClick={() => { setSrcSearchInput(''); navigateSources({ src_q: '', src_status: '' }); }}
                                className="inline-flex h-9 items-center gap-1 rounded-lg px-3 text-sm font-medium text-slate-500 transition hover:text-slate-800"
                            >
                                <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                                </svg>
                                {tw.filter_clear ?? 'Nullstill'}
                            </button>
                        )}
                    </div>

                    {sources.length === 0 ? (
                        <p className="text-sm text-slate-400">
                            {tw.sources_list_empty ?? 'Ingen kildedokumenter lastet opp ennå.'}
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-2xl border border-slate-100">
                            <table className="min-w-full table-fixed divide-y divide-slate-100">
                                <colgroup>
                                    <col />
                                    <col style={{ width: '130px' }} />
                                    <col style={{ width: '120px' }} />
                                    <col style={{ width: '220px' }} />
                                    <col style={{ width: '56px' }} />
                                    <col style={{ width: '210px' }} />
                                </colgroup>
                                <thead className="bg-slate-50">
                                    <tr className="text-left text-[11px] font-semibold uppercase tracking-widest text-slate-400">
                                        <th className="px-4 py-3">{tw.source_col_filename ?? 'Filnavn'}</th>
                                        <th className="px-4 py-3">{tw.source_col_status ?? 'Status'}</th>
                                        <th className="px-4 py-3">{tw.source_col_uploaded ?? 'Lastet opp'}</th>
                                        <th className="px-4 py-3">{tw.ingest_col_wiki_status ?? 'Wiki-status'}</th>
                                        <th className="px-4 py-3 text-center">{tw.source_col_source ?? 'Kilde'}</th>
                                        <th className="px-4 py-3 text-right">{tw.source_col_actions ?? 'Handlinger'}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50 bg-white">
                                    {sources.map((source) => {
                                        const isInProgress = !!(source.latest_ingest_run && IN_PROGRESS_STATUSES.includes(source.latest_ingest_run.status));
                                        const canDelete = !isInProgress && source.generated_pages.length === 0;
                                        return (
                                        <tr key={source.id} className="text-sm">
                                            <td className="overflow-hidden px-4 py-3 align-top">
                                                <span className="block truncate font-medium text-slate-900" title={source.original_filename}>
                                                    {source.original_filename}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 align-top">
                                                <SourceStatusBadge
                                                    status={source.document_status}
                                                    label={sourceStatusLabel(source.document_status)}
                                                />
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 align-top text-sm text-slate-500">
                                                {formatDate(source.created_at, locale)}
                                            </td>
                                            <td className="px-4 py-3 align-top">
                                                <IngestStatusBadge
                                                    run={source.latest_ingest_run}
                                                    label={source.latest_ingest_run ? ingestStatusLabel(source.latest_ingest_run.status) : null}
                                                    notStartedLabel={notStartedLabel}
                                                    locale={locale}
                                                    onReload={handleSourceReload}
                                                    tw={tw}
                                                    onViewDecision={setDecisionView}
                                                />
                                                {source.generated_pages.length > 0 && (
                                                    <ul className="mt-2 space-y-0.5">
                                                        {source.generated_pages.map((p) => (
                                                            <li key={p.id}>
                                                                <Link
                                                                    href={`/app/wiki/${p.slug}`}
                                                                    className="block truncate text-[11px] text-violet-600 hover:text-violet-800 hover:underline"
                                                                    title={p.title}
                                                                >
                                                                    {p.title}
                                                                </Link>
                                                            </li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 align-top text-center">
                                                <a
                                                    href={`/app/wiki/sources/${source.id}/download`}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    aria-label={tw.source_open_document ?? 'Åpne kildedokument'}
                                                    title={tw.source_open_document ?? 'Åpne kildedokument'}
                                                    className="inline-flex h-7 w-7 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                                                >
                                                    <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                        <path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.614L6.295 8.235a.75.75 0 1 0-1.09 1.03l4.25 4.5a.75.75 0 0 0 1.09 0l4.25-4.5a.75.75 0 0 0-1.09-1.03l-2.955 3.129V2.75Z" />
                                                        <path d="M3.5 12.75a.75.75 0 0 0-1.5 0v2.5A2.75 2.75 0 0 0 4.75 18h10.5A2.75 2.75 0 0 0 18 15.25v-2.5a.75.75 0 0 0-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5Z" />
                                                    </svg>
                                                </a>
                                            </td>
                                            <td className="px-4 py-3 align-top">
                                                <div className="flex flex-col items-end gap-2">
                                                    <div className="flex items-center gap-3">
                                                        {source.document_status === 'extracted' && (
                                                            <button
                                                                type="button"
                                                                disabled={ingestingIds.has(source.id) || isInProgress || !wikiGenerationAvailable}
                                                                onClick={() => {
                                                                    if (ingestingIds.has(source.id)) return;
                                                                    setIngestingIds((prev) => new Set(prev).add(source.id));
                                                                    router.post(
                                                                        `/app/wiki/sources/${source.id}/ingest`,
                                                                        {},
                                                                        {
                                                                            onFinish: () =>
                                                                                setIngestingIds((prev) => {
                                                                                    const next = new Set(prev);
                                                                                    next.delete(source.id);
                                                                                    return next;
                                                                                }),
                                                                        },
                                                                    );
                                                                }}
                                                                className="inline-flex h-7 items-center justify-center rounded-full border border-violet-200 bg-violet-50 px-3 text-xs font-semibold text-violet-700 transition hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-50"
                                                            >
                                                                {ingestingIds.has(source.id)
                                                                    ? (tw.source_ingest_starting ?? 'Starter...')
                                                                    : (tw.source_ingest_button ?? 'Generer wiki-utkast')}
                                                            </button>
                                                        )}
                                                        {canDelete && (
                                                            <button
                                                                type="button"
                                                                onClick={() => handleDelete(source)}
                                                                className="inline-flex h-7 items-center gap-1 rounded-lg px-2 text-xs font-medium text-rose-500 transition hover:bg-rose-50 hover:text-rose-700"
                                                            >
                                                                <svg className="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                    <path fillRule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.519.149.022a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 3.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 7.5a.75.75 0 1 0 1.5-.06l-.3-7.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 7.5a.75.75 0 1 0 1.5.06l.3-7.5Z" clipRule="evenodd" />
                                                                </svg>
                                                                {tw.source_delete_button ?? 'Slett'}
                                                            </button>
                                                        )}
                                                    </div>
                                                    {source.document_status === 'extracted' && !wikiGenerationAvailable && (
                                                        <span className="text-right text-[11px] text-slate-400">
                                                            {tw.source_ingest_not_available ?? 'Wiki-generering er ikke aktivert ennå.'}
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <div className="border-t border-slate-100 pt-4">
                        <form onSubmit={submitUpload} className="space-y-3">
                            <div className="space-y-1.5">
                                <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                    {tw.sources_file_label ?? 'Velg fil'}
                                </span>
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept=".pdf,.docx"
                                    disabled={uploadForm.processing}
                                    onChange={(e) => uploadForm.setData('file', e.target.files?.[0] ?? null)}
                                    className="block w-full max-w-sm cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm transition file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1 file:text-xs file:font-semibold file:text-slate-700 hover:border-slate-300 disabled:cursor-not-allowed disabled:opacity-60"
                                />
                                <p className="text-xs text-slate-400">
                                    {tw.sources_file_hint ?? 'PDF eller DOCX · Maks 20 MB'}
                                </p>
                                {uploadForm.errors.file ? (
                                    <p className="text-sm text-rose-600">{uploadForm.errors.file}</p>
                                ) : null}
                            </div>

                            <button
                                type="submit"
                                disabled={!uploadForm.data.file || uploadForm.processing}
                                className="inline-flex min-h-9 items-center justify-center rounded-full bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {uploadForm.processing
                                    ? (tw.sources_uploading ?? 'Laster opp...')
                                    : (tw.sources_upload_button ?? 'Last opp kilde')}
                            </button>
                        </form>
                    </div>
                </div>
            </section>
            {decisionView && (
                <DecisionModal
                    run={decisionView}
                    tw={tw}
                    onClose={() => setDecisionView(null)}
                />
            )}
        </>
    );
}

// ─── Runs tab ────────────────────────────────────────────────────────────────

function RunsTab({ runs, tw, locale }) {
    const ingestStatusLabel = (status) => ({
        queued: tw.ingest_status_queued ?? 'I kø',
        running: tw.ingest_status_running ?? 'Kjører',
        sections_planned: tw.ingest_status_running ?? 'Kjører',
        completed: tw.ingest_status_completed ?? 'Fullført',
        failed: tw.ingest_status_failed ?? 'Feilet',
        decision_only: tw.ingest_status_decision_only ?? 'Beslutning lagret',
    }[status] ?? status);

    if (runs.length === 0) {
        return (
            <EmptyStateBox
                title={tw.runs_empty ?? 'Ingen kjøringer ennå'}
                description=""
            />
        );
    }

    return (
        <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-slate-200">
                    <thead className="bg-slate-50">
                        <tr className="text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                            <th className="px-6 py-4">{tw.runs_col_document ?? 'Dokument'}</th>
                            <th className="px-6 py-4">{tw.runs_col_status ?? 'Status'}</th>
                            <th className="px-6 py-4">{tw.runs_col_decision ?? 'Beslutning'}</th>
                            <th className="px-6 py-4">{tw.runs_col_created ?? 'Opprettet'}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {runs.map((run) => {
                            const statusCls = INGEST_STATUS_STYLES[run.status] ?? 'bg-slate-100 text-slate-600';
                            return (
                                <tr key={run.id} className="text-sm text-slate-700">
                                    <td className="px-6 py-4">
                                        <span className="block max-w-xs truncate font-medium text-slate-900" title={run.source_document_filename ?? ''}>
                                            {run.source_document_filename ?? '—'}
                                        </span>
                                        {run.status === 'failed' && run.error_message && (
                                            <p className="mt-1 line-clamp-2 text-[11px] text-rose-500" title={run.error_message}>
                                                {run.error_message}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-6 py-4">
                                        <span className={`${BADGE} ${statusCls}`}>
                                            {ingestStatusLabel(run.status)}
                                        </span>
                                    </td>
                                    <td className="px-6 py-4">
                                        {run.maintainer_decision_status === 'applied' ? (
                                            <span className={`${BADGE} bg-emerald-100 text-emerald-700`}>
                                                {tw.run_decision_applied ?? 'Anvendt'}
                                            </span>
                                        ) : run.maintainer_decision_status === 'pending' ? (
                                            <span className={`${BADGE} bg-slate-100 text-slate-500`}>
                                                {tw.run_decision_pending ?? 'Venter'}
                                            </span>
                                        ) : (
                                            <span className="text-slate-400">—</span>
                                        )}
                                    </td>
                                    <td className="whitespace-nowrap px-6 py-4 text-slate-500">
                                        {formatDate(run.created_at, locale)}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

// ─── Quality tab ─────────────────────────────────────────────────────────────

function QualityTab({ findings, lintHealth, tw, locale }) {
    return (
        <div className="space-y-5">
            <LintHealthBar health={lintHealth} tw={tw} />

            {findings.length === 0 ? (
                <EmptyStateBox
                    title={tw.quality_empty ?? 'Ingen åpne helsefunn'}
                    description=""
                />
            ) : (
                <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200">
                            <thead className="bg-slate-50">
                                <tr className="text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                    <th className="px-6 py-4">{tw.quality_col_page ?? 'Side'}</th>
                                    <th className="px-6 py-4">{tw.quality_col_severity ?? 'Alvorlighet'}</th>
                                    <th className="px-6 py-4">{tw.quality_col_type ?? 'Type'}</th>
                                    <th className="px-6 py-4">{tw.quality_col_message ?? 'Beskrivelse'}</th>
                                    <th className="px-6 py-4">{tw.quality_col_detected ?? 'Oppdaget'}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {findings.map((f) => {
                                    const sevCls = SEVERITY_STYLES[f.severity] ?? 'bg-slate-100 text-slate-600';
                                    return (
                                        <tr key={f.id} className="text-sm text-slate-700">
                                            <td className="px-6 py-4">
                                                {f.page_slug ? (
                                                    <Link
                                                        href={`/app/wiki/${f.page_slug}`}
                                                        className="font-medium text-violet-700 hover:underline"
                                                    >
                                                        {f.page_title ?? f.page_slug}
                                                    </Link>
                                                ) : (
                                                    <span className="text-slate-400">—</span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4">
                                                <span className={`${BADGE} ${sevCls}`}>{f.severity}</span>
                                            </td>
                                            <td className="px-6 py-4 font-mono text-[11px] text-slate-500">{f.code}</td>
                                            <td className="px-6 py-4 text-slate-600">{f.message}</td>
                                            <td className="whitespace-nowrap px-6 py-4 text-slate-500">
                                                {formatDate(f.created_at, locale)}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
        </div>
    );
}

// ─── Main export ─────────────────────────────────────────────────────────────

export default function WikiIndex({
    active_tab: activeTab = 'pages',
    pages = [],
    pages_meta: pagesMeta = null,
    pages_filters: pagesFilters = null,
    sources = [],
    sources_filters: sourcesFilters = null,
    runs = [],
    quality_findings: qualityFindings = [],
    sources_store_url: sourcesStoreUrl = '/app/wiki/sources',
    wiki_generation_available: wikiGenerationAvailable = false,
    lint_health: lintHealth = { error: 0, warning: 0, info: 0, total: 0 },
}) {
    const { translations = {} } = usePage().props;
    const tw = translations?.wiki ?? {};
    const locale = document.documentElement.lang || 'no';

    return (
        <CustomerAppLayout title={tw.index_title ?? 'Wiki'} showPageTitle={false}>
            <div className="space-y-6">
                <section className="space-y-1.5">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                        {tw.index_title ?? 'Wiki'}
                    </h1>
                    <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                        {tw.index_description ?? 'Strukturert kunnskap om virksomheten, generert fra godkjent innhold.'}
                    </p>
                </section>

                <TabBar activeTab={activeTab} lintHealth={lintHealth} tw={tw} />

                {activeTab === 'pages' && (
                    <PagesTab
                        pages={pages}
                        pagesMeta={pagesMeta}
                        pagesFilters={pagesFilters}
                        tw={tw}
                        locale={locale}
                    />
                )}
                {activeTab === 'sources' && (
                    <SourcesTab
                        sources={sources}
                        sourcesFilters={sourcesFilters}
                        sourcesStoreUrl={sourcesStoreUrl}
                        wikiGenerationAvailable={wikiGenerationAvailable}
                        tw={tw}
                        locale={locale}
                    />
                )}
                {activeTab === 'runs' && (
                    <RunsTab runs={runs} tw={tw} locale={locale} />
                )}
                {activeTab === 'quality' && (
                    <QualityTab findings={qualityFindings} lintHealth={lintHealth} tw={tw} locale={locale} />
                )}
            </div>
        </CustomerAppLayout>
    );
}
