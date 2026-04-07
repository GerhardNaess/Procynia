import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import KnowledgeItemForm, { KNOWLEDGE_DOCUMENT_TYPE_OPTIONS } from './KnowledgeItemForm';

const EXTRACTION_STATUS_META = {
    pending: {
        label: 'Venter',
        className: 'bg-amber-100 text-amber-700 ring-amber-200',
    },
    completed: {
        label: 'Tekst ekstrahert',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
    failed: {
        label: 'Tekstuttrekk feilet',
        className: 'bg-rose-100 text-rose-700 ring-rose-200',
    },
};

const ACTIVE_STATUS_META = {
    true: {
        label: 'Aktiv',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
    false: {
        label: 'Inaktiv',
        className: 'bg-slate-100 text-slate-700 ring-slate-200',
    },
};

function formatDate(value, locale) {
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

function formatFileSize(bytes) {
    const value = Number(bytes ?? 0);

    if (!Number.isFinite(value) || value <= 0) {
        return '—';
    }

    if (value < 1024) {
        return `${value} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let size = value / 1024;
    let unit = 'KB';

    for (const candidateUnit of units) {
        unit = candidateUnit;

        if (size < 1024 || candidateUnit === 'GB') {
            break;
        }

        size /= 1024;
    }

    return `${size.toFixed(1)} ${unit}`;
}

export default function KnowledgeBaseIndex({ pageTitle = 'Kunnskapsdokumenter', knowledgeItems = [] }) {
    const { locale = 'nb-NO' } = usePage().props;
    const [deletingId, setDeletingId] = useState(null);
    const items = Array.isArray(knowledgeItems) ? knowledgeItems : [];
    const uploadForm = useForm({
        document: null,
        document_type: 'other',
        is_active: true,
    });

    const submitUpload = (event) => {
        event.preventDefault();

        uploadForm.post('/app/ai/knowledge-base', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                uploadForm.reset('document');
                uploadForm.setData('document_type', 'other');
                uploadForm.setData('is_active', true);
            },
        });
    };

    const deleteKnowledgeDocument = (item) => {
        if (!item?.delete_url || deletingId !== null) {
            return;
        }

        if (!window.confirm(`Slette ${item.original_filename}?`)) {
            return;
        }

        setDeletingId(item.id);

        router.delete(item.delete_url, {
            preserveScroll: true,
            onFinish: () => setDeletingId(null),
        });
    };

    return (
        <CustomerAppLayout title={pageTitle} showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-4">
                    <div className="space-y-2">
                        <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                            Kunnskapsdokumenter
                        </div>
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Kunnskapsdokumenter</h1>
                        <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                            Last opp dokumenter som senere kan brukes som grunnlag for AI-forslag til besvarelse.
                        </p>
                    </div>
                </section>

                <KnowledgeItemForm
                    form={uploadForm}
                    documentTypeOptions={KNOWLEDGE_DOCUMENT_TYPE_OPTIONS}
                    submitLabel="Last opp dokument"
                    onSubmit={submitUpload}
                    showFileUpload
                />

                {items.length === 0 ? (
                    <section className="rounded-[22px] border border-dashed border-slate-300 bg-white px-6 py-10 text-center shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="text-lg font-semibold text-slate-900">
                            Ingen kunnskapsdokumenter er lastet opp ennå.
                        </div>
                        <p className="mt-2 text-sm text-slate-500">
                            Last opp referanseprosjekter, metodebeskrivelser, CV-er og andre gjenbrukbare dokumenter.
                        </p>
                    </section>
                ) : (
                    <section className="overflow-hidden rounded-[22px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-5 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                            Filnavn
                                        </th>
                                        <th className="px-5 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                            Lastet opp
                                        </th>
                                        <th className="px-5 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                            Størrelse
                                        </th>
                                        <th className="px-5 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                            Status
                                        </th>
                                        <th className="px-5 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                            Lastet opp av
                                        </th>
                                        <th className="px-5 py-3 text-right text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                            Handlinger
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200 bg-white">
                                    {items.map((item) => {
                                        const extractionStatusMeta = EXTRACTION_STATUS_META[item.extraction_status] ?? EXTRACTION_STATUS_META.pending;
                                        const activeStatusMeta = ACTIVE_STATUS_META[String(Boolean(item.is_active))] ?? ACTIVE_STATUS_META.true;
                                        const isDeleting = deletingId === item.id;
                                        const uploadedAtLabel = formatDate(item.uploaded_at, locale);

                                        return (
                                            <tr key={item.id} className="align-top">
                                                <td className="px-5 py-4">
                                                    <div className="space-y-1.5">
                                                        <div className="font-medium text-slate-950">
                                                            {item.original_filename}
                                                        </div>
                                                        <div className="flex flex-wrap gap-2">
                                                            <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                {item.document_type_label}
                                                            </span>
                                                            <span className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] ring-1 ring-inset ${extractionStatusMeta.className}`}>
                                                                {item.extraction_status_label}
                                                            </span>
                                                            {Number(item.chunk_count ?? 0) > 0 ? (
                                                                <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                    Tekstbiter: {Number(item.chunk_count ?? 0)}
                                                                </span>
                                                            ) : null}
                                                            {item.extraction_error ? (
                                                                <span className="inline-flex rounded-full bg-rose-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-rose-700 ring-1 ring-inset ring-rose-200">
                                                                    Feil i tekstuttrekk
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                        <p className="max-w-3xl text-sm leading-6 text-slate-500">
                                                            {item.content_excerpt ?? 'Ingen ekstrahert tekst.'}
                                                        </p>
                                                        {item.extraction_error ? (
                                                            <p className="text-xs leading-5 text-rose-600">
                                                                {item.extraction_error}
                                                            </p>
                                                        ) : null}
                                                    </div>
                                                </td>
                                                <td className="px-5 py-4 text-sm text-slate-600">
                                                    {uploadedAtLabel}
                                                </td>
                                                <td className="px-5 py-4 text-sm text-slate-600">
                                                    {formatFileSize(item.file_size_bytes)}
                                                </td>
                                                <td className="px-5 py-4">
                                                    <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${activeStatusMeta.className}`}>
                                                        {activeStatusMeta.label}
                                                    </span>
                                                </td>
                                                <td className="px-5 py-4 text-sm text-slate-600">
                                                    {item.uploaded_by ?? '—'}
                                                </td>
                                                <td className="px-5 py-4">
                                                    <div className="flex justify-end gap-2">
                                                        <Link
                                                            href={item.edit_url}
                                                            className="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                        >
                                                            Rediger
                                                        </Link>
                                                        <button
                                                            type="button"
                                                            onClick={() => deleteKnowledgeDocument(item)}
                                                            disabled={isDeleting}
                                                            className="inline-flex items-center justify-center rounded-full border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            {isDeleting ? 'Sletter...' : 'Slett'}
                                                        </button>
                                                    </div>
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
        </CustomerAppLayout>
    );
}
