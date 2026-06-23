import { Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../../Components/App/PageHelpButton';

function formatDate(value, locale, emptyLabel = '–') {
    const parsedDate = parseDateValue(value);

    if (!parsedDate) return emptyLabel;

    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(parsedDate);
}

function parseDateValue(value) {
    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : value;
    }

    const text = String(value ?? '').trim();

    if (text === '') {
        return null;
    }

    const match = text.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2}(?:\.\d+)?)(Z|[+-]\d{2}(?::?\d{2})?)?$/);

    let normalized = text;

    if (match) {
        const [, date, time, tz = ''] = match;

        if (tz === undefined || tz === '') {
            normalized = `${date}T${time}Z`;
        } else if (tz === 'Z') {
            normalized = `${date}T${time}Z`;
        } else if (/^[+-]\d{2}$/.test(tz)) {
            normalized = `${date}T${time}${tz}:00`;
        } else if (/^[+-]\d{4}$/.test(tz)) {
            normalized = `${date}T${time}${tz.slice(0, 3)}:${tz.slice(3)}`;
        } else {
            normalized = `${date}T${time}${tz}`;
        }
    } else if (text.includes(' ') && !text.includes('T')) {
        normalized = text.replace(' ', 'T');
    }

    const parsed = new Date(normalized);

    return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function isBlankValue(value) {
    if (value === null || value === undefined) {
        return true;
    }

    if (typeof value === 'string') {
        return value.trim() === '';
    }

    return false;
}

function normalizeSortValue(value) {
    if (isBlankValue(value)) {
        return { empty: true, kind: 'empty', value: null };
    }

    if (value instanceof Date) {
        return Number.isNaN(value.getTime())
            ? { empty: true, kind: 'empty', value: null }
            : { empty: false, kind: 'number', value: value.getTime() };
    }

    if (typeof value === 'boolean') {
        return { empty: false, kind: 'number', value: value ? 1 : 0 };
    }

    if (typeof value === 'number') {
        return Number.isFinite(value)
            ? { empty: false, kind: 'number', value }
            : { empty: true, kind: 'empty', value: null };
    }

    const parsedDate = parseDateValue(value);
    if (parsedDate) {
        return { empty: false, kind: 'number', value: parsedDate.getTime() };
    }

    const numeric = Number(value);
    if (Number.isFinite(numeric) && String(value).trim() !== '') {
        return { empty: false, kind: 'number', value: numeric };
    }

    return { empty: false, kind: 'string', value: String(value) };
}

function compareSortValues(leftValue, rightValue, collator) {
    const left = normalizeSortValue(leftValue);
    const right = normalizeSortValue(rightValue);

    if (left.empty && right.empty) {
        return 0;
    }

    if (left.empty) {
        return 1;
    }

    if (right.empty) {
        return -1;
    }

    if (left.kind === 'number' && right.kind === 'number') {
        if (left.value === right.value) {
            return 0;
        }

        return left.value < right.value ? -1 : 1;
    }

    return collator.compare(String(left.value), String(right.value));
}

function sortRows(rows, sortState, accessors, tieBreaker, collator) {
    const sortedRows = [...rows];
    const field = sortState?.field ?? 'default';
    const direction = sortState?.direction === 'desc' ? -1 : 1;
    const accessor = accessors?.[field] ?? accessors?.default ?? ((row) => row);

    return sortedRows.sort((left, right) => {
        const comparison = compareSortValues(accessor(left), accessor(right), collator);

        if (comparison !== 0) {
            return comparison * direction;
        }

        return compareSortValues(tieBreaker(left), tieBreaker(right), collator);
    });
}

function getDocumentRowKey(row) {
    return String(row?.knowledge_item_id ?? '');
}

function getChunkRowKey(row) {
    return String(row?.knowledge_item_chunk_id ?? `${row?.knowledge_item_id ?? 'chunk'}-${row?.chunk_index ?? 'x'}`);
}

const APPROVAL_STATUS_CLASSES = {
    approved: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    draft: 'border-amber-200 bg-amber-50 text-amber-700',
    rejected: 'border-rose-200 bg-rose-50 text-rose-700',
};

