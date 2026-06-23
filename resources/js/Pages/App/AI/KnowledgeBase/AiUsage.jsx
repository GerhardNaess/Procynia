import { Link, usePage } from '@inertiajs/react';
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

const APPROVAL_STATUS_CLASSES = {
    approved: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    draft: 'border-amber-200 bg-amber-50 text-amber-700',
    rejected: 'border-rose-200 bg-rose-50 text-rose-700',
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
    const emptyTitle = tk.ai_usage_empty_title ?? 'Ingen loggede Kunnskapsbase-kilder er sendt til AI ennå.';
    const emptyNote = tk.ai_usage_empty_note ?? 'Eldre svarutkast kan mangle loggposter fra før brukslogging ble aktivert.';

    const documentCount = safeSummary?.document_count ?? 0;
    const chunkCount = safeSummary?.chunk_count ?? 0;
    const evidenceCount = safeSummary?.evidence_count ?? 0;
    const isEmpty = evidenceCount === 0;

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

                {isEmpty ? (
                    <section className="rounded-[22px] border border-slate-200 bg-white p-8 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="flex flex-col items-center gap-3 text-center">
                            <p className="text-base font-medium text-slate-700">{emptyTitle}</p>
                            <p className="max-w-lg text-sm text-slate-500">{emptyNote}</p>
                        </div>
                    </section>
                ) : (
                    <>
                        <DocumentUsageSection
                            title={sectionDocuments}
                            rows={safeDocumentUsageRows}
                            tk={tk}
                            locale={locale}
                        />
                        <ChunkUsageSection
                            title={sectionChunks}
                            rows={safeChunkUsageRows}
                            tk={tk}
                            locale={locale}
                        />
                    </>
                )}
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

function ChunkUsageSection({ title, rows, tk, locale }) {
    const emptyLabel = tk.ai_usage_chunk_empty ?? 'Ingen Kunnskapsbase-utdrag er logget som sendt til AI ennå.';
    const chunkRowLabel = tk.ai_usage_chunk_row_label ?? 'Utdrag';
    const versionCurrent = tk.ai_usage_chunk_version_current ?? 'Gjeldende';
    const versionSuperseded = tk.ai_usage_chunk_version_superseded ?? 'Erstattet';
    const versionUnknown = tk.ai_usage_chunk_version_unknown ?? 'Ukjent';

    return (
        <section className="overflow-hidden rounded-[22px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
            <div className="border-b border-slate-200 px-5 py-4">
                <h2 className="text-base font-semibold text-slate-900">{title}</h2>
            </div>

            {rows.length === 0 ? (
                <div className="px-6 py-10 text-center text-sm text-slate-500">{emptyLabel}</div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200">
                        <thead className="bg-slate-50/80">
                            <tr>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_chunk_table_col_document ?? 'Dokument'}
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_chunk_table_col_chunk ?? 'Utdrag'}
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_chunk_table_col_type ?? 'Type'}
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_chunk_table_col_section ?? 'Seksjon'}
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_chunk_table_col_heading ?? 'Heading'}
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_chunk_table_col_topic ?? 'Tema'}
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_chunk_table_col_version ?? 'Versjon'}
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_chunk_table_col_version_status ?? 'Versjonsstatus'}
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_chunk_table_col_sent_to_ai ?? 'Sendt til AI'}
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_chunk_table_col_cases ?? 'Saker'}
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_chunk_table_col_requirements ?? 'Krav'}
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_chunk_table_col_primary ?? 'Primærkilder'}
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_chunk_table_col_score ?? 'Snitt score'}
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_chunk_table_col_max_score ?? 'Maks score'}
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_chunk_table_col_last_used ?? 'Sist brukt'}
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

function DocumentUsageSection({ title, rows, tk, locale }) {
    const emptyLabel = tk.ai_usage_doc_empty ?? 'Ingen Kunnskapsbase-dokumenter er logget som sendt til AI ennå.';

    return (
        <section className="overflow-hidden rounded-[22px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
            <div className="border-b border-slate-200 px-5 py-4">
                <h2 className="text-base font-semibold text-slate-900">{title}</h2>
            </div>

            {rows.length === 0 ? (
                <div className="px-6 py-10 text-center text-sm text-slate-500">{emptyLabel}</div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200">
                        <thead className="bg-slate-50/80">
                            <tr>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_doc_table_col_document ?? 'Dokument'}
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_doc_table_col_category ?? 'Kategori'}
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_doc_table_col_version ?? 'Versjon'}
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_doc_table_col_approval ?? 'Godkjenning'}
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_doc_table_col_cases ?? 'Saker'}
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_doc_table_col_requirements ?? 'Krav'}
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_doc_table_col_sent_to_ai ?? 'Sendt til AI'}
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_doc_table_col_primary ?? 'Primærkilder'}
                                </th>
                                <th className="px-4 py-2.5 text-right text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_doc_table_col_score ?? 'Snitt score'}
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_doc_table_col_last_used ?? 'Sist brukt'}
                                </th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                    {tk.ai_usage_doc_table_col_superseded ?? 'Eldre versjon brukt'}
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-200 bg-white">
                            {rows.map((row) => (
                                <DocumentUsageRow key={row.knowledge_item_id} row={row} tk={tk} locale={locale} />
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

function DocumentUsageRow({ row, tk, locale }) {
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
        <tr className="align-top transition hover:bg-slate-50/40">
            <td className="px-4 py-3.5">
                <div className="max-w-55">
                    {showUrl ? (
                        <Link
                            href={showUrl}
                            className="font-medium text-violet-700 hover:underline"
                        >
                            {displayName}
                        </Link>
                    ) : (
                        <span className="font-medium text-slate-950">{displayName}</span>
                    )}
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
