import { Link } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

/**
 * Purpose:
 * Format supplier timestamps for the customer-facing supplier detail stub.
 *
 * Inputs:
 * ISO-8601 timestamp strings.
 *
 * Returns:
 * string
 *
 * Side effects:
 * None.
 */
function formatDateTime(value, locale) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

/**
 * Purpose:
 * Render a simple read-only competitor detail page for customer users.
 *
 * Inputs:
 * Supplier payload from Inertia.
 *
 * Returns:
 * JSX.Element
 *
 * Side effects:
 * None.
 */
export default function SupplierShow({ supplier, linkedNotices = [] }) {
    const locale = document.documentElement.lang || 'no';

    return (
        <CustomerAppLayout title={supplier.supplier_name} showPageTitle={false}>
            <div className="space-y-6">
                <section className="flex flex-col gap-4 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-1.5">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950">{supplier.supplier_name}</h1>
                        <p className="max-w-2xl text-sm leading-6 text-slate-500">
                            Read-only customer detail for a harvested Doffin competitor.
                        </p>
                    </div>
                    <Link
                        href={supplier.back_url}
                        className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                    >
                        Back to competitors
                    </Link>
                </section>

                <section className="grid gap-4 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] md:grid-cols-2">
                    <div className="space-y-1">
                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Supplier name</div>
                        <div className="text-base font-semibold text-slate-950">{supplier.supplier_name}</div>
                    </div>
                    <div className="space-y-1">
                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Organization number</div>
                        <div className="text-sm font-medium text-slate-900">{supplier.organization_number || '—'}</div>
                    </div>
                    <div className="space-y-1">
                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Normalized name</div>
                        <div className="text-sm font-medium text-slate-900 break-all">{supplier.normalized_name}</div>
                    </div>
                    <div className="space-y-1">
                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Notices</div>
                        <div className="text-sm font-medium text-slate-900">{supplier.notices_count}</div>
                    </div>
                    <div className="space-y-1 md:col-span-2">
                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Updated at</div>
                        <div className="text-sm font-medium text-slate-900">{formatDateTime(supplier.updated_at, locale)}</div>
                    </div>
                </section>

                <section className="overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                        <div>
                            <h2 className="text-xl font-semibold tracking-tight text-slate-950">Linked notices</h2>
                            <p className="mt-1 text-sm text-slate-500">Read-only notice links for this competitor.</p>
                        </div>
                        <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">
                            {linkedNotices.length}
                        </span>
                    </div>

                    {linkedNotices.length === 0 ? (
                        <div className="px-6 py-10 text-sm text-slate-500">No linked notices are available for this competitor.</div>
                    ) : (
                        <div className="max-h-[34rem] overflow-y-auto">
                            <div className="divide-y divide-slate-200">
                                {linkedNotices.map((notice) => (
                                    <article key={notice.id} className="px-6 py-4">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="min-w-0 flex-1 space-y-1.5">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    {notice.show_url ? (
                                                        <Link
                                                            href={notice.show_url}
                                                            className="text-sm font-semibold text-violet-700 transition hover:text-violet-800"
                                                        >
                                                            {notice.notice_id || 'Unknown notice'}
                                                        </Link>
                                                    ) : (
                                                        <span className="text-sm font-semibold text-slate-900">
                                                            {notice.notice_id || 'Unknown notice'}
                                                        </span>
                                                    )}

                                                    {notice.source ? (
                                                        <span className="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700 ring-1 ring-inset ring-slate-200">
                                                            {notice.source}
                                                        </span>
                                                    ) : null}
                                                </div>

                                                <div className="text-sm font-medium leading-5 text-slate-950">
                                                    {notice.heading || 'No heading available'}
                                                </div>
                                            </div>

                                            <div className="text-xs font-medium text-slate-500">
                                                {formatDateTime(notice.publication_date, locale)}
                                            </div>
                                        </div>

                                        <div className="mt-3 grid gap-x-4 gap-y-2 md:grid-cols-2">
                                            <div className="space-y-1">
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Buyer</div>
                                                <div className="text-sm text-slate-900">{notice.buyer_name || 'Unknown buyer'}</div>
                                            </div>

                                            <div className="space-y-1">
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Winner lots</div>
                                                <div className="text-sm text-slate-900">
                                                    {notice.winner_lots.length > 0 ? notice.winner_lots.join(', ') : 'None'}
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                ))}
                            </div>
                        </div>
                    )}
                </section>
            </div>
        </CustomerAppLayout>
    );
}
