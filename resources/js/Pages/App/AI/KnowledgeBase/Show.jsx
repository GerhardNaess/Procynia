import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';

const DOCUMENT_STATUS_CLASS = {
    review: 'bg-amber-100 text-amber-800 ring-amber-200',
    processing: 'bg-sky-100 text-sky-700 ring-sky-200',
    approved: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    failed: 'bg-rose-100 text-rose-700 ring-rose-200',
};

const CHUNK_REVIEW_STATUS_CLASS = {
    pending_review: 'bg-amber-100 text-amber-800 ring-amber-200',
    approved: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    rejected: 'bg-rose-100 text-rose-700 ring-rose-200',
};

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function formatTemplate(template, values = {}) {
    let output = String(template ?? '');

    Object.entries(values).forEach(([key, value]) => {
        output = output.replaceAll(`:${key}`, String(value));
    });

    return output;
}

function normalizeSearchText(value) {
    return String(value ?? '').trim().toLowerCase();
}

function highlightText(text, query) {
    if (!query || !text) return text;
    const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const parts = String(text).split(new RegExp(`(${escaped})`, 'gi'));
    if (parts.length === 1) return text;
    return parts.map((part, i) =>
        normalizeSearchText(part) === normalizeSearchText(query)
            ? <mark key={i} className="rounded bg-amber-100 text-amber-900">{part}</mark>
            : part,
    );
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

function formatFileTypeLabel(mimeType, labels = {}) {
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
        return labels.wordDocumentLabel ?? 'Word-dokument';
    }

    if (
        value === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        || value === 'application/vnd.ms-excel'
    ) {
        return labels.excelDocumentLabel ?? 'Excel-dokument';
    }

    if (value === 'text/plain') {
        return labels.textFileLabel ?? 'Tekstfil';
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

function isChunkMetadataPending(chunk) {
    return chunk?.metadata_status === 'pending_review' && !chunk?.ai_summary;
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

function getGeneratedGraphicFallbackNumber(value) {
    const text = String(value ?? '').trim();
    const match = text.match(/^(?:bilde|grafikk|picture|image|graphic)\s*(\d+)$/iu);

    return match ? Number.parseInt(match[1], 10) : null;
}

function isGeneratedGraphicFallbackLabel(value) {
    return getGeneratedGraphicFallbackNumber(value) !== null;
}

function getGraphicDisplayTitle(chunk, graphicSequence = 0, labels = {}) {
    const graphicLabel = labels.graphicLabel ?? 'Grafikk';
    const title = String(chunk?.title ?? '').trim();

    if (title !== '' && !isGeneratedGraphicFallbackLabel(title)) {
        return title;
    }

    const caption = getGraphicCaption(chunk);

    if (caption !== '') {
        return caption;
    }

    const altText = String(chunk?.image_alt_text ?? '').trim();

    if (altText !== '' && !isGeneratedGraphicFallbackLabel(altText)) {
        return altText;
    }

    const storedSequence = Number.parseInt(String(chunk?.image_metadata?.graphic_sequence_in_document ?? ''), 10);
    const resolvedSequence = Number.isFinite(storedSequence) && storedSequence > 0 ? storedSequence : graphicSequence;

    return resolvedSequence > 0 ? `${graphicLabel} ${resolvedSequence}` : graphicLabel;
}

function getGraphicAltText(chunk) {
    const altText = String(chunk?.image_alt_text ?? '').trim();

    if (altText === '' || isGeneratedGraphicFallbackLabel(altText)) {
        return '';
    }

    return altText;
}

function getGraphicCaption(chunk) {
    const caption = String(chunk?.image_caption ?? '').trim();

    if (caption === '' || isGeneratedGraphicFallbackLabel(caption)) {
        return '';
    }

    return caption;
}

function getGraphicSearchableContent(chunk) {
    let text = String(chunk?.content ?? chunk?.content_preview ?? '').trim();

    if (text === '') {
        return '';
    }

    text = text
        .replace(/^Bilde i seksjon:/gimu, 'Grafikk i seksjon:')
        .replace(/^Bilde$/gimu, 'Grafikk')
        .replace(/^Bildefil:/gimu, 'Grafikkfil:')
        .replace(/^Bildetekst:/gimu, 'Grafikktekst:')
        .replace(/^Bildebeskrivelse:/gimu, 'Grafikkbeskrivelse:')
        .replace(/(?:\n\s*)*Alternativ tekst:\s*(?:Bilde|Grafikk|Picture|Image|Graphic)\s*\d+\s*/gimu, '\n\n')
        .replace(/\n{3,}/gmu, '\n\n')
        .trim();

    return text;
}

function getChunkEditableContent(chunk) {
    if (chunk?.chunk_type === 'image') {
        return getGraphicSearchableContent(chunk);
    }

    return chunk?.content ?? '';
}

function getTableDisplayTitle(chunk, tableSequence = 0, labels = {}) {
    const tableLabel = labels.tableLabel ?? 'Tabell';
    const storedSequence = Number.parseInt(String(chunk?.table_metadata?.table_sequence_in_document ?? ''), 10);
    const resolvedSequence = Number.isFinite(storedSequence) && storedSequence > 0 ? storedSequence : tableSequence;

    return resolvedSequence > 0 ? `${tableLabel} ${resolvedSequence}` : tableLabel;
}

function getChunkDisplayTitle(chunk, index = 0, graphicSequence = 0, tableSequence = 0, labels = {}) {
    if (chunk?.chunk_type === 'image') {
        return getGraphicDisplayTitle(chunk, graphicSequence, labels);
    }

    if (chunk?.chunk_type === 'table') {
        return getTableDisplayTitle(chunk, tableSequence, labels);
    }

    const title = String(chunk?.title ?? '').trim();
    const chunkLabel = labels.chunkLabel ?? 'Chunk';

    return title !== '' ? title : `${chunkLabel} ${index + 1}`;
}

function getChunkTypeLabel(chunk, labels = {}) {
    if (chunk?.chunk_type === 'image') {
        return labels.graphicTypeLabel ?? labels.graphicLabel ?? 'Grafikk';
    }

    if (chunk?.chunk_type === 'table') {
        return labels.tableTypeLabel ?? labels.tableLabel ?? 'Tabell';
    }

    if (chunk?.chunk_type === 'document') {
        return labels.documentTypeLabel ?? 'Dokument';
    }

    return labels.textTypeLabel ?? 'Tekst';
}

/**
 * Purpose: Resolve a stable image file extension for preview decisions.
 * Inputs: A knowledge chunk that may contain image metadata.
 * Returns: A normalized lower-case extension or null when no extension is available.
 * Side effects: None.
 */
function getImageChunkExtension(chunk) {
    const fromMetadata = String(chunk?.image_metadata?.extension ?? '').trim().toLowerCase();

    if (fromMetadata !== '') {
        return fromMetadata;
    }

    const fromFilename = String(chunk?.image_original_filename ?? '').trim();
    const filenameMatch = fromFilename.match(/\.([A-Za-z0-9]+)$/);

    if (filenameMatch?.[1]) {
        return filenameMatch[1].toLowerCase();
    }

    const fromPath = String(chunk?.image_path ?? '').trim();
    const pathMatch = fromPath.match(/\.([A-Za-z0-9]+)$/);

    return pathMatch?.[1] ? pathMatch[1].toLowerCase() : null;
}

/**
 * Purpose: Determine whether a chunk image can be previewed directly in the browser.
 * Inputs: A knowledge chunk that may contain image metadata and a stored image URL.
 * Returns: True when the image format is browser-friendly for direct preview.
 * Side effects: None.
 */
function canPreviewImageChunk(chunk) {
    const extension = getImageChunkExtension(chunk);

    if (!extension) {
        return false;
    }

    return ['png', 'jpg', 'jpeg', 'gif', 'webp'].includes(extension);
}

/**
 * Purpose: Remove markdown-only table warnings from the user-facing warning list.
 * Inputs: Raw warning values from a selected knowledge chunk.
 * Returns: A filtered list of displayable warnings.
 * Side effects: None.
 */
function normalizeTableWarningsForDisplay(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    return value
        .map((warning) => String(warning ?? '').trim())
        .filter((warning) => warning !== '' && warning !== 'markdown_is_simplified');
}

/**
 * Purpose: Render a structured table preview from the structured table model.
 * Inputs: A knowledge chunk table_json payload.
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
            className: classNames(
                'border border-slate-200 px-3 py-2 align-top text-slate-700',
                isHeaderRow || rowType === 'title' || rowType === 'group'
                    ? 'bg-slate-50 font-semibold text-slate-950'
                    : 'bg-white',
            ),
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
        <div className="mt-4 overflow-x-auto rounded-[14px] border border-slate-200 bg-white">
            <table className="min-w-full border-collapse text-sm">
                {(titleRow || headerRowIndices.length > 0) ? (
                    <thead>
                        {titleRow ? renderRow(titleRow, titleRowIndex, false) : null}
                        {headerRowIndices.map((headerRowIndex) => {
                            if (!rows[headerRowIndex]) {
                                return null;
                            }

                            return renderRow(rows[headerRowIndex], headerRowIndex, true);
                        })}
                    </thead>
                ) : null}
                <tbody>
                    {bodyRows.map((row, rowIndex) => renderRow(row, rowIndex, false))}
                </tbody>
            </table>
        </div>
    );
}

function buildHistoryEntries(item, locale, status, labels = {}) {
    const entries = [];

    if (item?.uploaded_at) {
        entries.push({
            label: labels.historyUploadedLabel ?? 'Lagt opp',
            time: formatDateTime(item.uploaded_at, locale),
            text: item?.uploaded_by
                ? formatTemplate(labels.historyUploadedByPrefix ?? 'Av :name', { name: item.uploaded_by })
                : (labels.historyUploadedText ?? 'Lastet opp i kunnskapsbasen.'),
        });
    }

    if (item?.updated_at && item.updated_at !== item.uploaded_at) {
        entries.push({
            label: labels.historyUpdatedLabel ?? 'Sist endret',
            time: formatDateTime(item.updated_at, locale),
            text: labels.historyUpdatedText ?? 'Metadata eller dokumentstatus ble sist lagret.',
        });
    }

    if (status === 'failed') {
        entries.push({
            label: labels.historyExtractionLabel ?? 'Ekstraksjon',
            time: item?.updated_at ? formatDateTime(item.updated_at, locale) : '—',
            text: item?.extraction_error || (labels.historyExtractionFailedText ?? 'Tekstuttrekk feilet.'),
        });
    } else if (status === 'processing') {
        entries.push({
            label: labels.historyExtractionLabel ?? 'Ekstraksjon',
            time: item?.updated_at ? formatDateTime(item.updated_at, locale) : '—',
            text: labels.historyExtractionProcessingText ?? 'Dokumentet er under prosessering.',
        });
    } else {
        entries.push({
            label: labels.historyExtractionLabel ?? 'Ekstraksjon',
            time: item?.updated_at ? formatDateTime(item.updated_at, locale) : '—',
            text: labels.historyExtractionCompletedText ?? 'Tekst er ekstrahert og klargjort for chunk-visning.',
        });
    }

    return entries;
}

function getRevisionChangeTypeLabel(changeType) {
    const normalized = String(changeType ?? '').trim();

    if (normalized === 'created') {
        return 'Opprettet';
    }

    if (normalized === 'metadata_updated') {
        return 'Metadata endret';
    }

    if (normalized === 'deleted') {
        return 'Slettet';
    }

    return normalized !== '' ? normalized : '—';
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
    pageTitle = null,
    knowledgeItem = null,
    indexUrl = '/app/ai/knowledge-base',
    summaryUpdateUrl = '/app/ai/knowledge-base',
    editUrl = '/app/ai/knowledge-base',
}) {
    const { locale = 'nb-NO', translations = {} } = usePage().props;
    const tks = translations?.knowledge_show ?? {};
    const commonText = translations?.common ?? {};
    const knowledgeShowLabels = {
        graphicLabel: tks.graphic_section,
        tableLabel: tks.table_section,
        chunkLabel: tks.chunk_label,
        chunkNumberLabel: tks.chunk_number,
        chunkCounterLabel: tks.chunk_counter,
        chunkCountLabel: tks.chunk_count,
        reviewProgressLabel: tks.review_progress,
        wordDocumentLabel: tks.filetype_word,
        excelDocumentLabel: tks.filetype_excel,
        textFileLabel: tks.filetype_text,
        historyUploadedLabel: tks.history_uploaded,
        historyUploadedByPrefix: tks.history_uploaded_by_prefix,
        historyUploadedText: tks.history_uploaded_text,
        historyUpdatedLabel: tks.history_updated,
        historyUpdatedText: tks.history_updated_text,
        historyExtractionLabel: tks.history_extraction,
        historyExtractionFailedText: tks.history_extraction_failed_text,
        historyExtractionProcessingText: tks.history_extraction_processing_text,
        historyExtractionCompletedText: tks.history_extraction_completed_text,
        activeLabel: tks.active_label,
        inactiveLabel: tks.inactive_label,
        documentFallbackTitle: tks.document_fallback_title,
        chunkTypeDocumentLabel: tks.chunk_type_document,
        chunkTypeTextLabel: tks.chunk_type_text,
        unknownValue: tks.unknown_value,
        documentOwnerLabel: commonText.document_owner_label ?? 'Dokumenteier',
    };

    const DOCUMENT_STATUS_LABEL = {
        review: tks.doc_status_review,
        processing: tks.doc_status_processing,
        approved: tks.doc_status_approved,
        failed: tks.doc_status_failed,
    };

    const CHUNK_REVIEW_STATUS_META = {
        pending_review: { label: tks.chunk_status_review, className: CHUNK_REVIEW_STATUS_CLASS.pending_review },
        approved: { label: tks.chunk_status_approved, className: CHUNK_REVIEW_STATUS_CLASS.approved },
        rejected: { label: tks.chunk_status_rejected, className: CHUNK_REVIEW_STATUS_CLASS.rejected },
    };

    const getChunkReviewStatusMeta = (chunk) =>
        CHUNK_REVIEW_STATUS_META[getChunkReviewStatus(chunk)] ?? CHUNK_REVIEW_STATUS_META.pending_review;

    const TAB_OPTIONS = [
        { value: 'chunks', label: tks.tab_chunks },
        { value: 'metadata', label: tks.tab_metadata },
        { value: 'history', label: tks.tab_history },
    ];
    const [activeTab, setActiveTab] = useState('chunks');
    const [selectedChunkId, setSelectedChunkId] = useState(knowledgeItem?.chunks?.[0]?.id ?? null);
    const [chunkReviewRequest, setChunkReviewRequest] = useState(null);
    const [isChunkMetadataEditing, setIsChunkMetadataEditing] = useState(false);
    const [isChunkContentEditing, setIsChunkContentEditing] = useState(false);
    const [isChunkContentSaving, setIsChunkContentSaving] = useState(false);
    const [showChunkSystemMetadata, setShowChunkSystemMetadata] = useState(false);
    const [chunkSearchQuery, setChunkSearchQuery] = useState('');
    const tabsRef = useRef(null);

    const resolvedPageTitle = pageTitle ?? tks.breadcrumb;
    const ownershipLabel = String(knowledgeItem?.ownership_label ?? '').trim();
    const ownerName = String(knowledgeItem?.owner_name ?? '').trim();
    const uploadedByName = String(knowledgeItem?.uploaded_by ?? '').trim();
    const owningSavedNoticeTitle = String(knowledgeItem?.owning_saved_notice_title ?? '').trim();
    const documentThemeLabel = String(knowledgeItem?.document_theme_label ?? '').trim();
    const ownerDisplayName = ownerName !== '' ? ownerName : commonText.not_set ?? tks.unknown_owner;
    const documentTitle = knowledgeItem?.original_filename ?? knowledgeItem?.title ?? knowledgeShowLabels.documentFallbackTitle;
    const documentStatus = getDocumentStatus(knowledgeItem);
    const documentStatusMeta = {
        className: DOCUMENT_STATUS_CLASS[documentStatus] ?? DOCUMENT_STATUS_CLASS.review,
        label: DOCUMENT_STATUS_LABEL[documentStatus] ?? DOCUMENT_STATUS_LABEL.review,
    };
    const chunks = Array.isArray(knowledgeItem?.chunks) ? knowledgeItem.chunks : [];
    const totalChunksCount = Number(knowledgeItem?.chunk_count ?? chunks.length);
    const readyChunksCount = chunks.filter((chunk) => getChunkStatus(chunk) === 'ready').length;
    const activeLabel = knowledgeItem?.is_active_label ?? (knowledgeItem?.is_active ? knowledgeShowLabels.activeLabel : knowledgeShowLabels.inactiveLabel);
    const chunkReviewCounts = chunks.reduce((accumulator, chunk) => {
        accumulator[getChunkReviewStatus(chunk)] = (accumulator[getChunkReviewStatus(chunk)] ?? 0) + 1;

        return accumulator;
    }, {
        pending_review: 0,
        approved: 0,
        rejected: 0,
    });
    const graphicSequenceByChunkId = useMemo(() => {
        const sequenceByChunkId = new Map();
        let graphicSequence = 0;

        chunks.forEach((chunk) => {
            if (chunk?.chunk_type !== 'image') {
                return;
            }

            graphicSequence += 1;
            sequenceByChunkId.set(chunk.id, graphicSequence);
        });

        return sequenceByChunkId;
    }, [chunks]);
    const tableSequenceByChunkId = useMemo(() => {
        const sequenceByChunkId = new Map();
        let tableSequence = 0;

        chunks.forEach((chunk) => {
            if (chunk?.chunk_type !== 'table') {
                return;
            }

            tableSequence += 1;
            sequenceByChunkId.set(chunk.id, tableSequence);
        });

        return sequenceByChunkId;
    }, [chunks]);
    const filteredChunks = useMemo(() => {
        const q = normalizeSearchText(chunkSearchQuery);
        if (!q) return chunks;
        return chunks.filter((chunk, index) => {
            const displayTitle = normalizeSearchText(
                getChunkDisplayTitle(chunk, index, graphicSequenceByChunkId.get(chunk.id) ?? 0, tableSequenceByChunkId.get(chunk.id) ?? 0),
            );
            return (
                displayTitle.includes(q) ||
                normalizeSearchText(chunk.content_preview).includes(q) ||
                normalizeSearchText(chunk.title).includes(q) ||
                normalizeSearchText(chunk.ai_summary).includes(q) ||
                normalizeSearchText(chunk.keywords).includes(q)
            );
        });
    }, [chunks, chunkSearchQuery, graphicSequenceByChunkId, tableSequenceByChunkId]);

    useEffect(() => {
        if (!chunkSearchQuery || filteredChunks.length === 0) return;
        if (!filteredChunks.some((c) => c.id === selectedChunkId)) {
            setSelectedChunkId(filteredChunks[0].id);
        }
    }, [filteredChunks, chunkSearchQuery, selectedChunkId]);

    const reviewProgressCount = chunkReviewCounts.approved + chunkReviewCounts.rejected;
    const chunkCountText = totalChunksCount > 0
        ? formatTemplate(knowledgeShowLabels.chunkCountLabel, { count: totalChunksCount })
        : tks.no_chunks;
    const reviewProgressText = totalChunksCount > 0
        ? formatTemplate(knowledgeShowLabels.reviewProgressLabel, { done: reviewProgressCount, total: totalChunksCount })
        : tks.no_chunks_available;
    const selectedChunk = chunks.find((chunk) => chunk.id === selectedChunkId) ?? chunks[0] ?? null;
    const selectedChunkIndex = selectedChunk ? chunks.findIndex((chunk) => chunk.id === selectedChunk.id) : -1;
    const selectedChunkReviewStatus = selectedChunk ? getChunkReviewStatus(selectedChunk) : 'pending_review';
    const selectedChunkReviewStatusMeta = CHUNK_REVIEW_STATUS_META[selectedChunkReviewStatus] ?? CHUNK_REVIEW_STATUS_META.pending_review;
    const selectedGraphicSequence = selectedChunk ? (graphicSequenceByChunkId.get(selectedChunk.id) ?? 0) : 0;
    const selectedTableSequence = selectedChunk ? (tableSequenceByChunkId.get(selectedChunk.id) ?? 0) : 0;
    const selectedChunkDisplayTitle = selectedChunk ? getChunkDisplayTitle(selectedChunk, selectedChunkIndex, selectedGraphicSequence, selectedTableSequence) : 'Chunk';
    const selectedChunkImageExtension = selectedChunk ? getImageChunkExtension(selectedChunk) : null;
    const selectedChunkImageCanPreview = selectedChunk ? canPreviewImageChunk(selectedChunk) : false;
    const selectedChunkTableWarnings = normalizeTableWarningsForDisplay(selectedChunk?.table_warnings);
    const progressPercent = totalChunksCount > 0
        ? Math.round((readyChunksCount / totalChunksCount) * 100)
        : 0;
    const summaryInitialText = normalizeSearchText(knowledgeItem?.summary).length > 0
        ? String(knowledgeItem.summary)
        : '';
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
    const chunkContentForm = useForm({
        content: '',
        table_text: '',
        table_markdown: '',
        table_html: '',
        image: null,
        image_alt_text: '',
        image_caption: '',
        ocr_text: '',
        image_description: '',
    });
    const summaryHasOverflow = normalizeSearchText(summaryForm.data.summary).length > 180 || summaryForm.data.summary.includes('\n');
    const revisionEntries = Array.isArray(knowledgeItem?.revisions) ? knowledgeItem.revisions : [];
    const processHistoryEntries = buildHistoryEntries(knowledgeItem, locale, documentStatus, knowledgeShowLabels);

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
            setIsChunkContentEditing(false);
            setShowChunkSystemMetadata(false);
            return;
        }

        chunkMetadataForm.setData('title', selectedChunk.chunk_type === 'image'
            ? getGraphicDisplayTitle(selectedChunk, selectedGraphicSequence)
            : (selectedChunk.chunk_type === 'table' ? getTableDisplayTitle(selectedChunk, selectedTableSequence) : (selectedChunk.title ?? '')));
        chunkMetadataForm.setData('ai_summary', selectedChunk.ai_summary ?? '');
        chunkMetadataForm.setData('service_product_tag', selectedChunk.service_product_tag ?? '');
        chunkMetadataForm.setData('theme_tag', selectedChunk.theme_tag ?? '');
        chunkMetadataForm.setData('topic', selectedChunk.topic ?? '');
        chunkMetadataForm.setData('sub_topic', selectedChunk.sub_topic ?? '');
        chunkMetadataForm.setData('keywords', normalizeChunkKeywordList(selectedChunk.keywords).join(', '));
        chunkContentForm.setData({
            content: getChunkEditableContent(selectedChunk),
            table_text: selectedChunk.table_text ?? '',
            table_markdown: selectedChunk.table_markdown ?? '',
            table_html: selectedChunk.table_html ?? '',
            image: null,
            image_alt_text: getGraphicAltText(selectedChunk),
            image_caption: getGraphicCaption(selectedChunk),
            ocr_text: selectedChunk.ocr_text ?? '',
            image_description: selectedChunk.image_description ?? '',
        });
        setIsChunkMetadataEditing(false);
        setIsChunkContentEditing(false);
        setShowChunkSystemMetadata(false);
    }, [
        selectedChunk?.id,
        selectedChunkIndex,
        selectedGraphicSequence,
        selectedTableSequence,
        selectedChunk?.title,
        selectedChunk?.ai_summary,
        selectedChunk?.service_product_tag,
        selectedChunk?.theme_tag,
        selectedChunk?.topic,
        selectedChunk?.sub_topic,
        selectedChunk?.keywords,
        selectedChunk?.content,
        selectedChunk?.table_text,
        selectedChunk?.table_markdown,
        selectedChunk?.table_html,
        selectedChunk?.image_alt_text,
        selectedChunk?.image_caption,
        selectedChunk?.ocr_text,
        selectedChunk?.image_description,
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

        chunkMetadataForm.setData('title', selectedChunk.chunk_type === 'image'
            ? getGraphicDisplayTitle(selectedChunk, selectedGraphicSequence)
            : (selectedChunk.chunk_type === 'table' ? getTableDisplayTitle(selectedChunk, selectedTableSequence) : (selectedChunk.title ?? '')));
        chunkMetadataForm.setData('ai_summary', selectedChunk.ai_summary ?? '');
        chunkMetadataForm.setData('service_product_tag', selectedChunk.service_product_tag ?? '');
        chunkMetadataForm.setData('theme_tag', selectedChunk.theme_tag ?? '');
        chunkMetadataForm.setData('topic', selectedChunk.topic ?? '');
        chunkMetadataForm.setData('sub_topic', selectedChunk.sub_topic ?? '');
        chunkMetadataForm.setData('keywords', normalizeChunkKeywordList(selectedChunk.keywords).join(', '));
        chunkMetadataForm.clearErrors();
        setIsChunkMetadataEditing(false);
    };

    const beginChunkContentEdit = () => {
        if (!selectedChunk) {
            return;
        }

        setIsChunkContentEditing(true);
    };

    const cancelChunkContentEdit = () => {
        if (!selectedChunk) {
            return;
        }

        chunkContentForm.setData({
            content: getChunkEditableContent(selectedChunk),
            table_text: selectedChunk.table_text ?? '',
            table_markdown: selectedChunk.table_markdown ?? '',
            table_html: selectedChunk.table_html ?? '',
            image: null,
            image_alt_text: getGraphicAltText(selectedChunk),
            image_caption: getGraphicCaption(selectedChunk),
            ocr_text: selectedChunk.ocr_text ?? '',
            image_description: selectedChunk.image_description ?? '',
        });
        chunkContentForm.clearErrors();
        setIsChunkContentEditing(false);
    };

    const submitChunkContent = (event) => {
        event.preventDefault();

        const updateUrl = selectedChunk?.content_update_url ?? selectedChunk?.metadata_update_url;

        if (!selectedChunk || !updateUrl || isChunkContentSaving) {
            return;
        }

        let payload = {
            _method: 'patch',
        };

        if (selectedChunk.chunk_type === 'image') {
            payload = {
                ...payload,
                image: chunkContentForm.data.image,
                image_alt_text: chunkContentForm.data.image_alt_text,
                image_caption: chunkContentForm.data.image_caption,
                ocr_text: chunkContentForm.data.ocr_text,
                image_description: chunkContentForm.data.image_description,
            };

            if (String(chunkContentForm.data.content ?? '').trim() !== getGraphicSearchableContent(selectedChunk).trim()) {
                payload.content = chunkContentForm.data.content;
            }
        } else if (selectedChunk.chunk_type === 'table') {
            payload.table_text = chunkContentForm.data.table_text;
        } else {
            payload.content = chunkContentForm.data.content;
        }

        chunkContentForm.clearErrors();
        setIsChunkContentSaving(true);

        router.post(updateUrl, payload, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                setIsChunkContentEditing(false);
            },
            onError: (errors) => {
                chunkContentForm.setError(errors);
            },
            onFinish: () => {
                setIsChunkContentSaving(false);
            },
        });
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
        { label: tks.chunk_id, value: selectedChunk.id ?? '—' },
        { label: tks.document_id, value: selectedChunk.knowledge_item_id ?? knowledgeItem?.id ?? '—' },
        { label: tks.section, value: selectedChunk.section_title || '—' },
        { label: tks.section_path, value: selectedChunk.section_path || '—' },
        { label: tks.chunk_index, value: selectedChunk.chunk_index !== null && selectedChunk.chunk_index !== undefined ? selectedChunk.chunk_index + 1 : '—' },
        { label: tks.position_start, value: selectedChunk.start_offset !== null && selectedChunk.start_offset !== undefined ? selectedChunk.start_offset + 1 : '—' },
        { label: tks.position_end, value: selectedChunk.end_offset !== null && selectedChunk.end_offset !== undefined ? selectedChunk.end_offset : '—' },
        { label: tks.source, value: selectedChunk.source_filename ?? knowledgeItem?.original_filename ?? '—' },
        { label: tks.file_extension, value: selectedChunk.source_filetype ?? knowledgeItem?.mime_type ?? '—' },
        { label: tks.review_status, value: selectedChunk.review_status_label ?? selectedChunk.review_status ?? '—' },
        { label: tks.embedding_model, value: selectedChunk.embedding_model ?? '—' },
        { label: tks.embedding_generated, value: selectedChunk.embedding_generated_at ? formatDateTime(selectedChunk.embedding_generated_at, locale) : '—' },
        ...(selectedChunk.chunk_type === 'image' ? [
            { label: tks.graphic_url, value: selectedChunk.image_url || '—' },
            { label: tks.graphic_file, value: selectedChunk.image_original_filename || '—' },
            { label: tks.graphic_path, value: selectedChunk.image_path || '—' },
            { label: tks.storage_disk, value: selectedChunk.image_disk || '—' },
            { label: tks.mime_type, value: selectedChunk.image_mime_type || '—' },
            { label: tks.file_extension, value: selectedChunkImageExtension || '—' },
            { label: tks.graphic_kind, value: selectedChunk.image_metadata?.image_kind || selectedChunk.image_metadata?.detected_type || tks.unknown_value },
            { label: tks.width, value: selectedChunk.image_width ?? '—' },
            { label: tks.height, value: selectedChunk.image_height ?? '—' },
            { label: tks.hash, value: selectedChunk.image_hash || '—' },
            { label: tks.graphic_metadata, value: selectedChunk.image_metadata ? tks.available : '—' },
        ] : []),
    ] : [];

    return (
        <CustomerAppLayout title={resolvedPageTitle} showPageTitle={false}>
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
                                        {tks.breadcrumb}
                                    </div>
                                    <h1 className="max-w-4xl text-4xl font-semibold tracking-tight text-slate-950">
                                        {documentTitle}
                                    </h1>
                                    <div className="flex flex-wrap gap-2">
                                        <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-600">
                                            {knowledgeItem?.document_type_label ?? '—'}
                                        </span>
                                        {ownershipLabel !== '' ? (
                                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600">
                                                {ownershipLabel}
                                            </span>
                                        ) : null}
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
                                            {chunkCountText}
                                        </span>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-500">
                                        <span>
                                            {tks.last_updated}: <span className="font-medium text-slate-900">{formatDateTime(knowledgeItem?.updated_at ?? knowledgeItem?.uploaded_at, locale)}</span>
                                        </span>
                                        <span>
                                            {knowledgeShowLabels.documentOwnerLabel}: <span className="font-medium text-slate-900">{ownerDisplayName}</span>
                                        </span>
                                        {uploadedByName !== '' && uploadedByName !== ownerDisplayName ? (
                                            <span>
                                                {tks.uploaded_by_label ?? 'Opplastet av'}: <span className="font-medium text-slate-900">{uploadedByName}</span>
                                            </span>
                                        ) : null}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-3 lg:justify-end">
                            <Link
                                href={indexUrl}
                                className="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                            >
                                {tks.back}
                            </Link>
                            <Link
                                href={editUrl}
                                className="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                            >
                                {tks.edit_metadata}
                            </Link>
                            <button
                                type="button"
                                onClick={openChunksTab}
                                className="inline-flex items-center justify-center rounded-2xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-violet-700"
                            >
                                {tks.continue_review}
                            </button>
                        </div>
                    </div>
                </section>

                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <article className="h-full sm:col-span-2 xl:col-span-2 rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <form onSubmit={submitSummary} className="flex h-full flex-col">
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                    {tks.doc_summary}
                                </div>
                                {summaryHasOverflow ? (
                                    <span className="inline-flex shrink-0 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-medium text-slate-600">
                                        {tks.more}
                                    </span>
                                ) : null}
                            </div>

                            <textarea
                                value={summaryForm.data.summary}
                                onChange={(event) => summaryForm.setData('summary', event.target.value)}
                                rows={2}
                                placeholder={tks.summary_placeholder}
                                className="mt-3 h-[92px] w-full resize-none rounded-[18px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-700 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            />

                            <div className="mt-3 flex items-end justify-between gap-3">
                                <p className="max-w-[15rem] text-xs leading-5 text-slate-500">
                                    {tks.summary_hint}
                                </p>
                                <button
                                    type="submit"
                                    disabled={summaryForm.processing}
                                    className="inline-flex items-center justify-center rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {summaryForm.processing ? tks.saving : tks.save}
                                </button>
                            </div>
                        </form>
                    </article>

                    <article className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                            {tks.status_progress}
                        </div>
                        <div className="mt-3 flex items-center justify-between gap-3">
                            <span className={classNames(
                                'inline-flex rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset',
                                documentStatusMeta.className,
                            )}>
                                {documentStatusMeta.label}
                            </span>
                            <span className="text-sm font-medium text-slate-700">
                                {totalChunksCount > 0 ? `${readyChunksCount} / ${totalChunksCount} ${tks.meta_chunks}` : tks.no_chunks_yet}
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
                                ? formatTemplate(tks.extraction_progress, { percent: progressPercent })
                                : tks.extraction_incomplete}
                        </p>
                    </article>

                    <article className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                            {tks.doc_info}
                        </div>
                        <dl className="mt-3 space-y-3 text-sm">
                            <div className="flex items-start justify-between gap-4">
                                <dt className="text-slate-500">{tks.doc_type}</dt>
                                <dd className="text-right font-medium text-slate-950">{knowledgeItem?.document_type_label ?? '—'}</dd>
                            </div>
                            {documentThemeLabel !== '' ? (
                                <div className="flex items-start justify-between gap-4">
                                    <dt className="text-slate-500">Tema</dt>
                                    <dd className="text-right font-medium text-slate-950">{documentThemeLabel}</dd>
                                </div>
                            ) : null}
                            {ownershipLabel !== '' ? (
                                <div className="flex items-start justify-between gap-4">
                                    <dt className="text-slate-500">Tilhørighet</dt>
                                    <dd className="text-right font-medium text-slate-950">{ownershipLabel}</dd>
                                </div>
                            ) : null}
                            {ownerName !== '' ? (
                                <div className="flex items-start justify-between gap-4">
                                    <dt className="text-slate-500">Eier</dt>
                                    <dd className="text-right font-medium text-slate-950">{ownerName}</dd>
                                </div>
                            ) : null}
                            {owningSavedNoticeTitle !== '' ? (
                                <div className="flex items-start justify-between gap-4">
                                    <dt className="text-slate-500">Sak</dt>
                                    <dd className="text-right font-medium text-slate-950">{owningSavedNoticeTitle}</dd>
                                </div>
                            ) : null}
                            <div className="flex items-start justify-between gap-4">
                                <dt className="text-slate-500">{tks.doc_activity}</dt>
                                <dd className="text-right font-medium text-slate-950">{activeLabel}</dd>
                            </div>
                            <div className="flex items-start justify-between gap-4">
                                <dt className="text-slate-500">{tks.doc_file_size}</dt>
                                <dd className="text-right font-medium text-slate-950">{formatFileSize(knowledgeItem?.file_size_bytes)}</dd>
                            </div>
                            <div className="flex items-start justify-between gap-4">
                                <dt className="text-slate-500">{tks.doc_file_type}</dt>
                                <dd className="text-right font-medium text-slate-950">{formatFileTypeLabel(knowledgeItem?.mime_type, knowledgeShowLabels)}</dd>
                            </div>
                            <div className="flex items-start justify-between gap-4">
                                <dt className="text-slate-500">{knowledgeShowLabels.documentOwnerLabel}</dt>
                                <dd className="text-right font-medium text-slate-950">{ownerDisplayName}</dd>
                            </div>
                            <div className="flex items-start justify-between gap-4">
                                <dt className="text-slate-500">{tks.doc_last_updated}</dt>
                                <dd className="text-right font-medium text-slate-950">{formatDateTime(knowledgeItem?.updated_at ?? knowledgeItem?.uploaded_at, locale)}</dd>
                            </div>
                        </dl>
                    </article>
                </section>

                <section
                    ref={tabsRef}
                    className="rounded-[22px] border border-slate-200 bg-slate-50/70 p-2 shadow-[0_8px_24px_rgba(15,23,42,0.04)]"
                >
                    <div className="flex items-center gap-2">
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
                        {activeTab === 'chunks' && chunks.length > 0 ? (
                            <div className="relative ml-auto">
                                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400">
                                    <path fillRule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.452 4.391l3.328 3.329a.75.75 0 1 1-1.06 1.06l-3.329-3.328A7 7 0 0 1 2 9Z" clipRule="evenodd" />
                                </svg>
                                <input
                                    type="search"
                                    value={chunkSearchQuery}
                                    onChange={(e) => setChunkSearchQuery(e.target.value)}
                                    placeholder={tks.search_chunks}
                                    className="h-9 w-56 rounded-full border border-slate-300 bg-white pl-8 pr-4 text-sm text-slate-900 placeholder:text-slate-500 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                />
                            </div>
                        ) : null}
                    </div>
                </section>

                <section className="rounded-[22px] border border-slate-200 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    {activeTab === 'chunks' ? (
                        <div className="space-y-5">
                            <div className="rounded-[20px] border border-slate-200 bg-slate-50/70 p-4">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="flex flex-wrap gap-2">
                                        <span className="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700">
                                        {tks.review_approved} {chunkReviewCounts.approved}
                                        </span>
                                        <span className="inline-flex rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700">
                                            {tks.review_pending} {chunkReviewCounts.pending_review}
                                        </span>
                                        <span className="inline-flex rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-medium text-rose-700">
                                            {tks.review_rejected} {chunkReviewCounts.rejected}
                                        </span>
                                    </div>

                                    <div className="text-sm font-medium text-slate-600">
                                        {reviewProgressText}
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
                                        {tks.no_chunks_empty_title}
                                    </div>
                                    <p className="mt-2 text-sm text-slate-500">
                                        {tks.no_chunks_empty_hint}
                                    </p>
                                </div>
                            ) : (
                                <div className="grid gap-5 xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
                                    <div className="rounded-[20px] border border-slate-200 bg-slate-50/70 p-4 xl:flex xl:max-h-[calc(100vh-14rem)] xl:flex-col xl:overflow-hidden">
                                        <div className="flex items-center justify-between gap-3 xl:shrink-0">
                                            <div>
                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                    {tks.chunks_list_heading}
                                                </div>
                                                <div className="mt-1 text-sm text-slate-500">
                                                    {tks.chunks_list_hint}
                                                </div>
                                            </div>
                                            <div className="text-xs font-medium text-slate-500">
                                                {chunkSearchQuery
                                                    ? `${filteredChunks.length} av ${chunks.length} ${tks.meta_chunks}`
                                                    : `${chunks.length} ${tks.meta_chunks}`}
                                            </div>
                                        </div>

                                        <div className="mt-4 space-y-3 xl:min-h-0 xl:overflow-y-auto xl:pr-2">
                                            {filteredChunks.length === 0 && chunkSearchQuery ? (
                                                <div className="rounded-[18px] border border-dashed border-slate-200 bg-slate-50 px-5 py-7 text-center text-sm text-slate-500">
                                                    {formatTemplate(tks.no_chunks_match, { query: chunkSearchQuery })}
                                                </div>
                                            ) : null}
                                            {filteredChunks.map((chunk) => {
                                                const isSelected = selectedChunk?.id === chunk.id;
                                                const reviewStatusMeta = getChunkReviewStatusMeta(chunk);
                                                const previewText = chunk.content_preview || tks.no_preview;
                                                const originalIndex = chunks.findIndex((c) => c.id === chunk.id);
                                                const q = normalizeSearchText(chunkSearchQuery);

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
                                                            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                                                                {isSelected ? (
                                                                    <span className="inline-flex shrink-0 rounded-full border border-violet-200 bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-700">
                                                                        {tks.selected_badge}
                                                                    </span>
                                                                ) : null}
                                                                <span className="inline-flex shrink-0 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-medium text-slate-600">
                                                                    {formatTemplate(knowledgeShowLabels.chunkNumberLabel, { number: originalIndex + 1 })}
                                                                </span>
                                                                <span className="inline-flex shrink-0 rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                                                                    {getChunkTypeLabel(chunk, knowledgeShowLabels)}
                                                                </span>
                                                                {isChunkMetadataPending(chunk) ? (
                                                                    <span className="inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700">
                                                                        <span className="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-amber-400" />
                                                                        {tks.metadata_generating}
                                                                    </span>
                                                                ) : null}
                                                            </div>

                                                            <span className={classNames(
                                                                'inline-flex shrink-0 rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset',
                                                                reviewStatusMeta.className,
                                                            )}>
                                                                {reviewStatusMeta.label}
                                                            </span>
                                                        </div>

                                                        <div className="mt-3 text-sm font-medium text-slate-950">
                                                            {highlightText(getChunkDisplayTitle(chunk, originalIndex, graphicSequenceByChunkId.get(chunk.id) ?? 0, tableSequenceByChunkId.get(chunk.id) ?? 0, knowledgeShowLabels), q)}
                                                        </div>

                                                        <p className="mt-2 max-h-24 overflow-hidden text-sm leading-6 text-slate-600">
                                                            {highlightText(previewText, q)}
                                                        </p>

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
                                                <div className="space-y-2">
                                                    <div className="flex items-center justify-between gap-3">
                                                        <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                            {tks.selected_chunk_heading}
                                                        </div>
                                                        <div className="flex shrink-0 gap-1.5">
                                                            <button
                                                                type="button"
                                                                onClick={() => updateSelectedChunkReviewStatus('approved')}
                                                                disabled={chunkReviewRequest?.chunkId === selectedChunk.id}
                                                                className={classNames(
                                                                    'inline-flex items-center justify-center rounded-lg px-3 py-1 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-60',
                                                                    selectedChunkReviewStatus === 'approved'
                                                                        ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                                                                        : 'border border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-100',
                                                                )}
                                                            >
                                                                    {chunkReviewRequest?.chunkId === selectedChunk.id && chunkReviewRequest.reviewStatus === 'approved'
                                                                    ? tks.saving
                                                                    : tks.approve}
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={() => updateSelectedChunkReviewStatus('rejected')}
                                                                disabled={chunkReviewRequest?.chunkId === selectedChunk.id}
                                                                className={classNames(
                                                                    'inline-flex items-center justify-center rounded-lg px-3 py-1 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-60',
                                                                    selectedChunkReviewStatus === 'rejected'
                                                                        ? 'bg-rose-600 text-white hover:bg-rose-700'
                                                                        : 'border border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100',
                                                                )}
                                                            >
                                                                    {chunkReviewRequest?.chunkId === selectedChunk.id && chunkReviewRequest.reviewStatus === 'rejected'
                                                                    ? tks.saving
                                                                    : tks.reject}
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={() => updateSelectedChunkReviewStatus('pending_review')}
                                                                disabled={chunkReviewRequest?.chunkId === selectedChunk.id}
                                                                className={classNames(
                                                                    'inline-flex items-center justify-center rounded-lg px-3 py-1 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-60',
                                                                    selectedChunkReviewStatus === 'pending_review'
                                                                        ? 'bg-amber-600 text-white hover:bg-amber-700'
                                                                        : 'border border-amber-200 bg-amber-50 text-amber-700 hover:border-amber-300 hover:bg-amber-100',
                                                                )}
                                                            >
                                                                {chunkReviewRequest?.chunkId === selectedChunk.id && chunkReviewRequest.reviewStatus === 'pending_review'
                                                                    ? tks.saving
                                                                    : tks.mark_review}
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <h2 className="text-lg font-semibold tracking-tight text-slate-950">
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
                                                            {selectedChunkIndex >= 0
                                                                ? formatTemplate(knowledgeShowLabels.chunkNumberLabel, { number: selectedChunkIndex + 1 })
                                                                : knowledgeShowLabels.chunkLabel}
                                                        </span>
                                                        <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600">
                                                            {getChunkTypeLabel(selectedChunk, knowledgeShowLabels)}
                                                        </span>
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
                                                                {tks.embedding_ready}
                                                            </span>
                                                        ) : null}
                                                        {selectedChunk.embedding_error ? (
                                                            <span className="inline-flex rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-rose-700">
                                                                {tks.embedding_failed}
                                                            </span>
                                                        ) : null}
                                                    </div>

                                                    {selectedChunk.chunk_type === 'image' ? (
                                                        <div className="mt-4 max-h-[32rem] overflow-auto rounded-[18px] border border-slate-200 bg-white p-4">
                                                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                {tks.graphic_section}
                                                            </div>
                                                            {selectedChunk.image_url && selectedChunkImageCanPreview ? (
                                                                <div className="mt-4 overflow-hidden rounded-[16px] border border-slate-200 bg-slate-50">
                                                                    <img
                                                                        src={selectedChunk.image_url}
                                                                        alt={getGraphicAltText(selectedChunk) || getGraphicCaption(selectedChunk) || selectedChunkDisplayTitle || tks.graphic_section}
                                                                        className="block max-h-[22rem] w-full object-contain"
                                                                    />
                                                                </div>
                                                            ) : (
                                                                <div className="mt-4 rounded-[16px] border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm text-slate-500">
                                                                    {selectedChunk.image_url && !selectedChunkImageCanPreview
                                                                        ? tks.graphic_not_previewable
                                                                        : tks.no_preview}
                                                                </div>
                                                            )}

                                                            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                                                <div className="rounded-[14px] border border-slate-200 bg-slate-50/70 p-3">
                                                                    <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        {tks.graphic_caption}
                                                                    </div>
                                                                    <div className="mt-2 text-sm leading-6 text-slate-700">
                                                                        {getGraphicCaption(selectedChunk) || tks.no_graphic_caption}
                                                                    </div>
                                                                </div>
                                                                <div className="rounded-[14px] border border-slate-200 bg-slate-50/70 p-3">
                                                                    <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        {tks.graphic_alt_text}
                                                                    </div>
                                                                    <div className="mt-2 text-sm leading-6 text-slate-700">
                                                                        {getGraphicAltText(selectedChunk) || tks.no_graphic_alt_text}
                                                                    </div>
                                                                </div>
                                                                <div className="rounded-[14px] border border-slate-200 bg-slate-50/70 p-3">
                                                                    <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        {tks.graphic_ocr}
                                                                    </div>
                                                                    <div className="mt-2 text-sm leading-6 text-slate-700">
                                                                        {selectedChunk.ocr_text ? tks.ocr_done : tks.ocr_not_done}
                                                                    </div>
                                                                </div>
                                                                <div className="rounded-[14px] border border-slate-200 bg-slate-50/70 p-3">
                                                                    <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        {tks.graphic_description_label}
                                                                    </div>
                                                                    <div className="mt-2 text-sm leading-6 text-slate-700">
                                                                        {selectedChunk.image_description ? tks.graphic_description_done : tks.graphic_description_not_done}
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            <div className="mt-4 rounded-[16px] border border-slate-200 bg-slate-50/70 p-4">
                                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                    {tks.searchable_text}
                                                                </div>
                                                                <pre className="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-700">
                                                                    {getGraphicSearchableContent(selectedChunk) || tks.no_searchable_text}
                                                                </pre>
                                                            </div>

                                                            {selectedChunk.image_metadata ? (
                                                                <details className="mt-4 rounded-[16px] border border-slate-200 bg-slate-50/70 p-4">
                                                                    <summary className="cursor-pointer text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        {tks.graphic_metadata}
                                                                    </summary>
                                                                    <pre className="mt-3 whitespace-pre-wrap break-words text-xs leading-5 text-slate-600">
                                                                        {JSON.stringify(selectedChunk.image_metadata, null, 2)}
                                                                    </pre>
                                                                </details>
                                                            ) : null}
                                                        </div>
                                                    ) : selectedChunk.chunk_type === 'table' ? (
                                                        <div className="mt-4 max-h-[28rem] overflow-auto rounded-[18px] border border-slate-200 bg-white p-4">
                                                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                {tks.table_section}
                                                            </div>
                                                            {selectedChunk.table_complexity === 'complex' ? (
                                                                <div className="mt-3 rounded-[14px] border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                                                    {tks.complex_table_warning}
                                                                </div>
                                                            ) : null}
                                                            {selectedChunkTableWarnings.length > 0 ? (
                                                                <div className="mt-3 flex flex-wrap gap-2">
                                                                    {selectedChunkTableWarnings.map((warning) => (
                                                                        <span
                                                                            key={warning}
                                                                            className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-medium text-slate-600"
                                                                        >
                                                                            {warning}
                                                                        </span>
                                                                    ))}
                                                                </div>
                                                            ) : null}
                                                            {selectedChunk.table_html ? (
                                                                <div
                                                                    className="mt-4 overflow-x-auto rounded-[14px]"
                                                                    dangerouslySetInnerHTML={{ __html: selectedChunk.table_html }}
                                                                />
                                                            ) : selectedChunk.table_json ? (
                                                                <StructuredTablePreview tableJson={selectedChunk.table_json} />
                                                            ) : selectedChunk.table_text ? (
                                                                <pre className="mt-4 whitespace-pre-wrap text-sm leading-6 text-slate-700">
                                                                    {selectedChunk.table_text}
                                                                </pre>
                                                            ) : (
                                                                <pre className="mt-4 whitespace-pre-wrap text-sm leading-6 text-slate-700">
                                                                    {selectedChunk.table_markdown || tks.no_table_view}
                                                                </pre>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <div className="mt-4 max-h-[28rem] overflow-auto rounded-[18px] border border-slate-200 bg-white p-4">
                                                            <p className="whitespace-pre-wrap text-sm leading-7 text-slate-700">
                                                                {selectedChunk.content || selectedChunk.content_preview || tks.no_preview}
                                                            </p>
                                                        </div>
                                                    )}
                                                </div>

                                                <div className="rounded-[20px] border border-slate-200 bg-white p-5">
                                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                                        <div>
                                                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                {tks.chunk_content_heading}
                                                            </div>
                                                            <p className="mt-1 text-sm text-slate-500">
                                                                {tks.chunk_content_hint}
                                                            </p>
                                                        </div>

                                                        {!isChunkContentEditing ? (
                                                            <button
                                                                type="button"
                                                                onClick={beginChunkContentEdit}
                                                                className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                            >
                                                                {tks.edit_content}
                                                            </button>
                                                        ) : null}
                                                    </div>

                                                    {isChunkContentEditing ? (
                                                        <form onSubmit={submitChunkContent} className="mt-4 space-y-4">
                                                            {selectedChunk.chunk_type === 'image' ? (
                                                                <div className="grid gap-4 sm:grid-cols-2">
                                                                    <label className="space-y-2 sm:col-span-2">
                                                                        <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                    {tks.replace_graphic}
                                                                        </span>
                                                                        <input
                                                                            type="file"
                                                                            accept="image/jpeg,image/png,image/webp,image/gif"
                                                                            onChange={(event) => chunkContentForm.setData('image', event.target.files?.[0] ?? null)}
                                                                            className="block w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition file:mr-4 file:rounded-xl file:border-0 file:bg-violet-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-violet-700 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        {chunkContentForm.errors.image ? (
                                                                            <p className="text-xs text-rose-600">{chunkContentForm.errors.image}</p>
                                                                        ) : null}
                                                                    </label>
                                                                    <label className="space-y-2">
                                                                        <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                            {tks.graphic_caption_label}
                                                                        </span>
                                                                        <input
                                                                            type="text"
                                                                            value={chunkContentForm.data.image_caption}
                                                                            onChange={(event) => chunkContentForm.setData('image_caption', event.target.value)}
                                                                            className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                    </label>
                                                                    <label className="space-y-2">
                                                                        <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                            {tks.alt_text_label}
                                                                        </span>
                                                                        <input
                                                                            type="text"
                                                                            value={chunkContentForm.data.image_alt_text}
                                                                            onChange={(event) => chunkContentForm.setData('image_alt_text', event.target.value)}
                                                                            className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                    </label>
                                                                    <label className="space-y-2 sm:col-span-2">
                                                                        <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                            {tks.ocr_text_label}
                                                                        </span>
                                                                        <textarea
                                                                            value={chunkContentForm.data.ocr_text}
                                                                            onChange={(event) => chunkContentForm.setData('ocr_text', event.target.value)}
                                                                            rows={5}
                                                                            className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        {chunkContentForm.errors.ocr_text ? (
                                                                            <p className="text-xs text-rose-600">{chunkContentForm.errors.ocr_text}</p>
                                                                        ) : null}
                                                                    </label>
                                                                    <label className="space-y-2 sm:col-span-2">
                                                                        <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                            {tks.graphic_description_edit_label}
                                                                        </span>
                                                                        <textarea
                                                                            value={chunkContentForm.data.image_description}
                                                                            onChange={(event) => chunkContentForm.setData('image_description', event.target.value)}
                                                                            rows={5}
                                                                            className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        {chunkContentForm.errors.image_description ? (
                                                                            <p className="text-xs text-rose-600">{chunkContentForm.errors.image_description}</p>
                                                                        ) : null}
                                                                    </label>
                                                                    <label className="space-y-2 sm:col-span-2">
                                                                        <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                            {tks.searchable_text_edit_label}
                                                                        </span>
                                                                        <textarea
                                                                            value={chunkContentForm.data.content}
                                                                            onChange={(event) => chunkContentForm.setData('content', event.target.value)}
                                                                            rows={6}
                                                                            className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        {chunkContentForm.errors.content ? (
                                                                            <p className="text-xs text-rose-600">{chunkContentForm.errors.content}</p>
                                                                        ) : null}
                                                                    </label>
                                                                </div>
                                                            ) : selectedChunk.chunk_type === 'table' ? (
                                                                <label className="block space-y-2">
                                                                    <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        {tks.table_text_label}
                                                                    </span>
                                                                    <textarea
                                                                        value={chunkContentForm.data.table_text}
                                                                        onChange={(event) => chunkContentForm.setData('table_text', event.target.value)}
                                                                        rows={10}
                                                                        className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                    />
                                                                    {chunkContentForm.errors.table_text ? (
                                                                        <p className="text-xs text-rose-600">{chunkContentForm.errors.table_text}</p>
                                                                    ) : null}
                                                                </label>
                                                            ) : (
                                                                <label className="block space-y-2">
                                                                    <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        {tks.text_content_label}
                                                                    </span>
                                                                    <textarea
                                                                        value={chunkContentForm.data.content}
                                                                        onChange={(event) => chunkContentForm.setData('content', event.target.value)}
                                                                        rows={10}
                                                                        className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                    />
                                                                    {chunkContentForm.errors.content ? (
                                                                        <p className="text-xs text-rose-600">{chunkContentForm.errors.content}</p>
                                                                    ) : null}
                                                                </label>
                                                            )}

                                                            <div className="flex flex-wrap gap-2">
                                                                <button
                                                                    type="button"
                                                                    onClick={cancelChunkContentEdit}
                                                                    className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                                >
                                                                    {tks.cancel}
                                                                </button>
                                                                <button
                                                                    type="submit"
                                                                    disabled={isChunkContentSaving}
                                                                    className="inline-flex items-center justify-center rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                                >
                                                                    {isChunkContentSaving ? tks.saving : tks.save_content}
                                                                </button>
                                                            </div>
                                                        </form>
                                                    ) : null}
                                                </div>

                                                <div className="rounded-[20px] border border-slate-200 bg-white p-5">
                                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                                        <div>
                                                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                {tks.product_metadata_heading}
                                                            </div>
                                                            <p className="mt-1 text-sm text-slate-500">
                                                                {tks.product_metadata_hint}
                                                            </p>
                                                        </div>

                                                        {!isChunkMetadataEditing ? (
                                                            <button
                                                                type="button"
                                                                onClick={beginChunkMetadataEdit}
                                                                className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                            >
                                                                {tks.edit_metadata}
                                                            </button>
                                                        ) : (
                                                            <div className="flex flex-wrap gap-2">
                                                                <button
                                                                    type="button"
                                                                    onClick={cancelChunkMetadataEdit}
                                                                    className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                                >
                                                                    {tks.cancel}
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={submitChunkMetadata}
                                                                    disabled={chunkMetadataForm.processing}
                                                                    className="inline-flex items-center justify-center rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                                >
                                                                    {chunkMetadataForm.processing ? tks.saving : tks.save_metadata}
                                                                </button>
                                                            </div>
                                                        )}
                                                    </div>

                                                    {!isChunkMetadataEditing ? (
                                                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                                            {isChunkMetadataPending(selectedChunk) ? (
                                                                <div className="sm:col-span-2 flex items-center gap-3 rounded-[18px] border border-amber-200 bg-amber-50 px-4 py-3">
                                                                    <span className="inline-block h-2 w-2 shrink-0 animate-pulse rounded-full bg-amber-400" />
                                                                    <p className="text-sm text-amber-800">
                                                                        {tks.ai_metadata_generating}
                                                                    </p>
                                                                </div>
                                                            ) : null}
                                                            <div className="rounded-[18px] border border-slate-200 bg-slate-50/70 p-4 sm:col-span-2">
                                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                    {tks.chunk_title_label}
                                                                </div>
                                                                <div className="mt-2 text-sm font-medium text-slate-950">
                                                                    {selectedChunk.title ?? tks.no_title}
                                                                </div>
                                                            </div>
                                                            <div className="rounded-[18px] border border-slate-200 bg-slate-50/70 p-4 sm:col-span-2">
                                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                    {tks.ai_summary_label}
                                                                </div>
                                                                <div className="mt-2 max-h-32 overflow-auto whitespace-pre-wrap text-sm leading-6 text-slate-700">
                                                                    {selectedChunk.ai_summary || tks.no_ai_summary}
                                                                </div>
                                                            </div>
                                                            <div className="rounded-[18px] border border-slate-200 bg-slate-50/70 p-4">
                                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                    {tks.service_product_tag}
                                                                </div>
                                                                <div className="mt-2 text-sm font-medium text-slate-950">
                                                                    {selectedChunk.service_product_tag || '—'}
                                                                </div>
                                                            </div>
                                                            <div className="rounded-[18px] border border-slate-200 bg-slate-50/70 p-4">
                                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                    {tks.theme_tag}
                                                                </div>
                                                                <div className="mt-2 text-sm font-medium text-slate-950">
                                                                    {selectedChunk.theme_tag || '—'}
                                                                </div>
                                                            </div>
                                                            <div className="rounded-[18px] border border-slate-200 bg-slate-50/70 p-4">
                                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                    {tks.topic_placeholder}
                                                                </div>
                                                                <div className="mt-2 text-sm font-medium text-slate-950">
                                                                    {selectedChunk.topic || '—'}
                                                                </div>
                                                            </div>
                                                            <div className="rounded-[18px] border border-slate-200 bg-slate-50/70 p-4">
                                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                    {tks.sub_topic_placeholder}
                                                                </div>
                                                                <div className="mt-2 text-sm font-medium text-slate-950">
                                                                    {selectedChunk.sub_topic || '—'}
                                                                </div>
                                                            </div>
                                                            <div className="rounded-[18px] border border-slate-200 bg-slate-50/70 p-4 sm:col-span-2">
                                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                    {tks.keywords_placeholder}
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
                                                                        {tks.chunk_title_label}
                                                                    </span>
                                                                    <input
                                                                        type="text"
                                                                        value={chunkMetadataForm.data.title}
                                                                        onChange={(event) => chunkMetadataForm.setData('title', event.target.value)}
                                                                        placeholder={tks.title_placeholder}
                                                                        className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                    />
                                                                </label>

                                                                <label className="space-y-2 sm:col-span-2">
                                                                    <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        {tks.ai_summary_label}
                                                                    </span>
                                                                    <textarea
                                                                        value={chunkMetadataForm.data.ai_summary}
                                                                        onChange={(event) => chunkMetadataForm.setData('ai_summary', event.target.value)}
                                                                        rows={4}
                                                                        placeholder={tks.summary_edit_placeholder}
                                                                        className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                    />
                                                                </label>

                                                                <label className="space-y-2">
                                                                    <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        {tks.service_product_tag}
                                                                    </span>
                                                                    <input
                                                                        type="text"
                                                                        value={chunkMetadataForm.data.service_product_tag}
                                                                        onChange={(event) => chunkMetadataForm.setData('service_product_tag', event.target.value)}
                                                                        placeholder={tks.service_product_placeholder}
                                                                        className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                    />
                                                                </label>

                                                                <label className="space-y-2">
                                                                    <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        {tks.theme_tag}
                                                                    </span>
                                                                    <input
                                                                        type="text"
                                                                        value={chunkMetadataForm.data.theme_tag}
                                                                        onChange={(event) => chunkMetadataForm.setData('theme_tag', event.target.value)}
                                                                        placeholder={tks.theme_placeholder}
                                                                        className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                    />
                                                                </label>

                                                                <label className="space-y-2">
                                                                    <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        {tks.topic_placeholder}
                                                                    </span>
                                                                    <input
                                                                        type="text"
                                                                        value={chunkMetadataForm.data.topic}
                                                                        onChange={(event) => chunkMetadataForm.setData('topic', event.target.value)}
                                                                        placeholder={tks.topic_placeholder}
                                                                        className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                    />
                                                                </label>

                                                                <label className="space-y-2">
                                                                    <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        {tks.sub_topic_placeholder}
                                                                    </span>
                                                                    <input
                                                                        type="text"
                                                                        value={chunkMetadataForm.data.sub_topic}
                                                                        onChange={(event) => chunkMetadataForm.setData('sub_topic', event.target.value)}
                                                                        placeholder={tks.sub_topic_placeholder}
                                                                        className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                    />
                                                                </label>

                                                                <label className="space-y-2 sm:col-span-2">
                                                                    <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        {tks.keywords_placeholder}
                                                                    </span>
                                                                    <input
                                                                        type="text"
                                                                        value={chunkMetadataForm.data.keywords}
                                                                        onChange={(event) => chunkMetadataForm.setData('keywords', event.target.value)}
                                                                        placeholder={tks.keywords_placeholder}
                                                                        className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                    />
                                                                    <p className="text-xs text-slate-500">
                                                                        {tks.keywords_json_hint}
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
                                                                {tks.system_data_heading}
                                                            </div>
                                                            <p className="mt-1 text-sm text-slate-500">
                                                                {tks.system_data_hint}
                                                            </p>
                                                        </div>

                                                        <button
                                                            type="button"
                                                            onClick={() => setShowChunkSystemMetadata((current) => !current)}
                                                            className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                        >
                                                            {showChunkSystemMetadata ? tks.hide_system_data : tks.show_system_data}
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
                                                                ? formatTemplate(knowledgeShowLabels.chunkCounterLabel, { current: selectedChunkIndex + 1, total: chunks.length })
                                                                : knowledgeShowLabels.chunkLabel}
                                                    </div>

                                                    <button
                                                        type="button"
                                                        onClick={goToNextChunk}
                                                        disabled={selectedChunkIndex < 0 || selectedChunkIndex >= chunks.length - 1}
                                                        className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                            {selectedChunkIndex >= 0 && selectedChunkIndex < chunks.length - 1
                                                                ? tks.next_chunk
                                                                : tks.last_chunk}
                                                    </button>
                                                </div>
                                            </div>
                                        ) : (
                                            <div className="rounded-[20px] border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center">
                                                <div className="text-lg font-semibold text-slate-900">
                                                    {tks.no_chunk_selected}
                                                </div>
                                                <p className="mt-2 text-sm text-slate-500">
                                                    {tks.no_chunk_selected_hint}
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
                                    <dt className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">{tks.meta_type}</dt>
                                    <dd className="mt-2 text-sm font-medium text-slate-950">{knowledgeItem?.document_type_label ?? '—'}</dd>
                                </div>
                                <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                    <dt className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">{tks.meta_status}</dt>
                                    <dd className="mt-2 text-sm font-medium text-slate-950">{documentStatusMeta.label}</dd>
                                </div>
                                <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                    <dt className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">{tks.meta_activity}</dt>
                                    <dd className="mt-2 text-sm font-medium text-slate-950">{activeLabel}</dd>
                                </div>
                                <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                    <dt className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">{tks.meta_file_size}</dt>
                                    <dd className="mt-2 text-sm font-medium text-slate-950">{formatFileSize(knowledgeItem?.file_size_bytes)}</dd>
                                </div>
                                <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                    <dt className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">{tks.meta_mime}</dt>
                                    <dd className="mt-2 text-sm font-medium text-slate-950">{knowledgeItem?.mime_type ?? '—'}</dd>
                                </div>
                                <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                    <dt className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">{knowledgeShowLabels.documentOwnerLabel}</dt>
                                    <dd className="mt-2 text-sm font-medium text-slate-950">{ownerDisplayName}</dd>
                                </div>
                                <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                    <dt className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">{tks.meta_chunks}</dt>
                                    <dd className="mt-2 text-sm font-medium text-slate-950">
                                        {totalChunksCount > 0 ? `${readyChunksCount} av ${totalChunksCount}` : tks.no_chunks}
                                    </dd>
                                </div>
                                <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                    <dt className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">{tks.meta_last_updated}</dt>
                                    <dd className="mt-2 text-sm font-medium text-slate-950">{formatDateTime(knowledgeItem?.updated_at ?? knowledgeItem?.uploaded_at, locale)}</dd>
                                </div>
                            </dl>
                        </div>
                    ) : null}

                    {activeTab === 'history' ? (
                        <div className="space-y-6">
                            <section className="space-y-3">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                            Revisjoner
                                        </div>
                                        <p className="mt-1 text-sm text-slate-500">
                                            Read-only historikk for lagrede dokumentendringer.
                                        </p>
                                    </div>
                                </div>

                                {revisionEntries.length > 0 ? (
                                    <div className="space-y-3">
                                        {revisionEntries.map((revision) => (
                                            <div
                                                key={revision.id}
                                                className="flex flex-col gap-4 rounded-[20px] border border-slate-200 bg-white px-5 py-4 shadow-sm sm:flex-row sm:items-start sm:justify-between"
                                            >
                                                <div className="space-y-2">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="inline-flex rounded-full border border-violet-200 bg-violet-50 px-2.5 py-1 text-xs font-semibold text-violet-700">
                                                            Revisjon {revision.revision_no}
                                                        </span>
                                                        <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-medium text-slate-600">
                                                            {getRevisionChangeTypeLabel(revision.change_type)}
                                                        </span>
                                                    </div>

                                                    <dl className="grid gap-x-6 gap-y-2 text-sm text-slate-600 sm:grid-cols-2">
                                                        <div className="space-y-0.5">
                                                            <dt className="text-xs font-medium uppercase tracking-[0.14em] text-slate-400">
                                                                Endringstype
                                                            </dt>
                                                            <dd className="font-medium text-slate-900">
                                                                {getRevisionChangeTypeLabel(revision.change_type)}
                                                            </dd>
                                                        </div>
                                                        <div className="space-y-0.5">
                                                            <dt className="text-xs font-medium uppercase tracking-[0.14em] text-slate-400">
                                                                Tidspunkt
                                                            </dt>
                                                            <dd className="font-medium text-slate-900">
                                                                {formatDateTime(revision.created_at, locale)}
                                                            </dd>
                                                        </div>
                                                        <div className="space-y-0.5 sm:col-span-2">
                                                            <dt className="text-xs font-medium uppercase tracking-[0.14em] text-slate-400">
                                                                Bruker
                                                            </dt>
                                                            <dd className="font-medium text-slate-900">
                                                                {revision.changed_by_name || '—'}
                                                            </dd>
                                                        </div>
                                                    </dl>
                                                </div>

                                                <div className="text-sm font-medium text-slate-500">
                                                    #{revision.revision_no}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <div className="rounded-[20px] border border-dashed border-slate-300 bg-slate-50 px-5 py-4 text-sm text-slate-500">
                                        Ingen revisjoner registrert ennå.
                                    </div>
                                )}
                            </section>

                            <section className="space-y-3">
                                <div>
                                    <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                        Prosesshistorikk
                                    </div>
                                    <p className="mt-1 text-sm text-slate-500">
                                        Opplasting, lagring og ekstraksjon for dokumentet.
                                    </p>
                                </div>

                                {processHistoryEntries.length > 0 ? (
                                    <div className="space-y-3">
                                        {processHistoryEntries.map((entry) => (
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
                    ) : null}
                </section>

            </div>
        </CustomerAppLayout>
    );
}
