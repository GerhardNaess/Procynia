import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import StatusBadge from '../../../Components/App/StatusBadge';
import FilePickerField from '../../../Components/App/FilePickerField';
import ProcessFlowPanel from '../../../Components/App/ProcessFlowPanel';
import {
    DESTRUCTIVE_COLOURS,
    PRIMARY_ACTION,
    SECONDARY_ACTION,
} from '../../../Support/actionStyles';

/**
 * One styrende dokument.
 *
 * Four things on one page, in the order a kvalitetsleder needs them: what the document is and who
 * answers for it, the structure its type carries, the files that belong to it, and the knowledge in
 * Wiki behind it. Files and Wiki pages are kept in separate sections because they answer different
 * questions — one is what the document IS and leaves behind, the other is what the virksomhet knows
 * about the subject. The structure is saved as a whole list — see QualityItemService for why — so
 * the editor builds one array and posts it, rather than firing a request per row.
 *
 * A process carries a second tab, "Flyt": how the process actually runs, as lanes, branches and a
 * diagram drawn from them. It is a tab rather than another panel because it answers a different
 * question from the rest of the page — not what the document is, but how the work goes — and
 * because it is the one view that is not text. Every other type's page is unchanged: without a
 * flow there is no tab strip at all.
 *
 * Because of that tab, a process's Dokument tab carries neither "Struktur" nor "Relasjoner". Steg,
 * input and output were a second, competing place to describe the same run the flow already holds,
 * and leaving both open invited two answers to one question. For a process, the flow is the single
 * place. Checklists and controls keep their Struktur panel — they have no flow to move it to — and
 * the backend still serves and accepts process structure, so this is UI only.
 */

const TYPE_TONES = {
    policy: 'violet',
    process: 'blue',
    procedure: 'sky',
    work_instruction: 'slate',
    checklist: 'emerald',
    control: 'amber',
};

const STATUS_TONES = {
    draft: 'slate',
    active: 'green',
    under_review: 'blue',
    retired: 'slate',
};

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-sm font-semibold text-slate-700';
const ROW_DESTRUCTIVE = `inline-flex min-h-9 items-center justify-center rounded-lg px-3 py-1.5 text-sm font-semibold transition ${DESTRUCTIVE_COLOURS}`;
/** Matches the active tab on the Kvalitet index, so one tab strip does not read as two kinds. */
const TAB_ACTIVE = 'inline-flex min-h-11 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-base font-semibold text-violet-700';

