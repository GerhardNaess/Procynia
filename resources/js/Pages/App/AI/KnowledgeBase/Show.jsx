import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';

const TAB_OPTIONS = [
    { value: 'chunks', label: 'Chunks' },
    { value: 'metadata', label: 'Metadata' },
    { value: 'history', label: 'Historikk' },
];

const DOCUMENT_STATUS_META = {
    review: {
        label: 'Trenger review',
        className: 'bg-amber-100 text-amber-800 ring-amber-200',
    },
    processing: {
        label: 'Under prosessering',
        className: 'bg-sky-100 text-sky-700 ring-sky-200',
    },
    approved: {
        label: 'Godkjent',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
    failed: {
        label: 'Feilet',
        className: 'bg-rose-100 text-rose-700 ring-rose-200',
    },
};

const CHUNK_REVIEW_STATUS_META = {
    pending_review: {
        label: 'Trenger review',
        className: 'bg-amber-100 text-amber-800 ring-amber-200',
    },
    approved: {
        label: 'Godkjent',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
    rejected: {
        label: 'Avvist',
        className: 'bg-rose-100 text-rose-700 ring-rose-200',
    },
};

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function normalizeSearchText(value) {
    return String(value ?? '').trim().toLowerCase();
}

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

function formatFileTypeLabel(mimeType) {
    const value = String(mimeType ?? '').trim().toLowerCase();

    if (value === '') {
        return '—';
    }

    if (value === 'application/pdf') {
        return 'PDF';
    }

    if (
        value === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        || value === 'application/msword'
    ) {
        return 'Word-dokument';
    }

    if (
        value === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        || value === 'application/vnd.ms-excel'
    ) {
        return 'Excel-dokument';
    }

    if (value === 'text/plain') {
        return 'Tekstfil';
    }

    return mimeType;
}

function normalizeChunkKeywordList(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    return value
        .map((item) => String(item ?? '').replace(/\s+/g, ' ').trim())
        .filter((item) => item !== '');
}

function formatChunkKeywordList(value) {
    const keywords = normalizeChunkKeywordList(value);

    return keywords.length > 0 ? keywords.join(', ') : '—';
}

function getDocumentStatus(item) {
    if (item?.extraction_status === 'failed') {
        return 'failed';
    }

    if (item?.extraction_status === 'pending') {
        return 'processing';
    }

    if (item?.extraction_status === 'completed' && !item?.is_active) {
        return 'review';
    }

    return 'approved';
}

function getChunkStatus(chunk) {
    if (chunk?.embedding_error) {
        return 'failed';
    }

    if (chunk?.embedding_generated_at) {
        return 'ready';
    }

    return 'processing';
}

function getChunkReviewStatus(chunk) {
    if (chunk?.review_status === 'approved') {
        return 'approved';
    }

    if (chunk?.review_status === 'rejected') {
        return 'rejected';
    }

    return 'pending_review';
}

function getChunkReviewStatusMeta(chunk) {
    return CHUNK_REVIEW_STATUS_META[getChunkReviewStatus(chunk)] ?? CHUNK_REVIEW_STATUS_META.pending_review;
}

function getChunkDisplayTitle(chunk, index = 0) {
    const title = String(chunk?.title ?? '').trim();

    return title !== '' ? title : `Chunk ${index + 1}`;
}

function buildHistoryEntries(item, locale, status) {
    const entries = [];

    if (item?.uploaded_at) {
        entries.push({
            label: 'Lagt opp',
            time: formatDateTime(item.uploaded_at, locale),
            text: item?.uploaded_by ? `Av ${item.uploaded_by}` : 'Lastet opp i kunnskapsbasen.',
        });
    }

    if (item?.updated_at && item.updated_at !== item.uploaded_at) {
        entries.push({
            label: 'Sist endret',
            time: formatDateTime(item.updated_at, locale),
            text: 'Metadata eller dokumentstatus ble sist lagret.',
        });
    }

    if (status === 'failed') {
        entries.push({
            label: 'Ekstraksjon',
            time: item?.updated_at ? formatDateTime(item.updated_at, locale) : '—',
            text: item?.extraction_error || 'Tekstuttrekk feilet.',
        });
    } else if (status === 'processing') {
        entries.push({
            label: 'Ekstraksjon',
            time: item?.updated_at ? formatDateTime(item.updated_at, locale) : '—',
            text: 'Dokumentet er under prosessering.',
        });
    } else {
        entries.push({
            label: 'Ekstraksjon',
            time: item?.updated_at ? formatDateTime(item.updated_at, locale) : '—',
            text: 'Tekst er ekstrahert og klargjort for chunk-visning.',
        });
    }

    return entries;
}

function getChunkRangeLabel(chunk) {
    const startOffset = Number(chunk?.start_offset ?? 0);
    const endOffset = Number(chunk?.end_offset ?? 0);

    if (!Number.isFinite(startOffset) || !Number.isFinite(endOffset) || endOffset <= 0) {
        return 'Posisjon —';
    }

    return `Posisjon ${startOffset + 1}–${endOffset}`;
}

function DocumentIcon(props) {
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true" {...props}>
            <path d="M7 3.75h6.2L18.5 9v11.25A1.75 1.75 0 0 1 16.75 22H7.25A1.75 1.75 0 0 1 5.5 20.25v-15A1.25 1.25 0 0 1 6.75 4h.25" strokeLinecap="round" strokeLinejoin="round" />
            <path d="M13.25 4V8.5h4.5" strokeLinecap="round" strokeLinejoin="round" />
            <path d="M8.5 12.25h7M8.5 15.5h7" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

export default function KnowledgeBaseShow({
    pageTitle = 'Kunnskapsdokumenter',
    knowledgeItem = null,
    indexUrl = '/app/ai/knowledge-base',
    summaryUpdateUrl = '/app/ai/knowledge-base',
    editUrl = '/app/ai/knowledge-base',
}) {
    const { locale = 'nb-NO' } = usePage().props;
    const [activeTab, setActiveTab] = useState('chunks');
    const [selectedChunkId, setSelectedChunkId] = useState(knowledgeItem?.chunks?.[0]?.id ?? null);
    const [chunkReviewRequest, setChunkReviewRequest] = useState(null);
    const [isChunkMetadataEditing, setIsChunkMetadataEditing] = useState(false);
    const [showChunkSystemMetadata, setShowChunkSystemMetadata] = useState(false);
    const tabsRef = useRef(null);

    const documentTitle = knowledgeItem?.original_filename ?? knowledgeItem?.title ?? 'Kunnskapsdokument';
    const documentStatus = getDocumentStatus(knowledgeItem);
    const documentStatusMeta = DOCUMENT_STATUS_META[documentStatus] ?? DOCUMENT_STATUS_META.review;
    const chunks = Array.isArray(knowledgeItem?.chunks) ? knowledgeItem.chunks : [];
    const totalChunksCount = Number(knowledgeItem?.chunk_count ?? chunks.length);
    const readyChunksCount = chunks.filter((chunk) => getChunkStatus(chunk) === 'ready').length;
    const activeLabel = knowledgeItem?.is_active_label ?? (knowledgeItem?.is_active ? 'Aktiv' : 'Inaktiv');
    const chunkReviewCounts = chunks.reduce((accumulator, chunk) => {
        accumulator[getChunkReviewStatus(chunk)] = (accumulator[getChunkReviewStatus(chunk)] ?? 0) + 1;

        return accumulator;
    }, {
        pending_review: 0,
        approved: 0,
        rejected: 0,
    });
    const reviewProgressCount = chunkReviewCounts.approved + chunkReviewCounts.rejected;
    const selectedChunk = chunks.find((chunk) => chunk.id === selectedChunkId) ?? chunks[0] ?? null;
    const selectedChunkIndex = selectedChunk ? chunks.findIndex((chunk) => chunk.id === selectedChunk.id) : -1;
    const selectedChunkReviewStatus = selectedChunk ? getChunkReviewStatus(selectedChunk) : 'pending_review';
    const selectedChunkReviewStatusMeta = CHUNK_REVIEW_STATUS_META[selectedChunkReviewStatus] ?? CHUNK_REVIEW_STATUS_META.pending_review;
    const selectedChunkDisplayTitle = selectedChunk ? getChunkDisplayTitle(selectedChunk, selectedChunkIndex) : 'Chunk';
    const progressPercent = totalChunksCount > 0
        ? Math.round((readyChunksCount / totalChunksCount) * 100)
        : 0;
    const summaryInitialText = normalizeSearchText(knowledgeItem?.summary).length > 0
        ? String(knowledgeItem.summary)
        : (normalizeSearchText(knowledgeItem?.content_excerpt).length > 0
            ? String(knowledgeItem.content_excerpt)
            : '');
    const summaryForm = useForm({
        summary: summaryInitialText,
    });
    const chunkMetadataForm = useForm({
        title: '',
        ai_summary: '',
        service_product_tag: '',
        theme_tag: '',
        topic: '',
        sub_topic: '',
        keywords: '',
    });
    const summaryHasOverflow = normalizeSearchText(summaryForm.data.summary).length > 180 || summaryForm.data.summary.includes('\n');
    const historyEntries = buildHistoryEntries(knowledgeItem, locale, documentStatus);

    useEffect(() => {
        if (chunks.length === 0) {
            if (selectedChunkId !== null) {
                setSelectedChunkId(null);
            }

            return;
        }

        if (!selectedChunkId || !chunks.some((chunk) => chunk.id === selectedChunkId)) {
            setSelectedChunkId(chunks[0].id);
        }
    }, [chunks, selectedChunkId]);

    useEffect(() => {
        if (!selectedChunk) {
            setIsChunkMetadataEditing(false);
            setShowChunkSystemMetadata(false);
            return;
        }

        chunkMetadataForm.setData('title', selectedChunk.title ?? '');
        chunkMetadataForm.setData('ai_summary', selectedChunk.ai_summary ?? '');
        chunkMetadataForm.setData('service_product_tag', selectedChunk.service_product_tag ?? '');
        chunkMetadataForm.setData('theme_tag', selectedChunk.theme_tag ?? '');
        chunkMetadataForm.setData('topic', selectedChunk.topic ?? '');
        chunkMetadataForm.setData('sub_topic', selectedChunk.sub_topic ?? '');
        chunkMetadataForm.setData('keywords', normalizeChunkKeywordList(selectedChunk.keywords).join(', '));
        setIsChunkMetadataEditing(false);
        setShowChunkSystemMetadata(false);
    }, [
        selectedChunk?.id,
        selectedChunk?.title,
        selectedChunk?.ai_summary,
        selectedChunk?.service_product_tag,
        selectedChunk?.theme_tag,
        selectedChunk?.topic,
        selectedChunk?.sub_topic,
        selectedChunk?.keywords,
    ]);

    const submitSummary = (event) => {
        event.preventDefault();

        summaryForm.patch(summaryUpdateUrl, {
            preserveScroll: true,
        });
    };

    const openChunksTab = () => {
        setActiveTab('chunks');

        window.requestAnimationFrame(() => {
            tabsRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    };

    const selectChunk = (chunkId) => {
        setSelectedChunkId(chunkId);
    };

    const beginChunkMetadataEdit = () => {
        if (!selectedChunk) {
            return;
        }

        setIsChunkMetadataEditing(true);
    };

    const cancelChunkMetadataEdit = () => {
        if (!selectedChunk) {
            return;
        }

        chunkMetadataForm.setData('title', selectedChunk.title ?? '');
        chunkMetadataForm.setData('ai_summary', selectedChunk.ai_summary ?? '');
        chunkMetadataForm.setData('service_product_tag', selectedChunk.service_product_tag ?? '');
        chunkMetadataForm.setData('theme_tag', selectedChunk.theme_tag ?? '');
        chunkMetadataForm.setData('topic', selectedChunk.topic ?? '');
        chunkMetadataForm.setData('sub_topic', selectedChunk.sub_topic ?? '');
        chunkMetadataForm.setData('keywords', normalizeChunkKeywordList(selectedChunk.keywords).join(', '));
        chunkMetadataForm.clearErrors();
        setIsChunkMetadataEditing(false);
    };

    const submitChunkMetadata = (event) => {
        event.preventDefault();

        if (!selectedChunk || !selectedChunk.metadata_update_url) {
            return;
        }

        chunkMetadataForm.patch(selectedChunk.metadata_update_url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setIsChunkMetadataEditing(false);
            },
        });
    };

    const updateSelectedChunkReviewStatus = (reviewStatus) => {
        if (!selectedChunk || !selectedChunk.review_status_update_url) {
            return;
        }

        router.patch(selectedChunk.review_status_update_url, {
            review_status: reviewStatus,
        }, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                setChunkReviewRequest({
                    chunkId: selectedChunk.id,
                    reviewStatus,
                });
            },
            onFinish: () => {
                setChunkReviewRequest(null);
            },
        });
    };

    const goToNextChunk = () => {
        if (selectedChunkIndex < 0 || selectedChunkIndex >= chunks.length - 1) {
            return;
        }

        setSelectedChunkId(chunks[selectedChunkIndex + 1].id);
    };

    const selectedChunkSystemMetadata = selectedChunk ? [
        { label: 'Chunk ID', value: selectedChunk.id ?? '—' },
        { label: 'Dokument ID', value: selectedChunk.knowledge_item_id ?? knowledgeItem?.id ?? '—' },
        { label: 'Seksjon', value: selectedChunk.section_title || '—' },
        { label: 'Seksjonssti', value: selectedChunk.section_path || '—' },
        { label: 'Chunk index', value: selectedChunk.chunk_index !== null && selectedChunk.chunk_index !== undefined ? selectedChunk.chunk_index + 1 : '—' },
        { label: 'Posisjon start', value: selectedChunk.start_offset !== null && selectedChunk.start_offset !== undefined ? selectedChunk.start_offset + 1 : '—' },
        { label: 'Posisjon slutt', value: selectedChunk.end_offset !== null && selectedChunk.end_offset !== undefined ? selectedChunk.end_offset : '—' },
        { label: 'Kilde', value: selectedChunk.source_filename ?? knowledgeItem?.original_filename ?? '—' },
        { label: 'Filtype', value: selectedChunk.source_filetype ?? knowledgeItem?.mime_type ?? '—' },
        { label: 'Review-status', value: selectedChunk.review_status_label ?? selectedChunk.review_status ?? '—' },
        { label: 'Embeddingmodell', value: selectedChunk.embedding_model ?? '—' },
        { label: 'Embedding generert', value: selectedChunk.embedding_generated_at ? formatDateTime(selectedChunk.embedding_generated_at, locale) : '—' },
    ] : [];

    return (
        <CustomerAppLayout title={pageTitle} showPageTitle={false}>
            <div className="space-y-7">
                <section className="rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                        <div className="space-y-4">
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
                                <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-slate-950 text-white shadow-sm">
                                    <DocumentIcon className="h-7 w-7" />
                                </div>

                                <div className="space-y-3">
                                    <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                        Kunnskapsdokumenter
                                    </div>
                                    <h1 className="max-w-4xl text-4xl font-semibold tracking-tight text-slate-950">
                                        {documentTitle}
                                    </h1>
                                    <div className="flex flex-wrap gap-2">
                                        <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-600">
                                            {knowledgeItem?.document_type_label ?? '—'}
                                        </span>
                                        <span className={classNames(
                                            'inline-flex rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset',
                                            documentStatusMeta.className,
                                        )}>
                                            {documentStatusMeta.label}
                                        </span>
                                        <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600">
                                            {activeLabel}
                                        </span>
                                        <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600">
                                            {totalChunksCount > 0 ? `${totalChunksCount} chunks` : 'Ingen chunks'}
                                        </span>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-500">
                                        <span>
                                            Sist oppdatert: <span className="font-medium text-slate-900">{formatDateTime(knowledgeItem?.updated_at ?? knowledgeItem?.uploaded_at, locale)}</span>
                                        </span>
                                        <span>
                                            Eier: <span className="font-medium text-slate-900">{knowledgeItem?.uploaded_by ?? 'Ukjent'}</span>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-3 lg:justify-end">
                            <Link
                                href={indexUrl}
                                className="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                            >
                                Tilbake
                            </Link>
                            <Link
                                href={editUrl}
                                className="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                            >
                                Rediger metadata
                            </Link>
                            <button
                                type="button"
                                onClick={openChunksTab}
                                className="inline-flex items-center justify-center rounded-2xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-violet-700"
                            >
                                Fortsett review
                            </button>
                        </div>
                    </div>
                </section>

                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <article className="h-full sm:col-span-2 xl:col-span-2 rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <form onSubmit={submitSummary} className="flex h-full flex-col">
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                    Dokumentoppsummering
                                </div>
                                {summaryHasOverflow ? (
                                    <span className="inline-flex shrink-0 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-medium text-slate-600">
                                        Mer
                                    </span>
                                ) : null}
                            </div>

                            <textarea
                                value={summaryForm.data.summary}
                                onChange={(event) => summaryForm.setData('summary', event.target.value)}
                                rows={2}
                                placeholder="Skriv en kort oppsummering av dokumentet."
                                className="mt-3 h-[92px] w-full resize-none rounded-[18px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-700 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            />

                            <div className="mt-3 flex items-end justify-between gap-3">
                                <p className="max-w-[15rem] text-xs leading-5 text-slate-500">
                                    Rediger direkte her.
                                </p>
                                <button
                                    type="submit"
                                    disabled={summaryForm.processing}
                                    className="inline-flex items-center justify-center rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {summaryForm.processing ? 'Lagrer...' : 'Lagre'}
                                </button>
                            </div>
                        </form>
                    </article>

                    <article className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                            Status / fremdrift
                        </div>
                        <div className="mt-3 flex items-center justify-between gap-3">
                            <span className={classNames(
                                'inline-flex rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset',
                                documentStatusMeta.className,
                            )}>
                                {documentStatusMeta.label}
                            </span>
                            <span className="text-sm font-medium text-slate-700">
                                {totalChunksCount > 0 ? `${readyChunksCount} / ${totalChunksCount} chunks` : 'Ingen chunks ennå'}
                            </span>
                        </div>
                        <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                            <div
                                className="h-full rounded-full bg-violet-500 transition-[width]"
                                style={{ width: `${progressPercent}%` }}
                            />
                        </div>
                        <p className="mt-2 text-xs text-slate-500">
                            {totalChunksCount > 0
                                ? `${progressPercent}% av dokumentets chunks er ferdig ekstrahert og klare for gjennomgang.`
                                : 'Chunking eller review er ikke ferdig enda.'}
                        </p>
                    </article>

                    <article className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                            Dokumentinfo
                        </div>
                        <dl className="mt-3 space-y-3 text-sm">
                            <div className="flex items-start justify-between gap-4">
                                <dt className="text-slate-500">Type</dt>
                                <dd className="text-right font-medium text-slate-950">{knowledgeItem?.document_type_label ?? '—'}</dd>
                            </div>
                            <div className="flex items-start justify-between gap-4">
                                <dt className="text-slate-500">Aktivitet</dt>
                                <dd className="text-right font-medium text-slate-950">{activeLabel}</dd>
                            </div>
                            <div className="flex items-start justify-between gap-4">
                                <dt className="text-slate-500">Filstørrelse</dt>
                                <dd className="text-right font-medium text-slate-950">{formatFileSize(knowledgeItem?.file_size_bytes)}</dd>
                            </div>
                            <div className="flex items-start justify-between gap-4">
                                <dt className="text-slate-500">Filtype</dt>
                                <dd className="text-right font-medium text-slate-950">{formatFileTypeLabel(knowledgeItem?.mime_type)}</dd>
                            </div>
                            <div className="flex items-start justify-between gap-4">
                                <dt className="text-slate-500">Eier</dt>
                                <dd className="text-right font-medium text-slate-950">{knowledgeItem?.uploaded_by ?? '—'}</dd>
                            </div>
                            <div className="flex items-start justify-between gap-4">
                                <dt className="text-slate-500">Sist oppdatert</dt>
                                <dd className="text-right font-medium text-slate-950">{formatDateTime(knowledgeItem?.updated_at ?? knowledgeItem?.uploaded_at, locale)}</dd>
                            </div>
                        </dl>
                    </article>
                </section>

                <section
                    ref={tabsRef}
                    className="rounded-[22px] border border-slate-200 bg-slate-50/70 p-2 shadow-[0_8px_24px_rgba(15,23,42,0.04)]"
                >
                    <div className="flex flex-wrap gap-2">
                        {TAB_OPTIONS.map((option) => {
                            const isActive = activeTab === option.value;

                            return (
                                <button
                                    key={option.value}
                                    type="button"
                                    onClick={() => setActiveTab(option.value)}
                                    className={classNames(
                                        'inline-flex items-center justify-center rounded-full border px-4 py-2 text-sm font-medium transition',
                                        isActive
                                            ? 'border-violet-200 bg-violet-50 text-violet-700'
                                            : 'border-transparent bg-white text-slate-600 hover:border-slate-200 hover:text-slate-950',
                                    )}
                                >
                                    {option.label}
                                </button>
                            );
                        })}
                    </div>
                </section>

                <section className="rounded-[22px] border border-slate-200 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    {activeTab === 'chunks' ? (
                        <div className="space-y-5">
                            <div className="rounded-[20px] border border-slate-200 bg-slate-50/70 p-4">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="flex flex-wrap gap-2">
                                        <span className="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700">
                                            Godkjent {chunkReviewCounts.approved}
                                        </span>
                                        <span className="inline-flex rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700">
                                            Trenger review {chunkReviewCounts.pending_review}
                                        </span>
                                        <span className="inline-flex rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-medium text-rose-700">
                                            Avvist {chunkReviewCounts.rejected}
                                        </span>
                                    </div>

                                    <div className="text-sm font-medium text-slate-600">
                                        {totalChunksCount > 0
                                            ? `${reviewProgressCount} av ${totalChunksCount} chunks er manuelt godkjent`
                                            : 'Ingen chunks tilgjengelig'}
                                    </div>
                                </div>

                                <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div
                                        className="h-full rounded-full bg-violet-500 transition-[width]"
                                        style={{ width: `${totalChunksCount > 0 ? Math.round((reviewProgressCount / totalChunksCount) * 100) : 0}%` }}
                                    />
                                </div>
                            </div>

                            {chunks.length === 0 ? (
                                <div className="rounded-[20px] border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center">
                                    <div className="text-lg font-semibold text-slate-900">
                                        Ingen chunks er tilgjengelige ennå.
                                    </div>
                                    <p className="mt-2 text-sm text-slate-500">
                                        Dokumentet har enten ikke blitt chunket ennå, eller tekstuttrekket ga ingen brukbare tekstbiter.
                                    </p>
                                </div>
                            ) : (
                                <div className="grid gap-5 xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
                                    <div className="rounded-[20px] border border-slate-200 bg-slate-50/70 p-4">
                                        <div className="flex items-center justify-between gap-3">
                                            <div>
                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                    Chunk-liste
                                                </div>
                                                <div className="mt-1 text-sm text-slate-500">
                                                    Klikk en chunk for å åpne den.
                                                </div>
                                            </div>
                                            <div className="text-xs font-medium text-slate-500">
                                                {chunks.length} chunks
                                            </div>
                                        </div>

                                        <div className="mt-4 space-y-3">
                                            {chunks.map((chunk, index) => {
                                                const isSelected = selectedChunk?.id === chunk.id;
                                                const reviewStatusMeta = getChunkReviewStatusMeta(chunk);
                                                const previewText = chunk.content_preview || 'Ingen forhåndsvisning tilgjengelig.';

                                                return (
                                                    <button
                                                        key={chunk.id}
                                                        type="button"
                                                        onClick={() => selectChunk(chunk.id)}
                                                        aria-pressed={isSelected}
                                                        className={classNames(
                                                            'block w-full rounded-[18px] border p-4 text-left transition',
                                                            isSelected
                                                                ? 'border-violet-300 bg-white shadow-[0_8px_24px_rgba(124,58,237,0.08)]'
                                                                : 'border-slate-200 bg-white hover:border-slate-300 hover:shadow-[0_8px_24px_rgba(15,23,42,0.04)]',
                                                        )}
                                                    >
                                                        <div className="flex items-start justify-between gap-3">
                                                            <div className="space-y-2">
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    <div className="text-sm font-medium text-slate-950">
                                                                        {getChunkDisplayTitle(chunk, index)}
                                                                    </div>
                                                                    {isSelected ? (
                                                                        <span className="inline-flex rounded-full border border-violet-200 bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-700">
                                                                            Valgt
                                                                        </span>
                                                                    ) : null}
                                                                </div>

                                                                <p className="max-h-24 overflow-hidden text-sm leading-6 text-slate-600">
                                                                    {previewText}
                                                                </p>
                                                            </div>

                                                            <span className={classNames(
                                                                'inline-flex shrink-0 rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset',
                                                                reviewStatusMeta.className,
                                                            )}>
                                                                {reviewStatusMeta.label}
                                                            </span>
                                                        </div>

                                                        <div className="mt-3 flex flex-wrap items-center gap-2">
                                                            <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-medium text-slate-600">
                                                                {getChunkRangeLabel(chunk)}
                                                            </span>
                                                            {chunk.embedding_model ? (
                                                                <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-medium text-slate-600">
                                                                    {chunk.embedding_model}
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>

                                    <div className="rounded-[20px] border border-slate-200 bg-white p-5 shadow-[0_4px_18px_rgba(15,23,42,0.03)]">
                                        {selectedChunk ? (
                                            <div className="space-y-5">
                                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                    <div className="space-y-2">
                                                        <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                            Valgt chunk
                                                        </div>
                                                        <h2 className="text-2xl font-semibold tracking-tight text-slate-950">
                                                            {selectedChunkDisplayTitle}
                                                        </h2>
                                                        <div className="flex flex-wrap gap-2">
                                                            <span className={classNames(
                                                                'inline-flex rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset',
                                                                selectedChunkReviewStatusMeta.className,
                                                            )}>
                                                                {selectedChunkReviewStatusMeta.label}
                                                            </span>
                                                            <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-600">
                                                                {selectedChunkIndex >= 0 ? `Chunk ${selectedChunkIndex + 1}` : 'Chunk'}
                                                            </span>
                                                        </div>
                                                    </div>

                                                    <div className="flex flex-wrap gap-2">
                                                        <button
                                                            type="button"
                                                            onClick={() => updateSelectedChunkReviewStatus('approved')}
                                                            disabled={chunkReviewRequest?.chunkId === selectedChunk.id}
                                                            className={classNames(
                                                                'inline-flex items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-60',
                                                                selectedChunkReviewStatus === 'approved'
                                                                    ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                                                                    : 'border border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-100',
                                                            )}
                                                        >
                                                            {chunkReviewRequest?.chunkId === selectedChunk.id && chunkReviewRequest.reviewStatus === 'approved'
                                                                ? 'Lagrer...'
                                                                : 'Godkjenn'}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => updateSelectedChunkReviewStatus('rejected')}
                                                            disabled={chunkReviewRequest?.chunkId === selectedChunk.id}
                                                            className={classNames(
                                                                'inline-flex items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-60',
                                                                selectedChunkReviewStatus === 'rejected'
                                                                    ? 'bg-rose-600 text-white hover:bg-rose-700'
                                                                    : 'border border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100',
                                                            )}
                                                        >
                                                            {chunkReviewRequest?.chunkId === selectedChunk.id && chunkReviewRequest.reviewStatus === 'rejected'
                                                                ? 'Lagrer...'
                                                                : 'Avvis'}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => updateSelectedChunkReviewStatus('pending_review')}
                                                            disabled={chunkReviewRequest?.chunkId === selectedChunk.id}
                                                            className={classNames(
                                                                'inline-flex items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-60',
                                                                selectedChunkReviewStatus === 'pending_review'
                                                                    ? 'bg-amber-600 text-white hover:bg-amber-700'
                                                                    : 'border border-amber-200 bg-amber-50 text-amber-700 hover:border-amber-300 hover:bg-amber-100',
                                                            )}
                                                        >
                                                            {chunkReviewRequest?.chunkId === selectedChunk.id && chunkReviewRequest.reviewStatus === 'pending_review'
                                                                ? 'Lagrer...'
                                                                : 'Trenger review'}
                                                        </button>
                                                    </div>
                                                </div>

                                                <div className="rounded-[20px] border border-slate-200 bg-slate-50/70 p-4">
                                                    <div className="flex flex-wrap items-center gap-2 text-xs font-medium text-slate-500">
                                                        <span className="inline-flex rounded-full border border-slate-200 bg-white px-2.5 py-1 text-slate-600">
                                                            {getChunkRangeLabel(selectedChunk)}
                                                        </span>
                                                        {selectedChunk.embedding_model ? (
                                                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-2.5 py-1 text-slate-600">
                                                                {selectedChunk.embedding_model}
                                                            </span>
                                                        ) : null}
                                                        {selectedChunk.embedding_generated_at ? (
                                                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-2.5 py-1 text-slate-600">
                                                                Embedding klar
                                                            </span>
                                                        ) : null}
                                                        {selectedChunk.embedding_error ? (
                                                            <span className="inline-flex rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-rose-700">
                                                                Embedding feilet
                                                            </span>
                                                        ) : null}
                                                    </div>

                                                    <div className="mt-4 max-h-[28rem] overflow-auto rounded-[18px] border border-slate-200 bg-white p-4">
                                                        <p className="whitespace-pre-wrap text-sm leading-7 text-slate-700">
                                                            {selectedChunk.content || selectedChunk.content_preview || 'Ingen forhåndsvisning tilgjengelig.'}
                                                        </p>
                                                    </div>
                                                </div>

                                                <div className="rounded-[20px] border border-slate-200 bg-white p-5">
                                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                                        <div>
                                                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                Produktmetadata
                                                            </div>
                                                            <p className="mt-1 text-sm text-slate-500">
                                                                Rediger kun feltene brukeren arbeider med.
                                                            </p>
                                                        </div>

                                                        {!isChunkMetadataEditing ? (
                                                            <button
                                                                type="button"
                                                                onClick={beginChunkMetadataEdit}
                                                                className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                            >
                                                                Rediger metadata
                                                            </button>
                                                        ) : (
                                                            <div className="flex flex-wrap gap-2">
                                                                <button
                                                                    type="button"
                                                                    onClick={cancelChunkMetadataEdit}
                                                                    className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                                >
                                                                    Avbryt
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={submitChunkMetadata}
                                                                    disabled={chunkMetadataForm.processing}
                                                                    className="inline-flex items-center justify-center rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                                >
                                                                    {chunkMetadataForm.processing ? 'Lagrer...' : 'Lagre metadata'}
                                                                </button>
                                                            </div>
                                                        )}
                                                    </div>

                                                    {!isChunkMetadataEditing ? (
                                                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                                            <div className="rounded-[18px] border border-slate-200 bg-slate-50/70 p-4 sm:col-span-2">
                                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                    Tittel
                                                                </div>
                                                                <div className="mt-2 text-sm font-medium text-slate-950">
                                                                    {selectedChunk.title ?? 'Ingen tittel'}
                                                                </div>
                                                            </div>
                                                            <div className="rounded-[18px] border border-slate-200 bg-slate-50/70 p-4 sm:col-span-2">
                                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                    AI-generert oppsummering
                                                                </div>
                                                                <div className="mt-2 max-h-32 overflow-auto whitespace-pre-wrap text-sm leading-6 text-slate-700">
                                                                    {selectedChunk.ai_summary || 'Ingen oppsummering lagt til ennå.'}
                                                                </div>
                                                            </div>
                                                            <div className="rounded-[18px] border border-slate-200 bg-slate-50/70 p-4">
                                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                    Tjeneste/produkt-tag
                                                                </div>
                                                                <div className="mt-2 text-sm font-medium text-slate-950">
                                                                    {selectedChunk.service_product_tag || '—'}
                                                                </div>
                                                            </div>
                                                            <div className="rounded-[18px] border border-slate-200 bg-slate-50/70 p-4">
                                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                    Tema-tag
                                                                </div>
                                                                <div className="mt-2 text-sm font-medium text-slate-950">
                                                                    {selectedChunk.theme_tag || '—'}
                                                                </div>
                                                            </div>
                                                            <div className="rounded-[18px] border border-slate-200 bg-slate-50/70 p-4">
                                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                    Topic
                                                                </div>
                                                                <div className="mt-2 text-sm font-medium text-slate-950">
                                                                    {selectedChunk.topic || '—'}
                                                                </div>
                                                            </div>
                                                            <div className="rounded-[18px] border border-slate-200 bg-slate-50/70 p-4">
                                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                    Sub-topic
                                                                </div>
                                                                <div className="mt-2 text-sm font-medium text-slate-950">
                                                                    {selectedChunk.sub_topic || '—'}
                                                                </div>
                                                            </div>
                                                            <div className="rounded-[18px] border border-slate-200 bg-slate-50/70 p-4 sm:col-span-2">
                                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                    Keywords
                                                                </div>
                                                                <div className="mt-2 text-sm font-medium text-slate-950">
                                                                    {formatChunkKeywordList(selectedChunk.keywords)}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <form onSubmit={submitChunkMetadata} className="mt-4 space-y-4">
                                                            <div className="grid gap-4 sm:grid-cols-2">
                                                                <label className="space-y-2 sm:col-span-2">
                                                                    <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        Tittel
                                                                    </span>
                                                                    <input
                                                                        type="text"
                                                                        value={chunkMetadataForm.data.title}
                                                                        onChange={(event) => chunkMetadataForm.setData('title', event.target.value)}
                                                                        placeholder="Gi chunken en tydelig tittel"
                                                                        className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                    />
                                                                </label>

                                                                <label className="space-y-2 sm:col-span-2">
                                                                    <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        AI-generert oppsummering
                                                                    </span>
                                                                    <textarea
                                                                        value={chunkMetadataForm.data.ai_summary}
                                                                        onChange={(event) => chunkMetadataForm.setData('ai_summary', event.target.value)}
                                                                        rows={4}
                                                                        placeholder="Kort oppsummering av hva chunken handler om"
                                                                        className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                    />
                                                                </label>

                                                                <label className="space-y-2">
                                                                    <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        Tjeneste/produkt-tag
                                                                    </span>
                                                                    <input
                                                                        type="text"
                                                                        value={chunkMetadataForm.data.service_product_tag}
                                                                        onChange={(event) => chunkMetadataForm.setData('service_product_tag', event.target.value)}
                                                                        placeholder="F.eks. Prosjektstyring"
                                                                        className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                    />
                                                                </label>

                                                                <label className="space-y-2">
                                                                    <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        Tema-tag
                                                                    </span>
                                                                    <input
                                                                        type="text"
                                                                        value={chunkMetadataForm.data.theme_tag}
                                                                        onChange={(event) => chunkMetadataForm.setData('theme_tag', event.target.value)}
                                                                        placeholder="F.eks. Drift"
                                                                        className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                    />
                                                                </label>

                                                                <label className="space-y-2">
                                                                    <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        Topic
                                                                    </span>
                                                                    <input
                                                                        type="text"
                                                                        value={chunkMetadataForm.data.topic}
                                                                        onChange={(event) => chunkMetadataForm.setData('topic', event.target.value)}
                                                                        placeholder="F.eks. Servicedesk"
                                                                        className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                    />
                                                                </label>

                                                                <label className="space-y-2">
                                                                    <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        Sub-topic
                                                                    </span>
                                                                    <input
                                                                        type="text"
                                                                        value={chunkMetadataForm.data.sub_topic}
                                                                        onChange={(event) => chunkMetadataForm.setData('sub_topic', event.target.value)}
                                                                        placeholder="F.eks. Lærlingordning"
                                                                        className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                    />
                                                                </label>

                                                                <label className="space-y-2 sm:col-span-2">
                                                                    <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        Keywords
                                                                    </span>
                                                                    <input
                                                                        type="text"
                                                                        value={chunkMetadataForm.data.keywords}
                                                                        onChange={(event) => chunkMetadataForm.setData('keywords', event.target.value)}
                                                                        placeholder="Kommaseparerte nøkkelord"
                                                                        className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                    />
                                                                    <p className="text-xs text-slate-500">
                                                                        Lagres som en JSON-array.
                                                                    </p>
                                                                </label>
                                                            </div>
                                                        </form>
                                                    )}
                                                </div>

                                                <div className="rounded-[20px] border border-slate-200 bg-slate-50/70 p-4">
                                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                                        <div>
                                                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                Systemdata
                                                            </div>
                                                            <p className="mt-1 text-sm text-slate-500">
                                                                Kun for sporbarhet og kontroll.
                                                            </p>
                                                        </div>

                                                        <button
                                                            type="button"
                                                            onClick={() => setShowChunkSystemMetadata((current) => !current)}
                                                            className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                        >
                                                            {showChunkSystemMetadata ? 'Skjul systemdata' : 'Vis systemdata'}
                                                        </button>
                                                    </div>

                                                    {showChunkSystemMetadata ? (
                                                        <dl className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                                            {selectedChunkSystemMetadata.map((item) => (
                                                                <div key={item.label} className="rounded-[18px] border border-slate-200 bg-white p-4">
                                                                    <dt className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        {item.label}
                                                                    </dt>
                                                                    <dd className="mt-2 break-words text-sm font-medium text-slate-950">
                                                                        {item.value}
                                                                    </dd>
                                                                </div>
                                                            ))}
                                                        </dl>
                                                    ) : null}
                                                </div>

                                                <div className="flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                                                    <div className="text-sm text-slate-500">
                                                        {selectedChunkIndex >= 0 && chunks.length > 0
                                                            ? `Chunk ${selectedChunkIndex + 1} av ${chunks.length}`
                                                            : 'Chunk'}
                                                    </div>

                                                    <button
                                                        type="button"
                                                        onClick={goToNextChunk}
                                                        disabled={selectedChunkIndex < 0 || selectedChunkIndex >= chunks.length - 1}
                                                        className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        {selectedChunkIndex >= 0 && selectedChunkIndex < chunks.length - 1
                                                            ? 'Neste chunk'
                                                            : 'Siste chunk'}
                                                    </button>
                                                </div>
                                            </div>
                                        ) : (
                                            <div className="rounded-[20px] border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center">
                                                <div className="text-lg font-semibold text-slate-900">
                                                    Ingen chunk er valgt.
                                                </div>
                                                <p className="mt-2 text-sm text-slate-500">
                                                    Velg en chunk i listen for å gå gjennom innholdet.
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}
                        </div>
                    ) : null}

                    {activeTab === 'metadata' ? (
                        <div className="rounded-[20px] border border-slate-200 bg-slate-50/70 p-5">
                            <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                    <dt className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">Type</dt>
                                    <dd className="mt-2 text-sm font-medium text-slate-950">{knowledgeItem?.document_type_label ?? '—'}</dd>
                                </div>
                                <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                    <dt className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">Status</dt>
                                    <dd className="mt-2 text-sm font-medium text-slate-950">{documentStatusMeta.label}</dd>
                                </div>
                                <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                    <dt className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">Aktivitet</dt>
                                    <dd className="mt-2 text-sm font-medium text-slate-950">{activeLabel}</dd>
                                </div>
                                <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                    <dt className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">Filstørrelse</dt>
                                    <dd className="mt-2 text-sm font-medium text-slate-950">{formatFileSize(knowledgeItem?.file_size_bytes)}</dd>
                                </div>
                                <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                    <dt className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">MIME-type</dt>
                                    <dd className="mt-2 text-sm font-medium text-slate-950">{knowledgeItem?.mime_type ?? '—'}</dd>
                                </div>
                                <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                    <dt className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">Eier</dt>
                                    <dd className="mt-2 text-sm font-medium text-slate-950">{knowledgeItem?.uploaded_by ?? '—'}</dd>
                                </div>
                                <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                    <dt className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">Chunks</dt>
                                    <dd className="mt-2 text-sm font-medium text-slate-950">
                                        {totalChunksCount > 0 ? `${readyChunksCount} av ${totalChunksCount}` : 'Ingen chunks'}
                                    </dd>
                                </div>
                                <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                    <dt className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">Sist oppdatert</dt>
                                    <dd className="mt-2 text-sm font-medium text-slate-950">{formatDateTime(knowledgeItem?.updated_at ?? knowledgeItem?.uploaded_at, locale)}</dd>
                                </div>
                            </dl>
                        </div>
                    ) : null}

                    {activeTab === 'history' ? (
                        <div className="space-y-3">
                            {historyEntries.map((entry) => (
                                <div
                                    key={`${entry.label}-${entry.time}`}
                                    className="flex flex-col gap-3 rounded-[20px] border border-slate-200 bg-slate-50/70 px-5 py-4 sm:flex-row sm:items-start sm:justify-between"
                                >
                                    <div className="flex gap-3">
                                        <div className="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-violet-500" />
                                        <div className="space-y-1">
                                            <div className="text-sm font-medium text-slate-900">
                                                {entry.label}
                                            </div>
                                            <p className="text-sm leading-6 text-slate-600">
                                                {entry.text}
                                            </p>
                                        </div>
                                    </div>

                                    <div className="text-sm font-medium text-slate-500">
                                        {entry.time}
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : null}
                </section>

            </div>
        </CustomerAppLayout>
    );
}
