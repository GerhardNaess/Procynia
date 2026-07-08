import { Link, useForm, usePage } from '@inertiajs/react';
import { useRef } from 'react';
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

const SOURCE_STATUS_STYLES = {
    extracted: 'bg-emerald-100 text-emerald-700',
    pending: 'bg-amber-100 text-amber-700',
    failed: 'bg-rose-100 text-rose-700',
};

function SourceStatusBadge({ status, label }) {
    const cls = SOURCE_STATUS_STYLES[status] ?? 'bg-slate-200 text-slate-600';
    return (
        <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${cls}`}>
            {label}
        </span>
    );
}

export default function WikiIndex({ pages, sources = [], sources_store_url: sourcesStoreUrl = '/app/wiki/sources' }) {
    const { translations = {} } = usePage().props;
    const tw = translations?.wiki ?? {};
    const locale = document.documentElement.lang || 'no';

    const fileInputRef = useRef(null);
    const uploadForm = useForm({ file: null });

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

    const statusLabel = (status) => ({
        approved: tw.status_approved ?? 'Godkjent',
        pending_review: tw.status_pending_review ?? 'Til gjennomgang',
        draft: tw.status_draft ?? 'Utkast',
        rejected: tw.status_rejected ?? 'Avvist',
        archived: tw.status_archived ?? 'Arkivert',
    }[status] ?? status);

    const sourceStatusLabel = (status) => ({
        extracted: tw.source_status_extracted ?? 'Ekstrahert',
        pending: tw.source_status_pending ?? 'Behandles',
        failed: tw.source_status_failed ?? 'Feilet',
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
                        <section className="hidden overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)] md:block">
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
                {/* Source document upload */}
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

                        {sources.length === 0 ? (
                            <p className="text-sm text-slate-400">
                                {tw.sources_list_empty ?? 'Ingen kildedokumenter lastet opp ennå.'}
                            </p>
                        ) : (
                            <div className="overflow-hidden rounded-2xl border border-slate-100">
                                <table className="min-w-full divide-y divide-slate-100">
                                    <thead className="bg-slate-50">
                                        <tr className="text-left text-[11px] font-semibold uppercase tracking-widest text-slate-400">
                                            <th className="px-4 py-3">{tw.source_col_filename ?? 'Filnavn'}</th>
                                            <th className="px-4 py-3">{tw.source_col_status ?? 'Status'}</th>
                                            <th className="px-4 py-3">{tw.source_col_uploaded ?? 'Lastet opp'}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-50 bg-white">
                                        {sources.map((source) => (
                                            <tr key={source.id} className="text-sm">
                                                <td className="max-w-xs truncate px-4 py-3 font-medium text-slate-900">
                                                    {source.original_filename}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <SourceStatusBadge
                                                        status={source.document_status}
                                                        label={sourceStatusLabel(source.document_status)}
                                                    />
                                                </td>
                                                <td className="px-4 py-3 text-slate-500">
                                                    {formatDate(source.created_at, locale)}
                                                </td>
                                            </tr>
                                        ))}
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
            </div>
        </CustomerAppLayout>
    );
}
