import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import InfoHint from '../../../Components/App/InfoHint';
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

function isEnglishLocale(locale) {
    return String(locale ?? '').toLowerCase().startsWith('en');
}

function getDocumentStatusMeta(status, locale) {
    const english = isEnglishLocale(locale);
    const metaByStatus = {
        uploaded: {
            label: english ? 'Uploaded' : 'Lastet opp',
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        },
        text_extracted: {
            label: english ? 'Text extracted' : 'Tekst hentet ut',
            className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        },
        queued: {
            label: english ? 'Waiting for processing' : 'Venter på behandling',
            className: 'bg-amber-100 text-amber-700 ring-amber-200',
        },
        processing: {
            label: english ? 'Processing' : 'Behandles',
            className: 'bg-violet-100 text-violet-700 ring-violet-200',
        },
        merging: {
            label: english ? 'Finalising requirements' : 'Ferdigstiller krav',
            className: 'bg-sky-100 text-sky-700 ring-sky-200',
        },
        completed: {
            label: english ? 'Completed' : 'Ferdig behandlet',
            className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        },
        failed: {
            label: english ? 'Failed' : 'Feilet',
            className: 'bg-rose-100 text-rose-700 ring-rose-200',
        },
    };

    return metaByStatus[String(status ?? '').trim()] ?? {
        label: english ? 'Unknown status' : 'Ukjent status',
        className: 'bg-slate-100 text-slate-700 ring-slate-200',
    };
}

function getRequirementStatusMeta(progress, locale, texts = {}) {
    const english = isEnglishLocale(locale);
    const status = String(progress?.status ?? '').trim();

    const metaByStatus = {
        not_started: {
            label: texts.requirementStatusNotStarted ?? (english ? 'Not started' : 'Ikke startet'),
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        },
        processing: {
            label: texts.requirementStatusProcessing ?? (english ? 'Processing requirements' : 'Behandler krav'),
            className: 'bg-violet-100 text-violet-700 ring-violet-200',
        },
        completed: {
            label: texts.requirementStatusCompleted ?? (english ? 'Requirements extracted' : 'Krav ferdig hentet ut'),
            className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        },
        failed: {
            label: texts.requirementStatusFailed ?? (english ? 'Requirement extraction failed' : 'Krav-ekstraksjon feilet'),
            className: 'bg-rose-100 text-rose-700 ring-rose-200',
        },
    };

    if (status === '') {
        return metaByStatus.not_started;
    }

    if (status === 'failed') {
        return metaByStatus.failed;
    }

    if (status === 'completed') {
        return metaByStatus.completed;
    }

    return metaByStatus.processing;
}

function getRequirementProgressText(progress, locale, texts = {}) {
    if (!progress || typeof progress !== 'object') {
        return '';
    }

    const completed = Number(progress?.completed_calls ?? 0);
    const total = Number(progress?.total_calls ?? 0);

    if (total <= 0) {
        return '';
    }

    const english = isEnglishLocale(locale);
    const template = texts.requirementProgressCount
        ?? (english ? ':completed of :total parts completed' : ':completed av :total deler ferdig');

    return formatLocalizedTemplate(template, {
        completed,
        total,
    });
}

