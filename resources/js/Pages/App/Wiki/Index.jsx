import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Fragment, useEffect, useRef, useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import {
    getWikiQualityCheckCopy,
} from './wikiQualityChecks';

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

// Shared row-action button styles — deliberately distinct from BADGE (status chips: h-6, no
// border, no hover/focus state) so an action always reads as a clickable control, never as a
// status label. Base carries layout + a11y (focus ring, disabled state, no word-wrapping inside
// the label); variants only change color/emphasis.
const ACTION_BUTTON_BASE = 'inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-lg border px-3 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50';
const ACTION_BUTTON_PRIMARY = `${ACTION_BUTTON_BASE} border-transparent bg-violet-600 text-white shadow-sm hover:bg-violet-700 focus-visible:ring-violet-400`;
const ACTION_BUTTON_SECONDARY = `${ACTION_BUTTON_BASE} border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50 focus-visible:ring-slate-300`;
const ACTION_BUTTON_DESTRUCTIVE = `${ACTION_BUTTON_BASE} border-rose-300 bg-white text-rose-700 hover:border-rose-400 hover:bg-rose-50 focus-visible:ring-rose-400`;

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

const DOCUMENT_OWNER_SUMMARY_STYLES = {
    awaiting_sync: 'bg-slate-100 text-slate-600',
    blocked_by_quality: 'bg-amber-100 text-amber-700',
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

const INGEST_STATUS_STYLES = {
    queued: 'bg-amber-100 text-amber-700',
    running: 'bg-blue-100 text-blue-700',
    sections_planned: 'bg-blue-100 text-blue-700',
    maintainer_decision: 'bg-violet-100 text-violet-700',
    applying: 'bg-indigo-100 text-indigo-700',
    generating_pages: 'bg-sky-100 text-sky-700',
    verification_linking: 'bg-cyan-100 text-cyan-700',
    qa: 'bg-fuchsia-100 text-fuchsia-700',
    awaiting_document_owner_approval: 'bg-amber-100 text-amber-700',
    completed: 'bg-emerald-100 text-emerald-700',
    failed: 'bg-rose-100 text-rose-700',
    escalated: 'bg-amber-100 text-amber-700',
    decision_only: 'bg-violet-100 text-violet-700',
    cancelled: 'bg-slate-200 text-slate-600',
};

const SEVERITY_STYLES = {
    error: 'bg-rose-100 text-rose-700',
    warning: 'bg-amber-100 text-amber-700',
    info: 'bg-slate-100 text-slate-600',
};

const INGEST_STATUS_LABELS = {
    queued: 'I kø',
    running: 'Kjører',
    sections_planned: 'Seksjoner planlagt',
    maintainer_decision: 'Vedlikeholdersbeslutning',
    applying: 'Anvender beslutning',
    generating_pages: 'Genererer sider',
    verification_linking: 'Verifisering og lenking',
    qa: 'QA',
    awaiting_document_owner_approval: 'Avventer dokumenteiergodkjenning',
    completed: 'Fullført',
    failed: 'Feilet',
    escalated: 'Eskalert',
    decision_only: 'Beslutning lagret',
    cancelled: 'Avbrutt',
};

const IN_PROGRESS_STATUSES = [
    'queued',
    'running',
    'sections_planned',
    'maintainer_decision',
    'applying',
    'generating_pages',
    'verification_linking',
    'qa',
    'awaiting_document_owner_approval',
    'decision_only',
];

const ACTIVE_WIKI_RUN_STATUSES = [
    'running',
    'sections_planned',
    'maintainer_decision',
    'applying',
    'generating_pages',
    'generating_concept_entity_pages',
    'verification_linking',
    'qa',
    'awaiting_document_owner_approval',
];

const RUN_TIMELINE_STEPS = [
    { key: 'queued', labelKey: 'ingest_timeline_queue', fallback: 'Kø' },
    { key: 'maintainer_decision', labelKey: 'ingest_timeline_decision', fallback: 'Beslutning' },
    { key: 'applying', labelKey: 'ingest_timeline_apply', fallback: 'Anvendelse' },
    { key: 'generating_pages', labelKey: 'ingest_timeline_pages', fallback: 'Sider' },
    { key: 'verification_linking', labelKey: 'ingest_timeline_verification', fallback: 'Verifisering' },
    { key: 'qa', labelKey: 'ingest_timeline_qa', fallback: 'QA' },
    { key: 'awaiting_document_owner_approval', labelKey: 'ingest_timeline_owner_approval', fallback: 'Dokumenteier' },
];

function isActiveWikiRun(run) {
    return !!run && ACTIVE_WIKI_RUN_STATUSES.includes(run.status);
}

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

function getIngestActivityCopy(run, tw) {
    if (!run) {
        return null;
    }

    const map = {
        queued: {
            label: tw.ingest_activity_queued ?? 'Venter i kø',
            detail: tw.ingest_activity_queued ?? 'Venter i kø',
            tone: 'waiting',
        },
        running: {
            label: tw.ingest_activity_active ?? 'Arbeid pågår',
            detail: tw.ingest_activity_planning ?? 'Dokumentet struktureres',
            tone: 'active',
        },
        sections_planned: {
            label: tw.ingest_activity_active ?? 'Arbeid pågår',
            detail: tw.ingest_activity_planning ?? 'Dokumentet struktureres',
            tone: 'active',
        },
        maintainer_decision: {
            label: tw.ingest_activity_active ?? 'Arbeid pågår',
            detail: tw.ingest_activity_decision ?? 'Vedlikeholdersbeslutning behandles',
            tone: 'active',
        },
        applying: {
            label: tw.ingest_activity_active ?? 'Arbeid pågår',
            detail: tw.ingest_activity_applying ?? 'Anvender beslutning',
            tone: 'active',
        },
        generating_pages: {
            label: tw.ingest_activity_active ?? 'Arbeid pågår',
            detail: tw.ingest_activity_generating_pages ?? 'Oppretter Wiki-sider',
            waiting: tw.ingest_activity_generating_pages_waiting ?? 'Venter på at sidejobbene blir ferdige',
            tone: 'active',
        },
        generating_concept_entity_pages: {
            label: tw.ingest_activity_active ?? 'Arbeid pågår',
            detail: tw.ingest_activity_generating_pages ?? 'Oppretter Wiki-sider',
            waiting: tw.ingest_activity_generating_pages_waiting ?? 'Venter på at sidejobbene blir ferdige',
            tone: 'active',
        },
        verification_linking: {
            label: tw.ingest_activity_active ?? 'Arbeid pågår',
            detail: tw.ingest_activity_verifying ?? 'Verifiserer kilder og lenker',
            waiting: tw.ingest_activity_verifying_waiting ?? 'Venter på at kontrolljobbene blir ferdige',
            tone: 'active',
        },
        qa: {
            label: tw.ingest_activity_active ?? 'Arbeid pågår',
            detail: tw.ingest_activity_qa ?? 'Kvalitetssikrer innholdet',
            tone: 'active',
        },
        awaiting_document_owner_approval: {
            label: tw.ingest_activity_waiting_owner_approval ?? 'Avventer dokumenteier',
            detail: tw.ingest_activity_waiting_owner_approval ?? 'Venter på dokumenteiergodkjenning',
            tone: 'warning',
        },
        completed: {
            label: tw.ingest_activity_completed ?? 'Fullført',
            detail: tw.ingest_activity_completed ?? 'Fullført',
            tone: 'done',
        },
        failed: {
            label: tw.ingest_activity_failed ?? 'Feilet',
            detail: tw.ingest_activity_failed ?? 'Feilet',
            tone: 'error',
        },
        escalated: {
            label: tw.ingest_activity_escalated ?? 'Eskalert',
            detail: tw.ingest_activity_escalated ?? 'Eskalert',
            tone: 'warning',
        },
        decision_only: {
            label: tw.ingest_activity_decision_only ?? 'Beslutning lagret',
            detail: tw.ingest_activity_decision_only ?? 'Beslutning lagret',
            tone: 'decision',
        },
    };

    return map[run.status] ?? {
        label: run.status,
        detail: run.status,
        tone: 'waiting',
    };
}

function getRunTimelineState(run, stepIndex) {
    if (!run) return 'empty';
    if (run.status === 'decision_only') {
        return stepIndex === 0 ? 'done' : 'empty';
    }

    const currentIndex = RUN_TIMELINE_STEPS.findIndex((step) => step.key === run.status
        || (step.key === 'queued' && ['queued', 'running', 'sections_planned'].includes(run.status))
        || (step.key === 'generating_pages' && run.status === 'generating_concept_entity_pages'));

    if (run.status === 'completed') {
        return 'done';
    }

    if (run.status === 'failed' || run.status === 'escalated') {
        if (currentIndex === -1) {
            return stepIndex < RUN_TIMELINE_STEPS.length - 1 ? 'done' : 'error';
        }
        if (stepIndex < currentIndex) return 'done';
        if (stepIndex === currentIndex) return 'error';
        return 'empty';
    }

    if (run.status === 'awaiting_document_owner_approval') {
        return stepIndex < RUN_TIMELINE_STEPS.length - 1 ? 'done' : 'waiting';
    }

    if (currentIndex === -1) return 'empty';
    if (stepIndex < currentIndex) return 'done';
    if (stepIndex === currentIndex) {
        return run.status === 'queued' ? 'waiting' : 'active';
    }
    return 'empty';
}

function RunTimeline({ run, tw }) {
    if (!run || run.status === 'decision_only') {
        return null;
    }

    return (
        <ol className="mt-2 flex flex-wrap gap-1.5">
            {RUN_TIMELINE_STEPS.map((step, index) => {
                const state = getRunTimelineState(run, index);
                const stateCls = state === 'done'
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                    : state === 'active'
                        ? 'border-violet-200 bg-violet-50 text-violet-700'
                        : state === 'error'
                            ? 'border-rose-200 bg-rose-50 text-rose-700'
                            : 'border-slate-200 bg-slate-50 text-slate-400';
                const dotCls = state === 'done'
                    ? 'bg-emerald-500'
                    : state === 'active'
                        ? 'bg-violet-500 animate-pulse'
                        : state === 'error'
                            ? 'bg-rose-500'
                            : 'bg-slate-300';
                return (
                    <li
                        key={step.key}
                        className={`inline-flex items-center gap-1.5 rounded-full border px-2 py-1 text-[11px] font-semibold ${stateCls}`}
                    >
                        <span className={`h-2 w-2 rounded-full ${dotCls}`} aria-hidden="true" />
                        {tw[step.labelKey] ?? step.fallback}
                    </li>
                );
            })}
        </ol>
    );
}

function RunActivityBlock({ run, tw, locale, showCounters = false, showTimeline = false }) {
    if (!run) return null;

    const activity = getIngestActivityCopy(run, tw);
    const isActive = isActiveWikiRun(run);
    const progressAt = run.last_progress_at ?? run.updated_at ?? run.started_at ?? run.created_at;
    const progressLabel = formatRelativeProgress(progressAt, locale);
    const lastProgressLabel = progressLabel
        ? `${tw.ingest_activity_last_progress ?? 'Siste fremdrift'} ${progressLabel}`
        : null;
    const staleMinutes = progressAt ? Math.max(0, Math.round((Date.now() - new Date(progressAt).getTime()) / 60000)) : null;
    const seemsStalled = isActive && staleMinutes !== null && staleMinutes >= 15;
    const counters = [];

    if (showCounters) {
        if ((run.pages_count ?? 0) > 0) {
            counters.push(`${run.pages_count} ${tw.runs_col_pages ?? 'Sider'}`);
        }
        if ((run.sections_count ?? 0) > 0) {
            counters.push(`${run.sections_count} ${tw.runs_col_sections ?? 'Seksjoner'}`);
        }
        if ((run.lint_count ?? 0) > 0) {
            counters.push(`${run.lint_count} ${tw.runs_col_lint ?? 'Funn'}`);
        }
    }

    return (
        <div className="mt-2 space-y-1.5" aria-live={isActive ? 'polite' : 'off'}>
            <div className="flex flex-wrap items-center gap-2">
                {activity?.tone === 'active' ? (
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-violet-50 px-2.5 py-1 text-[11px] font-semibold text-violet-700">
                        <span className="h-2 w-2 rounded-full bg-violet-500 animate-pulse" aria-hidden="true" />
                        {activity.label}
                    </span>
                ) : (
                    <span
                        className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ${
                            activity?.tone === 'done'
                                ? 'bg-emerald-50 text-emerald-700'
                                : activity?.tone === 'error'
                                    ? 'bg-rose-50 text-rose-700'
                                    : activity?.tone === 'warning'
                                        ? 'bg-amber-50 text-amber-700'
                                        : activity?.tone === 'decision'
                                            ? 'bg-violet-50 text-violet-700'
                                            : 'bg-slate-100 text-slate-500'
                        }`}
                    >
                        {activity?.label ?? run.status}
                    </span>
                )}
                {seemsStalled && (
                    <span className="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-semibold text-amber-700">
                        {tw.ingest_activity_stalled ?? 'Ser ut til å stå stille'}
                    </span>
                )}
            </div>

            <p className="text-[11px] leading-4 text-slate-500">
                {activity?.detail ?? run.status}
                {activity?.waiting ? ` · ${activity.waiting}` : ''}
            </p>

            {seemsStalled && (
                <p className="text-[11px] leading-4 text-amber-700">
                    {(tw.ingest_activity_stalled_explanation ?? 'Ingen registrert fremdrift siden :time. Kjøringen kan vente på en handling og er ikke nødvendigvis feilet.')
                        .replace(':time', `${formatDate(progressAt, locale)} ${formatTime(progressAt, locale) ?? ''}`.trim())}
                </p>
            )}

            {showCounters && counters.length > 0 && (
                <p className="text-[11px] leading-4 text-slate-400">
                    {counters.join(' · ')}
                </p>
            )}

            {lastProgressLabel && (
                <p className="text-[11px] leading-4 text-slate-400">
                    {lastProgressLabel}
                </p>
            )}

            {showTimeline && (
                <RunTimeline run={run} tw={tw} />
            )}
        </div>
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
                    title: tw.page_help_item_status_approved_title ?? 'Godkjent',
                    text: tw.page_help_item_status_approved_text ?? 'Siden har bestått den eksisterende artikkel- eller kvalitetsgodkjenningen.',
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
                {
                    title: tw.sources_page_help_item_status_owner_title ?? 'Avventer Dokumenteier',
                    text: tw.sources_page_help_item_status_owner_text ?? 'Dokumentet mangler en gyldig Dokumenteier, eller eierstatusen er ennå ikke synkronisert til Wiki-sidene som bruker dokumentet.',
                },
                {
                    title: tw.sources_page_help_item_status_approval_title ?? 'Avventer godkjenning',
                    text: tw.sources_page_help_item_status_approval_text ?? 'Wiki-materialet er under arbeid og venter på nødvendige godkjenninger før det kan tas i bruk.',
                },
            ],
        },
        {
            title: tw.sources_page_help_section_changes ?? 'Når dokumentet endrer seg',
            items: [
                {
                    title: tw.sources_page_help_item_changes_owner_title ?? 'Ny eier gir nye oppgaver',
                    text: tw.sources_page_help_item_changes_owner_text ?? 'Når Dokumenteier endres, oppdateres hvem som skal godkjenne de Wiki-sidene som faktisk bruker dokumentet. Historikken beholdes.',
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
                    text: tw.runs_page_help_item_when_start_text ?? 'En kjøring kan opprettes når brukeren velger Lag Wiki-utkast for et kildedokument, eller når eksisterende Wiki-materiale må behandles på nytt eller gjenopprettes gjennom eksisterende UI.',
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
                    text: tw.runs_page_help_item_progress_status_text ?? 'Status viser den overordnede tilstanden til kjøringen, for eksempel Venter, Behandles, Genererer Wiki-sider, Avventer QA, Avventer godkjenning fra Dokumenteier, Fullført eller Feilet.',
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
                    title: tw.runs_page_help_item_status_running_title ?? 'Behandles',
                    text: tw.runs_page_help_item_status_running_text ?? 'Procynia arbeider aktivt med kjøringen.',
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
                    title: tw.runs_page_help_item_status_owner_title ?? 'Avventer godkjenning fra Dokumenteier',
                    text: tw.runs_page_help_item_status_owner_text ?? 'Kjøringen har kommet langt, men én eller flere Dokumenteiere må fortsatt godkjenne materialet. Dette er ikke en teknisk feil, men en menneskelig beslutning som gjenstår.',
                },
                {
                    title: tw.runs_page_help_item_status_rejected_title ?? 'Avvist av Dokumenteier',
                    text: tw.runs_page_help_item_status_rejected_text ?? 'Minst én nødvendig Dokumenteier har avvist materialet. Kjøringen kan ikke fullføres før avviket er behandlet.',
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
                    title: tw.runs_page_help_item_status_escalated_title ?? 'Eskalert',
                    text: tw.runs_page_help_item_status_escalated_text ?? 'Noe krevde ekstra oppfølging, og kjøringen trenger videre håndtering før den kan regnes som avsluttet.',
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

function getWikiQualityHelpSections(tw) {
    return [
        {
            title: tw.quality_page_help_section_worklist ?? 'Kvalitet som arbeidsliste',
            items: [
                {
                    title: tw.quality_page_help_item_worklist_title ?? 'Hver rad peker til et konkret sted',
                    text: tw.quality_page_help_item_worklist_text ?? 'Kvalitetsfunn er laget for oppfølging. Velg en rad for å åpne Wiki-siden eller påstanden funnet gjelder.',
                },
                {
                    title: tw.quality_page_help_item_worklist_scope_title ?? 'Noen funn peker på en hel side',
                    text: tw.quality_page_help_item_worklist_scope_text ?? 'Sidebaserte funn åpner den berørte Wiki-siden slik at du kan kontrollere og rette innholdet på riktig sted.',
                },
            ],
        },
        {
            title: tw.quality_page_help_section_claims ?? 'Når funnet gjelder en påstand',
            items: [
                {
                    title: tw.quality_page_help_item_claim_focus_title ?? 'Så nær den konkrete påstanden som mulig',
                    text: tw.quality_page_help_item_claim_focus_text ?? 'Funn som gjelder en påstand, åpner den aktuelle Wiki-siden og fokuserer påstanden når det er mulig. Hvis presist påstandsfokus mangler, åpnes siden likevel på trygg måte.',
                },
                {
                    title: tw.quality_page_help_item_claim_focus_scope_title ?? 'Kilden hjelper deg videre',
                    text: tw.quality_page_help_item_claim_focus_scope_text ?? 'Kildefilen og kjøringen kan fortsatt åpnes fra tabellen, men de skal ikke overstyre radens hovedmål.',
                },
            ],
        },
        {
            title: tw.quality_page_help_section_meaning ?? 'Hvordan lese alvorligheten',
            items: [
                {
                    title: tw.quality_page_help_item_meaning_title ?? 'Alvorlighet beskriver funnet',
                    text: tw.quality_page_help_item_meaning_text ?? 'Alvorligheten sier hvor viktig funnet er, men den retter ikke problemet automatisk. Du må fortsatt åpne den berørte siden eller påstanden og gjøre den faktiske kontrollen der.',
                },
            ],
        },
    ];
}

function ingestStatusLabel(status, qaStatus = null) {
    if (status === 'completed' && qaStatus === 'passed') {
        return 'Fullført / bestått';
    }

    if (status === 'escalated' || qaStatus === 'escalated') {
        return 'Eskalert';
    }

    if (status === 'failed' && qaStatus === 'failed') {
        return 'Feilet';
    }

    return INGEST_STATUS_LABELS[status] ?? status;
}

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
    const errorMessage = run.qa_last_error ?? run.error_message;
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
                    {queuedSince ? `I kø siden ${queuedSince} · ` : ''}
                    {tw.ingest_activity_queued_note ?? 'Behandlingen er ikke startet ennå.'}
                </p>
            )}
            <RunActivityBlock run={run} tw={tw} locale={locale} showCounters />
            {run.status === 'failed' && errorMessage && (
                <p
                    className="line-clamp-2 wrap-break-word text-[11px] leading-4 text-rose-500"
                    title={errorMessage}
                >
                    {errorMessage}
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

// ─── Coverage panel ──────────────────────────────────────────────────────────

function CoverageStat({ label, value, warn = false, error = false, numCls: numClsOverride }) {
    const numCls = numClsOverride ?? (
        (error && value > 0) ? 'text-rose-600' :
        (warn && value > 0) ? 'text-amber-600' :
        'text-slate-900'
    );
    return (
        <div className="flex flex-col gap-0.5">
            <span className={`text-xl font-semibold tabular-nums ${numCls}`}>{value ?? '—'}</span>
            <span className="text-[11px] text-slate-500">{label}</span>
        </div>
    );
}

function CoverageSection({ title, children }) {
    return (
        <div className="space-y-3">
            <h3 className="text-[11px] font-semibold uppercase tracking-widest text-slate-400">{title}</h3>
            <div className="flex flex-wrap gap-x-8 gap-y-3">{children}</div>
        </div>
    );
}

function CoveragePanel({ coverage, tw }) {
    if (!coverage) return null;

    const sc = coverage.source_coverage ?? {};
    const pq = coverage.page_quality ?? {};
    const cc = coverage.claim_coverage ?? {};
    const lint = coverage.lint ?? {};
    const gaps = sc.gaps ?? [];

    const gapLabelMap = {
        applied_run:                      tw.coverage_docs_with_run             ?? 'applied kjøring',
        article_missing:                  tw.coverage_gap_article_missing        ?? 'Artikkel-side mangler',
        article_missing_current_version:  tw.coverage_gap_article_no_version    ?? 'Artikkel: mangler gjeldende versjon',
        article_missing_content:          tw.coverage_gap_article_no_content    ?? 'Artikkel: mangler innhold',
        summary_missing:                  tw.coverage_gap_summary_missing        ?? 'Sammendrag-side mangler',
        summary_missing_current_version:  tw.coverage_gap_summary_no_version    ?? 'Sammendrag: mangler gjeldende versjon',
        summary_missing_content:          tw.coverage_gap_summary_no_content    ?? 'Sammendrag: mangler innhold',
    };

    return (
        <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
            <div className="border-b border-slate-100 px-5 py-4">
                <h2 className="text-sm font-semibold text-slate-800">
                    {tw.coverage_title ?? 'Dekning'}
                </h2>
            </div>

            <div className="divide-y divide-slate-100">
                {/* Source coverage */}
                <div className="px-5 py-4">
                    <CoverageSection title={tw.coverage_section_sources ?? 'Kildedekning'}>
                        <CoverageStat label={tw.coverage_extracted_docs ?? 'Extracted dokumenter'} value={sc.extracted_documents ?? 0} />
                        <CoverageStat label={tw.coverage_docs_with_run ?? 'Med applied kjøring'} value={sc.documents_with_applied_run ?? 0} />
                        <CoverageStat label={tw.coverage_docs_with_article ?? 'Artikkel-side opprettet'} value={sc.documents_with_article ?? 0} />
                        <CoverageStat label={tw.coverage_docs_with_summary ?? 'Sammendrag-side opprettet'} value={sc.documents_with_summary ?? 0} />
                        <CoverageStat label={tw.coverage_docs_article_content ?? 'Artikkel med gjeldende innhold'} value={sc.documents_with_article_content ?? 0} />
                        <CoverageStat label={tw.coverage_docs_summary_content ?? 'Sammendrag med gjeldende innhold'} value={sc.documents_with_summary_content ?? 0} />
                    </CoverageSection>
                </div>

                {/* Page quality */}
                <div className="px-5 py-4">
                    <CoverageSection title={tw.coverage_section_pages ?? 'Sidekvalitet'}>
                        <CoverageStat label={tw.coverage_pages_total ?? 'Totalt sider'} value={pq.total ?? 0} />
                        <CoverageStat label={tw.coverage_pages_no_version ?? 'Uten gjeldende versjon'} value={pq.without_current_version ?? 0} warn />
                        <CoverageStat label={tw.coverage_pages_no_content ?? 'Uten innhold'} value={pq.without_content ?? 0} warn />
                        <CoverageStat label={tw.coverage_pages_no_claims ?? 'Uten claims'} value={pq.without_claims ?? 0} warn />
                    </CoverageSection>
                </div>

                {/* Claim coverage + graph */}
                <div className="flex flex-wrap divide-x divide-slate-100">
                    <div className="px-5 py-4">
                        <CoverageSection title={tw.coverage_section_claims ?? 'Claim-dekning'}>
                            <CoverageStat
                                label={tw.coverage_claim_pct ?? 'Dekningsgrad'}
                                value={cc.claim_coverage_pct != null ? `${cc.claim_coverage_pct}%` : '—'}
                                numCls={
                                    cc.claim_coverage_pct == null ? 'text-slate-900' :
                                    cc.claim_coverage_pct >= 80 ? 'text-emerald-600' :
                                    cc.claim_coverage_pct >= 50 ? 'text-amber-600' :
                                    'text-rose-600'
                                }
                            />
                            <CoverageStat label="Med kildereferanse" value={cc.claims_with_source_reference ?? 0} />
                            <CoverageStat label="Uten kildereferanse" value={cc.claims_without_source_reference ?? 0} warn />
                        </CoverageSection>
                    </div>
                    <div className="px-5 py-4">
                        <CoverageSection title={tw.coverage_section_graph ?? 'Graf og struktur'}>
                            <CoverageStat label={tw.lint_severity_error ?? 'Feil'} value={lint.open_errors ?? 0} error />
                            <CoverageStat label={tw.lint_severity_warning ?? 'Advarsler'} value={lint.open_warnings ?? 0} warn />
                            <CoverageStat label={tw.coverage_orphan_pages ?? 'Foreldreløse sider'} value={lint.orphan_pages ?? 0} warn />
                        </CoverageSection>
                    </div>
                </div>

                {/* Gaps */}
                <div className="px-5 py-4">
                    <h3 className="mb-3 text-[11px] font-semibold uppercase tracking-widest text-slate-400">
                        {tw.coverage_gaps_title ?? 'Dekning-gap'}
                    </h3>
                    {gaps.length === 0 ? (
                        <p className="text-sm text-slate-500">{tw.coverage_no_gaps ?? 'Ingen dekning-gap'}</p>
                    ) : (
                        <ul className="space-y-1">
                            {gaps.map((gap) => (
                                <li key={gap.document_id} className="flex items-baseline gap-2 text-sm">
                                    <span className="max-w-[240px] truncate font-medium text-slate-700" title={gap.filename}>
                                        {gap.filename}
                                    </span>
                                    <span className="text-slate-400">—</span>
                                    <span className="text-amber-600">
                                        {tw.coverage_gap_missing ?? 'Mangler'}:{' '}
                                        {gap.missing.map((m) => gapLabelMap[m] ?? m).join(', ')}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </section>
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
                                        <DocumentOwnerSummaryBadge
                                            summary={page.document_owner_summary}
                                            fallbackLabel={tw.document_owner_sync_pending ?? 'Avventer synkronisering'}
                                        />
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
                                        <th className="px-6 py-4">{tw.document_owner_column ?? 'Dokumenteier'}</th>
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
                                            <td className="px-6 py-4">
                                                <DocumentOwnerSummaryBadge
                                                    summary={page.document_owner_summary}
                                                    fallbackLabel={tw.document_owner_sync_pending ?? 'Avventer synkronisering'}
                                                />
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

function SourcesTab({
    sources,
    sourcesFilters,
    sourcesStoreUrl,
    documentOwnerOptions = [],
    wikiGenerationAvailable,
    tw,
    locale,
}) {
    const srcFilters = sourcesFilters ?? {};
    const [srcSearchInput, setSrcSearchInput] = useState(srcFilters.search ?? '');
    const { auth = {} } = usePage().props;
    const currentUser = auth.user ?? {};
    const canAssignDocumentOwner = Boolean(currentUser.can_assign_enterprise_wiki_document_owner);
    const ownerOptions = documentOwnerOptions ?? [];

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
    const uploadForm = useForm({
        file: null,
        owner_user_id: String(currentUser.id ?? ''),
    });
    const [ingestingIds, setIngestingIds] = useState(new Set());
    const [decisionView, setDecisionView] = useState(null);
    const [ownerDrafts, setOwnerDrafts] = useState({});
    const [savingOwnerIds, setSavingOwnerIds] = useState(new Set());

    const handleSourceReload = () => router.reload({ only: ['sources'] });

    const handleOwnerChange = (sourceId, value) => {
        setOwnerDrafts((prev) => ({
            ...prev,
            [sourceId]: value,
        }));
    };

    const handleOwnerSave = (source) => {
        if (!source || !canAssignDocumentOwner) return;

        const currentValue = ownerDrafts[source.id] ?? String(source.owner_user_id ?? '');
        const normalized = String(currentValue ?? '').trim();

        if (normalized === String(source.owner_user_id ?? '')) {
            return;
        }

        setSavingOwnerIds((prev) => new Set(prev).add(source.id));

        router.patch(
            `/app/wiki/sources/${source.id}/owner`,
            { owner_user_id: normalized === '' ? null : Number(normalized) },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSavingOwnerIds((prev) => {
                        const next = new Set(prev);
                        next.delete(source.id);
                        return next;
                    });
                },
            },
        );
    };

    const [deletePreview, setDeletePreview] = useState(null);

    const handleDeleteClick = (source) => {
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
        router.delete(`/app/wiki/sources/${sourceId}`, { preserveScroll: true });
    };

    const handleDeleteCancel = () => setDeletePreview(null);

    const submitUpload = (event) => {
        event.preventDefault();
        if (!uploadForm.data.file || uploadForm.processing) return;
        uploadForm.post(sourcesStoreUrl, {
            forceFormData: true,
            onSuccess: () => {
                uploadForm.reset();
                uploadForm.setData('owner_user_id', String(currentUser.id ?? ''));
                if (fileInputRef.current) fileInputRef.current.value = '';
            },
        });
    };

    const sourceStatusLabel = (status) => ({
        extracted: tw.source_status_extracted ?? 'Ekstrahert',
        pending: tw.source_status_pending ?? 'Behandles',
        failed: tw.source_status_failed ?? 'Feilet',
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
                                    <col style={{ width: '110px' }} />
                                    <col style={{ width: '110px' }} />
                                    <col style={{ width: '220px' }} />
                                    <col style={{ width: '200px' }} />
                                    <col style={{ width: '420px' }} />
                                </colgroup>
                                <thead className="bg-slate-50">
                                    <tr className="text-left text-[11px] font-semibold uppercase tracking-widest text-slate-400">
                                        <th className="px-4 py-3">{tw.source_col_filename ?? 'Filnavn'}</th>
                                        <th className="px-4 py-3">{tw.source_col_status ?? 'Status'}</th>
                                        <th className="px-4 py-3">{tw.source_col_uploaded ?? 'Lastet opp'}</th>
                                        <th className="px-4 py-3">{tw.ingest_col_wiki_status ?? 'Wiki-status'}</th>
                                        <th className="px-4 py-3">{tw.source_col_owner ?? 'Dokumenteier'}</th>
                                        <th className="px-4 py-3">{tw.source_col_actions ?? 'Handlinger'}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50 bg-white">
                                    {sources.map((source) => {
                                        const isInProgress = !!(source.latest_ingest_run && IN_PROGRESS_STATUSES.includes(source.latest_ingest_run.status));
                                        // Frontend only reflects authorization/state for UX — the backend
                                        // (deletePreview/destroy) independently re-checks both, since the
                                        // frontend is never the security boundary. Authorized users still see
                                        // the button while a run is active, but it is disabled with a clear
                                        // explanation rather than silently disappearing.
                                        const canDelete = !!source.can_delete;
                                        const sourceOwnerLabel = source.owner_name ?? (tw.document_owner_missing ?? 'Mangler Dokumenteier');
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
                                                    label={source.latest_ingest_run ? ingestStatusLabel(source.latest_ingest_run.status, source.latest_ingest_run.qa_status) : null}
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
                                            <td className="px-4 py-3 align-top">
                                                {canAssignDocumentOwner ? (
                                                    <div className="space-y-2">
                                                        <select
                                                            value={ownerDrafts[source.id] ?? String(source.owner_user_id ?? '')}
                                                            onChange={(event) => handleOwnerChange(source.id, event.target.value)}
                                                            className={`${SELECT_CLS} w-full`}
                                                        >
                                                            <option value="">{tw.document_owner_missing ?? 'Mangler Dokumenteier'}</option>
                                                            {ownerOptions.map((option) => (
                                                                <option key={option.id} value={option.id}>
                                                                    {option.label}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        <button
                                                            type="button"
                                                            disabled={savingOwnerIds.has(source.id)}
                                                            onClick={() => handleOwnerSave(source)}
                                                            className="inline-flex h-7 items-center rounded-full border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            {savingOwnerIds.has(source.id)
                                                                ? (tw.document_owner_saving ?? 'Lagrer eier...')
                                                                : (tw.document_owner_save ?? 'Lagre eier')}
                                                        </button>
                                                    </div>
                                                ) : (
                                                    <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${source.owner_name ? 'bg-slate-100 text-slate-700' : 'bg-amber-50 text-amber-700'}`}>
                                                        {sourceOwnerLabel}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 align-top">
                                                <div className="flex flex-col items-start gap-2">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <a
                                                            href={`/app/wiki/sources/${source.id}/download`}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className={ACTION_BUTTON_SECONDARY}
                                                        >
                                                            <svg className="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                <path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.614L6.295 8.235a.75.75 0 1 0-1.09 1.03l4.25 4.5a.75.75 0 0 0 1.09 0l4.25-4.5a.75.75 0 0 0-1.09-1.03l-2.955 3.129V2.75Z" />
                                                                <path d="M3.5 12.75a.75.75 0 0 0-1.5 0v2.5A2.75 2.75 0 0 0 4.75 18h10.5A2.75 2.75 0 0 0 18 15.25v-2.5a.75.75 0 0 0-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5Z" />
                                                            </svg>
                                                            {tw.source_download_button ?? 'Last ned'}
                                                        </a>
                                                        {source.document_status === 'extracted' && (
                                                            <button
                                                                type="button"
                                                                disabled={ingestingIds.has(source.id) || isInProgress || !wikiGenerationAvailable}
                                                                title={
                                                                    !wikiGenerationAvailable
                                                                        ? (tw.source_ingest_not_available ?? 'Wiki-generering er ikke aktivert ennå.')
                                                                        : (isInProgress ? (tw.document_has_active_run ?? 'Dokumentet har en aktiv kjøring') : undefined)
                                                                }
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
                                                                className={ACTION_BUTTON_PRIMARY}
                                                            >
                                                                <svg className="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-11.25a.75.75 0 00-1.5 0v2.5h-2.5a.75.75 0 000 1.5h2.5v2.5a.75.75 0 001.5 0v-2.5h2.5a.75.75 0 000-1.5h-2.5v-2.5z" clipRule="evenodd" />
                                                                </svg>
                                                                {ingestingIds.has(source.id)
                                                                    ? (tw.source_ingest_starting ?? 'Starter...')
                                                                    : (tw.source_ingest_button ?? 'Lag Wiki-utkast')}
                                                            </button>
                                                        )}
                                                        {canDelete && (
                                                            <button
                                                                type="button"
                                                                disabled={isInProgress}
                                                                title={isInProgress ? (tw.document_has_active_run ?? 'Dokumentet har en aktiv kjøring') : undefined}
                                                                onClick={() => handleDeleteClick(source)}
                                                                className={ACTION_BUTTON_DESTRUCTIVE}
                                                            >
                                                                <svg className="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                    <path fillRule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.519.149.022a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 3.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 7.5a.75.75 0 1 0 1.5-.06l-.3-7.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 7.5a.75.75 0 1 0 1.5.06l.3-7.5Z" clipRule="evenodd" />
                                                                </svg>
                                                                {tw.source_delete_button ?? 'Slett'}
                                                            </button>
                                                        )}
                                                    </div>
                                                    <Link
                                                        href={`/app/wiki?tab=runs&run_src=${source.id}`}
                                                        className="text-[11px] text-slate-400 hover:text-violet-600 hover:underline"
                                                    >
                                                        {tw.runs_view_runs ?? 'Kjøringer'}
                                                    </Link>
                                                    {source.document_status === 'extracted' && !wikiGenerationAvailable && (
                                                        <span className="text-[11px] text-slate-400">
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

                            {canAssignDocumentOwner ? (
                                <div className="space-y-1.5">
                                    <label className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500" htmlFor="wiki-source-owner">
                                        {tw.document_owner_label ?? 'Dokumenteier'}
                                    </label>
                                    <select
                                        id="wiki-source-owner"
                                        value={uploadForm.data.owner_user_id}
                                        onChange={(event) => uploadForm.setData('owner_user_id', event.target.value)}
                                        className={`${SELECT_CLS} w-full max-w-sm`}
                                    >
                                        <option value="">{tw.document_owner_choose ?? 'Velg dokumenteier'}</option>
                                        {ownerOptions.map((option) => (
                                            <option key={option.id} value={option.id}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                    {uploadForm.errors.owner_user_id ? (
                                        <p className="text-sm text-rose-600">{uploadForm.errors.owner_user_id}</p>
                                    ) : null}
                                </div>
                            ) : (
                                <input type="hidden" name="owner_user_id" value={uploadForm.data.owner_user_id} />
                            )}

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
            {deletePreview && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" role="dialog" aria-modal="true">
                    <div className="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
                        <h2 className="mb-4 text-base font-semibold text-slate-900">
                            {tw.delete_preview_title ?? 'Bekreft sletting av kildedokument'}
                        </h2>

                        {deletePreview.loading && (
                            <p className="text-sm text-slate-500">{tw.delete_preview_loading ?? 'Laster forhåndsvisning...'}</p>
                        )}

                        {!deletePreview.loading && deletePreview.error && (
                            <p className="text-sm text-rose-600">Kunne ikke laste forhåndsvisning. Prøv igjen.</p>
                        )}

                        {!deletePreview.loading && !deletePreview.error && deletePreview.blocked && (
                            <p className="text-sm text-rose-600">
                                {tw.delete_preview_blocked_in_progress ?? 'Dokumentet kan ikke slettes mens en Wiki-kjøring pågår. Vent til kjøringen er ferdig eller stopp den først.'}
                            </p>
                        )}

                        {!deletePreview.loading && !deletePreview.error && !deletePreview.blocked && deletePreview.data && (
                            <div className="space-y-3">
                                <p className="text-sm font-medium text-slate-800 break-all">
                                    {deletePreview.data.document_name}
                                </p>
                                <p className="text-sm text-slate-600">
                                    {tw.delete_preview_intro ?? 'Dette sletter kildedokumentet og Enterprise Wiki-innhold som kun er generert fra dette dokumentet. Handlingen kan ikke angres.'}
                                </p>
                                <dl className="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3 text-sm space-y-1.5">
                                    <div className="flex justify-between">
                                        <dt className="text-slate-500">{tw.document_owner_label ?? 'Dokumenteier'}</dt>
                                        <dd className="font-semibold text-slate-800">
                                            {deletePreview.data.document_owner_name ?? (tw.document_owner_missing ?? 'Mangler Dokumenteier')}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-slate-500">{tw.delete_preview_runs ?? 'Kjøringer som slettes'}</dt>
                                        <dd className="font-semibold text-slate-800">{deletePreview.data.run_count}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-slate-500">{tw.delete_preview_sole_source_pages ?? 'Sider som bare tilhører dokumentet'}</dt>
                                        <dd className="font-semibold text-rose-600">{deletePreview.data.sole_source_page_count}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-slate-500">{tw.delete_preview_page_versions ?? 'Sideversjoner som slettes'}</dt>
                                        <dd className="font-semibold text-slate-800">{deletePreview.data.page_version_count}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-slate-500">{tw.delete_preview_claims ?? 'Claims som slettes'}</dt>
                                        <dd className="font-semibold text-slate-800">{deletePreview.data.claim_count}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-slate-500">{tw.delete_preview_findings ?? 'Funn som slettes'}</dt>
                                        <dd className="font-semibold text-slate-800">{deletePreview.data.finding_count}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-slate-500">{tw.delete_preview_shared_pages ?? 'Delte sider som beholdes'}</dt>
                                        <dd className="font-semibold text-emerald-600">{deletePreview.data.shared_page_count}</dd>
                                    </div>
                                </dl>
                                {deletePreview.data.sole_source_page_count === 0 && (
                                    <p className="text-xs text-slate-400">{tw.delete_preview_no_pages ?? 'Ingen wiki-sider vil bli slettet.'}</p>
                                )}
                                {deletePreview.data.shared_page_count > 0 && (
                                    <p className="text-xs text-emerald-600">
                                        {(tw.delete_preview_shared_pages_note ?? '{count} sider beholdes fordi de også bygger på andre kildedokumenter.')
                                            .replace('{count}', deletePreview.data.shared_page_count)}
                                    </p>
                                )}
                                {!deletePreview.data.storage_file_exists && (
                                    <p className="text-xs text-amber-600">
                                        {tw.delete_preview_storage_missing ?? 'Dokumentfilen finnes ikke lenger i lagring.'}
                                    </p>
                                )}
                                <p className="text-xs font-semibold text-rose-600">
                                    {tw.delete_preview_irreversible ?? 'Handlingen kan ikke angres.'}
                                </p>
                            </div>
                        )}

                        <div className="mt-5 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={handleDeleteCancel}
                                className="inline-flex h-9 items-center rounded-full border border-slate-200 px-4 text-sm font-semibold text-slate-600 transition hover:border-slate-300 hover:text-slate-900"
                            >
                                {tw.delete_cancel_button ?? 'Avbryt'}
                            </button>
                            {!deletePreview.loading && !deletePreview.error && !deletePreview.blocked && (
                                <button
                                    type="button"
                                    onClick={handleDeleteConfirm}
                                    className="inline-flex h-9 items-center rounded-full bg-rose-600 px-4 text-sm font-semibold text-white transition hover:bg-rose-700"
                                >
                                    {tw.delete_confirm_button ?? 'Slett dokument og Wiki-innhold'}
                                </button>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

// ─── Runs tab ────────────────────────────────────────────────────────────────

function findingsCountToneClass(run) {
    if ((run.findings_open_blocking_count ?? 0) > 0) {
        return 'text-rose-600 hover:bg-rose-50 focus-visible:ring-rose-400';
    }

    if ((run.findings_open_non_blocking_count ?? 0) > 0) {
        return 'text-amber-600 hover:bg-amber-50 focus-visible:ring-amber-400';
    }

    return 'text-slate-500 hover:bg-slate-100 focus-visible:ring-slate-400';
}

function RunsTab({ runs, runsFilters, tw, locale }) {
    const filters = runsFilters ?? {};
    // expandedRuns[runId] is null (closed) | 'pages' | 'findings' — only one panel is ever shown
    // per row, so switching panels never stacks two overlapping detail areas under the same row.
    const [expandedRuns, setExpandedRuns] = useState({});
    const [runPagesState, setRunPagesState] = useState({});
    const [runFindingsState, setRunFindingsState] = useState({});
    const [cancelTarget, setCancelTarget] = useState(null);

    const handleCancelClick = (run) => setCancelTarget(run);
    const handleCancelDismiss = () => setCancelTarget(null);
    const handleCancelConfirm = () => {
        if (!cancelTarget) return;
        const runId = cancelTarget.id;
        setCancelTarget(null);
        router.patch(`/app/wiki/runs/${runId}/cancel`, {}, { preserveScroll: true });
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

    const fetchRunFindings = (runId) => {
        setRunFindingsState((current) => ({ ...current, [runId]: { status: 'loading', data: null } }));

        fetch(`/app/wiki/runs/${runId}/findings`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((res) => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return res.json();
            })
            .then((data) => setRunFindingsState((current) => ({ ...current, [runId]: { status: 'ready', data } })))
            .catch(() => setRunFindingsState((current) => ({ ...current, [runId]: { status: 'error', data: null } })));
    };

    const togglePanel = (run, panel, hasData) => {
        if (!hasData) return;

        const currentPanel = expandedRuns[run.id] ?? null;
        const nextPanel = currentPanel === panel ? null : panel;
        setExpandedRuns((current) => ({ ...current, [run.id]: nextPanel }));

        if (nextPanel === 'pages' && !runPagesState[run.id]) {
            fetchRunPages(run.id);
        }

        if (nextPanel === 'findings' && !runFindingsState[run.id]) {
            fetchRunFindings(run.id);
        }
    };

    return (
        <div className="space-y-4">
            {/* Filter bar */}
            <div className="flex flex-wrap items-center gap-2">
                <select
                    value={filters.status ?? ''}
                    onChange={(e) => navigate({ run_status: e.target.value })}
                    className={SELECT_CLS}
                >
                    <option value="">{tw.runs_filter_status_all ?? 'Alle statuser'}</option>
                    {[
                        'queued',
                        'maintainer_decision',
                        'applying',
                        'generating_pages',
                        'verification_linking',
                        'qa',
                        'completed',
                        'escalated',
                        'failed',
                        'running',
                        'sections_planned',
                        'decision_only',
                    ].map((s) => (
                        <option key={s} value={s}>{ingestStatusLabel(s)}</option>
                    ))}
                </select>

                <select
                    value={filters.decision ?? ''}
                    onChange={(e) => navigate({ run_decision: e.target.value })}
                    className={SELECT_CLS}
                >
                    <option value="">{tw.runs_filter_decision_all ?? 'Alle beslutninger'}</option>
                    <option value="pending">{tw.run_decision_pending ?? 'Venter'}</option>
                    <option value="applied">{tw.run_decision_applied ?? 'Anvendt'}</option>
                    <option value="none">{tw.runs_filter_decision_none ?? 'Ingen beslutning'}</option>
                </select>

                {filters.src_id && (
                    <span className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-violet-200 bg-violet-50 px-3 text-sm text-violet-700">
                        {tw.runs_filter_src_active ?? 'Filtrert på dokument'}
                        <button
                            type="button"
                            onClick={() => navigate({ run_src: '' })}
                            className="ml-0.5 text-violet-400 hover:text-violet-700"
                            aria-label="Fjern kildefilter"
                        >
                            <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                            </svg>
                        </button>
                    </span>
                )}

                {hasActiveFilter && (
                    <button
                        type="button"
                        onClick={() => navigate({ run_status: '', run_decision: '', run_src: '' })}
                        className="inline-flex h-9 items-center gap-1 rounded-lg px-3 text-sm font-medium text-slate-500 transition hover:text-slate-800"
                    >
                        <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
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
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200">
                            <thead className="bg-slate-50">
                                <tr className="text-left text-[11px] font-semibold uppercase tracking-widest text-slate-400">
                                    <th className="px-4 py-3 text-right tabular-nums">{tw.runs_col_id ?? 'ID'}</th>
                                    <th className="px-4 py-3">{tw.runs_col_document ?? 'Dokument'}</th>
                                    <th className="px-4 py-3">{tw.runs_col_status ?? 'Status'}</th>
                                    <th className="px-4 py-3">{tw.runs_col_decision ?? 'Beslutning'}</th>
                                    <th className="px-4 py-3 text-right">{tw.runs_col_pages ?? 'Sider'}</th>
                                    <th className="px-4 py-3 text-right">{tw.runs_col_sections ?? 'Seksjoner'}</th>
                                    <th className="px-4 py-3 text-right">{tw.runs_col_lint ?? 'Funn'}</th>
                                    <th className="px-4 py-3 whitespace-nowrap">{tw.runs_col_created ?? 'Opprettet'}</th>
                                    <th className="px-4 py-3 whitespace-nowrap">{tw.runs_col_finished ?? 'Fullført'}</th>
                                    <th className="px-4 py-3 whitespace-nowrap">{tw.runs_col_actions ?? 'Handlinger'}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {runs.map((run) => {
                                    const statusCls = INGEST_STATUS_STYLES[run.status] ?? 'bg-slate-100 text-slate-600';
                                    const runError = run.qa_last_error ?? run.error_message;
                                    const activePanel = expandedRuns[run.id] ?? null;
                                    const panelId = `run-panel-${run.id}`;
                                    return (
                                    <Fragment key={run.id}>
                                        <tr className="text-sm text-slate-700">
                                            <td className="px-4 py-3 text-right font-mono text-xs text-slate-400 tabular-nums">
                                                {run.id}
                                            </td>
                                            <td className="max-w-[200px] px-4 py-3">
                                                {run.source_id ? (
                                                    <Link
                                                        href={`/app/wiki?tab=sources`}
                                                        className="block truncate text-sm font-medium text-slate-900 hover:text-violet-700 hover:underline"
                                                        title={run.source_document_filename ?? ''}
                                                    >
                                                        {run.source_document_filename ?? '—'}
                                                    </Link>
                                                ) : (
                                                    <span className="text-slate-400">—</span>
                                                )}
                                                {run.status === 'failed' && runError && (
                                                    <p className="mt-0.5 line-clamp-2 text-[11px] text-rose-500" title={runError}>
                                                        {runError}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`${BADGE} ${statusCls}`}>
                                                    {ingestStatusLabel(run.status, run.qa_status)}
                                                </span>
                                                <RunActivityBlock run={run} tw={tw} locale={locale} showTimeline />
                                            </td>
                                            <td className="px-4 py-3">
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
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {run.pages_count > 0 ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => togglePanel(run, 'pages', true)}
                                                        aria-expanded={activePanel === 'pages'}
                                                        aria-controls={panelId}
                                                        className="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-semibold text-violet-700 transition hover:bg-violet-50 hover:text-violet-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-400"
                                                    >
                                                        {run.pages_count}
                                                        <svg
                                                            className={`h-3.5 w-3.5 transition-transform ${activePanel === 'pages' ? 'rotate-180' : ''}`}
                                                            viewBox="0 0 20 20"
                                                            fill="currentColor"
                                                            aria-hidden="true"
                                                        >
                                                            <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clipRule="evenodd" />
                                                        </svg>
                                                    </button>
                                                ) : (
                                                    <span className="text-slate-400" title={tw.runs_pages_none ?? 'Ingen Wiki-sider'}>0</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums text-slate-500">
                                                {run.sections_count > 0 ? run.sections_count : <span className="text-slate-400">0</span>}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {run.lint_count > 0 ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => togglePanel(run, 'findings', true)}
                                                        aria-expanded={activePanel === 'findings'}
                                                        aria-controls={panelId}
                                                        aria-label={(tw.runs_findings_toggle_open ?? 'Vis :count kvalitetsfunn').replace(':count', run.lint_count)}
                                                        className={`inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-semibold transition focus:outline-none focus-visible:ring-2 ${findingsCountToneClass(run)}`}
                                                    >
                                                        {run.lint_count}
                                                        <svg
                                                            className={`h-3.5 w-3.5 transition-transform ${activePanel === 'findings' ? 'rotate-180' : ''}`}
                                                            viewBox="0 0 20 20"
                                                            fill="currentColor"
                                                            aria-hidden="true"
                                                        >
                                                            <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clipRule="evenodd" />
                                                        </svg>
                                                    </button>
                                                ) : (
                                                    <span className="text-slate-400" title={tw.runs_findings_none ?? 'Ingen funn'}>0</span>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-sm text-slate-500">
                                                {formatDate(run.created_at, locale)}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-sm text-slate-500">
                                                {run.finished_at ? formatDate(run.finished_at, locale) : <span className="text-slate-400">—</span>}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3">
                                                {run.can_cancel ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleCancelClick(run)}
                                                        className={ACTION_BUTTON_DESTRUCTIVE}
                                                    >
                                                        {tw.run_cancel_button ?? 'Avbryt kjøring'}
                                                    </button>
                                                ) : (
                                                    <span className="text-slate-300">—</span>
                                                )}
                                            </td>
                                        </tr>
                                        {activePanel !== null && (
                                            <tr>
                                                <td colSpan={10} className="bg-slate-50/70 px-4 py-4">
                                                    {activePanel === 'pages' ? (
                                                        <RunAffectedPagesPanel
                                                            panelId={panelId}
                                                            state={runPagesState[run.id]}
                                                            onRetry={() => fetchRunPages(run.id)}
                                                            tw={tw}
                                                        />
                                                    ) : (
                                                        <RunFindingsPanel
                                                            panelId={panelId}
                                                            state={runFindingsState[run.id]}
                                                            onRetry={() => fetchRunFindings(run.id)}
                                                            tw={tw}
                                                            locale={locale}
                                                        />
                                                    )}
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            {cancelTarget && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" role="dialog" aria-modal="true">
                    <div className="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
                        <h2 className="mb-4 text-base font-semibold text-slate-900">
                            {tw.run_cancel_confirm_title ?? 'Bekreft avbrutt kjøring'}
                        </h2>
                        <p className="text-sm font-medium text-slate-800 break-all">
                            {cancelTarget.source_document_filename}
                        </p>
                        <p className="mt-2 text-sm text-slate-600">
                            {tw.run_cancel_confirm_body ?? 'Dette stopper kjøringen. Wiki-innhold som allerede er generert blir beholdt, men kjøringen kan ikke fortsette eller fullføres etterpå. Handlingen kan ikke angres.'}
                        </p>
                        <div className="mt-5 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={handleCancelDismiss}
                                className="inline-flex h-9 items-center rounded-full border border-slate-200 px-4 text-sm font-semibold text-slate-600 transition hover:border-slate-300 hover:text-slate-900"
                            >
                                {tw.run_cancel_dismiss_button ?? 'Lukk'}
                            </button>
                            <button
                                type="button"
                                onClick={handleCancelConfirm}
                                className="inline-flex h-9 items-center rounded-full bg-rose-600 px-4 text-sm font-semibold text-white transition hover:bg-rose-700"
                            >
                                {tw.run_cancel_confirm_button ?? 'Avbryt kjøringen'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

const OWNER_STATUS_STYLES = {
    approved: 'bg-emerald-100 text-emerald-700',
    rejected: 'bg-rose-100 text-rose-700',
    pending: 'bg-amber-100 text-amber-700',
    mixed: 'bg-amber-100 text-amber-700',
    missing_owner: 'bg-rose-100 text-rose-700',
    awaiting_sync: 'bg-slate-100 text-slate-500',
    blocked_by_quality: 'bg-slate-100 text-slate-500',
    processing: 'bg-slate-100 text-slate-500',
    processing_failed: 'bg-rose-100 text-rose-700',
    superseded: 'bg-slate-100 text-slate-400',
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
            <div id={panelId} className="flex items-center gap-2 py-2 text-sm text-slate-500">
                <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-slate-300 border-t-violet-500" aria-hidden="true" />
                {tw.runs_pages_loading ?? 'Henter berørte Wiki-sider …'}
            </div>
        );
    }

    if (state.status === 'error') {
        return (
            <div id={panelId} className="flex items-center justify-between gap-3 py-2 text-sm text-rose-600">
                <span>{tw.runs_pages_error ?? 'Kunne ikke hente Wiki-sidene. Prøv igjen.'}</span>
                <button
                    type="button"
                    onClick={onRetry}
                    className="shrink-0 rounded-md border border-rose-200 px-2.5 py-1 text-xs font-medium text-rose-700 transition hover:bg-rose-50"
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

    if ((summary.blocked_by_quality ?? 0) > 0) {
        summaryParts.push(
            (tw.runs_pages_summary_blocked ?? ':count blokkert av kvalitetskontroll').replace(':count', summary.blocked_by_quality),
        );
    }

    return (
        <div id={panelId} className="space-y-3">
            <div>
                <h4 className="text-sm font-semibold text-slate-700">{tw.runs_pages_heading ?? 'Berørte Wiki-sider'}</h4>
                <p className="mt-0.5 text-xs text-slate-500">{summaryParts.join(' · ')}</p>
                {stallExplanation && (
                    <p className="mt-1 text-xs font-medium text-amber-700">{stallExplanation}</p>
                )}
            </div>

            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-100 text-sm">
                    <thead className="bg-slate-50 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                        <tr className="text-left">
                            <th className="px-3 py-2">{tw.runs_pages_col_page ?? 'Wiki-side'}</th>
                            <th className="px-3 py-2">{tw.runs_pages_col_result ?? 'Resultat'}</th>
                            <th className="px-3 py-2">{tw.runs_pages_col_version ?? 'Sideversjon'}</th>
                            <th className="px-3 py-2">{tw.runs_pages_col_owner_status ?? 'Dokumenteierstatus'}</th>
                            <th className="px-3 py-2 text-right">{tw.runs_pages_col_action ?? 'Handling'}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {pages.map((page) => (
                            <tr key={page.page_id} className="text-slate-700">
                                <td className="max-w-[240px] px-3 py-2">
                                    <span className="block truncate font-medium text-slate-900" title={page.title}>
                                        {page.title}
                                    </span>
                                </td>
                                <td className="px-3 py-2">
                                    <span className={`${BADGE} ${page.action === 'created' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>
                                        {page.action === 'created'
                                            ? (tw.runs_pages_result_created ?? 'Opprettet')
                                            : (tw.runs_pages_result_updated ?? 'Oppdatert')}
                                    </span>
                                </td>
                                <td className="px-3 py-2 whitespace-nowrap text-slate-500">
                                    {page.version_number
                                        ? (tw.runs_pages_version_label ?? 'Versjon :number').replace(':number', page.version_number)
                                        : '—'}
                                </td>
                                <td className="px-3 py-2">
                                    <span className={`${BADGE} ${OWNER_STATUS_STYLES[page.document_owner_status?.state] ?? 'bg-slate-100 text-slate-500'}`}>
                                        {page.document_owner_status?.label ?? '—'}
                                    </span>
                                </td>
                                <td className="px-3 py-2 text-right whitespace-nowrap">
                                    <Link href={page.url} className="text-sm font-medium text-violet-700 hover:underline">
                                        {page.can_handle
                                            ? (tw.runs_pages_action_open_and_handle ?? 'Åpne og behandle')
                                            : (tw.runs_pages_action_open ?? 'Åpne side')}
                                    </Link>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

const FINDING_SEVERITY_STYLES = {
    critical: 'bg-rose-100 text-rose-700',
    error: 'bg-rose-100 text-rose-700',
    warning: 'bg-amber-100 text-amber-700',
    suggestion: 'bg-sky-100 text-sky-700',
    info: 'bg-slate-100 text-slate-500',
};

const FINDING_STATUS_STYLES = {
    requires_action: 'bg-rose-100 text-rose-700',
    open: 'bg-amber-100 text-amber-700',
    resolved: 'bg-emerald-100 text-emerald-700',
    informative: 'bg-slate-100 text-slate-500',
    superseded: 'bg-slate-100 text-slate-400',
    // Best-practice suggestion statuses — deliberately neutral/positive, never the rose/critical
    // styling used for real quality defects (Del 1).
    pending_review: 'bg-sky-100 text-sky-700',
    approved: 'bg-emerald-100 text-emerald-700',
    approved_edited: 'bg-emerald-100 text-emerald-700',
    rejected: 'bg-slate-100 text-slate-500',
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

function matchesFindingsLocalFilter(finding, filterKey) {
    switch (filterKey) {
        case 'open':
            return finding.status === 'requires_action' || finding.status === 'open' || finding.status === 'pending_review';
        case 'blocking':
            return finding.blocks_run;
        case 'resolved':
            return finding.status === 'resolved' || finding.status === 'approved' || finding.status === 'approved_edited' || finding.status === 'rejected';
        case 'informative':
            return finding.status === 'informative' || finding.status === 'superseded';
        default:
            return true;
    }
}

/**
 * Kjøringer "Funn" detail — same inline-under-the-row placement and lazy-fetch pattern as
 * RunAffectedPagesPanel (Del 7); shares the same expansion row, switching in as an alternative to
 * it rather than stacking a second independent panel. Local status/blocking filters apply only
 * within this open panel and never touch the Kjøringer tab's own filter bar.
 */
function RunFindingsPanel({ panelId, state, onRetry, tw, locale }) {
    const [localFilter, setLocalFilter] = useState('all');

    if (!state || state.status === 'loading') {
        return (
            <div id={panelId} className="flex items-center gap-2 py-2 text-sm text-slate-500">
                <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-slate-300 border-t-rose-500" aria-hidden="true" />
                {tw.runs_findings_loading ?? 'Henter kvalitetsfunn …'}
            </div>
        );
    }

    if (state.status === 'error') {
        return (
            <div id={panelId} className="flex items-center justify-between gap-3 py-2 text-sm text-rose-600">
                <span>{tw.runs_findings_error ?? 'Kunne ikke hente kvalitetsfunnene. Prøv igjen.'}</span>
                <button
                    type="button"
                    onClick={onRetry}
                    className="shrink-0 rounded-md border border-rose-200 px-2.5 py-1 text-xs font-medium text-rose-700 transition hover:bg-rose-50"
                >
                    {tw.runs_pages_retry ?? 'Prøv igjen'}
                </button>
            </div>
        );
    }

    const findings = state.data?.findings ?? [];
    const summary = state.data?.summary ?? {};
    const visibleFindings = findings.filter((f) => matchesFindingsLocalFilter(f, localFilter));

    return (
        <div id={panelId} className="space-y-3">
            <div>
                <h4 className="text-sm font-semibold text-slate-700">{tw.runs_findings_heading ?? 'Kvalitetsfunn'}</h4>
                <p className="mt-0.5 text-xs text-slate-500">
                    {(tw.runs_findings_total_label ?? ':count funn totalt').replace(':count', summary.total ?? 0)}
                    {' · '}
                    {summary.open_blocking > 0 && `${summary.open_blocking} ${tw.runs_findings_open_blocking ?? 'åpne blokkerende'} · `}
                    {summary.open_non_blocking > 0 && `${summary.open_non_blocking} ${tw.runs_findings_open_non_blocking ?? 'åpne ikke-blokkerende'} · `}
                    {summary.best_practice_pending > 0 && `${summary.best_practice_pending} ${tw.runs_findings_best_practice_pending_label ?? 'forslag venter på vurdering'} · `}
                    {summary.resolved > 0 && `${summary.resolved} ${tw.runs_findings_resolved ?? 'løst'} · `}
                    {summary.informative > 0 && `${summary.informative} ${tw.runs_findings_informative ?? 'informativt'} · `}
                    {summary.superseded > 0 && `${summary.superseded} ${tw.runs_findings_superseded ?? 'ikke lenger aktuelt'} · `}
                </p>
                {summary.explanation && (
                    <p className="mt-1 text-xs font-medium text-slate-600">{summary.explanation}</p>
                )}
            </div>

            {findings.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {FINDINGS_LOCAL_FILTERS.map((filterKey) => (
                        <button
                            key={filterKey}
                            type="button"
                            onClick={() => setLocalFilter(filterKey)}
                            className={`rounded-full px-2.5 py-1 text-[11px] font-semibold transition ${
                                localFilter === filterKey
                                    ? 'bg-slate-800 text-white'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                            }`}
                        >
                            {findingsLocalFilterLabel(filterKey, tw)}
                        </button>
                    ))}
                </div>
            )}

            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-100 text-sm">
                    <thead className="bg-slate-50 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                        <tr className="text-left">
                            <th className="px-3 py-2">{tw.runs_findings_col_finding ?? 'Funn'}</th>
                            <th className="px-3 py-2">{tw.runs_pages_col_page ?? 'Wiki-side'}</th>
                            <th className="px-3 py-2">{tw.quality_col_severity ?? 'Alvorlighet'}</th>
                            <th className="px-3 py-2">{tw.runs_findings_col_status ?? 'Status'}</th>
                            <th className="px-3 py-2 text-right">{tw.runs_pages_col_action ?? 'Handling'}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {visibleFindings.map((finding) => (
                            <tr key={finding.id} className="align-top text-slate-700">
                                <td className="max-w-[280px] px-3 py-2">
                                    {finding.category === 'best_practice_suggestion' && (
                                        <span className="mb-0.5 inline-flex items-center rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700">
                                            {finding.category_label}
                                        </span>
                                    )}
                                    <span className="block font-medium text-slate-900">{finding.title}</span>
                                    <span className="mt-0.5 block text-xs text-slate-500">
                                        {finding.category === 'best_practice_suggestion' && (
                                            <span className="font-medium text-slate-400">{tw.runs_findings_best_practice_reason_label ?? 'Begrunnelse:'} </span>
                                        )}
                                        {finding.explanation}
                                    </span>
                                </td>
                                <td className="max-w-[200px] px-3 py-2">
                                    {finding.scope === 'run' ? (
                                        <span className="text-slate-500">{tw.runs_findings_scope_run ?? 'Gjelder hele kjøringen'}</span>
                                    ) : (
                                        <span className="block truncate" title={finding.page_title ?? ''}>{finding.page_title ?? '—'}</span>
                                    )}
                                </td>
                                <td className="px-3 py-2">
                                    <span className={`${BADGE} ${FINDING_SEVERITY_STYLES[finding.severity] ?? 'bg-slate-100 text-slate-500'}`}>
                                        {finding.severity_label}
                                    </span>
                                </td>
                                <td className="px-3 py-2">
                                    <span className={`${BADGE} ${FINDING_STATUS_STYLES[finding.status] ?? 'bg-slate-100 text-slate-500'}`}>
                                        {finding.status_label}
                                    </span>
                                </td>
                                <td className="px-3 py-2 text-right whitespace-nowrap">
                                    {finding.url && finding.action ? (
                                        <Link href={finding.url} className="text-sm font-medium text-violet-700 hover:underline">
                                            {finding.action_label}
                                        </Link>
                                    ) : (
                                        <span className="text-slate-400">—</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
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

function QualityTab({ findings, qualityFilters, lintHealth, coverage, tw, locale }) {
    const filters = qualityFilters ?? {};

    const navigate = (overrides) => {
        router.get('/app/wiki', {
            tab: 'quality',
            q_severity: filters.severity ?? '',
            q_code: filters.code ?? '',
            q_page_type: filters.page_type ?? '',
            ...overrides,
        }, { preserveState: true, preserveScroll: true });
    };

    const hasActiveFilter = !!(filters.severity || filters.code || filters.page_type);

    const usedCodes = [...new Set(findings.map((f) => f.code))].sort();

    const openFinding = (finding) => {
        if (!finding.target_url) {
            return;
        }

        router.visit(finding.target_url, { preserveScroll: true });
    };

    const handleFindingKeyDown = (event, finding) => {
        if (!finding.target_url) {
            return;
        }

        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openFinding(finding);
        }
    };

    return (
        <div className="space-y-5">
            <CoveragePanel coverage={coverage} tw={tw} />
            <LintHealthBar health={lintHealth} tw={tw} />

            {/* Filter bar */}
            <div className="flex flex-wrap items-center gap-2">
                <select
                    value={filters.severity ?? ''}
                    onChange={(e) => navigate({ q_severity: e.target.value })}
                    className={SELECT_CLS}
                >
                    <option value="">{tw.quality_filter_severity_all ?? 'Alle alvorligheter'}</option>
                    <option value="error">{tw.lint_severity_error ?? 'Feil'}</option>
                    <option value="warning">{tw.lint_severity_warning ?? 'Advarsel'}</option>
                    <option value="info">{tw.lint_severity_info ?? 'Info'}</option>
                </select>

                <select
                    value={filters.code ?? ''}
                    onChange={(e) => navigate({ q_code: e.target.value })}
                    className={SELECT_CLS}
                >
                    <option value="">{tw.quality_filter_code_all ?? 'Alle sjekker'}</option>
                    {usedCodes.map((c) => (
                        <option key={c} value={c}>{getQualityCheckCopy(c, tw).label}</option>
                    ))}
                </select>

                <select
                    value={filters.page_type ?? ''}
                    onChange={(e) => navigate({ q_page_type: e.target.value })}
                    className={SELECT_CLS}
                >
                    <option value="">{tw.quality_filter_page_type_all ?? 'Alle sidetyper'}</option>
                    {Object.entries(PAGE_TYPE_LABELS).map(([k, v]) => (
                        <option key={k} value={k}>{v}</option>
                    ))}
                </select>

                {hasActiveFilter && (
                    <button
                        type="button"
                        onClick={() => navigate({ q_severity: '', q_code: '', q_page_type: '' })}
                        className="inline-flex h-9 items-center gap-1 rounded-lg px-3 text-sm font-medium text-slate-500 transition hover:text-slate-800"
                    >
                        <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                        </svg>
                        {tw.quality_filter_clear ?? 'Nullstill'}
                    </button>
                )}
            </div>

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
                                <tr className="text-left text-[11px] font-semibold uppercase tracking-widest text-slate-400">
                                    <th className="px-4 py-3">{tw.quality_col_page ?? 'Side'}</th>
                                    <th className="px-4 py-3">{tw.quality_col_page_type ?? 'Sidetype'}</th>
                                    <th className="px-4 py-3">{tw.quality_col_severity ?? 'Alvorlighet'}</th>
                                    <th className="px-4 py-3">{tw.quality_col_type ?? 'Type'}</th>
                                    <th className="px-4 py-3">{tw.quality_col_message ?? 'Beskrivelse'}</th>
                                    <th className="px-4 py-3">{tw.quality_col_source ?? 'Kildefil'}</th>
                                    <th className="px-4 py-3 text-right">{tw.quality_col_run ?? 'Kjøring'}</th>
                                    <th className="px-4 py-3 whitespace-nowrap">{tw.quality_col_detected ?? 'Oppdaget'}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {findings.map((f) => {
                                    const sevCls = SEVERITY_STYLES[f.severity] ?? 'bg-slate-100 text-slate-600';
                                    const checkCopy = getQualityCheckCopy(f.code, tw);
                                    const description = checkCopy.unknown && f.message ? f.message : (checkCopy.description || f.message || '');
                                    const rowTitle = f.page_title ?? f.page_slug ?? checkCopy.label;
                                    return (
                                        <tr
                                            key={f.id}
                                            role={f.target_url ? 'link' : undefined}
                                            tabIndex={f.target_url ? 0 : undefined}
                                            aria-label={f.target_url ? `${tw.quality_row_open ?? 'Åpne kvalitetsfunn'}: ${rowTitle}` : undefined}
                                            onClick={() => openFinding(f)}
                                            onKeyDown={(event) => handleFindingKeyDown(event, f)}
                                            className={`text-sm text-slate-700 ${f.target_url ? 'cursor-pointer transition hover:bg-violet-50 focus-visible:bg-violet-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-violet-300' : ''}`}
                                        >
                                            <td className="max-w-[180px] px-4 py-3">
                                                {f.page_slug ? (
                                                    <Link
                                                        href={`/app/wiki/${f.page_slug}`}
                                                        onClick={(event) => event.stopPropagation()}
                                                        className="block truncate font-medium text-violet-700 hover:underline"
                                                        title={f.page_title ?? f.page_slug}
                                                    >
                                                        {f.page_title ?? f.page_slug}
                                                    </Link>
                                                ) : (
                                                    <span className="text-slate-400">—</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                {f.page_type ? (
                                                    <span className="font-mono text-[11px] text-slate-500">{f.page_type}</span>
                                                ) : (
                                                    <span className="text-slate-400">—</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`${BADGE} ${sevCls}`}>{f.severity}</span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="space-y-0.5">
                                                    <div className="text-sm font-medium text-slate-700">
                                                        {checkCopy.label}
                                                    </div>
                                                    {checkCopy.unknown && (
                                                        <div className="font-mono text-[11px] text-slate-400">{f.code}</div>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="max-w-[240px] px-4 py-3 text-slate-600">
                                                <span className="block truncate" title={description}>{description || '—'}</span>
                                            </td>
                                            <td className="max-w-[160px] px-4 py-3">
                                                {f.source_filename ? (
                                                    <span className="block truncate text-[11px] text-slate-500" title={f.source_filename}>
                                                        {f.source_filename}
                                                    </span>
                                                ) : (
                                                    <span className="text-slate-400">—</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {f.run_id ? (
                                                    <Link
                                                        href={`/app/wiki?tab=runs&run_src=${f.run_id}`}
                                                        onClick={(event) => event.stopPropagation()}
                                                        className="font-mono text-[11px] text-slate-400 hover:text-violet-700 hover:underline"
                                                    >
                                                        #{f.run_id}
                                                    </Link>
                                                ) : (
                                                    <span className="text-slate-400">—</span>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-sm text-slate-500">
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
    document_owner_options: documentOwnerOptions = [],
    runs = [],
    runs_filters: runsFilters = null,
    quality_findings: qualityFindings = [],
    quality_filters: qualityFilters = null,
    coverage = null,
    sources_store_url: sourcesStoreUrl = '/app/wiki/sources',
    wiki_generation_available: wikiGenerationAvailable = false,
    lint_health: lintHealth = { error: 0, warning: 0, info: 0, total: 0 },
}) {
    const { translations = {} } = usePage().props;
    const tw = translations?.wiki ?? {};
    const locale = document.documentElement.lang || 'no';
    const visibleRuns = activeTab === 'sources' ? sources : activeTab === 'runs' ? runs : [];
    const hasActiveWikiRun = visibleRuns.some(isActiveWikiRun) || visibleRuns.some((run) => run?.status === 'queued');
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
                    : activeTab === 'quality'
                        ? {
                            title: tw.quality_page_help_title ?? 'Slik fungerer Kvalitet',
                            intro: tw.quality_page_help_intro ?? 'Kvalitetsfunn er en arbeidsliste for å gå direkte til stedet der noe bør kontrolleres eller rettes. Noen funn peker på en hel side, mens andre kan peke på en konkret påstand.',
                            sections: getWikiQualityHelpSections(tw),
                        }
                : null;

    useEffect(() => {
        if (!hasActiveWikiRun || !['sources', 'runs'].includes(activeTab)) {
            return undefined;
        }

        const only = activeTab === 'sources' ? ['sources'] : ['runs'];
        const interval = window.setInterval(() => {
            router.reload({ only, preserveScroll: true });
        }, 5000);

        return () => window.clearInterval(interval);
    }, [activeTab, hasActiveWikiRun]);

    return (
        <CustomerAppLayout title={tw.index_title ?? 'Wiki'} showPageTitle={false}>
            <div className="space-y-6">
                <section className="flex flex-col gap-4 rounded-3xl border border-slate-200 bg-white/80 p-6 shadow-sm overflow-visible sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-1.5">
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                                {tw.index_title ?? 'Wiki'}
                            </h1>
                            {wikiHelpContent && (
                                <PageHelpButton
                                    buttonLabel={tw.page_help_button ?? 'Hjelp'}
                                    title={wikiHelpContent.title}
                                    intro={wikiHelpContent.intro}
                                    sections={wikiHelpContent.sections}
                                />
                            )}
                        </div>
                        <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                            {tw.index_description ?? 'Strukturert kunnskap om virksomheten, generert fra godkjent innhold.'}
                        </p>
                    </div>
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
                        documentOwnerOptions={documentOwnerOptions}
                        wikiGenerationAvailable={wikiGenerationAvailable}
                        tw={tw}
                        locale={locale}
                    />
                )}
                {activeTab === 'runs' && (
                    <RunsTab runs={runs} runsFilters={runsFilters} tw={tw} locale={locale} />
                )}
                {activeTab === 'quality' && (
                    <QualityTab findings={qualityFindings} qualityFilters={qualityFilters} lintHealth={lintHealth} coverage={coverage} tw={tw} locale={locale} />
                )}
            </div>
        </CustomerAppLayout>
    );
}
