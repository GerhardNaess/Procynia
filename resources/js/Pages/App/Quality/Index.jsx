import { useState } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';
import FilePickerField from '../../../Components/App/FilePickerField';
import QualityItemActions from '../../../Components/App/QualityItemActions';
import StatusBadge from '../../../Components/App/StatusBadge';
import { publicationLabel, publicationTone } from '../../../Support/processPublication';
import { itemLabel } from '../../../Support/qualityStructure';
import {
    DESTRUCTIVE_COLOURS,
    PRIMARY_ACTION,
    SECONDARY_ACTION,
} from '../../../Support/actionStyles';

/**
 * Kvalitet — the virksomhet's styrende dokumenter, prosesser and kontroller.
 *
 * Every row is a quality object of its own, with its own owner, number, status and review cycle. A
 * row leads to the object's page in Kvalitet, where its structure is edited and the Wiki pages
 * behind it are attached. Wiki is never typed or relabelled by any of this.
 *
 * All three are stored as quality items, but they are never listed as one kind of thing: a document
 * says what applies, a process how the virksomhet works, a control how it verifies that it happens —
 * with evidence as the record that it did.
 */

// Listed in their own sections, by what they are rather than as documents.
const NON_DOCUMENT_TYPES = ['process', 'control'];

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
const LABEL = 'block text-base font-semibold text-slate-700';
const ROW_DESTRUCTIVE = `ml-auto inline-flex min-h-9 items-center justify-center rounded-lg px-3 py-1.5 text-base font-semibold transition ${DESTRUCTIVE_COLOURS}`;

export default function QualityIndex() {
    const {
        translations = {},
        active_tab: activeTab = 'overview',
        permissions = {},
        items = [],
        type_counts: typeCounts = {},
        quality_types: qualityTypes = [],
        statuses = [],
        owner_options: ownerOptions = [],
        control_register: controlRegister = {},
        tools = [],
        tool_categories: toolCategories = [],
        tool_document_options: toolDocumentOptions = [],
        attention = [],
    } = usePage().props;

    const tq = translations?.quality ?? {};
    const typeLabels = tq.types ?? {};
    const statusLabels = tq.statuses ?? {};

    // One flag per permission the backend gates on, never a single "may manage". A reader who may
    // edit but not delete sees every editing affordance and no delete button — which is the only
    // way the page can stop offering requests the controller is going to refuse.
    const canCreate = permissions.can_create ?? false;
    const canEdit = permissions.can_edit ?? false;
    const canDelete = permissions.can_delete ?? false;
    const canChange = canCreate || canEdit || canDelete;

    return (
        <CustomerAppLayout title={tq.index_title ?? 'Kvalitet'} showPageTitle={false}>
            <div className="space-y-6">
                <header className="space-y-2">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                        {tq.index_title ?? 'Kvalitet'}
                    </h1>
                    <p className="max-w-3xl text-base leading-6 text-slate-600">
                        {tq.index_description
                            ?? 'Styrende dokumenter, prosesser og kontroller — med eier, status og revisjon.'}
                    </p>
                </header>

                {activeTab === 'overview' && (
                    <TypeCounts counts={typeCounts} types={qualityTypes} labels={tq.types_plural ?? {}} />
                )}

                {activeTab === 'overview' && <AttentionPanel findings={attention} tq={tq} />}

                {! canChange && (
                    <p className="text-base text-slate-500">
                        {tq.manage_denied ?? 'Du kan se kvalitetssystemet, men ikke endre det.'}
                    </p>
                )}

                {activeTab === 'tools' ? (
                    <>
                        <ToolLibrary tools={tools} tq={tq} />
                        {canEdit && (
                            <RegisterToolPanel
                                tq={tq}
                                categories={toolCategories}
                                documentOptions={toolDocumentOptions}
                            />
                        )}
                    </>
                ) : activeTab === 'controls' ? (
                    <ControlRegister
                        items={items}
                        register={controlRegister}
                        tq={tq}
                        canDelete={canDelete}
                        activeTab={activeTab}
                        statusLabels={statusLabels}
                        heading={tq.register?.heading ?? 'Kontrollregister'}
                        help={tq.register?.help ?? 'Alle kontroller i kvalitetssystemet, og hvilke prosessaktiviteter de brukes i.'}
                        showPlacements
                    />
                ) : activeTab === 'processes' ? (
                    <ProcessTable
                        items={items}
                        tq={tq}
                        canDelete={canDelete}
                        activeTab={activeTab}
                        statusLabels={statusLabels}
                    />
                ) : (
                    <OverviewSections
                        items={items}
                        register={controlRegister}
                        tq={tq}
                        canDelete={canDelete}
                        activeTab={activeTab}
                        typeLabels={typeLabels}
                        statusLabels={statusLabels}
                    />
                )}

                {activeTab === 'overview' && (
                    <>
                        {canCreate && (
                            <CreateItemPanel
                                tq={tq}
                                qualityTypes={qualityTypes}
                                statuses={statuses}
                                ownerOptions={ownerOptions}
                                typeLabels={typeLabels}
                                statusLabels={statusLabels}
                            />
                        )}
                    </>
                )}
            </div>
        </CustomerAppLayout>
    );
}

