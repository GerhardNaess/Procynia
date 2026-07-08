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

const INGEST_STATUS_STYLES = {
    queued: 'bg-amber-100 text-amber-700',
    running: 'bg-blue-100 text-blue-700',
    sections_planned: 'bg-blue-100 text-blue-700',
    completed: 'bg-emerald-100 text-emerald-700',
    failed: 'bg-rose-100 text-rose-700',
};

const IN_PROGRESS_STATUSES = ['queued', 'running', 'sections_planned'];

function IngestStatusBadge({ run, label, notStartedLabel }) {
    if (!run) {
        return (
            <span className="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-500">
                {notStartedLabel}
            </span>
        );
    }
    const cls = INGEST_STATUS_STYLES[run.status] ?? 'bg-slate-100 text-slate-600';
    return (
        <div>
            <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${cls}`}>{label}</span>
            {run.status === 'failed' && run.error_message ? (
                <p className="mt-0.5 max-w-45 truncate text-[11px] text-rose-500" title={run.error_message}>
                    {run.error_message}
                </p>
            ) : null}
        </div>
    );
}

export default function WikiIndex({ pages, sources = [], sources_store_url: sourcesStoreUrl = '/app/wiki/sources' }) {
    const { translations = {} } = usePage().props;
    const tw = translations?.wiki ?? {};
    const locale = document.documentElement.lang || 'no';

    const fileInputRef = useRef(null);
    const uploadForm = useForm({ file: null });
    const [ingestingIds, setIngestingIds] = useState(new Set());

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

    const startIngest = (id) => {
        if (ingestingIds.has(id)) return;
        setIngestingIds((prev) => new Set(prev).add(id));
        router.post(
            `/app/wiki/sources/${id}/ingest`,
            {},
            {
                onFinish: () =>
                    setIngestingIds((prev) => {
                        const next = new Set(prev);
                        next.delete(id);
                        return next;
                    }),
            },
        );
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
    }[status] ?? status);

    const notStartedLabel = tw.ingest_status_not_started ?? 'Ikke startet';

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
                                            <th className="px-4 py-3">{tw.ingest_col_wiki_status ?? 'Wiki-status'}</th>
                                            <th className="px-4 py-3"></th>
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
                                                <td className="px-4 py-3">
                                                    <IngestStatusBadge
                                                        run={source.latest_ingest_run}
                                                        label={source.latest_ingest_run ? ingestStatusLabel(source.latest_ingest_run.status) : null}
                                                        notStartedLabel={notStartedLabel}
                                                    />
                                                    {source.generated_pages.length > 0 && (
                                                        <ul className="mt-1.5 space-y-0.5">
                                                            {source.generated_pages.map((p) => (
                                                                <li key={p.id}>
                                                                    <Link
                                                                        href={`/app/wiki/${p.slug}`}
                                                                        className="max-w-50 truncate text-[11px] text-violet-600 hover:text-violet-800 hover:underline"
                                                                    >
                                                                        {p.title}
                                                                    </Link>
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="flex flex-col items-start gap-2">
                                                        <a
                                                            href={`/app/wiki/sources/${source.id}/download`}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="inline-flex items-center gap-1 text-[11px] font-medium text-slate-500 hover:text-slate-800"
                                                        >
                                                            <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                <path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.614L6.295 8.235a.75.75 0 1 0-1.09 1.03l4.25 4.5a.75.75 0 0 0 1.09 0l4.25-4.5a.75.75 0 0 0-1.09-1.03l-2.955 3.129V2.75Z" />
                                                                <path d="M3.5 12.75a.75.75 0 0 0-1.5 0v2.5A2.75 2.75 0 0 0 4.75 18h10.5A2.75 2.75 0 0 0 18 15.25v-2.5a.75.75 0 0 0-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5Z" />
                                                            </svg>
                                                            {tw.source_open_document ?? 'Åpne dokument'}
                                                        </a>
                                                        {source.document_status === 'extracted' && (() => {
                                                            const isInProgress = source.latest_ingest_run && IN_PROGRESS_STATUSES.includes(source.latest_ingest_run.status);
                                                            const isDisabled = ingestingIds.has(source.id) || isInProgress;
                                                            return (
                                                                <button
                                                                    type="button"
                                                                    disabled={isDisabled}
                                                                    onClick={() => startIngest(source.id)}
                                                                    className="inline-flex min-h-8 items-center justify-center rounded-full border border-violet-200 bg-violet-50 px-3 py-1 text-xs font-semibold text-violet-700 transition hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-50"
                                                                >
                                                                    {ingestingIds.has(source.id)
                                                                        ? (tw.source_ingest_starting ?? 'Starter...')
                                                                        : (tw.source_ingest_button ?? 'Generer wiki-utkast')}
                                                                </button>
                                                            );
                                                        })()}
                                                    </div>
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