export default function QualityItem() {
    const {
        translations = {},
        item,
        can_manage: canManage = false,
        statuses = [],
        frequencies = [],
        link_types: linkTypes = [],
        owner_options: ownerOptions = [],
        wiki_links: wikiLinks = [],
        wiki_page_options: wikiPageOptions = [],
        wiki_search: wikiSearch = '',
        documents = [],
        document_options: documentOptions = [],
        document_relation_types: documentRelationTypes = [],
        document_search: documentSearch = '',
        relations = [],
        active_tab: activeTab = 'document',
        has_flow: hasFlow = false,
        blueprint = null,
        flow_proposal: flowProposal = null,
        flow_error: flowError = null,
        flow_ai_available: flowAiAvailable = false,
    } = usePage().props;

    const tq = translations?.quality ?? {};
    const td = tq.detail ?? {};
    const typeLabels = tq.types ?? {};
    const statusLabels = tq.statuses ?? {};
    /** A process answers "how does this run?" in the Flyt tab, so Dokument does not ask it twice. */
    const isProcess = item.quality_type === 'process';

    return (
        <CustomerAppLayout title={item.title} showPageTitle={false}>
            <div className="space-y-6">
                <Link href="/app/quality" className="text-sm font-semibold text-slate-600 hover:underline">
                    ← {tq.back_to_quality ?? 'Tilbake til Kvalitet'}
                </Link>

                <header className="space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge tone={TYPE_TONES[item.quality_type] ?? 'slate'}>
                            {typeLabels?.[item.quality_type] ?? item.quality_type}
                        </StatusBadge>
                        <StatusBadge tone={STATUS_TONES[item.status] ?? 'slate'}>
                            {statusLabels?.[item.status] ?? item.status}
                        </StatusBadge>
                        {item.code && <span className="text-base text-slate-500">{item.code}</span>}
                    </div>
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">{item.title}</h1>
                    {item.purpose && (
                        <p className="max-w-3xl whitespace-pre-line text-base leading-6 text-slate-600">
                            {item.purpose}
                        </p>
                    )}
                </header>

                {hasFlow && <DetailTabs td={td} item={item} activeTab={activeTab} />}

                {hasFlow && activeTab === 'flow' ? (
                    <ProcessFlowPanel
                        tq={tq}
                        item={item}
                        blueprint={blueprint}
                        canManage={canManage}
                        proposal={flowProposal}
                        flowError={flowError}
                        flowAiAvailable={flowAiAvailable}
                    />
                ) : (
                    <>
                        <MetadataPanel
                            tq={tq}
                            td={td}
                            item={item}
                            canManage={canManage}
                            statuses={statuses}
                            statusLabels={statusLabels}
                            ownerOptions={ownerOptions}
                        />

                        {! isProcess && (
                            <StructurePanel
                                tq={tq}
                                td={td}
                                item={item}
                                canManage={canManage}
                                frequencies={frequencies}
                                frequencyLabels={tq.frequencies ?? {}}
                            />
                        )}

                        {! isProcess && (
                            <RelationsPanel tq={tq} relations={relations} typeLabels={typeLabels} />
                        )}

                        <DocumentsPanel
                            tq={tq}
                            item={item}
                            canManage={canManage}
                            documents={documents}
                            documentOptions={documentOptions}
                            documentSearch={documentSearch}
                            wikiSearch={wikiSearch}
                            relationTypes={documentRelationTypes}
                            relationTypeLabels={tq.document_relation_types ?? {}}
                        />

                        <WikiPanel
                            tq={tq}
                            item={item}
                            canManage={canManage}
                            wikiLinks={wikiLinks}
                            wikiPageOptions={wikiPageOptions}
                            wikiSearch={wikiSearch}
                            documentSearch={documentSearch}
                            linkTypes={linkTypes}
                            linkTypeLabels={tq.link_types ?? {}}
                        />
                    </>
                )}
            </div>
        </CustomerAppLayout>
    );
}

/**
 * The tab strip, which only a process has.
 *
 * Links rather than local state: the tab is in the URL, so the redirect back from generating or
 * approving a flow lands on the flow, and a link to a process's diagram is a link somebody can
 * send. The cost is a round trip per tab switch, which is the trade the module's own tabs already
 * make on the Kvalitet index.
 */
function DetailTabs({ td, item, activeTab }) {
    const tabs = [
        ['document', td.tab_document ?? 'Dokument'],
        ['flow', td.tab_flow ?? 'Flyt'],
    ];

    return (
        <nav className="flex flex-wrap gap-2">
            {tabs.map(([key, label]) => (
                <Link
                    key={key}
                    href={`/app/quality/items/${item.id}?tab=${key}`}
                    className={key === activeTab ? TAB_ACTIVE : SECONDARY_ACTION}
                >
                    {label}
                </Link>
            ))}
        </nav>
    );
}

