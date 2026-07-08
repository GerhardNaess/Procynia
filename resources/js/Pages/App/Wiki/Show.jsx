import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

const PAGE_STATUS_STYLES = {
    approved: 'bg-emerald-100 text-emerald-700',
    pending_review: 'bg-amber-100 text-amber-700',
    draft: 'bg-slate-200 text-slate-600',
    rejected: 'bg-rose-100 text-rose-700',
    archived: 'bg-slate-200 text-slate-500',
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

function Badge({ label, cls }) {
    return (
        <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${cls}`}>
            {label}
        </span>
    );
}

function WarnIcon({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clipRule="evenodd" />
        </svg>
    );
}

export default function WikiShow({ page, current_version, claims }) {
    const { translations = {}, auth = {} } = usePage().props;
    const tw = translations?.wiki ?? {};
    const isSystemOwner = auth.user?.is_system_owner ?? false;

    const [processing, setProcessing] = useState(null);

    const sendAction = (action) => {
        if (processing) return;
        setProcessing(action);
        router.patch(`/app/wiki/${page.slug}/${action}`, {}, {
            onFinish: () => setProcessing(null),
        });
    };

    const actionLabel = tw.action_confirming ?? 'Behandler...';

    const pageStatusLabel = (status) => ({
        approved: tw.status_approved ?? 'Godkjent',
        pending_review: tw.status_pending_review ?? 'Til gjennomgang',
        draft: tw.status_draft ?? 'Utkast',
        rejected: tw.status_rejected ?? 'Avvist',
        archived: tw.status_archived ?? 'Arkivert',
    }[status] ?? status);

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

    const isDraft = page.status === 'draft';
    const isPendingReview = page.status === 'pending_review';
    const isApproved = page.status === 'approved';

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

                {/* Page header */}
                <section className="space-y-3">
                    <div className="flex flex-wrap items-start gap-3">
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                            {page.title}
                        </h1>
                        <div className="mt-1">
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

                {/* Approval actions */}
                {(() => {
                    const actionableForOwner = isSystemOwner && ['draft', 'pending_review', 'rejected'].includes(page.status);
                    const noticeForNonOwner = !isSystemOwner && page.status === 'pending_review';
                    if (!actionableForOwner && !noticeForNonOwner) return null;

                    return (
                        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_4px_14px_rgba(15,23,42,0.04)]">
                            {/* pending_review — System Owner sees approve/reject */}
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

                            {/* draft — System Owner can submit */}
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

                            {/* rejected — System Owner can reopen */}
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

                            {/* pending_review — non-owner sees notice only */}
                            {noticeForNonOwner && (
                                <p className="text-sm text-amber-700">
                                    {tw.pending_review_notice ?? 'Denne siden venter på godkjenning av System Owner.'}
                                </p>
                            )}
                        </section>
                    );
                })()}

                {/* Verification basis — claims and sources */}
                <section className="space-y-4">
                    <div>
                        <h2 className="text-base font-semibold text-slate-700">
                            {tw.verification_heading ?? 'Verifikasjonsgrunnlag'}
                        </h2>
                        <p className="mt-0.5 text-sm text-slate-400">
                            {tw.verification_description ?? 'Påstander og kildebevis som siden bygger på.'}
                        </p>
                    </div>

                    {!current_version ? (
                        <p className="text-sm text-slate-400">{tw.no_version ?? 'Ingen aktiv versjon tilgjengelig.'}</p>
                    ) : claims.length === 0 ? (
                        <p className="text-sm text-slate-400">{tw.no_claims ?? 'Ingen påstander for denne siden.'}</p>
                    ) : (
                        <div className="space-y-4">
                            {claims.map((claim) => (
                                <article
                                    key={claim.id}
                                    className="rounded-[18px] border border-slate-200 bg-white p-5 shadow-[0_4px_14px_rgba(15,23,42,0.04)]"
                                >
                                    {/* Claim text */}
                                    <p className="text-[15px] leading-7 text-slate-900">{claim.claim_text}</p>

                                    {/* Badges row */}
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
                                        {(() => {
                                            const hasSource = claim.source_references.length > 0;
                                            const hasExcerpt = claim.source_references.some(r => r.excerpt?.trim());
                                            if (!hasSource) {
                                                return (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-700">
                                                        <WarnIcon className="h-3 w-3" />
                                                        {tw.quality_no_source ?? 'Mangler kilde'}
                                                    </span>
                                                );
                                            }
                                            if (!hasExcerpt) {
                                                return (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-700">
                                                        <WarnIcon className="h-3 w-3" />
                                                        {tw.quality_missing_excerpt ?? 'Mangler utdrag'}
                                                    </span>
                                                );
                                            }
                                            return (
                                                <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold ${isApproved ? 'bg-slate-100 text-slate-400' : 'bg-emerald-100 text-emerald-700'}`}>
                                                    <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                        <path fillRule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clipRule="evenodd" />
                                                    </svg>
                                                    {tw.quality_source_found ?? 'Kilde funnet'}
                                                </span>
                                            );
                                        })()}
                                    </div>

                                    {/* Source references */}
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
                    )}
                </section>
            </div>
        </CustomerAppLayout>
    );
}
