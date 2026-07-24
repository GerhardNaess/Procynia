import { Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import InfoHint from '../../../../Components/App/InfoHint';

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
    documentCategoryOptions = [],
    documentOwnershipOptions = [],
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
    const knowledgeText = translations?.knowledge ?? {};
    const [deleting, setDeleting] = useState(false);
    const [contentExcerptExpanded, setContentExcerptExpanded] = useState(false);
    const selectedDocumentLabel = form.data.document?.name ?? 'Ingen fil valgt ennå.';
    const selectedOwnershipType = form.data.ownership_type ?? 'company';
    const selectedDocumentOwnerUserId = form.data.owner_user_id ?? '';
    const contentExcerpt = knowledgeItem?.content_excerpt ?? '';
    const contentExcerptLimit = 220;
    const hasLongContentExcerpt = contentExcerpt.length > contentExcerptLimit;
    const visibleContentExcerpt = hasLongContentExcerpt && !contentExcerptExpanded
        ? `${contentExcerpt.slice(0, contentExcerptLimit).trimEnd()}...`
        : contentExcerpt;

    const documentCategoryLabel = knowledgeText.document_category_label ?? 'Dokumentkategori';
    const documentTopicLabel = knowledgeText.document_topic_label ?? 'Tema';
    const selectDocumentCategoryText = knowledgeText.select_document_category ?? 'Velg dokumentkategori';
    const selectDocumentTopicText = knowledgeText.select_document_topic ?? 'Velg tema';
    const selectDocumentCategoryFirstText = knowledgeText.select_document_category_first ?? 'Velg dokumentkategori først';
    const noTopicsAvailableText = knowledgeText.no_topics_available ?? 'Ingen temaer er tilgjengelige for valgt dokumentkategori. Administrer temaer under Kundemiljø → Kunnskapsbase.';
    const documentOwnerLabel = commonText.document_owner_label ?? 'Dokumenteier';
    const ownershipLabel = knowledgeText.ownership_label_text ?? 'Tilhørighet';
    const ownershipHelpText = !knowledgeItem
        ? knowledgeText.ownership_default_help ?? 'Selskap er standard for nye dokumenter.'
        : null;
    const selectableOwnershipOptions = (Array.isArray(documentOwnershipOptions) ? documentOwnershipOptions : [])
        .filter((option) => option?.selectable !== false || option?.value === selectedOwnershipType);
    const notSetLabel = commonText.not_set ?? 'Ikke satt';
    const lastReviewedAtLabel = knowledgeText.last_reviewed_at_label ?? 'Sist gjennomgått';
    const reviewDueAtLabel = knowledgeText.review_due_at_label ?? 'Neste gjennomgang';
    const documentStatusLabel = knowledgeText.document_status_label ?? 'Livsløpstatus';
    const documentStatusHelpText = knowledgeText.document_status_help ?? 'Status viser hvor dokumentet er i livsløpet. Kun aktive dokumenter kan brukes som aktivt kunnskapsgrunnlag.';
    const documentStatusOptions = [
        { value: 'draft', label: knowledgeText.filter_draft ?? 'Utkast' },
        { value: 'pending_review', label: knowledgeText.filter_pending_review ?? 'Til vurdering' },
        { value: 'active', label: knowledgeText.filter_active ?? 'Aktiv' },
        { value: 'expired', label: knowledgeText.filter_expired ?? 'Utløpt' },
        { value: 'archived', label: knowledgeText.filter_archived ?? 'Arkivert' },
    ];

    const selectedCategoryId = form.data.document_category_id
        ? Number(form.data.document_category_id)
        : null;
    const selectedTopicId = form.data.document_topic_id
        ? Number(form.data.document_topic_id)
        : null;

    const availableTopics = useMemo(() => {
        if (!selectedCategoryId) {
            return [];
        }

        const category = (Array.isArray(documentCategoryOptions) ? documentCategoryOptions : [])
            .find((cat) => Number(cat.id) === selectedCategoryId);

        return Array.isArray(category?.topics) ? category.topics : [];
    }, [selectedCategoryId, documentCategoryOptions]);

    const selectedCategoryHasNoTopics = selectedCategoryId !== null && availableTopics.length === 0;

    const handleCategoryChange = (newCategoryId) => {
        const numericCategoryId = newCategoryId === '' ? null : Number(newCategoryId);
        form.setData('document_category_id', numericCategoryId);

        const categoryTopics = numericCategoryId !== null
            ? (Array.isArray(documentCategoryOptions) ? documentCategoryOptions : [])
                .find((cat) => Number(cat.id) === numericCategoryId)?.topics ?? []
            : [];

        const isCurrentTopicStillValid = selectedTopicId !== null
            && categoryTopics.some((topic) => Number(topic.id) === selectedTopicId);

        if (!isCurrentTopicStillValid) {
            form.setData('document_topic_id', null);
        }
    };

    const ownershipSelect = (
        <label className="space-y-2">
            <span className="text-base font-medium text-slate-700">{ownershipLabel}</span>
            <select
                value={selectedOwnershipType}
                onChange={(event) => form.setData('ownership_type', event.target.value)}
                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
            >
                {selectableOwnershipOptions.map((option) => (
                    <option
                        key={option.value}
                        value={option.value}
                        disabled={option.selectable === false && option.value !== selectedOwnershipType}
                    >
                        {option.label}
                    </option>
                ))}
            </select>
            {ownershipHelpText ? (
                <p className="text-base leading-5 text-slate-600">
                    {ownershipHelpText}
                </p>
            ) : null}
            {form.errors.ownership_type ? <p className="text-base text-rose-600">{form.errors.ownership_type}</p> : null}
        </label>
    );

    const documentOwnerSelect = knowledgeItem ? (
        <label className="space-y-2">
            <span className="text-base font-medium text-slate-700">{documentOwnerLabel}</span>
            <select
                value={selectedDocumentOwnerUserId}
                onChange={(event) => form.setData('owner_user_id', event.target.value)}
                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
            >
                <option value="">{notSetLabel}</option>
                {documentOwnerOptions.map((option) => (
                    <option key={option.id} value={option.id}>
                        {option.label}
                    </option>
                ))}
            </select>
            {form.errors.owner_user_id ? <p className="text-base text-rose-600">{form.errors.owner_user_id}</p> : null}
        </label>
    ) : null;

    const documentStatusSelect = (
        <label className="space-y-2">
            <span className="flex items-center gap-1.5 text-base font-medium text-slate-700">
                {documentStatusLabel}
                <InfoHint label="Forklaring for Status" text={documentStatusHelpText} />
            </span>
            <select
                value={form.data.document_status ?? 'active'}
                onChange={(event) => form.setData('document_status', event.target.value)}
                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
            >
                {documentStatusOptions.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            {form.errors.document_status ? <p className="text-base text-rose-600">{form.errors.document_status}</p> : null}
        </label>
    );

    const reviewDatesSection = (
        <div className="grid gap-4 sm:grid-cols-2">
            <label className="space-y-2">
                <span className="text-base font-medium text-slate-700">{lastReviewedAtLabel}</span>
                <input
                    type="date"
                    value={form.data.last_reviewed_at ?? ''}
                    onChange={(event) => form.setData('last_reviewed_at', event.target.value || null)}
                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                />
                {form.errors.last_reviewed_at ? <p className="text-base text-rose-600">{form.errors.last_reviewed_at}</p> : null}
            </label>
            <label className="space-y-2">
                <span className="text-base font-medium text-slate-700">{reviewDueAtLabel}</span>
                <input
                    type="date"
                    value={form.data.review_due_at ?? ''}
                    onChange={(event) => form.setData('review_due_at', event.target.value || null)}
                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                />
                {form.errors.review_due_at ? <p className="text-base text-rose-600">{form.errors.review_due_at}</p> : null}
            </label>
        </div>
    );

    const documentCategorySelect = (
        <label className="space-y-2">
            <span className="text-base font-medium text-slate-700">{documentCategoryLabel}</span>
            <select
                value={selectedCategoryId ?? ''}
                onChange={(event) => handleCategoryChange(event.target.value)}
                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
            >
                <option value="">{selectDocumentCategoryText}</option>
                {(Array.isArray(documentCategoryOptions) ? documentCategoryOptions : []).map((category) => (
                    <option key={category.id} value={category.id}>
                        {category.name}
                    </option>
                ))}
            </select>
            {form.errors.document_category_id ? <p className="text-base text-rose-600">{form.errors.document_category_id}</p> : null}
        </label>
    );

    const documentTopicSelect = (
        <div className="space-y-2">
            <span className="text-base font-medium text-slate-700">{documentTopicLabel}</span>
            {selectedCategoryId === null ? (
                <div className="flex h-11 items-center rounded-xl border border-slate-200 bg-slate-50 px-4 text-base text-slate-600">
                    {selectDocumentCategoryFirstText}
                </div>
            ) : selectedCategoryHasNoTopics ? (
                <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-base leading-5 text-amber-700">
                    {noTopicsAvailableText}
                </div>
            ) : (
                <select
                    value={selectedTopicId ?? ''}
                    onChange={(event) => form.setData('document_topic_id', event.target.value === '' ? null : Number(event.target.value))}
                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                >
                    <option value="">{selectDocumentTopicText}</option>
                    {availableTopics.map((topic) => (
                        <option key={topic.id} value={topic.id}>
                            {topic.name}
                        </option>
                    ))}
                </select>
            )}
            {form.errors.document_topic_id ? <p className="text-base text-rose-600">{form.errors.document_topic_id}</p> : null}
        </div>
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
                                <label htmlFor="knowledge-document" className="text-base font-medium text-slate-700">
                                    Velg dokument
                                </label>
                                <div className="flex min-h-[56px] items-center gap-4 rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                                    <label
                                        htmlFor="knowledge-document"
                                        className="flex min-w-0 flex-1 cursor-pointer items-center gap-4"
                                    >
                                        <span className="inline-flex shrink-0 items-center rounded-full bg-violet-600 px-4 py-2 text-base font-medium text-white transition hover:bg-violet-700">
                                            Velg fil
                                        </span>
                                        <span className="min-w-0 flex-1 text-base text-slate-600">
                                            {selectedDocumentLabel}
                                        </span>
                                    </label>
                                    <input
                                        id="knowledge-document"
                                        type="file"
                                        accept=".pdf,.doc,.docx,.xls,.xlsx"
                                        onChange={(event) => form.setData('document', event.target.files?.[0] ?? null)}
                                        className="sr-only"
                                    />
                                </div>
                                <p className="text-base leading-5 text-slate-600">
                                    Tillatte filtyper: PDF, DOC, DOCX, XLS, XLSX. Maks 20 MB per fil.
                                </p>
                                {form.errors.document && !form.errors.duplicate_file ? <p className="text-base text-rose-600">{form.errors.document}</p> : null}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-4">
                                {documentCategorySelect}

                                {documentTopicSelect}

                                {ownershipSelect}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-4">
                                <label className="space-y-2">
                                    <span className="flex items-center gap-1.5 text-base font-medium text-slate-700">
                                        {knowledgeText.ai_usage_label ?? 'Kan brukes av AI'}
                                        <InfoHint
                                            label="Forklaring for Kan brukes av AI"
                                            text={knowledgeText.ai_usage_help ?? 'Aktiver for å inkludere dokumentet som grunnlag i AI-arbeid. Deaktiver for å holde dokumentet utenfor AI-kjøringer uten å deaktivere det.'}
                                        />
                                    </span>
                                    <label className="flex min-h-11 items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 text-base text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={form.data.ai_usage_enabled}
                                            onChange={(event) => form.setData('ai_usage_enabled', event.target.checked)}
                                            className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                                        />
                                        <span>{form.data.ai_usage_enabled ? (knowledgeText.ai_usage_on ?? 'Brukes av AI') : (knowledgeText.ai_usage_off ?? 'Brukes ikke av AI')}</span>
                                    </label>
                                </label>

                                {documentStatusSelect}
                            </div>

                            {reviewDatesSection}
                        </div>

                        <button
                            type="submit"
                            disabled={form.processing}
                            className="inline-flex items-center justify-center rounded-2xl bg-violet-600 px-5 py-3 text-base font-semibold text-white shadow-sm transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60 lg:mt-7"
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
                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-2.5 py-1 text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                {knowledgeItem.extraction_status_label}
                            </span>
                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-2.5 py-1 text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                Tekstbiter: {Number(knowledgeItem.chunk_count ?? 0)}
                            </span>
                        </div>

                        <div className="grid gap-3 text-base text-slate-600 sm:grid-cols-2">
                            <div>
                                <div className="text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                    Lastet opp
                                </div>
                                <div>{formatDateTime(knowledgeItem.uploaded_at)}</div>
                            </div>
                            <div>
                                <div className="text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                    Størrelse
                                </div>
                                <div>{formatFileSize(knowledgeItem.file_size_bytes)}</div>
                            </div>
                            <div>
                                <div className="text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                    Lastet opp av
                                </div>
                                <div>{knowledgeItem.uploaded_by ?? '—'}</div>
                            </div>
                            <div>
                                <div className="text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                    Utdrag
                                </div>
                                <div className="whitespace-pre-wrap break-words leading-6">
                                    {visibleContentExcerpt || 'Ingen ekstrahert tekst.'}
                                </div>
                                {hasLongContentExcerpt ? (
                                    <button
                                        type="button"
                                        onClick={() => setContentExcerptExpanded((currentValue) => !currentValue)}
                                        className="mt-1 text-base font-semibold text-violet-700 transition hover:text-violet-900"
                                    >
                                        {contentExcerptExpanded ? 'Vis mindre' : 'Mer'}
                                    </button>
                                ) : null}
                            </div>
                        </div>

                        {knowledgeItem.extraction_error ? (
                            <p className="text-base text-rose-600">
                                {knowledgeItem.extraction_error}
                            </p>
                        ) : null}

                        <p className="text-base text-slate-600">
                            For å endre innholdet, last opp et nytt dokument.
                        </p>
                    </section>
                ) : null}

                <div className="grid gap-4 sm:grid-cols-2">
                    {ownershipSelect}
                    {documentOwnerSelect}
                </div>

                <div className="grid gap-4 sm:grid-cols-4">
                    {documentCategorySelect}

                    {documentTopicSelect}

                    <label className="space-y-2">
                        <span className="flex items-center gap-1.5 text-base font-medium text-slate-700">
                            {knowledgeText.ai_usage_label ?? 'Kan brukes av AI'}
                            <InfoHint
                                label="Forklaring for Kan brukes av AI"
                                text={knowledgeText.ai_usage_help ?? 'Aktiver for å inkludere dokumentet som grunnlag i AI-arbeid. Deaktiver for å holde dokumentet utenfor AI-kjøringer uten å deaktivere det.'}
                            />
                        </span>
                        <label className="flex min-h-11 items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 text-base text-slate-700">
                            <input
                                type="checkbox"
                                checked={form.data.ai_usage_enabled}
                                onChange={(event) => form.setData('ai_usage_enabled', event.target.checked)}
                                className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                            />
                            <span>{form.data.ai_usage_enabled ? (knowledgeText.ai_usage_on ?? 'Brukes av AI') : (knowledgeText.ai_usage_off ?? 'Brukes ikke av AI')}</span>
                        </label>
                    </label>
                </div>

                <div className="grid gap-4 sm:grid-cols-4">
                    {documentStatusSelect}
                </div>

                {reviewDatesSection}

                <div className={classNames(
                    'flex flex-col gap-3 sm:flex-row',
                    deleteUrl ? 'sm:justify-between' : 'sm:justify-end',
                )}>
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <Link
                            href={backHref}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                        >
                            Tilbake
                        </Link>
                        {deleteUrl ? (
                            <button
                                type="button"
                                onClick={deleteKnowledgeItem}
                                disabled={deleting}
                                className="inline-flex min-h-11 items-center justify-center rounded-xl border border-rose-200 bg-white px-4 py-2.5 text-base font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {deleting ? 'Sletter...' : 'Slett'}
                            </button>
                        ) : null}
                    </div>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-base font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {form.processing ? 'Lagrer...' : submitLabel}
                    </button>
                </div>
            </form>
        </section>
    );
}
