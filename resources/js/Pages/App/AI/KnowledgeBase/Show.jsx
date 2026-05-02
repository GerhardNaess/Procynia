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


/**
 * Purpose: Escape pasted table cell text before rendering a local preview.
 * Inputs: Raw cell text from clipboard data.
 * Returns: HTML-safe text.
 * Side effects: None.
 */
function escapeHtmlText(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


const PASTED_TABLE_ALLOWED_TAGS = new Set([
    'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
    'br', 'p', 'div', 'span', 'strong', 'b', 'em', 'i', 'u', 'sub', 'sup',
]);

const PASTED_TABLE_ALLOWED_STYLES = new Set([
    'text-align', 'vertical-align', 'font-weight', 'font-style', 'text-decoration',
    'background', 'background-color', 'color', 'border', 'border-top', 'border-right',
    'border-bottom', 'border-left', 'border-color', 'border-style', 'border-width',
    'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
    'white-space', 'width', 'height', 'border-collapse',
]);

/**
 * Purpose: Preserve safe visual table formatting from Word or Excel paste HTML.
 * Inputs: Raw inline style text from a pasted table node.
 * Returns: A sanitized inline style value or an empty string.
 * Side effects: None.
 */
function sanitizePastedTableStyle(styleValue) {
    return String(styleValue ?? '')
        .split(';')
        .map((stylePart) => stylePart.trim())
        .filter((stylePart) => stylePart.includes(':'))
        .map((stylePart) => {
            const separatorIndex = stylePart.indexOf(':');
            const property = stylePart.slice(0, separatorIndex).trim().toLowerCase();
            const value = stylePart.slice(separatorIndex + 1).trim();
            const lowerValue = value.toLowerCase();

            if (!PASTED_TABLE_ALLOWED_STYLES.has(property)) {
                return null;
            }

            if (lowerValue.includes('url(') || lowerValue.includes('expression(') || lowerValue.includes('javascript:')) {
                return null;
            }

            return `${property}: ${value}`;
        })
        .filter(Boolean)
        .join('; ');
}

/**
 * Purpose: Copy one pasted table node while preserving only safe table markup and basic formatting.
 * Inputs: Source DOM node and the target document used to create sanitized nodes.
 * Returns: A sanitized DOM node or fragment.
 * Side effects: None.
 */
function sanitizePastedTableNode(sourceNode, targetDocument) {
    if (sourceNode.nodeType === Node.TEXT_NODE) {
        return targetDocument.createTextNode(sourceNode.textContent ?? '');
    }

    if (sourceNode.nodeType !== Node.ELEMENT_NODE) {
        return targetDocument.createDocumentFragment();
    }

    const tagName = sourceNode.tagName.toLowerCase();

    if (!PASTED_TABLE_ALLOWED_TAGS.has(tagName)) {
        const fragment = targetDocument.createDocumentFragment();

        Array.from(sourceNode.childNodes).forEach((childNode) => {
            fragment.appendChild(sanitizePastedTableNode(childNode, targetDocument));
        });

        return fragment;
    }

    const sanitizedNode = targetDocument.createElement(tagName);

    Array.from(sourceNode.attributes).forEach((attribute) => {
        const attributeName = attribute.name.toLowerCase();
        const attributeValue = String(attribute.value ?? '').trim();

        if (attributeName === 'style') {
            const style = sanitizePastedTableStyle(attributeValue);

            if (style !== '') {
                sanitizedNode.setAttribute('style', style);
            }

            return;
        }

        if ((attributeName === 'colspan' || attributeName === 'rowspan') && ['th', 'td'].includes(tagName)) {
            const spanValue = Math.max(1, Math.min(attributeName === 'colspan' ? 20 : 100, Number.parseInt(attributeValue, 10) || 1));
            sanitizedNode.setAttribute(attributeName, String(spanValue));
            return;
        }

        if (attributeName === 'scope' && ['th', 'td'].includes(tagName) && /^(row|col|rowgroup|colgroup)$/.test(attributeValue)) {
            sanitizedNode.setAttribute(attributeName, attributeValue);
            return;
        }

        if (attributeName === 'align' && /^(left|center|right|justify)$/.test(attributeValue)) {
            sanitizedNode.setAttribute(attributeName, attributeValue);
            return;
        }

        if (attributeName === 'valign' && /^(top|middle|bottom|baseline)$/.test(attributeValue)) {
            sanitizedNode.setAttribute(attributeName, attributeValue);
            return;
        }

        if ((attributeName === 'width' || attributeName === 'height') && /^[0-9]+(\.[0-9]+)?(%|px)?$/.test(attributeValue)) {
            sanitizedNode.setAttribute(attributeName, attributeValue);
        }
    });

    Array.from(sourceNode.childNodes).forEach((childNode) => {
        sanitizedNode.appendChild(sanitizePastedTableNode(childNode, targetDocument));
    });

    return sanitizedNode;
}

/**
 * Purpose: Extract the pasted table HTML while preserving safe formatting from Word or Excel.
 * Inputs: Raw clipboard HTML.
 * Returns: Sanitized table HTML or an empty string when no table exists.
 * Side effects: None.
 */
function sanitizePastedTableHtml(html) {
    const rawHtml = String(html ?? '').trim();

    if (rawHtml === '' || !rawHtml.toLowerCase().includes('<table')) {
        return '';
    }

    const sourceDocument = new DOMParser().parseFromString(rawHtml, 'text/html');
    const tableNode = sourceDocument.querySelector('table');

    if (!tableNode) {
        return '';
    }

    const targetDocument = document.implementation.createHTMLDocument('');
    const container = targetDocument.createElement('div');
    container.appendChild(sanitizePastedTableNode(tableNode, targetDocument));

    return container.innerHTML;
}

/**
 * Purpose: Normalize one pasted table cell into plain searchable text.
 * Inputs: Raw text from a Word or Excel cell.
 * Returns: A squished cell value.
 * Side effects: None.
 */
function normalizePastedTableCellText(value) {
    return String(value ?? '').replace(/\s+/g, ' ').trim();
}

/**
 * Purpose: Extract table rows from HTML copied from Word, Excel, or a browser table.
 * Inputs: Raw clipboard HTML.
 * Returns: A two-dimensional row/cell array.
 * Side effects: None.
 */
function extractPastedTableRowsFromHtml(html) {
    const rawHtml = String(html ?? '').trim();

    if (rawHtml === '' || !rawHtml.toLowerCase().includes('<table')) {
        return [];
    }

    const documentNode = new DOMParser().parseFromString(rawHtml, 'text/html');
    const tableNode = documentNode.querySelector('table');

    if (!tableNode) {
        return [];
    }

    return Array.from(tableNode.querySelectorAll('tr'))
        .map((rowNode) => Array.from(rowNode.querySelectorAll('th,td'))
            .map((cellNode) => normalizePastedTableCellText(cellNode.textContent))
            .filter((cellText) => cellText !== ''))
        .filter((row) => row.length > 0);
}

/**
 * Purpose: Extract table rows from plain-text clipboard data when HTML table data is unavailable.
 * Inputs: Raw clipboard plain text.
 * Returns: A two-dimensional row/cell array.
 * Side effects: None.
 */
function extractPastedTableRowsFromText(text) {
    const rawText = String(text ?? '').trim();

    if (rawText === '') {
        return [];
    }

    return rawText
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line !== '')
        .map((line) => {
            if (line.includes('\t')) {
                return line.split('\t').map((cellText) => normalizePastedTableCellText(cellText));
            }

            return line.split(/\s{2,}/).map((cellText) => normalizePastedTableCellText(cellText));
        })
        .map((row) => row.filter((cellText) => cellText !== ''))
        .filter((row) => row.length > 0);
}

