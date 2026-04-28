import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import {
    readRememberedAiRequirementId,
    writeRememberedAiRequirementId,
} from '../../../Support/aiWorkspaceState';

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

const KNOWLEDGE_GROUNDING_META = {
    green: {
        label: 'Godt kunnskapsgrunnlag',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
    amber: {
        label: 'Delvis kunnskapsgrunnlag',
        className: 'bg-amber-100 text-amber-700 ring-amber-200',
    },
    red: {
        label: 'Svakt kunnskapsgrunnlag',
        className: 'bg-rose-100 text-rose-700 ring-rose-200',
    },
};

const KNOWLEDGE_GROUNDING_JUDGE_META = {
    supported: {
        label: 'Støttet av kunnskap',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
    partial: {
        label: 'Delvis kunnskapsgrunnlag',
        className: 'bg-amber-100 text-amber-700 ring-amber-200',
    },
    unsupported: {
        label: 'Svakt kunnskapsgrunnlag',
        className: 'bg-rose-100 text-rose-700 ring-rose-200',
    },
    failed: {
        label: 'Kunne ikke vurderes sikkert',
        className: 'bg-slate-100 text-slate-700 ring-slate-200',
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

function normalizeAnswerDraftPayload(answerDraft) {
    return {
        text: normalizeAnswerDraftText(answerDraft?.text ?? ''),
        generated_at: answerDraft?.generated_at ?? null,
        knowledge_grounding: normalizeKnowledgeGroundingPayload(answerDraft?.knowledge_grounding ?? null),
        generation_state: typeof answerDraft?.generation_state === 'string' ? answerDraft.generation_state : null,
        missing_knowledge: normalizeMissingKnowledgePayload(answerDraft?.missing_knowledge ?? null),
    };
}

function normalizeMissingKnowledgePayload(missingKnowledge) {
    if (!missingKnowledge || typeof missingKnowledge !== 'object') {
        return null;
    }

    const message = typeof missingKnowledge?.message === 'string' ? missingKnowledge.message.trim() : '';
    const recommendedDocumentTitle = typeof missingKnowledge?.recommended_document_title === 'string'
        ? missingKnowledge.recommended_document_title.trim()
        : '';
    const suggestedFilename = typeof missingKnowledge?.suggested_filename === 'string'
        ? missingKnowledge.suggested_filename.trim()
        : '';
    const missingKnowledgeSummary = typeof missingKnowledge?.missing_knowledge_summary === 'string'
        ? missingKnowledge.missing_knowledge_summary.trim()
        : '';
    const reasoningSummary = typeof missingKnowledge?.reasoning_summary === 'string'
        ? missingKnowledge.reasoning_summary.trim()
        : '';
    const judgeStatus = ['supported', 'partial', 'unsupported', 'failed'].includes(missingKnowledge?.judge_status)
        ? missingKnowledge.judge_status
        : null;
    const directlySupportedPoints = normalizeGroundingPointList(
        missingKnowledge?.directly_supported_points
        ?? missingKnowledge?.supported_points
        ?? [],
    );
    const relatedButInsufficientPoints = normalizeStringList(missingKnowledge?.related_but_insufficient_points ?? []);
    const unsupportedPoints = normalizeStringList(missingKnowledge?.unsupported_points ?? []);

    if (
        message === ''
        && recommendedDocumentTitle === ''
        && suggestedFilename === ''
        && missingKnowledgeSummary === ''
        && reasoningSummary === ''
        && judgeStatus === null
        && directlySupportedPoints.length === 0
        && relatedButInsufficientPoints.length === 0
        && unsupportedPoints.length === 0
    ) {
        return null;
    }

    return {
        message: message !== '' ? message : null,
        recommended_document_title: recommendedDocumentTitle !== '' ? recommendedDocumentTitle : null,
        suggested_filename: suggestedFilename !== '' ? suggestedFilename : null,
        missing_knowledge_summary: missingKnowledgeSummary !== '' ? missingKnowledgeSummary : null,
        reasoning_summary: reasoningSummary !== '' ? reasoningSummary : null,
        judge_status: judgeStatus,
        can_generate_answer: typeof missingKnowledge?.can_generate_answer === 'boolean'
            ? missingKnowledge.can_generate_answer
            : null,
        directly_supported_points: directlySupportedPoints,
        related_but_insufficient_points: relatedButInsufficientPoints,
        unsupported_points: unsupportedPoints,
        supported_points: directlySupportedPoints.map((point) => point.requirement_point),
    };
}

function normalizeGroundingPointList(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    const normalizedValues = [];
    const seen = new Set();

    value.forEach((item) => {
        if (typeof item === 'string') {
            const normalizedItem = String(item ?? '').replace(/\s+/g, ' ').trim();

            if (normalizedItem === '') {
                return;
            }

            const key = normalizedItem.toLowerCase();

            if (seen.has(key)) {
                return;
            }

            seen.add(key);
            normalizedValues.push({
                requirement_point: normalizedItem,
                support_summary: normalizedItem,
                evidence_reference: null,
                evidence_quote: null,
                source: null,
            });
            return;
        }

        if (!item || typeof item !== 'object') {
            return;
        }

        const requirementPoint = typeof item.requirement_point === 'string'
            ? item.requirement_point.replace(/\s+/g, ' ').trim()
            : '';
        const supportSummary = typeof item.support_summary === 'string'
            ? item.support_summary.replace(/\s+/g, ' ').trim()
            : '';
        const evidenceReference = typeof item.evidence_reference === 'string'
            ? item.evidence_reference.replace(/\s+/g, ' ').trim()
            : '';
        const evidenceQuote = typeof item.evidence_quote === 'string'
            ? item.evidence_quote.replace(/\s+/g, ' ').trim()
            : '';
        const source = normalizeGroundingSource(item.source);

        const normalizedRequirementPoint = requirementPoint !== '' ? requirementPoint : supportSummary;
        const normalizedSupportSummary = supportSummary !== '' ? supportSummary : requirementPoint;

        if (normalizedRequirementPoint === '' && normalizedSupportSummary === '') {
            return;
        }

        const key = normalizedRequirementPoint.toLowerCase() || normalizedSupportSummary.toLowerCase();

        if (seen.has(key)) {
            return;
        }

        seen.add(key);
        normalizedValues.push({
            requirement_point: normalizedRequirementPoint,
            support_summary: normalizedSupportSummary,
            evidence_reference: evidenceReference !== '' ? evidenceReference : null,
            evidence_quote: evidenceQuote !== '' ? evidenceQuote : null,
            source,
        });
    });

    return normalizedValues;
}

function normalizeGroundingSource(value) {
    if (!value || typeof value !== 'object') {
        return null;
    }

    const knowledgeItemId = Number(value.knowledge_item_id);
    const knowledgeItemChunkId = Number(value.knowledge_item_chunk_id);
    const documentTitle = typeof value.document_title === 'string'
        ? value.document_title.replace(/\s+/g, ' ').trim()
        : '';
    const sectionTitle = typeof value.section_title === 'string'
        ? value.section_title.replace(/\s+/g, ' ').trim()
        : '';
    const sectionPath = typeof value.section_path === 'string'
        ? value.section_path.replace(/\s+/g, ' ').trim()
        : '';
    const sourceLabel = typeof value.source_label === 'string'
        ? value.source_label.replace(/\s+/g, ' ').trim()
        : '';
    const openUrl = typeof value.open_url === 'string'
        ? value.open_url.trim()
        : '';
    const content = typeof value.content === 'string'
        ? value.content.trim()
        : '';
    const contentPreview = typeof value.content_preview === 'string'
        ? value.content_preview.replace(/\s+/g, ' ').trim()
        : '';
    const chunkIndex = Number(value.chunk_index);

    if (!Number.isInteger(knowledgeItemId) || knowledgeItemId <= 0) {
        return null;
    }

    if (!Number.isInteger(knowledgeItemChunkId) || knowledgeItemChunkId <= 0) {
        return null;
    }

    return {
        knowledge_item_id: knowledgeItemId,
        knowledge_item_chunk_id: knowledgeItemChunkId,
        document_title: documentTitle !== '' ? documentTitle : null,
        section_title: sectionTitle !== '' ? sectionTitle : null,
        section_path: sectionPath !== '' ? sectionPath : null,
        chunk_index: Number.isInteger(chunkIndex) && chunkIndex > 0 ? chunkIndex : null,
        source_label: sourceLabel !== '' ? sourceLabel : null,
        open_url: openUrl !== '' ? openUrl : null,
        content: content !== '' ? content : null,
        content_preview: contentPreview !== '' ? contentPreview : null,
    };
}

function EvidenceSourceModal({ evidence = null, onClose }) {
    if (!evidence || typeof onClose !== 'function') {
        return null;
    }

    const source = evidence.source ?? null;
    const contentPreview = source?.content
        ?? source?.content_preview
        ?? evidence.evidence_quote
        ?? evidence.evidence_reference
        ?? 'Ingen utdrag er tilgjengelig for dette beviset.';

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center overflow-hidden bg-slate-950/45 px-4 py-4"
            role="dialog"
            aria-modal="true"
            aria-label="Bevisvisning"
            onClick={(event) => {
                if (event.target === event.currentTarget) {
                    onClose();
                }
            }}
        >
            <div className="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_20px_60px_rgba(15,23,42,0.18)]">
                <div className="shrink-0 flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
                    <div className="space-y-1">
                        <div className="text-xs font-semibold uppercase tracking-[0.16em] text-violet-600">
                            Bevis
                        </div>
                        <h2 className="text-xl font-semibold text-slate-950">
                            {evidence.requirement_point ?? '—'}
                        </h2>
                        {evidence.support_summary ? (
                            <p className="text-sm leading-6 text-slate-500">
                                {evidence.support_summary}
                            </p>
                        ) : null}
                    </div>

                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:border-slate-300 hover:text-slate-900"
                        aria-label="Lukk bevisvisning"
                    >
                        ×
                    </button>
                </div>

                <div className="grid gap-4 overflow-y-auto px-6 py-6 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start">
                    <div className="space-y-4 lg:max-h-[calc(90vh-12rem)] lg:overflow-y-auto lg:pr-1">
                        <section className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                            <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                Kilde
                            </div>
                            <div className="mt-2 space-y-2 text-sm leading-6 text-slate-700">
                                <div>
                                    <span className="font-semibold text-slate-900">Dokument:</span>{' '}
                                    {source?.document_title ?? '—'}
                                </div>
                                <div>
                                    <span className="font-semibold text-slate-900">Seksjon:</span>{' '}
                                    {source?.section_title ?? '—'}
                                </div>
                                <div>
                                    <span className="font-semibold text-slate-900">Seksjonssti:</span>{' '}
                                    {source?.section_path ?? '—'}
                                </div>
                                <div>
                                    <span className="font-semibold text-slate-900">Chunk:</span>{' '}
                                    {source?.chunk_index ?? '—'}
                                </div>
                                <div>
                                    <span className="font-semibold text-slate-900">Dokument-ID:</span>{' '}
                                    {source?.knowledge_item_id ?? '—'}
                                </div>
                                <div>
                                    <span className="font-semibold text-slate-900">Chunk-ID:</span>{' '}
                                    {source?.knowledge_item_chunk_id ?? '—'}
                                </div>
                            </div>
                        </section>

                        <section className="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                            <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                Utdrag
                            </div>
                            <div className="mt-3 whitespace-pre-wrap text-sm leading-7 text-slate-700">
                                {contentPreview}
                            </div>
                        </section>
                    </div>

                    <aside className="space-y-4 lg:max-h-[calc(90vh-12rem)] lg:overflow-y-auto lg:pr-1">
                        <section className="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                            <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                Bevislinje
                            </div>
                            <div className="mt-2 text-sm leading-6 text-slate-700">
                                {evidence.evidence_reference ? (
                                    <div className="font-medium text-slate-900">
                                        {evidence.evidence_reference}
                                    </div>
                                ) : null}
                                {evidence.evidence_quote ? (
                                    <div className="mt-2 text-slate-600">
                                        {evidence.evidence_quote}
                                    </div>
                                ) : null}
                                {source?.source_label ? (
                                    <div className="mt-3 text-xs text-slate-500">
                                        {source.source_label}
                                    </div>
                                ) : null}
                            </div>
                        </section>
                    </aside>
                </div>

                <div className="shrink-0 border-t border-slate-200 bg-white px-6 py-5">
                    <div className="flex flex-col gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            onClick={onClose}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                        >
                            Lukk
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

function normalizeStringList(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    const normalizedValues = [];
    const seen = new Set();

    value.forEach((item) => {
        const normalizedItem = String(item ?? '').replace(/\s+/g, ' ').trim();

        if (normalizedItem === '') {
            return;
        }

        const key = normalizedItem.toLowerCase();

        if (seen.has(key)) {
            return;
        }

        seen.add(key);
        normalizedValues.push(normalizedItem);
    });

    return normalizedValues;
}

function normalizeKnowledgeGroundingPayload(knowledgeGrounding) {
    if (!knowledgeGrounding || typeof knowledgeGrounding !== 'object') {
        return null;
    }

    const level = ['green', 'amber', 'red'].includes(knowledgeGrounding?.level)
        ? knowledgeGrounding.level
        : null;

    if (level === null) {
        return null;
    }

    return {
        level,
        max_score: Number(knowledgeGrounding?.max_score ?? 0),
        sources_count: Number(knowledgeGrounding?.sources_count ?? 0),
    };
}

function normalizeAnswerDraftText(value) {
    return String(value ?? '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
}

function buildRequirementAnswerDraftState(requirement) {
    const normalizedDraft = normalizeAnswerDraftPayload(requirement?.answer_draft ?? null);
    const normalizedText = normalizeAnswerDraftText(normalizedDraft.text);

    return {
        text: normalizedText,
        persistedText: normalizedText,
        generatedAt: normalizedDraft.generated_at,
        knowledgeGrounding: normalizedDraft.knowledge_grounding,
        generationState: normalizedDraft.generation_state ?? (normalizedText !== '' || normalizedDraft.generated_at !== null ? 'generated' : null),
        missingKnowledge: normalizedDraft.missing_knowledge,
        isDirty: false,
    };
}

function normalizeAnswerBasisItemIds(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    const normalizedIds = [];

    value.forEach((itemId) => {
        const normalizedItemId = Number(itemId);

        if (!Number.isInteger(normalizedItemId) || normalizedItemId <= 0) {
            return;
        }

        if (!normalizedIds.includes(normalizedItemId)) {
            normalizedIds.push(normalizedItemId);
        }
    });

    return normalizedIds;
}

function buildRequirementAnswerBasisSelectionState(requirement) {
    return normalizeAnswerBasisItemIds(requirement?.answer_basis_item_ids ?? []);
}

function extractAxiosErrorMessage(error, fallbackMessage) {
    return error?.response?.data?.message
        ?? error?.response?.data?.error
        ?? error?.message
        ?? fallbackMessage;
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
    pageTitle = 'I arbeid',
    case: caseData = null,
    ai_status: aiStatus = 'not_started',
    requirements_count: requirementsCount = 0,
    requirements_overview: requirementsOverviewProp = {},
    requirements = [],
    requirements_store_url: requirementsStoreUrl = '',
    assessment_refresh_url: assessmentRefreshUrl = '',
    evidence_refresh_url: evidenceRefreshUrl = '',
    documents = [],
    documents_upload_url: documentsUploadUrl = '',
    answer_basis_items: answerBasisItemsProp = [],
    answer_basis_documents_upload_url: answerBasisDocumentsUploadUrl = '',
    answer_basis_text_store_url: answerBasisTextStoreUrl = '',
}) {
    const currentCaseId = caseData?.id ?? null;
    const {
        locale = 'nb-NO',
        assigned_user_options: assignedUserOptionsProp = [],
    } = usePage().props;
    const fileInputRef = useRef(null);
    const answerBasisDocumentFileInputRef = useRef(null);
    const [reviewingRequirementId, setReviewingRequirementId] = useState(null);
    const [workingRequirementId, setWorkingRequirementId] = useState(null);
    const [refreshingAssessments, setRefreshingAssessments] = useState(false);
    const [refreshingEvidence, setRefreshingEvidence] = useState(false);
    const [updatingEvidenceId, setUpdatingEvidenceId] = useState(null);
    const [deletingDocumentId, setDeletingDocumentId] = useState(null);
    const [editingRequirementId, setEditingRequirementId] = useState(null);
    const [activeRequirementId, setActiveRequirementId] = useState(() => {
        if (currentCaseId === null || currentCaseId === undefined) {
            return null;
        }

        const rememberedRequirementId = readRememberedAiRequirementId(currentCaseId);

        if (rememberedRequirementId === null) {
            return null;
        }

        const currentRequirements = Array.isArray(requirements) ? requirements : [];

        return currentRequirements.some((requirement) => String(requirement.id) === rememberedRequirementId)
            ? rememberedRequirementId
            : null;
    });
    const [answerDraftsByRequirementId, setAnswerDraftsByRequirementId] = useState({});
    const [answerBasisSelectionsByRequirementId, setAnswerBasisSelectionsByRequirementId] = useState({});
    const [answerDraftGeneratingRequirementId, setAnswerDraftGeneratingRequirementId] = useState(null);
    const [answerDraftSavingRequirementId, setAnswerDraftSavingRequirementId] = useState(null);
    const [answerDraftError, setAnswerDraftError] = useState(null);
    const [answerBasisSelectionSavingRequirementId, setAnswerBasisSelectionSavingRequirementId] = useState(null);
    const [answerBasisSelectionError, setAnswerBasisSelectionError] = useState(null);
    const [deletingAnswerBasisItemId, setDeletingAnswerBasisItemId] = useState(null);
    const [selectedEvidence, setSelectedEvidence] = useState(null);
    const [showAdvancedAI, setShowAdvancedAI] = useState(false);
    const [showManualRequirementForm, setShowManualRequirementForm] = useState(false);
    const documentRefreshInFlightRef = useRef(false);
    const finalRequirementsRefreshInFlightRef = useRef(false);
    const documentUploadForm = useForm({
        documents: [],
    });
    const answerBasisDocumentUploadForm = useForm({
        documents: [],
    });
    const answerBasisTextForm = useForm({
        title: '',
        body_text: '',
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
    const answerBasisItems = Array.isArray(answerBasisItemsProp) ? answerBasisItemsProp : [];
    const requirementRows = Array.isArray(requirements) ? requirements : [];
    const answerBasisItemsById = answerBasisItems.reduce((accumulator, item) => {
        accumulator[String(item.id)] = item;

        return accumulator;
    }, {});
    const requirementsOverview = requirementsOverviewProp && typeof requirementsOverviewProp === 'object'
        ? requirementsOverviewProp
        : {};
    const documentNeedsRefresh = hasActiveDocumentProcessing(documentRows);
    const editingRequirement = editingRequirementId !== null
        ? requirementRows.find((requirement) => requirement.id === editingRequirementId) ?? null
        : null;
    const documentError = Object.values(documentUploadForm.errors).find(Boolean) ?? null;
    const answerBasisDocumentError = Object.values(answerBasisDocumentUploadForm.errors).find(Boolean) ?? null;
    const answerBasisTextError = Object.values(answerBasisTextForm.errors).find(Boolean) ?? null;
    const manualRequirementError = Object.values(manualRequirementForm.errors).find(Boolean) ?? null;
    const requirementEditError = Object.values(requirementEditForm.errors).find(Boolean) ?? null;
    const selectedDocumentsLabel = documentUploadForm.data.documents.length > 0
        ? documentUploadForm.data.documents.map((document) => document.name).join(', ')
        : 'Ingen filer valgt ennå.';
    const requirementCountLabel = Number(requirementsCount ?? requirementRows.length);
    const requirementUpdatesLocked = reviewingRequirementId !== null
        || workingRequirementId !== null
        || refreshingAssessments
        || refreshingEvidence
        || answerDraftGeneratingRequirementId !== null
        || answerDraftSavingRequirementId !== null
        || answerBasisSelectionSavingRequirementId !== null
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
        setAnswerDraftsByRequirementId((currentState) => {
            const nextState = { ...currentState };

            requirementRows.forEach((requirement) => {
                const requirementKey = String(requirement.id);
                const serverDraftState = buildRequirementAnswerDraftState(requirement);
                const currentDraftState = nextState[requirementKey];

                if (currentDraftState === undefined) {
                    nextState[requirementKey] = serverDraftState;
                    return;
                }

                if (!currentDraftState.isDirty) {
                    nextState[requirementKey] = {
                        ...currentDraftState,
                        text: serverDraftState.text,
                        persistedText: serverDraftState.persistedText,
                        generatedAt: serverDraftState.generatedAt,
                        knowledgeGrounding: serverDraftState.knowledgeGrounding ?? currentDraftState.knowledgeGrounding ?? null,
                        isDirty: false,
                    };
                }
            });

            return nextState;
        });
    }, [requirementRows]);

    useEffect(() => {
        const nextSelections = {};

        requirementRows.forEach((requirement) => {
            nextSelections[String(requirement.id)] = buildRequirementAnswerBasisSelectionState(requirement);
        });

        setAnswerBasisSelectionsByRequirementId(nextSelections);
    }, [requirementRows]);

    useEffect(() => {
        if (currentCaseId === null || currentCaseId === undefined) {
            return;
        }

        if (activeRequirementId === null) {
            writeRememberedAiRequirementId(currentCaseId, null);
            return;
        }

        const normalizedActiveRequirementId = String(activeRequirementId);
        const activeRequirementExists = requirementRows.some((requirement) => String(requirement.id) === normalizedActiveRequirementId);

        if (!activeRequirementExists) {
            setActiveRequirementId(null);
            writeRememberedAiRequirementId(currentCaseId, null);
            return;
        }

        writeRememberedAiRequirementId(currentCaseId, normalizedActiveRequirementId);
    }, [activeRequirementId, currentCaseId, requirementRows]);

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

    useEffect(() => {
        if (documentNeedsRefresh) {
            finalRequirementsRefreshInFlightRef.current = false;
            return undefined;
        }

        const hasCompletedDocument = documentRows.some((document) => document?.processing_status === 'completed');
        const shouldForceFinalRequirementsRefresh = hasCompletedDocument && requirementRows.length === 0;

        if (!shouldForceFinalRequirementsRefresh || finalRequirementsRefreshInFlightRef.current) {
            return undefined;
        }

        finalRequirementsRefreshInFlightRef.current = true;

        router.reload({
            only: ['case', 'ai_status', 'documents', 'requirements', 'requirements_count', 'requirements_overview'],
            preserveScroll: true,
            preserveState: true,
        });

        return undefined;
    }, [documentNeedsRefresh, documentRows, requirementRows.length]);

    const activeRequirement = activeRequirementId !== null
        ? requirementRows.find((requirement) => String(requirement.id) === String(activeRequirementId)) ?? null
        : null;
    const activeRequirementKey = activeRequirement !== null ? String(activeRequirement.id) : null;
    const activeRequirementDraft = activeRequirementKey !== null
        ? answerDraftsByRequirementId[activeRequirementKey] ?? buildRequirementAnswerDraftState(activeRequirement)
        : null;
    const activeRequirementBlockedMissingKnowledge = activeRequirementDraft?.generationState === 'blocked_missing_knowledge';
    const activeRequirementHasDraft = activeRequirementDraft !== null
        && !activeRequirementBlockedMissingKnowledge
        && (
            activeRequirementDraft.generatedAt !== null
            || normalizeAnswerDraftText(activeRequirementDraft.persistedText).trim() !== ''
        );
    const activeRequirementDisplayIdentifier = activeRequirement?.current_requirement_identifier
        ?? activeRequirement?.requirement_identifier
        ?? '—';
    const activeRequirementKnowledgeGrounding = activeRequirementDraft?.knowledgeGrounding ?? null;
    const activeRequirementMissingKnowledge = activeRequirementDraft?.missingKnowledge ?? null;
    const activeRequirementMissingKnowledgeJudgeMeta = activeRequirementMissingKnowledge?.judge_status
        ? KNOWLEDGE_GROUNDING_JUDGE_META[activeRequirementMissingKnowledge.judge_status] ?? null
        : null;
    const activeRequirementAnswerBasisItemIds = activeRequirementKey !== null
        ? answerBasisSelectionsByRequirementId[activeRequirementKey] ?? buildRequirementAnswerBasisSelectionState(activeRequirement)
        : [];
    const activeRequirementSelectedAnswerBasisItems = activeRequirementAnswerBasisItemIds
        .map((answerBasisItemId) => answerBasisItemsById[String(answerBasisItemId)])
        .filter(Boolean);

    const handleDocumentChange = (event) => {
        documentUploadForm.setData('documents', Array.from(event.target.files ?? []));
    };

    const handleAnswerBasisDocumentChange = (event) => {
        answerBasisDocumentUploadForm.setData('documents', Array.from(event.target.files ?? []));
    };

    const resolveRequirementSourceDocument = (requirement) => {
        const requirementDocumentId = requirement?.saved_notice_ai_document_id ?? null;

        if (requirementDocumentId === null) {
            return null;
        }

        return documentRows.find((document) => String(document?.id ?? '') === String(requirementDocumentId)) ?? null;
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

    const submitAnswerBasisDocuments = (event) => {
        event.preventDefault();

        if (!answerBasisDocumentsUploadUrl || answerBasisDocumentUploadForm.processing) {
            return;
        }

        answerBasisDocumentUploadForm.post(answerBasisDocumentsUploadUrl, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                answerBasisDocumentUploadForm.reset('documents');
                answerBasisDocumentUploadForm.clearErrors();

                if (answerBasisDocumentFileInputRef.current) {
                    answerBasisDocumentFileInputRef.current.value = '';
                }
            },
        });
    };

    const submitAnswerBasisText = (event) => {
        event.preventDefault();

        if (!answerBasisTextStoreUrl || answerBasisTextForm.processing) {
            return;
        }

        answerBasisTextForm.post(answerBasisTextStoreUrl, {
            preserveScroll: true,
            onSuccess: () => {
                answerBasisTextForm.reset();
                answerBasisTextForm.clearErrors();
            },
        });
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
                setShowManualRequirementForm(false);
            },
        });
    };

    const startEditingRequirement = (requirement) => {
        const isEditingCurrentRequirement = editingRequirementId !== null
            && String(editingRequirementId) === String(requirement?.id ?? '');

        if (requirementUpdatesLocked && !isEditingCurrentRequirement) {
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

        if (!editingRequirement || !editingRequirement.edit_url || requirementEditForm.processing) {
            return;
        }

        requirementEditForm.patch(editingRequirement.edit_url, {
            preserveScroll: true,
            onSuccess: () => {
                cancelEditingRequirement();
            },
        });
    };

    const requestAnswerDraftGeneration = async (requirement, { force = false } = {}) => {
        if (!requirement || !requirement.answer_draft_generate_url || requirement.approval_status === 'rejected') {
            return;
        }

        const requirementKey = String(requirement.id);
        const selectedAnswerBasisItemIds = answerBasisSelectionsByRequirementId[requirementKey]
            ?? buildRequirementAnswerBasisSelectionState(requirement);

        setActiveRequirementId(String(requirement.id));
        setAnswerDraftError(null);
        setAnswerBasisSelectionError(null);

        if (!requirement.answer_draft_generate_url) {
            setAnswerDraftError('Svarutkast kan ikke genereres for dette kravet.');
            return;
        }

        if (
            answerDraftGeneratingRequirementId !== null
            || answerDraftSavingRequirementId !== null
            || answerBasisSelectionSavingRequirementId !== null
        ) {
            return;
        }

        setAnswerDraftGeneratingRequirementId(requirement.id);

        try {
            const response = await window.axios.post(requirement.answer_draft_generate_url, {
                answer_basis_item_ids: selectedAnswerBasisItemIds,
                force,
            });
            const answerDraft = normalizeAnswerDraftPayload(response?.data?.answer_draft ?? null);
            const normalizedText = normalizeAnswerDraftText(answerDraft.text);
            const knowledgeGrounding = normalizeKnowledgeGroundingPayload(response?.data?.knowledge_grounding ?? null);
            const responseAnswerBasisItemIds = normalizeAnswerBasisItemIds(
                response?.data?.answer_basis_item_ids ?? selectedAnswerBasisItemIds,
            );

            setAnswerBasisSelectionsByRequirementId((currentState) => ({
                ...currentState,
                [requirementKey]: responseAnswerBasisItemIds,
            }));

            setAnswerDraftsByRequirementId((currentState) => ({
                ...currentState,
                [requirementKey]: {
                    text: normalizedText,
                    persistedText: normalizedText,
                    generatedAt: answerDraft.generated_at,
                    knowledgeGrounding,
                    generationState: answerDraft.generation_state ?? (normalizedText !== '' || answerDraft.generated_at !== null ? 'generated' : null),
                    missingKnowledge: answerDraft.missing_knowledge ?? null,
                    isDirty: false,
                },
            }));
        } catch (error) {
            setAnswerDraftError(extractAxiosErrorMessage(error, 'Kunne ikke generere svarutkast.'));
        } finally {
            setAnswerDraftGeneratingRequirementId(null);
        }
    };

    const openRequirementAnswerWorkspace = (requirement) => {
        if (!requirement || !requirement.answer_draft_generate_url || requirement.approval_status === 'rejected') {
            return;
        }

        setActiveRequirementId(String(requirement.id));
        setAnswerDraftError(null);
        setAnswerBasisSelectionError(null);
    };

    const openEvidenceSource = (evidence) => {
        if (!evidence || typeof evidence !== 'object' || !evidence.source) {
            return;
        }

        setSelectedEvidence(evidence);
    };

    const closeEvidenceSource = () => {
        setSelectedEvidence(null);
    };

    const syncRequirementAnswerBasisSelection = async (requirement, nextAnswerBasisItemIds) => {
        if (
            !requirement
            || !requirement.answer_draft_generate_url
            || requirement.approval_status === 'rejected'
            || !requirement.answer_basis_selection_sync_url
        ) {
            return;
        }

        if (
            answerDraftGeneratingRequirementId !== null
            || answerDraftSavingRequirementId !== null
            || answerBasisSelectionSavingRequirementId !== null
        ) {
            return;
        }

        const requirementKey = String(requirement.id);
        const normalizedItemIds = normalizeAnswerBasisItemIds(nextAnswerBasisItemIds);

        setAnswerBasisSelectionSavingRequirementId(requirement.id);
        setAnswerBasisSelectionError(null);

        try {
            const response = await window.axios.patch(requirement.answer_basis_selection_sync_url, {
                answer_basis_item_ids: normalizedItemIds,
            });
            const responseItemIds = normalizeAnswerBasisItemIds(
                response?.data?.answer_basis_item_ids ?? normalizedItemIds,
            );

            setAnswerBasisSelectionsByRequirementId((currentState) => ({
                ...currentState,
                [requirementKey]: responseItemIds,
            }));
        } catch (error) {
            setAnswerBasisSelectionError(extractAxiosErrorMessage(error, 'Kunne ikke lagre kilder.'));
        } finally {
            setAnswerBasisSelectionSavingRequirementId(null);
        }
    };

    const toggleActiveRequirementAnswerBasisItem = (answerBasisItemId) => {
        if (activeRequirement === null) {
            return;
        }

        const currentSelectedIds = activeRequirementAnswerBasisItemIds;
        const nextSelectedIds = currentSelectedIds.includes(answerBasisItemId)
            ? currentSelectedIds.filter((currentId) => currentId !== answerBasisItemId)
            : [...currentSelectedIds, answerBasisItemId];

        void syncRequirementAnswerBasisSelection(activeRequirement, nextSelectedIds);
    };

    const updateActiveAnswerDraftText = (text) => {
        if (activeRequirementKey === null) {
            return;
        }

        const normalizedText = normalizeAnswerDraftText(text);

        setAnswerDraftsByRequirementId((currentState) => {
            const existingDraft = currentState[activeRequirementKey] ?? buildRequirementAnswerDraftState(activeRequirement);

            return {
                ...currentState,
                [activeRequirementKey]: {
                    ...existingDraft,
                    text: normalizedText,
                    generationState: existingDraft.generationState ?? (normalizedText !== '' ? 'generated' : null),
                    isDirty: normalizedText !== existingDraft.persistedText,
                },
            };
        });
    };

    useEffect(() => {
        if (!selectedEvidence) {
            return undefined;
        }

        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                closeEvidenceSource();
            }
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => {
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [selectedEvidence]);

    const saveActiveAnswerDraft = async () => {
        if (activeRequirement === null || activeRequirementDraft === null) {
            return;
        }

        if (!activeRequirement.answer_draft_save_url) {
            setAnswerDraftError('Svarutkast kan ikke lagres for dette kravet.');
            return;
        }

        if (answerDraftGeneratingRequirementId !== null || answerDraftSavingRequirementId !== null) {
            return;
        }

        const normalizedText = normalizeAnswerDraftText(activeRequirementDraft.text).trim();

        if (normalizedText === '') {
            setAnswerDraftError('Svarutkastet kan ikke være tomt.');
            return;
        }

        setAnswerDraftSavingRequirementId(activeRequirement.id);
        setAnswerDraftError(null);

        try {
            const response = await window.axios.patch(activeRequirement.answer_draft_save_url, {
                answer_draft_text: normalizedText,
            });
            const answerDraft = normalizeAnswerDraftPayload(response?.data?.answer_draft ?? null);
            const savedText = normalizeAnswerDraftText(answerDraft.text);
            const currentKnowledgeGrounding = activeRequirementDraft?.knowledgeGrounding ?? null;

            setAnswerDraftsByRequirementId((currentState) => ({
                ...currentState,
                [activeRequirementKey]: {
                    text: savedText,
                    persistedText: savedText,
                    generatedAt: answerDraft.generated_at,
                    knowledgeGrounding: currentKnowledgeGrounding,
                    generationState: answerDraft.generation_state ?? 'generated',
                    missingKnowledge: activeRequirementDraft?.missingKnowledge ?? null,
                    isDirty: false,
                },
            }));
        } catch (error) {
            setAnswerDraftError(extractAxiosErrorMessage(error, 'Kunne ikke lagre svarutkast.'));
        } finally {
            setAnswerDraftSavingRequirementId(null);
        }
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
                                                    <tr
                                                        key={document.id}
                                                        id={`ai-document-row-${document.id}`}
                                                        className="align-top transition-colors"
                                                    >
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
                                Kilder
                            </div>
                            <h2 className="text-xl font-semibold tracking-tight text-slate-950">
                                Kilder
                            </h2>
                            <p className="max-w-3xl text-sm leading-6 text-slate-500">
                                Legg inn dokumenter og tekst som AI kan bruke når svarutkast genereres eller forbedres.
                            </p>
                        </div>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <form onSubmit={submitAnswerBasisDocuments} className="space-y-4 rounded-[22px] border border-violet-200 bg-violet-50/40 p-4">
                                <div className="space-y-1">
                                    <div className="text-xs font-medium uppercase tracking-[0.16em] text-violet-600">
                                        Legg til dokument
                                    </div>
                                    <h3 className="text-sm font-semibold tracking-tight text-slate-950">
                                        Last opp kildedokumenter
                                    </h3>
                                    <p className="text-xs leading-5 text-slate-500">
                                        Disse dokumentene kan brukes av AI ved generering av svar.
                                    </p>
                                </div>

                                <div className="space-y-2">
                                    <label htmlFor="ai-answer-basis-documents" className="text-sm font-medium text-slate-700">
                                        Velg filer
                                    </label>
                                    <div className="flex min-h-[56px] items-center gap-4 rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                                        <label
                                            htmlFor="ai-answer-basis-documents"
                                            className="inline-flex shrink-0 cursor-pointer items-center rounded-full bg-slate-950 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
                                        >
                                            Velg filer
                                        </label>
                                        <span className="min-w-0 flex-1 text-sm text-slate-500">
                                            {answerBasisDocumentUploadForm.data.documents.length > 0
                                                ? answerBasisDocumentUploadForm.data.documents.map((document) => document.name).join(', ')
                                                : 'Ingen filer valgt ennå.'}
                                        </span>
                                        <input
                                            id="ai-answer-basis-documents"
                                            ref={answerBasisDocumentFileInputRef}
                                            type="file"
                                            multiple
                                            accept=".pdf,.doc,.docx,.xls,.xlsx"
                                            onChange={handleAnswerBasisDocumentChange}
                                            className="sr-only"
                                        />
                                    </div>
                                    <p className="text-xs leading-5 text-slate-500">
                                        Tillatte filtyper: PDF, DOC, DOCX, XLS, XLSX. Maks 20 MB per fil.
                                    </p>
                                    {answerBasisDocumentError ? (
                                        <p className="text-sm text-rose-600">{answerBasisDocumentError}</p>
                                    ) : null}
                                </div>

                                <button
                                    type="submit"
                                    disabled={
                                        answerBasisDocumentUploadForm.processing
                                        || !answerBasisDocumentsUploadUrl
                                        || answerBasisDocumentUploadForm.data.documents.length === 0
                                    }
                                    className="inline-flex items-center justify-center rounded-2xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {answerBasisDocumentUploadForm.processing ? 'Laster opp...' : 'Legg til dokument'}
                                </button>
                            </form>

                            <form onSubmit={submitAnswerBasisText} className="space-y-4 rounded-[22px] border border-slate-200 bg-slate-50/70 p-4">
                                <div className="space-y-1">
                                    <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                        Legg til tekst
                                    </div>
                                    <h3 className="text-sm font-semibold tracking-tight text-slate-950">
                                        Lim inn eller skriv tekst
                                    </h3>
                                    <p className="text-xs leading-5 text-slate-500">
                                        Bruk dette for metodikk, standardtekster, referansebeskrivelser og andre gjenbrukbare tekstblokker.
                                    </p>
                                </div>

                                <label className="block space-y-1">
                                    <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                        Tittel
                                    </span>
                                    <input
                                        type="text"
                                        value={answerBasisTextForm.data.title}
                                        onChange={(event) => answerBasisTextForm.setData('title', event.target.value)}
                                        disabled={answerBasisTextForm.processing}
                                        className="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-violet-400 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                        placeholder="For eksempel Metodebeskrivelse"
                                    />
                                    {answerBasisTextForm.errors.title ? (
                                        <p className="text-sm text-rose-600">{answerBasisTextForm.errors.title}</p>
                                    ) : null}
                                </label>

                                <label className="block space-y-1">
                                    <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                        Tekst
                                    </span>
                                    <textarea
                                        value={answerBasisTextForm.data.body_text}
                                        onChange={(event) => answerBasisTextForm.setData('body_text', event.target.value)}
                                        rows={6}
                                        disabled={answerBasisTextForm.processing}
                                        className="w-full rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-violet-400 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                        placeholder="Skriv eller lim inn gjenbrukbar tekst som AI kan bruke."
                                    />
                                    {answerBasisTextForm.errors.body_text ? (
                                        <p className="text-sm text-rose-600">{answerBasisTextForm.errors.body_text}</p>
                                    ) : null}
                                </label>

                                {answerBasisTextError ? (
                                    <p className="text-sm text-rose-600">{answerBasisTextError}</p>
                                ) : null}

                                <button
                                    type="submit"
                                    disabled={answerBasisTextForm.processing || !answerBasisTextStoreUrl}
                                    className="inline-flex items-center justify-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {answerBasisTextForm.processing ? 'Lagrer...' : 'Legg til tekst'}
                                </button>
                            </form>
                        </div>

                        {answerBasisItems.length === 0 ? (
                            <div className="rounded-[22px] border border-dashed border-slate-300 bg-slate-50 px-6 py-10">
                                <div className="text-lg font-semibold text-slate-900">
                                    Ingen kilder er lagt til ennå.
                                </div>
                                <p className="mt-2 text-sm text-slate-500">
                                    Legg til dokumenter eller tekstblokker som AI kan bruke når du genererer svarutkast.
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {answerBasisItems.map((answerBasisItem) => {
                                    const isDeleting = deletingAnswerBasisItemId === answerBasisItem.id;
                                    const bodySnippet = formatKnowledgeSnippet(answerBasisItem.body_text, 220);

                                    return (
                                        <div
                                            key={answerBasisItem.id}
                                            className="rounded-[18px] border border-slate-200 bg-white p-4 shadow-sm"
                                        >
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div className="space-y-2">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <div className="font-medium text-slate-950">
                                                            {answerBasisItem.title}
                                                        </div>
                                                        <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                            {answerBasisItem.answer_basis_type_label}
                                                        </span>
                                                    </div>
                                                    {answerBasisItem.original_filename ? (
                                                        <div className="text-sm text-slate-500">
                                                            {answerBasisItem.original_filename}
                                                        </div>
                                                    ) : null}
                                                    <p className="max-w-4xl text-sm leading-6 text-slate-600">
                                                        {bodySnippet}
                                                    </p>
                                                    <div className="text-xs text-slate-500">
                                                        {answerBasisItem.created_by ? `Opprettet av ${answerBasisItem.created_by}` : '—'}
                                                    </div>
                                                </div>

                                                <div className="flex flex-wrap gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            if (answerBasisItem.delete_url) {
                                                                if (deletingAnswerBasisItemId !== null) {
                                                                    return;
                                                                }

                                                                const confirmed = window.confirm('Slette denne kilden?');

                                                                if (!confirmed) {
                                                                    return;
                                                                }

                                                                setDeletingAnswerBasisItemId(answerBasisItem.id);

                                                                router.delete(answerBasisItem.delete_url, {
                                                                    preserveScroll: true,
                                                                    preserveState: true,
                                                                    onFinish: () => {
                                                                        setDeletingAnswerBasisItemId(null);
                                                                    },
                                                                });
                                                            }
                                                        }}
                                                        disabled={isDeleting || deletingAnswerBasisItemId !== null}
                                                        className="inline-flex items-center justify-center rounded-full border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        {isDeleting ? 'Sletter...' : 'Slett'}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
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

                <div className="grid gap-5 lg:grid-cols-2 lg:items-start">
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

                    <section className="rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] lg:flex lg:max-h-[calc(100vh-8rem)] lg:min-h-0 lg:flex-col lg:overflow-hidden">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="space-y-2">
                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                    Krav
                                </div>
                                <h2 className="text-xl font-semibold tracking-tight text-slate-950">
                                    Krav
                                </h2>
                                <p className="max-w-3xl text-sm leading-6 text-slate-500">
                                    Velg et krav og lag svar. Kilder, scoring og vurderinger ligger i avansert visning.
                                </p>
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={() => setShowManualRequirementForm((value) => !value)}
                                    disabled={requirementUpdatesLocked && !showManualRequirementForm}
                                    aria-expanded={showManualRequirementForm}
                                    className="inline-flex items-center justify-center rounded-full border border-violet-200 bg-violet-50 px-4 py-2 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {showManualRequirementForm ? 'Skjul skjema' : 'Legg til krav'}
                                </button>

                                <button
                                    type="button"
                                    onClick={() => setShowAdvancedAI((value) => !value)}
                                    className="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                                >
                                    {showAdvancedAI ? 'Skjul avansert' : 'Avansert'}
                                </button>

                                {showAdvancedAI ? (
                                    <>
                                        <button
                                            type="button"
                                            onClick={refreshEvidence}
                                            disabled={!evidenceRefreshUrl || requirementUpdatesLocked}
                                            className="inline-flex items-center justify-center rounded-full border border-violet-200 bg-violet-50 px-4 py-2 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {refreshingEvidence ? 'Oppdaterer...' : 'Oppdater kilder'}
                                        </button>

                                        <button
                                            type="button"
                                            onClick={refreshAssessments}
                                            disabled={!assessmentRefreshUrl || requirementUpdatesLocked}
                                            className="inline-flex items-center justify-center rounded-full border border-slate-200 bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {refreshingAssessments ? 'Analyserer...' : 'Analyser krav'}
                                        </button>
                                    </>
                                ) : null}
                            </div>
                        </div>

                        {showManualRequirementForm ? (
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
                        ) : null}

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
                            <div className="mt-5 max-h-[38rem] space-y-4 overflow-y-auto pr-2 lg:min-h-0 lg:max-h-none lg:flex-1">
                                {requirementRows.map((requirement) => {
                                    const sourceTypeMeta = REQUIREMENT_SOURCE_TYPE_META[requirement.source_type] ?? REQUIREMENT_SOURCE_TYPE_META.ai_candidate;
                                    const approvalStatus = requirement.approval_status ?? 'draft';
                                    const approvalStatusMeta = REQUIREMENT_APPROVAL_STATUS_META[approvalStatus] ?? REQUIREMENT_APPROVAL_STATUS_META.draft;
                                    const approvalActions = REQUIREMENT_APPROVAL_ACTIONS[approvalStatus] ?? REQUIREMENT_APPROVAL_ACTIONS.draft;
                                    const workStatus = requirement.work_status ?? 'not_started';
                                    const workStatusMeta = WORK_STATUS_META[workStatus] ?? WORK_STATUS_META.not_started;
                                    const assignedUserId = requirement.assigned_user?.id ? String(requirement.assigned_user.id) : '';
                                    const assignedUserLabel = requirement.assigned_user?.name ?? 'Ikke tildelt';
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
                                    const showEvidenceSection = showAdvancedAI && (isApprovedRequirement || evidenceRows.length > 0);
                                    const isActiveRequirement = String(activeRequirementId) === String(requirement.id);
                                    const canOpenAnswerWorkspace = Boolean(requirement.answer_draft_generate_url)
                                        && approvalStatus !== 'rejected';
                                    const requirementDraftState = answerDraftsByRequirementId[String(requirement.id)] ?? buildRequirementAnswerDraftState(requirement);
                                    const hasExistingAnswerDraft = (
                                        requirementDraftState.generatedAt !== null
                                        || normalizeAnswerDraftText(requirementDraftState.text).trim() !== ''
                                    );

                                    return (
                                        <article
                                            key={requirement.id}
                                            role={canOpenAnswerWorkspace ? 'button' : undefined}
                                            tabIndex={canOpenAnswerWorkspace ? 0 : undefined}
                                            onClick={(event) => {
                                                if (!canOpenAnswerWorkspace) {
                                                    return;
                                                }

                                                if (event.target instanceof Element && event.target.closest('button,a,input,select,textarea,label')) {
                                                    return;
                                                }

                                                void openRequirementAnswerWorkspace(requirement);
                                            }}
                                            onKeyDown={(event) => {
                                                if (!canOpenAnswerWorkspace) {
                                                    return;
                                                }

                                                if (event.target instanceof Element && event.target.closest('button,a,input,select,textarea,label')) {
                                                    return;
                                                }

                                                if (event.key === 'Enter' || event.key === ' ') {
                                                    event.preventDefault();
                                                    void openRequirementAnswerWorkspace(requirement);
                                                }
                                            }}
                                            className={`rounded-[22px] border p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)] transition ${
                                                isApprovedRequirement
                                                    ? 'border-emerald-100 bg-emerald-50/40'
                                                    : isRejectedRequirement
                                                        ? 'border-rose-100 bg-rose-50/30'
                                                    : 'border-slate-200 bg-white'
                                            } ${
                                                canOpenAnswerWorkspace ? 'cursor-pointer hover:border-violet-300' : ''
                                            } ${
                                                isActiveRequirement
                                                    ? 'ring-2 ring-violet-300 ring-offset-2 ring-offset-white'
                                                    : ''
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
                                                        {canOpenAnswerWorkspace ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    void requestAnswerDraftGeneration(requirement, { force: true });
                                                                }}
                                                                disabled={requirementUpdatesLocked || answerDraftGeneratingRequirementId === requirement.id}
                                                                aria-pressed={isActiveRequirement}
                                                                title="Generer svarutkast for dette kravet"
                                                                className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset transition disabled:cursor-not-allowed disabled:opacity-60 ${sourceTypeMeta.className} ${
                                                                    isActiveRequirement ? 'ring-violet-300' : ''
                                                                }`}
                                                            >
                                                                {hasExistingAnswerDraft ? 'Lag nytt svar' : 'Lag svar'}
                                                            </button>
                                                        ) : (
                                                            <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${sourceTypeMeta.className}`}>
                                                                {requirement.source_type_label ?? sourceTypeMeta.label}
                                                            </span>
                                                        )}
                                                        <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${approvalStatusMeta.className}`}>
                                                            {requirement.approval_status_label ?? approvalStatusMeta.label}
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
                                                    {(() => {
                                                        const sourceDocument = resolveRequirementSourceDocument(requirement);
                                                        const sourceDocumentUrl = sourceDocument?.preview_url ?? null;
                                                        const sourceDocumentPreviewMode = sourceDocument?.preview_mode ?? 'unavailable';
                                                        const sourceDocumentLabel = sourceDocument?.original_filename ?? requirement.document_filename ?? '—';
                                                        const canPreviewSourceDocument = Boolean(sourceDocumentUrl) && sourceDocumentPreviewMode !== 'unavailable';

                                                        if (canPreviewSourceDocument) {
                                                            return (
                                                                <a
                                                                    href={sourceDocumentUrl}
                                                                    className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1 font-medium text-slate-600 transition hover:border-violet-200 hover:bg-violet-50 hover:text-violet-700"
                                                                >
                                                                    Forhåndsvis kilde: {sourceDocumentLabel}
                                                                </a>
                                                            );
                                                        }

                                                        return (
                                                            <button
                                                                type="button"
                                                                disabled
                                                                className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1 font-medium text-slate-400 opacity-60"
                                                            >
                                                                Forhåndsvis kilde: {sourceDocumentLabel}
                                                            </button>
                                                        );
                                                    })()}
                                                </div>

                                                {activeRequirement ? (
                                                    showAdvancedAI ? (
                                                        <div className="space-y-4 border-t border-slate-200/80 pt-4">
                                                            <div className="flex flex-wrap items-center justify-between gap-3">
                                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                    Kilder
                                                                </div>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        void requestAnswerDraftGeneration(activeRequirement, { force: true });
                                                                    }}
                                                                    disabled={
                                                                        !activeRequirement.answer_draft_generate_url
                                                                        || answerDraftGeneratingRequirementId === activeRequirement.id
                                                                        || answerDraftSavingRequirementId === activeRequirement.id
                                                                        || answerBasisSelectionSavingRequirementId === activeRequirement.id
                                                                    }
                                                                    className="inline-flex items-center justify-center rounded-full border border-violet-200 bg-violet-50 px-3 py-1.5 text-xs font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                                >
                                                                    {answerDraftGeneratingRequirementId === activeRequirement.id ? 'Genererer...' : 'Generer på nytt'}
                                                                </button>
                                                            </div>

                                                            {answerBasisSelectionError ? (
                                                                <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm leading-6 text-rose-700">
                                                                    {answerBasisSelectionError}
                                                                </div>
                                                            ) : null}

                                                            {activeRequirementSelectedAnswerBasisItems.length > 0 ? (
                                                                <div className="space-y-2">
                                                                    <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                        Valgte kilder
                                                                    </div>
                                                                    <div className="space-y-2">
                                                                        {activeRequirementSelectedAnswerBasisItems.map((answerBasisItem) => {
                                                                            const isSelected = activeRequirementAnswerBasisItemIds.includes(answerBasisItem.id);
                                                                            const isToggling = answerBasisSelectionSavingRequirementId === activeRequirement.id;

                                                                            return (
                                                                                <div
                                                                                    key={answerBasisItem.id}
                                                                                    className="rounded-2xl border border-violet-200 bg-violet-50/50 px-4 py-3"
                                                                                >
                                                                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                                                                        <div className="space-y-1">
                                                                                            <div className="font-medium text-slate-950">
                                                                                                {answerBasisItem.title}
                                                                                            </div>
                                                                                            <div className="flex flex-wrap gap-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                                                <span>{answerBasisItem.answer_basis_type_label}</span>
                                                                                                {answerBasisItem.original_filename ? (
                                                                                                    <span>{answerBasisItem.original_filename}</span>
                                                                                                ) : null}
                                                                                            </div>
                                                                                        </div>
                                                                                        <button
                                                                                            type="button"
                                                                                            onClick={() => toggleActiveRequirementAnswerBasisItem(answerBasisItem.id)}
                                                                                            disabled={isToggling || !isSelected}
                                                                                            className="inline-flex items-center justify-center rounded-full border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                                                        >
                                                                                            Fjern
                                                                                        </button>
                                                                                    </div>
                                                                                </div>
                                                                            );
                                                                        })}
                                                                    </div>
                                                                </div>
                                                            ) : (
                                                                <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">
                                                                    Ingen kilder er valgt for dette kravet ennå.
                                                                </div>
                                                            )}

                                                            <div className="space-y-2">
                                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                    Velg kilder
                                                                </div>

                                                                {answerBasisItems.length === 0 ? (
                                                                    <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">
                                                                        Legg til dokumenter eller tekst i kilder-seksjonen for å kunne knytte dem til kravet.
                                                                    </div>
                                                                ) : (
                                                                    <div className="space-y-2">
                                                                        {answerBasisItems.map((answerBasisItem) => {
                                                                            const isSelected = activeRequirementAnswerBasisItemIds.includes(answerBasisItem.id);
                                                                            const isToggling = answerBasisSelectionSavingRequirementId === activeRequirement.id;

                                                                            return (
                                                                                <div
                                                                                    key={answerBasisItem.id}
                                                                                    className={`rounded-2xl border px-4 py-3 ${
                                                                                        isSelected
                                                                                            ? 'border-violet-200 bg-violet-50/50'
                                                                                            : 'border-slate-200 bg-white'
                                                                                    }`}
                                                                                >
                                                                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                                                                        <div className="min-w-0 space-y-1">
                                                                                            <div className="flex flex-wrap items-center gap-2">
                                                                                                <div className="font-medium text-slate-950">
                                                                                                    {answerBasisItem.title}
                                                                                                </div>
                                                                                                <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                                                    {answerBasisItem.answer_basis_type_label}
                                                                                                </span>
                                                                                            </div>
                                                                                            {answerBasisItem.original_filename ? (
                                                                                                <div className="text-sm text-slate-500">
                                                                                                    {answerBasisItem.original_filename}
                                                                                                </div>
                                                                                            ) : null}
                                                                                            <p className="max-w-4xl text-sm leading-6 text-slate-600">
                                                                                                {formatKnowledgeSnippet(answerBasisItem.body_text, 160)}
                                                                                            </p>
                                                                                        </div>

                                                                                        <button
                                                                                            type="button"
                                                                                            onClick={() => toggleActiveRequirementAnswerBasisItem(answerBasisItem.id)}
                                                                                            disabled={isToggling}
                                                                                            className={`inline-flex items-center justify-center rounded-full px-3 py-1.5 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 ${
                                                                                                isSelected
                                                                                                    ? 'border border-rose-200 bg-white text-rose-700 hover:border-rose-300 hover:bg-rose-50'
                                                                                                    : 'border border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-100'
                                                                                            }`}
                                                                                        >
                                                                                            {isSelected ? 'Fjern' : 'Legg til'}
                                                                                        </button>
                                                                                    </div>
                                                                                </div>
                                                                            );
                                                                        })}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">
                                                            Svarutkastet vises her og kan redigeres direkte. Velg Avansert for kilder og vurderinger.
                                                        </div>
                                                    )
                                                ) : null}

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
                                                                disabled={requirementEditForm.processing}
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

                                                {showAdvancedAI ? (
                                                    <div className="space-y-3 border-t border-slate-200/80 pt-4">
                                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                Vurdering
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
                                                                        Vurdering feilet for dette kravet. Kjør analyse på nytt for å forsøke igjen.
                                                                    </div>
                                                                )
                                                            ) : (
                                                                <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">
                                                                    Vurdering er ikke generert ennå. Bruk &quot;Analyser krav&quot; for å vurdere dette kravet.
                                                                </div>
                                                            )
                                                        ) : (
                                                            <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">
                                                                Vurdering vises når kravet er godkjent.
                                                            </div>
                                                        )}
                                                    </div>
                                                ) : null}

                                                {showEvidenceSection ? (
                                                    <div className="space-y-3 border-t border-slate-200/80 pt-4">
                                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                Kilder
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
                                                                Ingen kilder lagret ennå. Oppdater kilder for å finne relevante dokumenter.
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
                                                            disabled={requirementUpdatesLocked && !isEditingThisRequirement}
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

                    <section className="rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] lg:max-h-[calc(100vh-8rem)] lg:overflow-y-auto">
                        <div className="space-y-5">
                            <div className="space-y-2">
                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                    Svarutkast
                                </div>
                                <h2 className="text-xl font-semibold tracking-tight text-slate-950">
                                    I arbeid
                                </h2>
                                <p className="text-sm leading-6 text-slate-500">
                                    Klikk et krav for å åpne svarutkastet. Trykk Lag svar for å generere et nytt.
                                </p>
                            </div>

                            {!activeRequirement ? (
                                <div className="rounded-[22px] border border-dashed border-slate-300 bg-slate-50 px-6 py-10">
                                    <div className="text-lg font-semibold text-slate-900">
                                        Ingen aktivt svarutkast ennå.
                                    </div>
                                    <p className="mt-2 text-sm text-slate-500">
                                        Klikk på et kravkort for å åpne svarutkastet her.
                                    </p>
                                </div>
                            ) : (
                                <div className={`space-y-4 rounded-[22px] border p-4 ${
                                    activeRequirementBlockedMissingKnowledge
                                        ? 'border-rose-200 bg-rose-50/40'
                                        : 'border-violet-200 bg-violet-50/40'
                                }`}>
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-violet-600">
                                            Svarutkast for krav {activeRequirementDisplayIdentifier}
                                        </div>
                                        <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-600">
                                            {activeRequirement.approval_status_label ?? 'Utkast'}
                                        </span>
                                    </div>

                                    {answerDraftGeneratingRequirementId === activeRequirement.id ? (
                                        <div className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-800">
                                            Genererer svarutkast ...
                                        </div>
                                    ) : null}

                                    {answerDraftError ? (
                                        <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm leading-6 text-rose-700">
                                            {answerDraftError}
                                        </div>
                                    ) : null}

                                    {activeRequirementBlockedMissingKnowledge ? (
                                        <div className="rounded-2xl border border-rose-200 bg-white px-4 py-5 text-sm leading-6 text-slate-700">
                                            <div className="text-sm font-semibold text-slate-950">
                                                {activeRequirementMissingKnowledge?.message ?? 'Procynia har ikke laget et svar fordi kunnskapsgrunnlaget er for svakt.'}
                                            </div>
                                            <p className="mt-2 text-sm leading-6 text-slate-600">
                                                Opprett og last opp et kunnskapsdokument som dekker dette kravet.
                                            </p>
                                            <p className="mt-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">
                                                Procynia fant noe relatert kunnskap, men ikke dokumentasjon som dekker kravet direkte.
                                            </p>

                                            {activeRequirementMissingKnowledge?.missing_knowledge_summary ? (
                                                <p className="mt-3 rounded-2xl border border-amber-100 bg-amber-50/80 px-4 py-3 text-sm leading-6 text-amber-900">
                                                    {activeRequirementMissingKnowledge.missing_knowledge_summary}
                                                </p>
                                            ) : null}

                                            <div className="mt-4 space-y-2 rounded-2xl border border-rose-100 bg-rose-50/70 px-4 py-4 text-sm leading-6 text-slate-700">
                                                <div>
                                                    <span className="font-semibold text-slate-900">Anbefalt dokumentnavn:</span>{' '}
                                                    {activeRequirementMissingKnowledge?.recommended_document_title ?? 'Dokumentasjon for udekket krav'}
                                                </div>
                                                <div>
                                                    <span className="font-semibold text-slate-900">Foreslått filnavn:</span>{' '}
                                                    {activeRequirementMissingKnowledge?.suggested_filename ?? 'dokumentasjon-for-udekket-krav.docx'}
                                                </div>
                                            </div>

                                            {(activeRequirementMissingKnowledge?.directly_supported_points?.length ?? 0) > 0
                                                || (activeRequirementMissingKnowledge?.related_but_insufficient_points?.length ?? 0) > 0
                                                || (activeRequirementMissingKnowledge?.unsupported_points?.length ?? 0) > 0 ? (
                                                <div className="mt-4 space-y-3">
                                                    {(activeRequirementMissingKnowledge?.directly_supported_points?.length ?? 0) > 0 ? (
                                                        <div className="rounded-2xl border border-emerald-100 bg-emerald-50/70 px-4 py-4 text-sm leading-6 text-slate-700">
                                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-emerald-700">
                                                                Direkte støttet
                                                            </div>
                                                            <ul className="mt-3 space-y-2">
                                                                {activeRequirementMissingKnowledge.directly_supported_points.map((point, index) => (
                                                                    <li key={`${point}-${index}`} className="flex gap-2">
                                                                        <span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-emerald-500" />
                                                                        <span className="space-y-1">
                                                                            <span className="block font-medium text-slate-900">
                                                                                {point?.requirement_point ?? '—'}
                                                                            </span>
                                                                            {point?.support_summary ? (
                                                                                <span className="block text-slate-700">
                                                                                    {point.support_summary}
                                                                                </span>
                                                                            ) : null}
                                                                            {(() => {
                                                                                const evidenceLabel = point?.evidence_reference
                                                                                    ?? point?.evidence_quote
                                                                                    ?? point?.source?.source_label
                                                                                    ?? null;

                                                                                if (evidenceLabel === null) {
                                                                                    return null;
                                                                                }

                                                                                if (point?.source?.open_url) {
                                                                                    return (
                                                                                        <button
                                                                                            type="button"
                                                                                            onClick={() => openEvidenceSource(point)}
                                                                                            className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-left text-xs font-medium text-slate-600 transition hover:border-violet-200 hover:bg-violet-50 hover:text-violet-700"
                                                                                            aria-label={`Åpne bevisdetaljer for ${evidenceLabel}`}
                                                                                        >
                                                                                            Bevis: {evidenceLabel}
                                                                                        </button>
                                                                                    );
                                                                                }

                                                                                return (
                                                                                    <span className="block text-xs text-slate-500">
                                                                                        Bevis: {evidenceLabel}
                                                                                    </span>
                                                                                );
                                                                            })()}
                                                                        </span>
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                        </div>
                                                    ) : null}

                                                    {(activeRequirementMissingKnowledge?.related_but_insufficient_points?.length ?? 0) > 0 ? (
                                                        <div className="rounded-2xl border border-amber-100 bg-amber-50/70 px-4 py-4 text-sm leading-6 text-slate-700">
                                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-amber-700">
                                                                Relatert, men ikke tilstrekkelig
                                                            </div>
                                                            <ul className="mt-3 space-y-2">
                                                                {activeRequirementMissingKnowledge.related_but_insufficient_points.map((point, index) => (
                                                                    <li key={`${point}-${index}`} className="flex gap-2">
                                                                        <span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-amber-500" />
                                                                        <span>{point}</span>
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                        </div>
                                                    ) : null}

                                                    {(activeRequirementMissingKnowledge?.unsupported_points?.length ?? 0) > 0 ? (
                                                        <div className="rounded-2xl border border-rose-100 bg-rose-50/70 px-4 py-4 text-sm leading-6 text-slate-700">
                                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-rose-700">
                                                                Mangler dokumentasjon for
                                                            </div>
                                                            <ul className="mt-3 space-y-2">
                                                                {activeRequirementMissingKnowledge.unsupported_points.map((point, index) => (
                                                                    <li key={`${point}-${index}`} className="flex gap-2">
                                                                        <span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-rose-500" />
                                                                        <span>{point}</span>
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                        </div>
                                                    ) : null}
                                                </div>
                                            ) : null}

                                            {activeRequirementMissingKnowledge?.reasoning_summary
                                                && activeRequirementMissingKnowledge?.judge_status !== 'failed' ? (
                                                <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">
                                                    {activeRequirementMissingKnowledge.reasoning_summary}
                                                </div>
                                            ) : null}

                                            <p className="mt-4 text-sm leading-6 text-slate-600">
                                                Når dokumentet er lagt til og behandlet, kan du kjøre «Lag svar» på nytt.
                                            </p>

                                            {activeRequirementMissingKnowledgeJudgeMeta ? (
                                                <div className="mt-4 flex justify-end">
                                                    <span className={`inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold ring-1 ring-inset ${activeRequirementMissingKnowledgeJudgeMeta.className}`}>
                                                        {activeRequirementMissingKnowledgeJudgeMeta.label}
                                                    </span>
                                                </div>
                                            ) : activeRequirementKnowledgeGrounding ? (
                                                <div className="mt-4 flex justify-end">
                                                    <span className={`inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold ring-1 ring-inset ${KNOWLEDGE_GROUNDING_META[activeRequirementKnowledgeGrounding.level]?.className ?? KNOWLEDGE_GROUNDING_META.red.className}`}>
                                                        {KNOWLEDGE_GROUNDING_META[activeRequirementKnowledgeGrounding.level]?.label ?? KNOWLEDGE_GROUNDING_META.red.label}
                                                    </span>
                                                </div>
                                            ) : null}
                                        </div>
                                    ) : activeRequirementHasDraft ? (
                                        <>
                                            <label className="block space-y-2">
                                                <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                    Svarutkast for krav {activeRequirementDisplayIdentifier}
                                                </span>
                                                <textarea
                                                    value={activeRequirementDraft?.text ?? ''}
                                                    onChange={(event) => updateActiveAnswerDraftText(event.target.value)}
                                                    rows={16}
                                                    disabled={
                                                        answerDraftGeneratingRequirementId === activeRequirement.id
                                                        || answerDraftSavingRequirementId === activeRequirement.id
                                                    }
                                                    className="w-full max-h-[50vh] resize-y overflow-y-auto rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-violet-400 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                    placeholder="Svarutkastet vises her og kan redigeres direkte."
                                                />
                                            </label>

                                            {activeRequirementKnowledgeGrounding ? (
                                                <div className="flex justify-end">
                                                    <span className={`inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold ring-1 ring-inset ${KNOWLEDGE_GROUNDING_META[activeRequirementKnowledgeGrounding.level]?.className ?? KNOWLEDGE_GROUNDING_META.red.className}`}>
                                                        {KNOWLEDGE_GROUNDING_META[activeRequirementKnowledgeGrounding.level]?.label ?? KNOWLEDGE_GROUNDING_META.red.label}
                                                    </span>
                                                </div>
                                            ) : null}

                                            <div className="flex flex-wrap items-center justify-between gap-3">
                                                <div className="text-xs text-slate-500">
                                                    {activeRequirementDraft?.generatedAt ? (
                                                        <>
                                                            Generert{' '}
                                                            {new Intl.DateTimeFormat(locale, {
                                                                day: '2-digit',
                                                                month: 'short',
                                                                year: 'numeric',
                                                                hour: '2-digit',
                                                                minute: '2-digit',
                                                            }).format(new Date(activeRequirementDraft.generatedAt))}
                                                        </>
                                                    ) : (
                                                        'Svarutkast er ikke generert ennå.'
                                                    )}
                                                    {activeRequirementDraft?.isDirty ? ' Ulagrede endringer.' : ''}
                                                </div>

                                                <button
                                                    type="button"
                                                    onClick={saveActiveAnswerDraft}
                                                    disabled={
                                                        !activeRequirementDraft
                                                        || normalizeAnswerDraftText(activeRequirementDraft.text).trim() === ''
                                                        || !activeRequirement.answer_draft_save_url
                                                        || answerDraftGeneratingRequirementId === activeRequirement.id
                                                        || answerDraftSavingRequirementId === activeRequirement.id
                                                    }
                                                    className="inline-flex items-center justify-center rounded-full bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    {answerDraftSavingRequirementId === activeRequirement.id ? 'Lagrer...' : 'Lagre endring'}
                                                </button>
                                            </div>
                                        </>
                                    ) : (
                                        <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5">
                                            <div className="text-sm font-semibold text-slate-900">
                                                Ingen svarutkast er opprettet ennå.
                                            </div>
                                            <p className="mt-2 text-sm leading-6 text-slate-600">
                                                Trykk Lag svar på kravet for å generere et utkast for akkurat dette kravet.
                                            </p>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    </section>
                </div>

                {selectedEvidence?.source ? (
                    <EvidenceSourceModal
                        evidence={selectedEvidence}
                        onClose={closeEvidenceSource}
                    />
                ) : null}
            </div>
        </CustomerAppLayout>
    );
}