function MetadataPanel({ tq, td, item, canManage, statuses, statusLabels, ownerOptions }) {
    const { data, setData, patch, processing, errors } = useForm({
        title: item.title ?? '',
        code: item.code ?? '',
        purpose: item.purpose ?? '',
        owner_user_id: item.owner_user_id ?? '',
        status: item.status ?? 'draft',
        review_interval_months: item.review_interval_months ?? '',
        last_reviewed_at: item.last_reviewed_at ?? '',
    });

    function submit(event) {
        event.preventDefault();
        patch(`/app/quality/items/${item.id}`);
    }

    if (! canManage) {
        return (
            <section className={CARD}>
                <h2 className="text-xl font-semibold text-slate-950">{td.metadata_heading ?? 'Styringsinformasjon'}</h2>
                <dl className="mt-4 grid gap-3 sm:grid-cols-2">
                    <ReadOnly label={tq.field_owner ?? 'Eier'} value={item.owner_name ?? (tq.no_owner ?? 'Ingen eier')} />
                    <ReadOnly label={tq.field_status ?? 'Status'} value={statusLabels?.[item.status] ?? item.status} />
                    <ReadOnly label={tq.field_last_reviewed ?? 'Sist revidert'} value={item.last_reviewed_at ?? '—'} />
                    <ReadOnly
                        label={tq.field_next_review ?? 'Neste revisjon'}
                        value={item.next_review_at ?? (tq.no_review ?? 'Ingen revisjonssyklus')}
                    />
                </dl>
            </section>
        );
    }

    return (
        <section className={CARD}>
            <h2 className="text-xl font-semibold text-slate-950">{td.metadata_heading ?? 'Styringsinformasjon'}</h2>

            <form onSubmit={submit} className="mt-4 grid gap-4 md:grid-cols-2">
                <Field label={tq.field_title ?? 'Tittel'} error={errors.title}>
                    <input className={INPUT} value={data.title} onChange={(e) => setData('title', e.target.value)} />
                </Field>

                <Field label={tq.field_code ?? 'Dokumentnr.'} error={errors.code}>
                    <input className={INPUT} value={data.code} onChange={(e) => setData('code', e.target.value)} />
                </Field>

                <Field label={tq.field_owner ?? 'Eier'} error={errors.owner_user_id}>
                    <select
                        className={INPUT}
                        value={data.owner_user_id ?? ''}
                        onChange={(e) => setData('owner_user_id', e.target.value)}
                    >
                        <option value="">{tq.field_owner_placeholder ?? 'Ingen eier'}</option>
                        {ownerOptions.map((owner) => (
                            <option key={owner.id} value={owner.id}>{owner.name}</option>
                        ))}
                    </select>
                </Field>

                <Field label={tq.field_status ?? 'Status'} error={errors.status}>
                    <select className={INPUT} value={data.status} onChange={(e) => setData('status', e.target.value)}>
                        {statuses.map((status) => (
                            <option key={status} value={status}>{statusLabels?.[status] ?? status}</option>
                        ))}
                    </select>
                </Field>

                <Field label={tq.field_review_interval ?? 'Revisjonsintervall (måneder)'} error={errors.review_interval_months}>
                    <input
                        type="number"
                        min="1"
                        max="120"
                        className={INPUT}
                        value={data.review_interval_months ?? ''}
                        onChange={(e) => setData('review_interval_months', e.target.value)}
                    />
                </Field>

                <Field label={tq.field_last_reviewed ?? 'Sist revidert'} error={errors.last_reviewed_at}>
                    <input
                        type="date"
                        className={INPUT}
                        value={data.last_reviewed_at ?? ''}
                        onChange={(e) => setData('last_reviewed_at', e.target.value)}
                    />
                </Field>

                <div className="md:col-span-2">
                    <Field label={tq.field_purpose ?? 'Formål'} error={errors.purpose}>
                        <textarea
                            rows={3}
                            className={INPUT}
                            value={data.purpose ?? ''}
                            onChange={(e) => setData('purpose', e.target.value)}
                        />
                    </Field>
                </div>

                <div className="flex flex-wrap items-center gap-3 md:col-span-2">
                    <button type="submit" className={PRIMARY_ACTION} disabled={processing}>
                        {tq.save ?? 'Lagre'}
                    </button>
                    <span className="text-sm text-slate-500">
                        {(tq.field_next_review ?? 'Neste revisjon')}: {item.next_review_at ?? '—'}
                    </span>
                    <button
                        type="button"
                        className={`ml-auto ${ROW_DESTRUCTIVE}`}
                        onClick={() => {
                            if (window.confirm(tq.delete_item_confirm ?? 'Slett dokumentet?')) {
                                router.delete(`/app/quality/items/${item.id}`);
                            }
                        }}
                    >
                        {tq.delete_item ?? 'Slett dokument'}
                    </button>
                </div>
            </form>
        </section>
    );
}

/**
 * Structure, saved as one list per kind.
 *
 * The whole set travels on save, which is what the backend expects: positions are renumbered from
 * the array order, and a row whose required field is blank is simply dropped. That makes "remove"
 * a client-side splice rather than its own endpoint.
 *
 * A process is not one of the kinds: its steg, input and output live in the Flyt tab now. The
 * endpoint still accepts them, so nothing here needs undoing if they come back.
 */
