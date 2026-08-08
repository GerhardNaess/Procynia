import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ReactMarkdown from 'react-markdown';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import {
    getWikiQualityCheckCopy,
    groupWikiFindingsByCode,
    splitWikiVerificationFindings,
} from './wikiQualityChecks';
import { formatFindingUserId } from './runFindingsLogic';
import {
    groupBestPracticeClaimsForReview,
    groupContentBlocksBySection,
    isPageVersionFinallyApproved,
    partitionBestPracticeReviewUnits,
    resolveBestPracticeSectionForBlock,
    hasInvalidBestPracticeMetadata,
} from './wikiBestPracticeSectionLogic';

function getWikiShowHelpSections(tw) {
    return [
        {
            title: tw.show_page_help_section_best_practice ?? 'Forslag basert på beste praksis',
            items: [
                {
                    title: tw.show_page_help_item_best_practice_what_title ?? 'Et forslag, ikke en feil',
                    text: tw.show_page_help_item_best_practice_what_text ?? 'Beste-praksis-tekst er et bevisst forslag utover kildedokumentet, ikke dokumentert kundekunnskap. Det er ikke en teknisk feil, og det blokkerer ikke kvalitetskontrollen.',
                },
                {
                    title: tw.show_page_help_item_best_practice_decide_title ?? 'Godkjenn, rediger eller avvis',
                    text: tw.show_page_help_item_best_practice_decide_text ?? 'Du kan godkjenne forslaget som det er, redigere teksten og godkjenne, eller avvise det. Beslutningen gjelder den konkrete teksten den ble foreslått for.',
                },
                {
                    title: tw.show_page_help_item_best_practice_defect_title ?? 'Udokumenterte faktapåstander er noe annet',
                    text: tw.show_page_help_item_best_practice_defect_text ?? 'Tekst som fremstilles som et faktisk forhold hos kunden, men ikke er støttet av kilden og heller ikke er beste praksis, behandles fortsatt som en kvalitetsfeil.',
                },
            ],
        },
        {
            title: tw.show_page_help_section_blocking ?? 'Alvorlighet og blokkering',
            items: [
                {
                    title: tw.show_page_help_item_blocking_categories_title ?? 'Hvert funn har en konkret forklaring',
                    text: tw.show_page_help_item_blocking_categories_text ?? 'Funn vises ikke lenger med én generell standardtekst. Hvert funn viser den konkrete påstanden, hva systemet mener avviker, og hvorfor.',
                },
                {
                    title: tw.show_page_help_item_blocking_technical_title ?? 'Teknisk usikkerhet er ikke det samme som en innholdsfeil',
                    text: tw.show_page_help_item_blocking_technical_text ?? 'Når systemet ikke fant en sikker kobling mellom en påstand og et kildeavsnitt, vises dette som en teknisk usikkerhet — ikke som en bekreftet feil i innholdet. Slike funn er som regel ikke blokkerende i utgangspunktet.',
                },
                {
                    title: tw.show_page_help_item_blocking_override_title ?? 'Systemets anbefaling er ikke det samme som brukerens beslutning',
                    text: tw.show_page_help_item_blocking_override_text ?? 'Et funn kan vise «Systemet anbefaler blokkering» og samtidig «Avventer vurdering» — det betyr at ingen har tatt stilling ennå, ikke at funnet allerede blokkerer. En autorisert bruker velger «Blokker godkjenning» eller «Godkjenn avvik / Ikke blokker». Beslutningen lagres med bruker, tidspunkt og eventuell kommentar.',
                },
            ],
        },
    ];
}

const PAGE_STATUS_STYLES = {
    approved: 'bg-emerald-100 text-emerald-700',
    pending_review: 'bg-amber-100 text-amber-700',
    draft: 'bg-slate-200 text-slate-600',
    rejected: 'bg-rose-100 text-rose-700',
    archived: 'bg-slate-200 text-slate-700',
};

const PAGE_TYPE_STYLES = {
    article: 'bg-violet-100 text-violet-700',
    summary: 'bg-sky-100 text-sky-700',
    concept: 'bg-teal-100 text-teal-700',
    entity: 'bg-orange-100 text-orange-700',
};

const CONFIDENCE_STYLES = {
    high: 'bg-emerald-100 text-emerald-700',
    medium: 'bg-amber-100 text-amber-700',
    low: 'bg-rose-100 text-rose-700',
    uncertain: 'bg-slate-200 text-slate-700',
};

const CLAIM_STATUS_STYLES = {
    pending: 'bg-amber-100 text-amber-700',
    approved: 'bg-emerald-100 text-emerald-700',
    rejected: 'bg-rose-100 text-rose-700',
};

const SOURCE_STATUS_STYLES = {
    source_found: 'bg-emerald-100 text-emerald-700',
    missing_excerpt: 'bg-amber-100 text-amber-700',
    manually_approved: 'bg-sky-100 text-sky-700',
    rejected: 'bg-slate-200 text-slate-700',
    missing_source: 'bg-rose-100 text-rose-700',
    best_practice_review: 'bg-amber-100 text-amber-700',
    internal_generation_error: 'bg-slate-200 text-slate-700',
    unsupported_generated_content: 'bg-slate-200 text-slate-700',
};

const SOURCE_STATUS_WARNS = new Set(['missing_excerpt', 'missing_source', 'best_practice_review', 'internal_generation_error', 'unsupported_generated_content']);

const CLAIM_SOURCE_STATUS_STYLES = {
    source_found: 'bg-emerald-100 text-emerald-700',
    missing_excerpt: 'bg-amber-100 text-amber-700',
    manually_approved: 'bg-sky-100 text-sky-700',
    rejected: 'bg-slate-200 text-slate-700',
    missing_source: 'bg-rose-100 text-rose-700',
    best_practice_review: 'bg-amber-100 text-amber-700',
    internal_generation_error: 'bg-slate-200 text-slate-700',
    unsupported_generated_content: 'bg-slate-200 text-slate-700',
};

const FINDING_CATEGORY_STYLES = {
    undocumented_or_incorrect_claim: 'bg-rose-100 text-rose-700',
    possible_content_deviation: 'bg-amber-100 text-amber-700',
    technical_uncertainty: 'bg-sky-100 text-sky-700',
};

const HIGH_VOLUME_THRESHOLD = 100;

function Badge({ label, cls }) {
    return (
        <span className={`inline-flex items-center whitespace-nowrap rounded-full px-3 py-1.5 text-base font-semibold leading-6 ${cls}`}>
            {label}
        </span>
    );
}

function CheckIcon({ className = 'h-3.5 w-3.5' }) {
    return (
        <svg className={className} viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fillRule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clipRule="evenodd" />
        </svg>
    );
}

function WarnIcon({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clipRule="evenodd" />
        </svg>
    );
}

function BackArrowIcon({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fillRule="evenodd" d="M17 10a.75.75 0 0 1-.75.75H5.612l4.158 3.96a.75.75 0 1 1-1.04 1.08l-5.5-5.25a.75.75 0 0 1 0-1.08l5.5-5.25a.75.75 0 1 1 1.04 1.08L5.612 9.25H16.25A.75.75 0 0 1 17 10Z" clipRule="evenodd" />
        </svg>
    );
}

function ChevronIcon({ open }) {
    return (
        <svg
            className="h-4 w-4 transition-transform"
            style={{ transform: open ? 'rotate(180deg)' : 'rotate(0deg)' }}
            viewBox="0 0 20 20"
            fill="currentColor"
            aria-hidden="true"
        >
            <path fillRule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clipRule="evenodd" />
        </svg>
    );
}

function ClaimSummary({ summary, tw }) {
    if (!summary || summary.total === 0) return null;

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm">
                <span className="font-semibold text-slate-700">
                    {summary.total} {tw.claims ?? 'påstander'}
                </span>
                <span className="flex items-center gap-1 text-emerald-700">
                    <CheckIcon />
                    {summary.source_found} {tw.quality_source_found ?? 'kilde funnet'}
                </span>
                {summary.manually_approved > 0 && (
                    <span className="flex items-center gap-1 text-sky-700">
                        <CheckIcon className="h-3.5 w-3.5" />
                        {summary.manually_approved} {tw.quality_manually_approved ?? 'manuelt godkjent'}
                    </span>
                )}
                {summary.rejected > 0 && (
                    <span className="flex items-center gap-1 text-slate-700">
                        <WarnIcon className="h-3.5 w-3.5" />
                        {summary.rejected} {tw.claim_status_rejected ?? 'avvist'}
                    </span>
                )}
                {summary.missing_excerpt > 0 && (
                    <span className="flex items-center gap-1 text-amber-700">
                        <WarnIcon className="h-3.5 w-3.5" />
                        {summary.missing_excerpt} {tw.quality_missing_excerpt ?? 'mangler utdrag'}
                    </span>
                )}
                {summary.missing_source > 0 && (
                    <span className="flex items-center gap-1 text-rose-600">
                        <WarnIcon className="h-3.5 w-3.5" />
                        {summary.missing_source} {tw.quality_no_source ?? 'mangler kilde'}
                    </span>
                )}
                {summary.conflict > 0 && (
                    <span className="flex items-center gap-1 text-rose-600">
                        <WarnIcon className="h-3.5 w-3.5" />
                        {summary.conflict} {tw.conflict_detected ?? 'mulig konflikt'}
                    </span>
                )}
            </div>
            {summary.total > HIGH_VOLUME_THRESHOLD && (
                <div className="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <WarnIcon className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
                    <p>{tw.high_volume_warning ?? 'Denne siden har mange påstander. Gå gjennom seksjon for seksjon før godkjenning.'}</p>
                </div>
            )}
        </div>
    );
}

const LINT_SEVERITY_STYLES = {
    error: 'bg-rose-100 text-rose-700',
    warning: 'bg-amber-100 text-amber-700',
    info: 'bg-slate-100 text-slate-700',
};

const WIKI_INLINE_LINK_CLASS =
    'rounded-sm text-violet-700 underline decoration-violet-300 decoration-2 underline-offset-2 transition hover:text-violet-800 hover:decoration-violet-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-500';

/**
 * ReactMarkdown's `a` component override for the article body: EnterpriseWikiWikilinkRenderer
 * rewrites canonical [[slug|anchor]] wikilinks into standard `/app/wiki/{slug}` markdown links
 * server-side, so this only needs to route those internally via Inertia instead of a full page
 * reload — every other markdown link (external URLs, etc.) renders as a plain anchor exactly
 * as before.
 */
function WikiArticleLink({ href, children, ...rest }) {
    if (typeof href === 'string' && href.startsWith('/app/wiki/')) {
        return (
            <Link href={href} className={WIKI_INLINE_LINK_CLASS}>
                {children}
            </Link>
        );
    }

    return (
        <a href={href} {...rest}>
            {children}
        </a>
    );
}

function LinkedPageList({ pages, label }) {
    if (!pages || pages.length === 0) return null;

    return (
        <div className="flex flex-wrap items-start gap-x-3 gap-y-2">
            <span className="shrink-0 text-xs font-semibold text-slate-500 mt-0.5">{label}:</span>
            <div className="flex flex-wrap gap-2">
                {pages.map((p) => (
                    <Link
                        key={p.id}
                        href={`/app/wiki/${p.slug}`}
                        className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1 text-sm font-medium text-slate-700 shadow-sm transition hover:border-violet-300 hover:bg-violet-50 hover:text-violet-800"
                    >
                        {p.page_type && (
                            <span className={`inline-block h-1.5 w-1.5 shrink-0 rounded-full ${
                                p.page_type === 'article' ? 'bg-violet-500' :
                                p.page_type === 'summary' ? 'bg-sky-500' :
                                p.page_type === 'concept' ? 'bg-teal-500' :
                                'bg-orange-500'
                            }`} />
                        )}
                        {p.title}
                    </Link>
                ))}
            </div>
        </div>
    );
}

const ORPHAN_CONCEPT_CANDIDATE_REASON_KEYS = {
    incoming_wikilink: 'structure_finding_candidate_reason_incoming_wikilink',
    structural_pairing: 'structure_finding_candidate_reason_structural_pairing',
    mentions_title: 'structure_finding_candidate_reason_mentions_title',
    shared_canonical_fact: 'structure_finding_candidate_reason_shared_canonical_fact',
};

const ORPHAN_CONCEPT_CANDIDATE_REASON_FALLBACKS = {
    incoming_wikilink: 'Denne siden lenker allerede inn til begrepssiden.',
    structural_pairing: 'Siden er allerede koblet til begrepssiden gjennom eksisterende sidestruktur.',
    mentions_title: 'Sidens tekst nevner allerede begrepssidens tittel.',
    shared_canonical_fact: 'Sidene deler et eksisterende faktagrunnlag.',
};