function formatDocumentTimestamp(value, locale) {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

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

function normalizeAnswerDraftPayload(answerDraft) {
    return {
        text: normalizeAnswerDraftText(answerDraft?.text ?? ''),
        generated_at: answerDraft?.generated_at ?? null,
        knowledge_grounding: normalizeKnowledgeGroundingPayload(answerDraft?.knowledge_grounding ?? null),
        generation_state: typeof answerDraft?.generation_state === 'string' ? answerDraft.generation_state : null,
        missing_knowledge: normalizeMissingKnowledgePayload(answerDraft?.missing_knowledge ?? null),
        retrieval_sources: normalizeRetrievalSourcesPayload(answerDraft?.retrieval_sources ?? []),
    };
}

function normalizeRetrievalSourcesPayload(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    return value
        .map((source) => {
            if (!source || typeof source !== 'object') {
                return null;
            }

            const chunkId = Number(source?.chunk_id ?? source?.id ?? 0);
            const knowledgeItemId = Number(source?.knowledge_item_id ?? 0);
            const chunkIndex = Number(source?.chunk_index ?? 0);

            if (!Number.isInteger(chunkId) || chunkId <= 0) {
                return null;
            }

            return {
                id: chunkId,
                chunk_id: chunkId,
                knowledge_item_id: Number.isInteger(knowledgeItemId) && knowledgeItemId > 0
                    ? knowledgeItemId
                    : null,
                title: typeof source?.title === 'string' ? source.title.trim() : '',
                document_title: typeof source?.document_title === 'string' ? source.document_title.trim() : '',
                knowledge_item_title: typeof source?.knowledge_item_title === 'string' ? source.knowledge_item_title.trim() : '',
                content_type: typeof source?.content_type === 'string' ? source.content_type.trim() : '',
                image_url: typeof source?.image_url === 'string' ? source.image_url.trim() : '',
                image_src: typeof source?.image_src === 'string' ? source.image_src.trim() : '',
                image_path: typeof source?.image_path === 'string' ? source.image_path.trim() : '',
                image_alt_text: typeof source?.image_alt_text === 'string' ? source.image_alt_text.trim() : '',
                image_caption: typeof source?.image_caption === 'string' ? source.image_caption.trim() : '',
                knowledge_item_summary: typeof source?.knowledge_item_summary === 'string' ? source.knowledge_item_summary.trim() : '',
                chunk_index: Number.isInteger(chunkIndex) && chunkIndex >= 0 ? chunkIndex : 0,
                chunk_type: typeof source?.chunk_type === 'string' ? source.chunk_type.trim() : 'semantic',
                heading_path: typeof source?.heading_path === 'string' ? source.heading_path.trim() : '',
                summary_for_retrieval: typeof source?.summary_for_retrieval === 'string' ? source.summary_for_retrieval.trim() : '',
                table_text: typeof source?.table_text === 'string' ? source.table_text.trim() : '',
                table_html: typeof source?.table_html === 'string' ? source.table_html : '',
                table_json: source?.table_json && typeof source.table_json === 'object' ? source.table_json : null,
                topic: typeof source?.topic === 'string' ? source.topic.trim() : '',
                sub_topic: typeof source?.sub_topic === 'string' ? source.sub_topic.trim() : '',
                keywords: Array.isArray(source?.keywords)
                    ? source.keywords
                        .map((keyword) => String(keyword ?? '').replace(/\s+/g, ' ').trim())
                        .filter((keyword) => keyword !== '')
                    : [],
                section_title: typeof source?.section_title === 'string' ? source.section_title.trim() : '',
                section_path: typeof source?.section_path === 'string' ? source.section_path.trim() : '',
                content: typeof source?.content === 'string' ? source.content : '',
                content_preview: typeof source?.content_preview === 'string' ? source.content_preview : '',
            };
        })
        .filter(Boolean);
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

function EvidenceSourceModal({ evidence = null, onClose, aiText = {} }) {
    if (!evidence || typeof onClose !== 'function') {
        return null;
    }

    const source = evidence.source ?? null;
    const contentPreview = source?.content
        ?? source?.content_preview
        ?? evidence.evidence_quote
        ?? evidence.evidence_reference
        ?? aiText.evidence_no_excerpt
        ?? 'Ingen utdrag er tilgjengelig for dette beviset.';

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center overflow-hidden bg-slate-950/45 px-4 py-4"
            role="dialog"
            aria-modal="true"
            aria-label={aiText.evidence_modal_title}
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
                            {aiText.evidence_title}
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
                        aria-label={aiText.evidence_modal_close}
                    >
                        ×
                    </button>
                </div>

                <div className="grid gap-4 overflow-y-auto px-6 py-6 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start">
                    <div className="space-y-4 lg:max-h-[calc(90vh-12rem)] lg:overflow-y-auto lg:pr-1">
                        <section className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                            <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                {aiText.evidence_source_title}
                            </div>
                            <div className="mt-2 space-y-2 text-sm leading-6 text-slate-700">
                                <div>
                                    <span className="font-semibold text-slate-900">{aiText.evidence_document_label}</span>{' '}
                                    {source?.document_title ?? '—'}
                                </div>
                                <div>
                                    <span className="font-semibold text-slate-900">{aiText.evidence_section_label}</span>{' '}
                                    {source?.section_title ?? '—'}
                                </div>
                                <div>
                                    <span className="font-semibold text-slate-900">{aiText.evidence_section_path_label}</span>{' '}
                                    {source?.section_path ?? '—'}
                                </div>
                                <div>
                                    <span className="font-semibold text-slate-900">{aiText.evidence_chunk_label}</span>{' '}
                                    {source?.chunk_index ?? '—'}
                                </div>
                                <div>
                                    <span className="font-semibold text-slate-900">{aiText.evidence_document_id_label}</span>{' '}
                                    {source?.knowledge_item_id ?? '—'}
                                </div>
                                <div>
                                    <span className="font-semibold text-slate-900">{aiText.evidence_chunk_id_label}</span>{' '}
                                    {source?.knowledge_item_chunk_id ?? '—'}
                                </div>
                            </div>
                        </section>

                        <section className="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                            <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                {aiText.evidence_excerpt_title}
                            </div>
                            <div className="mt-3 whitespace-pre-wrap text-sm leading-7 text-slate-700">
                                {contentPreview}
                            </div>
                        </section>
                    </div>

                    <aside className="space-y-4 lg:max-h-[calc(90vh-12rem)] lg:overflow-y-auto lg:pr-1">
                        <section className="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                            <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                {aiText.evidence_line_title}
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
                            {aiText.evidence_close}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

function AiAccessNotice({ aiText = {}, billingHref = '' }) {
    return (
        <div className="rounded-[22px] border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-900">
            <div className="font-semibold">
                {aiText.ai_access_unavailable_title}
            </div>
            <p className="mt-1 text-amber-900/90">
                {aiText.ai_access_unavailable_message}
            </p>
            {billingHref ? (
                <a
                    href={billingHref}
                    className="mt-3 inline-flex items-center justify-center rounded-full border border-amber-300 bg-white px-4 py-2 text-sm font-semibold text-amber-900 transition hover:bg-amber-100"
                >
                    {aiText.ai_access_billing_cta}
                </a>
            ) : null}
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

function escapeClipboardHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function normalizeClipboardImageUrl(imageUrl) {
    const normalizedImageUrl = String(imageUrl ?? '').trim();

    if (normalizedImageUrl === '') {
        return '';
    }

    if (/^https?:\/\//i.test(normalizedImageUrl)) {
        return normalizedImageUrl;
    }

    if (typeof window === 'undefined' || !window.location?.origin) {
        return normalizedImageUrl;
    }

    try {
        return new URL(normalizedImageUrl, window.location.origin).href;
    } catch (error) {
        return normalizedImageUrl;
    }
}

function formatLocalizedTemplate(template, replacements = {}) {
    let value = String(template ?? '');

    Object.entries(replacements).forEach(([key, replacement]) => {
        value = value.split(`:${key}`).join(String(replacement ?? ''));
    });

    return value;
}

function formatClipboardInlineText(value) {
    return escapeClipboardHtml(value)
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/&lt;(\/?)strong&gt;/gi, '<$1strong>')
        .replace(/&lt;(\/?)b&gt;/gi, '<$1b>')
        .replace(/&lt;(\/?)em&gt;/gi, '<$1em>')
        .replace(/&lt;(\/?)i&gt;/gi, '<$1i>')
        .replace(/&lt;(\/?)u&gt;/gi, '<$1u>');
}

function markdownTableRowToClipboardCells(line) {
    const normalizedLine = String(line ?? '').trim();

    if (!normalizedLine.includes('|')) {
        return [];
    }

    const trimmedLine = normalizedLine.replace(/^\|/, '').replace(/\|$/, '');

    return trimmedLine
        .split('|')
        .map((cell) => cell.trim());
}

function isMarkdownTableSeparatorLine(line) {
    const cells = markdownTableRowToClipboardCells(line);

    return cells.length > 1 && cells.every((cell) => /^:?-{3,}:?$/.test(cell));
}

function isMarkdownTableDataLine(line) {
    const cells = markdownTableRowToClipboardCells(line);

    return cells.length > 1 && cells.some((cell) => cell !== '');
}

function markdownTableToClipboardHtml(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
        return '';
    }

    const headerCells = rows[0];
    const bodyRows = rows.slice(1);

    return `<table><thead><tr>${headerCells
        .map((cellText) => `<th>${formatClipboardInlineText(cellText)}</th>`)
        .join('')}</tr></thead><tbody>${bodyRows
        .map((cells) => `<tr>${cells
            .map((cellText) => `<td>${formatClipboardInlineText(cellText)}</td>`)
            .join('')}</tr>`)
        .join('')}</tbody></table>`;
}

function markdownTableToClipboardText(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
        return '';
    }

    return rows
        .map((cells) => cells.map((cell) => String(cell ?? '').trim()).join('\t'))
        .join('\n')
        .trim();
}

function answerDraftTextToClipboardPayload(answerDraftText) {
    const lines = normalizeAnswerDraftText(answerDraftText).split('\n');
    const htmlBlocks = [];
    const textBlocks = [];
    let paragraphLines = [];
    let activeListType = null;
    let activeListItems = [];

    const flushParagraph = () => {
        if (paragraphLines.length === 0) {
            return;
        }

        htmlBlocks.push(`<p>${paragraphLines.map((line) => formatClipboardInlineText(line)).join('<br />')}</p>`);
        textBlocks.push(paragraphLines.join('\n'));
        paragraphLines = [];
    };

    const flushList = () => {
        if (activeListType === null || activeListItems.length === 0) {
            activeListType = null;
            activeListItems = [];
            return;
        }

        htmlBlocks.push(`<${activeListType}>${activeListItems
            .map((itemText) => `<li>${formatClipboardInlineText(itemText)}</li>`)
            .join('')}</${activeListType}>`);
        textBlocks.push(activeListItems.map((itemText, index) => {
            if (activeListType === 'ol') {
                return `${index + 1}. ${itemText}`;
            }

            return `• ${itemText}`;
        }).join('\n'));
        activeListType = null;
        activeListItems = [];
    };

    let lineIndex = 0;

    while (lineIndex < lines.length) {
        const rawLine = lines[lineIndex] ?? '';
        const trimmedLine = rawLine.trim();

        if (trimmedLine === '') {
            flushParagraph();
            flushList();
            lineIndex += 1;
            continue;
        }

        if (
            isMarkdownTableDataLine(trimmedLine)
            && lineIndex + 1 < lines.length
            && isMarkdownTableSeparatorLine(lines[lineIndex + 1])
        ) {
            flushParagraph();
            flushList();

            const tableRows = [markdownTableRowToClipboardCells(trimmedLine)];
            lineIndex += 2;

            while (lineIndex < lines.length && isMarkdownTableDataLine(lines[lineIndex])) {
                tableRows.push(markdownTableRowToClipboardCells(lines[lineIndex]));
                lineIndex += 1;
            }

            const tableHtml = markdownTableToClipboardHtml(tableRows);
            const tableText = markdownTableToClipboardText(tableRows);

            if (tableHtml !== '') {
                htmlBlocks.push(tableHtml);
            }

            if (tableText !== '') {
                textBlocks.push(tableText);
            }

            continue;
        }

        const headingMatch = trimmedLine.match(/^(#{1,3})\s+(.+)$/);

        if (headingMatch) {
            flushParagraph();
            flushList();

            const headingLevel = Math.min(3, headingMatch[1].length);
            const headingText = headingMatch[2].trim();

            htmlBlocks.push(`<h${headingLevel}>${formatClipboardInlineText(headingText)}</h${headingLevel}>`);
            textBlocks.push(headingText);
            lineIndex += 1;
            continue;
        }

        const bulletMatch = trimmedLine.match(/^[-*•]\s+(.+)$/);

        if (bulletMatch) {
            flushParagraph();

            if (activeListType !== 'ul') {
                flushList();
                activeListType = 'ul';
            }

            activeListItems.push(bulletMatch[1].trim());
            lineIndex += 1;
            continue;
        }

        const numberedMatch = trimmedLine.match(/^\d+[.)]\s+(.+)$/);

        if (numberedMatch) {
            flushParagraph();

            if (activeListType !== 'ol') {
                flushList();
                activeListType = 'ol';
            }

            activeListItems.push(numberedMatch[1].trim());
            lineIndex += 1;
            continue;
        }

        flushList();
        paragraphLines.push(trimmedLine);
        lineIndex += 1;
    }

    flushParagraph();
    flushList();

    return {
        html: htmlBlocks.join('\n').trim(),
        text: textBlocks.join('\n\n').trim(),
    };
}

function tableJsonToClipboardRows(tableJson) {
    const rows = Array.isArray(tableJson?.rows) ? tableJson.rows : [];

    return rows
        .map((row) => {
            const cells = Array.isArray(row?.cells) ? row.cells : [];

            return cells
                .filter((cell) => String(cell?.source_metadata?.v_merge ?? '').trim() !== 'continue')
                .map((cell) => String(cell?.text ?? '').trim());
        })
        .filter((cells) => cells.some((cellText) => cellText !== ''));
}

function tableJsonToClipboardHtml(tableJson) {
    const rows = tableJsonToClipboardRows(tableJson);

    if (rows.length === 0) {
        return '';
    }

    return `<table><tbody>${rows
        .map((cells) => `<tr>${cells
            .map((cellText) => `<td>${formatClipboardInlineText(cellText)}</td>`)
            .join('')}</tr>`)
        .join('')}</tbody></table>`;
}

function tableJsonToClipboardText(tableJson) {
    return tableJsonToClipboardRows(tableJson)
        .map((cells) => cells.join('\t'))
        .join('\n')
        .trim();
}

function resolveClipboardImageUrl(source) {
    return normalizeClipboardImageUrl(
        source?.image_url
        || source?.image_src
        || source?.image_path
        || source?.url
        || '',
    );
}

function resolveClipboardSourceTitle(source, fallbackTitle) {
    return String(
        source?.title
        || source?.heading_path
        || source?.section_title
        || source?.document_title
        || source?.knowledge_item_title
        || fallbackTitle
        || '',
    ).replace(/\s+/g, ' ').trim();
}

function sourceTableToClipboardPayload(source, index) {
    const fallbackTitle = `Tabell ${index + 1}`;
    const sourceTitle = resolveClipboardSourceTitle(source, fallbackTitle);
    const tableHtml = String(source?.table_html ?? '').trim();
    const tableText = String(source?.table_text ?? '').trim();
    const tableJsonHtml = tableHtml === '' ? tableJsonToClipboardHtml(source?.table_json) : '';
    const tableJsonText = tableText === '' ? tableJsonToClipboardText(source?.table_json) : '';
    const htmlBlocks = [];
    const textBlocks = [];

    if (sourceTitle !== '') {
        htmlBlocks.push(`<p><strong>${formatClipboardInlineText(sourceTitle)}</strong></p>`);
        textBlocks.push(sourceTitle);
    }

    if (tableHtml !== '') {
        htmlBlocks.push(tableHtml);
    } else if (tableJsonHtml !== '') {
        htmlBlocks.push(tableJsonHtml);
    } else if (tableText !== '') {
        htmlBlocks.push(`<pre>${escapeClipboardHtml(tableText)}</pre>`);
    }

    if (tableText !== '') {
        textBlocks.push(tableText);
    } else if (tableJsonText !== '') {
        textBlocks.push(tableJsonText);
    }

    return {
        html: htmlBlocks.join('\n').trim(),
        text: textBlocks.join('\n').trim(),
    };
}

function sourceImageToClipboardPayload(source, index) {
    const imageUrl = resolveClipboardImageUrl(source);

    if (imageUrl === '') {
        return {
            html: '',
            text: '',
        };
    }

    const fallbackTitle = `Grafikk ${index + 1}`;
    const sourceTitle = resolveClipboardSourceTitle(source, fallbackTitle);
    const imageAltText = String(source?.image_alt_text ?? '').replace(/\s+/g, ' ').trim();
    const imageCaption = String(source?.image_caption ?? '').replace(/\s+/g, ' ').trim();
    const htmlBlocks = [];
    const textBlocks = [];

    if (sourceTitle !== '') {
        htmlBlocks.push(`<p><strong>${formatClipboardInlineText(sourceTitle)}</strong></p>`);
        textBlocks.push(sourceTitle);
    }

    htmlBlocks.push(`<p><img src="${escapeClipboardHtml(imageUrl)}" alt="${escapeClipboardHtml(imageAltText)}" style="max-width: 100%; height: auto;" /></p>`);
    textBlocks.push(imageUrl);

    if (imageCaption !== '') {
        htmlBlocks.push(`<p><em>${formatClipboardInlineText(imageCaption)}</em></p>`);
        textBlocks.push(imageCaption);
    }

    return {
        html: htmlBlocks.join('\n').trim(),
        text: textBlocks.join('\n').trim(),
    };
}

function copyHtmlSelectionToClipboard(html) {
    if (typeof document === 'undefined' || !document.body || typeof window === 'undefined') {
        return false;
    }

    const selection = window.getSelection?.();

    if (!selection) {
        return false;
    }

    const container = document.createElement('div');
    container.setAttribute('contenteditable', 'true');
    container.style.position = 'fixed';
    container.style.left = '-9999px';
    container.style.top = '0';
    container.style.width = '900px';
    container.style.height = 'auto';
    container.style.overflow = 'visible';
    container.style.background = '#ffffff';
    container.style.color = '#000000';
    container.style.padding = '16px';
    container.innerHTML = html;

    document.body.appendChild(container);

    const range = document.createRange();
    range.selectNodeContents(container);

    selection.removeAllRanges();
    selection.addRange(range);
    container.focus();

    let copied = false;

    try {
        copied = document.execCommand('copy');
    } catch (error) {
        copied = false;
    } finally {
        selection.removeAllRanges();
        document.body.removeChild(container);
    }

    return copied;
}

async function writeHtmlClipboardPayload(html, text) {
    if (html === '') {
        return false;
    }

    const fullHtml = `<!doctype html><html><head><meta charset="utf-8"></head><body>${html}</body></html>`;

    if (
        typeof window !== 'undefined'
        && typeof window.ClipboardItem !== 'undefined'
        && navigator.clipboard?.write
    ) {
        try {
            await navigator.clipboard.write([
                new window.ClipboardItem({
                    'text/html': new Blob([fullHtml], { type: 'text/html' }),
                    'text/plain': new Blob([text], { type: 'text/plain' }),
                }),
            ]);

            return true;
        } catch (error) {
        }
    }

    if (copyHtmlSelectionToClipboard(html)) {
        return true;
    }

    if (text !== '' && navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch (error) {
        }
    }

    return false;
}

async function imageSourceToPngClipboardBlob(source) {
    const imageUrl = resolveClipboardImageUrl(source);

    if (imageUrl === '') {
        return null;
    }

    const response = await fetch(imageUrl, { credentials: 'same-origin' });

    if (!response.ok) {
        return null;
    }

    const imageBlob = await response.blob();

    if (imageBlob.type === 'image/png') {
        return imageBlob;
    }

    return new Promise((resolve) => {
        const objectUrl = URL.createObjectURL(imageBlob);
        const image = new Image();

        image.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = image.naturalWidth || image.width;
            canvas.height = image.naturalHeight || image.height;

            const context = canvas.getContext('2d');

            if (!context) {
                URL.revokeObjectURL(objectUrl);
                resolve(null);
                return;
            }

            context.drawImage(image, 0, 0);
            canvas.toBlob((pngBlob) => {
                URL.revokeObjectURL(objectUrl);
                resolve(pngBlob);
            }, 'image/png');
        };

        image.onerror = () => {
            URL.revokeObjectURL(objectUrl);
            resolve(null);
        };

        image.src = objectUrl;
    });
}

async function writeSingleImageClipboardPayload(source) {
    if (
        typeof window === 'undefined'
        || typeof window.ClipboardItem === 'undefined'
        || !navigator.clipboard?.write
    ) {
        return false;
    }

    try {
        const pngBlob = await imageSourceToPngClipboardBlob(source);

        if (!pngBlob) {
            return false;
        }

        await navigator.clipboard.write([
            new window.ClipboardItem({
                'image/png': pngBlob,
            }),
        ]);

        return true;
    } catch (error) {
        return false;
    }
}

function buildAnswerDraftClipboardPayload(answerDraftText, retrievalSources) {
    const normalizedSources = Array.isArray(retrievalSources)
        ? retrievalSources.filter((source) => source && typeof source === 'object')
        : [];
    const tableSources = normalizedSources.filter((source) => String(source?.chunk_type ?? '').trim().toLowerCase() === 'table');
    const imageSources = normalizedSources.filter((source) => String(source?.chunk_type ?? '').trim().toLowerCase() === 'image');
    const answerPayload = answerDraftTextToClipboardPayload(answerDraftText);
    const htmlBlocks = [];
    const textBlocks = [];

    if (answerPayload.html !== '') {
        htmlBlocks.push(answerPayload.html);
    }

    if (answerPayload.text !== '') {
        textBlocks.push(answerPayload.text);
    }

    tableSources.forEach((source, index) => {
        const tablePayload = sourceTableToClipboardPayload(source, index);

        if (tablePayload.html !== '') {
            htmlBlocks.push(tablePayload.html);
        }

        if (tablePayload.text !== '') {
            textBlocks.push(tablePayload.text);
        }
    });

    imageSources.forEach((source, index) => {
        const imagePayload = sourceImageToClipboardPayload(source, index);

        if (imagePayload.html !== '') {
            htmlBlocks.push(imagePayload.html);
        }

        if (imagePayload.text !== '') {
            textBlocks.push(imagePayload.text);
        }
    });

    return {
        html: htmlBlocks.join('\n').trim(),
        text: textBlocks.join('\n\n').trim(),
    };
}

/**
 * Purpose: Render a structured table preview from the structured table model.
 * Inputs: A retrieval source table_json payload.
 * Returns: A React table preview or null when no structured rows are available.
 * Side effects: None.
 */
function StructuredTablePreview({ tableJson }) {
    const rows = Array.isArray(tableJson?.rows) ? tableJson.rows.filter((row) => row && typeof row === 'object') : [];

    if (rows.length === 0) {
        return null;
    }

    const columnCount = Math.max(1, Number(tableJson?.column_count ?? 0));
    const titleRowIndex = Number.isInteger(tableJson?.title_row_index) ? tableJson.title_row_index : null;
    const headerRowIndices = Array.isArray(tableJson?.header_row_indices)
        ? tableJson.header_row_indices
            .map((index) => Number(index))
            .filter((index) => Number.isInteger(index) && index >= 0)
        : [];
    const headerRowIndexSet = new Set(headerRowIndices);
    const titleRow = titleRowIndex !== null ? rows[titleRowIndex] ?? null : null;
    const bodyRows = rows.filter((_, index) => index !== titleRowIndex && !headerRowIndexSet.has(index));

    const renderRowCell = (cell, rowType, rowIndex, cellIndex, isHeaderRow) => {
        const cellText = String(cell?.text ?? '').trim();
        const colspan = Math.max(1, Number(cell?.colspan ?? 1));
        const rowspan = Math.max(1, Number(cell?.rowspan ?? 1));
        const isGroupLead = rowType === 'group' && cellIndex === 0;
        const CellTag = isHeaderRow || rowType === 'title' || isGroupLead ? 'th' : 'td';
        const cellProps = {
            key: `cell-${rowIndex}-${cellIndex}`,
            className: `border border-slate-200 px-3 py-2 align-top ${
                isHeaderRow || rowType === 'title' || rowType === 'group'
                    ? 'bg-slate-50 font-semibold text-slate-950'
                    : 'bg-white text-slate-700'
            }`,
        };

        if (colspan > 1) {
            cellProps.colSpan = colspan;
        }

        if (rowspan > 1) {
            cellProps.rowSpan = rowspan;
        }

        if (isHeaderRow || rowType === 'title') {
            cellProps.scope = 'col';
        } else if (isGroupLead) {
            cellProps.scope = 'row';
        }

        return (
            <CellTag {...cellProps}>
                {cellText !== '' ? cellText : '\u00A0'}
            </CellTag>
        );
    };

    const renderRow = (row, rowIndex, isHeaderRow = false) => {
        const rowCells = Array.isArray(row?.cells) ? row.cells.filter((cell) => cell && typeof cell === 'object') : [];
        const visibleCells = rowCells.filter((cell) => String(cell?.source_metadata?.v_merge ?? '').trim() !== 'continue');
        const rowType = String(row?.row_type ?? 'data');

        if (rowType === 'title') {
            const titleText = visibleCells
                .map((cell) => String(cell?.text ?? '').trim())
                .filter((text) => text !== '')
                .join(' ');

            return (
                <tr key={`row-${rowIndex}`}>
                    <th
                        colSpan={columnCount}
                        scope="colgroup"
                        className="border border-slate-200 bg-slate-50 px-3 py-2 text-left font-semibold text-slate-950"
                    >
                        {titleText !== '' ? titleText : '\u00A0'}
                    </th>
                </tr>
            );
        }

        if (visibleCells.length === 0) {
            return (
                <tr key={`row-${rowIndex}`}>
                    <td className="border border-slate-200 px-3 py-2 text-slate-700" colSpan={columnCount}>
                        &nbsp;
                    </td>
                </tr>
            );
        }

        return (
            <tr key={`row-${rowIndex}`}>
                {visibleCells.map((cell, cellIndex) => renderRowCell(cell, rowType, rowIndex, cellIndex, isHeaderRow))}
            </tr>
        );
    };

    return (
        <div className="overflow-x-auto rounded-[14px] border border-slate-200">
            <table className="min-w-full border-collapse text-sm">
                <tbody>
                    {titleRow ? renderRow(titleRow, titleRowIndex, false) : null}
                    {bodyRows.map((row, index) => {
                        const originalIndex = rows.indexOf(row);
                        const isHeaderRow = headerRowIndexSet.has(originalIndex);

                        return renderRow(row, originalIndex, isHeaderRow);
                    })}
                </tbody>
            </table>
        </div>
    );
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
        retrievalSources: normalizedDraft.retrieval_sources,
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

function getRequirementExtractionBannerData(documents) {
    if (!Array.isArray(documents) || documents.length === 0) {
        return null;
    }

    const activeStatuses = new Set([
        ...DOCUMENT_PROCESSING_ACTIVE_STATUSES,
        'text_extracted',
    ]);

    const activeDocuments = documents.filter((document) => activeStatuses.has(document?.processing_status));

    if (activeDocuments.length > 0) {
        return {
            state: 'processing',
            document: activeDocuments[0],
            count: activeDocuments.length,
            message: null,
        };
    }

    const failedDocuments = documents.filter((document) => document?.processing_status === 'failed');

    if (failedDocuments.length > 0) {
        return {
            state: 'failed',
            document: failedDocuments[0],
            count: failedDocuments.length,
            message: '',
        };
    }

    return null;
}

/**
 * Purpose: Render the AI case control surface for a single saved notice.
 * Inputs: pageTitle, case, and ai_status props from the AI controller.
 * Returns: A customer-app page component for the AI case view.
 * Side effects: None.
 */
export default function AiShow({
    pageTitle = 'I arbeid',
    saved_notice_show_url: savedNoticeShowUrl = '',
    case: caseData = null,
    ai_status: aiStatus = 'not_started',
    requirements_count: requirementsCount = 0,
    requirements = [],
    requirements_store_url: requirementsStoreUrl = '',
    requirements_destroy_all_url: requirementsDestroyAllUrl = '',
    assessment_refresh_url: assessmentRefreshUrl = '',
    evidence_refresh_url: evidenceRefreshUrl = '',
    documents = [],
    documents_upload_url: documentsUploadUrl = '',
    answer_basis_items: answerBasisItemsProp = [],
    export_docx_url: exportDocxUrl = '',
}) {
    const currentCaseId = caseData?.id ?? null;
    const {
        locale = 'nb-NO',
        assigned_user_options: assignedUserOptionsProp = [],
        assignable_users: assignableUsersProp = [],
        translations = {},
        auth = {},
        can_use_ai_offer: canUseAiOffer = true,
    } = usePage().props;
    const tai = translations?.ai ?? {};
    const isEnglish = isEnglishLocale(locale);
    const documentListTexts = {
        documentStatusLabel: tai.document_status_label ?? (isEnglish ? 'Document status' : 'Dokumentstatus'),
        requirementStatusLabel: tai.requirement_status_label ?? (isEnglish ? 'Requirement status' : 'Kravstatus'),
        requirementStatusNotStarted: tai.requirement_status_not_started ?? (isEnglish ? 'Not started' : 'Ikke startet'),
        requirementStatusProcessing: tai.requirement_status_processing ?? (isEnglish ? 'Processing requirements' : 'Behandler krav'),
        requirementStatusCompleted: tai.requirement_status_completed ?? (isEnglish ? 'Requirements extracted' : 'Krav ferdig hentet ut'),
        requirementStatusFailed: tai.requirement_status_failed ?? (isEnglish ? 'Requirement extraction failed' : 'Krav-ekstraksjon feilet'),
        requirementProgressCount: tai.requirement_progress_count ?? (isEnglish ? ':completed of :total parts completed' : ':completed av :total deler ferdig'),
    };
    const currentUser = auth.user ?? null;
    const billingHref = currentUser?.is_system_owner ? '/app/billing' : '';

    const AI_STATUS_META = {
        not_started: {
            label: tai.ai_status_not_started,
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        },
        ready: {
            label: tai.ai_status_ready,
            className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        },
        in_review: {
            label: tai.ai_status_in_review,
            className: 'bg-violet-100 text-violet-700 ring-violet-200',
        },
    };

    const DOCUMENT_STATUS_META = {
        uploaded: {
            label: tai.document_status_uploaded,
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        },
        text_extracted: {
            label: tai.document_status_text_extracted,
            className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        },
        queued: {
            label: tai.document_status_queued,
            className: 'bg-amber-100 text-amber-700 ring-amber-200',
        },
        processing: {
            label: tai.document_status_processing,
            className: 'bg-violet-100 text-violet-700 ring-violet-200',
        },
        merging: {
            label: tai.document_status_merging,
            className: 'bg-sky-100 text-sky-700 ring-sky-200',
        },
        completed: {
            label: tai.document_status_completed,
            className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        },
        failed: {
            label: tai.document_status_failed,
            className: 'bg-rose-100 text-rose-700 ring-rose-200',
        },
    };

    const REQUIREMENT_TYPE_META = {
        mandatory: {
            label: tai.requirement_type_mandatory,
            className: 'bg-rose-100 text-rose-700 ring-rose-200',
        },
        documentation: {
            label: tai.requirement_type_documentation,
            className: 'bg-sky-100 text-sky-700 ring-sky-200',
        },
        administrative: {
            label: tai.requirement_type_administrative,
            className: 'bg-amber-100 text-amber-700 ring-amber-200',
        },
        unspecified: {
            label: tai.requirement_type_unspecified,
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        },
    };

    const REQUIREMENT_SOURCE_TYPE_META = {
        ai_candidate: {
            label: tai.requirement_source_ai_candidate,
            className: 'bg-violet-100 text-violet-700 ring-violet-200',
        },
        manual: {
            label: tai.requirement_source_manual,
            className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        },
    };

    const REQUIREMENT_APPROVAL_STATUS_META = {
        draft: {
            label: tai.requirement_approval_draft,
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        },
        approved: {
            label: tai.requirement_approval_approved,
            className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        },
        rejected: {
            label: tai.requirement_approval_rejected,
            className: 'bg-rose-100 text-rose-700 ring-rose-200',
        },
    };

    const REQUIREMENT_APPROVAL_ACTIONS = {
        draft: [
            {
                label: tai.approval_action_approve,
                value: 'confirmed',
                className: 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-100',
            },
            {
                label: tai.approval_action_reject_delete,
                value: 'rejected',
                className: 'border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100',
            },
        ],
        approved: [
            {
                label: tai.approval_action_to_draft,
                value: 'pending',
                className: 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950',
            },
            {
                label: tai.approval_action_reject_delete,
                value: 'rejected',
                className: 'border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100',
            },
        ],
        rejected: [
            {
                label: tai.approval_action_restore,
                value: 'pending',
                className: 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950',
            },
        ],
    };

    const WORK_STATUS_META = {
        not_started: {
            label: tai.work_status_not_started,
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        },
        in_progress: {
            label: tai.work_status_in_progress,
            className: 'bg-amber-100 text-amber-700 ring-amber-200',
        },
        done: {
            label: tai.work_status_done,
            className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        },
    };

    const EVIDENCE_SELECTION_STATUS_META = {
        suggested: {
            label: tai.evidence_selection_suggested,
            className: 'bg-violet-100 text-violet-700 ring-violet-200',
        },
        selected: {
            label: tai.evidence_selection_selected,
            className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        },
        rejected: {
            label: tai.evidence_selection_rejected,
            className: 'bg-rose-100 text-rose-700 ring-rose-200',
        },
    };

    const EVIDENCE_SELECTION_ACTIONS = [
        {
            label: tai.evidence_selection_action_suggested,
            value: 'suggested',
            className: 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950',
        },
        {
            label: tai.evidence_selection_action_select,
            value: 'selected',
            className: 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-100',
        },
        {
            label: tai.evidence_selection_action_reject,
            value: 'rejected',
            className: 'border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100',
        },
    ];

    const ASSESSMENT_STATUS_META = {
        completed: {
            label: tai.assessment_status_completed,
            className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        },
        failed: {
            label: tai.assessment_status_failed,
            className: 'bg-rose-100 text-rose-700 ring-rose-200',
        },
    };

    const COVERAGE_STATUS_META = {
        covered: {
            label: tai.coverage_status_covered,
            className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        },
        partial: {
            label: tai.coverage_status_partial,
            className: 'bg-amber-100 text-amber-700 ring-amber-200',
        },
        missing: {
            label: tai.coverage_status_missing,
            className: 'bg-rose-100 text-rose-700 ring-rose-200',
        },
    };

    const RISK_LEVEL_META = {
        low: {
            label: tai.risk_level_low,
            className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        },
        medium: {
            label: tai.risk_level_medium,
            className: 'bg-amber-100 text-amber-700 ring-amber-200',
        },
        high: {
            label: tai.risk_level_high,
            className: 'bg-rose-100 text-rose-700 ring-rose-200',
        },
    };

    const KNOWLEDGE_GROUNDING_META = {
        green: {
            label: tai.knowledge_grounding_green,
            className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        },
        amber: {
            label: tai.knowledge_grounding_amber,
            className: 'bg-amber-100 text-amber-700 ring-amber-200',
        },
        red: {
            label: tai.knowledge_grounding_red,
            className: 'bg-rose-100 text-rose-700 ring-rose-200',
        },
    };

    const KNOWLEDGE_GROUNDING_JUDGE_META = {
        supported: {
            label: tai.knowledge_grounding_judge_supported,
            className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        },
        partial: {
            label: tai.knowledge_grounding_judge_partial,
            className: 'bg-amber-100 text-amber-700 ring-amber-200',
        },
        unsupported: {
            label: tai.knowledge_grounding_judge_unsupported,
            className: 'bg-rose-100 text-rose-700 ring-rose-200',
        },
        failed: {
            label: tai.knowledge_grounding_judge_failed,
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        },
    };

    const WORK_STATUS_OPTIONS = [
        { value: 'not_started', label: tai.work_status_not_started },
        { value: 'in_progress', label: tai.work_status_in_progress },
        { value: 'done', label: tai.work_status_done },
    ];
    const fileInputRef = useRef(null);
    const [reviewingRequirementId, setReviewingRequirementId] = useState(null);
    const [workingRequirementId, setWorkingRequirementId] = useState(null);
    const [refreshingAssessments, setRefreshingAssessments] = useState(false);
    const [refreshingEvidence, setRefreshingEvidence] = useState(false);
    const [deletingAllRequirements, setDeletingAllRequirements] = useState(false);
    const [updatingEvidenceId, setUpdatingEvidenceId] = useState(null);
    const [editingRequirementId, setEditingRequirementId] = useState(null);
    const [responsiblePickerRequirementId, setResponsiblePickerRequirementId] = useState(null);
    const [responsibleSavingRequirementId, setResponsibleSavingRequirementId] = useState(null);
    const [responsibleUpdateError, setResponsibleUpdateError] = useState(null);
    const [responsibleUserSearch, setResponsibleUserSearch] = useState('');
    const [selectedResponsibleUserFilter, setSelectedResponsibleUserFilter] = useState('all');
    const [expandedRequirementDetailsById, setExpandedRequirementDetailsById] = useState({});
    const initialRequirementRows = Array.isArray(requirements) ? requirements : [];
    const initialRequirementIdFromQuery = (() => {
        if (typeof window === 'undefined') {
            return null;
        }

        const searchParams = new URLSearchParams(window.location.search);
        const requirementId = searchParams.get('requirement_id');

        return requirementId !== null && requirementId.trim() !== ''
            ? requirementId.trim()
            : null;
    })();
    const initialRequirementFocusIdRef = useRef(initialRequirementIdFromQuery);
    const [requirementRows, setRequirementRows] = useState(initialRequirementRows);
    const [activeRequirementId, setActiveRequirementId] = useState(() => {
        if (initialRequirementIdFromQuery !== null) {
            return initialRequirementRows.some((requirement) => String(requirement.id) === initialRequirementIdFromQuery)
                ? initialRequirementIdFromQuery
                : null;
        }

        if (currentCaseId === null || currentCaseId === undefined) {
            return null;
        }

        const rememberedRequirementId = readRememberedAiRequirementId(currentCaseId);

        if (rememberedRequirementId === null) {
            return null;
        }

        return initialRequirementRows.some((requirement) => String(requirement.id) === rememberedRequirementId)
            ? rememberedRequirementId
            : null;
    });
    const [answerDraftsByRequirementId, setAnswerDraftsByRequirementId] = useState({});
    const [answerBasisSelectionsByRequirementId, setAnswerBasisSelectionsByRequirementId] = useState({});
    const [answerDraftGeneratingRequirementId, setAnswerDraftGeneratingRequirementId] = useState(null);
    const [answerDraftSavingRequirementId, setAnswerDraftSavingRequirementId] = useState(null);
    const [answerDraftCopyStatus, setAnswerDraftCopyStatus] = useState(null);
    const [answerDraftError, setAnswerDraftError] = useState(null);
    const [answerDraftReaderExpanded, setAnswerDraftReaderExpanded] = useState(false);
    const [answerDraftMissingKnowledgeDetailsExpanded, setAnswerDraftMissingKnowledgeDetailsExpanded] = useState(false);
    const [answerDraftPromptsByRequirementId, setAnswerDraftPromptsByRequirementId] = useState({});
    const [promptEditorOpenRequirementId, setPromptEditorOpenRequirementId] = useState(null);
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
    const assignableUsers = Array.isArray(assignableUsersProp) ? assignableUsersProp : [];
    const documentRows = Array.isArray(documents) ? documents : [];
    const answerBasisItems = Array.isArray(answerBasisItemsProp) ? answerBasisItemsProp : [];
    const requirementExtractionBanner = getRequirementExtractionBannerData(documentRows);
    const extractedRequirementRows = requirementRows.filter(
        (requirement) => String(requirement?.source_type ?? '').trim() !== 'manual',
    );
    const answerBasisItemsById = answerBasisItems.reduce((accumulator, item) => {
        accumulator[String(item.id)] = item;

        return accumulator;
    }, {});
    const documentNeedsRefresh = hasActiveDocumentProcessing(documentRows);
    const editingRequirement = editingRequirementId !== null
        ? requirementRows.find((requirement) => requirement.id === editingRequirementId) ?? null
        : null;
    const documentError = Object.values(documentUploadForm.errors).find(Boolean) ?? null;
    const manualRequirementError = Object.values(manualRequirementForm.errors).find(Boolean) ?? null;
    const requirementEditError = Object.values(requirementEditForm.errors).find(Boolean) ?? null;
    const selectedDocumentsLabel = documentUploadForm.data.documents.length > 0
        ? documentUploadForm.data.documents.map((document) => document.name).join(', ')
        : tai.no_files_selected;
    const requirementCountLabel = Number(requirementsCount ?? requirementRows.length);
    const activeRequirementExtractionDocumentTitle = requirementExtractionBanner?.document?.original_filename
        ?? requirementExtractionBanner?.document?.processing_status_label
        ?? null;
    const activeRequirementExtractionDocumentStatus = requirementExtractionBanner?.document?.processing_status ?? null;
    const activeRequirementExtractionStatusMeta = activeRequirementExtractionDocumentStatus !== null
        ? DOCUMENT_STATUS_META[activeRequirementExtractionDocumentStatus] ?? null
        : null;
    const requirementExtractionBannerTitle = requirementExtractionBanner?.state === 'failed'
        ? tai.requirement_extraction_failed_title
        : tai.requirement_extraction_background_title;
    const requirementExtractionProgress = requirementExtractionBanner?.document?.requirement_extraction_progress ?? null;
    const requirementExtractionBannerDescription = (() => {
        if (requirementExtractionBanner?.state === 'failed') {
            return tai.requirement_extraction_failed_description;
        }
        if (activeRequirementExtractionDocumentStatus === 'merging') {
            return tai.requirement_extraction_progress_merging ?? tai.requirement_extraction_background_description;
        }
        const totalCalls = Number(requirementExtractionProgress?.total_calls ?? 0);
        if (totalCalls > 0) {
            return formatLocalizedTemplate(tai.requirement_extraction_progress_processing ?? '', {
                completed: requirementExtractionProgress?.completed_calls ?? 0,
                total: totalCalls,
            });
        }
        return tai.requirement_extraction_progress_started ?? tai.requirement_extraction_background_description;
    })();
    const requirementExtractionCandidateText = (() => {
        if (requirementExtractionBanner === null || requirementExtractionBanner?.state === 'failed') {
            return null;
        }
        const count = Number(requirementExtractionProgress?.candidate_count ?? 0);
        return formatLocalizedTemplate(tai.requirement_extraction_progress_candidates ?? '', { count });
    })();
    const requirementUpdatesLocked = reviewingRequirementId !== null
        || workingRequirementId !== null
        || refreshingAssessments
        || refreshingEvidence
        || answerDraftGeneratingRequirementId !== null
        || answerDraftSavingRequirementId !== null
        || answerBasisSelectionSavingRequirementId !== null
        || deletingAllRequirements
        || updatingEvidenceId !== null
        || manualRequirementForm.processing
        || requirementEditForm.processing
        || editingRequirementId !== null;
    const updatedAtLabel = caseData?.updated_at
        ? new Intl.DateTimeFormat(locale, {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        }).format(new Date(caseData.updated_at))
        : '—';

    useEffect(() => {
        setRequirementRows(Array.isArray(requirements) ? requirements : []);
    }, [requirements]);

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
                        retrievalSources: currentDraftState.retrievalSources ?? serverDraftState.retrievalSources ?? [],
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
        const requirementIdToFocus = initialRequirementFocusIdRef.current;

        if (requirementIdToFocus === null || activeRequirementId === null) {
            return;
        }

        if (String(activeRequirementId) !== String(requirementIdToFocus)) {
            return;
        }

        const targetElement = document.getElementById(`ai-requirement-${activeRequirementId}`);

        if (!targetElement) {
            return;
        }

        targetElement.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
        });
        initialRequirementFocusIdRef.current = null;
    }, [activeRequirementId, requirementRows]);

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
    const activeRequirementMissingKnowledgeSummary = typeof activeRequirementMissingKnowledge?.missing_knowledge_summary === 'string'
        ? activeRequirementMissingKnowledge.missing_knowledge_summary.trim()
        : '';
    const activeRequirementMissingKnowledgeReasoning = typeof activeRequirementMissingKnowledge?.reasoning_summary === 'string'
        ? activeRequirementMissingKnowledge.reasoning_summary.trim()
        : '';
    const activeRequirementMissingKnowledgeExplanation = typeof activeRequirementMissingKnowledge?.message === 'string'
        ? activeRequirementMissingKnowledge.message.trim()
        : '';
    const activeRequirementMissingKnowledgeRecommendedDocumentTitle = typeof activeRequirementMissingKnowledge?.recommended_document_title === 'string'
        ? activeRequirementMissingKnowledge.recommended_document_title.trim()
        : '';
    const activeRequirementMissingKnowledgeSuggestedFilename = typeof activeRequirementMissingKnowledge?.suggested_filename === 'string'
        ? activeRequirementMissingKnowledge.suggested_filename.trim()
        : '';
    const activeRequirementMissingKnowledgeHasDetails = activeRequirementMissingKnowledge !== null
        && (
            activeRequirementMissingKnowledgeSummary !== ''
            || activeRequirementMissingKnowledgeReasoning !== ''
            || (activeRequirementMissingKnowledge?.directly_supported_points?.length ?? 0) > 0
            || (activeRequirementMissingKnowledge?.related_but_insufficient_points?.length ?? 0) > 0
            || (activeRequirementMissingKnowledge?.unsupported_points?.length ?? 0) > 0
        );
    const activeRequirementAnswerBasisItemIds = activeRequirementKey !== null
        ? answerBasisSelectionsByRequirementId[activeRequirementKey] ?? buildRequirementAnswerBasisSelectionState(activeRequirement)
        : [];
    const activeRequirementSelectedAnswerBasisItems = activeRequirementAnswerBasisItemIds
        .map((answerBasisItemId) => answerBasisItemsById[String(answerBasisItemId)])
        .filter(Boolean);
    const activeRequirementRetrievalSources = Array.isArray(activeRequirementDraft?.retrievalSources)
        ? activeRequirementDraft.retrievalSources
        : [];
    const activeRequirementTableRetrievalSources = activeRequirementRetrievalSources.filter((source) => String(source?.chunk_type ?? '').trim().toLowerCase() === 'table');
    const activeRequirementImageRetrievalSources = activeRequirementRetrievalSources.filter((source) => String(source?.chunk_type ?? '').trim().toLowerCase() === 'image');
    const responsiblePickerRequirement = responsiblePickerRequirementId !== null
        ? requirementRows.find((requirement) => String(requirement.id) === String(responsiblePickerRequirementId)) ?? null
        : null;
    const responsiblePickerAssignedUserId = responsiblePickerRequirement?.assigned_user_id !== null && responsiblePickerRequirement?.assigned_user_id !== undefined
        ? String(responsiblePickerRequirement.assigned_user_id)
        : (responsiblePickerRequirement?.assigned_user?.id !== null && responsiblePickerRequirement?.assigned_user?.id !== undefined
            ? String(responsiblePickerRequirement.assigned_user.id)
            : null);
    const responsiblePickerAssignedUser = responsiblePickerRequirement?.assigned_user ?? null;
    const responsiblePickerAssignedUserName = String(responsiblePickerAssignedUser?.name ?? '').trim();
    const responsiblePickerAssignedUserEmail = String(responsiblePickerAssignedUser?.email ?? '').trim();
    const responsiblePickerHasAssignedUser = responsiblePickerAssignedUserId !== null && responsiblePickerAssignedUserId !== '';
    const responsiblePickerSummary = responsiblePickerHasAssignedUser
        ? `${tai.current_responsible_user}: ${responsiblePickerAssignedUserName || '—'}`
        : `${tai.current_responsible_user}: ${tai.no_responsible_user_set}`;
    const normalizedResponsibleUserSearch = responsibleUserSearch.trim().toLowerCase();
    const filteredAssignableUsers = normalizedResponsibleUserSearch === ''
        ? assignableUsers
        : assignableUsers.filter((user) => {
            const userName = String(user?.name ?? '').toLowerCase();
            const userEmail = String(user?.email ?? '').toLowerCase();

            return userName.includes(normalizedResponsibleUserSearch) || userEmail.includes(normalizedResponsibleUserSearch);
        });
    const selectedResponsibleUserFilterValue = String(selectedResponsibleUserFilter ?? 'all');
    const visibleRequirementRows = requirementRows.filter((requirement) => {
        const requirementAssignedUserId = requirement.assigned_user_id !== null && requirement.assigned_user_id !== undefined && String(requirement.assigned_user_id).trim() !== ''
            ? String(requirement.assigned_user_id)
            : (requirement.assigned_user?.id !== null && requirement.assigned_user?.id !== undefined && String(requirement.assigned_user.id).trim() !== ''
                ? String(requirement.assigned_user.id)
                : null);

        if (selectedResponsibleUserFilterValue === 'all') {
            return true;
        }

        if (selectedResponsibleUserFilterValue === 'unassigned') {
            return requirementAssignedUserId === null;
        }

        return requirementAssignedUserId !== null
            && String(requirementAssignedUserId) === selectedResponsibleUserFilterValue;
    });

    useEffect(() => {
        setAnswerDraftCopyStatus(null);
    }, [activeRequirementKey]);

    useEffect(() => {
        setAnswerDraftReaderExpanded(false);
    }, [activeRequirementKey]);

    useEffect(() => {
        setAnswerDraftMissingKnowledgeDetailsExpanded(false);
    }, [activeRequirementKey]);

    useEffect(() => {
        setResponsibleUserSearch('');
    }, [responsiblePickerRequirementId]);

    useEffect(() => {
        setSelectedResponsibleUserFilter('all');
    }, [currentCaseId]);

    useEffect(() => {
        const requirementIdToFocus = initialRequirementFocusIdRef.current;

        if (requirementIdToFocus === null) {
            return;
        }

        const requirementExists = requirementRows.some((requirement) => String(requirement.id) === String(requirementIdToFocus));

        if (!requirementExists) {
            return;
        }

        if (String(activeRequirementId ?? '') !== String(requirementIdToFocus)) {
            setActiveRequirementId(String(requirementIdToFocus));
        }
    }, [activeRequirementId, requirementRows]);

    useEffect(() => {
        setExpandedRequirementDetailsById({});
    }, [currentCaseId]);

    const handleDocumentChange = (event) => {
        documentUploadForm.setData('documents', Array.from(event.target.files ?? []));
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

        if (!canUseAiOffer || !documentsUploadUrl || documentUploadForm.processing) {
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

    const openResponsiblePicker = (requirement) => {
        if (!requirement || requirementUpdatesLocked) {
            return;
        }

        setResponsibleUpdateError(null);
        setResponsibleUserSearch('');
        setResponsiblePickerRequirementId(String(requirement.id));
    };

    const closeResponsiblePicker = () => {
        setResponsiblePickerRequirementId(null);
        setResponsibleUpdateError(null);
        setResponsibleUserSearch('');
    };

    const updateRequirementAssignedUser = async (requirement, assignedUserId) => {
        if (
            !requirement
            || !requirement.assigned_user_update_url
            || requirementUpdatesLocked
            || responsibleSavingRequirementId !== null
        ) {
            return;
        }

        setResponsibleSavingRequirementId(requirement.id);
        setResponsibleUpdateError(null);

        try {
            const response = await window.axios.patch(requirement.assigned_user_update_url, {
                assigned_user_id: assignedUserId === '' ? null : assignedUserId,
            });
            const updatedRequirement = response?.data?.requirement ?? null;
            const requirementId = String(requirement.id);

            if (updatedRequirement !== null && typeof updatedRequirement === 'object') {
                setRequirementRows((currentRows) => currentRows.map((row) => (
                    String(row.id) === requirementId ? updatedRequirement : row
                )));
            } else {
                setRequirementRows((currentRows) => currentRows.map((row) => (
                    String(row.id) === requirementId
                        ? {
                            ...row,
                            assigned_user_id: response?.data?.assigned_user_id ?? null,
                            assigned_user: response?.data?.assigned_user ?? null,
                        }
                        : row
                )));
            }

            closeResponsiblePicker();
        } catch (error) {
            setResponsibleUpdateError(extractAxiosErrorMessage(error, tai.could_not_update_responsible_user));
        } finally {
            setResponsibleSavingRequirementId(null);
        }
    };

    const destroyAllExtractedRequirements = () => {
        if (
            !canUseAiOffer
            || !requirementsDestroyAllUrl
            || extractedRequirementRows.length === 0
            || requirementUpdatesLocked
            || deletingAllRequirements
        ) {
            return;
        }

        const confirmed = window.confirm(
            `${tai.delete_all_confirm_title}\n\n${tai.delete_all_confirm_message}`,
        );

        if (!confirmed) {
            return;
        }

        setDeletingAllRequirements(true);

        router.delete(requirementsDestroyAllUrl, {
            preserveScroll: true,
            preserveState: false,
            onFinish: () => {
                setDeletingAllRequirements(false);
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
        if (!canUseAiOffer) {
            setAnswerDraftError(tai.ai_access_unavailable_message);
            return;
        }

        if (!requirement || !requirement.answer_draft_generate_url || requirement.approval_status === 'rejected') {
            return;
        }

        const requirementKey = String(requirement.id);
        const selectedAnswerBasisItemIds = answerBasisSelectionsByRequirementId[requirementKey]
            ?? buildRequirementAnswerBasisSelectionState(requirement);
        const userAnswerPrompt = normalizeAnswerDraftText(answerDraftPromptsByRequirementId[requirementKey] ?? '');

        setActiveRequirementId(String(requirement.id));
        setAnswerDraftError(null);
        setAnswerBasisSelectionError(null);

        if (!requirement.answer_draft_generate_url) {
            setAnswerDraftError(tai.answer_draft_cannot_generate);
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
                user_answer_prompt: userAnswerPrompt,
            }, { timeout: 320000 });
            const answerDraft = normalizeAnswerDraftPayload(response?.data?.answer_draft ?? null);
            const normalizedText = normalizeAnswerDraftText(answerDraft.text);
            const knowledgeGrounding = normalizeKnowledgeGroundingPayload(response?.data?.knowledge_grounding ?? null);
            const responseAnswerBasisItemIds = normalizeAnswerBasisItemIds(
                response?.data?.answer_basis_item_ids ?? selectedAnswerBasisItemIds,
            );
            const retrievalSources = normalizeRetrievalSourcesPayload(response?.data?.retrieval_sources ?? []);

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
                    retrievalSources,
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
        if (!canUseAiOffer) {
            setAnswerDraftError(tai.ai_access_unavailable_message);
            return;
        }

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

    /**
     * Purpose: Toggle the per-requirement prompt editor from the requirement card.
     * Inputs: Requirement row selected by the user.
     * Returns: Nothing.
     * Side effects: Opens the answer workspace for the requirement and toggles local prompt editor state.
     */
    const toggleRequirementPromptEditor = (requirement) => {
        if (!requirement || requirement.approval_status === 'rejected') {
            return;
        }

        const requirementKey = String(requirement.id);

        if (requirement.answer_draft_generate_url) {
            setActiveRequirementId(requirementKey);
            setAnswerDraftError(null);
            setAnswerBasisSelectionError(null);
        }

        setPromptEditorOpenRequirementId((currentState) => (
            currentState === requirementKey ? null : requirementKey
        ));
    };

    /**
     * Purpose: Keep the per-requirement user prompt in local UI state before answer generation.
     * Inputs: Requirement row and raw prompt text from the requirement card prompt field.
     * Returns: Nothing.
     * Side effects: Updates local prompt state for the selected requirement only.
     */
    const updateRequirementUserPrompt = (requirement, text) => {
        if (!requirement) {
            return;
        }

        const requirementKey = String(requirement.id);

        setAnswerDraftPromptsByRequirementId((currentState) => ({
            ...currentState,
            [requirementKey]: text,
        }));
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

    const copyActiveAnswerDraftContent = async () => {
        if (activeRequirement === null || activeRequirementDraft === null) {
            return;
        }

        const payload = buildAnswerDraftClipboardPayload(activeRequirementDraft.text, activeRequirementRetrievalSources);

        if (payload.html === '' && payload.text === '') {
            setAnswerDraftCopyStatus('empty');
            return;
        }

        setAnswerDraftCopyStatus(null);

        try {
            if (payload.html !== '') {
                const copiedHtml = await writeHtmlClipboardPayload(payload.html, payload.text);

                if (!copiedHtml) {
                    throw new Error('Could not copy rich content.');
                }
            } else if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(payload.text);
            } else if (copyHtmlSelectionToClipboard(escapeClipboardHtml(payload.text).replace(/\n/g, '<br />'))) {
            } else {
                throw new Error('Clipboard API is not available.');
            }

            setAnswerDraftCopyStatus('copied');
        } catch (error) {
            setAnswerDraftCopyStatus('failed');
        }
    };

    const saveActiveAnswerDraft = async () => {
        if (activeRequirement === null || activeRequirementDraft === null) {
            return;
        }

        if (!activeRequirement.answer_draft_save_url) {
            setAnswerDraftError(tai.answer_draft_cannot_save);
            return;
        }

        if (answerDraftGeneratingRequirementId !== null || answerDraftSavingRequirementId !== null) {
            return;
        }

        const normalizedText = normalizeAnswerDraftText(activeRequirementDraft.text).trim();

        if (normalizedText === '') {
            setAnswerDraftError(tai.answer_draft_cannot_be_empty);
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
            const currentRetrievalSources = activeRequirementDraft?.retrievalSources ?? [];

            setAnswerDraftsByRequirementId((currentState) => ({
                ...currentState,
                [activeRequirementKey]: {
                    text: savedText,
                    persistedText: savedText,
                    generatedAt: answerDraft.generated_at,
                    knowledgeGrounding: currentKnowledgeGrounding,
                    generationState: answerDraft.generation_state ?? 'generated',
                    missingKnowledge: activeRequirementDraft?.missingKnowledge ?? null,
                    retrievalSources: currentRetrievalSources,
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
        if (!canUseAiOffer || !evidenceRefreshUrl || requirementUpdatesLocked) {
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
        if (!canUseAiOffer || !assessmentRefreshUrl || requirementUpdatesLocked) {
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

    return (
        <CustomerAppLayout title={pageTitle} showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-4">
                    <div className="space-y-3">
                        <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                            {tai.case_workspace_overline ?? 'Saksdokumenter og AI'}
                        </div>
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="space-y-3">
                                <div className="flex flex-wrap items-center gap-3">
                                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                                        {caseData?.title ?? tai.ai_case_title}
                                    </h1>
                                    <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${aiStatusMeta.className}`}>
                                        {aiStatusMeta.label}
                                    </span>
                                    <InfoHint size="sm" label="Vis forklaring for AI-status" text={tai.hint_ai_status} />
                                </div>
                                <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                                    {tai.case_workspace_intro ?? 'Arbeidsflaten gjelder dokumenter, krav og AI-arbeid for denne konkrete saken.'}
                                </p>
                            </div>
                            {savedNoticeShowUrl ? (
                                <Link
                                    href={savedNoticeShowUrl}
                                    className="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                >
                                    {tai.case_workspace_back_to_case ?? 'Tilbake til saken'}
                                </Link>
                            ) : null}
                        </div>
                        <div className="flex flex-wrap gap-2 text-sm text-slate-500">
                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1.5">
                                {tai.case_reference_prefix} {caseData?.reference ?? '—'}
                            </span>
                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1.5">
                                {tai.case_owner_prefix} {caseData?.owner ?? '—'}
                            </span>
                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1.5">
                                {tai.case_phase_prefix} {caseData?.stage ?? '—'}
                            </span>
                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1.5">
                                {tai.case_updated_prefix} {updatedAtLabel}
                            </span>
                        </div>

                        {exportDocxUrl ? (
                            <div>
                                <a
                                    href={exportDocxUrl}
                                    title={tai.export_word_title ?? 'Last ned alle svarutkast som Word-dokument'}
                                    className="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                >
                                    {tai.export_word ?? 'Last ned Word'}
                                </a>
                            </div>
                        ) : null}
                    </div>
                </section>

                {!canUseAiOffer ? (
                    <AiAccessNotice aiText={tai} billingHref={billingHref} />
                ) : null}

                {requirementExtractionBanner ? (
                    <section
                        role={requirementExtractionBanner.state === 'failed' ? 'alert' : 'status'}
                        aria-live={requirementExtractionBanner.state === 'failed' ? 'assertive' : 'polite'}
                        className={
                            requirementExtractionBanner.state === 'failed'
                                ? 'mb-5 rounded-[22px] border border-rose-200 bg-rose-50 p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]'
                                : 'mb-5 rounded-[22px] border border-violet-200 bg-violet-50 p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]'
                        }
                    >
                        <div className="flex items-start gap-4">
                            <div
                                className={
                                    requirementExtractionBanner.state === 'failed'
                                        ? 'mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-rose-200 bg-white text-rose-600'
                                        : 'mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-violet-200 bg-white text-violet-600'
                                }
                            >
                                {requirementExtractionBanner.state === 'failed' ? (
                                    <svg
                                        viewBox="0 0 20 20"
                                        fill="none"
                                        aria-hidden="true"
                                        className="h-5 w-5"
                                    >
                                        <path
                                            d="M10 6.5v4.25"
                                            stroke="currentColor"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth="1.8"
                                        />
                                        <path
                                            d="M10 13.75h.01"
                                            stroke="currentColor"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth="2.2"
                                        />
                                        <path
                                            d="M8.83 3.95a1.4 1.4 0 0 1 2.34 0l5.23 8.6a1.4 1.4 0 0 1-1.17 2.15H4.77a1.4 1.4 0 0 1-1.17-2.15l5.23-8.6Z"
                                            stroke="currentColor"
                                            strokeLinejoin="round"
                                            strokeWidth="1.5"
                                        />
                                    </svg>
                                ) : (
                                    <div className="h-5 w-5 animate-spin rounded-full border-2 border-violet-200 border-t-violet-600" />
                                )}
                            </div>

                            <div className="min-w-0 flex-1 space-y-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2 className="text-base font-semibold tracking-tight text-slate-950">
                                        {requirementExtractionBannerTitle}
                                    </h2>
                                    {activeRequirementExtractionStatusMeta ? (
                                        <span className={`inline-flex rounded-full px-3 py-1 text-[11px] font-semibold ring-1 ring-inset ${activeRequirementExtractionStatusMeta.className}`}>
                                            {activeRequirementExtractionStatusMeta.label}
                                        </span>
                                    ) : null}
                                </div>
                                <p className="text-sm leading-6 text-slate-600">
                                    {requirementExtractionBannerDescription}
                                </p>
                                {requirementExtractionCandidateText ? (
                                    <p className="text-sm font-medium text-slate-700">
                                        {requirementExtractionCandidateText}
                                    </p>
                                ) : null}
                                {activeRequirementExtractionDocumentTitle ? (
                                    <p className="text-xs font-medium uppercase tracking-[0.14em] text-slate-500">
                                        {tai.requirement_extraction_document_prefix} {activeRequirementExtractionDocumentTitle}
                                    </p>
                                ) : null}
                                {requirementExtractionBanner.state === 'failed' ? (
                                    <div className="rounded-2xl border border-rose-200 bg-white px-4 py-3 text-sm leading-6 text-rose-700">
                                        {requirementExtractionBanner.message !== ''
                                            ? requirementExtractionBanner.message
                                            : tai.requirement_extraction_failed_fallback}
                                    </div>
                                ) : null}
                            </div>
                        </div>
                    </section>
                ) : null}

                <section className="mb-5 rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="space-y-5">
                        <div className="space-y-2">
                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                {tai.documents_section_overline}
                            </div>
                            <h2 className="text-xl font-semibold tracking-tight text-slate-950">
                                {tai.documents_section_title}
                            </h2>
                            <p className="max-w-3xl text-sm leading-6 text-slate-500">
                                {tai.documents_section_description}
                            </p>
                            <p className="max-w-3xl text-xs leading-5 text-slate-400">
                                {tai.documents_section_dedup_notice ?? (isEnglish ? 'The list shows the latest upload per filename. Earlier uploads with the same filename are not shown in this overview.' : 'Listen viser nyeste opplasting per filnavn. Tidligere opplastinger med samme filnavn vises ikke i denne oversikten.')}
                            </p>
                        </div>

                        {canUseAiOffer ? (
                            <form onSubmit={submitDocuments} className="space-y-4">
                                <div className="space-y-2">
                                    <label htmlFor="ai-documents" className="text-sm font-medium text-slate-700">
                                        {tai.choose_files}
                                    </label>
                                    <div className="flex min-h-[56px] flex-wrap items-center gap-4 rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                                        <label
                                            htmlFor="ai-documents"
                                            className="inline-flex shrink-0 cursor-pointer items-center rounded-full bg-violet-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-violet-700"
                                        >
                                            {tai.choose_files}
                                        </label>
                                        <span className="min-w-0 flex-1 text-sm text-slate-500">
                                            {selectedDocumentsLabel}
                                        </span>
                                        <button
                                            type="submit"
                                            disabled={
                                                documentUploadForm.processing
                                                || !documentsUploadUrl
                                                || documentUploadForm.data.documents.length === 0
                                            }
                                            className="ml-auto inline-flex shrink-0 items-center justify-center rounded-2xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {documentUploadForm.processing ? tai.uploading : tai.upload_and_extract_requirements}
                                        </button>
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
                                        {tai.allowed_file_types}
                                    </p>
                                    {documentError ? (
                                        <p className="text-sm text-rose-600">{documentError}</p>
                                    ) : null}
                                </div>
                            </form>
                        ) : (
                            <div className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm leading-6 text-amber-900">
                                {tai.ai_access_unavailable_message}
                            </div>
                        )}

                        <div className="space-y-3">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h3 className="text-sm font-semibold tracking-tight text-slate-950">
                                    {isEnglish ? 'Uploaded case documents' : 'Opplastede saksdokumenter'}
                                </h3>
                                <span className="text-xs font-medium uppercase tracking-[0.14em] text-slate-400">
                                    {documentRows.length}
                                </span>
                            </div>

                            {documentRows.length > 0 ? (
                                <ul className="space-y-3">
                                    {documentRows.map((document) => {
                                        const documentStatusMeta = getDocumentStatusMeta(document?.processing_status, locale);
                                        const requirementStatusMeta = getRequirementStatusMeta(document?.requirement_extraction_progress, locale, documentListTexts);
                                        const uploadedAtLabel = formatDocumentTimestamp(document?.uploaded_at, locale);
                                        const requirementExtractionProgress = document?.requirement_extraction_progress ?? null;
                                        const progressLabel = getRequirementProgressText(requirementExtractionProgress, locale, documentListTexts);

                                        return (
                                            <li
                                                key={document.id}
                                                className="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-4"
                                            >
                                                <div className="flex flex-wrap items-start justify-between gap-4">
                                                    <div className="min-w-0 space-y-2">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="min-w-0 truncate text-sm font-semibold text-slate-950">
                                                                {document.original_filename ?? (isEnglish ? 'Untitled document' : 'Uten filnavn')}
                                                            </span>
                                                        </div>

                                                        <div className="grid gap-2 text-xs text-slate-500 sm:grid-cols-2">
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <span className="font-semibold uppercase tracking-[0.12em] text-slate-400">
                                                                    {documentListTexts.documentStatusLabel}
                                                                </span>
                                                                <span className={`inline-flex rounded-full px-3 py-1 text-[11px] font-semibold ring-1 ring-inset ${documentStatusMeta.className}`}>
                                                                    {documentStatusMeta.label}
                                                                </span>
                                                            </div>
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <span className="font-semibold uppercase tracking-[0.12em] text-slate-400">
                                                                    {documentListTexts.requirementStatusLabel}
                                                                </span>
                                                                <span className={`inline-flex rounded-full px-3 py-1 text-[11px] font-semibold ring-1 ring-inset ${requirementStatusMeta.className}`}>
                                                                    {requirementStatusMeta.label}
                                                                </span>
                                                            </div>
                                                        </div>

                                                        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                                                            {document.uploaded_by ? (
                                                                <span>
                                                                    {isEnglish ? 'Uploaded by' : 'Lastet opp av'} {document.uploaded_by}
                                                                </span>
                                                            ) : null}
                                                            {uploadedAtLabel ? (
                                                                <span>
                                                                    {isEnglish ? 'Uploaded' : 'Lastet opp'} {uploadedAtLabel}
                                                                </span>
                                                            ) : null}
                                                        </div>

                                                        {progressLabel ? (
                                                            <p className="text-xs leading-5 text-slate-500">
                                                                {progressLabel}
                                                            </p>
                                                        ) : null}
                                                    </div>

                                                    {document.preview_url ? (
                                                        <a
                                                            href={document.preview_url}
                                                            className="inline-flex shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-violet-200 hover:bg-violet-50 hover:text-violet-700"
                                                        >
                                                            {isEnglish ? 'Preview' : 'Forhåndsvis'}
                                                        </a>
                                                    ) : null}
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            ) : (
                                <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-6 py-8">
                                    <div className="text-base font-semibold text-slate-900">
                                        {isEnglish
                                            ? 'No case documents have been uploaded yet.'
                                            : 'Ingen saksdokumenter er lastet opp ennå.'}
                                    </div>
                                    <p className="mt-2 text-sm leading-6 text-slate-500">
                                        {isEnglish
                                            ? 'Upload tender documents, requirement documents or other files related to this specific case.'
                                            : 'Last opp konkurransegrunnlag, kravdokumenter eller andre dokumenter som gjelder denne konkrete saken.'}
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>
                </section>

                <div className="grid gap-5 lg:grid-cols-2 lg:items-stretch">
                    <section className="h-full rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] lg:flex lg:max-h-[calc(100vh-8rem)] lg:min-h-0 lg:flex-col lg:overflow-hidden">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="space-y-2">
                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                    {tai.requirements_section_overline}
                                </div>
                                <h2 className="text-xl font-semibold tracking-tight text-slate-950">
                                    {tai.requirements_section_title}
                                </h2>
                                <p className="max-w-3xl text-sm leading-6 text-slate-500">
                                    {tai.requirements_section_description}
                                </p>
                                <span className="inline-flex rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                    {requirementCountLabel} {tai.requirements_total_suffix}
                                </span>
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={() => setShowManualRequirementForm((value) => !value)}
                                    disabled={requirementUpdatesLocked && !showManualRequirementForm}
                                    aria-expanded={showManualRequirementForm}
                                    className="inline-flex items-center justify-center rounded-full border border-violet-200 bg-violet-50 px-4 py-2 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {showManualRequirementForm ? tai.hide_requirement_form : tai.show_requirement_form}
                                </button>

                                <button
                                    type="button"
                                    onClick={() => setShowAdvancedAI((value) => !value)}
                                    className="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                                >
                                    {showAdvancedAI ? tai.hide_advanced : tai.advanced}
                                </button>

                                {canUseAiOffer && showAdvancedAI ? (
                                    <>
                                        <button
                                            type="button"
                                            onClick={refreshEvidence}
                                            disabled={!evidenceRefreshUrl || requirementUpdatesLocked}
                                            className="inline-flex items-center justify-center rounded-full border border-violet-200 bg-violet-50 px-4 py-2 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {refreshingEvidence ? tai.refreshing : tai.refresh_sources}
                                        </button>

                                        <button
                                            type="button"
                                            onClick={refreshAssessments}
                                            disabled={!assessmentRefreshUrl || requirementUpdatesLocked}
                                            className="inline-flex items-center justify-center rounded-full border border-slate-200 bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {refreshingAssessments ? tai.analyzing : tai.analyze_requirements}
                                        </button>
                                    </>
                                ) : null}

                                {canUseAiOffer && extractedRequirementRows.length > 0 && requirementsDestroyAllUrl ? (
                                    <button
                                        type="button"
                                        onClick={destroyAllExtractedRequirements}
                                        disabled={requirementUpdatesLocked || deletingAllRequirements}
                                        className="inline-flex items-center justify-center rounded-full border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {deletingAllRequirements ? tai.deleting : tai.delete_all_requirements}
                                    </button>
                                ) : null}

                            </div>
                        </div>

                        <div className="mt-4 flex flex-wrap items-end justify-between gap-3 rounded-[18px] border border-slate-200 bg-slate-50/70 px-4 py-3">
                            <label className="block min-w-[16rem] flex-1 space-y-1">
                                <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                    {tai.responsible}
                                </span>
                                <select
                                    value={selectedResponsibleUserFilterValue}
                                    onChange={(event) => setSelectedResponsibleUserFilter(event.target.value)}
                                    className="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-violet-400 focus:ring-4 focus:ring-violet-100"
                                >
                                    <option value="all">{tai.all_requirements}</option>
                                    <option value="unassigned">{tai.unassigned_requirements}</option>
                                    {assignableUsers.map((user) => (
                                        <option key={user.id} value={String(user.id)}>
                                            {user.name}{user.email ? ` · ${user.email}` : ''}
                                        </option>
                                    ))}
                                </select>
                            </label>

                            <div className="text-sm font-medium text-slate-500">
                                {formatLocalizedTemplate(tai.showing_requirements, {
                                    visible: visibleRequirementRows.length,
                                    total: requirementRows.length,
                                })}
                            </div>
                        </div>

                        {showManualRequirementForm ? (
                            <form onSubmit={submitManualRequirement} className="mt-5 space-y-4 rounded-[22px] border border-violet-200 bg-violet-50/40 p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="space-y-1">
                                    <div className="text-xs font-medium uppercase tracking-[0.16em] text-violet-600">
                                        {tai.manual_requirement_overline}
                                    </div>
                                    <h3 className="text-sm font-semibold tracking-tight text-slate-950">
                                        {tai.manual_requirement_title}
                                    </h3>
                                    <p className="text-xs leading-5 text-slate-500">
                                        {tai.manual_requirement_description}
                                    </p>
                                </div>
                                <button
                                    type="submit"
                                    disabled={manualRequirementForm.processing || !requirementsStoreUrl || requirementUpdatesLocked}
                                    className="inline-flex items-center justify-center rounded-full bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {manualRequirementForm.processing ? tai.saving : tai.manual_requirement_submit}
                                </button>
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                <label className="block space-y-1">
                                    <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                        {tai.requirement_id_label}
                                    </span>
                                    <input
                                        type="text"
                                        value={manualRequirementForm.data.requirement_identifier}
                                        onChange={(event) => manualRequirementForm.setData('requirement_identifier', event.target.value)}
                                        disabled={manualRequirementForm.processing || requirementUpdatesLocked}
                                        className="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-violet-400 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                        placeholder={tai.requirement_id_placeholder}
                                    />
                                    {manualRequirementForm.errors.requirement_identifier ? (
                                        <p className="text-sm text-rose-600">{manualRequirementForm.errors.requirement_identifier}</p>
                                    ) : null}
                                </label>

                                <label className="block space-y-1">
                                    <span className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                        {tai.requirement_type_label}
                                        <InfoHint size="sm" label="Vis forklaring for Kravtype" text={tai.hint_requirement_type} />
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
                                    {tai.requirement_text_label}
                                </span>
                                <textarea
                                    value={manualRequirementForm.data.requirement_text}
                                    onChange={(event) => manualRequirementForm.setData('requirement_text', event.target.value)}
                                    rows={4}
                                    disabled={manualRequirementForm.processing || requirementUpdatesLocked}
                                    className="w-full rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-violet-400 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                    placeholder={tai.requirement_text_placeholder}
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
                                    {tai.requirements_empty_title}
                                </div>
                                <p className="mt-2 text-sm text-slate-500">
                                    {tai.requirements_empty_hint}
                                </p>
                            </div>
                        ) : visibleRequirementRows.length === 0 ? (
                            <div className="mt-5 rounded-[22px] border border-dashed border-slate-300 bg-slate-50 px-6 py-10">
                                <div className="text-lg font-semibold text-slate-900">
                                    {tai.no_requirements_for_selected_responsible}
                                </div>
                            </div>
                        ) : (
                            <div className="mt-5 max-h-[38rem] space-y-4 overflow-y-auto pr-2 lg:min-h-0 lg:max-h-none lg:flex-1">
                                {visibleRequirementRows.map((requirement) => {
                                    const sourceTypeMeta = REQUIREMENT_SOURCE_TYPE_META[requirement.source_type] ?? REQUIREMENT_SOURCE_TYPE_META.ai_candidate;
                                    const approvalStatus = requirement.approval_status ?? 'draft';
                                    const approvalActions = REQUIREMENT_APPROVAL_ACTIONS[approvalStatus] ?? REQUIREMENT_APPROVAL_ACTIONS.draft;
                                    const workStatus = requirement.work_status ?? 'not_started';
                                    const workStatusMeta = WORK_STATUS_META[workStatus] ?? WORK_STATUS_META.not_started;
                                    const assignedUserId = requirement.assigned_user_id !== null && requirement.assigned_user_id !== undefined
                                        ? String(requirement.assigned_user_id)
                                        : (requirement.assigned_user?.id !== null && requirement.assigned_user?.id !== undefined
                                            ? String(requirement.assigned_user.id)
                                            : '');
                                    const assignedUserName = String(requirement.assigned_user?.name ?? '').trim();
                                    const assignedUserEmail = String(requirement.assigned_user?.email ?? '').trim();
                                    const hasAssignedUser = assignedUserId !== '';
                                    const responsibleButtonTitle = hasAssignedUser
                                        ? formatLocalizedTemplate(tai.responsible_with_contact, {
                                            name: assignedUserName || '—',
                                            email: assignedUserEmail || '—',
                                        })
                                        : tai.no_responsible_user_set;
                                    const responsibleButtonAriaLabel = hasAssignedUser
                                        ? formatLocalizedTemplate(tai.change_responsible_user_current, {
                                            name: assignedUserName || '—',
                                        })
                                        : tai.set_responsible;
                                    const assignedUserLabel = hasAssignedUser
                                        ? (assignedUserName || '—')
                                        : tai.no_responsible_user;
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
                                    const canOpenAnswerWorkspace = canUseAiOffer
                                        && Boolean(requirement.answer_draft_generate_url)
                                        && approvalStatus !== 'rejected';
                                    const requirementDraftState = answerDraftsByRequirementId[String(requirement.id)] ?? buildRequirementAnswerDraftState(requirement);
                                    const hasExistingAnswerDraft = (
                                        requirementDraftState.generatedAt !== null
                                        || normalizeAnswerDraftText(requirementDraftState.text).trim() !== ''
                                    );
                                    const requirementKey = String(requirement.id);
                                    const requirementUserPrompt = answerDraftPromptsByRequirementId[requirementKey] ?? '';
                                    const requirementPromptEditorOpen = promptEditorOpenRequirementId === requirementKey;
                                    const requirementHasUserPrompt = normalizeAnswerDraftText(requirementUserPrompt).trim() !== '';
                                    const requirementDetailsExpanded = Boolean(expandedRequirementDetailsById[requirementKey]);

                                    return (
                                        <article
                                            key={requirement.id}
                                            id={`ai-requirement-${requirement.id}`}
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
                                            } scroll-mt-24 ${
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
                                                                {isApprovedRequirement ? tai.workstream_approved : tai.workstream_pending}
                                                            </span>
                                                            <span>
                                                                {tai.revisions_prefix} {revisionCount}
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div className="flex flex-wrap gap-2">
                                                        {canOpenAnswerWorkspace ? (
                                                            <>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        toggleRequirementPromptEditor(requirement);
                                                                    }}
                                                                    disabled={requirementUpdatesLocked}
                                                                    aria-expanded={requirementPromptEditorOpen}
                                                                    aria-controls={`requirement-prompt-${requirement.id}`}
                                                                    title={tai.open_prompt_for_requirement}
                                                                    className={`inline-flex rounded-full border px-3 py-1 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 ${
                                                                        requirementPromptEditorOpen || requirementHasUserPrompt
                                                                            ? 'border-violet-300 bg-violet-50 text-violet-700'
                                                                            : 'border-slate-200 bg-white text-slate-600 hover:border-violet-200 hover:bg-violet-50 hover:text-violet-700'
                                                                    }`}
                                                                >
                                                                    {tai.prompt_button}
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        void requestAnswerDraftGeneration(requirement);
                                                                    }}
                                                                    disabled={requirementUpdatesLocked}
                                                                    aria-pressed={isActiveRequirement}
                                                                    title={tai.generate_draft_for_requirement}
                                                                    className="inline-flex rounded-full bg-violet-600 px-3 py-1 text-xs font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                                >
                                                                    {tai.create_answer}
                                                                </button>
                                                            </>
                                                        ) : (
                                                            <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${sourceTypeMeta.className}`}>
                                                                {requirement.source_type_label ?? sourceTypeMeta.label}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>

                                                <div className="flex flex-wrap gap-2">
                                                    {isApprovedRequirement ? (
                                                        <>
                                                            <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${workStatusMeta.className}`}>
                                                                {requirement.work_status_label ?? workStatusMeta.label}
                                                            </span>
                                                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-600">
                                                                {tai.assigned_prefix} {assignedUserLabel}
                                                            </span>
                                                        </>
                                                    ) : null}
                                                </div>

                                                <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                                    {(() => {
                                                        const sourceDocument = resolveRequirementSourceDocument(requirement);
                                                        const sourceDocumentUrl = requirement?.source_document_preview_url ?? sourceDocument?.preview_url ?? null;
                                                        const sourceDocumentLabel = sourceDocument?.original_filename ?? requirement.document_filename ?? '—';
                                                        const canPreviewSourceDocument = Boolean(sourceDocumentUrl);

                                                        if (canPreviewSourceDocument) {
                                                            return (
                                                                <a
                                                                    href={sourceDocumentUrl}
                                                                    className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1 font-medium text-slate-600 transition hover:border-violet-200 hover:bg-violet-50 hover:text-violet-700"
                                                                >
                                                                    {tai.preview_source_prefix} {sourceDocumentLabel}
                                                                </a>
                                                            );
                                                        }

                                                        return (
                                                            <button
                                                                type="button"
                                                                disabled
                                                                className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1 font-medium text-slate-400 opacity-60"
                                                            >
                                                                {tai.preview_source_prefix} {sourceDocumentLabel}
                                                            </button>
                                                        );
                                                    })()}
                                                </div>

                                                {requirementPromptEditorOpen ? (
                                                    <div className="rounded-2xl border border-violet-200 bg-violet-50/50 px-4 py-4">
                                                        <label
                                                            className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-violet-700"
                                                            htmlFor={`requirement-prompt-${requirement.id}`}
                                                        >
                                                            {tai.requirement_prompt_title}
                                                        </label>
                                                        <p className="mt-1 text-sm leading-6 text-slate-600">
                                                            {tai.prompt_instruction_hint}
                                                        </p>
                                                        <textarea
                                                            id={`requirement-prompt-${requirement.id}`}
                                                            value={requirementUserPrompt}
                                                            onChange={(event) => updateRequirementUserPrompt(requirement, event.target.value)}
                                                            maxLength={5000}
                                                            rows={4}
                                                            disabled={answerDraftGeneratingRequirementId === requirement.id}
                                                            className="mt-3 w-full resize-y rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm leading-6 text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-violet-400 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                            placeholder={tai.example_prompt_placeholder}
                                                        />
                                                        <div className="mt-3 flex justify-end">
                                                            <button
                                                                type="button"
                                                                onClick={() => { void requestAnswerDraftGeneration(requirement); }}
                                                                disabled={requirementUpdatesLocked}
                                                                className="inline-flex rounded-full bg-violet-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                            >
                                                                {tai.create_answer}
                                                            </button>
                                                        </div>
                                                    </div>
                                                ) : null}

                                                {activeRequirement ? (
                                                    showAdvancedAI ? (
                                                        <div className="space-y-4 border-t border-slate-200/80 pt-4">
                                                            <div className="flex flex-wrap items-center justify-between gap-3">
                                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                    {tai.sources_section_title}
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
                                                                    {answerDraftGeneratingRequirementId === activeRequirement.id ? tai.generating : tai.regenerate}
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
                                                                        {tai.selected_sources}
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
                                                                                            {tai.remove_button}
                                                                                        </button>
                                                                                    </div>
                                                                                </div>
                                                                            );
                                                                        })}
                                                                    </div>
                                                                </div>
                                                            ) : (
                                                                <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">
                                                                    {tai.no_selected_sources}
                                                                </div>
                                                            )}

                                                            <div className="space-y-2">
                                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                    {tai.choose_sources}
                                                                </div>

                                                                {answerBasisItems.length === 0 ? (
                                                                    <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">
                                                                        {tai.no_available_sources}
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
                                                                                            {isSelected ? tai.remove_button : tai.add_button}
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
                                                        null
                                                    )
                                                ) : null}

                                                {hasOriginalDifference ? (
                                                    <div className="rounded-2xl border border-violet-200 bg-violet-50/50 px-4 py-3">
                                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-violet-700">
                                                            {tai.original_proposal}
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
                                                                    {tai.requirement_id_label}
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
                                                                <span className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                    {tai.requirement_type_label}
                                                                    <InfoHint size="sm" label="Vis forklaring for Kravtype" text={tai.hint_requirement_type} />
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
                                                                {tai.requirement_text_label}
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
                                                                {requirementEditForm.processing ? tai.saving : tai.save_requirement_changes}
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={cancelEditingRequirement}
                                                                disabled={requirementEditForm.processing}
                                                                className="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                                            >
                                                                {tai.cancel}
                                                            </button>
                                                        </div>
                                                    </form>
                                                ) : null}

                                                {showAdvancedAI ? (
                                                    <div className="space-y-3 border-t border-slate-200/80 pt-4">
                                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                {tai.assessment_overline}
                                                            </div>
                                                            {hasAssessment && assessmentCompleted ? (
                                                                <span className="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-emerald-700">
                                                                    {tai.review_completed_at_prefix} {assessmentDateLabel}
                                                                </span>
                                                            ) : hasAssessment && assessmentFailed ? (
                                                                <span className="inline-flex rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-rose-700">
                                                                    {tai.assessment_failed_label}
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
                                                                                <span className="inline-flex items-center gap-1">
                                                                                    <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${
                                                                                        COVERAGE_STATUS_META[assessment.coverage_status]?.className
                                                                                            ?? COVERAGE_STATUS_META.missing.className
                                                                                    }`}>
                                                                                        {assessment.coverage_status_label ?? COVERAGE_STATUS_META.missing.label}
                                                                                    </span>
                                                                                    <InfoHint size="sm" label="Vis forklaring for Dekningsstatus" text={tai.hint_coverage_status} />
                                                                                </span>
                                                                            ) : null}
                                                                            {assessment.risk_level ? (
                                                                                <span className="inline-flex items-center gap-1">
                                                                                    <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${
                                                                                        RISK_LEVEL_META[assessment.risk_level]?.className
                                                                                            ?? RISK_LEVEL_META.high.className
                                                                                    }`}>
                                                                                        {assessment.risk_level_label ?? RISK_LEVEL_META.high.label}
                                                                                    </span>
                                                                                    <InfoHint size="sm" label="Vis forklaring for Risikonivå" text={tai.hint_risk_level} />
                                                                                </span>
                                                                            ) : null}
                                                                        </div>

                                                                        <div className="grid gap-3 md:grid-cols-2">
                                                                            <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                                    {tai.assessment_summary_label}
                                                                                </div>
                                                                                <p className="mt-2 text-sm leading-6 text-slate-700">
                                                                                    {assessment.requirement_summary ?? '—'}
                                                                                </p>
                                                                            </div>

                                                                            <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                                    {tai.assessment_reasoning_label}
                                                                                </div>
                                                                                <p className="mt-2 text-sm leading-6 text-slate-700">
                                                                                    {assessment.coverage_rationale ?? '—'}
                                                                                </p>
                                                                            </div>

                                                                            <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                                    {tai.assessment_missing_basis_label}
                                                                                </div>
                                                                                <p className="mt-2 text-sm leading-6 text-slate-700">
                                                                                    {assessment.missing_information ?? '—'}
                                                                                </p>
                                                                            </div>

                                                                            <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                                    {tai.assessment_next_step_label}
                                                                                </div>
                                                                                <p className="mt-2 text-sm leading-6 text-slate-700">
                                                                                    {assessment.recommended_next_step ?? '—'}
                                                                                </p>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                ) : (
                                                                    <div className="rounded-2xl border border-rose-200 bg-rose-50/40 px-4 py-3 text-sm leading-6 text-rose-700">
                                                                        {tai.assessment_failed_message}
                                                                    </div>
                                                                )
                                                            ) : (
                                                                <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">
                                                                    {tai.assessment_not_generated_message}
                                                                </div>
                                                            )
                                                        ) : (
                                                            <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">
                                                                {tai.assessment_visible_when_approved}
                                                            </div>
                                                        )}
                                                    </div>
                                                ) : null}

                                                {showEvidenceSection ? (
                                                    <div className="space-y-3 border-t border-slate-200/80 pt-4">
                                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                {tai.sources_section_title}
                                                            </div>
                                                            {isApprovedRequirement ? (
                                                                <span className="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-emerald-700">
                                                                    {tai.persisted_badge}
                                                                </span>
                                                            ) : null}
                                                        </div>

                                                        {evidenceRows.length > 0 ? (
                                                            <div className="space-y-2">
                                                                {evidenceRows.map((evidence) => {
                                                                    const evidenceStatusMeta = EVIDENCE_SELECTION_STATUS_META[evidence.selection_status] ?? EVIDENCE_SELECTION_STATUS_META.suggested;
                                                                    const evidenceChunkLabel = typeof evidence.knowledge_chunk?.chunk_index === 'number'
                                                                        ? `${tai.evidence_text_chunk_label} ${Number(evidence.knowledge_chunk.chunk_index) + 1}`
                                                                        : `${tai.evidence_text_chunk_label} —`;
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
                                                                                    {evidence.knowledge_item?.original_filename ?? tai.unknown_document_title}
                                                                                </div>
                                                                                <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                                    {evidence.knowledge_item?.document_type_label ?? evidence.knowledge_item?.document_type ?? '—'}
                                                                                </span>
                                                                                <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                                    {evidence.match_type_label ?? evidence.match_type}
                                                                                </span>
                                                                                {evidence.is_primary ? (
                                                                                    <span className="inline-flex rounded-full border border-violet-200 bg-violet-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-violet-700">
                                                                                        {tai.evidence_primary_label}
                                                                                    </span>
                                                                                ) : null}
                                                                            </div>

                                                                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                                                                <span className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] ring-1 ring-inset ${evidenceStatusMeta.className}`}>
                                                                                    {evidence.selection_status_label ?? evidenceStatusMeta.label}
                                                                                </span>
                                                                                <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                                    {tai.evidence_rank_label} {Number(evidence.match_rank ?? 0)}
                                                                                </span>
                                                                                <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                                    {tai.evidence_score_label} {Number(evidence.match_score ?? 0)}
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
                                                                {tai.no_sources_yet}
                                                            </p>
                                                        )}
                                                    </div>
                                                ) : null}

                                                <div className="flex flex-wrap gap-2 border-t border-slate-200/80 pt-4">
                                                    {isApprovedRequirement ? (
                                                        <div className="flex w-full flex-wrap justify-end gap-2">
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    setExpandedRequirementDetailsById((currentState) => ({
                                                                        ...currentState,
                                                                        [requirementKey]: !Boolean(currentState[requirementKey]),
                                                                    }));
                                                                }}
                                                                aria-expanded={requirementDetailsExpanded}
                                                                className="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                            >
                                                                {requirementDetailsExpanded ? tai.requirement_details_less : tai.requirement_details_more}
                                                            </button>
                                                        </div>
                                                    ) : null}

                                                    {isApprovedRequirement && requirementDetailsExpanded ? (
                                                        <div className="grid w-full gap-3 md:grid-cols-2">
                                                            <label className="block space-y-1">
                                                                <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                    {tai.work_status_label}
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
                                                                    {tai.assigned_to_label}
                                                                </span>
                                                                <select
                                                                    value={assignedUserId}
                                                                    onChange={(event) => updateRequirementWork(requirement, workStatus, event.target.value)}
                                                                    disabled={requirementUpdatesLocked}
                                                                    className="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-violet-400 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                                >
                                                                    <option value="">{tai.unassigned}</option>
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
                                                            {tai.edit_requirement}
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

                    <section className="h-full rounded-[22px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] lg:flex lg:max-h-[calc(100vh-8rem)] lg:min-h-0 lg:flex-col lg:overflow-hidden">
                        <div className="flex h-full min-h-0 flex-col gap-5">
                            <div className="space-y-2">
                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                    {tai.answer_draft_overline}
                                </div>
                                <h2 className="text-xl font-semibold tracking-tight text-slate-950">
                                    {tai.answer_draft_title}
                                </h2>
                                <p className="text-sm leading-6 text-slate-500">
                                    {tai.answer_draft_description}
                                </p>
                            </div>

                            {!activeRequirement ? (
                                canUseAiOffer ? (
                                    <div className="flex min-h-0 flex-1 flex-col justify-start rounded-[22px] border border-dashed border-slate-300 bg-slate-50 px-6 py-10">
                                        <div className="text-lg font-semibold text-slate-900">
                                            {tai.no_active_answer_draft_title}
                                        </div>
                                        <p className="mt-2 text-sm text-slate-500">
                                            {tai.no_active_answer_draft_description}
                                        </p>
                                    </div>
                                ) : (
                                    <AiAccessNotice aiText={tai} billingHref={billingHref} />
                                )
                            ) : !canUseAiOffer ? (
                                <AiAccessNotice aiText={tai} billingHref={billingHref} />
                            ) : (
                                <div className={`flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto rounded-[22px] border p-4 pr-2 ${
                                    activeRequirementBlockedMissingKnowledge
                                        ? 'border-amber-200 bg-amber-50/50'
                                        : 'border-violet-200 bg-violet-50/40'
                                }`}>
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-violet-600">
                                            {tai.answer_draft_for_requirement} {activeRequirementDisplayIdentifier}
                                        </div>

                                        <div className="flex flex-wrap items-center gap-2 pr-3">
                                            {activeRequirementHasDraft && !activeRequirementBlockedMissingKnowledge ? (
                                                <button
                                                    type="button"
                                                    onClick={() => setAnswerDraftReaderExpanded((currentState) => !currentState)}
                                                    className="inline-flex items-center justify-center rounded-full border border-violet-200 bg-white px-3 py-1.5 text-xs font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-50"
                                                >
                                                    {answerDraftReaderExpanded ? tai.normal_reader : tai.larger_reader}
                                                </button>
                                            ) : null}
                                        </div>
                                    </div>

                                    {answerDraftGeneratingRequirementId === activeRequirement.id ? (
                                        <div className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-800">
                                            {tai.generating_answer_draft}
                                        </div>
                                    ) : null}

                                    {answerDraftError ? (
                                        <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm leading-6 text-rose-700">
                                            {answerDraftError}
                                        </div>
                                    ) : null}

                                    {activeRequirementBlockedMissingKnowledge ? (
                                        <div className="rounded-2xl border border-amber-200 bg-white px-4 py-4 text-sm leading-6 text-slate-700">
                                            <div className="flex flex-wrap items-start gap-3">
                                                <span className="inline-flex rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-amber-700">
                                                    {tai.coverage_status_missing}
                                                </span>
                                                <div className="min-w-0 flex-1 text-sm font-semibold text-slate-950">
                                                    {tai.requirement_blocked_message}
                                                </div>
                                            </div>

                                            <div className="mt-4 rounded-2xl border border-amber-200 bg-amber-50/60 px-4 py-4 text-sm leading-6 text-slate-700">
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-amber-700">
                                                    {tai.assessment_next_step_label}
                                                </div>
                                                <p className="mt-2 text-sm leading-6 text-slate-700">
                                                    {tai.blocked_knowledge_intro}
                                                </p>

                                                {activeRequirementMissingKnowledgeRecommendedDocumentTitle !== '' || activeRequirementMissingKnowledgeSuggestedFilename !== '' ? (
                                                    <div className="mt-3 space-y-2 rounded-2xl border border-amber-200 bg-white px-4 py-3 text-sm leading-6 text-slate-700">
                                                        {activeRequirementMissingKnowledgeRecommendedDocumentTitle !== '' ? (
                                                            <div>
                                                                <span className="font-semibold text-slate-900">{tai.recommended_document_label}</span>{' '}
                                                                {activeRequirementMissingKnowledgeRecommendedDocumentTitle}
                                                            </div>
                                                        ) : null}

                                                        {activeRequirementMissingKnowledgeSuggestedFilename !== '' ? (
                                                            <div>
                                                                <span className="font-semibold text-slate-900">{tai.suggested_filename_label}</span>{' '}
                                                                {activeRequirementMissingKnowledgeSuggestedFilename}
                                                            </div>
                                                        ) : null}
                                                    </div>
                                                ) : null}

                                                <p className="mt-3 text-sm leading-6 text-slate-600">
                                                    {tai.document_processed_hint}
                                                </p>
                                            </div>

                                            {activeRequirementMissingKnowledgeHasDetails ? (
                                                <div className="mt-4">
                                                    <button
                                                        type="button"
                                                        onClick={() => setAnswerDraftMissingKnowledgeDetailsExpanded((currentState) => !currentState)}
                                                        className="inline-flex items-center justify-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-100"
                                                        aria-expanded={answerDraftMissingKnowledgeDetailsExpanded}
                                                    >
                                                        {answerDraftMissingKnowledgeDetailsExpanded
                                                            ? tai.blocked_knowledge_details_hide
                                                            : tai.blocked_knowledge_details_show}
                                                    </button>

                                                    {answerDraftMissingKnowledgeDetailsExpanded ? (
                                                        <div className="mt-3 space-y-4 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-4">
                                                            {activeRequirementMissingKnowledgeExplanation !== ''
                                                                && activeRequirementMissingKnowledgeExplanation !== tai.requirement_blocked_message ? (
                                                                <p className="text-sm leading-6 text-slate-700">
                                                                    {activeRequirementMissingKnowledgeExplanation}
                                                                </p>
                                                            ) : null}

                                                            {activeRequirementMissingKnowledgeSummary !== '' ? (
                                                                <p className="text-sm leading-6 text-slate-700">
                                                                    {activeRequirementMissingKnowledgeSummary}
                                                                </p>
                                                            ) : activeRequirementMissingKnowledgeReasoning !== '' ? (
                                                                <p className="text-sm leading-6 text-slate-700">
                                                                    {activeRequirementMissingKnowledgeReasoning}
                                                                </p>
                                                            ) : null}

                                                            {(activeRequirementMissingKnowledge?.directly_supported_points?.length ?? 0) > 0 ? (
                                                                <div className="space-y-2 rounded-2xl border border-emerald-100 bg-white px-4 py-4 text-sm leading-6 text-slate-700">
                                                                    <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-emerald-700">
                                                                        {tai.directly_supported_label}
                                                                    </div>
                                                                    <ul className="space-y-2">
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
                                                                                                    aria-label={`${tai.evidence_title} ${evidenceLabel}`}
                                                                                                >
                                                                                                    {tai.evidence_title}: {evidenceLabel}
                                                                                                </button>
                                                                                            );
                                                                                        }

                                                                                        return (
                                                                                            <span className="block text-xs text-slate-500">
                                                                                                {tai.evidence_title}: {evidenceLabel}
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
                                                                <div className="space-y-2 rounded-2xl border border-amber-100 bg-white px-4 py-4 text-sm leading-6 text-slate-700">
                                                                    <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-amber-700">
                                                                        {tai.related_insufficient_label}
                                                                    </div>
                                                                    <p className="text-sm leading-6 text-slate-600">
                                                                        {tai.blocked_related_knowledge}
                                                                    </p>
                                                                    <ul className="space-y-2">
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
                                                                <div className="space-y-2 rounded-2xl border border-rose-100 bg-white px-4 py-4 text-sm leading-6 text-slate-700">
                                                                    <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-rose-700">
                                                                        {tai.unsupported_points_label}
                                                                    </div>
                                                                    <ul className="space-y-2">
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
                                                </div>
                                            ) : null}
                                        </div>
                                    ) : activeRequirementHasDraft ? (
                                        <div className="flex flex-col gap-5">
                                            <div className="flex flex-col gap-2">
                                                <textarea
                                                    aria-label={`${tai.answer_draft_for_requirement} ${activeRequirementDisplayIdentifier}`}
                                                    value={activeRequirementDraft?.text ?? ''}
                                                    onChange={(event) => updateActiveAnswerDraftText(event.target.value)}
                                                    rows={16}
                                                    disabled={
                                                        answerDraftGeneratingRequirementId === activeRequirement.id
                                                        || answerDraftSavingRequirementId === activeRequirement.id
                                                    }
                                                    className={`${answerDraftReaderExpanded ? 'h-[32rem] lg:h-[calc(100vh-18rem)]' : 'h-[14rem]'} w-full resize-y overflow-y-auto rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm leading-7 text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-violet-400 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:opacity-60`}
                                                    placeholder={tai.answer_draft_placeholder}
                                                />
                                            </div>

                                            {activeRequirementImageRetrievalSources.length > 0 ? (
                                                <div className="flex flex-col gap-3">
                                                    {activeRequirementImageRetrievalSources.map((source, index) => {
                                                        const imageUrl = resolveClipboardImageUrl(source);

                                                        if (imageUrl === '') {
                                                            return null;
                                                        }

                                                        return (
                                                            <img
                                                                key={source.id ?? source.chunk_id ?? `answer-image-${index}`}
                                                                src={imageUrl}
                                                                alt=""
                                                                className="max-w-full rounded-2xl border border-slate-200 bg-white shadow-sm"
                                                            />
                                                        );
                                                    })}
                                                </div>
                                            ) : null}

                                            {activeRequirementTableRetrievalSources.length > 0 ? (
                                                <div className="mt-4 flex flex-col gap-3 rounded-[20px] border border-violet-200 bg-violet-50/40 p-4">
                                                    <div className="flex flex-col gap-1">
                                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-violet-700">
                                                            {tai.table_evidence_overline}
                                                        </div>
                                                        <p className="text-sm leading-6 text-slate-600">
                                                            {tai.table_evidence_description}
                                                        </p>
                                                    </div>

                                                    <div className="flex flex-col gap-3">
                                                        {activeRequirementTableRetrievalSources.map((source) => {
                                                            const sourceTitle = String(source?.title ?? '').trim()
                                                                || String(source?.heading_path ?? '').trim()
                                                                || String(source?.document_title ?? source?.knowledge_item_title ?? '').trim()
                                                                || tai.table_evidence_label;
                                                            const sourceDocumentTitle = String(source?.document_title ?? source?.knowledge_item_title ?? '').trim();
                                                            const summaryForRetrieval = String(source?.summary_for_retrieval ?? '').trim();
                                                            const tableText = String(source?.table_text ?? '').trim();
                                                            const hasRenderableTableJson = Array.isArray(source?.table_json?.rows)
                                                                && source.table_json.rows.length > 0;

                                                            return (
                                                                <div key={source.id ?? source.chunk_id} className="rounded-[18px] border border-slate-200 bg-white p-4 shadow-sm">
                                                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                                                        <div className="space-y-1">
                                                                            <div className="text-sm font-semibold text-slate-950">
                                                                                {sourceTitle}
                                                                            </div>
                                                                            {sourceDocumentTitle !== '' ? (
                                                                                <div className="text-xs text-slate-500">
                                                                                    {sourceDocumentTitle}
                                                                                </div>
                                                                            ) : null}
                                                                        </div>

                                                                        <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                            {tai.table_evidence_label}
                                                                        </span>
                                                                    </div>

                                                                    {summaryForRetrieval !== '' ? (
                                                                        <div className="mt-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-700">
                                                                            {summaryForRetrieval}
                                                                        </div>
                                                                    ) : null}

                                                                    {source.table_html ? (
                                                                        <div className="mt-4 overflow-x-auto rounded-[14px] border border-slate-200 bg-white">
                                                                            <div
                                                                                className="min-w-max"
                                                                                dangerouslySetInnerHTML={{ __html: source.table_html }}
                                                                            />
                                                                        </div>
                                                                    ) : hasRenderableTableJson ? (
                                                                        <div className="mt-4">
                                                                            <StructuredTablePreview tableJson={source.table_json} />
                                                                        </div>
                                                                    ) : tableText !== '' ? (
                                                                        <pre className="mt-4 whitespace-pre-wrap text-sm leading-6 text-slate-700">
                                                                            {tableText}
                                                                        </pre>
                                                                    ) : (
                                                                        <p className="mt-4 text-sm leading-6 text-slate-500">
                                                                            {tai.table_no_view}
                                                                        </p>
                                                                    )}
                                                                </div>
                                                            );
                                                        })}
                                                    </div>
                                                </div>
                                            ) : null}

                                            {activeRequirementKnowledgeGrounding ? (
                                                <div className="mt-4 flex items-center justify-end gap-2">
                                                    <InfoHint size="sm" align="left" label="Vis forklaring for Kunnskapsgrunnlag" text={tai.hint_knowledge_grounding} />
                                                    <span className={`inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold ring-1 ring-inset ${KNOWLEDGE_GROUNDING_META[activeRequirementKnowledgeGrounding.level]?.className ?? KNOWLEDGE_GROUNDING_META.red.className}`}>
                                                        {tai[`knowledge_grounding_${activeRequirementKnowledgeGrounding.level}`] ?? KNOWLEDGE_GROUNDING_META[activeRequirementKnowledgeGrounding.level]?.label ?? KNOWLEDGE_GROUNDING_META.red.label}
                                                    </span>
                                                </div>
                                            ) : null}

                                            <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                                                <div className="text-xs text-slate-500">
                                                    {activeRequirementDraft?.generatedAt ? (
                                                        <>
                                                            {tai.generated}{' '}
                                                            {new Intl.DateTimeFormat(locale, {
                                                                day: '2-digit',
                                                                month: 'short',
                                                                year: 'numeric',
                                                                hour: '2-digit',
                                                                minute: '2-digit',
                                                            }).format(new Date(activeRequirementDraft.generatedAt))}
                                                        </>
                                                    ) : (
                                                        tai.answer_draft_not_generated
                                                    )}
                                                    {activeRequirementDraft?.isDirty ? ` ${tai.unsaved_changes}` : ''}
                                                </div>

                                                <div className="flex flex-wrap items-center gap-2">
                                                    {answerDraftCopyStatus === 'failed' ? (
                                                        <span className="text-xs font-semibold text-rose-600">
                                                            {tai.copy_failed}
                                                        </span>
                                                    ) : null}

                                                    {answerDraftCopyStatus === 'empty' ? (
                                                        <span className="text-xs font-semibold text-slate-500">
                                                            {tai.copy_empty}
                                                        </span>
                                                    ) : null}

                                                    <button
                                                        type="button"
                                                        onClick={copyActiveAnswerDraftContent}
                                                        disabled={
                                                            !activeRequirementDraft
                                                            || answerDraftGeneratingRequirementId === activeRequirement.id
                                                            || answerDraftSavingRequirementId === activeRequirement.id
                                                        }
                                                        className="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        {answerDraftCopyStatus === 'copied' ? tai.copied : tai.copy_to_word}
                                                    </button>

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
                                                    {answerDraftSavingRequirementId === activeRequirement.id ? tai.saving : tai.save_answer_draft}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="flex min-h-0 flex-1 flex-col justify-start rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5">
                                            <div className="text-sm font-semibold text-slate-900">
                                                {tai.no_answer_draft_title}
                                            </div>
                                            <p className="mt-2 text-sm leading-6 text-slate-600">
                                                {tai.use_prompt_again_hint}
                                            </p>
                                        </div>
                                    )}
                                    {(activeRequirement?.knowledge_sources_sent_to_ai?.length ?? 0) > 0 ? (
                                        <div className="space-y-2 rounded-2xl border border-slate-200 bg-slate-50/60 px-4 py-3">
                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                {tai.sources_sent_to_ai_title ?? 'Kilder sendt til AI'}
                                            </div>
                                            <p className="text-[11px] leading-5 text-slate-500">
                                                {tai.sources_sent_to_ai_help ?? 'Disse kunnskapskildene ble sendt til AI som grunnlag for svaret.'}
                                            </p>
                                            <div className="space-y-1.5">
                                                {activeRequirement.knowledge_sources_sent_to_ai.map((source, index) => (
                                                    <div key={source.evidence_id ?? index} className="rounded-xl border border-slate-200 bg-white px-3 py-2">
                                                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                            {source.knowledge_item_show_url ? (
                                                                <Link
                                                                    href={source.knowledge_item_show_url}
                                                                    className="text-xs font-semibold text-slate-800 hover:text-violet-700 hover:underline"
                                                                >
                                                                    {source.original_filename ?? '—'}
                                                                </Link>
                                                            ) : (
                                                                <span className="text-xs font-semibold text-slate-800">
                                                                    {source.original_filename ?? '—'}
                                                                </span>
                                                            )}
                                                            {source.knowledge_item_version_no != null ? (
                                                                <span className="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-[10px] font-medium text-slate-500">
                                                                    v{source.knowledge_item_version_no}
                                                                </span>
                                                            ) : null}
                                                            {source.document_type_label ? (
                                                                <span className="inline-flex items-center rounded-full border border-violet-100 bg-violet-50 px-1.5 py-0.5 text-[10px] font-medium text-violet-700">
                                                                    {source.document_type_label}
                                                                </span>
                                                            ) : null}
                                                            {source.version_is_current_now === false ? (
                                                                <span className="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">
                                                                    {tai.sources_sent_to_ai_outdated_version ?? 'Tidligere dokumentversjon'}
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                        <div className="mt-1 flex flex-wrap items-center gap-x-2 text-[11px] text-slate-500">
                                                            {source.chunk_index != null ? (
                                                                <span>{tai.sources_sent_to_ai_excerpt_label ?? 'Utdrag'} {source.chunk_index + 1}</span>
                                                            ) : null}
                                                            {source.match_rank != null ? (
                                                                <span>{tai.sources_sent_to_ai_rank_label ?? 'Rangering'} {source.match_rank}</span>
                                                            ) : null}
                                                            {source.section_title ? (
                                                                <span className="max-w-[18rem] truncate">{source.section_title}</span>
                                                            ) : null}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    ) : null}
                                </div>
                            )}
                        </div>
                    </section>

                </div>

                {responsiblePickerRequirement !== null ? (
                    <div
                        className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 px-4 py-6"
                        onClick={closeResponsiblePicker}
                    >
                        <div
                            className="w-full max-w-2xl rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_24px_80px_rgba(15,23,42,0.25)]"
                            onClick={(event) => event.stopPropagation()}
                        >
                            <div className="flex items-start justify-between gap-4">
                                <div className="space-y-1">
                                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-violet-500">
                                        {tai.choose_responsible_user}
                                    </div>
                                    <h3 className="text-lg font-semibold tracking-tight text-slate-950">
                                        {responsiblePickerRequirement.current_requirement_text ?? responsiblePickerRequirement.requirement_text ?? tai.set_responsible}
                                    </h3>
                                    <p className="text-sm text-slate-500">
                                        {responsiblePickerRequirement.current_requirement_identifier ?? responsiblePickerRequirement.requirement_identifier ?? '—'}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={closeResponsiblePicker}
                                    className="rounded-full border border-slate-200 bg-white px-3 py-1 text-sm font-semibold text-slate-600 transition hover:border-slate-300 hover:text-slate-950"
                                >
                                    ×
                                    </button>
                                </div>

                            <div className="mt-5 space-y-3">
                                <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-950">
                                    {responsiblePickerSummary}
                                </div>

                                <label className="block space-y-1">
                                    <span className="sr-only">{tai.choose_responsible_user}</span>
                                    <input
                                        type="search"
                                        value={responsibleUserSearch}
                                        onChange={(event) => setResponsibleUserSearch(event.target.value)}
                                        placeholder={tai.responsible_user_search_placeholder}
                                        className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-violet-400 focus:ring-4 focus:ring-violet-100"
                                    />
                                </label>

                                {(() => {
                                    const isNoResponsibleSelected = !responsiblePickerHasAssignedUser;

                                    return (
                                        <button
                                            type="button"
                                            onClick={() => void updateRequirementAssignedUser(responsiblePickerRequirement, null)}
                                            disabled={responsibleSavingRequirementId === responsiblePickerRequirement.id}
                                            className={`flex w-full items-center justify-between rounded-2xl border px-4 py-3 text-left text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-60 ${
                                                isNoResponsibleSelected
                                                    ? 'border-violet-300 bg-violet-50 text-violet-800'
                                                    : 'border-slate-200 bg-slate-50 text-slate-700 hover:border-violet-300 hover:bg-violet-50 hover:text-violet-700'
                                            }`}
                                        >
                                            <div className="min-w-0 text-left">
                                                <div className="font-semibold">
                                                    {tai.no_responsible_user}
                                                </div>
                                            <div className="text-xs text-slate-500">
                                                {tai.remove_responsible}
                                            </div>
                                        </div>
                                            {isNoResponsibleSelected ? (
                                                <span className="shrink-0 rounded-full bg-violet-600 px-3 py-1 text-xs font-semibold text-white">
                                                    {tai.selected}
                                                </span>
                                            ) : null}
                                        </button>
                                    );
                                })()}

                                <div className="max-h-[45vh] space-y-2 overflow-y-auto pr-1">
                                    {assignableUsers.length > 0 ? (
                                        filteredAssignableUsers.length > 0 ? (
                                            filteredAssignableUsers.map((user) => {
                                                const isSelectedResponsibleUser = responsiblePickerAssignedUserId !== null
                                                    && String(user.id) === String(responsiblePickerAssignedUserId);

                                                return (
                                                    <button
                                                        key={user.id}
                                                        type="button"
                                                        onClick={() => void updateRequirementAssignedUser(responsiblePickerRequirement, user.id)}
                                                        disabled={responsibleSavingRequirementId === responsiblePickerRequirement.id}
                                                        className={`flex w-full items-center justify-between rounded-2xl border px-4 py-3 text-left text-sm transition disabled:cursor-not-allowed disabled:opacity-60 ${
                                                            isSelectedResponsibleUser
                                                                ? 'border-violet-300 bg-violet-50 text-violet-800'
                                                                : 'border-slate-200 bg-white text-slate-700 hover:border-violet-300 hover:bg-violet-50 hover:text-violet-700'
                                                        }`}
                                                    >
                                                        <div className="min-w-0">
                                                            <div className="font-semibold text-slate-950">
                                                                {user.name}
                                                            </div>
                                                            <div className="truncate text-xs text-slate-500">
                                                                {user.email}
                                                            </div>
                                                        </div>
                                                        {isSelectedResponsibleUser ? (
                                                            <span className="shrink-0 rounded-full bg-violet-600 px-3 py-1 text-xs font-semibold text-white">
                                                                {tai.selected_responsible_user}
                                                            </span>
                                                        ) : null}
                                                    </button>
                                                );
                                            })
                                        ) : (
                                            <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-500">
                                                {responsibleUserSearch.trim() !== ''
                                                    ? tai.no_users_found
                                                    : tai.no_responsible_user}
                                            </div>
                                        )
                                    ) : (
                                        <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-500">
                                            {tai.no_responsible_user}
                                        </div>
                                    )}
                                </div>

                                {responsibleUpdateError ? (
                                    <p className="text-sm text-rose-600">
                                        {responsibleUpdateError}
                                    </p>
                                ) : null}
                            </div>
                        </div>
                    </div>
                ) : null}

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