const DOCUMENT_SORT_ACCESSORS = {
    default: (row) => String(row?.original_filename ?? row?.title ?? ''),
    name: (row) => String(row?.original_filename ?? row?.title ?? ''),
    category: (row) => String(row?.document_type ?? ''),
    version: (row) => row?.current_version_no ?? null,
    approval: (row) => String(row?.current_version_approval_status ?? ''),
    cases: (row) => row?.case_count ?? 0,
    requirements: (row) => row?.requirement_count ?? 0,
    sent_to_ai: (row) => row?.evidence_count ?? 0,
    primary: (row) => row?.primary_count ?? 0,
    score: (row) => row?.avg_match_score ?? null,
    last_used: (row) => row?.last_used_at ?? null,
    superseded: (row) => row?.evidence_on_superseded_version_count ?? 0,
};

const CHUNK_SORT_ACCESSORS = {
    default: (row) => row?.evidence_count ?? 0,
    document: (row) => String(row?.original_filename ?? ''),
    chunk: (row) => row?.chunk_index ?? null,
    type: (row) => String(row?.chunk_type ?? ''),
    section: (row) => String(row?.section_title ?? ''),
    heading: (row) => String(row?.heading_path ?? ''),
    topic: (row) => String(row?.topic ?? ''),
    version: (row) => row?.version_no_used ?? null,
    version_status: (row) => row?.version_is_current == null ? null : (row.version_is_current ? 1 : 0),
    sent_to_ai: (row) => row?.evidence_count ?? 0,
    cases: (row) => row?.case_count ?? 0,
    requirements: (row) => row?.requirement_count ?? 0,
    primary: (row) => row?.primary_count ?? 0,
    score: (row) => row?.avg_match_score ?? null,
    max_score: (row) => row?.max_match_score ?? null,
    last_used: (row) => row?.last_used_at ?? null,
};