function OrphanConceptCandidateList({
    finding,
    tw,
    pageTypeLabel,
    onLinkCandidate,
    linkingCandidateId,
    confirmingCandidateId,
    onRequestConfirm,
    onCancelConfirm,
}) {
    if (finding.code !== 'orphan_concept_page' || finding.status !== 'open') {
        return null;
    }

    const candidates = finding.candidate_targets ?? [];

    return (
        <div className="mt-5 rounded-2xl border border-sky-100 bg-white/80 p-4">
            <h3 className="text-base font-semibold text-slate-800">
                {tw.structure_finding_candidates_heading ?? 'Kandidater for tilkobling'}
            </h3>
            <p className="mt-1 text-base leading-7 text-slate-600">
                {tw.structure_finding_candidates_intro ?? 'Denne begrepssiden mangler en utgående lenke til en artikkel- eller sammendragsside. Kandidatene under er valgt fordi eksisterende Wiki-data allerede knytter dem til denne siden.'}
            </p>

            {candidates.length === 0 && (
                <p className="mt-3 text-base leading-7 text-slate-600">
                    {tw.structure_finding_candidates_empty ?? 'Det finnes ingen dokumenterbar kandidat i eksisterende Wiki-data ennå. Legg til en relevant Wiki-lenke manuelt i teksten i stedet for å bruke et svakt forslag.'}
                </p>
            )}

            <ul className="mt-3 space-y-3">
                {candidates.map((candidate) => {
                    const isConfirming = confirmingCandidateId === candidate.page_id;
                    const isLinking = linkingCandidateId === candidate.page_id;

                    return (
                        <li key={candidate.page_id} className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p className="text-base font-semibold text-slate-800">{candidate.title}</p>
                                    <p className="text-base text-slate-600">{pageTypeLabel(candidate.page_type)}</p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <a
                                        href={`/app/wiki/${candidate.slug}`}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="rounded-lg border border-slate-300 px-3.5 py-2 text-base font-medium text-slate-700 hover:bg-slate-50"
                                    >
                                        {tw.structure_finding_candidate_open_button ?? 'Åpne siden'}
                                    </a>
                                    {finding.can_link_related_page && !isConfirming && (
                                        <button
                                            type="button"
                                            onClick={() => onRequestConfirm(candidate.page_id)}
                                            className="rounded-lg bg-violet-700 px-3.5 py-2 text-base font-semibold text-white hover:bg-violet-800"
                                        >
                                            {tw.structure_finding_candidate_link_button ?? 'Koble til denne siden'}
                                        </button>
                                    )}
                                </div>
                            </div>

                            <ul className="mt-2 list-disc space-y-1 pl-5 text-base leading-7 text-slate-600">
                                {(candidate.reasons ?? []).map((reason) => (
                                    <li key={reason}>
                                        {tw[ORPHAN_CONCEPT_CANDIDATE_REASON_KEYS[reason]]
                                            ?? ORPHAN_CONCEPT_CANDIDATE_REASON_FALLBACKS[reason]
                                            ?? reason}
                                    </li>
                                ))}
                            </ul>

                            {isConfirming && (
                                <div className="mt-3 rounded-lg border border-violet-200 bg-violet-50 px-3 py-3">
                                    <p className="text-base leading-6 text-violet-900">
                                        {(tw.structure_finding_candidate_confirm_text
                                            ?? 'Dette oppretter en utgående Wiki-lenke fra «:from» til «:to». Fortsette?')
                                            .replace(':from', finding.page_title)
                                            .replace(':to', candidate.title)}
                                    </p>
                                    <div className="mt-2 flex gap-2">
                                        <button
                                            type="button"
                                            disabled={isLinking}
                                            onClick={() => onLinkCandidate(candidate.page_id)}
                                            className="rounded-lg bg-violet-700 px-3.5 py-2 text-base font-semibold text-white hover:bg-violet-800 disabled:opacity-60"
                                        >
                                            {isLinking
                                                ? (tw.structure_finding_candidate_linking ?? 'Kobler til …')
                                                : (tw.structure_finding_candidate_confirm_button ?? 'Bekreft kobling')}
                                        </button>
                                        <button
                                            type="button"
                                            disabled={isLinking}
                                            onClick={() => onCancelConfirm()}
                                            className="rounded-lg border border-slate-300 px-3.5 py-2 text-base font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                                        >
                                            {tw.structure_finding_candidate_cancel_button ?? 'Avbryt'}
                                        </button>
                                    </div>
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>

            {!finding.can_link_related_page && (
                <p className="mt-3 text-base leading-6 text-slate-600">
                    {tw.structure_finding_candidates_read_only_note ?? 'Du har lesetilgang til dette funnet. En bruker med redigeringstilgang til Wiki-innhold kan opprette koblingen.'}
                </p>
            )}
        </div>
    );
}

function StructureFindingContextPanel({
    finding,
    tw,
    pageTypeLabel,
    outgoingLinks,
    incomingLinks,
    backlinks,
    relatedArticles,
    relatedConcepts,
    relatedEntities,
    onLinkCandidate,
    linkingCandidateId,
    confirmingCandidateId,
    onRequestConfirm,
    onCancelConfirm,
}) {
    if (!finding) {
        return null;
    }

    const hasDirectLinks = outgoingLinks.length > 0
        || incomingLinks.length > 0
        || backlinks.length > 0;
    const hasRelevantPages = relatedArticles.length > 0
        || relatedConcepts.length > 0
        || relatedEntities.length > 0;
    const severityClass = LINT_SEVERITY_STYLES[finding.severity] ?? 'bg-slate-100 text-slate-600';

    return (
        <section
            id={`structure-finding-${finding.id}`}
            data-testid="structure-finding-panel"
            tabIndex={-1}
            className="rounded-3xl border border-sky-200 bg-sky-50/80 p-6 shadow-[0_12px_30px_rgba(2,132,199,0.10)]"
        >
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="max-w-3xl space-y-3">
                    <div className="flex flex-wrap gap-2">
                        <Badge label={finding.severity_label ?? finding.severity} cls={severityClass} />
                        <Badge label={finding.status_label ?? finding.status} cls="bg-white text-slate-600 ring-1 ring-slate-200" />
                    </div>
                    <div className="space-y-2">
                        <p className="text-base font-semibold uppercase tracking-[0.18em] text-sky-700">
                            {tw.structure_finding_panel_kicker ?? 'Sidefunn'}
                        </p>
                        <h2 className="text-2xl font-semibold tracking-tight text-slate-950">
                            {tw.structure_finding_panel_heading ?? 'Problem med sidestrukturen'}
                        </h2>
                        <p className="text-base leading-7 text-slate-700">
                            {finding.description || finding.message || (tw.structure_finding_panel_unknown ?? 'Dette funnet gjelder strukturen på Wiki-siden.')}
                        </p>
                        <p className="text-base leading-7 text-slate-600">
                            {tw.structure_finding_panel_scope_note ?? 'Dette gjelder hele siden, ikke et bestemt avsnitt. Bruk lenkestatusen under til å vurdere hvilke Wiki-sider denne siden bør kobles til.'}
                        </p>
                    </div>
                </div>
                <dl className="grid min-w-64 gap-2 rounded-2xl border border-sky-100 bg-white/80 p-4 text-base text-slate-700">
                    <div>
                        <dt className="text-base font-semibold text-slate-700">
                            {tw.structure_finding_page_label ?? 'Side'}
                        </dt>
                        <dd>{finding.page_title}</dd>
                    </div>
                    <div>
                        <dt className="text-base font-semibold text-slate-700">
                            {tw.structure_finding_page_type_label ?? 'Sidetype'}
                        </dt>
                        <dd>{pageTypeLabel(finding.page_type)}</dd>
                    </div>
                    <div>
                        <dt className="text-base font-semibold text-slate-700">
                            {tw.structure_finding_type_label ?? 'Funntype'}
                        </dt>
                        <dd>{finding.category_label ?? finding.code}</dd>
                    </div>
                </dl>
            </div>

            {finding.message && finding.message !== finding.description && (
                <p className="mt-4 rounded-2xl border border-sky-100 bg-white/70 px-4 py-3 text-base leading-7 text-slate-600">
                    <span className="font-semibold text-slate-700">
                        {tw.structure_finding_detected_reason_label ?? 'Hvorfor funnet ble opprettet'}:
                    </span>{' '}
                    {finding.message}
                </p>
            )}

            {finding.is_current_version === false && (
                <p className="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-base leading-7 text-amber-800">
                    {tw.structure_finding_superseded_note ?? 'Funnet peker til en eldre sideversjon. Kontroller gjeldende side før du gjør endringer.'}
                </p>
            )}

            <div className="mt-5 grid gap-4 lg:grid-cols-2">
                <div className="rounded-2xl border border-sky-100 bg-white/80 p-4">
                    <h3 className="text-base font-semibold text-slate-800">
                        {tw.structure_finding_existing_links_heading ?? 'Eksisterende Wiki-lenker'}
                    </h3>
                    <div className="mt-3 space-y-3">
                        <LinkedPageList pages={outgoingLinks} label={tw.structure_finding_outgoing_links ?? 'Utgående lenker'} />
                        <LinkedPageList pages={backlinks.length > 0 ? backlinks : incomingLinks} label={tw.structure_finding_incoming_links ?? 'Sider som lenker hit'} />
                        {!hasDirectLinks && (
                            <p className="text-base leading-7 text-slate-500">
                                {tw.structure_finding_no_direct_links ?? 'Ingen direkte Wiki-lenker er registrert for denne siden ennå.'}
                            </p>
                        )}
                    </div>
                </div>

                <div className="rounded-2xl border border-sky-100 bg-white/80 p-4">
                    <h3 className="text-base font-semibold text-slate-800">
                        {tw.structure_finding_relevant_pages_heading ?? 'Relevante sider i eksisterende data'}
                    </h3>
                    <div className="mt-3 space-y-3">
                        <LinkedPageList pages={relatedArticles} label={tw.traversal_related_articles ?? 'Relaterte artikler'} />
                        <LinkedPageList pages={relatedConcepts} label={tw.traversal_related_concepts ?? 'Konsepter'} />
                        <LinkedPageList pages={relatedEntities} label={tw.traversal_related_entities ?? 'Entiteter'} />
                        {!hasRelevantPages && (
                            <p className="text-base leading-7 text-slate-500">
                                {tw.structure_finding_no_suggestions ?? 'Det finnes ingen sikre koblingsforslag i eksisterende Wiki-data. Legg heller til en relevant Wiki-lenke manuelt enn å bruke et svakt forslag.'}
                            </p>
                        )}
                    </div>
                </div>
            </div>

            <OrphanConceptCandidateList
                finding={finding}
                tw={tw}
                pageTypeLabel={pageTypeLabel}
                onLinkCandidate={onLinkCandidate}
                linkingCandidateId={linkingCandidateId}
                confirmingCandidateId={confirmingCandidateId}
                onRequestConfirm={onRequestConfirm}
                onCancelConfirm={onCancelConfirm}
            />

            <div className="mt-5 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-base leading-7 text-slate-600">
                <p className="font-semibold text-slate-800">
                    {tw.structure_finding_handling_heading ?? 'Behandling'}
                </p>
                <p>
                    {tw.structure_finding_handling_note ?? 'Det finnes ingen separat manuell lukking for sidestrukturfunn i dagens Wiki-flyt. Funnet lukkes av eksisterende lint-kjøring når lenkestrukturen er rettet.'}
                </p>
            </div>
        </section>
    );
}

/**
 * Renders a deterministic "table" content block (see EnterpriseWikiTableBlockBuilder) as a real,
 * semantic HTML table — never as Markdown text — so column headers, row order, and cell content
 * survive exactly as written in the source Word table. Never editable in this phase (no existing
 * block-editor affordance understands table_data), matching every other read-only source-based
 * block until the block editor itself grows table support.
 */
function WikiTableBlock({ block, tw, sourceDocuments }) {
    const tableData = block.table_data;

    if (!tableData || !Array.isArray(tableData.headers) || tableData.headers.length === 0) {
        return null;
    }

    const rows = Array.isArray(tableData.rows) ? tableData.rows : [];
    const tableNumber = (Number(tableData.table_index) || 0) + 1;
    const sourceDocument = sourceDocuments.find((doc) => String(doc.id) === String(block.source_id)) ?? null;

    return (
        <div className="not-prose my-4 space-y-2">
            <p className="text-base font-semibold text-slate-700">
                {(tw.wiki_table_caption ?? 'Tabell :number').replace(':number', tableNumber)}
            </p>
            <div className="overflow-x-auto rounded-2xl border border-slate-200">
                <table className="w-full min-w-[480px] border-collapse text-base text-slate-800">
                    <thead>
                        <tr className="bg-slate-50">
                            {tableData.headers.map((header, index) => (
                                <th
                                    key={index}
                                    scope="col"
                                    className="border-b border-slate-200 px-4 py-2.5 text-left font-semibold text-slate-700"
                                >
                                    {header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, rowIndex) => {
                            const cellsByIndex = {};
                            (Array.isArray(row?.cells) ? row.cells : []).forEach((cell) => {
                                cellsByIndex[cell.column_index] = cell.value;
                            });

                            return (
                                <tr key={row?.row_key ?? rowIndex} className="border-b border-slate-100 last:border-0">
                                    {tableData.headers.map((_, columnIndex) => (
                                        <td key={columnIndex} className="px-4 py-2.5 align-top leading-6">
                                            {cellsByIndex[columnIndex] ?? ''}
                                        </td>
                                    ))}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            <div className="flex flex-wrap items-center justify-between gap-2 text-base text-slate-500">
                <p>
                    {tw.source_page_reference ?? 'Plassering i kilden'}: {block.page_reference ?? '—'}
                </p>
                {sourceDocument?.download_url && (
                    <a
                        href={sourceDocument.download_url}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1 font-medium text-violet-600 hover:text-violet-800 hover:underline"
                    >
                        {tw.source_open_document ?? 'Åpne original kilde'}
                    </a>
                )}
            </div>
        </div>
    );
}

/**
 * Renders a deterministic "image" content block (see EnterpriseWikiImageBlockBuilder) as a real,
 * semantic <figure>/<img>/<figcaption> — never as Markdown text. The image itself is never
 * embedded directly or loaded from a raw storage path: image_data.image_url is always a server-
 * computed, authenticated route (WikiSourceController::image()) that re-extracts and re-encodes
 * the bytes on every request. Never editable in this phase, matching WikiTableBlock's precedent.
 */
function WikiImageBlock({ block, tw, sourceDocuments }) {
    const imageData = block.image_data;

    if (!imageData || !imageData.image_url) {
        return null;
    }

    const sourceDocument = sourceDocuments.find((doc) => String(doc.id) === String(block.source_id)) ?? null;
    const caption = imageData.caption
        || (tw.wiki_figure_caption ?? 'Figur :number').replace(':number', imageData.figure_number);

    return (
        <div className="not-prose my-4 space-y-2">
            <figure className="m-0 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
                <img
                    src={imageData.image_url}
                    alt={imageData.alt_text ?? ''}
                    loading="lazy"
                    className="block max-h-[28rem] w-full object-contain"
                />
                <figcaption className="border-t border-slate-200 bg-white px-4 py-2.5 text-base text-slate-700">
                    {caption}
                </figcaption>
            </figure>
            <div className="flex flex-wrap items-center justify-between gap-2 text-base text-slate-500">
                <p>
                    {tw.source_page_reference ?? 'Plassering i kilden'}: {block.page_reference ?? '—'}
                </p>
                {sourceDocument?.download_url && (
                    <a
                        href={sourceDocument.download_url}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1 font-medium text-violet-600 hover:text-violet-800 hover:underline"
                    >
                        {tw.source_open_document ?? 'Åpne original kilde'}
                    </a>
                )}
            </div>
        </div>
    );
}

export default function WikiShow({
    page,
    current_version,
    review_reference: reviewReference = null,
    structure_finding: structureFinding = null,
    claims,
    claim_summary: claimSummary = null,
    can_handle_wiki_claims: canHandleWikiClaims = false,
    source_documents: sourceDocuments = [],
    document_owner_approvals: documentOwnerApprovals = [],
    document_owner_approval_summary: documentOwnerApprovalSummary = null,
    document_owner_summary: documentOwnerSummary = null,
    lint_findings: lintFindings = [],
    lint_summary: lintSummary = null,
    can_edit_wiki_claims: canEditWikiClaims = false,
    manual_block_edit: manualBlockEdit = null,
    outgoing_links: outgoingLinks = [],
    incoming_links: incomingLinks = [],
    related_articles: relatedArticles = [],
    related_concepts: relatedConcepts = [],
    related_entities: relatedEntities = [],
    backlinks = [],
}) {
    const { translations = {}, auth = {}, errors = {} } = usePage().props;
    const tw = translations?.wiki ?? {};
    const locale = document.documentElement.lang || 'no';
    const isSystemOwner = auth.user?.is_system_owner ?? false;
    // Backend-validated (WikiController::show()/buildReviewReference()) — never trust the raw
    // ?claim_id= URL param alone to decide what to scroll to or highlight (Del 7). `status` is
    // one of 'ready' | 'superseded' | 'block_missing' | 'not_found'.
    const targetClaimId = reviewReference?.status === 'ready' ? String(reviewReference.claim_id) : null;
    const targetBlockKey = reviewReference?.status === 'ready' ? reviewReference.block_key : null;
    const hasStructureFinding = Boolean(structureFinding?.id);
    const backHref = reviewReference?.back_url ?? structureFinding?.back_url ?? '/app/wiki?tab=runs';
    const hasFocusedReview = targetBlockKey !== null;
    const focusedReviewClaims = hasFocusedReview
        ? claims.filter((claim) => String(claim.id) === String(targetClaimId))
        : [];
    const verificationClaims = hasFocusedReview
        ? claims.filter((claim) => String(claim.id) !== String(targetClaimId))
        : claims;
    const hasVerificationContent = verificationClaims.length > 0 || lintFindings.length > 0;

    const [processing, setProcessing] = useState(null);
    const [verificationOpen, setVerificationOpen] = useState(hasVerificationContent && !hasFocusedReview);
    const [verifiedClaimsOpen, setVerifiedClaimsOpen] = useState(!hasFocusedReview && Boolean(targetClaimId));
    const [structuralFindingsOpen, setStructuralFindingsOpen] = useState(!hasFocusedReview && Boolean(targetClaimId));
    const [claimProcessing, setClaimProcessing] = useState(null);
    const [claimSourceDrafts, setClaimSourceDrafts] = useState({});
    const [claimSourceProcessing, setClaimSourceProcessing] = useState(null);
    const [claimSourceCatalog, setClaimSourceCatalog] = useState({});
    const [approvalComments, setApprovalComments] = useState({});
    const [claimTextEdits, setClaimTextEdits] = useState({});
    const [wikiBlockEditingKey, setWikiBlockEditingKey] = useState(null);
    const [wikiBlockEditDrafts, setWikiBlockEditDrafts] = useState({});
    const [wikiBlockSaveProcessingKey, setWikiBlockSaveProcessingKey] = useState(null);
    const [wikiBlockEditError, setWikiBlockEditError] = useState(null);
    const [documentOwnerApprovalComments, setDocumentOwnerApprovalComments] = useState({});
    const [documentOwnerApprovalProcessing, setDocumentOwnerApprovalProcessing] = useState(null);
    const [confirmingCandidateId, setConfirmingCandidateId] = useState(null);
    const [linkingCandidateId, setLinkingCandidateId] = useState(null);
    const claimAccessNotice = canHandleWikiClaims
        ? (tw.verification_basis_claim_handler_notice ?? 'Kontroller påstandene mot kildedokumentene. Koble kilde, godkjenn eller avvis påstanden.')
        : (tw.verification_basis_read_only_notice ?? 'Påstandene må behandles av en bruker med tilgang til det aktuelle kildegrunnlaget.');

    const getWikiBlockMarkdown = (block) => block.markdown;
    const getWikiBlockRawMarkdown = (block) => typeof block.raw_markdown === 'string'
        ? block.raw_markdown
        : block.markdown;
    const manualBlockEditUrl = (claimId) => {
        const template = String(manualBlockEdit?.update_url_template ?? '');

        if (!template || !claimId) {
            return null;
        }

        return template.replace('__CLAIM_ID__', encodeURIComponent(String(claimId)));
    };
    const wikiBlockValidationError = wikiBlockEditError
        ?? errors.blocks
        ?? Object.entries(errors).find(([key]) => key.startsWith('blocks.'))?.[1]
        ?? errors.expected_page_version_id
        ?? errors.run_id
        ?? null;

    const startWikiBlockEdit = (blockKey, fallbackMarkdown) => {
        setWikiBlockEditError(null);
        setWikiBlockEditDrafts((prev) => ({
            ...prev,
            [blockKey]: fallbackMarkdown,
        }));
        setWikiBlockEditingKey(blockKey);
    };

    const cancelWikiBlockEdit = (blockKey) => {
        setWikiBlockEditDrafts((prev) => {
            const next = { ...prev };
            delete next[blockKey];
            return next;
        });
        setWikiBlockEditingKey((current) => (current === blockKey ? null : current));
    };

    const saveWikiBlockEdit = (blockKey) => {
        if (wikiBlockSaveProcessingKey !== null) {
            return;
        }

        const nextMarkdown = String(wikiBlockEditDrafts[blockKey] ?? '').trim();
        const runId = Number(manualBlockEdit?.run_id ?? 0);
        const updateUrl = manualBlockEditUrl(targetClaimId);

        if (!updateUrl || !runId || !current_version?.id || !targetClaimId) {
            setWikiBlockEditError('Lagring mangler gyldig Wiki-kontekst. Last inn funnet på nytt fra Kjøringer.');
            return;
        }

        if (!nextMarkdown) {
            setWikiBlockEditError('Tekstblokken kan ikke være tom.');
            return;
        }

        setWikiBlockEditError(null);
        setWikiBlockSaveProcessingKey(blockKey);
        router.patch(
            updateUrl,
            {
                run_id: runId,
                expected_page_version_id: current_version.id,
                blocks: [
                    {
                        block_key: blockKey,
                        markdown: nextMarkdown,
                    },
                ],
                back_url: reviewReference?.back_url ?? undefined,
            },
            {
                preserveScroll: true,
                onError: (formErrors) => {
                    const firstBlockError = Object.entries(formErrors).find(([key]) => key.startsWith('blocks.'))?.[1];
                    setWikiBlockEditError(
                        formErrors.blocks
                        ?? firstBlockError
                        ?? formErrors.expected_page_version_id
                        ?? formErrors.run_id
                        ?? 'Tekstendringen kunne ikke lagres. Kontroller teksten og prøv igjen.',
                    );
                },
                onSuccess: () => {
                    setWikiBlockEditDrafts({});
                    setWikiBlockEditingKey(null);
                    setWikiBlockEditError(null);
                },
                onFinish: () => setWikiBlockSaveProcessingKey(null),
            },
        );
    };

    const linkOrphanConceptCandidate = (targetPageId) => {
        if (linkingCandidateId !== null || !structureFinding?.id || !current_version?.id) {
            return;
        }

        setLinkingCandidateId(targetPageId);
        router.patch(
            `/app/wiki/${page.slug}/structure-findings/${structureFinding.id}/link-target`,
            {
                target_page_id: targetPageId,
                expected_page_version_id: current_version.id,
                back_url: structureFinding?.back_url ?? undefined,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setLinkingCandidateId(null);
                    setConfirmingCandidateId(null);
                },
            },
        );
    };

    useEffect(() => {
        if (!targetClaimId || hasFocusedReview) {
            return undefined;
        }

        setVerificationOpen(true);
        setVerifiedClaimsOpen(true);
        setStructuralFindingsOpen(true);

        const targetElement = document.getElementById(`claim-${targetClaimId}`);

        if (!targetElement) {
            return undefined;
        }

        const frame = window.requestAnimationFrame(() => {
            targetElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
            targetElement.focus({ preventScroll: true });
        });

        return () => window.cancelAnimationFrame(frame);
    }, [targetClaimId, hasFocusedReview, page.slug, claims.length, verificationOpen, verifiedClaimsOpen, structuralFindingsOpen]);

    useEffect(() => {
        if (!targetBlockKey) {
            return undefined;
        }

        const targetElement = document.getElementById(`wiki-block-${targetBlockKey}`);

        if (!targetElement) {
            return undefined;
        }

        const frame = window.requestAnimationFrame(() => {
            targetElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
            targetElement.focus({ preventScroll: true });
        });

        return () => window.cancelAnimationFrame(frame);
    }, [targetBlockKey, page.slug, current_version?.id]);

    useEffect(() => {
        if (!hasStructureFinding) {
            return undefined;
        }

        const targetElement = document.getElementById(`structure-finding-${structureFinding.id}`);

        if (!targetElement) {
            return undefined;
        }

        const frame = window.requestAnimationFrame(() => {
            targetElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
            targetElement.focus?.({ preventScroll: true });
        });

        return () => window.cancelAnimationFrame(frame);
    }, [hasStructureFinding, structureFinding?.id, page.slug]);

    const { claimFindings: claimLintFindings, structuralFindings } = splitWikiVerificationFindings(lintFindings);
    const structuralFindingGroups = groupWikiFindingsByCode(structuralFindings);
    // A claim still needs a human decision. Unchanged predicate — only how it is applied changed.
    const claimRequiresAction = (claim) => (
        claim.approval_status === 'pending'
        && (
            claim.content_origin === 'best_practice'
            || claim.content_origin === 'internal_error'
            || claim.content_origin === 'unsupported_generated_content'
            || claim.conflict_flag
            || claim.source_status === 'missing_source'
            || claim.source_status === 'missing_excerpt'
        )
    );
    const openClaims = verificationClaims.filter(claimRequiresAction);
    const verifiedClaims = verificationClaims.filter((claim) => !openClaims.includes(claim));

    const sendAction = (action) => {
        if (processing) return;
        setProcessing(action);
        router.patch(`/app/wiki/${page.slug}/${action}`, {}, {
            onFinish: () => setProcessing(null),
        });
    };

    const approveClaim = (claim) => {
        if (claimProcessing) return;
        setClaimProcessing(claim.id);
        const approvedText = claim.content_origin === 'best_practice'
            ? String(claimTextEdits[claim.id] ?? claim.claim_text ?? '').trim()
            : '';
        router.patch(
            `/app/wiki/${page.slug}/claims/${claim.id}/approve`,
            {
                comment: approvalComments[claim.id] || undefined,
                approved_text: approvedText || undefined,
            },
            { onFinish: () => setClaimProcessing(null) },
        );
    };

    const linkClaimSource = (claim) => {
        if (claimSourceProcessing) return;

        const draft = claimSourceDrafts[claim.id] ?? {};
        const sourceDocumentId = Number(draft.source_document_id || 0);
        const sourceElementKey = String(draft.source_element_key || '').trim();
        const sourceElementType = String(draft.source_element_type || '').trim();
        const sourceRowKey = String(draft.source_row_key || '').trim();
        const excerpt = String(draft.excerpt || '').trim();
        const pageReference = String(draft.page_reference || '').trim();

        if (!sourceDocumentId) {
            return;
        }

        const payload = {
            source_document_id: sourceDocumentId,
        };

        if (sourceElementKey && sourceElementType) {
            payload.source_element_key = sourceElementKey;
            payload.source_element_type = sourceElementType;
            if (sourceRowKey) {
                payload.source_row_key = sourceRowKey;
            }
        } else {
            if (!excerpt) {
                return;
            }

            payload.source_element_type = 'manual';
            payload.excerpt = excerpt;
            if (pageReference) {
                payload.page_reference = pageReference;
            }
        }

        setClaimSourceProcessing(claim.id);
        router.post(
            `/app/wiki/${page.slug}/claims/${claim.id}/source-references`,
            payload,
            {
                preserveScroll: true,
                onSuccess: () => {
                    setClaimSourceDrafts((prev) => {
                        const next = { ...prev };
                        delete next[claim.id];
                        return next;
                    });
                },
                onFinish: () => setClaimSourceProcessing(null),
            },
        );
    };

    const loadClaimSourceElements = async (claim, documentId) => {
        const sourceDocumentId = Number(documentId || 0);

        if (!sourceDocumentId || claimSourceCatalog[sourceDocumentId]?.loading || claimSourceCatalog[sourceDocumentId]?.loaded) {
            return;
        }

        setClaimSourceCatalog((prev) => ({
            ...prev,
            [sourceDocumentId]: {
                ...(prev[sourceDocumentId] ?? {}),
                loading: true,
                error: null,
            },
        }));

        try {
            const response = await fetch(
                `/app/wiki/${page.slug}/claims/${claim.id}/source-documents/${sourceDocumentId}/elements`,
                {
                    headers: {
                        Accept: 'application/json',
                    },
                },
            );

            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload?.message || 'Kunne ikke hente kildeelementer.');
            }

            setClaimSourceCatalog((prev) => ({
                ...prev,
                [sourceDocumentId]: {
                    loading: false,
                    loaded: true,
                    ...payload,
                },
            }));
        } catch (error) {
            setClaimSourceCatalog((prev) => ({
                ...prev,
                [sourceDocumentId]: {
                    ...(prev[sourceDocumentId] ?? {}),
                    loading: false,
                    loaded: false,
                    error: error instanceof Error ? error.message : 'Kunne ikke hente kildeelementer.',
                    elements: [],
                },
            }));
        }
    };

    const selectClaimSourceElement = (claim, documentId, element) => {
        setClaimSourceDrafts((prev) => {
            const current = prev[claim.id] ?? {};

            return {
                ...prev,
                [claim.id]: {
                    ...current,
                    source_document_id: documentId,
                    source_element_key: element.source_element_key,
                    source_element_type: element.source_element_type,
                    source_row_key: element.source_row_key ?? '',
                    excerpt: element.reference_text ?? '',
                    page_reference: element.page_reference ?? '',
                    source_element_search: current.source_element_search ?? '',
                },
            };
        });
    };

    const rejectClaim = (claim) => {
        if (claimProcessing) return;
        setClaimProcessing(claim.id);
        router.patch(
            `/app/wiki/${page.slug}/claims/${claim.id}/reject`,
            { comment: approvalComments[claim.id] || undefined },
            { onFinish: () => setClaimProcessing(null) },
        );
    };

    const unapproveClaim = (claim) => {
        if (claimProcessing) return;
        setClaimProcessing(claim.id);
        router.patch(
            `/app/wiki/${page.slug}/claims/${claim.id}/unapprove`,
            {},
            { onFinish: () => setClaimProcessing(null) },
        );
    };

    const updateClaimBlocking = (claim, blocking) => {
        if (claimProcessing) return;
        setClaimProcessing(claim.id);
        router.patch(
            `/app/wiki/${page.slug}/claims/${claim.id}/blocking`,
            { blocking, comment: approvalComments[claim.id] || undefined },
            { onFinish: () => setClaimProcessing(null) },
        );
    };

    const approveDocumentOwnerApproval = (approval) => {
        if (documentOwnerApprovalProcessing) return;
        setDocumentOwnerApprovalProcessing(approval.id);
        router.patch(
            `/app/wiki/${page.slug}/document-owner-approvals/${approval.id}/approve`,
            { comment: documentOwnerApprovalComments[approval.id] || undefined },
            { onFinish: () => setDocumentOwnerApprovalProcessing(null) },
        );
    };

    const rejectDocumentOwnerApproval = (approval) => {
        if (documentOwnerApprovalProcessing) return;
        setDocumentOwnerApprovalProcessing(approval.id);
        router.patch(
            `/app/wiki/${page.slug}/document-owner-approvals/${approval.id}/reject`,
            { comment: documentOwnerApprovalComments[approval.id] || undefined },
            { onFinish: () => setDocumentOwnerApprovalProcessing(null) },
        );
    };

    const claimSourceStatusLabel = (status) => ({
        source_found: tw.claim_source_status_found ?? 'Kildegrunnlag funnet',
        missing_excerpt: tw.claim_source_status_missing_excerpt ?? 'Kildegrunnlag mangler utdrag',
        manually_approved: tw.claim_source_status_manually_approved ?? 'Manuelt godkjent uten kildereferanse',
        rejected: tw.claim_source_status_rejected ?? 'Avvist uten kildereferanse',
        missing_source: tw.claim_source_status_missing_source ?? 'Mangler kildereferanse',
        best_practice_review: tw.claim_source_status_best_practice_review ?? 'Forslag basert på beste praksis',
        internal_generation_error: tw.claim_source_status_internal_error ?? 'Genereringsfeil',
        unsupported_generated_content: tw.claim_source_status_unsupported_generated_content ?? 'Ikke-støttet generert innhold',
    }[status] ?? status);

    const sourceTypeLabel = (type) => ({
        enterprise_wiki_document: tw.source_type_enterprise_wiki_document ?? 'Kildedokument',
        knowledge_item_version: tw.source_type_knowledge_item_version ?? 'Kunnskapsbaseversjon',
        saved_notice_document: tw.source_type_saved_notice_document ?? 'Lagret kunngjøringsdokument',
        doffin_notice: tw.source_type_doffin_notice ?? 'Doffin-kunngjøring',
        manual: tw.source_type_manual ?? 'Manuell kilde',
    }[type] ?? type);

    const sourceElementTypeLabel = (type) => ({
        paragraph: tw.source_element_type_paragraph ?? 'Avsnitt',
        list_item: tw.source_element_type_list_item ?? 'Listepunkt',
        table_row: tw.source_element_type_table_row ?? 'Tabellrad',
        manual: tw.source_element_type_manual ?? 'Manuelt kilderutdrag',
    }[type] ?? type);

    const actionLabel = tw.action_confirming ?? 'Behandler...';

    const pageStatusLabel = (status) => ({
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

    const confidenceLabel = (c) => ({
        high: tw.confidence_high ?? 'Høy sikkerhet',
        medium: tw.confidence_medium ?? 'Medium',
        low: tw.confidence_low ?? 'Lav',
        uncertain: tw.confidence_uncertain ?? 'Usikker',
    }[c] ?? c);

    const claimStatusLabel = (s) => ({
        pending: tw.claim_status_pending ?? 'Venter på manuell vurdering',
        approved: tw.claim_status_approved ?? 'Godkjent',
        rejected: tw.claim_status_rejected ?? 'Avvist',
    }[s] ?? s);

    // Mirrors EnterpriseWikiRunFindingsService's own id construction ('claim-defect-'.$claim->id /
    // 'best-practice-'.$primary->id) so the SAME stable id shown in the Kjøringer "Funn" panel is
    // shown here too — reconstructed client-side from data already present on the claim
    // (content_origin, id) rather than requiring a backend prop change. Returns null for a claim
    // that is not itself a Funn-panel finding category (e.g. a plain source_based claim).
    const findingIdForClaim = (claim) => {
        if (claim.content_origin === 'best_practice') return `best-practice-${claim.id}`;
        if (claim.content_origin === 'internal_error' || claim.content_origin === 'unsupported_generated_content') return `claim-defect-${claim.id}`;

        return null;
    };

    const claimProblemLabel = (claim) => {
        if (claim.content_origin === 'internal_error' || claim.content_origin === 'unsupported_generated_content') {
            return tw.claim_finding_no_source_excerpt ?? 'Systemet fant ingen sikker kildetekst for denne påstanden.';
        }

        if (claim.finding_recommended_action) {
            return claim.finding_recommended_action;
        }

        if (claim.content_origin === 'best_practice') {
            return claim.review_reason
                || tw.verification_basis_best_practice_reason_fallback
                || 'Innholdet er et forslag basert på beste praksis og må vurderes før det kan brukes som godkjent materiale.';
        }

        if (claim.conflict_flag) {
            return tw.verification_basis_claim_problem_conflict ?? 'Påstanden er markert som mulig konflikt.';
        }

        return ({
            missing_source: tw.verification_basis_claim_problem_missing_source ?? 'Systemet fant ikke et kildeavsnitt som dokumenterer påstanden. Koble en kilde dersom påstanden kan dokumenteres, eller avvis den.',
            missing_excerpt: tw.verification_basis_claim_problem_missing_excerpt ?? 'Kilden er funnet, men mangler tekstutdrag.',
        }[claim.source_status]
            ?? tw.claim_finding_action_possible_content_deviation
            ?? 'Sammenlign påstanden med kildeteksten. Avviket er ikke bekreftet — vurder om det er reelt før du bestemmer deg.');
    };

    const renderClaimCard = (claim, group, options = {}) => {
        const { showClaimText = true } = options;
        const isInlineReview = options.inlineReview === true;
        const isOpenGroup = group === 'open';
        const problemLabel = isOpenGroup ? claimProblemLabel(claim) : '';
        const isPendingDecision = claim.approval_status === 'pending';
        const showDecisionBadge = claim.approval_status !== 'pending';
        const sourceDraft = claimSourceDrafts[claim.id] ?? {};
        // The reviewer edits (and approves) the whole block: WikiClaimController::
        // applyBestPracticeTextEdit() replaces the BLOCK's markdown with whatever is submitted
        // here. Seeding this from claim_text was therefore lossy for any block that produced more
        // than one claim — approving would have replaced the entire block with the single
        // fragment this card happened to represent. Seed from the block's own markdown instead,
        // falling back to claim_text when the block is no longer present in the current version.
        const claimBlockKey = typeof claim.content_block_key === 'string' ? claim.content_block_key.trim() : '';
        const claimSection = claimBlockKey !== '' ? resolveBestPracticeSectionForBlock(contentBlocks, claimBlockKey) : null;
        const claimBlockMarkdown = claimSection !== null
            ? claimSection.markdown
            : (claimBlockKey !== ''
                ? getWikiBlockRawMarkdown(contentBlocks.find((block) => block.block_key === claimBlockKey) ?? {})
                : '');
        const claimEditBaseline = claim.content_origin === 'best_practice' && claimBlockMarkdown.trim() !== ''
            ? claimBlockMarkdown
            : (claim.claim_text ?? '');
        const claimTextEdit = claimTextEdits[claim.id] ?? claimEditBaseline;
        const groupedClaimCount = options.claimCount ?? 1;
        const sourceReferences = claim.source_references ?? [];
        const hasSourceReferences = sourceReferences.length > 0;
        const canHandleClaim = claim.can_handle ?? false;
        const isBestPracticeClaim = claim.content_origin === 'best_practice';
        const isClaimDefect = claim.content_origin === 'internal_error' || claim.content_origin === 'unsupported_generated_content';
        const hasUserDecision = claim.user_decision && claim.user_decision !== 'pending';
        const selectedSourceDocument = sourceDocuments.find((doc) => String(doc.id) === String(sourceDraft.source_document_id)) ?? null;
        const selectedSourceCatalog = selectedSourceDocument ? (claimSourceCatalog[selectedSourceDocument.id] ?? null) : null;
        const selectedSourceElements = selectedSourceCatalog?.elements ?? [];
        const sourceElementSearch = String(sourceDraft.source_element_search || '').trim().toLowerCase();
        const filteredSourceElements = sourceElementSearch
            ? selectedSourceElements.filter((element) => {
                const haystack = [
                    element.source_element_key,
                    element.source_element_type,
                    element.source_row_key,
                    element.page_reference,
                    element.display_text,
                    element.reference_text,
                ]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase();

                return haystack.includes(sourceElementSearch);
            })
            : selectedSourceElements;
        const canLinkSource = canHandleClaim && !isBestPracticeClaim && claim.source_status === 'missing_source';

        if (isInlineReview) {
            return (
                <article
                    key={claim.id}
                    id={`claim-${claim.id}`}
                    tabIndex={-1}
                    className="scroll-mt-24 border-l-4 border-violet-300 bg-violet-50/30 px-4 py-3"
                >
                    <div className="space-y-3">
                        <div className="space-y-1.5">
                            <p className="text-base font-semibold uppercase tracking-wide text-violet-700">
                                {tw.review_reference_inline_heading ?? 'Vurdering for dette avsnittet'}
                            </p>
                            {findingIdForClaim(claim) && (
                                <p className="font-mono text-sm font-semibold text-slate-600">
                                    {(tw.runs_findings_id_label ?? 'Funn #:id').replace(':id', formatFindingUserId(findingIdForClaim(claim)))}
                                </p>
                            )}
                            <p className="text-lg font-semibold text-slate-900">
                                {claim.finding_category_label ?? (tw.claim_finding_category_possible_content_deviation ?? 'Mulig innholdsavvik')}
                            </p>
                            <p className="text-base leading-7 text-slate-700">
                                {problemLabel}
                            </p>
                            {claim.system_recommends_blocking && (
                                <p className="text-base font-medium text-amber-700">
                                    {tw.claim_finding_system_recommends_blocking ?? 'Systemet anbefaler blokkering'}
                                </p>
                            )}
                            {hasUserDecision && (
                                <p className={`text-base font-medium ${
                                    claim.user_decision === 'blocking'
                                        ? 'text-rose-700'
                                        : claim.user_decision === 'not_blocking'
                                            ? 'text-amber-700'
                                            : 'text-slate-600'
                                }`}>
                                    {claim.user_decision === 'blocking'
                                        ? (tw.claim_finding_user_decision_blocking ?? 'Bruker har valgt: Blokkerer godkjenning')
                                        : claim.user_decision === 'not_blocking'
                                            ? (tw.claim_finding_user_decision_not_blocking ?? 'Bruker har valgt: Godkjenn avvik / ikke blokker')
                                            : (tw.claim_finding_user_decision_pending ?? 'Avventer vurdering')}
                                </p>
                            )}
                        </div>

                        {isInlineReview && canHandleClaim && canEditWikiClaims && targetBlockKey && (() => {
                            const targetBlock = contentBlocks.find((block) => block.block_key === targetBlockKey) ?? null;

                            return targetBlock?.content_origin === 'mixed'
                                && manualBlockEdit?.run_id
                                && wikiBlockEditingKey !== targetBlockKey;
                        })() && (
                            <div className="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => startWikiBlockEdit(
                                        targetBlockKey,
                                        getWikiBlockRawMarkdown(contentBlocks.find((block) => block.block_key === targetBlockKey) ?? {}),
                                    )}
                                    className="rounded-full border border-slate-300 bg-white px-4 py-2 text-base font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
                                >
                                    Rediger teksten
                                </button>
                            </div>
                        )}

                        {canHandleClaim && isPendingDecision && (
                            <div className="space-y-3 rounded-xl border border-slate-100 bg-white/80 px-3 py-3">
                                {claim.content_origin === 'best_practice' && (
                                    <label className="block space-y-1">
                                        <span className="text-base font-semibold text-amber-700">
                                            {tw.verification_basis_best_practice_edit_label ?? 'Rediger og godkjenn'}
                                        </span>
                                        <textarea
                                            rows={3}
                                            maxLength={4000}
                                            value={claimTextEdit}
                                            onChange={(e) => setClaimTextEdits((prev) => ({
                                                ...prev,
                                                [claim.id]: e.target.value,
                                            }))}
                                            className="w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-base text-slate-700 focus:border-amber-400 focus:outline-none"
                                        />
                                    </label>
                                )}

                                <div className="flex flex-wrap gap-2">
                                    <input
                                        type="text"
                                        maxLength={1000}
                                        placeholder={tw.approval_comment_placeholder ?? 'Valgfri kommentar'}
                                        value={approvalComments[claim.id] ?? ''}
                                        onChange={(e) => setApprovalComments((prev) => ({ ...prev, [claim.id]: e.target.value }))}
                                        className="min-w-50 flex-1 rounded-lg border border-slate-200 px-3 py-2 text-base text-slate-700 focus:border-violet-300 focus:outline-none"
                                    />
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        disabled={claimProcessing === claim.id}
                                        onClick={() => approveClaim(claim)}
                                        className="rounded-full bg-emerald-600 px-4 py-2 text-base font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-50"
                                    >
                                        Behold teksten
                                    </button>
                                    <button
                                        type="button"
                                        disabled={claimProcessing === claim.id}
                                        onClick={() => rejectClaim(claim)}
                                        className="rounded-full border border-rose-200 bg-white px-4 py-2 text-base font-semibold text-rose-700 transition hover:bg-rose-50 disabled:opacity-50"
                                    >
                                        Fjern teksten
                                    </button>
                                </div>
                            </div>
                        )}

                        {hasUserDecision && canHandleClaim && (
                            <div className="space-y-2 rounded-xl border border-slate-100 bg-white/80 px-3 py-3">
                                {claim.blocking_override_by_name && (
                                    <p className="text-base text-slate-600">
                                        {(tw.claim_blocking_reason_overridden ?? 'Overstyrt av :name den :date.')
                                            .replace(':name', claim.blocking_override_by_name ?? '—')
                                            .replace(':date', claim.blocking_override_at ? new Date(claim.blocking_override_at).toLocaleString(locale) : '')}
                                    </p>
                                )}
                                <div className="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        disabled={claimProcessing === claim.id || claim.user_decision === 'blocking'}
                                        onClick={() => updateClaimBlocking(claim, true)}
                                        className={`rounded-full px-4 py-2 text-base font-semibold transition disabled:opacity-50 ${
                                            claim.user_decision === 'blocking'
                                                ? 'bg-rose-600 text-white'
                                                : 'border border-rose-300 bg-white text-rose-700 hover:bg-rose-50'
                                        }`}
                                    >
                                        {tw.keep_blocking_button ?? 'Blokker godkjenning'}
                                    </button>
                                    <button
                                        type="button"
                                        disabled={claimProcessing === claim.id || claim.user_decision === 'not_blocking'}
                                        onClick={() => updateClaimBlocking(claim, false)}
                                        className={`rounded-full px-4 py-2 text-base font-semibold transition disabled:opacity-50 ${
                                            claim.user_decision === 'not_blocking'
                                                ? 'border border-amber-400 bg-amber-100 text-amber-900'
                                                : 'border border-amber-300 bg-white text-amber-800 hover:bg-amber-50'
                                        }`}
                                    >
                                        {tw.remove_blocking_button ?? 'Godkjenn avvik / Ikke blokker'}
                                    </button>
                                </div>
                            </div>
                        )}

                        {canHandleClaim && !claim.user_decision && !isPendingDecision && (
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-100 bg-white/80 px-3 py-3">
                                <span className="text-sm text-slate-600">
                                    {(tw.claim_decided_by_at ?? 'Besluttet av :name den :date')
                                        .replace(':name', claim.approved_by_name ?? '—')
                                        .replace(':date', claim.approved_at ? new Date(claim.approved_at).toLocaleString(locale) : '')}
                                    {claim.approval_comment ? ` — “${claim.approval_comment}”` : ''}
                                </span>
                                <button
                                    type="button"
                                    disabled={claimProcessing === claim.id}
                                    onClick={() => unapproveClaim(claim)}
                                    className="rounded-full border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 disabled:opacity-50"
                                >
                                    {tw.undo_claim_decision_button ?? tw.unapprove_claim_button ?? 'Angre beslutning'}
                                </button>
                            </div>
                        )}

                        {!canHandleClaim && (
                            <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-base leading-7 text-slate-600">
                                {claimAccessNotice}
                            </div>
                        )}
                    </div>
                </article>
            );
        }

        return (
            <article
                key={claim.id}
                id={`claim-${claim.id}`}
                tabIndex={-1}
                className={`scroll-mt-24 rounded-[18px] border ${showClaimText ? 'border-slate-200 bg-white p-5 shadow-[0_4px_14px_rgba(15,23,42,0.04)]' : 'border-violet-100 bg-white/95 p-4 shadow-sm'}`}
            >
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                        {findingIdForClaim(claim) && (
                            <p className="font-mono text-sm font-semibold text-slate-600">
                                {(tw.runs_findings_id_label ?? 'Funn #:id').replace(':id', formatFindingUserId(findingIdForClaim(claim)))}
                            </p>
                        )}
                        {showClaimText && (
                            <p className="text-[15px] leading-7 text-slate-900">{claim.claim_text}</p>
                        )}
                        {showClaimText && claim.page_excerpt && claim.page_excerpt !== claim.claim_text && (
                            <p className="mt-2 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-xs leading-5 text-slate-500">
                                {tw.verification_basis_page_excerpt_label ?? 'Tekst i Wiki-siden'}: {claim.page_excerpt}
                            </p>
                        )}

                        <div className="mt-3 flex flex-wrap items-center gap-2">
                            {showDecisionBadge && (
                                <Badge
                                    label={claimStatusLabel(claim.approval_status)}
                                    cls={CLAIM_STATUS_STYLES[claim.approval_status] ?? 'bg-slate-200 text-slate-500'}
                                />
                            )}
                            {claim.confidence && claim.confidence !== 'uncertain' && claim.content_origin === 'source_based' && (
                                <Badge
                                    label={confidenceLabel(claim.confidence)}
                                    cls={CONFIDENCE_STYLES[claim.confidence] ?? 'bg-slate-200 text-slate-500'}
                                />
                            )}
                            {isBestPracticeClaim && (
                                <Badge
                                    label={tw.verification_basis_best_practice_badge ?? 'Beste praksis'}
                                    cls="bg-amber-100 text-amber-700"
                                />
                            )}
                            {groupedClaimCount > 1 && (
                                <Badge
                                    label={(tw.verification_basis_block_claim_count ?? 'Samlet vurdering · :count påstander')
                                        .replace(':count', groupedClaimCount)}
                                    cls="bg-slate-100 text-slate-600"
                                />
                            )}
                            {claim.finding_category_label && (
                                <Badge
                                    label={claim.finding_category_label}
                                    cls={FINDING_CATEGORY_STYLES[claim.finding_category] ?? 'bg-slate-200 text-slate-600'}
                                />
                            )}
                            {claim.system_recommends_blocking && (
                                <Badge
                                    label={tw.claim_finding_system_recommends_blocking ?? 'Systemet anbefaler blokkering'}
                                    cls="bg-amber-100 text-amber-700"
                                />
                            )}
                            {hasUserDecision && (
                                <Badge
                                    label={claim.user_decision === 'blocking'
                                        ? (tw.claim_finding_user_decision_blocking ?? 'Bruker har valgt: Blokkerer godkjenning')
                                        : (tw.claim_finding_user_decision_not_blocking ?? 'Bruker har valgt: Godkjenn avvik / ikke blokker')}
                                    cls={claim.user_decision === 'blocking'
                                        ? 'bg-rose-100 text-rose-700'
                                        : 'bg-amber-100 text-amber-700'}
                                />
                            )}
                            {claim.conflict_flag && (
                                <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-semibold text-rose-600">
                                    <WarnIcon className="h-3 w-3" />
                                    {tw.conflict_detected ?? 'Mulig konflikt'}
                                </span>
                            )}
                            <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold ${CLAIM_SOURCE_STATUS_STYLES[claim.source_status] ?? 'bg-slate-200 text-slate-500'}`}>
                                {SOURCE_STATUS_WARNS.has(claim.source_status) ? (
                                    <WarnIcon className="h-3 w-3" />
                                ) : (
                                    <CheckIcon className="h-3 w-3" />
                                )}
                                {claimSourceStatusLabel(claim.source_status)}
                            </span>
                        </div>
                    </div>
                </div>

                {problemLabel && (
                    <p className="mt-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                        {problemLabel}
                    </p>
                )}

                {claim.finding_recommended_action && (
                    <p className="mt-2 text-xs leading-5 text-slate-500">
                        {claim.finding_recommended_action}
                    </p>
                )}

                {hasUserDecision && (
                    <div className="mt-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
                        {claim.blocking_override_by_name && (
                            <p className="text-base text-slate-500">
                                {(tw.claim_blocking_reason_overridden ?? 'Overstyrt av :name den :date.')
                                    .replace(':name', claim.blocking_override_by_name ?? '—')
                                    .replace(':date', claim.blocking_override_at ? new Date(claim.blocking_override_at).toLocaleString(locale) : '')}
                            </p>
                        )}
                        {canHandleClaim && (
                            <div className="mt-1.5 space-y-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        disabled={claimProcessing === claim.id || claim.user_decision === 'blocking'}
                                        onClick={() => updateClaimBlocking(claim, true)}
                                        className={`rounded-full px-3 py-1.5 text-base font-semibold transition disabled:opacity-50 ${
                                            claim.user_decision === 'blocking'
                                                ? 'bg-rose-600 text-white'
                                                : 'border border-rose-300 bg-white text-rose-700 hover:bg-rose-50'
                                        }`}
                                    >
                                        {tw.keep_blocking_button ?? 'Blokker godkjenning'}
                                    </button>
                                    <button
                                        type="button"
                                        disabled={claimProcessing === claim.id || claim.user_decision === 'not_blocking'}
                                        onClick={() => updateClaimBlocking(claim, false)}
                                        className={`rounded-full px-3 py-1.5 text-base font-semibold transition disabled:opacity-50 ${
                                            claim.user_decision === 'not_blocking'
                                                ? 'border border-amber-400 bg-amber-100 text-amber-900'
                                                : 'border border-amber-300 bg-white text-amber-800 hover:bg-amber-50'
                                        }`}
                                    >
                                        {tw.remove_blocking_button ?? 'Godkjenn avvik / Ikke blokker'}
                                    </button>
                                </div>
                                <p className="text-base leading-5 text-slate-500">
                                    <strong>{tw.keep_blocking_button ?? 'Blokker godkjenning'}:</strong>{' '}
                                    {tw.claim_blocking_consequence_block ?? 'Funnet hindrer endelig godkjenning til det er løst eller beslutningen endres.'}
                                    <br />
                                    <strong>{tw.remove_blocking_button ?? 'Godkjenn avvik / Ikke blokker'}:</strong>{' '}
                                    {tw.claim_blocking_consequence_not_block ?? 'Funnet beholdes i historikken, men hindrer ikke endelig godkjenning.'}
                                </p>
                            </div>
                        )}
                    </div>
                )}

                <div className="mt-4 space-y-3 border-t border-slate-100 pt-4">
                    <div className="space-y-1.5">
                        {hasSourceReferences && (
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {tw.verification_basis_claim_source_basis_heading ?? 'Kildegrunnlag'}
                            </p>
                        )}
                        {sourceReferences.length === 0 ? (
                            <div className="space-y-2">
                                {isClaimDefect && (
                                    <p className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs italic text-slate-500">
                                        {tw.claim_finding_no_source_excerpt ?? 'Systemet fant ingen sikker kildetekst for denne påstanden.'}
                                    </p>
                                )}
                                {isBestPracticeClaim && (
                                    <div className="space-y-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-3">
                                        <p className="text-sm leading-6 text-amber-800">
                                            {tw.verification_basis_best_practice_no_source_search ?? 'Dette er ikke presentert som dokumentert kundekunnskap. Vurder teksten faglig, eller avvis den. Du skal ikke lete etter en manglende kilde.'}
                                        </p>
                                        {canHandleClaim && isPendingDecision && (
                                            <label className="block space-y-1">
                                                <span className="text-[11px] font-semibold uppercase tracking-wide text-amber-700">
                                                    {tw.verification_basis_best_practice_edit_label ?? 'Rediger og godkjenn'}
                                                </span>
                                                <textarea
                                                    rows={3}
                                                    maxLength={4000}
                                                    value={claimTextEdit}
                                                    onChange={(e) => setClaimTextEdits((prev) => ({
                                                        ...prev,
                                                        [claim.id]: e.target.value,
                                                    }))}
                                                    className="w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-xs text-slate-700 focus:border-amber-400 focus:outline-none"
                                                />
                                            </label>
                                        )}
                                    </div>
                                )}

                                {canLinkSource && (
                                    <div className="rounded-xl border border-sky-200 bg-sky-50 px-3 py-3">
                                        <p className="text-xs font-semibold uppercase tracking-wide text-sky-700">
                                            {tw.claim_source_link_heading ?? 'Koble kilde'}
                                        </p>
                                        <p className="mt-1 text-xs leading-5 text-sky-800">
                                            {tw.claim_source_link_intro ?? 'Velg et kildedokument og lim inn utdraget som dokumenterer påstanden.'}
                                        </p>

                                        {sourceDocuments.length === 0 ? (
                                            <p className="mt-2 text-xs text-sky-700">
                                                {tw.claim_source_no_documents ?? 'Ingen kildedokumenter er tilgjengelige for denne kunden.'}
                                            </p>
                                        ) : (
                                            <div className="mt-3 space-y-3">
                                                <label className="block space-y-1">
                                                    <span className="text-[11px] font-semibold uppercase tracking-wide text-sky-700">
                                                        {tw.claim_source_document_label ?? 'Kildedokument'}
                                                    </span>
                                                    <select
                                                        value={sourceDraft.source_document_id ?? ''}
                                                        onChange={(e) => {
                                                            const nextDocumentId = e.target.value;

                                                            setClaimSourceDrafts((prev) => ({
                                                                ...prev,
                                                                [claim.id]: {
                                                                    ...sourceDraft,
                                                                    source_document_id: nextDocumentId,
                                                                    source_element_key: '',
                                                                    source_element_type: '',
                                                                    source_row_key: '',
                                                                    excerpt: '',
                                                                    page_reference: '',
                                                                    source_element_search: '',
                                                                },
                                                            }));

                                                            if (nextDocumentId) {
                                                                loadClaimSourceElements(claim, nextDocumentId);
                                                            }
                                                        }}
                                                        className="w-full rounded-lg border border-sky-200 bg-white px-3 py-2 text-xs text-slate-700 focus:border-sky-400 focus:outline-none"
                                                    >
                                                        <option value="">{tw.claim_source_document_placeholder ?? 'Velg et kildedokument'}</option>
                                                        {sourceDocuments.map((doc) => (
                                                            <option key={doc.id} value={doc.id}>
                                                                {doc.original_filename}
                                                                {doc.owner_name ? ` · ${doc.owner_name}` : ''}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </label>

                                                {selectedSourceDocument?.download_url && (
                                                    <a
                                                        href={selectedSourceDocument.download_url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="inline-flex items-center gap-1 text-[11px] font-medium text-sky-700 hover:text-sky-900 hover:underline"
                                                    >
                                                        <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                            <path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.614L6.295 8.235a.75.75 0 1 0-1.09 1.03l4.25 4.5a.75.75 0 0 0 1.09 0l4.25-4.5a.75.75 0 0 0-1.09-1.03l-2.955 3.129V2.75Z" />
                                                            <path d="M3.5 12.75a.75.75 0 0 0-1.5 0v2.5A2.75 2.75 0 0 0 4.75 18h10.5A2.75 2.75 0 0 0 18 15.25v-2.5a.75.75 0 0 0-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5Z" />
                                                        </svg>
                                                        {tw.claim_source_open_selected_document ?? (tw.source_open_document ?? 'Åpne original kilde')}
                                                    </a>
                                                )}

                                                {selectedSourceCatalog?.loading && (
                                                    <p className="text-xs text-sky-700">
                                                        {tw.action_confirming ?? 'Behandler...'}
                                                    </p>
                                                )}

                                                {selectedSourceCatalog?.error && (
                                                    <p className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                                                        {selectedSourceCatalog.error}
                                                    </p>
                                                )}

                                                {selectedSourceDocument && !selectedSourceCatalog && (
                                                    <p className="text-xs text-sky-700">
                                                        {tw.action_confirming ?? 'Behandler...'}
                                                    </p>
                                                )}

                                                {selectedSourceCatalog?.manual_source_allowed ? (
                                                    <>
                                                        <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                                            {selectedSourceCatalog.manual_source_reason ?? 'Dette dokumentet har ikke strukturerte kildeelementer. Bruk et manuelt kilderutdrag.'}
                                                        </p>
                                                        <label className="block space-y-1">
                                                            <span className="text-[11px] font-semibold uppercase tracking-wide text-sky-700">
                                                                {tw.claim_source_excerpt_label ?? 'Tekstutdrag'}
                                                            </span>
                                                            <textarea
                                                                rows={3}
                                                                maxLength={4000}
                                                                value={sourceDraft.excerpt ?? ''}
                                                                onChange={(e) => setClaimSourceDrafts((prev) => ({
                                                                    ...prev,
                                                                    [claim.id]: {
                                                                        ...sourceDraft,
                                                                        excerpt: e.target.value,
                                                                    },
                                                                }))}
                                                                className="w-full rounded-lg border border-sky-200 px-3 py-2 text-xs text-slate-700 focus:border-sky-400 focus:outline-none"
                                                                placeholder={tw.claim_source_excerpt_placeholder ?? 'Lim inn teksten som dokumenterer påstanden.'}
                                                            />
                                                        </label>

                                                        <label className="block space-y-1">
                                                            <span className="text-[11px] font-semibold uppercase tracking-wide text-sky-700">
                                                                {tw.claim_source_page_reference_label ?? (tw.source_page_reference ?? 'Plassering i kilden')}
                                                            </span>
                                                            <input
                                                                type="text"
                                                                maxLength={255}
                                                                value={sourceDraft.page_reference ?? ''}
                                                                onChange={(e) => setClaimSourceDrafts((prev) => ({
                                                                    ...prev,
                                                                    [claim.id]: {
                                                                        ...sourceDraft,
                                                                        page_reference: e.target.value,
                                                                    },
                                                                }))}
                                                                className="w-full rounded-lg border border-sky-200 px-3 py-2 text-xs text-slate-700 focus:border-sky-400 focus:outline-none"
                                                                placeholder={tw.claim_source_page_reference_placeholder ?? 'Valgfri plassering, for eksempel avsnitt, tabellrad eller sidetall'}
                                                            />
                                                        </label>
                                                    </>
                                                ) : selectedSourceCatalog ? (
                                                    <>
                                                        <label className="block space-y-1">
                                                            <span className="text-[11px] font-semibold uppercase tracking-wide text-sky-700">
                                                                {tw.claim_source_element_search_label ?? 'Søk i kilden'}
                                                            </span>
                                                            <input
                                                                type="search"
                                                                value={sourceDraft.source_element_search ?? ''}
                                                                onChange={(e) => setClaimSourceDrafts((prev) => ({
                                                                    ...prev,
                                                                    [claim.id]: {
                                                                        ...sourceDraft,
                                                                        source_element_search: e.target.value,
                                                                    },
                                                                }))}
                                                                className="w-full rounded-lg border border-sky-200 px-3 py-2 text-xs text-slate-700 focus:border-sky-400 focus:outline-none"
                                                                placeholder={tw.claim_source_element_search_placeholder ?? 'Søk etter avsnitt, listepunkt eller tabellrad'}
                                                            />
                                                        </label>

                                                        <div className="max-h-56 space-y-2 overflow-auto rounded-xl border border-sky-100 bg-white p-2">
                                                            {filteredSourceElements.length > 0 ? filteredSourceElements.map((element) => {
                                                                const isSelected = String(sourceDraft.source_element_key || '') === String(element.source_element_key || '')
                                                                    && String(sourceDraft.source_element_type || '') === String(element.source_element_type || '')
                                                                    && String(sourceDraft.source_row_key || '') === String(element.source_row_key || '');

                                                                return (
                                                                    <button
                                                                        type="button"
                                                                        key={`${element.source_element_type}:${element.source_element_key}`}
                                                                        onClick={() => selectClaimSourceElement(claim, selectedSourceDocument.id, element)}
                                                                        className={`w-full rounded-lg border px-3 py-2 text-left text-xs transition ${isSelected ? 'border-sky-400 bg-sky-50' : 'border-slate-200 bg-white hover:border-sky-200 hover:bg-sky-50'}`}
                                                                    >
                                                                        <div className="flex flex-wrap items-center gap-2">
                                                                            <span className="font-semibold text-slate-700">
                                                                                {sourceElementTypeLabel(element.source_element_type)}
                                                                            </span>
                                                                            <span className="text-slate-500">
                                                                                {element.page_reference ?? (tw.source_page_reference ?? 'Plassering i kilden')}
                                                                            </span>
                                                                        </div>
                                                                        <p className="mt-1 text-slate-600">
                                                                            {element.display_text ?? element.reference_text}
                                                                        </p>
                                                                    </button>
                                                                );
                                                            }) : (
                                                                <p className="px-2 py-3 text-xs italic text-slate-400">
                                                                    {tw.claim_source_no_element_match ?? 'Ingen kildeelementer matcher søket.'}
                                                                </p>
                                                            )}
                                                        </div>

                                                        {sourceDraft.source_element_key && (
                                                            <div className="rounded-xl border border-sky-200 bg-sky-50 px-3 py-3">
                                                                <p className="text-[11px] font-semibold uppercase tracking-wide text-sky-700">
                                                                    {tw.claim_source_selected_element ?? 'Valgt element'}
                                                                </p>
                                                                <p className="mt-1 text-xs text-sky-900">
                                                                    {selectedSourceElements.find((element) => String(element.source_element_key) === String(sourceDraft.source_element_key) && String(element.source_element_type) === String(sourceDraft.source_element_type))?.reference_text ?? ''}
                                                                </p>
                                                                <p className="mt-1 text-xs text-sky-700">
                                                                    {tw.claim_source_page_reference_label ?? (tw.source_page_reference ?? 'Plassering i kilden')}: {sourceDraft.page_reference || '—'}
                                                                </p>
                                                            </div>
                                                        )}
                                                    </>
                                                ) : null}

                                                <div className="flex flex-wrap gap-2">
                                                    <button
                                                        type="button"
                                                        disabled={
                                                            claimSourceProcessing === claim.id
                                                            || !sourceDraft.source_document_id
                                                            || (
                                                                selectedSourceCatalog
                                                                && selectedSourceCatalog.manual_source_allowed
                                                                && !String(sourceDraft.excerpt || '').trim()
                                                            )
                                                            || (
                                                                selectedSourceCatalog
                                                                && !selectedSourceCatalog.manual_source_allowed
                                                                && !String(sourceDraft.source_element_key || '').trim()
                                                            )
                                                        }
                                                        onClick={() => linkClaimSource(claim)}
                                                        className="rounded-full bg-sky-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-sky-700 disabled:opacity-50"
                                                    >
                                                        {claimSourceProcessing === claim.id ? (tw.action_confirming ?? 'Behandler...') : (tw.claim_source_link_submit ?? 'Koble kilde')}
                                                    </button>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                )}

                                {!canHandleClaim && !isBestPracticeClaim && (
                                    <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-600">
                                        {claimAccessNotice}
                                    </div>
                                )}
                            </div>
                        ) : (
                            <ul className="space-y-3">
                                {sourceReferences.map((ref) => (
                                    <li key={ref.id} className="space-y-1.5">
                                        <p className="text-base font-semibold text-slate-700">
                                            {ref.source_label}
                                        </p>
                                        {ref.source_type && (
                                            <p className="text-base text-slate-600">
                                                {tw.source_type ?? 'Kildetype'}: {sourceTypeLabel(ref.source_type)}
                                            </p>
                                        )}
                                        {ref.source_element_type && (
                                            <p className="text-base text-slate-600">
                                                {tw.source_element_type ?? 'Kildeelement'}: {sourceElementTypeLabel(ref.source_element_type)}
                                            </p>
                                        )}
                                        {ref.source_row_key && ref.source_row_key !== ref.source_element_key && (
                                            <p className="text-base text-slate-600">
                                                {tw.source_row_key ?? 'Radnøkkel'}: {ref.source_row_key}
                                            </p>
                                        )}
                                        {ref.page_reference && (
                                            <p className="text-base text-slate-600">
                                                {tw.source_page_reference ?? 'Plassering i kilden'}: {ref.page_reference}
                                            </p>
                                        )}
                                        {ref.excerpt ? (
                                            <p className="text-base leading-6 text-slate-600 line-clamp-3">
                                                {ref.excerpt}
                                            </p>
                                        ) : (
                                            <p className="text-base italic text-slate-600">
                                                {tw.source_no_excerpt ?? 'Ingen tekstutdrag tilgjengelig.'}
                                            </p>
                                        )}
                                        {ref.download_url && (
                                            <a
                                                href={ref.download_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex items-center gap-1 text-[11px] font-medium text-violet-600 hover:text-violet-800 hover:underline"
                                            >
                                                <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.614L6.295 8.235a.75.75 0 1 0-1.09 1.03l4.25 4.5a.75.75 0 0 0 1.09 0l4.25-4.5a.75.75 0 0 0-1.09-1.03l-2.955 3.129V2.75Z" />
                                                    <path d="M3.5 12.75a.75.75 0 0 0-1.5 0v2.5A2.75 2.75 0 0 0 4.75 18h10.5A2.75 2.75 0 0 0 18 15.25v-2.5a.75.75 0 0 0-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5Z" />
                                                </svg>
                                                {tw.source_open_document ?? 'Åpne original kilde'}
                                            </a>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    {canHandleClaim && isPendingDecision && (
                        <div className="flex flex-wrap items-center gap-2">
                            <input
                                type="text"
                                maxLength={1000}
                                placeholder={tw.approval_comment_placeholder ?? 'Valgfri kommentar'}
                                value={approvalComments[claim.id] ?? ''}
                                onChange={(e) => setApprovalComments((prev) => ({ ...prev, [claim.id]: e.target.value }))}
                                className="min-w-50 flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 focus:border-violet-300 focus:outline-none"
                            />
                            <button
                                type="button"
                                disabled={claimProcessing === claim.id}
                                onClick={() => approveClaim(claim)}
                                className="rounded-full bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-50"
                            >
                                Behold teksten
                            </button>
                            <button
                                type="button"
                                disabled={claimProcessing === claim.id}
                                onClick={() => rejectClaim(claim)}
                                className="rounded-full border border-rose-200 bg-white px-4 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-50 disabled:opacity-50"
                            >
                                Fjern teksten
                            </button>
                        </div>
                    )}

                    {!isPendingDecision && canHandleClaim && (
                        <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-sky-50 px-3 py-3 text-sm text-sky-700">
                            <span>
                                {(tw.claim_decided_by_at ?? 'Besluttet av :name den :date')
                                    .replace(':name', claim.approved_by_name ?? '—')
                                    .replace(':date', claim.approved_at ? new Date(claim.approved_at).toLocaleString(locale) : '')}
                                {claim.approval_comment ? ` — “${claim.approval_comment}”` : ''}
                            </span>
                            <button
                                type="button"
                                disabled={claimProcessing === claim.id}
                                onClick={() => unapproveClaim(claim)}
                                className="rounded-full border border-sky-300 bg-white px-3 py-2 text-sm font-semibold text-sky-700 transition hover:bg-sky-100 disabled:opacity-50"
                            >
                                {tw.undo_claim_decision_button ?? tw.unapprove_claim_button ?? 'Angre beslutning'}
                            </button>
                        </div>
                    )}

                    {!canHandleClaim && hasSourceReferences && (
                        <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-600">
                            {claimAccessNotice}
                        </div>
                    )}
                </div>
            </article>
        );
    };

    const documentOwnerApprovalCardClass = (status, isOverride) => ({
        approved: isOverride
            ? 'border-fuchsia-200 bg-fuchsia-50'
            : 'border-emerald-200 bg-emerald-50',
        rejected: 'border-rose-200 bg-rose-50',
        pending: 'border-amber-200 bg-amber-50',
    }[status] ?? 'border-slate-200 bg-slate-50');

    const documentOwnerApprovalSentence = (approval) => approval.summary_text ?? '';
    const pendingDocumentOwnerNames = documentOwnerApprovals
        .filter((approval) => approval.approval_status === 'pending')
        .map((approval) => approval.document_owner_name ?? (tw.document_owner_missing ?? 'Mangler Dokumenteier'));

    const isDraft = page.status === 'draft';
    const isPendingReview = page.status === 'pending_review';
    const isApproved = page.status === 'approved';

    // rendered_markdown has [[wikilinks]] pre-transformed into clickable internal links;
    // fall back to raw content_markdown for older payloads/tests that don't send it.
    const articleContent = current_version?.rendered_markdown ?? current_version?.content_markdown ?? null;
    const hasArticle = Boolean(articleContent?.trim());
    // Rendering each block individually (rather than the whole article as one string) is what
    // makes "scroll to and highlight the exact suggested text" (Del 3/4) possible — each block
    // gets a stable DOM anchor from its content_block_key. Falls back to the single-blob render
    // above for pages generated before block-level provenance existed (no content_blocks_json).
    const contentBlocks = Array.isArray(current_version?.content_blocks_json)
        ? [...current_version.content_blocks_json]
            .filter((block) => block && typeof block.markdown === 'string' && block.markdown.trim() !== '')
            .sort((a, b) => (a.position ?? 0) - (b.position ?? 0))
        : [];

    // The visual "Beste praksis" section that owns the focused review target, if any. When the
    // target block is part of a multi-block section, the review panel must render AFTER the whole
    // section (below) rather than from inside renderBlock() — placing it inside split the section's
    // own paragraphs apart and left everything after the first one outside the review.
    const targetReviewSection = resolveBestPracticeSectionForBlock(contentBlocks, targetBlockKey);

    // Product rule: best-practice content stops being flagged once the PAGE VERSION itself is
    // finally approved through the existing document owner approval — that is what makes the
    // addition settled agreement text. Until then it keeps its "Beste praksis" marking, whatever
    // the individual claim reviews say.
    const pageIsFinallyApproved = isPageVersionFinallyApproved(documentOwnerSummary);

    // Review units are partitioned ONCE, so a unit whose claims are partly approved and partly
    // pending is listed only under "krever behandling" — never simultaneously under "Verifiserte
    // påstander", which previously made settled text look like it still needed approving.
    const reviewUnits = partitionBestPracticeReviewUnits(verificationClaims, contentBlocks, claimRequiresAction);

    // Derive semantic traversal groups from outgoing links
    const summaryLinks = outgoingLinks.filter((p) => p.page_type === 'summary');
    const articleLinks = outgoingLinks.filter((p) => p.page_type === 'article');

    const hasAnyTraversal =
        summaryLinks.length > 0 ||
        articleLinks.length > 0 ||
        relatedConcepts.length > 0 ||
        relatedEntities.length > 0 ||
        relatedArticles.length > 0;

    return (
        <CustomerAppLayout title={page.title} showPageTitle={false}>
            <div className="space-y-8">

                {/* Back link */}
                <Link
                    href="/app/wiki"
                    className="inline-flex items-center gap-1.5 text-sm text-slate-500 transition hover:text-slate-950"
                >
                    <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fillRule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clipRule="evenodd" />
                    </svg>
                    {tw.back ?? 'Tilbake til Wiki'}
                </Link>

                {/* Page header */}
                <section className="space-y-3">
                    <div className="flex flex-wrap items-start gap-3">
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                            {page.title}
                        </h1>
                        <PageHelpButton
                            buttonLabel={tw.page_help_button ?? 'Hjelp'}
                            title={tw.show_page_help_title ?? 'Slik fungerer Wiki-siden'}
                            intro={tw.show_page_help_intro ?? 'Forklarer hvordan beste-praksis-forslag skiller seg fra kvalitetsfeil.'}
                            sections={getWikiShowHelpSections(tw)}
                        />
                        <div className="mt-1 flex flex-wrap gap-2">
                            {page.page_type && (
                                <Badge
                                    label={pageTypeLabel(page.page_type)}
                                    cls={PAGE_TYPE_STYLES[page.page_type] ?? 'bg-slate-200 text-slate-600'}
                                />
                            )}
                            <Badge
                                label={pageStatusLabel(page.status)}
                                cls={PAGE_STATUS_STYLES[page.status] ?? 'bg-slate-200 text-slate-600'}
                            />
                        </div>
                    </div>
                    {current_version && (
                        <p className="text-sm text-slate-400">
                            {tw.version ?? 'Versjon'} {current_version.version_number}
                        </p>
                    )}
                </section>

                {/* Draft / pending notice */}
                {(isDraft || isPendingReview) && (
                    <div className="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4">
                        <WarnIcon className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
                        <p className="text-sm text-amber-800">
                            {isPendingReview
                                ? (tw.pending_review_draft_notice ?? 'Denne siden er til gjennomgang. Innholdet er AI-generert.')
                                : (tw.draft_notice ?? 'Dette er et AI-generert utkast.')}
                        </p>
                    </div>
                )}

                {/* Approval actions */}
                {(() => {
                    const actionableForOwner = isSystemOwner && ['draft', 'pending_review', 'rejected'].includes(page.status);
                    const noticeForNonOwner = !isSystemOwner && page.status === 'pending_review';
                    if (!actionableForOwner && !noticeForNonOwner) return null;

                    return (
                        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_4px_14px_rgba(15,23,42,0.04)]">
                            {isSystemOwner && page.status === 'pending_review' && (
                                <div className="flex flex-wrap items-center gap-4">
                                    <p className="text-sm text-slate-600">
                                        {tw.pending_review_notice ?? 'Denne siden venter på godkjenning av System Owner.'}
                                    </p>
                                    <div className="flex gap-2">
                                        <button
                                            type="button"
                                            disabled={processing !== null}
                                            onClick={() => sendAction('approve')}
                                            className="inline-flex min-h-9 items-center justify-center rounded-full bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {processing === 'approve' ? actionLabel : (tw.approve_button ?? 'Godkjenn')}
                                        </button>
                                        <button
                                            type="button"
                                            disabled={processing !== null}
                                            onClick={() => sendAction('reject')}
                                            className="inline-flex min-h-9 items-center justify-center rounded-full border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {processing === 'reject' ? actionLabel : (tw.reject_button ?? 'Avvis')}
                                        </button>
                                    </div>
                                </div>
                            )}

                            {isSystemOwner && page.status === 'draft' && (
                                <button
                                    type="button"
                                    disabled={processing !== null}
                                    onClick={() => sendAction('submit')}
                                    className="inline-flex min-h-9 items-center justify-center rounded-full bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {processing === 'submit' ? actionLabel : (tw.submit_button ?? 'Send til gjennomgang')}
                                </button>
                            )}

                            {isSystemOwner && page.status === 'rejected' && (
                                <button
                                    type="button"
                                    disabled={processing !== null}
                                    onClick={() => sendAction('submit')}
                                    className="inline-flex min-h-9 items-center justify-center rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {processing === 'submit' ? actionLabel : (tw.reopen_button ?? 'Gjenåpne')}
                                </button>
                            )}

                            {noticeForNonOwner && (
                                <p className="text-sm text-amber-700">
                                    {tw.pending_review_notice ?? 'Denne siden venter på godkjenning av System Owner.'}
                                </p>
                            )}
                        </section>
                    );
                })()}

                {(reviewReference || hasStructureFinding) && (
                    <div>
                        <Link
                            href={backHref}
                            className="inline-flex items-center gap-1.5 text-sm font-medium text-violet-700 hover:text-violet-900 hover:underline"
                        >
                            <BackArrowIcon className="h-4 w-4" aria-hidden="true" />
                            {tw.review_reference_back_to_findings ?? 'Tilbake til funn'}
                        </Link>
                    </div>
                )}

                {hasStructureFinding && (
                    <StructureFindingContextPanel
                        finding={structureFinding}
                        tw={tw}
                        pageTypeLabel={pageTypeLabel}
                        outgoingLinks={outgoingLinks}
                        incomingLinks={incomingLinks}
                        backlinks={backlinks}
                        relatedArticles={relatedArticles}
                        relatedConcepts={relatedConcepts}
                        relatedEntities={relatedEntities}
                        onLinkCandidate={linkOrphanConceptCandidate}
                        linkingCandidateId={linkingCandidateId}
                        confirmingCandidateId={confirmingCandidateId}
                        onRequestConfirm={setConfirmingCandidateId}
                        onCancelConfirm={() => setConfirmingCandidateId(null)}
                    />
                )}

                {reviewReference?.status === 'superseded' && (
                    <div className="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4">
                        <WarnIcon className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
                        <p className="text-sm text-amber-800">
                            {(tw.review_reference_superseded ?? 'Forslaget gjelder en eldre sideversjon (versjon :version) og er ikke lenger aktuelt for gjeldende innhold.')
                                .replace(':version', reviewReference.version_number ?? '—')}
                        </p>
                    </div>
                )}

                {(reviewReference?.status === 'block_missing' || reviewReference?.status === 'not_found') && (
                    <div className="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4">
                        <WarnIcon className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
                        <div className="text-sm text-amber-800">
                            <p>
                                {reviewReference.status === 'block_missing'
                                    ? (tw.review_reference_block_missing ?? 'Teksten kunne ikke lokaliseres på Wiki-siden.')
                                    : (tw.review_reference_not_found ?? 'Teksten kunne ikke lokaliseres på Wiki-siden.')}
                            </p>
                            {isSystemOwner && reviewReference.technical_block_key && (
                                <p className="mt-1 text-xs text-amber-600">block_key: {reviewReference.technical_block_key}</p>
                            )}
                        </div>
                    </div>
                )}

                {reviewReference?.status === 'ready' && reviewReference.block_key === null && (
                    <div className="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4">
                        <WarnIcon className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
                        <p className="text-sm text-amber-800">
                            {tw.review_reference_no_confident_block ?? 'Systemet fant ingen sikker tekstblokk å markere for denne påstanden. Se hele siden under.'}
                        </p>
                    </div>
                )}

                {/* Article — primary content */}
                <section className="space-y-4">
                    {!isApproved && (
                        <div className="flex items-center gap-3">
                            <h2 className="text-base font-semibold text-slate-700">
                                {tw.article_heading ?? 'Artikkelutkast'}
                            </h2>
                            <span className="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-700">
                                {tw.article_ai_label ?? 'AI-generert'}
                            </span>
                        </div>
                    )}

                    {hasArticle ? (
                        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-[0_4px_14px_rgba(15,23,42,0.04)]">
                            <div className="wiki-article">
                                {(() => {
                                    // A single content block's own markup — table/image/editing/plain markdown.
                                    // A block never carries its own "Beste praksis" frame: the amber label and
                                    // background belong to the whole faglig seksjon and are drawn once, around the
                                    // section (see below), so no individual paragraph is highlighted on its own.
                                    const renderBlock = (block) => {
                                        const isTargetBlock = targetBlockKey !== null && block.block_key === targetBlockKey;
                                        const currentBlockMarkdown = getWikiBlockMarkdown(block);
                                        const currentBlockRawMarkdown = getWikiBlockRawMarkdown(block);
                                        const isEditingTargetBlock = wikiBlockEditingKey === block.block_key;
                                        const hasBestPracticeMetadataIssue = hasInvalidBestPracticeMetadata(block);
                                        const currentDraft = wikiBlockEditDrafts[block.block_key] ?? currentBlockRawMarkdown;
                                        const canSaveBlockEdit = Boolean(
                                            canEditWikiClaims
                                            && manualBlockEdit?.run_id
                                            && current_version?.id
                                            && targetClaimId
                                            && !wikiBlockSaveProcessingKey,
                                        );

                                        return (
                                            <div
                                                key={block.block_key}
                                                id={`wiki-block-${block.block_key}`}
                                                data-block-key={block.block_key}
                                                tabIndex={-1}
                                                // The focused-review target highlight marks the ONE thing the user
                                                // navigated to. When that block sits inside a best-practice section
                                                // the review unit is the whole section, so ringing a single paragraph
                                                // inside it contradicts the panel below ("gjelder hele teksten over")
                                                // and made the first sentence look separated from the rest. The
                                                // section's own frame plus that panel already locate the review.
                                                className={isTargetBlock && targetReviewSection === null
                                                    ? 'rounded-lg border border-amber-300 bg-amber-50/70 px-3 py-2 ring-2 ring-amber-200 transition-colors'
                                                    : undefined}
                                            >
                                                {block.block_type === 'table' ? (
                                                    <WikiTableBlock block={block} tw={tw} sourceDocuments={sourceDocuments} />
                                                ) : block.block_type === 'image' ? (
                                                    <WikiImageBlock block={block} tw={tw} sourceDocuments={sourceDocuments} />
                                                ) : isEditingTargetBlock ? (
                                                    <div className="space-y-3">
                                                        <label className="block space-y-2">
                                                            <span className="text-base font-semibold text-violet-700">
                                                                {tw.wiki_block_edit_label ?? 'Rediger teksten'}
                                                            </span>
                                                            <textarea
                                                                autoFocus
                                                                rows={10}
                                                                value={currentDraft}
                                                                onChange={(e) => setWikiBlockEditDrafts((prev) => ({
                                                                    ...prev,
                                                                    [block.block_key]: e.target.value,
                                                                }))}
                                                                className="w-full rounded-lg border border-violet-200 bg-white px-4 py-3 text-lg leading-8 text-slate-900 focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-200"
                                                            />
                                                        </label>
                                                        <div className="flex flex-wrap gap-2">
                                                            <button
                                                                type="button"
                                                                disabled={!canSaveBlockEdit}
                                                                onClick={() => saveWikiBlockEdit(block.block_key)}
                                                                className="rounded-full bg-violet-600 px-4 py-2 text-base font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                            >
                                                                {wikiBlockSaveProcessingKey === block.block_key ? 'Lagrer...' : 'Lagre endring'}
                                                            </button>
                                                            <button
                                                                type="button"
                                                                disabled={wikiBlockSaveProcessingKey === block.block_key}
                                                                onClick={() => cancelWikiBlockEdit(block.block_key)}
                                                                className="rounded-full border border-slate-300 bg-white px-4 py-2 text-base font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                            >
                                                                Avbryt
                                                            </button>
                                                        </div>
                                                        {wikiBlockValidationError && (
                                                            <p className="text-base text-rose-600">
                                                                {wikiBlockValidationError}
                                                            </p>
                                                        )}
                                                        {!manualBlockEdit?.run_id && (
                                                            <p className="text-base text-slate-500">
                                                                Lagring krever gyldig kjøringskontekst. Åpne funnet fra Kjøringer og prøv igjen.
                                                            </p>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <>
                                                        {hasBestPracticeMetadataIssue && (
                                                            <div className="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">
                                                                {tw.wiki_best_practice_metadata_missing ?? 'Beste-praksis-metadata mangler. Blokken merkes ikke som beste praksis før lagret opprinnelse og begrunnelse er konsistente.'}
                                                            </div>
                                                        )}
                                                        <ReactMarkdown components={{ a: WikiArticleLink }}>{currentBlockMarkdown}</ReactMarkdown>
                                                    </>
                                                )}
                                                {isTargetBlock && targetReviewSection === null && focusedReviewClaims.length > 0 && (
                                                    <div className="mt-4 rounded-2xl border border-violet-200 bg-violet-50/60 px-4 py-4 shadow-[0_4px_14px_rgba(15,23,42,0.04)]">
                                                        <div className="space-y-1">
                                                            <p className="text-base font-semibold text-violet-700">
                                                                {tw.review_reference_inline_heading ?? 'Vurdering for dette avsnittet'}
                                                            </p>
                                                            <p className="text-base text-slate-600">
                                                                {tw.review_reference_inline_subtitle ?? 'Handlingene under gjelder teksten over.'}
                                                            </p>
                                                        </div>
                                                        <div className="mt-4 space-y-3">
                                                            {groupBestPracticeClaimsForReview(focusedReviewClaims, contentBlocks).map((unit) => renderClaimCard(
                                                                unit.claim,
                                                                unit.claim.approval_status === 'pending' ? 'open' : 'verified',
                                                                { showClaimText: false, inlineReview: true, claimCount: unit.claimCount },
                                                            ))}
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    };

                                    if (contentBlocks.length === 0) {
                                        return <ReactMarkdown components={{ a: WikiArticleLink }}>{articleContent}</ReactMarkdown>;
                                    }

                                    // Server-computed section_key (EnterpriseWikiBestPracticeSectionService, via
                                    // WikiController::renderedContentBlocks()) is grouped here purely by "consecutive
                                    // blocks sharing the same key" — the heading/level detection itself is never
                                    // re-derived on the frontend (Del 3: "ikke dupliser logikken").
                                    return groupContentBlocksBySection(contentBlocks).map((group) => {
                                        if (group.type === 'single') {
                                            return renderBlock(group.block);
                                        }

                                        return (
                                            <div
                                                key={`section-${group.sectionKey}`}
                                                className={pageIsFinallyApproved
                                                    ? 'space-y-3'
                                                    : 'space-y-3 rounded-xl border border-amber-200 bg-amber-50/60 px-4 py-3'}
                                            >
                                                {!pageIsFinallyApproved && (
                                                    <p className="text-xs font-semibold uppercase tracking-wide text-amber-700">
                                                        {tw.wiki_best_practice_section_label ?? 'Beste praksis'}
                                                    </p>
                                                )}
                                                {group.blocks.map((block) => renderBlock(block))}
                                                {targetReviewSection?.sectionKey === group.sectionKey && focusedReviewClaims.length > 0 && (
                                                    <div className="mt-4 rounded-2xl border border-violet-200 bg-violet-50/60 px-4 py-4 shadow-[0_4px_14px_rgba(15,23,42,0.04)]">
                                                        <div className="space-y-1">
                                                            <p className="text-base font-semibold text-violet-700">
                                                                {tw.review_reference_section_heading ?? 'Vurdering for denne seksjonen'}
                                                            </p>
                                                            <p className="text-base text-slate-600">
                                                                {tw.review_reference_section_subtitle ?? 'Handlingene under gjelder hele teksten over.'}
                                                            </p>
                                                        </div>
                                                        <div className="mt-4 space-y-3">
                                                            {groupBestPracticeClaimsForReview(focusedReviewClaims, contentBlocks).map((unit) => renderClaimCard(
                                                                unit.claim,
                                                                unit.claim.approval_status === 'pending' ? 'open' : 'verified',
                                                                { showClaimText: false, inlineReview: true, claimCount: unit.claimCount },
                                                            ))}
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    });
                                })()}
                            </div>
                        </div>
                    ) : (
                        <p className="text-sm text-slate-400">
                            {tw.article_empty ?? 'Ingen artikkelinnhold generert ennå.'}
                        </p>
                    )}
                </section>

                {current_version && documentOwnerApprovals.length === 0 && documentOwnerSummary?.state === 'qa_review_open' && (
                    <section className="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                        <p className="text-sm font-medium text-slate-700">
                            {tw.document_owner_qa_review_open_message ?? 'Wiki-siden er tilgjengelig og kan brukes. Systemet har notert noen faglige punkter til frivillig QA-gjennomgang.'}
                        </p>
                    </section>
                )}

                {current_version && documentOwnerApprovals.length > 0 && (
                    <section className="space-y-3">
                        <div className="flex flex-wrap items-center gap-3">
                            <h2 className="text-base font-semibold text-slate-700">
                                Dokumenteiergodkjenning
                            </h2>
                        </div>

                        {documentOwnerApprovalSummary?.summary_text && (
                            <p className={`text-sm font-medium ${documentOwnerApprovalSummary.ready ? 'text-emerald-700' : 'text-slate-600'}`}>
                                {documentOwnerApprovalSummary.summary_text}
                            </p>
                        )}

                        {documentOwnerApprovalSummary?.total > 0 && (
                            <div className="flex flex-wrap gap-x-3 gap-y-1 text-sm font-medium text-slate-600">
                                <p>
                                    {(tw.document_owner_approval_received_count ?? ':approved av :total godkjenninger mottatt')
                                        .replace(':approved', documentOwnerApprovalSummary.approved ?? 0)
                                        .replace(':total', documentOwnerApprovalSummary.total ?? 0)}
                                </p>
                                {pendingDocumentOwnerNames.length > 0 && (
                                    <p>
                                        {(tw.document_owner_approval_missing_prefix ?? 'Mangler')}:{' '}
                                        {pendingDocumentOwnerNames.join(', ')}
                                    </p>
                                )}
                            </div>
                        )}

                        <div className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_4px_14px_rgba(15,23,42,0.04)]">
                            {documentOwnerApprovals.map((approval) => (
                                <article key={approval.id} className={`rounded-xl border p-4 ${documentOwnerApprovalCardClass(approval.approval_status, approval.is_override)}`}>
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="space-y-2">
                                            <p className="text-sm font-semibold text-slate-900">
                                                {documentOwnerApprovalSentence(approval)}
                                            </p>

                                            <div className="space-y-1.5 text-xs text-slate-600">
                                                <p>
                                                    {tw.document_owner_label ?? 'Dokumenteier'}: {approval.document_owner_name ?? (tw.document_owner_missing ?? 'Mangler Dokumenteier')}
                                                    {approval.document_owner_email ? <span className="text-slate-500"> · {approval.document_owner_email}</span> : null}
                                                </p>

                                                {approval.source_documents?.length > 1 && (
                                                    <div className="flex flex-wrap gap-2">
                                                        {approval.source_documents.map((doc) => (
                                                            <span key={doc.id} className="rounded-full bg-white px-2.5 py-0.5 font-medium text-slate-600 ring-1 ring-slate-200">
                                                                {doc.original_filename}
                                                            </span>
                                                        ))}
                                                    </div>
                                                )}

                                                {approval.decided_at && (
                                                    <p>
                                                        {tw.document_owner_decision_date ?? 'Beslutningsdato'}: {new Date(approval.decided_at).toLocaleString(locale, {
                                                            dateStyle: 'medium',
                                                            timeStyle: 'short',
                                                        })}
                                                    </p>
                                                )}

                                                {approval.decided_by_name && approval.decided_by_name !== approval.document_owner_name && (
                                                    <p>
                                                        {approval.is_override
                                                            ? (tw.document_owner_overridden_by ?? 'Overstyrt av')
                                                            : (tw.document_owner_decided_by ?? 'Beslutning tatt av')}{' '}
                                                        {approval.decided_by_name}
                                                    </p>
                                                )}

                                                {approval.approval_comment && (
                                                    <p>{approval.approval_comment}</p>
                                                )}

                                                {approval.override_reason && (
                                                    <p>{tw.document_owner_override_reason ?? 'Overstyringsgrunn'}: {approval.override_reason}</p>
                                                )}
                                            </div>
                                        </div>

                                        {approval.can_decide && approval.approval_status === 'pending' && (
                                            <div className="flex flex-wrap gap-2">
                                                <input
                                                    type="text"
                                                    maxLength={2000}
                                                    placeholder="Valgfri kommentar"
                                                    value={documentOwnerApprovalComments[approval.id] ?? ''}
                                                    onChange={(e) => setDocumentOwnerApprovalComments((prev) => ({ ...prev, [approval.id]: e.target.value }))}
                                                    className="min-w-56 flex-1 rounded-lg border border-slate-200 px-3 py-1.5 text-xs text-slate-700 focus:border-violet-300 focus:outline-none"
                                                />
                                                <button
                                                    type="button"
                                                    disabled={documentOwnerApprovalProcessing === approval.id}
                                                    onClick={() => approveDocumentOwnerApproval(approval)}
                                                    className="rounded-full bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-50"
                                                >
                                                    {documentOwnerApprovalProcessing === approval.id
                                                        ? 'Behandler...'
                                                        : (tw.document_owner_approval_approve_button ?? 'Godkjenn dokument')}
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled={documentOwnerApprovalProcessing === approval.id}
                                                    onClick={() => rejectDocumentOwnerApproval(approval)}
                                                    className="rounded-full border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 disabled:opacity-50"
                                                >
                                                    Avvis
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                </article>
                            ))}
                        </div>
                    </section>
                )}

                {/* Backlinks — pages that link to this one via a canonical inline wikilink */}
                <section className="space-y-3">
                    <h2 className="text-base font-semibold text-slate-700">
                        {tw.backlinks_heading ?? 'Lenket fra'}
                    </h2>

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_4px_14px_rgba(15,23,42,0.04)]">
                        {backlinks.length > 0 ? (
                            <LinkedPageList pages={backlinks} label={tw.backlinks_label ?? 'Sider'} />
                        ) : (
                            <p className="text-sm text-slate-400">
                                {tw.backlinks_empty ?? 'Ingen andre sider lenker hit ennå.'}
                            </p>
                        )}
                    </div>
                </section>

                {/* Traversal / navigation */}
                <section className="space-y-3">
                    <h2 className="text-base font-semibold text-slate-700">
                        {tw.traversal_heading ?? 'Navigasjon'}
                    </h2>

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_4px_14px_rgba(15,23,42,0.04)]">
                        {hasAnyTraversal ? (
                            <div className="space-y-3">
                                <LinkedPageList
                                    pages={summaryLinks}
                                    label={tw.traversal_summary_link ?? 'Sammendrag'}
                                />
                                <LinkedPageList
                                    pages={articleLinks}
                                    label={tw.traversal_article_link ?? 'Kildeartikkel'}
                                />
                                <LinkedPageList
                                    pages={relatedConcepts}
                                    label={tw.traversal_related_concepts ?? 'Konsepter'}
                                />
                                <LinkedPageList
                                    pages={relatedEntities}
                                    label={tw.traversal_related_entities ?? 'Entiteter'}
                                />
                                <LinkedPageList
                                    pages={relatedArticles}
                                    label={tw.traversal_related_articles ?? 'Relaterte artikler'}
                                />
                            </div>
                        ) : (
                            <p className="text-sm text-slate-400">
                                {tw.traversal_no_links ?? 'Ingen lenker registrert ennå.'}
                            </p>
                        )}
                    </div>
                </section>

                {/* Verification — secondary, collapsible */}
                <section className="space-y-3">
                    <button
                        type="button"
                        onClick={() => setVerificationOpen((v) => !v)}
                        className="flex items-center gap-2 text-sm font-semibold text-slate-500 transition-colors hover:text-slate-700"
                    >
                        <ChevronIcon open={verificationOpen} />
                        {tw.verification_heading ?? 'Verifikasjonsgrunnlag'}
                        {openClaims.length > 0 && (
                            <span className="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-normal text-rose-700">
                                {openClaims.length} {tw.verification_basis_open_heading ?? 'Påstander som krever behandling'}
                            </span>
                        )}
                        {verifiedClaims.length > 0 && (
                            <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-normal text-emerald-700">
                                {verifiedClaims.length} {tw.verification_basis_verified_heading ?? 'Verifiserte påstander'}
                            </span>
                        )}
                        {structuralFindings.length > 0 && (
                            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500">
                                {structuralFindings.length} {tw.verification_basis_structural_heading ?? 'Problem med sidestrukturen'}
                            </span>
                        )}
                    </button>

                    {verificationOpen && (
                        <div className="space-y-5">
                            <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_4px_14px_rgba(15,23,42,0.04)]">
                                <p className="text-sm leading-6 text-slate-600">
                                    {claimAccessNotice}
                                </p>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <Badge
                                        label={`${openClaims.length} ${tw.verification_basis_open_heading ?? 'Påstander som krever behandling'}`}
                                        cls="bg-rose-100 text-rose-700"
                                    />
                                    <Badge
                                        label={`${verifiedClaims.length} ${tw.verification_basis_verified_heading ?? 'Verifiserte påstander'}`}
                                        cls="bg-emerald-100 text-emerald-700"
                                    />
                                    <Badge
                                        label={`${structuralFindings.length} ${tw.verification_basis_structural_heading ?? 'Problem med sidestrukturen'}`}
                                        cls="bg-slate-100 text-slate-600"
                                    />
                                </div>
                                {claimLintFindings.length > 0 && (
                                    <p className="mt-3 text-xs text-slate-500">
                                        {tw.verification_basis_claim_findings_note ?? 'Påstandsrelaterte kontrollfunn vises direkte i påstandskortene nedenfor.'}
                                    </p>
                                )}
                            </div>

                            <section className="space-y-3">
                                <div className="space-y-1">
                                    <h3 className="text-sm font-semibold text-slate-700">
                                        {tw.verification_basis_open_heading ?? 'Påstander som krever behandling'}
                                        <span className="ml-2 rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-700">
                                            {openClaims.length}
                                        </span>
                                    </h3>
                                    <p className="text-sm text-slate-500">
                                        {claimAccessNotice}
                                    </p>
                                </div>

                                {!current_version ? (
                                    <p className="text-sm text-slate-400">{tw.no_version ?? 'Ingen aktiv versjon tilgjengelig.'}</p>
                                ) : openClaims.length === 0 ? (
                                    <p className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">
                                        {tw.verification_basis_no_open_claims ?? 'Alle påstander som krever behandling, er ferdigbehandlet.'}
                                    </p>
                                ) : (
                                    <div className="space-y-4">
                                        {reviewUnits.open.map((unit) => renderClaimCard(unit.claim, 'open', { claimCount: unit.claimCount }))}
                                    </div>
                                )}
                            </section>

                            <section className="space-y-3">
                                <button
                                    type="button"
                                    onClick={() => setVerifiedClaimsOpen((v) => !v)}
                                    className="flex items-center gap-2 text-sm font-semibold text-slate-500 transition-colors hover:text-slate-700"
                                >
                                    <ChevronIcon open={verifiedClaimsOpen} />
                                    {tw.verification_basis_verified_heading ?? 'Verifiserte påstander'}
                                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500">
                                        {verifiedClaims.length}
                                    </span>
                                </button>

                                {verifiedClaimsOpen && (
                                    <div className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_4px_14px_rgba(15,23,42,0.04)]">
                                        {verifiedClaims.length === 0 ? (
                                            <p className="text-sm text-slate-400">
                                                {tw.verification_basis_no_verified_claims ?? 'Ingen verifiserte påstander ennå.'}
                                            </p>
                                        ) : (
                                            <div className="space-y-4">
                                                {reviewUnits.verified.map((unit) => renderClaimCard(unit.claim, 'verified', { claimCount: unit.claimCount }))}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </section>

                            <section className="space-y-3">
                                <button
                                    type="button"
                                    onClick={() => setStructuralFindingsOpen((v) => !v)}
                                    className="flex items-center gap-2 text-sm font-semibold text-slate-500 transition-colors hover:text-slate-700"
                                >
                                    <ChevronIcon open={structuralFindingsOpen} />
                                    {tw.verification_basis_structural_heading ?? 'Problem med sidestrukturen'}
                                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500">
                                        {structuralFindings.length}
                                    </span>
                                </button>

                                {structuralFindingsOpen && (
                                    <div className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_4px_14px_rgba(15,23,42,0.04)]">
                                        <p className="text-sm text-slate-500">
                                            {tw.verification_basis_structural_intro ?? 'Disse funnene gjelder struktur og lenker på Wiki-siden, ikke den enkelte påstanden.'}
                                        </p>

                                        {structuralFindingGroups.length === 0 ? (
                                            <p className="text-sm text-slate-400">
                                                {tw.verification_basis_no_structural_findings ?? 'Ingen strukturelle funn på denne siden.'}
                                            </p>
                                        ) : (
                                            <div className="space-y-2">
                                                {structuralFindingGroups.map((group) => {
                                                    const first = group.findings[0];
                                                    const checkCopy = getWikiQualityCheckCopy(first.code, tw);

                                                    return (
                                                        <article key={first.code} className="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                                                            <div className="flex items-start gap-3">
                                                                <span className={`mt-0.5 inline-flex shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold ${LINT_SEVERITY_STYLES[first.severity] ?? 'bg-slate-100 text-slate-600'}`}>
                                                                    {first.severity === 'error'
                                                                        ? (tw.lint_severity_error ?? 'Feil')
                                                                        : first.severity === 'warning'
                                                                            ? (tw.lint_severity_warning ?? 'Advarsel')
                                                                            : (tw.lint_severity_info ?? 'Info')}
                                                                </span>
                                                                <div className="min-w-0 flex-1">
                                                                    <div className="flex flex-wrap items-center gap-2">
                                                                        <p className="text-sm font-semibold text-slate-700">
                                                                            {checkCopy.label}
                                                                        </p>
                                                                        <span className="rounded-full bg-white px-2 py-0.5 text-xs font-medium text-slate-500 ring-1 ring-slate-200">
                                                                            ×{group.findings.length}
                                                                        </span>
                                                                    </div>
                                                                    {checkCopy.unknown && (
                                                                        <p className="font-mono text-[11px] text-slate-400">{first.code}</p>
                                                                    )}
                                                                    <p className="mt-1 text-sm text-slate-600">
                                                                        {checkCopy.description || first.message}
                                                                    </p>
                                                                </div>
                                                            </div>
                                                        </article>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </section>
                        </div>
                    )}
                </section>

            </div>
        </CustomerAppLayout>
    );
}
