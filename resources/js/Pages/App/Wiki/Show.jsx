import { Link } from '@inertiajs/react';
import { usePage } from '@inertiajs/react';
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

export default function WikiShow({ page, current_version, claims }) {
    const { translations = {} } = usePage().props;
    const tw = translations?.wiki ?? {};

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

                {/* Claims */}
                <section className="space-y-4">
                    <h2 className="text-base font-semibold text-slate-700">
                        {tw.claims_heading ?? 'Påstander'}
                    </h2>

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
                                                <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clipRule="evenodd" />
                                                </svg>
                                                {tw.conflict_detected ?? 'Mulig konflikt'}
                                            </span>
                                        )}
                                    </div>

                                    {/* Source references */}
                                    {claim.source_references.length > 0 && (
                                        <ul className="mt-4 space-y-2 border-t border-slate-100 pt-4">
                                            {claim.source_references.map((ref) => (
                                                <li key={ref.id} className="space-y-0.5">
                                                    <p className="text-xs font-semibold text-slate-500">
                                                        {tw.source ?? 'Kilde'}: {ref.source_label}
                                                    </p>
                                                    {ref.excerpt && (
                                                        <p className="text-xs leading-5 text-slate-400 line-clamp-3">
                                                            {ref.excerpt}
                                                        </p>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </CustomerAppLayout>
    );
}
