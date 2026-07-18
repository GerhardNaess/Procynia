import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ReactMarkdown from 'react-markdown';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

const PAGE_STATUS_STYLES = {
    approved: 'bg-emerald-100 text-emerald-700',
    pending_review: 'bg-amber-100 text-amber-700',
    draft: 'bg-slate-200 text-slate-600',
    rejected: 'bg-rose-100 text-rose-700',
    archived: 'bg-slate-200 text-slate-500',
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
    uncertain: 'bg-slate-200 text-slate-500',
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
    missing_source: 'bg-rose-100 text-rose-700',
};

const SOURCE_STATUS_WARNS = new Set(['missing_excerpt', 'missing_source']);

const HIGH_VOLUME_THRESHOLD = 100;

function Badge({ label, cls }) {
    return (
        <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${cls}`}>
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
    info: 'bg-slate-100 text-slate-600',
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

export default function WikiShow({
    page,
    current_version,
    claims,
    claim_summary: claimSummary = null,
    document_owner_approvals: documentOwnerApprovals = [],
    document_owner_approval_summary: documentOwnerApprovalSummary = null,
    lint_findings: lintFindings = [],
    lint_summary: lintSummary = null,
    outgoing_links: outgoingLinks = [],
    related_articles: relatedArticles = [],
    related_concepts: relatedConcepts = [],
    related_entities: relatedEntities = [],
    backlinks = [],
}) {
    const { translations = {}, auth = {} } = usePage().props;
    const tw = translations?.wiki ?? {};
    const isSystemOwner = auth.user?.is_system_owner ?? false;
    // Claim approve/undo uses its own permission (System Owner, or QA + effective access to
    // approve_wiki_claims) — separate from whole-page approve/reject, which stays System
    // Owner-only. See User::canApproveWikiClaims().
    const canApproveWikiClaims = auth.user?.can_approve_wiki_claims ?? false;

    const [processing, setProcessing] = useState(null);
    const [verificationOpen, setVerificationOpen] = useState(false);
    const [claimProcessing, setClaimProcessing] = useState(null);
    const [approvalComments, setApprovalComments] = useState({});
    const [documentOwnerApprovalComments, setDocumentOwnerApprovalComments] = useState({});
    const [documentOwnerApprovalProcessing, setDocumentOwnerApprovalProcessing] = useState(null);
    const [expandedLintGroups, setExpandedLintGroups] = useState({});
    const targetClaimId = typeof window !== 'undefined'
        ? new URLSearchParams(window.location.search).get('claim_id')
        : null;

    useEffect(() => {
        if (!targetClaimId) {
            return undefined;
        }

        const targetElement = document.getElementById(`claim-${targetClaimId}`);

        if (!targetElement) {
            return undefined;
        }

        const frame = window.requestAnimationFrame(() => {
            targetElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
            targetElement.focus({ preventScroll: true });
        });

        return () => window.cancelAnimationFrame(frame);
    }, [targetClaimId, page.slug, claims.length]);

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
        router.patch(
            `/app/wiki/${page.slug}/claims/${claim.id}/approve`,
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

    const sourceStatusLabel = (status) => ({
        source_found: tw.quality_source_found ?? 'Kilde funnet',
        missing_excerpt: tw.quality_missing_excerpt ?? 'Mangler utdrag',
        manually_approved: tw.quality_manually_approved ?? 'Manuelt godkjent',
        missing_source: tw.quality_no_source ?? 'Mangler kilde',
    }[status] ?? status);

    const lintGroups = Object.values(
        lintFindings.reduce((acc, f) => {
            (acc[f.code] ??= []).push(f);
            return acc;
        }, {}),
    );

    const collapsedLintLabel = (code, count, sampleMessage) => {
        if (code === 'claim_missing_source') {
            return (tw.lint_group_claim_missing_source ?? ':count påstander mangler kilde').replace(':count', count);
        }
        return (tw.lint_group_generic ?? ':message (×:count)')
            .replace(':message', sampleMessage)
            .replace(':count', count);
    };

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
        high: tw.confidence_high ?? 'Høy',
        medium: tw.confidence_medium ?? 'Medium',
        low: tw.confidence_low ?? 'Lav',
        uncertain: tw.confidence_uncertain ?? 'Usikker',
    }[c] ?? c);

    const claimStatusLabel = (s) => ({
        pending: tw.claim_status_pending ?? 'Venter',
        approved: tw.claim_status_approved ?? 'Godkjent',
        rejected: tw.claim_status_rejected ?? 'Avvist',
    }[s] ?? s);

    const documentOwnerApprovalStatusLabel = (s) => ({
        pending: 'Venter på Dokumenteier',
        approved: 'Dokumenteier godkjent',
        rejected: 'Dokumenteier avvist',
    }[s] ?? s);

    const isDraft = page.status === 'draft';
    const isPendingReview = page.status === 'pending_review';
    const isApproved = page.status === 'approved';

    // rendered_markdown has [[wikilinks]] pre-transformed into clickable internal links;
    // fall back to raw content_markdown for older payloads/tests that don't send it.
    const articleContent = current_version?.rendered_markdown ?? current_version?.content_markdown ?? null;
    const hasArticle = Boolean(articleContent?.trim());

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
                                ? (tw.pending_review_draft_notice ?? 'Denne siden er til gjennomgang. Innholdet er AI-generert og ikke kvalitetssikret.')
                                : (tw.draft_notice ?? 'Dette er et AI-generert utkast. Innholdet er ikke kvalitetssikret.')}
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
                                <ReactMarkdown components={{ a: WikiArticleLink }}>{articleContent}</ReactMarkdown>
                            </div>
                        </div>
                    ) : (
                        <p className="text-sm text-slate-400">
                            {tw.article_empty ?? 'Ingen artikkelinnhold generert ennå.'}
                        </p>
                    )}
                </section>

                {current_version && documentOwnerApprovals.length > 0 && (
                    <section className="space-y-3">
                        <div className="flex flex-wrap items-center gap-3">
                            <h2 className="text-base font-semibold text-slate-700">
                                Dokumenteiergodkjenning
                            </h2>
                            <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${documentOwnerApprovalSummary?.ready ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}`}>
                                {documentOwnerApprovalSummary?.ready ? 'Klar' : 'Avventer'}
                            </span>
                            {documentOwnerApprovalSummary?.missing_owner > 0 && (
                                <span className="rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-700">
                                    {documentOwnerApprovalSummary.missing_owner} uten eier
                                </span>
                            )}
                            {documentOwnerApprovalSummary?.pending > 0 && (
                                <span className="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-700">
                                    {documentOwnerApprovalSummary.pending} venter
                                </span>
                            )}
                            {documentOwnerApprovalSummary?.approved > 0 && (
                                <span className="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">
                                    {documentOwnerApprovalSummary.approved} godkjent
                                </span>
                            )}
                            {documentOwnerApprovalSummary?.rejected > 0 && (
                                <span className="rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-700">
                                    {documentOwnerApprovalSummary.rejected} avvist
                                </span>
                            )}
                        </div>

                        {documentOwnerApprovalSummary?.message && (
                            <p className="text-sm text-amber-700">
                                {documentOwnerApprovalSummary.message}
                            </p>
                        )}

                        <div className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_4px_14px_rgba(15,23,42,0.04)]">
                            {documentOwnerApprovals.map((approval) => (
                                <article key={approval.id} className="rounded-xl border border-slate-100 bg-slate-50 p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="space-y-2">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge
                                                    label={documentOwnerApprovalStatusLabel(approval.approval_status)}
                                                    cls={
                                                        approval.approval_status === 'approved'
                                                            ? 'bg-emerald-100 text-emerald-700'
                                                            : approval.approval_status === 'rejected'
                                                                ? 'bg-rose-100 text-rose-700'
                                                                : 'bg-amber-100 text-amber-700'
                                                    }
                                                />
                                                {approval.is_override && (
                                                    <span className="rounded-full bg-fuchsia-100 px-2.5 py-0.5 text-xs font-semibold text-fuchsia-700">
                                                        System Owner-override
                                                    </span>
                                                )}
                                            </div>

                                            <p className="text-sm font-semibold text-slate-800">
                                                {approval.document_owner_name ?? 'Kildedokument mangler Dokumenteier'}
                                                {approval.document_owner_email ? <span className="font-normal text-slate-500"> · {approval.document_owner_email}</span> : null}
                                            </p>

                                            <div className="space-y-1 text-xs text-slate-500">
                                                {approval.source_documents?.length > 0 && (
                                                    <div className="flex flex-wrap gap-2">
                                                        {approval.source_documents.map((doc) => (
                                                            <span key={doc.id} className="rounded-full bg-white px-2.5 py-0.5 font-medium text-slate-600 ring-1 ring-slate-200">
                                                                {doc.original_filename}
                                                            </span>
                                                        ))}
                                                    </div>
                                                )}
                                                {approval.approval_comment && (
                                                    <p>{approval.approval_comment}</p>
                                                )}
                                                {approval.decided_by_name && approval.decided_at && (
                                                    <p>
                                                        Beslutning av {approval.decided_by_name} {approval.is_override ? 'som System Owner' : ''}
                                                    </p>
                                                )}
                                                {approval.override_reason && (
                                                    <p>Overstyringsgrunn: {approval.override_reason}</p>
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
                                                    {documentOwnerApprovalProcessing === approval.id ? 'Behandler...' : 'Godkjenn'}
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
                        {claimSummary && claimSummary.total > 0 && (
                            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500">
                                {claimSummary.total} {tw.claims ?? 'påstander'}
                            </span>
                        )}
                        {lintSummary && lintSummary.total === 0 && (
                            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">
                                <CheckIcon className="h-3 w-3" />
                                {tw.lint_summary_ok ?? 'OK'}
                            </span>
                        )}
                        {lintSummary && lintSummary.error > 0 && (
                            <span className="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700">
                                <WarnIcon className="h-3 w-3" />
                                {lintSummary.error}
                            </span>
                        )}
                        {lintSummary && lintSummary.warning > 0 && (
                            <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">
                                <WarnIcon className="h-3 w-3" />
                                {lintSummary.warning}
                            </span>
                        )}
                    </button>

                    {verificationOpen && (
                        <div className="space-y-4">
                            {lintFindings.length > 0 && (
                                <div className="space-y-2">
                                    <p className="text-sm font-semibold text-slate-600">
                                        {tw.lint_findings_heading ?? 'Helsekontroll'}
                                    </p>
                                    <ul className="space-y-2">
                                        {lintGroups.map((group) => {
                                            const first = group[0];
                                            const severityBadge = (
                                                <span className={`mt-0.5 inline-flex shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold ${LINT_SEVERITY_STYLES[first.severity] ?? 'bg-slate-100 text-slate-600'}`}>
                                                    {first.severity === 'error'
                                                        ? (tw.lint_severity_error ?? 'Feil')
                                                        : first.severity === 'warning'
                                                            ? (tw.lint_severity_warning ?? 'Advarsel')
                                                            : (tw.lint_severity_info ?? 'Info')}
                                                </span>
                                            );

                                            if (group.length === 1) {
                                                return (
                                                    <li
                                                        key={first.id}
                                                        className="flex items-start gap-3 rounded-xl border border-slate-100 bg-white px-4 py-3"
                                                    >
                                                        {severityBadge}
                                                        <p className="text-sm text-slate-700">{first.message}</p>
                                                    </li>
                                                );
                                            }

                                            const isExpanded = expandedLintGroups[first.code] ?? false;

                                            return (
                                                <li
                                                    key={first.code}
                                                    className="rounded-xl border border-slate-100 bg-white px-4 py-3"
                                                >
                                                    <div className="flex items-start gap-3">
                                                        {severityBadge}
                                                        <button
                                                            type="button"
                                                            onClick={() => setExpandedLintGroups((prev) => ({ ...prev, [first.code]: !isExpanded }))}
                                                            className="flex-1 text-left text-sm text-slate-700 hover:underline"
                                                        >
                                                            {collapsedLintLabel(first.code, group.length, first.message)}
                                                            <span className="ml-1 text-xs text-slate-400">
                                                                ({isExpanded ? (tw.lint_group_hide_details ?? 'Skjul detaljer') : (tw.lint_group_show_details ?? 'Vis detaljer')})
                                                            </span>
                                                        </button>
                                                    </div>
                                                    {isExpanded && (
                                                        <ul className="mt-2 space-y-1 pl-8 text-xs text-slate-500">
                                                            {group.map((f) => (
                                                                <li key={f.id}>{f.message}</li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </div>
                            )}

                            {!current_version ? (
                                <p className="text-sm text-slate-400">{tw.no_version ?? 'Ingen aktiv versjon tilgjengelig.'}</p>
                            ) : claims.length === 0 ? (
                                <p className="text-sm text-slate-400">{tw.no_claims ?? 'Ingen påstander for denne siden.'}</p>
                            ) : (
                                <>
                                    <ClaimSummary summary={claimSummary} tw={tw} />
                                    <div className="space-y-4">
                                        {claims.map((claim) => (
                                            <article
                                                key={claim.id}
                                                id={`claim-${claim.id}`}
                                                tabIndex={-1}
                                                className={`scroll-mt-24 rounded-[18px] border border-slate-200 bg-white p-5 shadow-[0_4px_14px_rgba(15,23,42,0.04)] ${String(claim.id) === targetClaimId ? 'ring-2 ring-violet-300 ring-offset-2 ring-offset-white' : ''}`}
                                            >
                                                <p className="text-[15px] leading-7 text-slate-900">{claim.claim_text}</p>

                                                <div className="mt-3 flex flex-wrap items-center gap-2">
                                                    <Badge
                                                        label={claimStatusLabel(claim.approval_status)}
                                                        cls={CLAIM_STATUS_STYLES[claim.approval_status] ?? 'bg-slate-200 text-slate-500'}
                                                    />
                                                    {claim.confidence && claim.confidence !== 'uncertain' && (
                                                        <Badge
                                                            label={confidenceLabel(claim.confidence)}
                                                            cls={CONFIDENCE_STYLES[claim.confidence] ?? 'bg-slate-200 text-slate-500'}
                                                        />
                                                    )}
                                                    {claim.conflict_flag && (
                                                        <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-semibold text-rose-600">
                                                            <WarnIcon className="h-3 w-3" />
                                                            {tw.conflict_detected ?? 'Mulig konflikt'}
                                                        </span>
                                                    )}
                                                    <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold ${SOURCE_STATUS_STYLES[claim.source_status] ?? 'bg-slate-200 text-slate-500'}`}>
                                                        {SOURCE_STATUS_WARNS.has(claim.source_status) ? (
                                                            <WarnIcon className="h-3 w-3" />
                                                        ) : (
                                                            <CheckIcon className="h-3 w-3" />
                                                        )}
                                                        {sourceStatusLabel(claim.source_status)}
                                                    </span>
                                                </div>

                                                {canApproveWikiClaims && claim.source_status === 'missing_source' && (
                                                    <div className="mt-3 flex flex-wrap items-center gap-2">
                                                        <input
                                                            type="text"
                                                            maxLength={1000}
                                                            placeholder={tw.approval_comment_placeholder ?? 'Valgfri kommentar'}
                                                            value={approvalComments[claim.id] ?? ''}
                                                            onChange={(e) => setApprovalComments((prev) => ({ ...prev, [claim.id]: e.target.value }))}
                                                            className="min-w-50 flex-1 rounded-lg border border-slate-200 px-3 py-1.5 text-xs text-slate-700 focus:border-violet-300 focus:outline-none"
                                                        />
                                                        <button
                                                            type="button"
                                                            disabled={claimProcessing === claim.id}
                                                            onClick={() => approveClaim(claim)}
                                                            className="rounded-full bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-violet-700 disabled:opacity-50"
                                                        >
                                                            {tw.approve_claim_button ?? 'Godkjenn manuelt'}
                                                        </button>
                                                    </div>
                                                )}

                                                {canApproveWikiClaims && claim.approval_status === 'approved' && (
                                                    <div className="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-700">
                                                        <span>
                                                            {(tw.approved_by_at ?? 'Godkjent av :name den :date')
                                                                .replace(':name', claim.approved_by_name ?? '—')
                                                                .replace(':date', claim.approved_at ? new Date(claim.approved_at).toLocaleString() : '')}
                                                            {claim.approval_comment ? ` — “${claim.approval_comment}”` : ''}
                                                        </span>
                                                        <button
                                                            type="button"
                                                            disabled={claimProcessing === claim.id}
                                                            onClick={() => unapproveClaim(claim)}
                                                            className="rounded-full border border-sky-300 px-3 py-1 text-xs font-semibold text-sky-700 transition hover:bg-sky-100 disabled:opacity-50"
                                                        >
                                                            {tw.unapprove_claim_button ?? 'Angre godkjenning'}
                                                        </button>
                                                    </div>
                                                )}

                                                <div className="mt-4 border-t border-slate-100 pt-4">
                                                    {claim.source_references.length === 0 ? (
                                                        <p className="flex items-center gap-1.5 text-xs font-medium text-amber-600">
                                                            <WarnIcon className="h-3.5 w-3.5 shrink-0" />
                                                            {tw.claim_no_sources ?? 'Ingen kildereferanser for denne påstanden.'}
                                                        </p>
                                                    ) : (
                                                        <ul className="space-y-3">
                                                            {claim.source_references.map((ref) => (
                                                                <li key={ref.id}>
                                                                    <p className="text-xs font-semibold text-slate-600">
                                                                        {ref.source_label}
                                                                    </p>
                                                                    {ref.page_reference && (
                                                                        <p className="mt-0.5 text-xs text-slate-400">
                                                                            {tw.source_page_reference ?? 'Avsnitt'}: {ref.page_reference}
                                                                        </p>
                                                                    )}
                                                                    {ref.excerpt ? (
                                                                        <p className="mt-1 text-xs leading-5 text-slate-400 line-clamp-3">
                                                                            {ref.excerpt}
                                                                        </p>
                                                                    ) : (
                                                                        <p className="mt-1 text-xs italic text-slate-300">
                                                                            {tw.source_no_excerpt ?? 'Ingen tekstutdrag tilgjengelig.'}
                                                                        </p>
                                                                    )}
                                                                    {ref.download_url && (
                                                                        <a
                                                                            href={ref.download_url}
                                                                            target="_blank"
                                                                            rel="noreferrer"
                                                                            className="mt-1.5 inline-flex items-center gap-1 text-[11px] font-medium text-violet-600 hover:text-violet-800 hover:underline"
                                                                        >
                                                                            <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                                <path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.614L6.295 8.235a.75.75 0 1 0-1.09 1.03l4.25 4.5a.75.75 0 0 0 1.09 0l4.25-4.5a.75.75 0 0 0-1.09-1.03l-2.955 3.129V2.75Z" />
                                                                                <path d="M3.5 12.75a.75.75 0 0 0-1.5 0v2.5A2.75 2.75 0 0 0 4.75 18h10.5A2.75 2.75 0 0 0 18 15.25v-2.5a.75.75 0 0 0-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5Z" />
                                                                            </svg>
                                                                            {tw.source_open_document ?? 'Åpne kildedokument'}
                                                                        </a>
                                                                    )}
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                </div>
                                            </article>
                                        ))}
                                    </div>
                                </>
                            )}
                        </div>
                    )}
                </section>

            </div>
        </CustomerAppLayout>
    );
}
