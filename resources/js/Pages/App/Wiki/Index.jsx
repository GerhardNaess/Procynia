import { Link } from '@inertiajs/react';
import { usePage } from '@inertiajs/react';
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

const STATUS_STYLES = {
    approved: 'bg-emerald-100 text-emerald-700',
    pending_review: 'bg-amber-100 text-amber-700',
    draft: 'bg-slate-200 text-slate-600',
    rejected: 'bg-rose-100 text-rose-700',
    archived: 'bg-slate-200 text-slate-500',
};

function StatusBadge({ status, label }) {
    const cls = STATUS_STYLES[status] ?? 'bg-slate-200 text-slate-600';
    return (
        <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${cls}`}>
            {label}
        </span>
    );
}

export default function WikiIndex({ pages }) {
    const { translations = {} } = usePage().props;
    const tw = translations?.wiki ?? {};
    const locale = document.documentElement.lang || 'no';

    const statusLabel = (status) => ({
        approved: tw.status_approved ?? 'Godkjent',
        pending_review: tw.status_pending_review ?? 'Til gjennomgang',
        draft: tw.status_draft ?? 'Utkast',
        rejected: tw.status_rejected ?? 'Avvist',
        archived: tw.status_archived ?? 'Arkivert',
    }[status] ?? status);

    return (
        <CustomerAppLayout title={tw.index_title ?? 'Wiki'} showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                        {tw.index_title ?? 'Wiki'}
                    </h1>
                    <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                        {tw.index_description ?? 'Strukturert kunnskap om virksomheten, generert fra godkjent innhold.'}
                    </p>
                </section>

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
                        <section className="hidden overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)] md:block">
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-slate-200">
                                    <thead className="bg-slate-50">
                                        <tr className="text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                            <th className="px-6 py-4">Tittel</th>
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
            </div>
        </CustomerAppLayout>
    );
}
