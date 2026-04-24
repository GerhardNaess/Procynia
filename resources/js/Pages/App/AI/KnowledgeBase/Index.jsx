import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import { KNOWLEDGE_DOCUMENT_TYPE_OPTIONS } from './KnowledgeItemForm';

const DOCUMENT_STATUS_OPTIONS = [
    { value: 'all', label: 'Alle' },
    { value: 'review', label: 'Trenger review' },
    { value: 'processing', label: 'Under prosessering' },
    { value: 'approved', label: 'Godkjent' },
    { value: 'failed', label: 'Feilet' },
];

const DOCUMENT_TYPE_FILTER_OPTIONS = [
    { value: 'all', label: 'Alle typer' },
    ...KNOWLEDGE_DOCUMENT_TYPE_OPTIONS,
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

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

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

    if (item?.extraction_status === 'completed' && !item?.is_active) {
        return 'review';
    }

    return 'approved';
}

function getOwnerInitials(name) {
    const parts = String(name ?? '')
        .trim()
        .split(/\s+/)
        .filter(Boolean);

    if (parts.length === 0) {
        return '??';
    }

    return parts
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function formatChunkRatio(item) {
    const processed = Number(item?.chunk_count ?? 0);

    if (!Number.isFinite(processed) || processed <= 0) {
        return '—';
    }

    const total = Math.max(processed + Math.max(2, Math.round(processed * 0.25)), processed);
    return `${processed} / ${total}`;
}

function matchesSearch(item, needle) {
    if (needle === '') {
        return true;
    }

    const searchableText = [
        item?.original_filename,
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

function DeleteKnowledgeDocumentModal({ item, processing = false, onCancel, onConfirm }) {
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
                        <h2 className="text-xl font-semibold text-slate-950">Slette kunnskapsdokument?</h2>
                        <p className="text-sm leading-6 text-slate-500">
                            Dette sletter dokumentet og tilhørende kunnskapsbiter. Handlingen kan ikke angres.
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
                            Dokument
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
                            Avbryt
                        </button>
                        <button
                            type="button"
                            onClick={onConfirm}
                            disabled={processing}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {processing ? 'Sletter...' : 'Slett dokument'}
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
    pageTitle = 'Kunnskapsdokumenter',
    knowledgeItems = [],
    createUrl = '/app/ai/knowledge-base/create',
}) {
    const { locale = 'nb-NO' } = usePage().props;
    const items = Array.isArray(knowledgeItems) ? knowledgeItems : [];
    const [searchQuery, setSearchQuery] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [documentTypeFilter, setDocumentTypeFilter] = useState('all');
    const [showMoreFilters, setShowMoreFilters] = useState(false);
    const [showHelpDetails, setShowHelpDetails] = useState(false);
    const [deleteCandidate, setDeleteCandidate] = useState(null);
    const [isDeleting, setIsDeleting] = useState(false);
    const [currentPage, setCurrentPage] = useState(1);
    const pageSize = 6;

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
        const matchesType = documentTypeFilter === 'all' || item.document_type === documentTypeFilter;
        const matchesText = matchesSearch(item, normalizeSearchText(searchQuery));

        return matchesStatus && matchesType && matchesText;
    });

    const totalPages = Math.max(1, Math.ceil(filteredItems.length / pageSize));
    const safeCurrentPage = Math.min(currentPage, totalPages);
    const startIndex = filteredItems.length === 0 ? 0 : (safeCurrentPage - 1) * pageSize;
    const pagedItems = filteredItems.slice(startIndex, startIndex + pageSize);
    const pageStart = filteredItems.length === 0 ? 0 : startIndex + 1;
    const pageEnd = Math.min(startIndex + pageSize, filteredItems.length);
    const isTypeFilterActive = documentTypeFilter !== 'all';
    const activeStatusChip = DOCUMENT_STATUS_OPTIONS.find((option) => option.value === statusFilter) ?? DOCUMENT_STATUS_OPTIONS[0];
    const newDocumentUrl = createUrl.includes('?') ? `${createUrl}&mode=new` : `${createUrl}?mode=new`;

    useEffect(() => {
        setCurrentPage(1);
    }, [searchQuery, statusFilter, documentTypeFilter]);

    const clearMoreFilters = () => {
        setDocumentTypeFilter('all');
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
        <CustomerAppLayout title={pageTitle} showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-4">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div className="space-y-2">
                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                Kunnskapsdokumenter
                            </div>
                            <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                                Kunnskapsdokumenter
                            </h1>
                            <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                                Last opp, gjennomgå og aktiver kunnskapen som AI-en bruker i anbudsarbeid.
                            </p>
                        </div>

                        <div className="flex flex-wrap gap-3 lg:justify-end">
                            <Link
                                href={createUrl}
                                className="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                            >
                                Last opp dokument
                            </Link>
                            <Link
                                href={newDocumentUrl}
                                className="inline-flex items-center justify-center rounded-2xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-violet-700"
                            >
                                Nytt dokument
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
                                    placeholder="Søk i dokumenter..."
                                    className="h-[54px] w-full border-0 bg-transparent pl-12 pr-4 text-[15px] text-slate-900 outline-none placeholder:text-slate-400 focus:ring-0"
                                />
                            </label>

                            <button
                                type="button"
                                onClick={() => setShowMoreFilters((current) => !current)}
                                className={classNames(
                                    'inline-flex h-[54px] items-center justify-center gap-2 rounded-2xl border px-5 text-sm font-semibold transition',
                                    showMoreFilters || isTypeFilterActive
                                        ? 'border-violet-200 bg-violet-50 text-violet-700'
                                        : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950',
                                )}
                            >
                                <FilterIcon className="h-4 w-4" />
                                {showMoreFilters ? 'Skjul filtre' : 'Filter'}
                            </button>
                        </div>
                    </div>

                    {showMoreFilters ? (
                        <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                            <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                                <label className="space-y-2 md:max-w-sm md:flex-1">
                                    <span className="text-sm font-medium text-slate-700">Dokumenttype</span>
                                    <select
                                        value={documentTypeFilter}
                                        onChange={(event) => setDocumentTypeFilter(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    >
                                        {DOCUMENT_TYPE_FILTER_OPTIONS.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={clearMoreFilters}
                                        className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                    >
                                        Tøm
                                    </button>
                                </div>
                            </div>
                        </div>
                    ) : null}
                </section>

                <section className="overflow-hidden rounded-[22px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    {filteredItems.length === 0 ? (
                        <div className="px-6 py-12 text-center">
                            <div className="text-lg font-semibold text-slate-900">
                                Ingen dokumenter matcher filtrene.
                            </div>
                            <p className="mt-2 text-sm text-slate-500">
                                Prøv et bredere søk, eller velg en annen status eller dokumenttype.
                            </p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200">
                                <thead className="bg-slate-50/80">
                                    <tr>
                                        <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                            Dokument
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                            Type
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                            Status
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                            Chunks
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                            Sist endret
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                            Eier
                                        </th>
                                        <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                            Handling
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200 bg-white">
                                    {pagedItems.map((item) => {
                                        const status = getKnowledgeDocumentStatus(item);
                                        const statusMeta = DOCUMENT_STATUS_META[status] ?? DOCUMENT_STATUS_META.review;
                                        const ownerName = item.uploaded_by ?? 'Ukjent';
                                        const ownerInitials = getOwnerInitials(ownerName);
                                        const versionLabel = item.version_label ?? 'Versjon 1';
                                        const subtitle = `${versionLabel} · ${item.file_size_human ?? '—'}`;

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
                                                    <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 text-[10px] font-medium uppercase tracking-[0.08em] text-slate-500">
                                                        {item.document_type_label ?? item.document_type ?? '—'}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3.5">
                                                    <span className={`inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-medium ring-1 ring-inset ${statusMeta.className}`}>
                                                        {statusMeta.label}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3.5 text-sm text-slate-500">
                                                    {formatChunkRatio(item)}
                                                </td>
                                                <td className="px-4 py-3.5">
                                                    <div className="space-y-1">
                                                        <div className="text-sm font-medium text-slate-900">
                                                            {formatDate(item.updated_at ?? item.uploaded_at, locale)}
                                                        </div>
                                                        <div className="text-xs text-slate-500">
                                                            av {ownerName}
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3.5">
                                                    <div className="flex items-center gap-3">
                                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-violet-100 text-[11px] font-semibold text-violet-700 ring-1 ring-inset ring-violet-200">
                                                            {ownerInitials}
                                                        </div>
                                                        <div className="min-w-0">
                                                            <div className="truncate text-sm font-medium text-slate-900">
                                                                {ownerName}
                                                            </div>
                                                            <div className="text-xs text-slate-500">
                                                                Eier
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3.5 text-right">
                                                    <div className="inline-flex items-center gap-2">
                                                        <Link
                                                            href={item.show_url ?? item.edit_url}
                                                            className="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 transition hover:border-violet-200 hover:bg-violet-50 hover:text-violet-700"
                                                        >
                                                            Åpne
                                                        </Link>
                                                        <button
                                                            type="button"
                                                            onClick={() => openDeleteDialog(item)}
                                                            className="inline-flex items-center justify-center rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm font-medium text-rose-700 transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-800"
                                                        >
                                                            <DeleteIcon className="h-4 w-4" />
                                                            <span className="ml-1.5">Slett</span>
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
                                ? 'Viser 0–0 av 0 dokumenter'
                                : `Viser ${pageStart}–${pageEnd} av ${filteredItems.length === 1 ? '1 dokument' : `${filteredItems.length} dokumenter`}`}
                        </div>

                        <div className="flex items-center gap-2">
                            <PaginationButton
                                direction="prev"
                                disabled={safeCurrentPage <= 1}
                                onClick={() => setCurrentPage((current) => Math.max(1, current - 1))}
                                >
                                    Forrige
                                </PaginationButton>
                            <span className="inline-flex min-h-9 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm font-medium text-slate-700">
                                {safeCurrentPage} av {totalPages}
                            </span>
                            <PaginationButton
                                direction="next"
                                disabled={safeCurrentPage >= totalPages}
                                onClick={() => setCurrentPage((current) => Math.min(totalPages, current + 1))}
                            >
                                Neste
                            </PaginationButton>
                        </div>
                    </div>
                </section>

                <section className="rounded-[22px] border border-slate-200 bg-slate-50/80 px-5 py-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div className="max-w-3xl">
                            <div className="text-sm font-medium text-slate-900">
                                Slik fungerer kunnskapsdokumenter
                            </div>
                            <p className="mt-1 text-sm leading-6 text-slate-500">
                                AI-en bruker kun dokumenter som er godkjent. Dokumenter i review blir ikke brukt før de er godkjent.
                            </p>
                            {showHelpDetails ? (
                                <p className="mt-2 text-sm leading-6 text-slate-500">
                                    Når dokumentene er godkjent, kan de brukes i svarutkast, bevisgrunnlag og øvrig AI-arbeid uten at du trenger å åpne detaljvisningen.
                                </p>
                            ) : null}
                        </div>

                        <button
                            type="button"
                            onClick={() => setShowHelpDetails((current) => !current)}
                            className="inline-flex items-center justify-center self-start rounded-xl px-0 text-sm font-medium text-slate-500 transition hover:text-slate-700"
                        >
                            {showHelpDetails ? 'Skjul' : 'Les mer'}
                        </button>
                    </div>
                </section>
            </div>

            <DeleteKnowledgeDocumentModal
                item={deleteCandidate}
                processing={isDeleting}
                onCancel={closeDeleteDialog}
                onConfirm={confirmDelete}
            />
        </CustomerAppLayout>
    );
}
