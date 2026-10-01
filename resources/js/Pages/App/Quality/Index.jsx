import { useMemo, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';
import StatusBadge from '../../../Components/App/StatusBadge';
import {
    candidatesForRelationEnd,
    documentLabel,
    relationTypeIsUsable,
} from '../../../Support/qualityStructure';

/**
 * Kvalitet — the faglig view of the Wiki.
 *
 * Every row here is a Wiki page. The module shows what kind of styrende dokument it is and how it
 * relates to the others, and then hands the reader back to Wiki for the content itself; it never
 * becomes a second place to read or edit a page.
 */

const TYPE_TONES = {
    policy: 'violet',
    process: 'blue',
    procedure: 'sky',
    work_instruction: 'slate',
    checklist: 'emerald',
    control: 'amber',
};

const PUBLICATION_TONES = {
    published: 'green',
    published_with_changes: 'emerald',
    in_review: 'blue',
    changes_requested: 'amber',
    draft: 'slate',
    archived: 'slate',
    no_version: 'rose',
};

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const PRIMARY_BUTTON = 'inline-flex min-h-10 items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-base font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50';
const QUIET_BUTTON = 'inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950';

export default function QualityIndex() {
    const {
        translations = {},
        active_tab: activeTab = 'overview',
        can_manage: canManage = false,
        documents = [],
        type_counts: typeCounts = {},
        quality_types: qualityTypes = [],
        relation_types: relationTypes = [],
        relations = [],
        unclassified_pages: unclassifiedPages = [],
        unclassified_search: unclassifiedSearch = '',
        relation_page_options: relationPageOptions = [],
    } = usePage().props;

    const tq = translations?.quality ?? {};
    const typeLabels = tq.types ?? {};
    const typeLabelsPlural = tq.types_plural ?? {};
    const relationLabels = tq.relation_types ?? {};
    const relationLabelsIncoming = tq.relation_types_incoming ?? {};

    return (
        <CustomerAppLayout title={tq.index_title ?? 'Kvalitet'} showPageTitle={false}>
            <div className="space-y-6">
                <header className="space-y-2">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                        {tq.index_title ?? 'Kvalitet'}
                    </h1>
                    <p className="max-w-3xl text-base leading-6 text-slate-600">
                        {tq.index_description
                            ?? 'Styrende dokumenter, prosesser og kontroller — bygget på godkjent innhold i Wiki.'}
                    </p>
                </header>

                {activeTab === 'overview' && (
                    <TypeCounts
                        counts={typeCounts}
                        qualityTypes={qualityTypes}
                        typeLabelsPlural={typeLabelsPlural}
                    />
                )}

                <DocumentTable
                    documents={documents}
                    canManage={canManage}
                    tq={tq}
                    typeLabels={typeLabels}
                    relationLabels={relationLabels}
                    relationLabelsIncoming={relationLabelsIncoming}
                />

                {activeTab === 'overview' && (
                    <>
                        <RelationsCard
                            relations={relations}
                            relationTypes={relationTypes}
                            documents={relationPageOptions}
                            canManage={canManage}
                            tq={tq}
                            relationLabels={relationLabels}
                        />
                        <ClassifyCard
                            pages={unclassifiedPages}
                            search={unclassifiedSearch}
                            qualityTypes={qualityTypes}
                            canManage={canManage}
                            tq={tq}
                            typeLabels={typeLabels}
                        />
                    </>
                )}
            </div>
        </CustomerAppLayout>
    );
}

/**
 * The document hierarchy as counts, governing first. Reading left to right is reading downwards
 * through the kvalitetssystem, which is why the order comes from the backend rather than from
 * whatever order the counts arrive in.
 */
function TypeCounts({ counts, qualityTypes, typeLabelsPlural }) {
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            {qualityTypes.map((type) => (
                <div key={type} className="rounded-[20px] border border-slate-200 bg-white p-4 shadow-sm">
                    <div className="text-3xl font-semibold tracking-tight text-slate-950">
                        {counts?.[type] ?? 0}
                    </div>
                    <div className="mt-1 text-base leading-6 text-slate-600">
                        {typeLabelsPlural?.[type] ?? type}
                    </div>
                </div>
            ))}
        </div>
    );
}

