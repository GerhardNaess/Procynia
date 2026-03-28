import { Link, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

function formatDate(value, locale) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

function formatFileSize(bytes) {
    if (!bytes) {
        return '—';
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function NoticeShow({ notice }) {
    const { locale, translations } = usePage().props;

    return (
        <CustomerAppLayout title={notice.title}>
            <div className="space-y-6">
                <div>
                    <Link href="/app/notices" className="text-sm font-medium text-slate-600 transition hover:text-slate-950">
                        {translations.common.back}
                    </Link>
                </div>

                <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="grid gap-6 lg:grid-cols-2">
                        <div className="space-y-3">
                            <div className="text-sm text-slate-500">{notice.notice_id}</div>
                            <div className="text-sm text-slate-600">{notice.reason_summary}</div>
                            {notice.relevance_score !== null || notice.relevance_level ? (
                                <div className="flex flex-wrap gap-3 pt-2">
                                    <span className="rounded-full bg-slate-900 px-3 py-1 text-sm font-medium text-white">
                                        {translations.frontend.relevance_score} {notice.relevance_score ?? '—'}
                                    </span>
                                    <span className="rounded-full border border-slate-200 px-3 py-1 text-sm font-medium text-slate-700">
                                        {notice.relevance_level || '—'}
                                    </span>
                                </div>
                            ) : null}
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <div className="text-xs uppercase tracking-wide text-slate-400">{translations.frontend.published}</div>
                                <div className="mt-1 text-sm text-slate-800">{formatDate(notice.publication_date, locale)}</div>
                            </div>
                            <div>
                                <div className="text-xs uppercase tracking-wide text-slate-400">{translations.common.deadline}</div>
                                <div className="mt-1 text-sm text-slate-800">{formatDate(notice.deadline, locale)}</div>
                            </div>
                            <div>
                                <div className="text-xs uppercase tracking-wide text-slate-400">{translations.frontend.status}</div>
                                <div className="mt-1 text-sm text-slate-800">{notice.status || '—'}</div>
                            </div>
                            <div>
                                <div className="text-xs uppercase tracking-wide text-slate-400">{translations.frontend.buyer}</div>
                                <div className="mt-1 text-sm text-slate-800">{notice.buyer_name || '—'}</div>
                            </div>
                        </div>
                    </div>
                    {notice.description ? (
                        <div className="mt-6 border-t border-slate-200 pt-6 text-sm leading-7 text-slate-700">
                            {notice.description}
                        </div>
                    ) : null}
                </section>

                <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="mb-4 flex items-center justify-between gap-4">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-950">{translations.frontend.matched_for_customer}</h2>
                            <p className="text-sm text-slate-500">{translations.frontend.customer_safe_reasoning}</p>
                        </div>
                    </div>

                    {notice.department_contexts.length === 0 ? (
                        <div className="rounded-2xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500">
                            {translations.frontend.no_department_context}
                        </div>
                    ) : (
                        <div className="space-y-4">
                            {notice.department_contexts.map((context, index) => (
                                <div key={`${context.department}-${index}`} className="rounded-2xl border border-slate-200 p-4">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <div className="font-medium text-slate-950">{context.department || translations.common.not_available}</div>
                                            <div className="text-sm text-slate-500">
                                                {context.watch_profile_name || translations.common.none}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3 text-sm">
                                            <span className="rounded-full bg-slate-900 px-3 py-1 font-medium text-white">{context.score}</span>
                                            <span className="rounded-full border border-slate-200 px-3 py-1 font-medium text-slate-700">
                                                {context.relevance_level}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="mb-4 flex items-center justify-between gap-4">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-950">{translations.frontend.cpv}</h2>
                            <p className="text-sm text-slate-500">{translations.frontend.relevant_for_departments}</p>
                        </div>
                    </div>

                    {notice.cpv_codes.length === 0 ? (
                        <div className="rounded-2xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500">
                            {translations.common.none}
                        </div>
                    ) : (
                        <div className="divide-y divide-slate-200">
                            {notice.cpv_codes.map((cpv) => (
                                <div key={cpv.code} className="grid gap-4 py-4 lg:grid-cols-[180px_minmax(0,1fr)]">
                                    <div className="font-medium text-slate-950">{cpv.code}</div>
                                    <div className="text-sm text-slate-600">{cpv.description || translations.common.not_available}</div>
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-950">{translations.frontend.documents}</h2>
                            <p className="text-sm text-slate-500">{translations.frontend.document_count.replace(':count', notice.documents.length)}</p>
                        </div>
                        {notice.documents.length > 1 ? (
                            <a
                                href={notice.download_all_url}
                                className="rounded-full bg-slate-950 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
                            >
                                {translations.frontend.download_all}
                            </a>
                        ) : null}
                    </div>

                    {notice.documents.length === 0 ? (
                        <div className="rounded-2xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500">
                            {translations.frontend.no_documents}
                        </div>
                    ) : (
                        <div className="divide-y divide-slate-200">
                            {notice.documents.map((document) => (
                                <div key={document.id} className="grid gap-4 py-4 lg:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))]">
                                    <div className="font-medium text-slate-950">{document.title}</div>
                                    <div className="text-sm text-slate-500">{document.mime_type || translations.common.not_available}</div>
                                    <div className="text-sm text-slate-500">{formatFileSize(document.file_size)}</div>
                                    <div className="flex justify-start lg:justify-end">
                                        <a
                                            href={document.download_url}
                                            className="rounded-full border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                        >
                                            {translations.common.download}
                                        </a>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </CustomerAppLayout>
    );
}