function TypeCounts({ counts, types, labels }) {
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            {types.map((type) => (
                <div key={type} className="rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                    <p className="text-2xl font-semibold text-slate-950">{counts?.[type] ?? 0}</p>
                    <p className="text-base text-slate-600">{labels?.[type] ?? type}</p>
                </div>
            ))}
        </div>
    );
}

/**
 * What in the kvalitetssystem needs attention. Each finding is a fixed rule the server applies to
 * the rows that already exist — see QualityAttentionService — so this only shows the count, says
 * what the rule means and leads to the objects it found.
 */
function AttentionPanel({ findings, tq }) {
    const ta = tq.attention ?? {};

    return (
        <section aria-labelledby="quality-attention-heading" className={CARD}>
            <h2 id="quality-attention-heading" className="text-xl font-semibold text-slate-950">
                {ta.heading ?? 'Trenger oppmerksomhet'}
            </h2>
            <p className="mt-1 max-w-3xl text-base leading-6 text-slate-600">{ta.help ?? ''}</p>

            <ul className="mt-4 grid gap-3 lg:grid-cols-2">
                {findings.map((finding) => (
                    <AttentionFinding key={finding.key} finding={finding} ta={ta} />
                ))}
            </ul>
        </section>
    );
}

function AttentionFinding({ finding, ta }) {
    const [open, setOpen] = useState(false);
    const copy = ta[finding.key] ?? {};
    const count = finding.items.length;
    const listId = `quality-attention-${finding.key}`;

    return (
        <li className={`rounded-2xl border px-4 py-3 ${count > 0 ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white'}`}>
            <div className="flex items-start gap-3">
                <p className={`text-2xl font-semibold ${count > 0 ? 'text-amber-800' : 'text-slate-400'}`}>{count}</p>
                <div className="min-w-0 flex-1">
                    <h3 className="text-base font-semibold text-slate-950">{copy.title ?? finding.key}</h3>
                    <p className="mt-1 text-base leading-6 text-slate-600">{copy.help ?? ''}</p>
                </div>
            </div>

            {count === 0 ? (
                <p className="mt-2 text-base text-slate-500">{ta.clear ?? 'Ingen funn'}</p>
            ) : (
                <>
                    <button
                        type="button"
                        className="mt-2 text-base font-semibold text-violet-700 hover:text-violet-900"
                        aria-expanded={open}
                        aria-controls={listId}
                        onClick={() => setOpen((value) => ! value)}
                    >
                        {open ? (ta.hide ?? 'Skjul') : (ta.show ?? 'Vis')}
                    </button>
                    {open && (
                        <ul id={listId} className="mt-2 space-y-1">
                            {finding.items.map((item) => (
                                <li key={item.id}>
                                    <Link href={item.url} className="text-base font-medium text-slate-800 underline-offset-2 hover:underline">
                                        {item.code ? `${item.code} ${item.title}` : item.title}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}
        </li>
    );
}

/**
 * Oversikt: the whole kvalitetssystem, one section per kind of object. Policies, procedures,
 * arbeidsinstrukser and sjekklister are the styrende dokumenter; processes and controls each get a
 * section of their own, so neither is ever presented as a document.
 */
function OverviewSections({ items, register, tq, canDelete, activeTab, typeLabels, statusLabels }) {
    const ts = tq.sections ?? {};
    const documents = items.filter((item) => ! NON_DOCUMENT_TYPES.includes(item.quality_type));
    const processes = items.filter((item) => item.quality_type === 'process');
    const controls = items.filter((item) => item.quality_type === 'control');

    return (
        <>
            <ItemTable
                items={documents}
                tq={tq}
                canDelete={canDelete}
                activeTab={activeTab}
                typeLabels={typeLabels}
                statusLabels={statusLabels}
            />
            <ProcessTable
                items={processes}
                tq={tq}
                canDelete={canDelete}
                activeTab={activeTab}
                statusLabels={statusLabels}
                help={ts.processes_help ?? 'Hvordan virksomheten arbeider.'}
            />
            <ControlRegister
                items={controls}
                register={register}
                tq={tq}
                canDelete={canDelete}
                activeTab={activeTab}
                statusLabels={statusLabels}
                heading={ts.controls_heading ?? 'Kontroller'}
                help={ts.controls_help ?? 'Hvordan virksomheten verifiserer at noe faktisk skjer. Evidens er dokumentasjonen på at kontrollen er gjennomført.'}
            />
        </>
    );
}

/**
 * A titled card for one kind of quality object. The heading stays when the list is empty, so a
 * reader of Oversikt always sees which three kinds of object the kvalitetssystem holds.
 */
function Section({ id, heading, help, empty, emptyText, children }) {
    return (
        <section aria-labelledby={id} className={CARD}>
            <h2 id={id} className="text-xl font-semibold text-slate-950">{heading}</h2>
            {help && <p className="mt-1 max-w-3xl text-base leading-6 text-slate-600">{help}</p>}
            {empty ? <p className="mt-4 text-base text-slate-600">{emptyText}</p> : children}
        </section>
    );
}

function ItemStatus({ item, tq, statusLabels }) {
    // A process reads its status from its approved revisions; the other types have no revisions
    // and show the stored status.
    return item.publication ? (
        <StatusBadge tone={publicationTone(item.publication)}>
            {publicationLabel(item.publication, tq.publication)}
        </StatusBadge>
    ) : (
        <StatusBadge tone={STATUS_TONES[item.status] ?? 'slate'}>
            {statusLabels?.[item.status] ?? item.status}
        </StatusBadge>
    );
}

function ItemTable({ items, tq, canDelete, activeTab, typeLabels, statusLabels }) {
    const table = tq.table ?? {};

    return (
        <Section
            id="quality-documents-heading"
            heading={tq.items_heading ?? 'Styrende dokumenter'}
            help={tq.sections?.documents_help ?? 'Policyer, prosedyrer, arbeidsinstrukser og sjekklister — det virksomheten styres etter.'}
            empty={items.length === 0}
            emptyText={tq.items_empty ?? 'Ingen styrende dokumenter er registrert ennå.'}
        >
            <div className="relative mt-4 overflow-x-auto">
                <table className="w-full min-w-[720px] text-left text-base">
                    <thead className="text-base uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="pb-2">{table.document ?? 'Dokument'}</th>
                            <th className="pb-2">{table.type ?? 'Type'}</th>
                            <th className="pb-2">{table.owner ?? 'Eier'}</th>
                            <th className="pb-2">{table.status ?? 'Status'}</th>
                            <th className="pb-2">{table.next_review ?? 'Neste revisjon'}</th>
                            <th className="pb-2">{table.wiki ?? 'Wiki'}</th>
                            {canDelete && (
                                <th className="pb-2 text-right">
                                    <span className="sr-only">{tq.actions_menu ?? 'Handlinger'}</span>
                                </th>
                            )}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {items.map((item) => (
                            <tr key={item.id} className="align-top">
                                <td className="py-3 pr-4">
                                    <Link href={item.url} className="font-semibold text-slate-950 hover:underline">
                                        {itemLabel(item)}
                                    </Link>
                                </td>
                                <td className="py-3 pr-4">
                                    <StatusBadge tone={TYPE_TONES[item.quality_type] ?? 'slate'}>
                                        {typeLabels?.[item.quality_type] ?? item.quality_type}
                                    </StatusBadge>
                                </td>
                                <td className="py-3 pr-4 text-slate-700">
                                    {item.owner_name ?? (tq.no_owner ?? 'Ingen eier')}
                                </td>
                                <td className="py-3 pr-4">
                                    <ItemStatus item={item} tq={tq} statusLabels={statusLabels} />
                                </td>
                                <td className="py-3 pr-4 text-slate-700">
                                    {item.next_review_at ?? (tq.no_review ?? 'Ingen revisjonssyklus')}
                                </td>
                                <td className="py-3 text-slate-700">{item.wiki_link_count}</td>
                                {canDelete && (
                                    <td className="py-3 pl-4 text-right">
                                        <QualityItemActions tq={tq} item={item} tab={activeTab} />
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Section>
    );
}

/**
 * Prosesser: how the virksomhet works. Owner, publication status, review and the Wiki behind each —
 * no type column, because every row is a process.
 */
function ProcessTable({ items, tq, canDelete, activeTab, statusLabels, help = null }) {
    const table = tq.table ?? {};
    const ts = tq.sections ?? {};

    return (
        <Section
            id="quality-processes-heading"
            heading={ts.processes_heading ?? 'Prosesser'}
            help={help}
            empty={items.length === 0}
            emptyText={ts.processes_empty ?? 'Ingen prosesser er registrert ennå.'}
        >
            <div className="relative mt-4 overflow-x-auto">
                <table className="w-full min-w-[640px] text-left text-base">
                    <thead className="text-base uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="pb-2">{tq.types?.process ?? 'Prosess'}</th>
                            <th className="pb-2">{table.owner ?? 'Eier'}</th>
                            <th className="pb-2">{table.status ?? 'Status'}</th>
                            <th className="pb-2">{table.next_review ?? 'Neste revisjon'}</th>
                            <th className="pb-2">{table.wiki ?? 'Wiki'}</th>
                            {canDelete && (
                                <th className="pb-2 text-right">
                                    <span className="sr-only">{tq.actions_menu ?? 'Handlinger'}</span>
                                </th>
                            )}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {items.map((item) => (
                            <tr key={item.id} className="align-top">
                                <td className="py-3 pr-4">
                                    <Link href={item.url} className="font-semibold text-slate-950 hover:underline">
                                        {itemLabel(item)}
                                    </Link>
                                </td>
                                <td className="py-3 pr-4 text-slate-700">
                                    {item.owner_name ?? (tq.no_owner ?? 'Ingen eier')}
                                </td>
                                <td className="py-3 pr-4">
                                    <ItemStatus item={item} tq={tq} statusLabels={statusLabels} />
                                </td>
                                <td className="py-3 pr-4 text-slate-700">
                                    {item.next_review_at ?? (tq.no_review ?? 'Ingen revisjonssyklus')}
                                </td>
                                <td className="py-3 text-slate-700">{item.wiki_link_count}</td>
                                {canDelete && (
                                    <td className="py-3 pl-4 text-right">
                                        <QualityItemActions tq={tq} item={item} tab={activeTab} />
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Section>
    );
}

/**
 * Kontroller as a register: what each control checks, who carries it out, how often and how,
 * whether evidence has been recorded — and, on the Kontroller tab, the process activities it is
 * applied in. No document columns: a control has no Wiki page count or document number to show.
 *
 * Controls are listed whether they are placed or not — one that has been taken off every activity
 * is still a control the virksomhet has, and stays here until somebody deletes it.
 */
function ControlRegister({ items, register, tq, canDelete, activeTab, statusLabels, heading, help, showPlacements = false }) {
    const tr = tq.register ?? {};
    const frequencyLabels = tq.frequencies ?? {};
    const notSet = <span className="text-slate-500">{tr.not_set ?? 'Ikke angitt'}</span>;

    return (
        <Section
            id={`quality-controls-heading-${activeTab}`}
            heading={heading}
            help={help}
            empty={items.length === 0}
            emptyText={tr.empty ?? 'Ingen kontroller er registrert ennå. Kontroller legges til på aktivitetene i en prosessflyt.'}
        >
            <div className="relative mt-4 overflow-x-auto">
                <table className={`w-full text-left text-base ${showPlacements ? 'min-w-[1080px]' : 'min-w-[900px]'}`}>
                    <thead className="text-base uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="pb-2">{tr.control ?? 'Kontroll'}</th>
                            <th className="pb-2">{tr.criterion ?? 'Hva kontrolleres'}</th>
                            <th className="pb-2">{tr.responsibility ?? 'Ansvarlig'}</th>
                            <th className="pb-2">{tr.frequency ?? 'Frekvens'}</th>
                            <th className="pb-2">{tr.method ?? 'Metode'}</th>
                            <th className="pb-2">{tq.table?.status ?? 'Status'}</th>
                            <th className="pb-2">{tr.evidence ?? 'Evidens'}</th>
                            {showPlacements && <th className="pb-2">{tr.used_in ?? 'Brukes i'}</th>}
                            {canDelete && (
                                <th className="pb-2 text-right">
                                    <span className="sr-only">{tq.actions_menu ?? 'Handlinger'}</span>
                                </th>
                            )}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {items.map((item) => {
                            const entry = register?.[item.id] ?? {};
                            const placements = entry.placements ?? [];
                            const evidenceCount = entry.evidence_count ?? 0;
                            // Who carries the control out; a control described without it falls back
                            // to its owner, who answers for it.
                            const responsible = entry.responsibility || item.owner_name;

                            return (
                                <tr key={item.id} className="align-top">
                                    <td className="py-3 pr-4">
                                        <Link href={item.url} className="font-semibold text-slate-950 hover:underline">
                                            {itemLabel(item)}
                                        </Link>
                                    </td>
                                    <td className="max-w-sm py-3 pr-4 text-slate-700">
                                        {entry.criterion || <span className="text-slate-500">{tr.no_criterion ?? 'Ikke beskrevet'}</span>}
                                    </td>
                                    <td className="py-3 pr-4 text-slate-700">{responsible || notSet}</td>
                                    <td className="py-3 pr-4 text-slate-700">
                                        {entry.frequency ? (frequencyLabels[entry.frequency] ?? entry.frequency) : notSet}
                                    </td>
                                    <td className="max-w-xs py-3 pr-4 text-slate-700">{entry.method || notSet}</td>
                                    <td className="py-3 pr-4">
                                        <StatusBadge tone={STATUS_TONES[item.status] ?? 'slate'}>
                                            {statusLabels?.[item.status] ?? item.status}
                                        </StatusBadge>
                                    </td>
                                    <td className="py-3 pr-4">
                                        <StatusBadge tone={evidenceCount > 0 ? 'green' : 'amber'}>
                                            {evidenceCount === 0
                                                ? (tr.evidence_none ?? 'Ingen evidens')
                                                : evidenceCount === 1
                                                    ? (tr.evidence_count_one ?? '1 registrert')
                                                    : (tr.evidence_count_many ?? ':count registrert').replace(':count', evidenceCount)}
                                        </StatusBadge>
                                    </td>
                                    {showPlacements && (
                                        <td className="py-3 pr-4">
                                            {placements.length === 0 ? (
                                                <span className="text-slate-500">{tr.unplaced ?? 'Ikke koblet til noen aktivitet'}</span>
                                            ) : (
                                                <ul className="space-y-1">
                                                    {placements.map((placement) => (
                                                        <li key={placement.id} className="text-slate-700">
                                                            <Link href={placement.url} className="font-semibold text-slate-950 hover:underline">
                                                                {placement.process_title}
                                                            </Link>
                                                            <span className="text-slate-400"> › </span>
                                                            {placement.activity_exists
                                                                ? placement.activity_label
                                                                : <span className="italic text-slate-500">{tr.activity_missing ?? 'Aktiviteten finnes ikke lenger'}</span>}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </td>
                                    )}
                                    {canDelete && (
                                        <td className="py-3 pl-4 text-right">
                                            <QualityItemActions tq={tq} item={item} tab={activeTab} />
                                        </td>
                                    )}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </Section>
    );
}

/**
 * Verktøy as a library: what each document is for, and the controls carried out with it.
 *
 * Cards rather than a file table, because the question a reader brings is "which document do I need
 * for this control?" — so each tool says what it is, what it helps with and where it is used before
 * it says what the file is called.
 */
function ToolLibrary({ tools, tq }) {
    const tt = tq.tools ?? {};
    const categoryLabels = tq.tool_categories ?? {};

    if (tools.length === 0) {
        return (
            <EmptyStateBox
                title={tt.empty ?? 'Ingen verktøy er registrert ennå.'}
                description={tt.empty_help ?? 'Registrer det første verktøyet nedenfor, og koble det til kontrollene som skal utføres med det.'}
            />
        );
    }

    return (
        <section className={CARD}>
            <h2 className="text-xl font-semibold text-slate-950">{tt.heading ?? 'Verktøy'}</h2>
            <p className="mt-1 max-w-3xl text-base leading-6 text-slate-600">{tt.help ?? ''}</p>

            <ul className="mt-5 grid gap-4 lg:grid-cols-2">
                {tools.map((tool) => (
                    <li key={tool.id} className="flex flex-col rounded-2xl border border-slate-200 p-5">
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge tone={tool.category ? 'emerald' : 'slate'}>
                                {tool.category ? (categoryLabels[tool.category] ?? tool.category) : (tt.no_category ?? 'Uten kategori')}
                            </StatusBadge>
                        </div>
                        <h3 className="mt-3 text-lg font-semibold text-slate-950">{tool.title}</h3>
                        {tool.description && (
                            <p className="mt-1 whitespace-pre-line text-base leading-6 text-slate-700">{tool.description}</p>
                        )}

                        <div className="mt-4">
                            <p className="text-base font-semibold uppercase tracking-wide text-slate-500">{tt.used_in ?? 'Brukes i'}</p>
                            {tool.controls.length === 0 ? (
                                <p className="mt-1 text-base text-slate-500">
                                    {tt.unused ?? 'Ikke koblet til noen kontroll ennå. Koble det til fra kontrollen.'}
                                </p>
                            ) : (
                                <ul className="mt-2 flex flex-wrap gap-2">
                                    {tool.controls.map((control) => (
                                        <li key={control.id}>
                                            <Link
                                                href={control.url}
                                                className="inline-flex items-center rounded-full bg-amber-50 px-3 py-1 text-base font-semibold text-amber-800 ring-1 ring-inset ring-amber-200 hover:bg-amber-100"
                                            >
                                                {itemLabel(control)}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>

                        <div className="mt-auto pt-5">
                            <div className="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                                <a href={tool.open_url} target="_blank" rel="noreferrer" className={SECONDARY_ACTION}>
                                    {tt.open ?? 'Åpne'}
                                </a>
                                <a href={tool.download_url} className={SECONDARY_ACTION}>
                                    {tt.download ?? 'Last ned'}
                                </a>
                                {tool.filename && (
                                    <span className="min-w-0 break-all text-base text-slate-500">{tool.filename}</span>
                                )}
                            </div>
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/**
 * Registering a tool: a name and a purpose for a file in the archive — uploaded here, or picked from
 * what the archive already holds. Either way the file is not copied.
 */
function RegisterToolPanel({ tq, categories, documentOptions }) {
    const tt = tq.tools ?? {};
    const categoryLabels = tq.tool_categories ?? {};
    const [source, setSource] = useState('upload');
    const [fileInputKey, setFileInputKey] = useState(0);
    const form = useForm({
        title: '',
        description: '',
        category: '',
        file: null,
        enterprise_wiki_document_id: '',
    });

    function submit(event) {
        event.preventDefault();
        form.transform((data) => (source === 'upload'
            ? { ...data, enterprise_wiki_document_id: '' }
            : { ...data, file: null }));
        form.post('/app/quality/tools', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setFileInputKey((key) => key + 1);
            },
        });
    }

    const hasDocument = source === 'upload' ? form.data.file !== null : form.data.enterprise_wiki_document_id !== '';

    return (
        <section className={CARD}>
            <h2 className="text-xl font-semibold text-slate-950">{tt.add_heading ?? 'Registrer verktøy'}</h2>
            <p className="mt-1 max-w-3xl text-base leading-6 text-slate-600">{tt.add_help ?? ''}</p>

            <form onSubmit={submit} className="mt-4 grid gap-4 md:grid-cols-2">
                <Field label={tt.field_title ?? 'Navn'} error={form.errors.title}>
                    <input
                        className={INPUT}
                        value={form.data.title}
                        placeholder={tt.field_title_placeholder ?? ''}
                        onChange={(event) => form.setData('title', event.target.value)}
                    />
                </Field>

                <Field label={tt.field_category ?? 'Type verktøy'} error={form.errors.category}>
                    <select
                        className={INPUT}
                        value={form.data.category}
                        onChange={(event) => form.setData('category', event.target.value)}
                    >
                        <option value="">{tt.field_category_placeholder ?? 'Ikke angitt'}</option>
                        {categories.map((category) => (
                            <option key={category} value={category}>{categoryLabels[category] ?? category}</option>
                        ))}
                    </select>
                </Field>

                <div className="md:col-span-2">
                    <Field label={tt.field_description ?? 'Kort beskrivelse'} error={form.errors.description}>
                        <textarea
                            rows={2}
                            className={INPUT}
                            value={form.data.description}
                            placeholder={tt.field_description_placeholder ?? ''}
                            onChange={(event) => form.setData('description', event.target.value)}
                        />
                    </Field>
                </div>

                <fieldset className="space-y-3 md:col-span-2">
                    <div className="flex flex-wrap gap-4">
                        {['upload', 'existing'].map((option) => (
                            <label key={option} className="inline-flex items-center gap-2 text-base text-slate-800">
                                <input
                                    type="radio"
                                    name="tool-source"
                                    value={option}
                                    checked={source === option}
                                    onChange={() => setSource(option)}
                                />
                                {option === 'upload'
                                    ? (tt.source_upload ?? 'Last opp nytt dokument')
                                    : (tt.source_existing ?? 'Velg fra dokumentarkivet')}
                            </label>
                        ))}
                    </div>

                    {source === 'upload' ? (
                        <FilePickerField
                            id="quality-tool-file"
                            inputKey={fileInputKey}
                            label={tt.field_file ?? 'Dokument'}
                            accept=".pdf,.docx"
                            file={form.data.file}
                            buttonLabel={tq.file_choose ?? 'Velg fil'}
                            emptyLabel={tq.file_none_selected ?? 'Ingen fil valgt'}
                            help={tt.field_file_help ?? 'PDF eller Word (DOCX), maks 20 MB.'}
                            error={form.errors.file}
                            onChange={(file) => form.setData('file', file)}
                        />
                    ) : (
                        <Field label={tt.field_document ?? 'Dokument i arkivet'} error={form.errors.enterprise_wiki_document_id}>
                            <select
                                className={INPUT}
                                value={form.data.enterprise_wiki_document_id}
                                onChange={(event) => form.setData('enterprise_wiki_document_id', event.target.value)}
                            >
                                <option value="">{tt.field_document_placeholder ?? 'Velg dokument …'}</option>
                                {documentOptions.map((option) => (
                                    <option key={option.document_id} value={option.document_id}>{option.filename}</option>
                                ))}
                            </select>
                        </Field>
                    )}
                </fieldset>

                <div className="md:col-span-2">
                    <button
                        type="submit"
                        className={PRIMARY_ACTION}
                        disabled={form.processing || form.data.title.trim() === '' || ! hasDocument}
                    >
                        {tt.submit ?? 'Registrer verktøy'}
                    </button>
                </div>
            </form>
        </section>
    );
}

function CreateItemPanel({ tq, qualityTypes, statuses, ownerOptions, typeLabels, statusLabels }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        quality_type: '',
        title: '',
        code: '',
        purpose: '',
        owner_user_id: '',
        status: 'draft',
        review_interval_months: '',
        last_reviewed_at: '',
        file: null,
    });

    // Held in state so the file input can be cleared on success — a file input's value cannot be
    // set programmatically, so remounting it is the only way to stop a submitted file lingering in
    // the form after the item has been created.
    const [fileInputKey, setFileInputKey] = useState(0);

    function submit(event) {
        event.preventDefault();
        // forceFormData, because a file cannot travel as JSON. Inertia would switch on its own once
        // it sees a File, but saying so keeps the request shape from depending on whether the user
        // happened to pick one.
        post('/app/quality/items', {
            forceFormData: true,
            onSuccess: () => {
                reset();
                setFileInputKey((key) => key + 1);
            },
        });
    }

    return (
        <section className={CARD}>
            <h2 className="text-xl font-semibold text-slate-950">{tq.create_heading ?? 'Registrer i kvalitetssystemet'}</h2>
            <p className="mt-1 max-w-3xl text-base leading-6 text-slate-600">{tq.create_help ?? ''}</p>

            <form onSubmit={submit} className="mt-4 grid gap-4 md:grid-cols-2">
                <Field label={tq.field_type ?? 'Type'} error={errors.quality_type}>
                    <select
                        className={INPUT}
                        value={data.quality_type}
                        // A process starts unpublished — it comes into force by approving its flow —
                        // so a status picked for another type is not carried over.
                        onChange={(event) => {
                            const qualityType = event.target.value;
                            setData((previous) => ({
                                ...previous,
                                quality_type: qualityType,
                                status: qualityType === 'process' ? 'draft' : previous.status,
                                // A control has no document of its own: its records are evidence.
                                file: qualityType === 'control' ? null : previous.file,
                            }));
                        }}
                    >
                        <option value="">{tq.field_type_placeholder ?? 'Velg type …'}</option>
                        {qualityTypes.map((type) => (
                            <option key={type} value={type}>{typeLabels?.[type] ?? type}</option>
                        ))}
                    </select>
                </Field>

                <Field label={tq.field_title ?? 'Tittel'} error={errors.title}>
                    <input
                        className={INPUT}
                        value={data.title}
                        onChange={(event) => setData('title', event.target.value)}
                    />
                </Field>

                <Field
                    label={data.quality_type === 'control' ? (tq.field_code_control ?? 'Kontrollnr.') : (tq.field_code ?? 'Dokumentnr.')}
                    error={errors.code}
                >
                    <input
                        className={INPUT}
                        value={data.code}
                        onChange={(event) => setData('code', event.target.value)}
                    />
                </Field>

                <Field label={tq.field_owner ?? 'Eier'} error={errors.owner_user_id}>
                    <select
                        className={INPUT}
                        value={data.owner_user_id}
                        onChange={(event) => setData('owner_user_id', event.target.value)}
                    >
                        <option value="">{tq.field_owner_placeholder ?? 'Ingen eier'}</option>
                        {ownerOptions.map((owner) => (
                            <option key={owner.id} value={owner.id}>{owner.name}</option>
                        ))}
                    </select>
                </Field>

                <Field label={tq.field_status ?? 'Status'} error={errors.status}>
                    {data.quality_type === 'process' ? (
                        <p className="min-h-10 py-2 text-base text-slate-600">
                            {tq.publication?.create_status_help ?? 'En prosess blir gjeldende når flyten godkjennes og publiseres.'}
                        </p>
                    ) : (
                        <select
                            className={INPUT}
                            value={data.status}
                            onChange={(event) => setData('status', event.target.value)}
                        >
                            {statuses.map((status) => (
                                <option key={status} value={status}>{statusLabels?.[status] ?? status}</option>
                            ))}
                        </select>
                    )}
                </Field>

                <Field label={tq.field_review_interval ?? 'Revisjonsintervall (måneder)'} error={errors.review_interval_months}>
                    <input
                        type="number"
                        min="1"
                        max="120"
                        className={INPUT}
                        value={data.review_interval_months}
                        onChange={(event) => setData('review_interval_months', event.target.value)}
                    />
                </Field>

                <Field label={tq.field_last_reviewed ?? 'Sist revidert'} error={errors.last_reviewed_at}>
                    <input
                        type="date"
                        className={INPUT}
                        value={data.last_reviewed_at}
                        onChange={(event) => setData('last_reviewed_at', event.target.value)}
                    />
                </Field>

                {data.quality_type !== 'control' && (
                    <div className="md:col-span-2">
                        <FilePickerField
                            id="quality-create-file"
                            inputKey={fileInputKey}
                            label={tq.field_file ?? 'Last opp dokument'}
                            accept=".pdf,.docx"
                            file={data.file}
                            buttonLabel={tq.file_choose ?? 'Velg fil'}
                            emptyLabel={tq.file_none_selected ?? 'Ingen fil valgt'}
                            help={tq.field_file_help ?? 'Valgfritt. PDF eller Word (DOCX), maks 20 MB.'}
                            error={errors.file}
                            onChange={(file) => setData('file', file)}
                        />
                    </div>
                )}

                <div className="md:col-span-2">
                    <Field label={tq.field_purpose ?? 'Formål'} error={errors.purpose}>
                        <textarea
                            rows={3}
                            className={INPUT}
                            value={data.purpose}
                            onChange={(event) => setData('purpose', event.target.value)}
                        />
                    </Field>
                </div>

                <div className="md:col-span-2">
                    <button type="submit" className={PRIMARY_ACTION} disabled={processing}>
                        {tq.create_submit ?? 'Opprett'}
                    </button>
                </div>
            </form>
        </section>
    );
}

function Field({ label, error, children }) {
    return (
        <label className="block space-y-1">
            <span className={LABEL}>{label}</span>
            {children}
            {error && <span className="block text-base text-rose-600">{error}</span>}
        </label>
    );
}
