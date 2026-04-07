import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

const AI_STATUS_META = {
    not_started: {
        label: 'Ikke startet',
        className: 'bg-slate-100 text-slate-700 ring-slate-200',
    },
    ready: {
        label: 'Klar',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
    in_review: {
        label: 'Under vurdering',
        className: 'bg-violet-100 text-violet-700 ring-violet-200',
    },
};

const DOCUMENT_STATUS_META = {
    uploaded: {
        label: 'Lastet opp',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
};

const REQUIREMENT_TYPE_META = {
    mandatory: {
        label: 'Obligatorisk',
        className: 'bg-rose-100 text-rose-700 ring-rose-200',
    },
    documentation: {
        label: 'Dokumentasjon',
        className: 'bg-sky-100 text-sky-700 ring-sky-200',
    },
    administrative: {
        label: 'Administrativ',
        className: 'bg-amber-100 text-amber-700 ring-amber-200',
    },
    unspecified: {
        label: 'Uspesifisert',
        className: 'bg-slate-100 text-slate-700 ring-slate-200',
    },
};

const REQUIREMENT_REVIEW_STATUS_META = {
    pending: {
        label: 'Til vurdering',
        className: 'bg-violet-100 text-violet-700 ring-violet-200',
    },
    confirmed: {
        label: 'Bekreftet',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
    rejected: {
        label: 'Avvist',
        className: 'bg-rose-100 text-rose-700 ring-rose-200',
    },
};

const REQUIREMENT_REVIEW_ACTIONS = {
    pending: [
        {
            label: 'Bekreft',
            value: 'confirmed',
            className: 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-100',
        },
        {
            label: 'Avvis',
            value: 'rejected',
            className: 'border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100',
        },
    ],
    confirmed: [
        {
            label: 'Nullstill til vurdering',
            value: 'pending',
            className: 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950',
        },
    ],
    rejected: [
        {
            label: 'Nullstill til vurdering',
            value: 'pending',
            className: 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950',
        },
    ],
};

const WORK_STATUS_META = {
    not_started: {
        label: 'Ikke startet',
        className: 'bg-slate-100 text-slate-700 ring-slate-200',
    },
    in_progress: {
        label: 'Under arbeid',
        className: 'bg-amber-100 text-amber-700 ring-amber-200',
    },
    done: {
        label: 'Ferdig',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
};

const WORK_STATUS_OPTIONS = [
    { value: 'not_started', label: 'Ikke startet' },
    { value: 'in_progress', label: 'Under arbeid' },
    { value: 'done', label: 'Ferdig' },
];

function formatKnowledgeSnippet(value, maxLength = 200) {
    const normalizedValue = String(value ?? '').replace(/\s+/g, ' ').trim();

    if (normalizedValue === '') {
        return '—';
    }

    if (normalizedValue.length <= maxLength) {
        return normalizedValue;
    }

    return `${normalizedValue.slice(0, Math.max(0, maxLength - 3)).trimEnd()}...`;
}

/**
 * Purpose: Render the AI case control surface for a single saved notice.
 * Inputs: pageTitle, case, and ai_status props from the AI controller.
 * Returns: A customer-app page component for the AI case view.
 * Side effects: None.
 */
export default function AiShow({
    pageTitle = 'AI-arbeid',
    case: caseData = null,
    ai_status: aiStatus = 'not_started',
    search_query: searchQuery = '',
    search_results: searchResults = [],
    search_url: searchUrl = '',
    requirements_count: requirementsCount = 0,
    requirements_overview: requirementsOverviewProp = {},
    requirements = [],
    documents = [],
    documents_upload_url: documentsUploadUrl = '',
}) {
    const {
        locale = 'nb-NO',
        assigned_user_options: assignedUserOptionsProp = [],
    } = usePage().props;
    const fileInputRef = useRef(null);
    const [searchInput, setSearchInput] = useState(searchQuery);
    const [reviewingRequirementId, setReviewingRequirementId] = useState(null);
    const [workingRequirementId, setWorkingRequirementId] = useState(null);
    const [deletingDocumentId, setDeletingDocumentId] = useState(null);
    const documentUploadForm = useForm({
        documents: [],
    });
    const aiStatusMeta = AI_STATUS_META[aiStatus] ?? AI_STATUS_META.not_started;
    const assignedUserOptions = Array.isArray(assignedUserOptionsProp) ? assignedUserOptionsProp : [];
    const documentRows = Array.isArray(documents) ? documents : [];
    const searchRows = Array.isArray(searchResults) ? searchResults : [];
    const requirementRows = Array.isArray(requirements) ? requirements : [];
    const requirementsOverview = requirementsOverviewProp && typeof requirementsOverviewProp === 'object'
        ? requirementsOverviewProp
        : {};
    const documentError = Object.values(documentUploadForm.errors).find(Boolean) ?? null;
    const selectedDocumentsLabel = documentUploadForm.data.documents.length > 0
        ? documentUploadForm.data.documents.map((document) => document.name).join(', ')
        : 'Ingen filer valgt ennå.';
    const hasSearchQuery = searchQuery.trim() !== '';
    const requirementCountLabel = Number(requirementsCount ?? requirementRows.length);
    const requirementUpdatesLocked = reviewingRequirementId !== null || workingRequirementId !== null;
    const confirmedRequirementsTotal = Number(requirementsOverview.confirmed_total ?? 0);
    const pendingRequirementsTotal = Number(requirementsOverview.pending_total ?? 0);
    const rejectedRequirementsTotal = Number(requirementsOverview.rejected_total ?? 0);
    const notStartedRequirementsTotal = Number(requirementsOverview.not_started_total ?? 0);
    const inProgressRequirementsTotal = Number(requirementsOverview.in_progress_total ?? 0);
    const doneRequirementsTotal = Number(requirementsOverview.done_total ?? 0);
    const unassignedConfirmedRequirementsTotal = Number(requirementsOverview.unassigned_confirmed_total ?? 0);
    const assignedConfirmedRequirementsTotal = Math.max(confirmedRequirementsTotal - unassignedConfirmedRequirementsTotal, 0);
    const updatedAtLabel = caseData?.updated_at
        ? new Intl.DateTimeFormat(locale, {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        }).format(new Date(caseData.updated_at))
        : '—';

    useEffect(() => {
        setSearchInput(searchQuery);
    }, [searchQuery]);

    const handleDocumentChange = (event) => {
        documentUploadForm.setData('documents', Array.from(event.target.files ?? []));
    };

    const submitDocuments = (event) => {
        event.preventDefault();

        if (!documentsUploadUrl || documentUploadForm.processing) {
            return;
        }

        documentUploadForm.post(documentsUploadUrl, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                documentUploadForm.reset('documents');

                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            },
        });
    };

    const submitSearch = (event) => {
        event.preventDefault();

        const trimmedSearch = searchInput.trim();
        const targetUrl = searchUrl || (caseData?.id ? `/app/ai/${caseData.id}` : '/app/ai');

        router.get(
            targetUrl,
            trimmedSearch === '' ? {} : { search: trimmedSearch },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    };

    const updateRequirementReviewStatus = (requirement, reviewStatus) => {
        if (!requirement.review_status_update_url || requirementUpdatesLocked) {
            return;
        }

        setReviewingRequirementId(requirement.id);

        router.patch(requirement.review_status_update_url, {
            review_status: reviewStatus,
        }, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                setReviewingRequirementId(null);
            },
        });
    };

    /**
     * Purpose: Persist the canonical work status and assignment for a confirmed requirement candidate.
     * Inputs: The requirement row, next work status, and next assignee id from the row controls.
     * Returns: None.
     * Side effects: Sends a PATCH request that updates the requirement work state on the server.
     */
    const updateRequirementWork = (requirement, workStatus, assignedUserId) => {
        if (!requirement.work_update_url || requirement.review_status !== 'confirmed' || requirementUpdatesLocked) {
            return;
        }

        setWorkingRequirementId(requirement.id);

        router.patch(requirement.work_update_url, {
            work_status: workStatus,
            assigned_user_id: assignedUserId === '' ? null : assignedUserId,
        }, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                setWorkingRequirementId(null);
            },
        });
    };

    const deleteDocument = (document) => {
        if (!document.delete_url || deletingDocumentId !== null) {
            return;
        }

        const confirmed = window.confirm(
            'Slette dette opplastede dokumentet? Dette fjerner filen, ekstrahert tekst, tekstbiter og kravkandidater.',
        );

        if (!confirmed) {
            return;
        }

        setDeletingDocumentId(document.id);

        router.delete(document.delete_url, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                setDeletingDocumentId(null);
            },
        });
    };

    return (
        <CustomerAppLayout title={pageTitle} showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-4">
                    <div className="space-y-2">
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                                {caseData?.title ?? 'AI-sak'}
                            </h1>
                            <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${aiStatusMeta.className}`}>
                                {aiStatusMeta.label}
                            </span>
                        </div>
                        <div className="flex flex-wrap gap-2 text-sm text-slate-500">
                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1.5">
                                Referanse: {caseData?.reference ?? '—'}
                            </span>
                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1.5">
                                Ansvarlig: {caseData?.owner ?? '—'}
                            </span>
                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1.5">
                                Fase: {caseData?.stage ?? '—'}
                            </span>
                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1.5">
                                Oppdatert: {updatedAtLabel}
                            </span>
                        </div>
                    </div>
                </section>

                <section className="rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="space-y-5">
                        <div className="space-y-2">
                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                Anbudsdokumenter
                            </div>
                            <h2 className="text-xl font-semibold tracking-tight text-slate-950">
                                Anbudsdokumenter
                            </h2>
                            <p className="max-w-3xl text-sm leading-6 text-slate-500">
                                Last opp anbudsdokumenter for analyse.
                            </p>
                        </div>

                        <form onSubmit={submitDocuments} className="space-y-4">
                            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                                <div className="space-y-2">
                                    <label htmlFor="ai-documents" className="text-sm font-medium text-slate-700">
                                        Velg filer
                                    </label>
                                    <div className="flex min-h-[56px] items-center gap-4 rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                                        <label
                                            htmlFor="ai-documents"
                                            className="inline-flex shrink-0 cursor-pointer items-center rounded-full bg-slate-950 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
                                        >
                                            Velg filer
                                        </label>
                                        <span className="min-w-0 flex-1 text-sm text-slate-500">
                                            {selectedDocumentsLabel}
                                        </span>
                                        <input
                                            id="ai-documents"
                                            ref={fileInputRef}
                                            type="file"
                                            multiple
                                            accept=".pdf,.doc,.docx,.xls,.xlsx"
                                            onChange={handleDocumentChange}
                                            className="sr-only"
                                        />
                                    </div>
                                    <p className="text-xs leading-5 text-slate-500">
                                        Tillatte filtyper: PDF, DOC, DOCX, XLS, XLSX. Maks 20 MB per fil.
                                    </p>
                                    {documentError ? (
                                        <p className="text-sm text-rose-600">{documentError}</p>
                                    ) : null}
                                </div>

                                <button
                                    type="submit"
                                    disabled={
                                        documentUploadForm.processing
                                        || !documentsUploadUrl
                                        || documentUploadForm.data.documents.length === 0
                                    }
                                    className="inline-flex items-center justify-center rounded-2xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60 lg:mt-7"
                                >
                                    Last opp anbudsdokumenter
                                </button>
                            </div>
                        </form>

                        {documentRows.length === 0 ? (
                            <div className="rounded-[22px] border border-dashed border-slate-300 bg-slate-50 px-6 py-10">
                                <div className="text-lg font-semibold text-slate-900">
                                    Ingen anbudsdokumenter er lastet opp ennå.
                                </div>
                                <p className="mt-2 text-sm text-slate-500">
                                    Opplastede dokumenter vises her når de er lagt til i denne saken.
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-hidden rounded-[22px] border border-slate-200">
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
                                                <th className="px-5 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                    Handlinger
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-200 bg-white">
                                            {documentRows.map((document) => {
                                                const documentStatusMeta = DOCUMENT_STATUS_META[document.processing_status] ?? DOCUMENT_STATUS_META.uploaded;
                                                const chunkCount = Number(document.chunk_count ?? 0);
                                                const isDeleting = deletingDocumentId === document.id;
                                                const uploadedAtLabel = document.uploaded_at
                                                    ? new Intl.DateTimeFormat(locale, {
                                                        day: '2-digit',
                                                        month: 'short',
                                                        year: 'numeric',
                                                        hour: '2-digit',
                                                        minute: '2-digit',
                                                    }).format(new Date(document.uploaded_at))
                                                    : '—';

                                                return (
                                                    <tr key={document.id} className="align-top">
                                                        <td className="px-5 py-4">
                                                            <div className="space-y-1.5">
                                                                <div className="font-medium text-slate-950">
                                                                    {document.original_filename}
                                                                </div>
                                                                <div className="flex flex-wrap gap-2">
                                                                    {document.has_extracted_text ? (
                                                                        <div className="inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-emerald-700 ring-1 ring-inset ring-emerald-200">
                                                                            Tekst ekstrahert
                                                                        </div>
                                                                    ) : null}
                                                                    {chunkCount > 0 ? (
                                                                        <div className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                            Tekstbiter: {chunkCount}
                                                                        </div>
                                                                    ) : null}
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td className="px-5 py-4 text-sm text-slate-600">
                                                            {uploadedAtLabel}
                                                        </td>
                                                        <td className="px-5 py-4 text-sm text-slate-600">
                                                            {document.file_size_human ?? '—'}
                                                        </td>
                                                        <td className="px-5 py-4">
                                                            <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${documentStatusMeta.className}`}>
                                                                {documentStatusMeta.label}
                                                            </span>
                                                        </td>
                                                        <td className="px-5 py-4 text-sm text-slate-600">
                                                            {document.uploaded_by ?? '—'}
                                                        </td>
                                                        <td className="px-5 py-4">
                                                            <button
                                                                type="button"
                                                                onClick={() => deleteDocument(document)}
                                                                disabled={isDeleting || deletingDocumentId !== null}
                                                                className="inline-flex items-center justify-center rounded-full border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                            >
                                                                {isDeleting ? 'Sletter...' : 'Slett'}
                                                            </button>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}
                    </div>
                </section>

                <section className="rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="space-y-5">
                        <div className="space-y-2">
                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                Søk
                            </div>
                            <h2 className="text-xl font-semibold tracking-tight text-slate-950">
                                Søk
                            </h2>
                            <p className="max-w-3xl text-sm leading-6 text-slate-500">
                                Søk i anbudsdokumentene for denne saken.
                            </p>
                        </div>

                        <form onSubmit={submitSearch} className="space-y-4">
                            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                                <div className="space-y-2">
                                    <label htmlFor="ai-document-search" className="text-sm font-medium text-slate-700">
                                        Søk i dokumenter
                                    </label>
                                    <input
                                        id="ai-document-search"
                                        type="search"
                                        value={searchInput}
                                        onChange={(event) => setSearchInput(event.target.value)}
                                        placeholder="Søk i ekstrahert dokumenttekst for denne saken."
                                        className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-violet-400 focus:ring-4 focus:ring-violet-100"
                                    />
                                </div>

                                <button
                                    type="submit"
                                    className="inline-flex items-center justify-center rounded-2xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    Søk
                                </button>
                            </div>
                        </form>

                        {hasSearchQuery ? (
                            searchRows.length === 0 ? (
                                <div className="rounded-[22px] border border-dashed border-slate-300 bg-slate-50 px-6 py-10">
                                    <div className="text-lg font-semibold text-slate-900">
                                        Ingen treff funnet i denne saken.
                                    </div>
                                    <p className="mt-2 text-sm text-slate-500">
                                        Prøv et annet søkeord eller en annen frase.
                                    </p>
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    {searchRows.map((result) => (
                                        <article key={result.chunk_id} className="rounded-[18px] border border-slate-200 bg-slate-50 p-4">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <div className="font-medium text-slate-950">
                                                    {result.document_filename}
                                                </div>
                                                <span className="inline-flex rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    Tekstbit {Number(result.chunk_index ?? 0) + 1}
                                                </span>
                                            </div>
                                            <p className="mt-2 text-sm leading-6 text-slate-600">
                                                {result.snippet}
                                            </p>
                                        </article>
                                    ))}
                                </div>
                            )
                        ) : (
                            <div className="rounded-[22px] border border-dashed border-slate-300 bg-slate-50 px-6 py-10">
                                <div className="text-lg font-semibold text-slate-900">
                                    Søk i ekstrahert dokumenttekst for denne saken.
                                </div>
                                <p className="mt-2 text-sm text-slate-500">
                                    Bruk et ord eller en frase for å finne treff i opplastede dokumenter.
                                </p>
                            </div>
                        )}
                    </div>
                </section>

                <section className="rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                Kravkandidater
                            </div>
                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                {requirementCountLabel} totalt
                            </span>
                        </div>
                        <h2 className="text-xl font-semibold tracking-tight text-slate-950">
                            Kravkandidater
                        </h2>
                        <p className="text-sm leading-6 text-slate-500">
                            Mulige krav identifisert i opplastede anbudsdokumenter. Bekreftede krav blir operative arbeidskrav.
                        </p>
                    </div>
                </section>

                <div className="grid gap-5 lg:grid-cols-2">
                    <section className="lg:col-span-2 rounded-[22px] border border-slate-200 bg-slate-50/70 p-4 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <div className="space-y-1">
                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                    Kravoversikt
                                </div>
                                <h3 className="text-sm font-semibold tracking-tight text-slate-950">
                                    Oppsummering av sakskrav
                                </h3>
                                <p className="text-xs leading-5 text-slate-500">
                                    Bekreftede krav er arbeidslaget. Til vurdering og avviste krav forblir i analyse.
                                </p>
                            </div>
                            <div className="text-xs text-slate-500">
                                Oppdatert fra lagrede kravposter.
                            </div>
                        </div>

                        <div className="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1.4fr)_repeat(4,minmax(0,1fr))]">
                            <div className="rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4">
                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-emerald-700">
                                    Bekreftet
                                </div>
                                <div className="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                                    {confirmedRequirementsTotal}
                                </div>
                                <div className="mt-1 text-xs text-slate-600">
                                    Arbeidskrav
                                </div>
                            </div>

                            <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                    Ikke startet
                                </div>
                                <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                    {notStartedRequirementsTotal}
                                </div>
                                <div className="mt-1 text-xs text-slate-500">
                                    Bare bekreftede
                                </div>
                            </div>

                            <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                    Under arbeid
                                </div>
                                <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                    {inProgressRequirementsTotal}
                                </div>
                                <div className="mt-1 text-xs text-slate-500">
                                    Bare bekreftede
                                </div>
                            </div>

                            <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                    Ferdig
                                </div>
                                <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                    {doneRequirementsTotal}
                                </div>
                                <div className="mt-1 text-xs text-slate-500">
                                    Bare bekreftede
                                </div>
                            </div>

                            <div className="rounded-2xl border border-amber-100 bg-amber-50/70 p-4">
                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-amber-700">
                                    Tildelt
                                </div>
                                <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                    Tildelt: {assignedConfirmedRequirementsTotal} av {requirementCountLabel} krav
                                </div>
                                <div className="mt-1 text-xs text-slate-600">
                                    krav
                                </div>
                            </div>
                        </div>

                        <div className="mt-3 flex flex-wrap gap-2">
                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-600">
                                Til vurdering: {pendingRequirementsTotal}
                            </span>
                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-600">
                                Avvist: {rejectedRequirementsTotal}
                            </span>
                        </div>
                    </section>

                    <section className="rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        {requirementRows.length === 0 ? (
                            <div className="mt-5 rounded-[22px] border border-dashed border-slate-300 bg-slate-50 px-6 py-10">
                                <div className="text-lg font-semibold text-slate-900">
                                    Ingen kravkandidater er ekstrahert ennå.
                                </div>
                                <p className="mt-2 text-sm text-slate-500">
                                    Kravkandidater vises her når dokumentteksten inneholder tydelige kravsignaler.
                                </p>
                            </div>
                        ) : (
                            <div className="mt-5 max-h-[38rem] space-y-4 overflow-y-auto pr-2 lg:max-h-[38rem]">
                                {requirementRows.map((requirement) => {
                                    const requirementTypeMeta = REQUIREMENT_TYPE_META[requirement.requirement_type] ?? REQUIREMENT_TYPE_META.unspecified;
                                    const reviewStatusMeta = REQUIREMENT_REVIEW_STATUS_META[requirement.review_status] ?? REQUIREMENT_REVIEW_STATUS_META.pending;
                                    const reviewActions = REQUIREMENT_REVIEW_ACTIONS[requirement.review_status] ?? REQUIREMENT_REVIEW_ACTIONS.pending;
                                    const workStatus = requirement.work_status ?? 'not_started';
                                    const workStatusMeta = WORK_STATUS_META[workStatus] ?? WORK_STATUS_META.not_started;
                                    const assignedUserId = requirement.assigned_user?.id ? String(requirement.assigned_user.id) : '';
                                    const assignedUserLabel = requirement.assigned_user?.name ?? 'Ikke tildelt';
                                    const chunkLabel = typeof requirement.chunk_index === 'number'
                                        ? `Tekstbit ${requirement.chunk_index + 1}`
                                        : 'Tekstbit —';
                                    const isConfirmedRequirement = requirement.review_status === 'confirmed';

                                return (
                                    <article
                                        key={requirement.id}
                                        className={`rounded-[22px] border p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)] ${
                                                isConfirmedRequirement
                                                    ? 'border-emerald-100 bg-emerald-50/40'
                                                    : 'border-slate-200 bg-white'
                                            }`}
                                        >
                                            <div className="space-y-3">
                                                <div className="space-y-1">
                                                    <div className="text-base font-semibold leading-7 text-slate-950 break-words">
                                                        {requirement.requirement_text}
                                                    </div>
                                                    <div className={`text-[11px] font-semibold uppercase tracking-[0.12em] ${
                                                        isConfirmedRequirement ? 'text-emerald-700' : 'text-slate-400'
                                                    }`}>
                                                        {isConfirmedRequirement ? 'Arbeidslag' : 'Analysselag'}
                                                    </div>
                                                </div>

                                                <div className="flex flex-wrap gap-2">
                                                    <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${reviewStatusMeta.className}`}>
                                                        {requirement.review_status_label ?? reviewStatusMeta.label}
                                                    </span>
                                                    <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${requirementTypeMeta.className}`}>
                                                        {requirement.requirement_type_label ?? requirementTypeMeta.label}
                                                    </span>
                                                    {isConfirmedRequirement ? (
                                                        <>
                                                            <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${workStatusMeta.className}`}>
                                                                {requirement.work_status_label ?? workStatusMeta.label}
                                                            </span>
                                                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-600">
                                                                Tildelt: {assignedUserLabel}
                                                            </span>
                                                        </>
                                                    ) : null}
                                            </div>

                                            <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                                <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1">
                                                    Kilde: {requirement.document_filename ?? '—'}
                                                    </span>
                                                    <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1">
                                                        {chunkLabel}
                                                </span>
                                            </div>

                                            {isConfirmedRequirement ? (
                                                <div className="space-y-3 border-t border-slate-200/80 pt-4">
                                                    <div className="space-y-2">
                                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                            Relevant kunnskapsgrunnlag
                                                        </div>
                                                        {Array.isArray(requirement.matched_knowledge) && requirement.matched_knowledge.length > 0 ? (
                                                            <div className="space-y-2">
                                                                {requirement.matched_knowledge.slice(0, 5).map((match) => (
                                                                    <div key={`${match.knowledge_item_id}-${match.chunk_id}`} className="rounded-2xl border border-slate-200 bg-white p-3">
                                                                        <div className="flex flex-wrap items-center gap-2">
                                                                            <div className="font-medium text-slate-950">
                                                                                {match.knowledge_item_title}
                                                                            </div>
                                                                            <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                                {match.content_type_label ?? match.content_type}
                                                                            </span>
                                                                        </div>
                                                                        <p className="mt-2 text-sm leading-6 text-slate-600">
                                                                            {formatKnowledgeSnippet(match.chunk_content)}
                                                                        </p>
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        ) : (
                                                            <p className="text-sm text-slate-500">
                                                                Ingen relevant kunnskap funnet.
                                                            </p>
                                                        )}
                                                    </div>

                                                    <div className="grid gap-3 md:grid-cols-2">
                                                        <label className="block space-y-1">
                                                            <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                Arbeidsstatus
                                                            </span>
                                                                <select
                                                                    value={workStatus}
                                                                    onChange={(event) => updateRequirementWork(requirement, event.target.value, assignedUserId)}
                                                                    disabled={requirementUpdatesLocked}
                                                                    className="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-violet-400 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                                >
                                                                    {WORK_STATUS_OPTIONS.map((option) => (
                                                                        <option key={option.value} value={option.value}>
                                                                            {option.label}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                            </label>
                                                            <label className="block space-y-1">
                                                                <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                    Tildelt til
                                                                </span>
                                                                <select
                                                                    value={assignedUserId}
                                                                    onChange={(event) => updateRequirementWork(requirement, workStatus, event.target.value)}
                                                                    disabled={requirementUpdatesLocked}
                                                                    className="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-violet-400 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                                >
                                                                    <option value="">
                                                                        Ikke tildelt
                                                                    </option>
                                                                    {assignedUserOptions.map((option) => (
                                                                        <option key={option.value} value={option.value}>
                                                                            {option.label}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                            </label>
                                                        </div>
                                                        <div className="flex flex-wrap gap-2">
                                                            {reviewActions.map((action) => (
                                                                <button
                                                                    key={action.value}
                                                                    type="button"
                                                                    onClick={() => updateRequirementReviewStatus(requirement, action.value)}
                                                                    disabled={requirementUpdatesLocked}
                                                                    className={`inline-flex items-center justify-center rounded-full border px-3 py-1.5 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 ${action.className}`}
                                                                >
                                                                    {action.label}
                                                                </button>
                                                            ))}
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <div className="flex flex-wrap gap-2 border-t border-slate-200/80 pt-4">
                                                        {reviewActions.map((action) => (
                                                            <button
                                                                key={action.value}
                                                                type="button"
                                                                onClick={() => updateRequirementReviewStatus(requirement, action.value)}
                                                                disabled={requirementUpdatesLocked}
                                                                className={`inline-flex items-center justify-center rounded-full border px-3 py-1.5 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 ${action.className}`}
                                                            >
                                                                {action.label}
                                                            </button>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        </article>
                                    );
                                })}
                            </div>
                        )}
                    </section>

                    <section className="rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="space-y-2">
                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                AI-status
                            </div>
                            <h2 className="text-xl font-semibold tracking-tight text-slate-950">
                                AI-status
                            </h2>
                            <p className="text-sm leading-6 text-slate-500">
                                Denne saken er for øyeblikket markert som {aiStatusMeta.label} basert på tilgjengelige saksdata.
                            </p>
                        </div>
                    </section>
                </div>
            </div>
        </CustomerAppLayout>
    );
}
