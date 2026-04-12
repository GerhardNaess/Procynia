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
        className: 'bg-slate-100 text-slate-700 ring-slate-200',
    },
    text_extracted: {
        label: 'Tekst ekstrahert',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
    queued: {
        label: 'I kø',
        className: 'bg-amber-100 text-amber-700 ring-amber-200',
    },
    processing: {
        label: 'Behandles',
        className: 'bg-violet-100 text-violet-700 ring-violet-200',
    },
    merging: {
        label: 'Slår sammen',
        className: 'bg-sky-100 text-sky-700 ring-sky-200',
    },
    completed: {
        label: 'Fullført',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
    failed: {
        label: 'Feilet',
        className: 'bg-rose-100 text-rose-700 ring-rose-200',
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

const REQUIREMENT_SOURCE_TYPE_META = {
    ai_candidate: {
        label: 'AI-kandidat',
        className: 'bg-violet-100 text-violet-700 ring-violet-200',
    },
    manual: {
        label: 'Manuelt',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
};

const REQUIREMENT_APPROVAL_STATUS_META = {
    draft: {
        label: 'Utkast',
        className: 'bg-slate-100 text-slate-700 ring-slate-200',
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

const REQUIREMENT_APPROVAL_ACTIONS = {
    draft: [
        {
            label: 'Godkjenn',
            value: 'confirmed',
            className: 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-100',
        },
        {
            label: 'Avvis / slett',
            value: 'rejected',
            className: 'border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100',
        },
    ],
    approved: [
        {
            label: 'Til utkast',
            value: 'pending',
            className: 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950',
        },
        {
            label: 'Avvis / slett',
            value: 'rejected',
            className: 'border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100',
        },
    ],
    rejected: [
        {
            label: 'Gjenopprett',
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

const EVIDENCE_SELECTION_STATUS_META = {
    suggested: {
        label: 'Forslag',
        className: 'bg-violet-100 text-violet-700 ring-violet-200',
    },
    selected: {
        label: 'Valgt',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
    rejected: {
        label: 'Avvist',
        className: 'bg-rose-100 text-rose-700 ring-rose-200',
    },
};

const EVIDENCE_SELECTION_ACTIONS = [
    {
        label: 'Sett til forslag',
        value: 'suggested',
        className: 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950',
    },
    {
        label: 'Velg',
        value: 'selected',
        className: 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-100',
    },
    {
        label: 'Avvis',
        value: 'rejected',
        className: 'border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100',
    },
];

const ASSESSMENT_STATUS_META = {
    completed: {
        label: 'Fullført',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
    failed: {
        label: 'Feilet',
        className: 'bg-rose-100 text-rose-700 ring-rose-200',
    },
};

const COVERAGE_STATUS_META = {
    covered: {
        label: 'Dekket',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
    partial: {
        label: 'Delvis dekket',
        className: 'bg-amber-100 text-amber-700 ring-amber-200',
    },
    missing: {
        label: 'Mangler grunnlag',
        className: 'bg-rose-100 text-rose-700 ring-rose-200',
    },
};

const RISK_LEVEL_META = {
    low: {
        label: 'Lav risiko',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
    medium: {
        label: 'Middels risiko',
        className: 'bg-amber-100 text-amber-700 ring-amber-200',
    },
    high: {
        label: 'Høy risiko',
        className: 'bg-rose-100 text-rose-700 ring-rose-200',
    },
};

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

function formatDocumentFailureDetails(document) {
    if (!document || document.processing_status !== 'failed') {
        return null;
    }

    const failureStage = document.processing_failure_stage ?? document.processing_error_type ?? null;
    const failureType = document.processing_failure_type ?? document.processing_error_type ?? null;
    const failureMessage = document.processing_failure_message ?? document.processing_error_message ?? null;

    return {
        failureStage,
        failureType,
        failureMessage,
    };
}

const DOCUMENT_PROCESSING_ACTIVE_STATUSES = new Set([
    'queued',
    'processing',
    'merging',
]);

function hasActiveDocumentProcessing(documents) {
    if (!Array.isArray(documents) || documents.length === 0) {
        return false;
    }

    return documents.some((document) => DOCUMENT_PROCESSING_ACTIVE_STATUSES.has(document?.processing_status));
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
    requirements_store_url: requirementsStoreUrl = '',
    assessment_refresh_url: assessmentRefreshUrl = '',
    evidence_refresh_url: evidenceRefreshUrl = '',
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
    const [refreshingAssessments, setRefreshingAssessments] = useState(false);
    const [refreshingEvidence, setRefreshingEvidence] = useState(false);
    const [updatingEvidenceId, setUpdatingEvidenceId] = useState(null);
    const [deletingDocumentId, setDeletingDocumentId] = useState(null);
    const [editingRequirementId, setEditingRequirementId] = useState(null);
    const documentRefreshInFlightRef = useRef(false);
    const documentUploadForm = useForm({
        documents: [],
    });
    const manualRequirementForm = useForm({
        requirement_identifier: '',
        requirement_text: '',
        requirement_type: 'unspecified',
    });
    const requirementEditForm = useForm({
        requirement_identifier: '',
        requirement_text: '',
        requirement_type: 'unspecified',
    });
    const aiStatusMeta = AI_STATUS_META[aiStatus] ?? AI_STATUS_META.not_started;
    const assignedUserOptions = Array.isArray(assignedUserOptionsProp) ? assignedUserOptionsProp : [];
    const documentRows = Array.isArray(documents) ? documents : [];
    const searchRows = Array.isArray(searchResults) ? searchResults : [];
    const requirementRows = Array.isArray(requirements) ? requirements : [];
    const requirementsOverview = requirementsOverviewProp && typeof requirementsOverviewProp === 'object'
        ? requirementsOverviewProp
        : {};
    const documentNeedsRefresh = hasActiveDocumentProcessing(documentRows);
    const editingRequirement = editingRequirementId !== null
        ? requirementRows.find((requirement) => requirement.id === editingRequirementId) ?? null
        : null;
    const documentError = Object.values(documentUploadForm.errors).find(Boolean) ?? null;
    const manualRequirementError = Object.values(manualRequirementForm.errors).find(Boolean) ?? null;
    const requirementEditError = Object.values(requirementEditForm.errors).find(Boolean) ?? null;
    const selectedDocumentsLabel = documentUploadForm.data.documents.length > 0
        ? documentUploadForm.data.documents.map((document) => document.name).join(', ')
        : 'Ingen filer valgt ennå.';
    const hasSearchQuery = searchQuery.trim() !== '';
    const requirementCountLabel = Number(requirementsCount ?? requirementRows.length);
    const requirementUpdatesLocked = reviewingRequirementId !== null
        || workingRequirementId !== null
        || refreshingAssessments
        || refreshingEvidence
        || updatingEvidenceId !== null
        || manualRequirementForm.processing
        || requirementEditForm.processing
        || editingRequirementId !== null;
    const approvedRequirementsTotal = Number(requirementsOverview.approved_total ?? requirementsOverview.confirmed_total ?? 0);
    const draftRequirementsTotal = Number(requirementsOverview.draft_total ?? requirementsOverview.pending_total ?? 0);
    const rejectedRequirementsTotal = Number(requirementsOverview.rejected_total ?? 0);
    const notStartedRequirementsTotal = Number(requirementsOverview.not_started_total ?? 0);
    const inProgressRequirementsTotal = Number(requirementsOverview.in_progress_total ?? 0);
    const doneRequirementsTotal = Number(requirementsOverview.done_total ?? 0);
    const unassignedApprovedRequirementsTotal = Number(
        requirementsOverview.unassigned_approved_total ?? requirementsOverview.unassigned_confirmed_total ?? 0,
    );
    const assignedApprovedRequirementsTotal = Math.max(approvedRequirementsTotal - unassignedApprovedRequirementsTotal, 0);
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

    useEffect(() => {
        if (!documentNeedsRefresh) {
            documentRefreshInFlightRef.current = false;
            return undefined;
        }

        const refreshDocumentState = () => {
            if (documentRefreshInFlightRef.current) {
                return;
            }

            documentRefreshInFlightRef.current = true;

            router.reload({
                only: ['case', 'ai_status', 'documents', 'requirements', 'requirements_count', 'requirements_overview'],
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    documentRefreshInFlightRef.current = false;
                },
            });
        };

        refreshDocumentState();

        const refreshTimer = window.setInterval(refreshDocumentState, 3000);

        return () => {
            window.clearInterval(refreshTimer);
            documentRefreshInFlightRef.current = false;
        };
    }, [documentNeedsRefresh]);

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

    const submitManualRequirement = (event) => {
        event.preventDefault();

        if (!requirementsStoreUrl || manualRequirementForm.processing || requirementUpdatesLocked) {
            return;
        }

        manualRequirementForm.post(requirementsStoreUrl, {
            preserveScroll: true,
            onSuccess: () => {
                manualRequirementForm.reset();
                manualRequirementForm.clearErrors();
            },
        });
    };

    const startEditingRequirement = (requirement) => {
        if (requirementUpdatesLocked) {
            return;
        }

        setEditingRequirementId(requirement.id);
        requirementEditForm.setData({
            requirement_identifier: requirement.current_requirement_identifier ?? requirement.requirement_identifier ?? '',
            requirement_text: requirement.current_requirement_text ?? requirement.requirement_text ?? '',
            requirement_type: requirement.requirement_type ?? 'unspecified',
        });
        requirementEditForm.clearErrors();
    };

    const cancelEditingRequirement = () => {
        setEditingRequirementId(null);
        requirementEditForm.reset();
        requirementEditForm.clearErrors();
    };

    const submitRequirementEdit = (event) => {
        event.preventDefault();

        if (!editingRequirement || !editingRequirement.edit_url || requirementEditForm.processing || requirementUpdatesLocked) {
            return;
        }

        requirementEditForm.patch(editingRequirement.edit_url, {
            preserveScroll: true,
            onSuccess: () => {
                cancelEditingRequirement();
            },
        });
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
        if (!requirement.work_update_url || requirement.approval_status !== 'approved' || requirementUpdatesLocked) {
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

    /**
     * Purpose: Persist the selected state for one evidence row.
     * Inputs: The evidence row and the next selection status.
     * Returns: None.
     * Side effects: Sends a PATCH request that updates the evidence selection state on the server.
     */
    const updateEvidenceSelectionStatus = (evidence, selectionStatus) => {
        if (!evidence.selection_status_update_url || requirementUpdatesLocked) {
            return;
        }

        setUpdatingEvidenceId(evidence.id);

        router.patch(evidence.selection_status_update_url, {
            selection_status: selectionStatus,
        }, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                setUpdatingEvidenceId(null);
            },
        });
    };

    /**
     * Purpose: Rebuild persisted evidence rows for the visible AI case.
     * Inputs: None.
     * Returns: None.
     * Side effects: Sends a POST request that regenerates deterministic evidence rows on the server.
     */
    const refreshEvidence = () => {
        if (!evidenceRefreshUrl || requirementUpdatesLocked) {
            return;
        }

        setRefreshingEvidence(true);

        router.post(evidenceRefreshUrl, {}, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                setRefreshingEvidence(false);
            },
        });
    };

    /**
     * Purpose: Rebuild persisted assessment rows for the visible AI case.
     * Inputs: None.
     * Returns: None.
     * Side effects: Sends a POST request that regenerates the requirement assessments on the server.
     */
    const refreshAssessments = () => {
        if (!assessmentRefreshUrl || requirementUpdatesLocked) {
            return;
        }

        setRefreshingAssessments(true);

        router.post(assessmentRefreshUrl, {}, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                setRefreshingAssessments(false);
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
                                                const failureDetails = formatDocumentFailureDetails(document);
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
                                                            <div className="space-y-2">
                                                                <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${documentStatusMeta.className}`}>
                                                                    {documentStatusMeta.label}
                                                                </span>
                                                                {failureDetails ? (
                                                                    <div className="max-w-sm rounded-2xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs leading-5 text-rose-800">
                                                                        <div className="font-semibold uppercase tracking-[0.12em] text-rose-700">
                                                                            Feilsti
                                                                        </div>
                                                                        <div className="mt-1">
                                                                            Stadium: {failureDetails.failureStage ?? '—'}
                                                                        </div>
                                                                        <div>
                                                                            Type: {failureDetails.failureType ?? '—'}
                                                                        </div>
                                                                        {failureDetails.failureMessage ? (
                                                                            <div className="mt-1 whitespace-pre-wrap">
                                                                                {failureDetails.failureMessage}
                                                                            </div>
                                                                        ) : null}
                                                                    </div>
                                                                ) : null}
                                                            </div>
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
                            Mulige krav identifisert i opplastede anbudsdokumenter. Godkjente krav blir operative arbeidskrav.
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
                                    Godkjente krav er arbeidslaget. Utkast og avviste krav forblir i analyse.
                                </p>
                            </div>
                            <div className="text-xs text-slate-500">
                                Oppdatert fra lagrede kravposter.
                            </div>
                        </div>

                        <div className="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1.4fr)_repeat(4,minmax(0,1fr))]">
                            <div className="rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4">
                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-emerald-700">
                                Godkjent
                            </div>
                            <div className="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                                    {approvedRequirementsTotal}
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
                                    Bare godkjente
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
                                    Bare godkjente
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
                                    Bare godkjente
                                </div>
                            </div>

                            <div className="rounded-2xl border border-amber-100 bg-amber-50/70 p-4">
                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-amber-700">
                                    Tildelt
                                </div>
                                <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                    Tildelt: {assignedApprovedRequirementsTotal} av {requirementCountLabel} krav
                                </div>
                                <div className="mt-1 text-xs text-slate-600">
                                    krav
                                </div>
                            </div>
                        </div>

                        <div className="mt-3 flex flex-wrap gap-2">
                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-600">
                                Utkast: {draftRequirementsTotal}
                            </span>
                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-600">
                                Avvist: {rejectedRequirementsTotal}
                            </span>
                        </div>
                    </section>

                    <section className="rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="space-y-2">
                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                    Kravkandidater
                                </div>
                                <h2 className="text-xl font-semibold tracking-tight text-slate-950">
                                    Kravkandidater
                                </h2>
                                <p className="max-w-3xl text-sm leading-6 text-slate-500">
                                    Mulige krav identifisert i opplastede anbudsdokumenter. Godkjente krav blir operative arbeidskrav.
                                </p>
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={refreshEvidence}
                                    disabled={!evidenceRefreshUrl || requirementUpdatesLocked}
                                    className="inline-flex items-center justify-center rounded-full border border-violet-200 bg-violet-50 px-4 py-2 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {refreshingEvidence ? 'Oppdaterer...' : 'Oppdater bevisgrunnlag'}
                                </button>

                                <button
                                    type="button"
                                    onClick={refreshAssessments}
                                    disabled={!assessmentRefreshUrl || requirementUpdatesLocked}
                                    className="inline-flex items-center justify-center rounded-full border border-slate-200 bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {refreshingAssessments ? 'Analyserer...' : 'Analyser krav'}
                                </button>
                            </div>
                        </div>

                        <form onSubmit={submitManualRequirement} className="mt-5 space-y-4 rounded-[22px] border border-violet-200 bg-violet-50/40 p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="space-y-1">
                                    <div className="text-xs font-medium uppercase tracking-[0.16em] text-violet-600">
                                        Legg til krav
                                    </div>
                                    <h3 className="text-sm font-semibold tracking-tight text-slate-950">
                                        Opprett et nytt krav manuelt
                                    </h3>
                                    <p className="text-xs leading-5 text-slate-500">
                                        Bruk dette når AI ikke foreslår kravet, eller når krav-ID må korrigeres før videre arbeid.
                                    </p>
                                </div>
                                <button
                                    type="submit"
                                    disabled={manualRequirementForm.processing || !requirementsStoreUrl || requirementUpdatesLocked}
                                    className="inline-flex items-center justify-center rounded-full bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {manualRequirementForm.processing ? 'Lagrer...' : 'Legg til krav'}
                                </button>
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                <label className="block space-y-1">
                                    <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                        Krav-ID
                                    </span>
                                    <input
                                        type="text"
                                        value={manualRequirementForm.data.requirement_identifier}
                                        onChange={(event) => manualRequirementForm.setData('requirement_identifier', event.target.value)}
                                        disabled={manualRequirementForm.processing || requirementUpdatesLocked}
                                        className="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-violet-400 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                        placeholder="For eksempel 3.2"
                                    />
                                    {manualRequirementForm.errors.requirement_identifier ? (
                                        <p className="text-sm text-rose-600">{manualRequirementForm.errors.requirement_identifier}</p>
                                    ) : null}
                                </label>

                                <label className="block space-y-1">
                                    <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                        Kravtype
                                    </span>
                                    <select
                                        value={manualRequirementForm.data.requirement_type}
                                        onChange={(event) => manualRequirementForm.setData('requirement_type', event.target.value)}
                                        disabled={manualRequirementForm.processing || requirementUpdatesLocked}
                                        className="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-violet-400 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {Object.entries(REQUIREMENT_TYPE_META).map(([value, meta]) => (
                                            <option key={value} value={value}>
                                                {meta.label}
                                            </option>
                                        ))}
                                    </select>
                                    {manualRequirementForm.errors.requirement_type ? (
                                        <p className="text-sm text-rose-600">{manualRequirementForm.errors.requirement_type}</p>
                                    ) : null}
                                </label>
                            </div>

                            <label className="block space-y-1">
                                <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                    Kravtekst
                                </span>
                                <textarea
                                    value={manualRequirementForm.data.requirement_text}
                                    onChange={(event) => manualRequirementForm.setData('requirement_text', event.target.value)}
                                    rows={4}
                                    disabled={manualRequirementForm.processing || requirementUpdatesLocked}
                                    className="w-full rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-violet-400 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                    placeholder="Skriv kravet slik brukeren skal se det."
                                />
                                {manualRequirementForm.errors.requirement_text ? (
                                    <p className="text-sm text-rose-600">{manualRequirementForm.errors.requirement_text}</p>
                                ) : null}
                            </label>

                            {manualRequirementError ? (
                                <p className="text-sm text-rose-600">{manualRequirementError}</p>
                            ) : null}
                        </form>

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
                                    const sourceTypeMeta = REQUIREMENT_SOURCE_TYPE_META[requirement.source_type] ?? REQUIREMENT_SOURCE_TYPE_META.ai_candidate;
                                    const approvalStatus = requirement.approval_status ?? 'draft';
                                    const approvalStatusMeta = REQUIREMENT_APPROVAL_STATUS_META[approvalStatus] ?? REQUIREMENT_APPROVAL_STATUS_META.draft;
                                    const approvalActions = REQUIREMENT_APPROVAL_ACTIONS[approvalStatus] ?? REQUIREMENT_APPROVAL_ACTIONS.draft;
                                    const workStatus = requirement.work_status ?? 'not_started';
                                    const workStatusMeta = WORK_STATUS_META[workStatus] ?? WORK_STATUS_META.not_started;
                                    const assignedUserId = requirement.assigned_user?.id ? String(requirement.assigned_user.id) : '';
                                    const assignedUserLabel = requirement.assigned_user?.name ?? 'Ikke tildelt';
                                    const chunkLabel = typeof requirement.chunk_index === 'number'
                                        ? `Tekstbit ${requirement.chunk_index + 1}`
                                        : 'Tekstbit —';
                                    const currentRequirementIdentifier = requirement.current_requirement_identifier ?? requirement.requirement_identifier ?? '—';
                                    const currentRequirementText = requirement.current_requirement_text ?? requirement.requirement_text ?? '';
                                    const originalRequirementIdentifier = requirement.original_requirement_identifier ?? null;
                                    const originalRequirementText = requirement.original_requirement_text ?? null;
                                    const hasOriginalDifference = Boolean(
                                        (originalRequirementIdentifier && originalRequirementIdentifier !== currentRequirementIdentifier)
                                        || (originalRequirementText && originalRequirementText !== currentRequirementText),
                                    );
                                    const revisionCount = Number(requirement.revision_count ?? 0);
                                    const isApprovedRequirement = approvalStatus === 'approved';
                                    const isRejectedRequirement = approvalStatus === 'rejected';
                                    const isEditingThisRequirement = editingRequirementId === requirement.id;
                                    const assessment = requirement.assessment ?? null;
                                    const hasAssessment = assessment !== null;
                                    const assessmentCompleted = assessment?.assessment_status === 'completed';
                                    const assessmentFailed = assessment?.assessment_status === 'failed';
                                    const evidenceRows = Array.isArray(requirement.evidence) ? requirement.evidence : [];
                                    const assessmentDateLabel = assessment?.assessed_at
                                        ? new Intl.DateTimeFormat(locale, {
                                            day: '2-digit',
                                            month: 'short',
                                            year: 'numeric',
                                            hour: '2-digit',
                                            minute: '2-digit',
                                        }).format(new Date(assessment.assessed_at))
                                        : '—';
                                    const showEvidenceSection = isApprovedRequirement || evidenceRows.length > 0;

                                    return (
                                        <article
                                            key={requirement.id}
                                            className={`rounded-[22px] border p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)] ${
                                                isApprovedRequirement
                                                    ? 'border-emerald-100 bg-emerald-50/40'
                                                    : isRejectedRequirement
                                                        ? 'border-rose-100 bg-rose-50/30'
                                                    : 'border-slate-200 bg-white'
                                            }`}
                                        >
                                            <div className="space-y-3">
                                                <div className="flex flex-wrap items-start justify-between gap-3">
                                                    <div className="min-w-0 flex-1 space-y-2">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            {currentRequirementIdentifier !== '—' ? (
                                                                <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                    {currentRequirementIdentifier}
                                                                </span>
                                                            ) : null}
                                                            {originalRequirementIdentifier && originalRequirementIdentifier !== currentRequirementIdentifier ? (
                                                                <span className="inline-flex rounded-full border border-violet-200 bg-violet-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-violet-700">
                                                                    Original: {originalRequirementIdentifier}
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                        <div className="text-base font-semibold leading-7 text-slate-950 break-words">
                                                            {currentRequirementText}
                                                        </div>
                                                        <div className="flex flex-wrap items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-400">
                                                            <span>
                                                                {isApprovedRequirement ? 'Arbeidslag' : 'Analysselag'}
                                                            </span>
                                                            <span>
                                                                Revisjoner: {revisionCount}
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div className="flex flex-wrap gap-2">
                                                        <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${sourceTypeMeta.className}`}>
                                                            {requirement.source_type_label ?? sourceTypeMeta.label}
                                                        </span>
                                                        <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${approvalStatusMeta.className}`}>
                                                            {requirement.approval_status_label ?? approvalStatusMeta.label}
                                                        </span>
                                                        <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${requirementTypeMeta.className}`}>
                                                            {requirement.requirement_type_label ?? requirementTypeMeta.label}
                                                        </span>
                                                        <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-600">
                                                            {requirement.edit_state_label ?? 'Original'}
                                                        </span>
                                                    </div>
                                                </div>

                                                <div className="flex flex-wrap gap-2">
                                                    {isApprovedRequirement ? (
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

                                                {hasOriginalDifference ? (
                                                    <div className="rounded-2xl border border-violet-200 bg-violet-50/50 px-4 py-3">
                                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-violet-700">
                                                            Opprinnelig forslag
                                                        </div>
                                                        <p className="mt-2 text-sm leading-6 text-slate-700">
                                                            {originalRequirementText ?? '—'}
                                                        </p>
                                                    </div>
                                                ) : null}

                                                {isEditingThisRequirement ? (
                                                    <form onSubmit={submitRequirementEdit} className="space-y-4 rounded-2xl border border-violet-200 bg-violet-50/40 p-4">
                                                        <div className="grid gap-4 md:grid-cols-2">
                                                            <label className="block space-y-1">
                                                                <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                    Krav-ID
                                                                </span>
                                                                <input
                                                                    type="text"
                                                                    value={requirementEditForm.data.requirement_identifier}
                                                                    onChange={(event) => requirementEditForm.setData('requirement_identifier', event.target.value)}
                                                                    disabled={requirementEditForm.processing}
                                                                    className="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-violet-400 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                                />
                                                                {requirementEditForm.errors.requirement_identifier ? (
                                                                    <p className="text-sm text-rose-600">{requirementEditForm.errors.requirement_identifier}</p>
                                                                ) : null}
                                                            </label>

                                                            <label className="block space-y-1">
                                                                <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                    Kravtype
                                                                </span>
                                                                <select
                                                                    value={requirementEditForm.data.requirement_type}
                                                                    onChange={(event) => requirementEditForm.setData('requirement_type', event.target.value)}
                                                                    disabled={requirementEditForm.processing}
                                                                    className="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-violet-400 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                                >
                                                                    {Object.entries(REQUIREMENT_TYPE_META).map(([value, meta]) => (
                                                                        <option key={value} value={value}>
                                                                            {meta.label}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                                {requirementEditForm.errors.requirement_type ? (
                                                                    <p className="text-sm text-rose-600">{requirementEditForm.errors.requirement_type}</p>
                                                                ) : null}
                                                            </label>
                                                        </div>

                                                        <label className="block space-y-1">
                                                            <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                Kravtekst
                                                            </span>
                                                            <textarea
                                                                value={requirementEditForm.data.requirement_text}
                                                                onChange={(event) => requirementEditForm.setData('requirement_text', event.target.value)}
                                                                rows={4}
                                                                disabled={requirementEditForm.processing}
                                                                className="w-full rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-violet-400 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                            />
                                                            {requirementEditForm.errors.requirement_text ? (
                                                                <p className="text-sm text-rose-600">{requirementEditForm.errors.requirement_text}</p>
                                                            ) : null}
                                                        </label>

                                                        {requirementEditError ? (
                                                            <p className="text-sm text-rose-600">{requirementEditError}</p>
                                                        ) : null}

                                                        <div className="flex flex-wrap gap-2">
                                                            <button
                                                                type="submit"
                                                                disabled={requirementEditForm.processing || requirementUpdatesLocked}
                                                                className="inline-flex items-center justify-center rounded-full bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                            >
                                                                {requirementEditForm.processing ? 'Lagrer...' : 'Lagre endringer'}
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={cancelEditingRequirement}
                                                                disabled={requirementEditForm.processing}
                                                                className="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                                            >
                                                                Avbryt
                                                            </button>
                                                        </div>
                                                    </form>
                                                ) : null}

                                                <div className="space-y-3 border-t border-slate-200/80 pt-4">
                                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                            AI-vurdering
                                                        </div>
                                                        {hasAssessment && assessmentCompleted ? (
                                                            <span className="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-emerald-700">
                                                                Vurdert {assessmentDateLabel}
                                                            </span>
                                                        ) : hasAssessment && assessmentFailed ? (
                                                            <span className="inline-flex rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-rose-700">
                                                                Feilet
                                                            </span>
                                                        ) : null}
                                                    </div>

                                                    {isApprovedRequirement ? (
                                                        hasAssessment ? (
                                                            assessmentCompleted ? (
                                                                <div className="space-y-3">
                                                                    <div className="flex flex-wrap gap-2">
                                                                        <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${
                                                                            ASSESSMENT_STATUS_META[assessment.assessment_status]?.className
                                                                                ?? ASSESSMENT_STATUS_META.completed.className
                                                                        }`}>
                                                                            {assessment.assessment_status_label ?? ASSESSMENT_STATUS_META.completed.label}
                                                                        </span>
                                                                        {assessment.coverage_status ? (
                                                                            <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${
                                                                                COVERAGE_STATUS_META[assessment.coverage_status]?.className
                                                                                    ?? COVERAGE_STATUS_META.missing.className
                                                                            }`}>
                                                                                {assessment.coverage_status_label ?? COVERAGE_STATUS_META.missing.label}
                                                                            </span>
                                                                        ) : null}
                                                                        {assessment.risk_level ? (
                                                                            <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${
                                                                                RISK_LEVEL_META[assessment.risk_level]?.className
                                                                                    ?? RISK_LEVEL_META.high.className
                                                                            }`}>
                                                                                {assessment.risk_level_label ?? RISK_LEVEL_META.high.label}
                                                                            </span>
                                                                        ) : null}
                                                                    </div>

                                                                    <div className="grid gap-3 md:grid-cols-2">
                                                                        <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                                Oppsummering
                                                                            </div>
                                                                            <p className="mt-2 text-sm leading-6 text-slate-700">
                                                                                {assessment.requirement_summary ?? '—'}
                                                                            </p>
                                                                        </div>

                                                                        <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                                Begrunnelse
                                                                            </div>
                                                                            <p className="mt-2 text-sm leading-6 text-slate-700">
                                                                                {assessment.coverage_rationale ?? '—'}
                                                                            </p>
                                                                        </div>

                                                                        <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                                Manglende grunnlag
                                                                            </div>
                                                                            <p className="mt-2 text-sm leading-6 text-slate-700">
                                                                                {assessment.missing_information ?? '—'}
                                                                            </p>
                                                                        </div>

                                                                        <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                                Anbefalt neste steg
                                                                            </div>
                                                                            <p className="mt-2 text-sm leading-6 text-slate-700">
                                                                                {assessment.recommended_next_step ?? '—'}
                                                                            </p>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            ) : (
                                                                <div className="rounded-2xl border border-rose-200 bg-rose-50/40 px-4 py-3 text-sm leading-6 text-rose-700">
                                                                    AI-vurdering feilet for dette kravet. Kjør analyse på nytt for å forsøke igjen.
                                                                </div>
                                                            )
                                                        ) : (
                                                            <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">
                                                                AI-vurdering er ikke generert ennå. Bruk &quot;Analyser krav&quot; for å vurdere dette kravet.
                                                            </div>
                                                        )
                                                    ) : (
                                                        <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">
                                                                AI-vurdering genereres når kravet er godkjent.
                                                            </div>
                                                        )}
                                                </div>

                                                {showEvidenceSection ? (
                                                    <div className="space-y-3 border-t border-slate-200/80 pt-4">
                                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                Bevisgrunnlag
                                                            </div>
                                                            {isApprovedRequirement ? (
                                                                <span className="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-emerald-700">
                                                                    Persistert
                                                                </span>
                                                            ) : null}
                                                        </div>

                                                        {evidenceRows.length > 0 ? (
                                                            <div className="space-y-2">
                                                                {evidenceRows.map((evidence) => {
                                                                    const evidenceStatusMeta = EVIDENCE_SELECTION_STATUS_META[evidence.selection_status] ?? EVIDENCE_SELECTION_STATUS_META.suggested;
                                                                    const evidenceChunkLabel = typeof evidence.knowledge_chunk?.chunk_index === 'number'
                                                                        ? `Tekstbit ${Number(evidence.knowledge_chunk.chunk_index) + 1}`
                                                                        : 'Tekstbit —';
                                                                    const evidenceUpdating = updatingEvidenceId === evidence.id;

                                                                    return (
                                                                        <div
                                                                            key={evidence.id}
                                                                            className={`rounded-2xl border p-3 shadow-sm ${
                                                                                evidence.selection_status === 'selected'
                                                                                    ? 'border-emerald-200 bg-emerald-50/40'
                                                                                    : evidence.selection_status === 'rejected'
                                                                                        ? 'border-rose-200 bg-rose-50/40'
                                                                                        : 'border-slate-200 bg-white'
                                                                            }`}
                                                                        >
                                                                            <div className="flex flex-wrap items-center gap-2">
                                                                                <div className="font-medium text-slate-950">
                                                                                    {evidence.knowledge_item?.original_filename ?? 'Ukjent dokument'}
                                                                                </div>
                                                                                <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                                    {evidence.knowledge_item?.document_type_label ?? evidence.knowledge_item?.document_type ?? '—'}
                                                                                </span>
                                                                                <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                                    {evidence.match_type_label ?? evidence.match_type}
                                                                                </span>
                                                                                {evidence.is_primary ? (
                                                                                    <span className="inline-flex rounded-full border border-violet-200 bg-violet-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-violet-700">
                                                                                        Primær
                                                                                    </span>
                                                                                ) : null}
                                                                            </div>

                                                                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                                                                <span className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] ring-1 ring-inset ${evidenceStatusMeta.className}`}>
                                                                                    {evidence.selection_status_label ?? evidenceStatusMeta.label}
                                                                                </span>
                                                                                <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                                    Rank {Number(evidence.match_rank ?? 0)}
                                                                                </span>
                                                                                <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                                    Score {Number(evidence.match_score ?? 0)}
                                                                                </span>
                                                                                <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                                    {evidenceChunkLabel}
                                                                                </span>
                                                                            </div>

                                                                            <p className="mt-2 text-sm leading-6 text-slate-600">
                                                                                {formatKnowledgeSnippet(evidence.knowledge_chunk?.content)}
                                                                            </p>

                                                                            <div className="mt-3 flex flex-wrap gap-2">
                                                                                {EVIDENCE_SELECTION_ACTIONS.map((action) => {
                                                                                    const isCurrentStatus = evidence.selection_status === action.value;

                                                                                    return (
                                                                                        <button
                                                                                            key={action.value}
                                                                                            type="button"
                                                                                            onClick={() => updateEvidenceSelectionStatus(evidence, action.value)}
                                                                                            disabled={requirementUpdatesLocked || evidenceUpdating || isCurrentStatus}
                                                                                            className={`inline-flex items-center justify-center rounded-full border px-3 py-1.5 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 ${action.className}`}
                                                                                        >
                                                                                            {action.label}
                                                                                        </button>
                                                                                    );
                                                                                })}
                                                                            </div>
                                                                        </div>
                                                                    );
                                                                })}
                                                            </div>
                                                        ) : (
                                                            <p className="text-sm text-slate-500">
                                                                Ingen bevisgrunnlag lagret ennå. Oppdater bevisgrunnlag for å finne relevante kunnskapsdokumenter.
                                                            </p>
                                                        )}
                                                    </div>
                                                ) : null}

                                                <div className="flex flex-wrap gap-2 border-t border-slate-200/80 pt-4">
                                                    {isApprovedRequirement ? (
                                                        <div className="grid w-full gap-3 md:grid-cols-2">
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
                                                    ) : null}

                                                    <div className="flex flex-wrap gap-2">
                                                        <button
                                                            type="button"
                                                            onClick={() => startEditingRequirement(requirement)}
                                                            disabled={requirementUpdatesLocked || isEditingThisRequirement}
                                                            className="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            Rediger
                                                        </button>

                                                        {approvalActions.map((action) => (
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
