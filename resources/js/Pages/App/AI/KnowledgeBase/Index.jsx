import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../../Components/App/PageHelpButton';
import { KNOWLEDGE_DOCUMENT_TYPE_OPTIONS } from './KnowledgeItemForm';

const DOCUMENT_STATUS_CLASS = {
    failed: 'bg-rose-100 text-rose-700 ring-rose-200',
    processing: 'bg-sky-100 text-sky-700 ring-sky-200',
    draft: 'bg-slate-100 text-slate-600 ring-slate-200',
    pending_review: 'bg-amber-100 text-amber-800 ring-amber-200',
    active: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    expired: 'bg-orange-100 text-orange-700 ring-orange-200',
    archived: 'bg-slate-100 text-slate-400 ring-slate-200',
};

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function formatDate(value, locale, emptyLabel = '') {
    if (!value) {
        return emptyLabel;
    }

    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

function formatFileSize(bytes, emptyLabel = '') {
    const value = Number(bytes ?? 0);

    if (!Number.isFinite(value) || value <= 0) {
        return emptyLabel;
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

function normalizeSearchText(value) {
    return String(value ?? '').trim().toLowerCase();
}

function getKnowledgeDocumentStatus(item) {
    if (item?.extraction_status === 'failed') {
        return 'failed';
    }

    if (item?.extraction_status === 'pending') {
        return 'processing';
    }

    return item?.document_status ?? 'active';
}

function getOwnerInitials(name, emptyLabel = '') {
    const parts = String(name ?? '')
        .trim()
        .split(/\s+/)
        .filter(Boolean);

    if (parts.length === 0) {
        return emptyLabel;
    }

    return parts
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function formatChunkRatio(item, emptyLabel = '') {
    const processed = Number(item?.chunk_count ?? 0);

    if (!Number.isFinite(processed) || processed <= 0) {
        return emptyLabel;
    }

    const total = Math.max(processed + Math.max(2, Math.round(processed * 0.25)), processed);
    return `${processed} / ${total}`;
}

function getKnowledgeDocumentOwnerFilterValue(item) {
    const ownerUserId = String(item?.owner_user_id ?? '').trim();

    if (ownerUserId !== '') {
        return `user:${ownerUserId}`;
    }

    const ownerName = String(item?.owner_name ?? '').trim();

    if (ownerName !== '') {
        return `name:${ownerName}`;
    }

    return 'none';
}

function matchesSearch(item, needle) {
    if (needle === '') {
        return true;
    }

    const searchableText = [
        item?.original_filename,
        item?.owner_name,
        item?.ownership_label,
        item?.owning_saved_notice_title,
        item?.document_category_name,
        item?.document_topic_name,
        item?.document_theme_label,
        item?.document_type_label,
        item?.extraction_status_label,
        item?.uploaded_by,
        item?.content_excerpt,
        item?.file_size_human,
        item?.chunk_count,
    ]
        .map((value) => normalizeSearchText(value))
        .join(' ');

    return searchableText.includes(needle);
}

function SearchIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M9 15a6 6 0 1 1 0-12 6 6 0 0 1 0 12Z" />
            <path d="m13.5 13.5 4 4" strokeLinecap="round" />
        </svg>
    );
}

function FilterIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M3 5h14" strokeLinecap="round" />
            <path d="M6 10h8" strokeLinecap="round" />
            <path d="M8.5 15h3" strokeLinecap="round" />
        </svg>
    );
}

function FileIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true" {...props}>
            <path d="M6 2.75h4.6L15.5 7.6V17.25A1.25 1.25 0 0 1 14.25 18.5h-8.5A1.25 1.25 0 0 1 4.5 17.25v-13A1.25 1.25 0 0 1 5.75 3h.25" strokeLinecap="round" strokeLinejoin="round" />
            <path d="M10.5 3v4.75h4.75" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function ChevronIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" className={className}>
            <path d="m7 4.5 6 5.5-6 5.5" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function DeleteIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true" {...props}>
            <path d="M4.5 5.5h11" strokeLinecap="round" />
            <path d="M8 5.5v-1A1.5 1.5 0 0 1 9.5 3h1A1.5 1.5 0 0 1 12 4.5v1" strokeLinecap="round" />
            <path d="M6.5 5.5l.5 9A1.5 1.5 0 0 0 8.5 16h3a1.5 1.5 0 0 0 1.5-1.5l.5-9" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function DeleteKnowledgeDocumentModal({ item, processing = false, onCancel, onConfirm, tk = {} }) {
    if (!item) {
        return null;
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center overflow-hidden bg-slate-950/45 px-4 py-4"
            onClick={(event) => {
                if (event.target === event.currentTarget) {
                    onCancel();
                }
            }}
        >
            <div className="flex w-full max-w-lg flex-col overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_20px_60px_rgba(15,23,42,0.18)]">
                <div className="shrink-0 flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
                    <div className="space-y-1">
                        <h2 className="text-xl font-semibold text-slate-950">{tk.delete_title}</h2>
                        <p className="text-sm leading-6 text-slate-500">
                            {tk.delete_body}
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={onCancel}
                        className="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:border-slate-300 hover:text-slate-900"
                    >
                        ×
                    </button>
                </div>

                <div className="space-y-3 px-6 py-6">
                    <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div className="text-xs font-medium uppercase tracking-[0.12em] text-slate-400">
                            {tk.delete_document_label}
                        </div>
                        <div className="mt-1 text-sm font-medium text-slate-950">
                            {item.original_filename}
                        </div>
                    </div>
                </div>

                <div className="shrink-0 border-t border-slate-200 bg-white px-6 py-5">
                    <div className="flex flex-col gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            onClick={onCancel}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                        >
                            {tk.cancel}
                        </button>
                        <button
                            type="button"
                            onClick={onConfirm}
                            disabled={processing}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {processing ? tk.deleting : tk.delete_confirm}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

function PaginationButton({ children, disabled = false, onClick, direction = 'next' }) {
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            className="inline-flex min-h-9 items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:text-slate-300"
        >
            {direction === 'prev' ? <ChevronIcon className="mr-1 h-4 w-4 rotate-180" /> : null}
            {children}
            {direction === 'next' ? <ChevronIcon className="ml-1 h-4 w-4" /> : null}
        </button>
    );
}

export default function KnowledgeBaseIndex({
    pageTitle = null,
    knowledgeItems = [],
    createUrl = '/app/ai/knowledge-base/create',
    aiUsageUrl = null,
}) {
    const { locale = 'nb-NO', translations = {} } = usePage().props;
    const tk = translations?.knowledge ?? {};
    const commonText = translations?.common ?? {};
    const ownershipLabelText = tk.ownership_label_text ?? 'Tilhørighet';
    const items = Array.isArray(knowledgeItems) ? knowledgeItems : [];
    const [searchQuery, setSearchQuery] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [reviewStateFilter, setReviewStateFilter] = useState('all');
    const [documentTypeFilter, setDocumentTypeFilter] = useState('all');
    const [documentCategoryFilter, setDocumentCategoryFilter] = useState('all');
    const [documentTopicFilter, setDocumentTopicFilter] = useState('all');
    const [ownershipFilter, setOwnershipFilter] = useState('all');
    const [ownerFilter, setOwnerFilter] = useState('all');
    const [showMoreFilters, setShowMoreFilters] = useState(false);
    const [deleteCandidate, setDeleteCandidate] = useState(null);
    const [isDeleting, setIsDeleting] = useState(false);
    const [currentPage, setCurrentPage] = useState(1);
    const pageSize = 6;

    const DOCUMENT_STATUS_OPTIONS = [
        { value: 'all', label: tk.filter_all },
        { value: 'active', label: tk.filter_active },
        { value: 'pending_review', label: tk.filter_pending_review },
        { value: 'draft', label: tk.filter_draft },
        { value: 'expired', label: tk.filter_expired },
        { value: 'archived', label: tk.filter_archived },
        { value: 'processing', label: tk.filter_processing },
        { value: 'failed', label: tk.filter_failed },
    ];
    const DOCUMENT_TYPE_FILTER_OPTIONS = [
        { value: 'all', label: tk.filter_all },
        ...KNOWLEDGE_DOCUMENT_TYPE_OPTIONS.filter((option) => option.value !== 'company'),
    ];
    const DOCUMENT_CATEGORY_FILTER_OPTIONS = (() => {
        const options = new Map();

        items.forEach((item) => {
            const categoryId = item?.document_category_id;
            const categoryName = String(item?.document_category_name ?? '').trim();

            if (!categoryId || options.has(categoryId)) {
                return;
            }

            options.set(categoryId, categoryName !== '' ? categoryName : String(categoryId));
        });

        return [
            { value: 'all', label: tk.filter_all },
            ...Array.from(options.entries())
                .map(([value, label]) => ({ value: String(value), label }))
                .sort((left, right) => left.label.localeCompare(right.label, 'nb-NO')),
        ];
    })();
    const DOCUMENT_TOPIC_FILTER_OPTIONS = (() => {
        const options = new Map();

        items.forEach((item) => {
            const topicId = item?.document_topic_id;
            const topicName = String(item?.document_topic_name ?? '').trim();

            if (!topicId || options.has(topicId)) {
                return;
            }

            options.set(topicId, topicName !== '' ? topicName : String(topicId));
        });

        return [
            { value: 'all', label: tk.filter_all },
            ...Array.from(options.entries())
                .map(([value, label]) => ({ value: String(value), label }))
                .sort((left, right) => left.label.localeCompare(right.label, 'nb-NO')),
        ];
    })();
    const DOCUMENT_OWNERSHIP_FILTER_OPTIONS = [
        { value: 'all', label: tk.filter_all },
        { value: 'company', label: 'Selskap' },
        { value: 'case', label: 'Sak/anbudssak' },
        { value: 'department', label: 'Avdeling/team' },
        { value: 'personal', label: 'Personlig' },
    ];
    const DOCUMENT_STATUS_LABEL = {
        failed: tk.filter_failed,
        processing: tk.filter_processing,
        draft: tk.filter_draft,
        pending_review: tk.filter_pending_review,
        active: tk.filter_active,
        expired: tk.filter_expired,
        archived: tk.filter_archived,
    };
    const REVIEW_STATE_CLASS = {
        not_set: 'bg-slate-100 text-slate-400 ring-slate-200',
        ok: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        due_soon: 'bg-amber-100 text-amber-800 ring-amber-200',
        overdue: 'bg-rose-100 text-rose-700 ring-rose-200',
    };
    const REVIEW_STATE_LABEL = {
        not_set: tk.review_state_not_set ?? 'Ingen frist',
        ok: tk.review_state_ok ?? 'Oppdatert',
        due_soon: tk.review_state_due_soon ?? 'Snart',
        overdue: tk.review_state_overdue ?? 'Forfalt',
    };
    const REVIEW_STATE_FILTER_OPTIONS = [
        { value: 'all', label: tk.filter_all },
        { value: 'overdue', label: tk.review_state_overdue ?? 'Forfalt' },
        { value: 'due_soon', label: tk.review_state_due_soon ?? 'Snart' },
        { value: 'ok', label: tk.review_state_ok ?? 'Oppdatert' },
        { value: 'not_set', label: tk.review_state_not_set ?? 'Ingen frist' },
    ];
    const DOCUMENT_OWNER_FILTER_OPTIONS = (() => {
        const options = new Map();

        items.forEach((item) => {
            const ownerFilterValue = getKnowledgeDocumentOwnerFilterValue(item);

            if (ownerFilterValue === 'none' || options.has(ownerFilterValue)) {
                return;
            }

            const ownerName = String(item?.owner_name ?? '').trim();
            options.set(ownerFilterValue, ownerName !== '' ? ownerName : commonText.not_set ?? 'Ikke satt');
        });

        return [
            { value: 'all', label: tk.filter_all },
            { value: 'none', label: commonText.not_set ?? 'Ikke satt' },
            ...Array.from(options.entries())
                .map(([value, label]) => ({ value, label }))
                .sort((left, right) => left.label.localeCompare(right.label, 'nb-NO')),
        ];
    })();

    const statusCounts = items.reduce((accumulator, item) => {
        const status = getKnowledgeDocumentStatus(item);
        accumulator.all += 1;
        accumulator[status] = (accumulator[status] ?? 0) + 1;
        return accumulator;
    }, {
        all: 0,
        review: 0,
        processing: 0,
        approved: 0,
        failed: 0,
    });

    const filteredItems = items.filter((item) => {
        const status = getKnowledgeDocumentStatus(item);
        const matchesStatus = statusFilter === 'all' || status === statusFilter;
        const matchesReviewState = reviewStateFilter === 'all' || (item?.review_state ?? 'not_set') === reviewStateFilter;
        const matchesType = documentTypeFilter === 'all' || item.document_type === documentTypeFilter;
        const matchesCategory = documentCategoryFilter === 'all' || String(item?.document_category_id ?? '') === documentCategoryFilter;
        const matchesTopic = documentTopicFilter === 'all' || String(item?.document_topic_id ?? '') === documentTopicFilter;
        const matchesOwnership = ownershipFilter === 'all' || String(item?.ownership_type ?? '').trim() === ownershipFilter;
        const matchesOwner = ownerFilter === 'all' || getKnowledgeDocumentOwnerFilterValue(item) === ownerFilter;
        const matchesText = matchesSearch(item, normalizeSearchText(searchQuery));

        return matchesStatus && matchesReviewState && matchesType && matchesCategory && matchesTopic && matchesOwnership && matchesOwner && matchesText;
    });

    const totalPages = Math.max(1, Math.ceil(filteredItems.length / pageSize));
    const safeCurrentPage = Math.min(currentPage, totalPages);
    const startIndex = filteredItems.length === 0 ? 0 : (safeCurrentPage - 1) * pageSize;
    const pagedItems = filteredItems.slice(startIndex, startIndex + pageSize);
    const pageStart = filteredItems.length === 0 ? 0 : startIndex + 1;
    const pageEnd = Math.min(startIndex + pageSize, filteredItems.length);
    const isAnyFilterActive = statusFilter !== 'all'
        || reviewStateFilter !== 'all'
        || documentTypeFilter !== 'all'
        || documentCategoryFilter !== 'all'
        || documentTopicFilter !== 'all'
        || ownershipFilter !== 'all'
        || ownerFilter !== 'all';
    const newDocumentUrl = createUrl.includes('?') ? `${createUrl}&mode=new` : `${createUrl}?mode=new`;
    const pageTitleText = pageTitle ?? tk.title;

    useEffect(() => {
        setCurrentPage(1);
    }, [searchQuery, statusFilter, reviewStateFilter, documentTypeFilter, documentCategoryFilter, documentTopicFilter, ownershipFilter, ownerFilter]);

    const clearMoreFilters = () => {
        setDocumentTypeFilter('all');
        setDocumentCategoryFilter('all');
        setDocumentTopicFilter('all');
        setOwnershipFilter('all');
        setOwnerFilter('all');
        setStatusFilter('all');
        setReviewStateFilter('all');
        setShowMoreFilters(false);
    };

    const openDeleteDialog = (item) => {
        setDeleteCandidate(item);
    };

    const closeDeleteDialog = () => {
        if (isDeleting) {
            return;
        }

        setDeleteCandidate(null);
    };

    const confirmDelete = () => {
        if (!deleteCandidate || isDeleting) {
            return;
        }

        setIsDeleting(true);

        router.delete(deleteCandidate.delete_url, {
            preserveScroll: true,
            onFinish: () => {
                setIsDeleting(false);
                setDeleteCandidate(null);
            },
        });
    };

    return (
        <CustomerAppLayout title={pageTitleText} showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-4">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div className="space-y-2">
                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                {tk.title}
                            </div>
                            <div className="flex items-center gap-3">
                                <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                                    {tk.title}
                                </h1>
                                <PageHelpButton
                                    buttonLabel={tk.page_help_button ?? 'Hjelp'}
                                    title={tk.page_help_title ?? 'Kunnskapsbase'}
                                    intro={tk.page_help_intro}
                                    sections={[
                                        {
                                            title: tk.page_help_section_what ?? 'Hva er kunnskapsdokumenter?',
                                            items: [
                                                {
                                                    title: tk.page_help_item_what_title ?? 'Kildemateriale for anbudsarbeid',
                                                    text: tk.page_help_item_what_body ?? 'Kunnskapsdokumenter er dokumenter Procynia bruker som kunnskapsgrunnlag i anbudsarbeid.',
                                                },
                                            ],
                                        },
                                        {
                                            title: tk.page_help_section_usage ?? 'Hvordan bruker AI-en dokumentene?',
                                            items: [
                                                {
                                                    title: tk.page_help_item_usage_title ?? 'Splitting, analyse og metadata',
                                                    text: tk.page_help_item_usage_body ?? 'Når et dokument lastes opp, deles det opp i tekstbiter, analyseres og berikes med metadata.',
                                                },
                                            ],
                                        },
                                        {
                                            title: tk.page_help_section_statuses ?? 'Hva betyr statusene?',
                                            items: [
                                                {
                                                    title: tk.page_help_item_statuses_title ?? 'Fire statuser',
                                                    text: tk.page_help_item_statuses_body ?? 'Trenger review: inaktiv. Godkjent: aktivt og klart. Feilet: tekstuttrekk mislyktes.',
                                                },
                                            ],
                                        },
                                        {
                                            title: tk.page_help_section_chunks ?? 'Hva betyr chunks?',
                                            items: [
                                                {
                                                    title: tk.page_help_item_chunks_title ?? 'Tekstbiter for presis søking',
                                                    text: tk.page_help_item_chunks_body ?? 'Chunks er tekstbiter dokumentet er delt opp i. Det gjør det mulig for AI å finne presise avsnitt.',
                                                },
                                            ],
                                        },
                                        {
                                            title: tk.page_help_section_upload ?? 'Hva bør jeg laste opp?',
                                            items: [
                                                {
                                                    title: tk.page_help_item_upload_title ?? 'Relevante og oppdaterte dokumenter',
                                                    text: tk.page_help_item_upload_body ?? 'Last opp dokumenter som gir AI-en et godt grunnlag. Unngå utdaterte dokumenter, dubletter og sensitive personopplysninger.',
                                                },
                                            ],
                                        },
                                        {
                                            title: tk.page_help_section_vocabulary ?? 'Sammenheng med Standardvokabular',
                                            items: [
                                                {
                                                    title: tk.page_help_item_vocabulary_title ?? 'Kildemateriale vs. begrepslag',
                                                    text: tk.page_help_item_vocabulary_body ?? 'Kunnskapsdokumenter er kildematerialet. Standardvokabular er det godkjente begrepslaget Procynia bygger på toppen.',
                                                },
                                            ],
                                        },
                                        {
                                            title: tk.page_help_section_recommendation ?? 'Praktisk anbefaling',
                                            items: [
                                                {
                                                    title: tk.page_help_item_recommendation_title ?? 'Start med de beste dokumentene',
                                                    text: tk.page_help_item_recommendation_body ?? 'Start med noen få representative dokumenter av høy kvalitet.',
                                                },
                                            ],
                                        },
                                    ]}
                                />
                            </div>
                            <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                                {tk.subtitle}
                            </p>
                        </div>

                        <div className="flex flex-wrap gap-3 lg:justify-end">
                            {aiUsageUrl ? (
                                <Link
                                    href={aiUsageUrl}
                                    className="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-violet-200 hover:bg-violet-50 hover:text-violet-700"
                                >
                                    {tk.ai_usage_nav_label ?? 'Bruk i AI'}
                                </Link>
                            ) : null}
                            <Link
                                href={newDocumentUrl}
                                className="inline-flex items-center justify-center rounded-2xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-violet-700"
                            >
                                {tk.new_document}
                            </Link>
                        </div>
                    </div>
                </section>

                <section className="rounded-[22px] border border-slate-200 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                        <div className="flex flex-1 flex-wrap gap-2">
                            {DOCUMENT_STATUS_OPTIONS.map((option) => {
                                const isActive = statusFilter === option.value;
                                const count = statusCounts[option.value] ?? items.length;

                                return (
                                    <button
                                        key={option.value}
                                        type="button"
                                        onClick={() => setStatusFilter(option.value)}
                                        className={classNames(
                                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm font-medium transition',
                                            isActive
                                                ? 'border-violet-200 bg-violet-50/80 text-violet-700'
                                                : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-950',
                                        )}
                                    >
                                        <span>{option.label}</span>
                                        <span
                                            className={classNames(
                                                'inline-flex min-w-5 items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset',
                                                isActive
                                                    ? 'bg-white text-violet-700 ring-violet-200'
                                                    : 'bg-slate-50 text-slate-600 ring-slate-200',
                                            )}
                                        >
                                            {count}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>

                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <label className="relative flex-1 overflow-hidden rounded-2xl border border-slate-200 bg-white sm:min-w-[320px]">
                                <SearchIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
                                <input
                                    type="search"
                                    value={searchQuery}
                                    onChange={(event) => setSearchQuery(event.target.value)}
                                    placeholder={tk.search_placeholder}
                                    className="h-[54px] w-full border-0 bg-transparent pl-12 pr-4 text-[15px] text-slate-900 outline-none placeholder:text-slate-400 focus:ring-0"
                                />
                            </label>

                            <button
                                type="button"
                                onClick={() => setShowMoreFilters((current) => !current)}
                                className={classNames(
                                    'inline-flex h-[54px] items-center justify-center gap-2 rounded-2xl border px-5 text-sm font-semibold transition',
                                    showMoreFilters || isAnyFilterActive
                                        ? 'border-violet-200 bg-violet-50 text-violet-700'
                                        : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950',
                                )}
                            >
                                <FilterIcon className="h-4 w-4" />
                                {showMoreFilters ? tk.hide_filters : tk.filter_button}
                            </button>
                        </div>
                    </div>

                    {showMoreFilters ? (
                        <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                            <div className="grid gap-4 xl:grid-cols-4">
                                <label className="space-y-2">
                                    <span className="text-sm font-medium text-slate-700">{tk.filter_document_category ?? tk.filter_document_type}</span>
                                    <select
                                        value={documentCategoryFilter}
                                        onChange={(event) => setDocumentCategoryFilter(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    >
                                        {DOCUMENT_CATEGORY_FILTER_OPTIONS.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <label className="space-y-2">
                                    <span className="text-sm font-medium text-slate-700">{tk.filter_document_topic ?? 'Tema'}</span>
                                    <select
                                        value={documentTopicFilter}
                                        onChange={(event) => setDocumentTopicFilter(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    >
                                        {DOCUMENT_TOPIC_FILTER_OPTIONS.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <label className="space-y-2">
                                    <span className="text-sm font-medium text-slate-700">{tk.status}</span>
                                    <select
                                        value={statusFilter}
                                        onChange={(event) => setStatusFilter(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    >
                                        {DOCUMENT_STATUS_OPTIONS.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <label className="space-y-2">
                                    <span className="text-sm font-medium text-slate-700">{ownershipLabelText}</span>
                                    <select
                                        value={ownershipFilter}
                                        onChange={(event) => setOwnershipFilter(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    >
                                        {DOCUMENT_OWNERSHIP_FILTER_OPTIONS.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <label className="space-y-2">
                                    <span className="text-sm font-medium text-slate-700">{tk.col_owner}</span>
                                    <select
                                        value={ownerFilter}
                                        onChange={(event) => setOwnerFilter(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    >
                                        {DOCUMENT_OWNER_FILTER_OPTIONS.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <label className="space-y-2">
                                    <span className="text-sm font-medium text-slate-700">{tk.review_date_label ?? 'Revisjon'}</span>
                                    <select
                                        value={reviewStateFilter}
                                        onChange={(event) => setReviewStateFilter(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    >
                                        {REVIEW_STATE_FILTER_OPTIONS.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>

                            <div className="mt-4 flex items-center justify-end">
                                <button
                                    type="button"
                                    onClick={clearMoreFilters}
                                    className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                >
                                    {tk.clear_filters}
                                </button>
                            </div>
                        </div>
                    ) : null}
                </section>

                <section className="overflow-hidden rounded-[22px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    {filteredItems.length === 0 ? (
                        <div className="px-6 py-12 text-center">
                            <div className="text-lg font-semibold text-slate-900">
                                {tk.no_results_title}
                            </div>
                            <p className="mt-2 text-sm text-slate-500">
                                {tk.no_results_subtitle}
                            </p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200">
                                <thead className="bg-slate-50/80">
                                    <tr>
                                        <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                            {tk.col_document}
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                            {tk.col_type}
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                            {tk.status}
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                            {tk.col_ai_usage ?? 'AI-bruk'}
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                            {tk.chunks}
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                            {tk.col_updated}
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                            {ownershipLabelText}
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                            {tk.col_owner}
                                        </th>
                                        <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                            {tk.col_action}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200 bg-white">
                                    {pagedItems.map((item) => {
                                        const status = getKnowledgeDocumentStatus(item);
                                        const statusClass = DOCUMENT_STATUS_CLASS[status] ?? DOCUMENT_STATUS_CLASS.review;
                                        const statusLabel = DOCUMENT_STATUS_LABEL[status] ?? DOCUMENT_STATUS_LABEL.review;
                                        const ownershipLabel = String(item?.ownership_label ?? '').trim();
                                        const uploadedByLabelText = tk.uploaded_by_label ?? 'Opplastet av';
                                        const themeLabelText = tk.theme_label ?? 'Tema';
                                        const documentCategoryDisplayLabel = String(item?.document_category_name ?? item?.document_type_label ?? '').trim();
                                        const documentTypeLabel = String(item?.document_type_label ?? '').trim();
                                        const documentTypeValue = String(item?.document_type ?? '').trim();
                                        const documentTopicDisplayLabel = String(item?.document_topic_name ?? item?.document_theme_label ?? '').trim();
                                        const ownerName = String(item?.owner_name ?? '').trim();
                                        const ownerDisplayName = ownerName !== '' ? ownerName : commonText.not_set;
                                        const uploadedByName = String(item?.uploaded_by ?? '').trim();
                                        const documentThemeLabel = String(item?.document_theme_label ?? '').trim();
                                        const ownerInitials = ownerName !== '' ? getOwnerInitials(ownerName, tk.unknown_owner_initials) : '';
                                        const versionLabel = item.version_label ?? tk.version_1;
                                        const subtitle = `${versionLabel} · ${item.file_size_human ?? commonText.not_available}`;

                                        return (
                                            <tr key={item.id} className="align-top transition hover:bg-slate-50/40">
                                                <td className="px-4 py-3.5">
                                                    <div className="flex gap-3">
                                                        <div className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500 ring-1 ring-inset ring-slate-200">
                                                            <FileIcon className="h-3.5 w-3.5" />
                                                        </div>
                                                        <div className="min-w-0 space-y-1">
                                                            <div className="font-medium text-slate-950">
                                                                {item.original_filename}
                                                            </div>
                                                            <div className="text-[11px] text-slate-500">
                                                                {subtitle}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3.5">
                                                    <div className="space-y-1.5">
                                                        <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 text-[10px] font-medium uppercase tracking-[0.08em] text-slate-500">
                                                            {documentCategoryDisplayLabel !== '' ? documentCategoryDisplayLabel : documentTypeLabel !== '' ? documentTypeLabel : documentTypeValue !== '' ? documentTypeValue : commonText.not_available}
                                                        </span>
                                                        {documentTopicDisplayLabel !== '' ? (
                                                            <div className="text-[11px] leading-5 text-slate-500">
                                                                <span className="font-medium text-slate-600">{themeLabelText}:</span> {documentTopicDisplayLabel}
                                                            </div>
                                                        ) : null}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3.5">
                                                    <div className="space-y-1.5">
                                                        <span className={`inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-medium ring-1 ring-inset ${statusClass}`}>
                                                            {statusLabel}
                                                        </span>
                                                        {item?.review_state && item.review_state !== 'not_set' ? (
                                                            <div>
                                                                <span className={`inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-medium ring-1 ring-inset ${REVIEW_STATE_CLASS[item.review_state] ?? REVIEW_STATE_CLASS.not_set}`}>
                                                                    {REVIEW_STATE_LABEL[item.review_state] ?? item.review_state}
                                                                </span>
                                                            </div>
                                                        ) : null}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3.5">
                                                    {item.ai_usage_enabled !== false ? (
                                                        <span className="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-medium ring-1 ring-inset bg-emerald-100 text-emerald-700 ring-emerald-200">
                                                            {tk.ai_usage_yes ?? 'Ja'}
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-medium ring-1 ring-inset bg-amber-100 text-amber-800 ring-amber-200">
                                                            {tk.ai_usage_no ?? 'Nei'}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3.5 text-sm text-slate-500">
                                                    {formatChunkRatio(item, commonText.not_available)}
                                                </td>
                                                <td className="px-4 py-3.5">
                                                    <div className="space-y-1">
                                                        <div className="text-sm font-medium text-slate-900">
                                                            {formatDate(item.updated_at ?? item.uploaded_at, locale, commonText.not_available)}
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3.5">
                                                    <div className="space-y-1.5">
                                                        <span className="inline-flex rounded-full border border-violet-200 bg-violet-50 px-2.5 py-0.5 text-[11px] font-medium text-violet-700">
                                                            {ownershipLabel !== '' ? ownershipLabel : commonText.not_set}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3.5">
                                                    <div className="space-y-1.5">
                                                        {ownerName !== '' ? (
                                                            <div className="flex items-center gap-3">
                                                                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-violet-100 text-[11px] font-semibold text-violet-700 ring-1 ring-inset ring-violet-200">
                                                                    {ownerInitials}
                                                                </div>
                                                                <div className="min-w-0">
                                                                    <div className="truncate text-sm font-medium text-slate-900">
                                                                        {ownerDisplayName}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        ) : (
                                                            <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 text-[11px] font-medium text-slate-500">
                                                                {ownerDisplayName}
                                                            </span>
                                                        )}
                                                        {uploadedByName !== '' ? (
                                                            <div className="text-xs text-slate-500">
                                                                {uploadedByLabelText}: {uploadedByName}
                                                            </div>
                                                        ) : null}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3.5 text-right">
                                                    <div className="inline-flex items-center gap-2">
                                                        <Link
                                                            href={item.show_url ?? item.edit_url}
                                                            className="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 transition hover:border-violet-200 hover:bg-violet-50 hover:text-violet-700"
                                                        >
                                                            {commonText.open}
                                                        </Link>
                                                        <button
                                                            type="button"
                                                            onClick={() => openDeleteDialog(item)}
                                                            className="inline-flex items-center justify-center rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm font-medium text-rose-700 transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-800"
                                                        >
                                                            <DeleteIcon className="h-4 w-4" />
                                                            <span className="ml-1.5">{tk.delete}</span>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <div className="flex flex-col gap-3 border-t border-slate-200 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between">
                        <div className="text-sm text-slate-500">
                            {filteredItems.length === 0
                                ? `${tk.showing} 0 ${tk.documents}`
                                : `${tk.showing} ${pageStart}–${pageEnd} ${tk.of} ${filteredItems.length === 1 ? `1 ${tk.document}` : `${filteredItems.length} ${tk.documents}`}`}
                        </div>

                        <div className="flex items-center gap-2">
                            <PaginationButton
                                direction="prev"
                                disabled={safeCurrentPage <= 1}
                                onClick={() => setCurrentPage((current) => Math.max(1, current - 1))}
                            >
                                {commonText.previous}
                            </PaginationButton>
                            <span className="inline-flex min-h-9 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm font-medium text-slate-700">
                                {safeCurrentPage} {tk.of} {totalPages}
                            </span>
                            <PaginationButton
                                direction="next"
                                disabled={safeCurrentPage >= totalPages}
                                onClick={() => setCurrentPage((current) => Math.min(totalPages, current + 1))}
                            >
                                {commonText.next}
                            </PaginationButton>
                        </div>
                    </div>
                </section>
            </div>

            <DeleteKnowledgeDocumentModal
                item={deleteCandidate}
                processing={isDeleting}
                onCancel={closeDeleteDialog}
                onConfirm={confirmDelete}
                tk={tk}
            />
        </CustomerAppLayout>
    );
}