/**
 * Purpose: Convert pasted table rows into tab-separated text for storage and retrieval.
 * Inputs: A two-dimensional row/cell array.
 * Returns: Plain table text.
 * Side effects: None.
 */
function pastedTableRowsToText(rows) {
    return rows.map((row) => row.join('\t')).join('\n');
}

/**
 * Purpose: Convert pasted table rows into markdown for legacy table fallback rendering.
 * Inputs: A two-dimensional row/cell array.
 * Returns: Markdown table text.
 * Side effects: None.
 */
function pastedTableRowsToMarkdown(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
        return '';
    }

    const columnCount = Math.max(...rows.map((row) => row.length));
    const markdownRows = [];

    rows.forEach((row, rowIndex) => {
        const cells = [];

        for (let cellIndex = 0; cellIndex < columnCount; cellIndex += 1) {
            cells.push(String(row[cellIndex] ?? '').replace(/\|/g, '\\|'));
        }

        markdownRows.push(`| ${cells.join(' | ')} |`);

        if (rowIndex === 0) {
            markdownRows.push(`| ${Array.from({ length: columnCount }, () => '---').join(' | ')} |`);
        }
    });

    return markdownRows.join('\n');
}

/**
 * Purpose: Convert pasted table rows into simple sanitized HTML for preview and backend parsing.
 * Inputs: A two-dimensional row/cell array.
 * Returns: Minimal table HTML.
 * Side effects: None.
 */