export default function KnowledgeBaseAiUsage({
    documentUsageRows = [],
    chunkUsageRows = [],
    summary = { document_count: 0, chunk_count: 0, evidence_count: 0 },
}) {
    const pageProps = usePage().props ?? {};
    const { locale = 'nb-NO', translations = {} } = pageProps;
    const tk = translations?.knowledge ?? {};
    const safeDocumentUsageRows = Array.isArray(documentUsageRows) ? documentUsageRows : [];
    const safeChunkUsageRows = Array.isArray(chunkUsageRows) ? chunkUsageRows : [];
    const safeSummary = summary && typeof summary === 'object' ? summary : {};
    const collator = useMemo(
        () => new Intl.Collator(locale, { numeric: true, sensitivity: 'base' }),
        [locale],
    );
    const [documentSort, setDocumentSort] = useState({
        field: 'sent_to_ai',
        direction: 'desc',
    });
    const [chunkSort, setChunkSort] = useState({
        field: 'sent_to_ai',
        direction: 'desc',
    });
    const [selectedDocumentKey, setSelectedDocumentKey] = useState('');

    const pageTitle = tk.ai_usage_page_title ?? 'Bruk i AI';
    const subtitle = tk.ai_usage_subtitle ?? 'Oversikten viser Kunnskapsbase-kilder som er sendt til AI som grunnlag.';
    const helpTitle = tk.ai_usage_page_help_title ?? pageTitle;
    const helpIntro = tk.ai_usage_page_help_intro ?? 'Denne siden viser hvilke kilder fra Kunnskapsbase som er sendt til AI som grunnlag i kravarbeid og svarutkast.';
    const helpSections = [
        {
            title: tk.ai_usage_page_help_section_overview ?? 'Hva siden viser',
            items: [
                {
                    text:
                        tk.ai_usage_page_help_item_overview
                        ?? 'Oversikten viser hvilke kunnskapsdokumenter og kildeutdrag Procynia har sendt til AI som grunnlag. Dokumenttabellen viser samlet bruk per dokument, mens utdragstabellen viser de konkrete kildeutdragene som ble sendt med.',
                },
            ],
        },
        {
            title: tk.ai_usage_page_help_section_limits ?? 'Viktig avgrensning',
            items: [
                {
                    text:
                        tk.ai_usage_page_help_item_limits
                        ?? 'Siden dokumenterer hva Procynia sendte til AI som kontekst. Den viser ikke hva AI-modellen faktisk brukte, vektla eller resonnerte med internt, og den viser ikke tokenforbruk eller kostnad.',
                },
            ],
        },
        {
            title: tk.ai_usage_page_help_section_data ?? 'Datagrunnlag',
            items: [
                {
                    text:
                        tk.ai_usage_page_help_item_data
                        ?? 'Datagrunnlaget er SavedNoticeAiEvidence. Rejected evidence telles ikke. Oversikten leser ikke fra SavedNoticeAiDocument.',
                },
            ],
        },
        {
            title: tk.ai_usage_page_help_section_case_docs ?? 'Skillet mot Saksdokumenter',
            items: [
                {
                    text:
                        tk.ai_usage_page_help_item_case_docs
                        ?? 'Saksdokumenter fra konkrete anbudssaker håndteres separat i AI-arbeidsflaten for saken. De vises ikke her og er ikke en del av Kunnskapsbase-modellen.',
                },
            ],
        },
        {
            title: tk.ai_usage_page_help_section_columns ?? 'Hvordan lese kolonnene',
            items: [
                {
                    text:
                        tk.ai_usage_page_help_item_columns
                        ?? 'Saker viser antall saker der dokumentet eller utdraget er sendt til AI. Krav viser antall krav. Sendt til AI viser registrerte kildehendelser. Primærkilder viser hvor mange kilder som er markert som primærkilde. Snitt score viser gjennomsnittlig relevansscore. Sist brukt viser siste registrerte tidspunkt. Eldre versjon brukt markerer om en tidligere dokumentversjon er brukt.',
                },
            ],
        },
        {
            title: tk.ai_usage_page_help_section_empty ?? 'Tom side',
            items: [
                {
                    text:
                        tk.ai_usage_page_help_item_empty
                        ?? 'Hvis oversikten er tom, finnes det ikke registrerte Kunnskapsbase-kilder sendt til AI ennå. Det betyr ikke nødvendigvis at AI aldri er brukt, men at denne typen Kunnskapsbase-bruk ikke er logget for perioden.',
                },
            ],
        },
    ];
    const summaryDocumentsLabel = tk.ai_usage_summary_documents ?? 'Dokumenter';
    const summaryChunksLabel = tk.ai_usage_summary_chunks ?? 'Utdrag';
    const summaryEvidenceLabel = tk.ai_usage_summary_evidence ?? 'Ganger sendt til AI';
    const sectionDocuments = tk.ai_usage_section_documents ?? 'Dokumenter sendt til AI';
    const sectionChunks = tk.ai_usage_section_chunks ?? 'Utdrag sendt til AI';
    const documentSectionHint = tk.ai_usage_document_section_hint ?? 'Klikk en dokumentrad for å se hvilke utdrag som er sendt til AI.';
    const selectedDocumentLabel = tk.ai_usage_selected_document ?? 'Valgt dokument';
    const selectDocumentLabel = tk.ai_usage_select_document ?? 'Vis utdrag';
    const selectedBadgeLabel = tk.ai_usage_selected ?? 'Valgt';
    const chunkEmptySelectedLabel = tk.ai_usage_chunk_empty_selected_document ?? 'Ingen utdrag er registrert for valgt dokument ennå.';
    const chunkEmptyNoDocumentsLabel = tk.ai_usage_chunk_empty_no_documents ?? 'Ingen dokumenter er sendt til AI ennå.';
    const emptyTitle = tk.ai_usage_empty_title ?? 'Ingen loggede Kunnskapsbase-kilder er sendt til AI ennå.';
    const emptyNote = tk.ai_usage_empty_note ?? 'Eldre svarutkast kan mangle loggposter fra før brukslogging ble aktivert.';

    const documentCount = safeSummary?.document_count ?? 0;
    const chunkCount = safeSummary?.chunk_count ?? 0;
    const evidenceCount = safeSummary?.evidence_count ?? 0;
    const sortedDocumentRows = useMemo(
        () => sortRows(
            safeDocumentUsageRows,
            documentSort,
            DOCUMENT_SORT_ACCESSORS,
            getDocumentRowKey,
            collator,
        ),
        [safeDocumentUsageRows, documentSort, collator],
    );
    const activeDocumentKey = selectedDocumentKey || getDocumentRowKey(sortedDocumentRows[0] ?? null);
    const activeDocument = useMemo(
        () => sortedDocumentRows.find((row) => getDocumentRowKey(row) === activeDocumentKey) ?? null,
        [sortedDocumentRows, activeDocumentKey],
    );
    const filteredChunkRows = useMemo(
        () => (activeDocumentKey === ''
            ? []
            : safeChunkUsageRows.filter((row) => String(row?.knowledge_item_id ?? '') === activeDocumentKey)),
        [safeChunkUsageRows, activeDocumentKey],
    );
    const sortedChunkRows = useMemo(
        () => sortRows(
            filteredChunkRows,
            chunkSort,
            CHUNK_SORT_ACCESSORS,
            getChunkRowKey,
            collator,
        ),
        [filteredChunkRows, chunkSort, collator],
    );

    const toggleDocumentSort = (field) => {
        setDocumentSort((current) => ({
            field,
            direction: current.field === field && current.direction === 'asc' ? 'desc' : 'asc',
        }));
    };

    const toggleChunkSort = (field) => {
        setChunkSort((current) => ({
            field,
            direction: current.field === field && current.direction === 'asc' ? 'desc' : 'asc',
        }));
    };

    return (
        <CustomerAppLayout title={pageTitle} showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-4">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div className="space-y-2">
                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                {tk.ai_usage_nav_label ?? 'Bruk i AI'}
                            </div>
                            <div className="flex flex-wrap items-center gap-3">
                                <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                                    {pageTitle}
                                </h1>
                                <PageHelpButton
                                    buttonLabel={tk.page_help_button ?? 'Hjelp'}
                                    title={helpTitle}
                                    intro={helpIntro}
                                    sections={helpSections}
                                />
                            </div>
                            <p className="max-w-2xl text-[15px] leading-7 text-slate-500">
                                {subtitle}
                            </p>
                        </div>

                    </div>
                </section>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <SummaryCard label={summaryDocumentsLabel} value={documentCount} />
                    <SummaryCard label={summaryChunksLabel} value={chunkCount} />
                    <SummaryCard label={summaryEvidenceLabel} value={evidenceCount} />
                </div>

                <DocumentUsageSection
                    title={sectionDocuments}
                    hint={documentSectionHint}
                    rows={sortedDocumentRows}
                    sortState={documentSort}
                    onSort={toggleDocumentSort}
                    selectedDocumentKey={activeDocumentKey}
                    selectedBadgeLabel={selectedBadgeLabel}
                    selectDocumentLabel={selectDocumentLabel}
                    onSelectDocument={setSelectedDocumentKey}
                    emptyTitle={emptyTitle}
                    emptyNote={emptyNote}
                    tk={tk}
                    locale={locale}
                />
                <ChunkUsageSection
                    title={sectionChunks}
                    rows={sortedChunkRows}
                    sortState={chunkSort}
                    onSort={toggleChunkSort}
                    selectedDocument={activeDocument}
                    selectedDocumentLabel={selectedDocumentLabel}
                    emptySelectedLabel={chunkEmptySelectedLabel}
                    emptyNoDocumentsLabel={chunkEmptyNoDocumentsLabel}
                    tk={tk}
                    locale={locale}
                />
            </div>
        </CustomerAppLayout>
    );
}