function DocumentTable({ documents, canManage, tq, typeLabels, relationLabels, relationLabelsIncoming }) {
    if (documents.length === 0) {
        return (
            <EmptyStateBox
                title={tq.documents_heading ?? 'Styrende dokumenter'}
                description={tq.documents_empty
                    ?? 'Ingen Wiki-sider er klassifisert ennå. Klassifiser sidene som utgjør kvalitetssystemet nedenfor.'}
            />
        );
    }

    const table = tq.table ?? {};

    return (
        <section className={CARD}>
            <h2 className="text-xl font-semibold text-slate-950">
                {tq.documents_heading ?? 'Styrende dokumenter'}
            </h2>

            <div className="mt-4 overflow-x-auto">
                <table className="min-w-full border-separate border-spacing-y-2 text-left">
                    <thead>
                        <tr className="text-sm font-semibold uppercase tracking-wide text-slate-500">
                            <th scope="col" className="px-3 py-2">{table.document ?? 'Dokument'}</th>
                            <th scope="col" className="px-3 py-2">{table.type ?? 'Type'}</th>
                            <th scope="col" className="px-3 py-2">{table.code ?? 'Dokumentnr.'}</th>
                            <th scope="col" className="px-3 py-2">{table.owner ?? 'Eier'}</th>
                            <th scope="col" className="px-3 py-2">{table.status ?? 'Status'}</th>
                            <th scope="col" className="px-3 py-2">{table.relations ?? 'Relasjoner'}</th>
                            {canManage && <th scope="col" className="px-3 py-2">{table.actions ?? 'Handling'}</th>}
                        </tr>
                    </thead>
                    <tbody>
                        {documents.map((document) => (
                            <DocumentRow
                                key={document.id}
                                document={document}
                                canManage={canManage}
                                tq={tq}
                                typeLabels={typeLabels}
                                relationLabels={relationLabels}
                                relationLabelsIncoming={relationLabelsIncoming}
                            />
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function DocumentRow({ document, canManage, tq, typeLabels, relationLabels, relationLabelsIncoming }) {
    const unclassify = () => {
        if (! window.confirm(tq.unclassify_confirm
            ?? 'Fjern klassifiseringen? Relasjoner til og fra dokumentet blir også fjernet. Wiki-siden beholdes.')) {
            return;
        }

        router.delete(`/app/quality/classifications/${document.id}`, { preserveScroll: true });
    };

    return (
        <tr className="align-top text-base text-slate-700 [&>td]:bg-slate-50/60 [&>td]:px-3 [&>td]:py-3 [&>td:first-child]:rounded-l-xl [&>td:last-child]:rounded-r-xl">
            <td>
                {/* The row leads back to Wiki, which holds the content. Kvalitet classifies it. */}
                <a href={document.wiki_url ?? '#'} className="font-semibold text-slate-950 underline-offset-2 hover:underline">
                    {document.title}
                </a>
            </td>
            <td>
                <StatusBadge tone={TYPE_TONES[document.quality_type] ?? 'slate'}>
                    {typeLabels?.[document.quality_type] ?? document.quality_type}
                </StatusBadge>
            </td>
            <td className="whitespace-nowrap">{document.quality_code ?? '—'}</td>
            <td>{document.owner_name ?? (tq.no_owner ?? 'Ingen eier')}</td>
            <td>
                {document.publication ? (
                    <StatusBadge tone={PUBLICATION_TONES[document.publication.state] ?? 'slate'}>
                        {document.publication.state_label}
                    </StatusBadge>
                ) : '—'}
            </td>
            <td>
                <RelationChips
                    relations={document.relations ?? []}
                    tq={tq}
                    relationLabels={relationLabels}
                    relationLabelsIncoming={relationLabelsIncoming}
                />
            </td>
            {canManage && (
                <td className="whitespace-nowrap">
                    <button type="button" onClick={unclassify} className={QUIET_BUTTON}>
                        {tq.unclassify ?? 'Fjern klassifisering'}
                    </button>
                </td>
            )}
        </tr>
    );
}

/**
 * Both directions, each worded from this document's point of view — "styrer" and "styres av" are
 * different statements, and a chip that said only "styrer" on both ends would be wrong on one.
 */
function RelationChips({ relations, tq, relationLabels, relationLabelsIncoming }) {
    if (relations.length === 0) {
        return <span className="text-slate-500">{tq.no_relations ?? 'Ingen relasjoner'}</span>;
    }

    return (
        <ul className="space-y-1">
            {relations.map((relation) => {
                const labels = relation.direction === 'outgoing' ? relationLabels : relationLabelsIncoming;

                return (
                    <li key={`${relation.id}-${relation.direction}`} className="leading-6">
                        <span className="font-semibold text-slate-600">
                            {labels?.[relation.relation_type] ?? relation.relation_type}
                        </span>
                        {' '}
                        <Link
                            href={`/app/wiki/${relation.other_page_slug}`}
                            className="text-slate-900 underline-offset-2 hover:underline"
                        >
                            {relation.other_page_title}
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}

function RelationsCard({ relations, relationTypes, documents, canManage, tq, relationLabels }) {
    const usableTypes = useMemo(
        () => relationTypes.filter((entry) => relationTypeIsUsable(documents, relationTypes, entry.key)),
        [documents, relationTypes],
    );

    return (
        <section className={CARD}>
            <h2 className="text-xl font-semibold text-slate-950">{tq.relations_heading ?? 'Relasjoner'}</h2>
            <p className="mt-1 max-w-3xl text-base leading-6 text-slate-600">
                {tq.relations_help
                    ?? 'Relasjoner sier hvordan dokumentene styrer hverandre. Bare kombinasjoner kvalitetssystemet tillater kan velges.'}
            </p>

            {canManage && (
                <RelationForm
                    relationTypes={relationTypes}
                    usableTypes={usableTypes}
                    documents={documents}
                    tq={tq}
                    relationLabels={relationLabels}
                />
            )}

            {relations.length === 0 ? (
                <p className="mt-4 text-base leading-6 text-slate-500">
                    {tq.relations_empty ?? 'Ingen relasjoner er opprettet ennå.'}
                </p>
            ) : (
                <ul className="mt-4 space-y-2">
                    {relations.map((relation) => (
                        <li
                            key={relation.id}
                            className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-50/60 px-3 py-2 text-base text-slate-700"
                        >
                            <span>
                                <span className="font-semibold text-slate-950">{relation.from_title}</span>
                                {' '}
                                <span className="text-slate-600">
                                    {relationLabels?.[relation.relation_type] ?? relation.relation_type}
                                </span>
                                {' '}
                                <span className="font-semibold text-slate-950">{relation.to_title}</span>
                            </span>
                            {canManage && (
                                <button
                                    type="button"
                                    className={QUIET_BUTTON}
                                    onClick={() => {
                                        if (! window.confirm(tq.relation_delete_confirm ?? 'Fjern relasjonen?')) {
                                            return;
                                        }

                                        router.delete(`/app/quality/relations/${relation.id}`, { preserveScroll: true });
                                    }}
                                >
                                    {tq.relation_delete ?? 'Fjern'}
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/**
 * The form offers only pairs the matrix allows, so choosing a relation type narrows both selects.
 * The backend enforces the same matrix — this spares the user a rejection, it does not replace it.
 */
function RelationForm({ relationTypes, usableTypes, documents, tq, relationLabels }) {
    const [relationType, setRelationType] = useState('');
    const form = useForm({ from_page_id: '', to_page_id: '', relation_type: '' });

    // Resolved at render rather than held in state: which types are usable depends on what is
    // classified, and that changes under the form. A selection that is no longer offered falls
    // back to the first one that is, instead of silently posting a type the select stopped showing.
    const activeType = usableTypes.some((entry) => entry.key === relationType)
        ? relationType
        : (usableTypes[0]?.key ?? '');

    const fromCandidates = candidatesForRelationEnd(documents, relationTypes, activeType, 'from');
    const toCandidates = candidatesForRelationEnd(documents, relationTypes, activeType, 'to');

    if (usableTypes.length === 0) {
        return (
            <p className="mt-4 text-base leading-6 text-slate-500">
                {tq.relation_no_candidates
                    ?? 'Ingen dokumenter er klassifisert slik at denne relasjonen kan brukes.'}
            </p>
        );
    }

    const submit = (event) => {
        event.preventDefault();

        form.transform((data) => ({ ...data, relation_type: activeType }))
            .post('/app/quality/relations', {
                preserveScroll: true,
                onSuccess: () => form.reset('from_page_id', 'to_page_id'),
            });
    };

    return (
        <form onSubmit={submit} className="mt-4 grid gap-3 sm:grid-cols-4">
            <label className="space-y-1">
                <span className="text-sm font-semibold text-slate-600">{tq.relation_from ?? 'Fra'}</span>
                <select
                    className={INPUT}
                    value={form.data.from_page_id}
                    onChange={(event) => form.setData('from_page_id', event.target.value)}
                >
                    <option value="">—</option>
                    {fromCandidates.map((document) => (
                        <option key={document.page_id} value={document.page_id}>{documentLabel(document)}</option>
                    ))}
                </select>
            </label>

            <label className="space-y-1">
                <span className="text-sm font-semibold text-slate-600">{tq.relation_type ?? 'Relasjon'}</span>
                <select
                    className={INPUT}
                    value={activeType}
                    onChange={(event) => {
                        setRelationType(event.target.value);
                        // The old selections almost certainly fail the new type's matrix, and a
                        // stale id in a hidden select is exactly how an invalid pair gets posted.
                        form.setData({ ...form.data, from_page_id: '', to_page_id: '' });
                    }}
                >
                    {usableTypes.map((entry) => (
                        <option key={entry.key} value={entry.key}>
                            {relationLabels?.[entry.key] ?? entry.key}
                        </option>
                    ))}
                </select>
            </label>

            <label className="space-y-1">
                <span className="text-sm font-semibold text-slate-600">{tq.relation_to ?? 'Til'}</span>
                <select
                    className={INPUT}
                    value={form.data.to_page_id}
                    onChange={(event) => form.setData('to_page_id', event.target.value)}
                >
                    <option value="">—</option>
                    {toCandidates.map((document) => (
                        <option key={document.page_id} value={document.page_id}>{documentLabel(document)}</option>
                    ))}
                </select>
            </label>

            <div className="flex items-end">
                <button
                    type="submit"
                    className={PRIMARY_BUTTON}
                    disabled={form.processing || ! form.data.from_page_id || ! form.data.to_page_id}
                >
                    {tq.relation_submit ?? 'Legg til relasjon'}
                </button>
            </div>

            {Object.values(form.errors).length > 0 && (
                <p className="sm:col-span-4 text-base leading-6 text-rose-700">
                    {Object.values(form.errors)[0]}
                </p>
            )}
        </form>
    );
}

function ClassifyCard({ pages, search, qualityTypes, canManage, tq, typeLabels }) {
    const [query, setQuery] = useState(search ?? '');

    return (
        <section className={CARD}>
            <h2 className="text-xl font-semibold text-slate-950">
                {tq.classify_heading ?? 'Klassifiser Wiki-sider'}
            </h2>
            <p className="mt-1 max-w-3xl text-base leading-6 text-slate-600">
                {tq.classify_help
                    ?? 'Kvalitet legger faglig struktur oppå Wiki. Innholdet, eieren og godkjenningen blir værende i Wiki-siden.'}
            </p>

            <form
                className="mt-4 flex flex-wrap gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    router.get('/app/quality', { tab: 'overview', search: query }, {
                        preserveState: true,
                        preserveScroll: true,
                    });
                }}
            >
                <input
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder={tq.classify_search_placeholder ?? 'Søk etter Wiki-side …'}
                    className={`${INPUT} sm:max-w-sm`}
                />
                <button type="submit" className={QUIET_BUTTON}>
                    {tq.classify_search_submit ?? 'Søk'}
                </button>
            </form>

            {pages.length === 0 ? (
                <p className="mt-4 text-base leading-6 text-slate-500">
                    {tq.classify_empty ?? 'Alle synlige Wiki-sider er klassifisert, eller søket ga ingen treff.'}
                </p>
            ) : (
                <ul className="mt-4 space-y-2">
                    {pages.map((page) => (
                        <ClassifyRow
                            key={page.page_id}
                            page={page}
                            qualityTypes={qualityTypes}
                            canManage={canManage}
                            tq={tq}
                            typeLabels={typeLabels}
                        />
                    ))}
                </ul>
            )}
        </section>
    );
}

function ClassifyRow({ page, qualityTypes, canManage, tq, typeLabels }) {
    const form = useForm({ page_id: page.page_id, quality_type: '', quality_code: '' });

    return (
        <li className="flex flex-wrap items-center gap-3 rounded-xl bg-slate-50/60 px-3 py-2">
            <Link
                href={`/app/wiki/${page.slug}`}
                className="min-w-48 flex-1 text-base font-semibold text-slate-950 underline-offset-2 hover:underline"
            >
                {page.title}
            </Link>

            {canManage ? (
                <form
                    className="flex flex-wrap items-center gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/app/quality/classifications', { preserveScroll: true });
                    }}
                >
                    <select
                        className={`${INPUT} w-auto`}
                        value={form.data.quality_type}
                        onChange={(event) => form.setData('quality_type', event.target.value)}
                    >
                        <option value="">{tq.classify_type_placeholder ?? 'Velg type …'}</option>
                        {qualityTypes.map((type) => (
                            <option key={type} value={type}>{typeLabels?.[type] ?? type}</option>
                        ))}
                    </select>
                    <input
                        type="text"
                        className={`${INPUT} w-32`}
                        value={form.data.quality_code}
                        onChange={(event) => form.setData('quality_code', event.target.value)}
                        placeholder={tq.classify_code_placeholder ?? 'Dokumentnr.'}
                    />
                    <button type="submit" className={PRIMARY_BUTTON} disabled={form.processing || ! form.data.quality_type}>
                        {tq.classify_submit ?? 'Klassifiser'}
                    </button>
                </form>
            ) : (
                <span className="text-base text-slate-500">
                    {tq.manage_denied ?? 'Du kan se kvalitetssystemet, men ikke endre klassifisering eller relasjoner.'}
                </span>
            )}
        </li>
    );
}