function pastedTableRowsToHtml(rows) {
    const htmlRows = rows.map((row, rowIndex) => {
        const cellTagName = rowIndex === 0 ? 'th' : 'td';
        const cells = row.map((cellText) => `<${cellTagName}>${escapeHtmlText(cellText)}</${cellTagName}>`).join('');

        return `<tr>${cells}</tr>`;
    });

    return `<table><tbody>${htmlRows.join('')}</tbody></table>`;
}

/**
 * Purpose: Build normalized table payload fields from a Word or Excel paste event.
 * Inputs: A clipboard event from the table paste drop zone.
 * Returns: Table text, markdown, and HTML values, or null when no table was detected.
 * Side effects: None.
 */
function buildPastedTablePayloadFromClipboard(clipboardData) {
    const html = clipboardData?.getData('text/html') ?? '';
    const text = clipboardData?.getData('text/plain') ?? '';
    const rows = extractPastedTableRowsFromHtml(html);
    const fallbackRows = rows.length > 0 ? rows : extractPastedTableRowsFromText(text);
    const sanitizedTableHtml = sanitizePastedTableHtml(html);

    if (fallbackRows.length === 0) {
        return null;
    }

    return {
        table_text: pastedTableRowsToText(fallbackRows),
        table_markdown: pastedTableRowsToMarkdown(fallbackRows),
        table_html: sanitizedTableHtml !== '' ? sanitizedTableHtml : pastedTableRowsToHtml(fallbackRows),
        content: pastedTableRowsToText(fallbackRows),
    };
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

function getChunkTypeLabel(chunk) {
    if (chunk?.chunk_type === 'image') {
        return 'Bilde';
    }

    if (chunk?.chunk_type === 'table') {
        return 'Tabell';
    }

    if (chunk?.chunk_type === 'document') {
        return 'Dokument';
    }

    return 'Tekst';
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
    const [isChunkContentEditing, setIsChunkContentEditing] = useState(false);
    const [isChunkContentSaving, setIsChunkContentSaving] = useState(false);
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
    const selectedChunkImageExtension = selectedChunk ? getImageChunkExtension(selectedChunk) : null;
    const selectedChunkImageCanPreview = selectedChunk ? canPreviewImageChunk(selectedChunk) : false;
    const selectedChunkTableWarnings = normalizeTableWarningsForDisplay(selectedChunk?.table_warnings);
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
            setIsChunkContentEditing(false);
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
        chunkContentForm.setData({
            content: selectedChunk.content ?? '',
            table_text: selectedChunk.table_text ?? '',
            table_markdown: selectedChunk.table_markdown ?? '',
            table_html: selectedChunk.table_html ?? '',
            image: null,
            image_alt_text: selectedChunk.image_alt_text ?? '',
            image_caption: selectedChunk.image_caption ?? '',
            ocr_text: selectedChunk.ocr_text ?? '',
            image_description: selectedChunk.image_description ?? '',
        });
        setIsChunkMetadataEditing(false);
        setIsChunkContentEditing(false);
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
            content: selectedChunk.content ?? '',
            table_text: selectedChunk.table_text ?? '',
            table_markdown: selectedChunk.table_markdown ?? '',
            table_html: selectedChunk.table_html ?? '',
            image: null,
            image_alt_text: selectedChunk.image_alt_text ?? '',
            image_caption: selectedChunk.image_caption ?? '',
            ocr_text: selectedChunk.ocr_text ?? '',
            image_description: selectedChunk.image_description ?? '',
        });
        chunkContentForm.clearErrors();
        setIsChunkContentEditing(false);
    };

    /**
     * Purpose: Replace the current table editor state with a structured table pasted from Word or Excel.
     * Inputs: Clipboard paste event from the table paste drop zone.
     * Returns: None.
     * Side effects: Updates only local form state until the user clicks Save.
     */
    const handleTablePaste = (event) => {
        const pastedTablePayload = buildPastedTablePayloadFromClipboard(event.clipboardData);

        if (!pastedTablePayload) {
            return;
        }

        event.preventDefault();
        chunkContentForm.setData({
            ...chunkContentForm.data,
            ...pastedTablePayload,
        });
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

            if (String(chunkContentForm.data.content ?? '').trim() !== String(selectedChunk.content ?? '').trim()) {
                payload.content = chunkContentForm.data.content;
            }
        } else if (selectedChunk.chunk_type === 'table') {
            payload.table_text = chunkContentForm.data.table_text;
            payload.table_markdown = chunkContentForm.data.table_markdown;
            payload.table_html = chunkContentForm.data.table_html;
            payload.content = chunkContentForm.data.content || chunkContentForm.data.table_text;
        } else {
            payload.content = chunkContentForm.data.content;
        }

        chunkContentForm.clearErrors();
        setIsChunkContentSaving(true);

        router.post(updateUrl, payload, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                chunkContentForm.setData('image', null);
                setIsChunkContentEditing(false);
                router.reload({ only: ['knowledgeItem'] });
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
        ...(selectedChunk.chunk_type === 'image' ? [
            { label: 'Bilde-URL', value: selectedChunk.image_url || '—' },
            { label: 'Bildefil', value: selectedChunk.image_original_filename || '—' },
            { label: 'Bildefilsti', value: selectedChunk.image_path || '—' },
            { label: 'Lagringsdisk', value: selectedChunk.image_disk || '—' },
            { label: 'MIME-type', value: selectedChunk.image_mime_type || '—' },
            { label: 'Filtype', value: selectedChunkImageExtension || '—' },
            { label: 'Bildetype', value: selectedChunk.image_metadata?.image_kind || selectedChunk.image_metadata?.detected_type || 'unknown' },
            { label: 'Bredde', value: selectedChunk.image_width ?? '—' },
            { label: 'Høyde', value: selectedChunk.image_height ?? '—' },
            { label: 'Hash', value: selectedChunk.image_hash || '—' },
            { label: 'Bildemetadata', value: selectedChunk.image_metadata ? 'Tilgjengelig' : '—' },
        ] : []),
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
                                    <div className="rounded-[20px] border border-slate-200 bg-slate-50/70 p-4 xl:flex xl:max-h-[calc(100vh-14rem)] xl:flex-col xl:overflow-hidden">
                                        <div className="flex items-center justify-between gap-3 xl:shrink-0">
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

                                        <div className="mt-4 space-y-3 xl:min-h-0 xl:overflow-y-auto xl:pr-2">
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
                                                                    <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                                                                        {getChunkTypeLabel(chunk)}
                                                                    </span>
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
                                                            <span className="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600">
                                                                {getChunkTypeLabel(selectedChunk)}
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

                                                    {selectedChunk.chunk_type === 'image' ? (
                                                        <div className="mt-4 max-h-[32rem] overflow-auto rounded-[18px] border border-slate-200 bg-white p-4">
                                                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                Bilde
                                                            </div>
                                                            {selectedChunk.image_url && selectedChunkImageCanPreview ? (
                                                                <div className="mt-4 overflow-hidden rounded-[16px] border border-slate-200 bg-slate-50">
                                                                    <img
                                                                        src={selectedChunk.image_url}
                                                                        alt={selectedChunk.image_alt_text || selectedChunk.image_caption || selectedChunk.title || 'Bilde'}
                                                                        className="block max-h-[22rem] w-full object-contain"
                                                                    />
                                                                </div>
                                                            ) : (
                                                                <div className="mt-4 rounded-[16px] border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm text-slate-500">
                                                                    {selectedChunk.image_url && !selectedChunkImageCanPreview
                                                                        ? 'Bildet er ekstrahert, men formatet kan ikke forhåndsvises direkte.'
                                                                        : 'Ingen forhåndsvisning tilgjengelig.'}
                                                                </div>
                                                            )}

                                                            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                                                <div className="rounded-[14px] border border-slate-200 bg-slate-50/70 p-3">
                                                                    <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        Bildetekst
                                                                    </div>
                                                                    <div className="mt-2 text-sm leading-6 text-slate-700">
                                                                        {selectedChunk.image_caption || 'Ingen bildetekst registrert.'}
                                                                    </div>
                                                                </div>
                                                                <div className="rounded-[14px] border border-slate-200 bg-slate-50/70 p-3">
                                                                    <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        Alt-tekst
                                                                    </div>
                                                                    <div className="mt-2 text-sm leading-6 text-slate-700">
                                                                        {selectedChunk.image_alt_text || 'Ingen alternativ tekst registrert.'}
                                                                    </div>
                                                                </div>
                                                                <div className="rounded-[14px] border border-slate-200 bg-slate-50/70 p-3">
                                                                    <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        OCR
                                                                    </div>
                                                                    <div className="mt-2 text-sm leading-6 text-slate-700">
                                                                        {selectedChunk.ocr_text ? 'OCR kjørt' : 'OCR ikke kjørt'}
                                                                    </div>
                                                                </div>
                                                                <div className="rounded-[14px] border border-slate-200 bg-slate-50/70 p-3">
                                                                    <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        Beskrivelse
                                                                    </div>
                                                                    <div className="mt-2 text-sm leading-6 text-slate-700">
                                                                        {selectedChunk.image_description ? 'Bildebeskrivelse generert' : 'Bildebeskrivelse ikke generert'}
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            <div className="mt-4 rounded-[16px] border border-slate-200 bg-slate-50/70 p-4">
                                                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                    Søkbar tekst
                                                                </div>
                                                                <pre className="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-700">
                                                                    {selectedChunk.content || selectedChunk.content_preview || 'Ingen søkbar tekst tilgjengelig.'}
                                                                </pre>
                                                            </div>

                                                            {selectedChunk.image_metadata ? (
                                                                <details className="mt-4 rounded-[16px] border border-slate-200 bg-slate-50/70 p-4">
                                                                    <summary className="cursor-pointer text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        Bildemetadata
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
                                                                Tabell
                                                            </div>
                                                            {selectedChunk.table_complexity === 'complex' ? (
                                                                <div className="mt-3 rounded-[14px] border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                                                    Kompleks tabell – struktur er bevart og bør kvalitetssikres.
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
                                                                    {selectedChunk.table_markdown || 'Ingen tabellvisning tilgjengelig.'}
                                                                </pre>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <div className="mt-4 max-h-[28rem] overflow-auto rounded-[18px] border border-slate-200 bg-white p-4">
                                                            <p className="whitespace-pre-wrap text-sm leading-7 text-slate-700">
                                                                {selectedChunk.content || selectedChunk.content_preview || 'Ingen forhåndsvisning tilgjengelig.'}
                                                            </p>
                                                        </div>
                                                    )}
                                                </div>

                                                <div className="rounded-[20px] border border-slate-200 bg-white p-5">
                                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                                        <div>
                                                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                Chunk-innhold
                                                            </div>
                                                            <p className="mt-1 text-sm text-slate-500">
                                                                Endringer reindekserer kun denne chunken og regenererer metadata i bakgrunnen.
                                                            </p>
                                                        </div>

                                                        {!isChunkContentEditing ? (
                                                            <button
                                                                type="button"
                                                                onClick={beginChunkContentEdit}
                                                                className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                            >
                                                                Rediger innhold
                                                            </button>
                                                        ) : null}
                                                    </div>

                                                    {isChunkContentEditing ? (
                                                        <form onSubmit={submitChunkContent} className="mt-4 space-y-4">
                                                            {selectedChunk.chunk_type === 'image' ? (
                                                                <div className="grid gap-4 sm:grid-cols-2">
                                                                    <label className="space-y-2 sm:col-span-2">
                                                                        <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                            Bytt bilde
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
                                                                            Bildetekst
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
                                                                            Alt-tekst
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
                                                                            Søkbar tekst
                                                                        </span>
                                                                        <textarea
                                                                            value={chunkContentForm.data.content}
                                                                            onChange={(event) => chunkContentForm.setData('content', event.target.value)}
                                                                            rows={6}
                                                                            className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                    </label>
                                                                </div>
                                                            ) : selectedChunk.chunk_type === 'table' ? (
                                                                <div className="space-y-4">
                                                                    <div
                                                                        role="textbox"
                                                                        tabIndex={0}
                                                                        onPaste={handleTablePaste}
                                                                        className="rounded-[18px] border border-dashed border-violet-300 bg-violet-50/70 px-4 py-6 text-sm leading-6 text-slate-700 outline-none transition focus:border-violet-400 focus:ring-4 focus:ring-violet-100"
                                                                    >
                                                                        <div className="font-semibold text-slate-950">
                                                                            Lim inn tabell fra Word eller Excel her
                                                                        </div>
                                                                        <p className="mt-1 text-slate-600">
                                                                            Kopier hele tabellen i Word eller Excel, klikk i dette feltet og lim inn. Procynia gjør den om til strukturert tabell før lagring.
                                                                        </p>
                                                                    </div>

                                                                    {chunkContentForm.data.table_html ? (
                                                                        <div className="overflow-x-auto rounded-[16px] border border-slate-200 bg-white p-3">
                                                                            <div
                                                                                className="prose prose-sm max-w-none prose-table:min-w-full prose-th:border prose-th:border-slate-200 prose-th:bg-slate-50 prose-th:px-3 prose-th:py-2 prose-td:border prose-td:border-slate-200 prose-td:px-3 prose-td:py-2"
                                                                                dangerouslySetInnerHTML={{ __html: chunkContentForm.data.table_html }}
                                                                            />
                                                                        </div>
                                                                    ) : null}

                                                                    <label className="block space-y-2">
                                                                        <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                            Tabelltekst
                                                                        </span>
                                                                        <textarea
                                                                            value={chunkContentForm.data.table_text}
                                                                            onChange={(event) => {
                                                                                const tableText = event.target.value;
                                                                                chunkContentForm.setData({
                                                                                    ...chunkContentForm.data,
                                                                                    table_text: tableText,
                                                                                    content: tableText,
                                                                                    table_html: '',
                                                                                    table_markdown: '',
                                                                                });
                                                                            }}
                                                                            rows={10}
                                                                            className="w-full rounded-[16px] border border-slate-200 bg-slate-50 px-4 py-3 font-mono text-sm leading-6 text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        <p className="text-xs text-slate-500">
                                                                            Tabeller som limes inn fra Word eller Excel vises strukturert over. Dette tekstfeltet brukes til søk og embedding.
                                                                        </p>
                                                                        {chunkContentForm.errors.table_text ? (
                                                                            <p className="text-xs text-rose-600">{chunkContentForm.errors.table_text}</p>
                                                                        ) : null}
                                                                        {chunkContentForm.errors.table_html ? (
                                                                            <p className="text-xs text-rose-600">{chunkContentForm.errors.table_html}</p>
                                                                        ) : null}
                                                                    </label>
                                                                </div>
                                                            ) : (
                                                                <label className="block space-y-2">
                                                                    <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                                                        Tekstinnhold
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
                                                                    Avbryt
                                                                </button>
                                                                <button
                                                                    type="submit"
                                                                    disabled={isChunkContentSaving}
                                                                    className="inline-flex items-center justify-center rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                                >
                                                                    {isChunkContentSaving ? 'Lagrer...' : 'Lagre innhold'}
                                                                </button>
                                                            </div>
                                                        </form>
                                                    ) : null}
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