function SummaryCard({ label, value }) {
    return (
        <div className="rounded-[18px] border border-slate-200 bg-white px-5 py-5 shadow-[0_4px_12px_rgba(15,23,42,0.03)]">
            <div className="text-3xl font-bold text-slate-950">{Number(value ?? 0).toLocaleString()}</div>
            <div className="mt-1 text-sm font-medium text-slate-500">{label}</div>
        </div>
    );
}

function SortableTableHeader({ label, field, sortState, onSort, align = 'left' }) {
    const isActive = sortState.field === field;
    const indicator = isActive ? (sortState.direction === 'asc' ? '↑' : '↓') : null;
    const justifyClass = align === 'right' ? 'justify-end' : 'justify-start';

    return (
        <button
            type="button"
            onClick={() => onSort(field)}
            className={`inline-flex items-center gap-1 transition hover:text-slate-700 ${justifyClass}`}
        >
            <span>{label}</span>
            {indicator ? <span>{indicator}</span> : null}
        </button>
    );
}

function ChunkUsageSection({
    title,
    rows,
    sortState,
    onSort,
    selectedDocument,
    selectedDocumentLabel,
    emptySelectedLabel,
    emptyNoDocumentsLabel,
    tk,
    locale,
}) {
    const chunkRowLabel = tk.ai_usage_chunk_row_label ?? 'Utdrag';
    const versionCurrent = tk.ai_usage_chunk_version_current ?? 'Gjeldende';
    const versionSuperseded = tk.ai_usage_chunk_version_superseded ?? 'Erstattet';
    const versionUnknown = tk.ai_usage_chunk_version_unknown ?? 'Ukjent';
    const emptyLabel = selectedDocument ? emptySelectedLabel : emptyNoDocumentsLabel;
    const selectedDocumentTitle = String(selectedDocument?.original_filename ?? selectedDocument?.title ?? '').trim();

    return (
        <section className="overflow-hidden rounded-[22px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
            <div className="border-b border-slate-200 px-5 py-4">
                <div className="flex flex-col gap-1.5">
                    <div className="flex flex-wrap items-center gap-3">
                        <h2 className="text-base font-semibold text-slate-900">{title}</h2>
                        {selectedDocument ? (
                            <span className="inline-flex rounded-full border border-violet-200 bg-violet-50 px-2.5 py-0.5 text-[10px] font-medium uppercase tracking-[0.08em] text-violet-700">
                                {selectedDocumentLabel}
                            </span>
                        ) : null}
                    </div>
                    {selectedDocument ? (
                        <p className="text-sm text-slate-500">{selectedDocumentTitle || '–'}</p>
                    ) : (
                        <p className="text-sm text-slate-500">{emptyLabel}</p>
                    )}
                </div>
            </div>

            {rows.length === 0 ? (
                <div className="px-6 py-10 text-center text-sm text-slate-500">{emptyLabel}</div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200">
                        <thead className="bg-slate-50/80">
                            <tr>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_chunk_table_col_document ?? 'Dokument'}
                                        field="document"
                                        sortState={sortState}
                                        onSort={onSort}
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_chunk_table_col_chunk ?? 'Utdrag'}
                                        field="chunk"
                                        sortState={sortState}
                                        onSort={onSort}
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_chunk_table_col_type ?? 'Type'}
                                        field="type"
                                        sortState={sortState}
                                        onSort={onSort}
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_chunk_table_col_section ?? 'Seksjon'}
                                        field="section"
                                        sortState={sortState}
                                        onSort={onSort}
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_chunk_table_col_heading ?? 'Heading'}
                                        field="heading"
                                        sortState={sortState}
                                        onSort={onSort}
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_chunk_table_col_topic ?? 'Tema'}
                                        field="topic"
                                        sortState={sortState}
                                        onSort={onSort}
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_chunk_table_col_version ?? 'Versjon'}
                                        field="version"
                                        sortState={sortState}
                                        onSort={onSort}
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_chunk_table_col_version_status ?? 'Versjonsstatus'}
                                        field="version_status"
                                        sortState={sortState}
                                        onSort={onSort}
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_chunk_table_col_sent_to_ai ?? 'Sendt til AI'}
                                        field="sent_to_ai"
                                        sortState={sortState}
                                        onSort={onSort}
                                        align="right"
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_chunk_table_col_cases ?? 'Saker'}
                                        field="cases"
                                        sortState={sortState}
                                        onSort={onSort}
                                        align="right"
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_chunk_table_col_requirements ?? 'Krav'}
                                        field="requirements"
                                        sortState={sortState}
                                        onSort={onSort}
                                        align="right"
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_chunk_table_col_primary ?? 'Primærkilder'}
                                        field="primary"
                                        sortState={sortState}
                                        onSort={onSort}
                                        align="right"
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_chunk_table_col_score ?? 'Snitt score'}
                                        field="score"
                                        sortState={sortState}
                                        onSort={onSort}
                                        align="right"
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_chunk_table_col_max_score ?? 'Maks score'}
                                        field="max_score"
                                        sortState={sortState}
                                        onSort={onSort}
                                        align="right"
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_chunk_table_col_last_used ?? 'Sist brukt'}
                                        field="last_used"
                                        sortState={sortState}
                                        onSort={onSort}
                                    />
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-200 bg-white">
                            {rows.map((row) => {
                                const showUrl = row?.knowledge_item_show_url ?? null;
                                const filename = String(row?.original_filename ?? '').trim();
                                const chunkIndex = row?.chunk_index != null && Number.isFinite(Number(row.chunk_index))
                                    ? Number(row.chunk_index) + 1
                                    : null;
                                const chunkType = String(row?.chunk_type ?? '').trim();
                                const sectionTitle = String(row?.section_title ?? '').trim();
                                const headingPath = String(row?.heading_path ?? '').trim();
                                const topic = String(row?.topic ?? '').trim();
                                const subTopic = String(row?.sub_topic ?? '').trim();
                                const versionNo = row?.version_no_used != null && row.version_no_used !== ''
                                    ? `v${row.version_no_used}`
                                    : versionUnknown;
                                const versionStatus = row?.version_is_current;
                                const versionStatusLabel = versionStatus === true
                                    ? versionCurrent
                                    : versionStatus === false
                                        ? versionSuperseded
                                        : versionUnknown;
                                const versionStatusClass = versionStatus === true
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                    : versionStatus === false
                                        ? 'border-orange-200 bg-orange-50 text-orange-700'
                                        : 'border-slate-200 bg-slate-50 text-slate-500';
                                const avgMatchScore = row?.avg_match_score != null ? Number(row.avg_match_score) : '–';
                                const maxMatchScore = row?.max_match_score != null ? Number(row.max_match_score) : '–';

                                return (
                                    <tr
                                        key={row?.knowledge_item_chunk_id ?? `${filename}-${row?.chunk_index ?? 'x'}`}
                                        className="align-top transition hover:bg-slate-50/40"
                                    >
                                        <td className="px-4 py-3.5">
                                            <div className="max-w-60">
                                                {showUrl ? (
                                                    <Link
                                                        href={showUrl}
                                                        className="font-medium text-violet-700 hover:underline"
                                                    >
                                                        {filename || '–'}
                                                    </Link>
                                                ) : (
                                                    <span className="font-medium text-slate-950">{filename || '–'}</span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3.5 text-sm text-slate-700">
                                            {chunkIndex ? `${chunkRowLabel} ${chunkIndex}` : '–'}
                                        </td>
                                        <td className="px-4 py-3.5">
                                            <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 text-[10px] font-medium uppercase tracking-[0.08em] text-slate-500">
                                                {chunkType || '–'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3.5 text-sm text-slate-700">
                                            {sectionTitle || '–'}
                                        </td>
                                        <td className="px-4 py-3.5 text-sm text-slate-700">
                                            {headingPath ? (
                                                <span className="block max-w-[18rem] truncate" title={headingPath}>
                                                    {headingPath}
                                                </span>
                                            ) : '–'}
                                        </td>
                                        <td className="px-4 py-3.5 text-sm text-slate-700">
                                            {topic || subTopic ? (
                                                <div className="space-y-0.5">
                                                    {topic ? <div>{topic}</div> : null}
                                                    {subTopic ? <div className="text-xs text-slate-500">{subTopic}</div> : null}
                                                </div>
                                            ) : (
                                                '–'
                                            )}
                                        </td>
                                        <td className="px-4 py-3.5 text-sm text-slate-700">
                                            {versionNo}
                                        </td>
                                        <td className="px-4 py-3.5">
                                            <span className={`inline-flex rounded-full border px-2.5 py-0.5 text-[10px] font-medium ${versionStatusClass}`}>
                                                {versionStatusLabel}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3.5 text-right text-sm font-medium text-slate-900">
                                            {Number(row?.evidence_count ?? 0)}
                                        </td>
                                        <td className="px-4 py-3.5 text-right text-sm text-slate-700">
                                            {Number(row?.case_count ?? 0)}
                                        </td>
                                        <td className="px-4 py-3.5 text-right text-sm text-slate-700">
                                            {Number(row?.requirement_count ?? 0)}
                                        </td>
                                        <td className="px-4 py-3.5 text-right text-sm text-slate-700">
                                            {Number(row?.primary_count ?? 0)}
                                        </td>
                                        <td className="px-4 py-3.5 text-right text-sm text-slate-700">
                                            {avgMatchScore}
                                        </td>
                                        <td className="px-4 py-3.5 text-right text-sm text-slate-700">
                                            {maxMatchScore}
                                        </td>
                                        <td className="px-4 py-3.5 text-sm text-slate-500">
                                            {formatDate(row?.last_used_at, locale)}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

function DocumentUsageSection({
    title,
    hint,
    rows,
    sortState,
    onSort,
    selectedDocumentKey,
    selectedBadgeLabel,
    selectDocumentLabel,
    onSelectDocument,
    emptyTitle,
    emptyNote,
    tk,
    locale,
}) {
    const emptyLabel = tk.ai_usage_doc_empty ?? 'Ingen Kunnskapsbase-dokumenter er logget som sendt til AI ennå.';

    return (
        <section className="overflow-hidden rounded-[22px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
            <div className="border-b border-slate-200 px-5 py-4">
                <div className="flex flex-col gap-1.5">
                    <h2 className="text-base font-semibold text-slate-900">{title}</h2>
                    <p className="text-sm text-slate-500">{hint}</p>
                </div>
            </div>

            {rows.length === 0 ? (
                <div className="px-6 py-10 text-center">
                    <div className="text-base font-medium text-slate-700">{emptyTitle ?? emptyLabel}</div>
                    <p className="mt-2 text-sm text-slate-500">{emptyNote ?? ''}</p>
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200">
                        <thead className="bg-slate-50/80">
                            <tr>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_doc_table_col_document ?? 'Dokument'}
                                        field="name"
                                        sortState={sortState}
                                        onSort={onSort}
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_doc_table_col_category ?? 'Kategori'}
                                        field="category"
                                        sortState={sortState}
                                        onSort={onSort}
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_doc_table_col_version ?? 'Versjon'}
                                        field="version"
                                        sortState={sortState}
                                        onSort={onSort}
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_doc_table_col_approval ?? 'Godkjenning'}
                                        field="approval"
                                        sortState={sortState}
                                        onSort={onSort}
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_doc_table_col_cases ?? 'Saker'}
                                        field="cases"
                                        sortState={sortState}
                                        onSort={onSort}
                                        align="right"
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_doc_table_col_requirements ?? 'Krav'}
                                        field="requirements"
                                        sortState={sortState}
                                        onSort={onSort}
                                        align="right"
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_doc_table_col_sent_to_ai ?? 'Sendt til AI'}
                                        field="sent_to_ai"
                                        sortState={sortState}
                                        onSort={onSort}
                                        align="right"
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_doc_table_col_primary ?? 'Primærkilder'}
                                        field="primary"
                                        sortState={sortState}
                                        onSort={onSort}
                                        align="right"
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_doc_table_col_score ?? 'Snitt score'}
                                        field="score"
                                        sortState={sortState}
                                        onSort={onSort}
                                        align="right"
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_doc_table_col_last_used ?? 'Sist brukt'}
                                        field="last_used"
                                        sortState={sortState}
                                        onSort={onSort}
                                    />
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    <SortableTableHeader
                                        label={tk.ai_usage_doc_table_col_superseded ?? 'Eldre versjon brukt'}
                                        field="superseded"
                                        sortState={sortState}
                                        onSort={onSort}
                                    />
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-200 bg-white">
                            {rows.map((row) => (
                                <DocumentUsageRow
                                    key={row.knowledge_item_id}
                                    row={row}
                                    tk={tk}
                                    locale={locale}
                                    isSelected={String(row?.knowledge_item_id ?? '') === selectedDocumentKey}
                                    selectedBadgeLabel={selectedBadgeLabel}
                                    selectDocumentLabel={selectDocumentLabel}
                                    onSelectDocument={onSelectDocument}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

function DocumentUsageRow({
    row,
    tk,
    locale,
    isSelected,
    selectedBadgeLabel,
    selectDocumentLabel,
    onSelectDocument,
}) {
    const displayName = String(row?.original_filename ?? row?.title ?? '').trim();
    const showUrl = row?.knowledge_item_show_url ?? null;

    const versionUnknown = tk.ai_usage_version_unknown ?? 'Ukjent';
    const versionLabel = row?.current_version_no != null ? `v${row.current_version_no}` : versionUnknown;

    const approvalStatus = String(row?.current_version_approval_status ?? '').trim();
    const approvalLabel = tk[`ai_usage_approval_${approvalStatus}`] ?? approvalStatus;
    const approvalClass = APPROVAL_STATUS_CLASSES[approvalStatus] ?? 'border-slate-200 bg-slate-50 text-slate-500';

    const supersededCount = Number(row?.evidence_on_superseded_version_count ?? 0);
    const hasSuperseded = supersededCount > 0;

    return (
        <tr className={`align-top transition ${isSelected ? 'bg-violet-50/60' : 'hover:bg-slate-50/40'}`}>
            <td className="px-4 py-3.5">
                <div className="max-w-55">
                    {showUrl ? (
                        <Link
                            href={showUrl}
                            className="font-medium text-violet-700 hover:underline"
                        >
                            {displayName || '–'}
                        </Link>
                    ) : (
                        <span className="font-medium text-slate-950">{displayName || '–'}</span>
                    )}
                    <div className="mt-1">
                        {isSelected ? (
                            <span className="inline-flex rounded-full border border-violet-200 bg-violet-50 px-2.5 py-0.5 text-[10px] font-medium text-violet-700">
                                {selectedBadgeLabel}
                            </span>
                        ) : (
                            <button
                                type="button"
                                className="text-xs font-medium text-violet-700 transition hover:underline"
                                onClick={() => onSelectDocument?.(row)}
                            >
                                {selectDocumentLabel}
                            </button>
                        )}
                    </div>
                </div>
            </td>
            <td className="px-4 py-3.5">
                <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 text-[10px] font-medium uppercase tracking-[0.08em] text-slate-500">
                    {String(row?.document_type ?? '–').trim()}
                </span>
            </td>
            <td className="px-4 py-3.5 text-sm text-slate-700">
                {versionLabel}
            </td>
            <td className="px-4 py-3.5">
                {approvalStatus !== '' ? (
                    <span className={`inline-flex rounded-full border px-2.5 py-0.5 text-[10px] font-medium ${approvalClass}`}>
                        {approvalLabel}
                    </span>
                ) : (
                    <span className="text-sm text-slate-400">–</span>
                )}
            </td>
            <td className="px-4 py-3.5 text-right text-sm text-slate-700">
                {Number(row?.case_count ?? 0)}
            </td>
            <td className="px-4 py-3.5 text-right text-sm text-slate-700">
                {Number(row?.requirement_count ?? 0)}
            </td>
            <td className="px-4 py-3.5 text-right text-sm font-medium text-slate-900">
                {Number(row?.evidence_count ?? 0)}
            </td>
            <td className="px-4 py-3.5 text-right text-sm text-slate-700">
                {Number(row?.primary_count ?? 0)}
            </td>
            <td className="px-4 py-3.5 text-right text-sm text-slate-700">
                {row?.avg_match_score != null ? Number(row.avg_match_score) : '–'}
            </td>
            <td className="px-4 py-3.5 text-sm text-slate-500">
                {formatDate(row?.last_used_at, locale)}
            </td>
            <td className="px-4 py-3.5">
                {hasSuperseded ? (
                    <span className="inline-flex rounded-full border border-orange-200 bg-orange-50 px-2.5 py-0.5 text-[10px] font-medium text-orange-700">
                        {tk.ai_usage_superseded_yes ?? 'Eldre versjon'}
                    </span>
                ) : (
                    <span className="text-sm text-slate-400">
                        {tk.ai_usage_superseded_no ?? 'Nei'}
                    </span>
                )}
            </td>
        </tr>
    );
}
