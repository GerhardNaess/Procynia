import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

export const KNOWLEDGE_DOCUMENT_TYPE_OPTIONS = [
    { value: 'company', label: 'Selskap' },
    { value: 'method', label: 'Metode' },
    { value: 'reference', label: 'Referanse' },
    { value: 'cv', label: 'CV' },
    { value: 'boilerplate', label: 'Standardtekst' },
    { value: 'other', label: 'Annet' },
];

function classNames(...values) {
    return values.filter(Boolean).join(' ');
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

function formatDateTime(value) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('nb-NO', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

export default function KnowledgeItemForm({
    form,
    documentTypeOptions = KNOWLEDGE_DOCUMENT_TYPE_OPTIONS,
    documentThemeOptions = [],
    documentOwnerOptions = [],
    backHref,
    submitLabel,
    onSubmit,
    deleteUrl = null,
    knowledgeItem = null,
    showFileUpload = false,
}) {
    const { translations = {} } = usePage().props;
    const commonText = translations?.common ?? {};
    const [deleting, setDeleting] = useState(false);
    const [contentExcerptExpanded, setContentExcerptExpanded] = useState(false);
    const selectedDocumentLabel = form.data.document?.name ?? 'Ingen fil valgt ennå.';
    const selectedDocumentThemeTermId = form.data.document_theme_term_id ?? '';
    const selectedDocumentOwnerUserId = form.data.owner_user_id ?? '';
    const ownershipLabel = knowledgeItem?.ownership_label ?? (!knowledgeItem ? 'Selskap' : null);
    const ownershipHelpText = knowledgeItem ? null : 'Kunnskapsdokumenter er generell selskapskunnskap og brukes på tvers av saker.';
    const contentExcerpt = knowledgeItem?.content_excerpt ?? '';
    const contentExcerptLimit = 220;
    const hasLongContentExcerpt = contentExcerpt.length > contentExcerptLimit;
    const visibleContentExcerpt = hasLongContentExcerpt && !contentExcerptExpanded
        ? `${contentExcerpt.slice(0, contentExcerptLimit).trimEnd()}...`
        : contentExcerpt;
    const documentOwnerLabel = commonText.document_owner_label ?? 'Dokumenteier';
    const notSetLabel = commonText.not_set ?? 'Ikke satt';
    const ownershipSummary = ownershipLabel ? (
        <section className="rounded-[20px] border border-slate-200 bg-slate-50 px-5 py-4">
            <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                Tilhørighet
            </div>
            <div className="mt-1 text-sm font-medium text-slate-950">
                Tilhørighet: {ownershipLabel}
            </div>
            {ownershipHelpText ? (
                <p className="mt-2 text-sm leading-6 text-slate-500">
                    {ownershipHelpText}
                </p>
            ) : null}
        </section>
    ) : null;
    const documentOwnerSelect = knowledgeItem ? (
        <label className="space-y-2">
            <span className="text-sm font-medium text-slate-700">{documentOwnerLabel}</span>
            <select
                value={selectedDocumentOwnerUserId}
                onChange={(event) => form.setData('owner_user_id', event.target.value)}
                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
            >
                <option value="">{notSetLabel}</option>
                {documentOwnerOptions.map((option) => (
                    <option key={option.id} value={option.id}>
                        {option.label}
                    </option>
                ))}
            </select>
            {form.errors.owner_user_id ? <p className="text-sm text-rose-600">{form.errors.owner_user_id}</p> : null}
        </label>
    ) : null;
    const documentThemeSelect = (
        <label className="space-y-2">
            <span className="text-sm font-medium text-slate-700">Tema</span>
            <select
                value={selectedDocumentThemeTermId}
                onChange={(event) => form.setData('document_theme_term_id', event.target.value)}
                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
            >
                <option value="">Ingen tema</option>
                {documentThemeOptions.map((option) => (
                    <option key={option.id} value={option.id}>
                        {option.label}
                    </option>
                ))}
            </select>
            {form.errors.document_theme_term_id ? <p className="text-sm text-rose-600">{form.errors.document_theme_term_id}</p> : null}
        </label>
    );

    const deleteKnowledgeItem = () => {
        if (!deleteUrl || deleting) {
            return;
        }

        if (!window.confirm('Er du sikker på at du vil slette dette kunnskapsdokumentet?')) {
            return;
        }

        setDeleting(true);

        router.delete(deleteUrl, {
            preserveScroll: true,
            onFinish: () => setDeleting(false),
        });
    };

    if (showFileUpload) {
        return (
            <section className="rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                <form onSubmit={onSubmit} className="space-y-5">
                    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <label htmlFor="knowledge-document" className="text-sm font-medium text-slate-700">
                                    Velg dokument
                                </label>
                                <div className="flex min-h-[56px] items-center gap-4 rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                                    <label
                                        htmlFor="knowledge-document"
                                        className="inline-flex shrink-0 cursor-pointer items-center rounded-full bg-violet-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-violet-700"
                                    >
                                        Velg fil
                                    </label>
                                    <span className="min-w-0 flex-1 text-sm text-slate-500">
                                        {selectedDocumentLabel}
                                    </span>
                                    <input
                                        id="knowledge-document"
                                        type="file"
                                        accept=".pdf,.doc,.docx,.xls,.xlsx"
                                        onChange={(event) => form.setData('document', event.target.files?.[0] ?? null)}
                                        className="sr-only"
                                    />
                                </div>
                                <p className="text-xs leading-5 text-slate-500">
                                    Tillatte filtyper: PDF, DOC, DOCX, XLS, XLSX. Maks 20 MB per fil.
                                </p>
                                {form.errors.document ? <p className="text-sm text-rose-600">{form.errors.document}</p> : null}
                            </div>

                            {ownershipSummary}

                            <div className="grid gap-4 sm:grid-cols-3">
                                <label className="space-y-2">
                                    <span className="text-sm font-medium text-slate-700">Dokumentkategori</span>
                                    <select
                                        value={form.data.document_type}
                                        onChange={(event) => form.setData('document_type', event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    >
                                        {documentTypeOptions.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                    {form.errors.document_type ? <p className="text-sm text-rose-600">{form.errors.document_type}</p> : null}
                                </label>

                                {documentThemeSelect}

                                <label className="space-y-2">
                                    <span className="text-sm font-medium text-slate-700">Status</span>
                                    <label className="flex min-h-11 items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={form.data.is_active}
                                            onChange={(event) => form.setData('is_active', event.target.checked)}
                                            className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                                        />
                                        <span>{form.data.is_active ? 'Aktiv' : 'Inaktiv'}</span>
                                    </label>
                                    {form.errors.is_active ? <p className="text-sm text-rose-600">{form.errors.is_active}</p> : null}
                                </label>
                            </div>
                        </div>

                        <button
                            type="submit"
                            disabled={form.processing}
                            className="inline-flex items-center justify-center rounded-2xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60 lg:mt-7"
                        >
                            {form.processing ? 'Lagrer...' : submitLabel}
                        </button>
                    </div>
                </form>
            </section>
        );
    }

    return (
        <section className="rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
            <form onSubmit={onSubmit} className="space-y-5">
                {knowledgeItem ? (
                    <section className="space-y-3 rounded-[20px] border border-slate-200 bg-slate-50 px-5 py-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="font-medium text-slate-950">
                                {knowledgeItem.original_filename}
                            </div>
                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                {knowledgeItem.extraction_status_label}
                            </span>
                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                Tekstbiter: {Number(knowledgeItem.chunk_count ?? 0)}
                            </span>
                        </div>

                        <div className="grid gap-3 text-sm text-slate-600 sm:grid-cols-2">
                            <div>
                                <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                    Lastet opp
                                </div>
                                <div>{formatDateTime(knowledgeItem.uploaded_at)}</div>
                            </div>
                            <div>
                                <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                    Størrelse
                                </div>
                                <div>{formatFileSize(knowledgeItem.file_size_bytes)}</div>
                            </div>
                            <div>
                                <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                    Lastet opp av
                                </div>
                                <div>{knowledgeItem.uploaded_by ?? '—'}</div>
                            </div>
                            <div>
                                <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                    Utdrag
                                </div>
                                <div className="whitespace-pre-wrap break-words leading-6">
                                    {visibleContentExcerpt || 'Ingen ekstrahert tekst.'}
                                </div>
                                {hasLongContentExcerpt ? (
                                    <button
                                        type="button"
                                        onClick={() => setContentExcerptExpanded((currentValue) => !currentValue)}
                                        className="mt-1 text-sm font-semibold text-violet-700 transition hover:text-violet-900"
                                    >
                                        {contentExcerptExpanded ? 'Vis mindre' : 'Mer'}
                                    </button>
                                ) : null}
                            </div>
                        </div>

                        {knowledgeItem.extraction_error ? (
                            <p className="text-sm text-rose-600">
                                {knowledgeItem.extraction_error}
                            </p>
                        ) : null}

                        <p className="text-sm text-slate-500">
                            For å endre innholdet, last opp et nytt dokument.
                        </p>
                    </section>
                ) : null}

                {ownershipSummary}

                {documentOwnerSelect ? (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {documentOwnerSelect}
                    </div>
                ) : null}

                <div className="grid gap-4 sm:grid-cols-3">
                    <label className="space-y-2">
                        <span className="text-sm font-medium text-slate-700">Dokumentkategori</span>
                        <select
                            value={form.data.document_type}
                            onChange={(event) => form.setData('document_type', event.target.value)}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                        >
                            {documentTypeOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        {form.errors.document_type ? <p className="text-sm text-rose-600">{form.errors.document_type}</p> : null}
                    </label>

                    {documentThemeSelect}

                    <label className="space-y-2">
                        <span className="text-sm font-medium text-slate-700">Status</span>
                        <label className="flex min-h-11 items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                checked={form.data.is_active}
                                onChange={(event) => form.setData('is_active', event.target.checked)}
                                className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                            />
                            <span>{form.data.is_active ? 'Aktiv' : 'Inaktiv'}</span>
                        </label>
                        {form.errors.is_active ? <p className="text-sm text-rose-600">{form.errors.is_active}</p> : null}
                    </label>
                </div>

                <div className={classNames(
                    'flex flex-col gap-3 sm:flex-row',
                    deleteUrl ? 'sm:justify-between' : 'sm:justify-end',
                )}>
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <Link
                            href={backHref}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                        >
                            Tilbake
                        </Link>
                        {deleteUrl ? (
                            <button
                                type="button"
                                onClick={deleteKnowledgeItem}
                                disabled={deleting}
                                className="inline-flex min-h-11 items-center justify-center rounded-xl border border-rose-200 bg-white px-4 py-2.5 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {deleting ? 'Sletter...' : 'Slett'}
                            </button>
                        ) : null}
                    </div>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {form.processing ? 'Lagrer...' : submitLabel}
                    </button>
                </div>
            </form>
        </section>
    );
}