function StructurePanel({ tq, td, item, canManage, frequencies, frequencyLabels }) {
    const [checklistItems, setChecklistItems] = useState(item.checklist_items ?? []);
    const [control, setControl] = useState(item.control ?? {
        criterion: '', responsibility: '', frequency: '', method: '',
    });
    const [saving, setSaving] = useState(false);

    if (! ['checklist', 'control'].includes(item.quality_type)) {
        return (
            <section className={CARD}>
                <h2 className="text-xl font-semibold text-slate-950">{td.structure_heading ?? 'Struktur'}</h2>
                <p className="mt-2 text-base text-slate-600">
                    {td.no_structure ?? 'Denne dokumenttypen har ingen egen struktur.'}
                </p>
            </section>
        );
    }

    function save() {
        const payload = {};

        if (item.quality_type === 'checklist') {
            payload.checklist_items = checklistItems;
        }

        if (item.quality_type === 'control') {
            payload.control = control;
        }

        setSaving(true);
        router.put(`/app/quality/items/${item.id}/structure`, payload, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        });
    }

    return (
        <section className={CARD}>
            <h2 className="text-xl font-semibold text-slate-950">{td.structure_heading ?? 'Struktur'}</h2>

            {item.quality_type === 'checklist' && (
                <div className="mt-4">
                    <RowEditor
                        heading={td.checklist_heading ?? 'Sjekklistepunkter'}
                        emptyText={td.checklist_empty ?? 'Ingen punkter er lagt inn ennå.'}
                        addText={td.add_checklist_item ?? 'Legg til punkt'}
                        removeText={td.remove_row ?? 'Fjern'}
                        canManage={canManage}
                        rows={checklistItems}
                        setRows={setChecklistItems}
                        blank={{ text: '', guidance: '', is_required: true }}
                        fields={[
                            { key: 'text', label: td.checklist_text ?? 'Punkt' },
                            { key: 'guidance', label: td.checklist_guidance ?? 'Veiledning' },
                            { key: 'is_required', label: td.checklist_required ?? 'Obligatorisk', checkbox: true },
                        ]}
                        numbered
                    />
                </div>
            )}

            {item.quality_type === 'control' && (
                <div className="mt-4 grid gap-4 md:grid-cols-2">
                    <Field label={td.control_criterion ?? 'Kriterium'}>
                        <textarea
                            rows={3}
                            disabled={! canManage}
                            className={INPUT}
                            value={control.criterion ?? ''}
                            onChange={(e) => setControl({ ...control, criterion: e.target.value })}
                        />
                    </Field>
                    <Field label={td.control_method ?? 'Metode'}>
                        <textarea
                            rows={3}
                            disabled={! canManage}
                            className={INPUT}
                            value={control.method ?? ''}
                            onChange={(e) => setControl({ ...control, method: e.target.value })}
                        />
                    </Field>
                    <Field label={td.control_responsibility ?? 'Ansvar'}>
                        <input
                            disabled={! canManage}
                            className={INPUT}
                            value={control.responsibility ?? ''}
                            onChange={(e) => setControl({ ...control, responsibility: e.target.value })}
                        />
                    </Field>
                    <Field label={td.control_frequency ?? 'Frekvens'}>
                        <select
                            disabled={! canManage}
                            className={INPUT}
                            value={control.frequency ?? ''}
                            onChange={(e) => setControl({ ...control, frequency: e.target.value })}
                        >
                            <option value="">{td.control_frequency_placeholder ?? 'Ikke angitt'}</option>
                            {frequencies.map((frequency) => (
                                <option key={frequency} value={frequency}>
                                    {frequencyLabels?.[frequency] ?? frequency}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>
            )}

            {canManage && (
                <div className="mt-6">
                    <button type="button" className={PRIMARY_ACTION} onClick={save} disabled={saving}>
                        {tq.save ?? 'Lagre'}
                    </button>
                </div>
            )}
        </section>
    );
}

function RowEditor({
    heading, emptyText, addText, removeText, canManage, rows, setRows, blank, fields, numbered = false,
}) {
    function update(index, key, value) {
        setRows(rows.map((row, i) => (i === index ? { ...row, [key]: value } : row)));
    }

    return (
        <div>
            <h3 className="text-base font-semibold text-slate-900">{heading}</h3>

            {rows.length === 0 ? (
                <p className="mt-2 text-sm text-slate-600">{emptyText}</p>
            ) : (
                <ul className="mt-3 space-y-3">
                    {rows.map((row, index) => (
                        <li key={index} className="rounded-2xl border border-slate-200 p-4">
                            {numbered && (
                                <p className="mb-2 text-sm font-semibold text-slate-500">{index + 1}</p>
                            )}
                            <div className="grid gap-3 md:grid-cols-2">
                                {fields.map((field) => (
                                    <Field key={field.key} label={field.label}>
                                        {field.checkbox ? (
                                            <input
                                                type="checkbox"
                                                disabled={! canManage}
                                                checked={Boolean(row[field.key])}
                                                onChange={(e) => update(index, field.key, e.target.checked)}
                                                className="h-5 w-5 rounded border-slate-300"
                                            />
                                        ) : field.textarea ? (
                                            <textarea
                                                rows={2}
                                                disabled={! canManage}
                                                className={INPUT}
                                                value={row[field.key] ?? ''}
                                                onChange={(e) => update(index, field.key, e.target.value)}
                                            />
                                        ) : (
                                            <input
                                                disabled={! canManage}
                                                className={INPUT}
                                                value={row[field.key] ?? ''}
                                                onChange={(e) => update(index, field.key, e.target.value)}
                                            />
                                        )}
                                    </Field>
                                ))}
                            </div>
                            {canManage && (
                                <button
                                    type="button"
                                    className={`mt-2 ${ROW_DESTRUCTIVE}`}
                                    onClick={() => setRows(rows.filter((_, i) => i !== index))}
                                >
                                    {removeText}
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canManage && (
                <button
                    type="button"
                    className={`${SECONDARY_ACTION} mt-3`}
                    onClick={() => setRows([...rows, { ...blank }])}
                >
                    {addText}
                </button>
            )}
        </div>
    );
}

function RelationsPanel({ tq, relations, typeLabels }) {
    const outgoing = tq.relation_types ?? {};
    const incoming = tq.relation_types_incoming ?? {};

    return (
        <section className={CARD}>
            <h2 className="text-xl font-semibold text-slate-950">{tq.relations_heading ?? 'Relasjoner'}</h2>

            {relations.length === 0 ? (
                <p className="mt-2 text-base text-slate-600">{tq.no_relations ?? 'Ingen relasjoner'}</p>
            ) : (
                <ul className="mt-3 space-y-2">
                    {relations.map((relation) => (
                        <li key={`${relation.id}-${relation.direction}`} className="flex flex-wrap items-center gap-2 text-base">
                            <span className="text-slate-500">
                                {relation.direction === 'outgoing'
                                    ? (outgoing?.[relation.relation_type] ?? relation.relation_type)
                                    : (incoming?.[relation.relation_type] ?? relation.relation_type)}
                            </span>
                            <Link href={relation.other_url} className="font-semibold text-slate-950 hover:underline">
                                {relation.other_code ? `${relation.other_code} — ${relation.other_title}` : relation.other_title}
                            </Link>
                            <span className="text-sm text-slate-500">
                                ({typeLabels?.[relation.other_quality_type] ?? relation.other_quality_type})
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/**
 * The files that belong to one styrende dokument.
 *
 * A separate seam from the Wiki one below, and separate on purpose: this reaches files the document
 * consists of, uses or leaves behind, where "Relatert kunnskap" reaches what the virksomhet has
 * written down about the subject. Attaching a file copies nothing and changes nothing about it —
 * the same file belongs to as many documents as it is attached to — and removing a row here removes
 * the connection alone. Deleting the file itself stays in Wiki → Kildedokumenter, where the
 * deletion flow knows what else is built on it.
 */
function DocumentsPanel({
    tq,
    item,
    canManage,
    documents,
    documentOptions,
    documentSearch,
    wikiSearch,
    relationTypes,
    relationTypeLabels,
}) {
    const tdoc = tq.documents ?? {};
    const statusLabels = tdoc.statuses ?? {};
    const [search, setSearch] = useState(documentSearch ?? '');
    // The input is hidden behind a styled label, so it has to be remounted after an upload —
    // otherwise it keeps the previous file while the label reads "Ingen fil valgt".
    const [fileInputKey, setFileInputKey] = useState(0);

    const linkForm = useForm({
        enterprise_wiki_document_id: '',
        relation_type: relationTypes[0] ?? 'source',
        note: '',
    });

    const uploadForm = useForm({
        file: null,
        relation_type: relationTypes[0] ?? 'source',
        note: '',
    });

    function submitLink(event) {
        event.preventDefault();
        linkForm.post(`/app/quality/items/${item.id}/document-links`, {
            preserveScroll: true,
            onSuccess: () => linkForm.reset(),
        });
    }

    function submitUpload(event) {
        event.preventDefault();
        uploadForm.post(`/app/quality/items/${item.id}/documents`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                uploadForm.reset();
                setFileInputKey((key) => key + 1);
            },
        });
    }

    return (
        <section className={CARD}>
            <h2 className="text-xl font-semibold text-slate-950">{tdoc.heading ?? 'Dokumenter'}</h2>
            <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{tdoc.help ?? ''}</p>

            {documents.length === 0 ? (
                <p className="mt-4 text-base text-slate-600">
                    {tdoc.empty ?? 'Ingen dokumenter er koblet til dette styrende dokumentet ennå.'}
                </p>
            ) : (
                <ul className="mt-4 divide-y divide-slate-100">
                    {documents.map((link) => (
                        <li key={link.id} className="flex flex-wrap items-center gap-3 py-3">
                            <a
                                href={link.download_url}
                                target="_blank"
                                rel="noreferrer"
                                className="font-semibold text-slate-950 hover:underline"
                            >
                                {link.filename}
                            </a>
                            <StatusBadge tone="slate">
                                {relationTypeLabels?.[link.relation_type] ?? link.relation_type}
                            </StatusBadge>
                            {link.document_status === 'failed' && (
                                <StatusBadge tone="amber">
                                    {statusLabels?.failed ?? 'Tekstuttrekk feilet'}
                                </StatusBadge>
                            )}
                            <span className="text-sm text-slate-500">
                                {link.owner_name ?? (tdoc.no_owner ?? 'Ingen eier')}
                                {link.uploaded_at ? ` · ${link.uploaded_at}` : ''}
                            </span>
                            {link.note && <span className="text-sm text-slate-600">{link.note}</span>}
                            {canManage && (
                                <button
                                    type="button"
                                    className={`ml-auto ${ROW_DESTRUCTIVE}`}
                                    onClick={() => {
                                        if (window.confirm(tdoc.unlink_confirm ?? 'Fjern koblingen?')) {
                                            router.delete(`/app/quality/document-links/${link.id}`, { preserveScroll: true });
                                        }
                                    }}
                                >
                                    {tdoc.unlink ?? 'Fjern kobling'}
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canManage && (
                <div className="mt-6 space-y-6">
                    <div className="space-y-4">
                        <h3 className="text-base font-semibold text-slate-900">
                            {tdoc.link_existing_heading ?? 'Koble til eksisterende dokument'}
                        </h3>

                        <div className="flex flex-wrap gap-2">
                            <input
                                className={`${INPUT} max-w-sm`}
                                placeholder={tdoc.search_placeholder ?? 'Søk etter filnavn …'}
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                            />
                            <button
                                type="button"
                                className={SECONDARY_ACTION}
                                onClick={() => router.get(
                                    `/app/quality/items/${item.id}`,
                                    { document_search: search, wiki_search: wikiSearch },
                                    { preserveScroll: true, preserveState: false },
                                )}
                            >
                                {tdoc.search_submit ?? 'Søk'}
                            </button>
                        </div>

                        {documentOptions.length === 0 ? (
                            <p className="text-sm text-slate-500">
                                {tdoc.no_candidates ?? 'Ingen dokumenter er lastet opp ennå, eller søket ga ingen treff.'}
                            </p>
                        ) : (
                            <form onSubmit={submitLink} className="grid gap-4 md:grid-cols-4">
                                <div className="md:col-span-2">
                                    <Field
                                        label={tdoc.document_label ?? 'Dokument'}
                                        error={linkForm.errors.enterprise_wiki_document_id}
                                    >
                                        <select
                                            className={INPUT}
                                            value={linkForm.data.enterprise_wiki_document_id}
                                            onChange={(e) => linkForm.setData('enterprise_wiki_document_id', e.target.value)}
                                        >
                                            <option value="">{tdoc.document_placeholder ?? 'Velg dokument …'}</option>
                                            {documentOptions.map((option) => (
                                                <option key={option.document_id} value={option.document_id}>
                                                    {option.filename}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                </div>

                                <Field label={tdoc.relation_type ?? 'Dokumenttype'} error={linkForm.errors.relation_type}>
                                    <select
                                        className={INPUT}
                                        value={linkForm.data.relation_type}
                                        onChange={(e) => linkForm.setData('relation_type', e.target.value)}
                                    >
                                        {relationTypes.map((type) => (
                                            <option key={type} value={type}>{relationTypeLabels?.[type] ?? type}</option>
                                        ))}
                                    </select>
                                </Field>

                                <div className="flex items-end">
                                    <button type="submit" className={PRIMARY_ACTION} disabled={linkForm.processing}>
                                        {tdoc.link_submit ?? 'Koble til'}
                                    </button>
                                </div>
                            </form>
                        )}
                    </div>

                    <div className="space-y-4 border-t border-slate-100 pt-6">
                        <h3 className="text-base font-semibold text-slate-900">
                            {tdoc.upload_heading ?? 'Last opp nytt dokument'}
                        </h3>
                        <p className="max-w-3xl text-sm leading-6 text-slate-600">{tdoc.upload_help ?? ''}</p>

                        <form onSubmit={submitUpload} className="grid gap-4 md:grid-cols-4">
                            <div className="md:col-span-2">
                                <FilePickerField
                                    id={`quality-document-upload-${item.id}`}
                                    inputKey={fileInputKey}
                                    label={tdoc.upload_field ?? 'Fil'}
                                    accept=".pdf,.docx"
                                    file={uploadForm.data.file}
                                    buttonLabel={tq.file_choose ?? 'Velg fil'}
                                    emptyLabel={tq.file_none_selected ?? 'Ingen fil valgt'}
                                    error={uploadForm.errors.file}
                                    disabled={uploadForm.processing}
                                    onChange={(file) => uploadForm.setData('file', file)}
                                />
                            </div>

                            <Field label={tdoc.relation_type ?? 'Dokumenttype'} error={uploadForm.errors.relation_type}>
                                <select
                                    className={INPUT}
                                    value={uploadForm.data.relation_type}
                                    onChange={(e) => uploadForm.setData('relation_type', e.target.value)}
                                >
                                    {relationTypes.map((type) => (
                                        <option key={type} value={type}>{relationTypeLabels?.[type] ?? type}</option>
                                    ))}
                                </select>
                            </Field>

                            <div className="flex items-end">
                                <button
                                    type="submit"
                                    className={PRIMARY_ACTION}
                                    disabled={uploadForm.processing || ! uploadForm.data.file}
                                >
                                    {tdoc.upload_submit ?? 'Last opp og koble til'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </section>
    );
}

/**
 * The seam to Wiki, from the quality side.
 *
 * Attaching a page changes nothing about the page — no type, no label, no move out of the Wiki
 * catalogue. The same page may back several documents here, and most pages back none.
 */
function WikiPanel({ tq, item, canManage, wikiLinks, wikiPageOptions, wikiSearch, documentSearch, linkTypes, linkTypeLabels }) {
    const tw = tq.wiki ?? {};
    const [search, setSearch] = useState(wikiSearch ?? '');
    const { data, setData, post, processing, errors, reset } = useForm({
        enterprise_wiki_page_id: '',
        link_type: 'documents',
        note: '',
    });

    function submit(event) {
        event.preventDefault();
        post(`/app/quality/items/${item.id}/wiki-links`, {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    }

    return (
        <section className={CARD}>
            <h2 className="text-xl font-semibold text-slate-950">{tw.heading ?? 'Kunnskap i Wiki'}</h2>
            <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{tw.help ?? ''}</p>

            {wikiLinks.length === 0 ? (
                <p className="mt-4 text-base text-slate-600">
                    {tw.empty ?? 'Ingen Wiki-sider er koblet til dette dokumentet ennå.'}
                </p>
            ) : (
                <ul className="mt-4 divide-y divide-slate-100">
                    {wikiLinks.map((link) => (
                        <li key={link.id} className="flex flex-wrap items-center gap-3 py-3">
                            <a
                                href={link.page_url}
                                className="font-semibold text-slate-950 hover:underline"
                            >
                                {link.page_title}
                            </a>
                            <StatusBadge tone="slate">
                                {linkTypeLabels?.[link.link_type] ?? link.link_type}
                            </StatusBadge>
                            {link.note && <span className="text-sm text-slate-600">{link.note}</span>}
                            {canManage && (
                                <button
                                    type="button"
                                    className={`ml-auto ${ROW_DESTRUCTIVE}`}
                                    onClick={() => {
                                        if (window.confirm(tw.unlink_confirm ?? 'Fjern koblingen?')) {
                                            router.delete(`/app/quality/wiki-links/${link.id}`, { preserveScroll: true });
                                        }
                                    }}
                                >
                                    {tw.unlink ?? 'Fjern kobling'}
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canManage && (
                <div className="mt-6 space-y-4">
                    <div className="flex flex-wrap gap-2">
                        <input
                            className={`${INPUT} max-w-sm`}
                            placeholder={tw.search_placeholder ?? 'Søk etter Wiki-side …'}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                        <button
                            type="button"
                            className={SECONDARY_ACTION}
                            onClick={() => router.get(
                                `/app/quality/items/${item.id}`,
                                { wiki_search: search, document_search: documentSearch },
                                { preserveScroll: true, preserveState: false },
                            )}
                        >
                            {tw.search_submit ?? 'Søk'}
                        </button>
                    </div>

                    {wikiPageOptions.length === 0 ? (
                        <p className="text-sm text-slate-500">
                            {tw.no_candidates ?? 'Ingen synlige Wiki-sider, eller søket ga ingen treff.'}
                        </p>
                    ) : (
                        <form onSubmit={submit} className="grid gap-4 md:grid-cols-4">
                            <div className="md:col-span-2">
                                <Field label={tw.page_label ?? 'Wiki-side'} error={errors.enterprise_wiki_page_id}>
                                    <select
                                        className={INPUT}
                                        value={data.enterprise_wiki_page_id}
                                        onChange={(e) => setData('enterprise_wiki_page_id', e.target.value)}
                                    >
                                        <option value="">{tw.page_placeholder ?? 'Velg Wiki-side …'}</option>
                                        {wikiPageOptions.map((page) => (
                                            <option key={page.page_id} value={page.page_id}>{page.title}</option>
                                        ))}
                                    </select>
                                </Field>
                            </div>

                            <Field label={tw.link_type ?? 'Koblingstype'} error={errors.link_type}>
                                <select
                                    className={INPUT}
                                    value={data.link_type}
                                    onChange={(e) => setData('link_type', e.target.value)}
                                >
                                    {linkTypes.map((type) => (
                                        <option key={type} value={type}>{linkTypeLabels?.[type] ?? type}</option>
                                    ))}
                                </select>
                            </Field>

                            <div className="flex items-end">
                                <button type="submit" className={PRIMARY_ACTION} disabled={processing}>
                                    {tw.submit ?? 'Koble til'}
                                </button>
                            </div>
                        </form>
                    )}
                </div>
            )}
        </section>
    );
}

function Field({ label, error, children }) {
    return (
        <label className="block space-y-1">
            <span className={LABEL}>{label}</span>
            {children}
            {error && <span className="block text-sm text-rose-600">{error}</span>}
        </label>
    );
}

function ReadOnly({ label, value }) {
    return (
        <div>
            <dt className={LABEL}>{label}</dt>
            <dd className="text-base text-slate-800">{value}</dd>
        </div>
    );
}
