import { useMemo, useState } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';
import FilePickerField from '../../../Components/App/FilePickerField';
import QualityItemActions from '../../../Components/App/QualityItemActions';
import StatusBadge from '../../../Components/App/StatusBadge';
import { publicationLabel, publicationTone } from '../../../Support/processPublication';
import {
    DESTRUCTIVE_COLOURS,
    PRIMARY_ACTION,
    SECONDARY_ACTION,
} from '../../../Support/actionStyles';
import {
    candidatesForRelationEnd,
    candidatesForRelationStart,
    itemLabel,
    relationTypeIsUsable,
} from '../../../Support/qualityStructure';

/**
 * Kvalitet — the virksomhet's styrende dokumenter.
 *
 * Every row is a quality object of its own, with its own owner, number, status and review cycle. A
 * row leads to the document's page in Kvalitet, where its structure is edited and the Wiki pages
 * behind it are attached. Wiki is never typed or relabelled by any of this.
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
const ROW_DESTRUCTIVE = `ml-auto inline-flex min-h-9 items-center justify-center rounded-lg px-3 py-1.5 text-sm font-semibold transition ${DESTRUCTIVE_COLOURS}`;
const TAB_ACTIVE = 'inline-flex min-h-11 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-base font-semibold text-violet-700';

const TABS = ['overview', 'processes', 'controls', 'checklists'];

export default function QualityIndex() {
    const {
        translations = {},
        active_tab: activeTab = 'overview',
        permissions = {},
        items = [],
        type_counts: typeCounts = {},
        quality_types: qualityTypes = [],
        statuses = [],
        relation_types: relationTypes = [],
        relations = [],
        relation_item_options: relationItemOptions = [],
        owner_options: ownerOptions = [],
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

                <Tabs activeTab={activeTab} tq={tq} />

                {! canChange && (
                    <p className="text-sm text-slate-500">
                        {tq.manage_denied ?? 'Du kan se kvalitetssystemet, men ikke endre det.'}
                    </p>
                )}

                <ItemTable
                    items={items}
                    tq={tq}
                    canDelete={canDelete}
                    activeTab={activeTab}
                    typeLabels={typeLabels}
                    statusLabels={statusLabels}
                />

                {activeTab === 'overview' && (
                    <>
                        <RelationPanel
                            tq={tq}
                            canEdit={canEdit}
                            relations={relations}
                            relationTypes={relationTypes}
                            itemOptions={relationItemOptions}
                            typeLabels={typeLabels}
                        />

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
                    <p className="text-sm text-slate-600">{labels?.[type] ?? type}</p>
                </div>
            ))}
        </div>
    );
}

function Tabs({ activeTab, tq }) {
    return (
        <nav className="flex flex-wrap gap-2">
            {TABS.map((tab) => (
                <Link
                    key={tab}
                    href={`/app/quality?tab=${tab}`}
                    className={
                        tab === activeTab
                            ? TAB_ACTIVE
                            : SECONDARY_ACTION
                    }
                >
                    {tq[`tab_${tab}`] ?? tab}
                </Link>
            ))}
        </nav>
    );
}

function ItemTable({ items, tq, canDelete, activeTab, typeLabels, statusLabels }) {
    const table = tq.table ?? {};

    if (items.length === 0) {
        return (
            <EmptyStateBox
                title={tq.items_heading ?? 'Styrende dokumenter'}
                description={tq.items_empty ?? 'Ingen styrende dokumenter er registrert ennå.'}
            />
        );
    }

    return (
        <section className={CARD}>
            <h2 className="text-xl font-semibold text-slate-950">{tq.items_heading ?? 'Styrende dokumenter'}</h2>
            <div className="mt-4 overflow-x-auto">
                <table className="w-full min-w-[720px] text-left text-base">
                    <thead className="text-sm uppercase tracking-wide text-slate-500">
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
                                    {/* A process reads its status from its approved revisions; the
                                        other types have no revisions and show the stored status. */}
                                    {item.publication ? (
                                        <StatusBadge tone={publicationTone(item.publication)}>
                                            {publicationLabel(item.publication, tq.publication)}
                                        </StatusBadge>
                                    ) : (
                                        <StatusBadge tone={STATUS_TONES[item.status] ?? 'slate'}>
                                            {statusLabels?.[item.status] ?? item.status}
                                        </StatusBadge>
                                    )}
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
            <h2 className="text-xl font-semibold text-slate-950">{tq.create_heading ?? 'Nytt styrende dokument'}</h2>
            <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{tq.create_help ?? ''}</p>

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

                <Field label={tq.field_code ?? 'Dokumentnr.'} error={errors.code}>
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

function RelationPanel({ tq, canEdit, relations, relationTypes, itemOptions, typeLabels }) {
    const relationLabels = tq.relation_types ?? {};
    const usableTypes = useMemo(
        () => relationTypes.filter((entry) => relationTypeIsUsable(itemOptions, relationTypes, entry.key)),
        [relationTypes, itemOptions],
    );

    const [relationType, setRelationType] = useState(usableTypes[0]?.key ?? '');
    const { data, setData, post, delete: destroy, processing, errors, reset } = useForm({
        from_item_id: '',
        to_item_id: '',
        relation_type: '',
    });

    const fromCandidates = candidatesForRelationStart(itemOptions, relationTypes, relationType);
    const fromItem = fromCandidates.find((item) => String(item.id) === String(data.from_item_id)) ?? null;
    const toCandidates = candidatesForRelationEnd(
        itemOptions,
        relationTypes,
        relationType,
        fromItem?.quality_type ?? null,
    );

    function submit(event) {
        event.preventDefault();
        post('/app/quality/relations', {
            data: { ...data, relation_type: relationType },
            onSuccess: () => reset(),
        });
    }

    return (
        <section className={CARD}>
            <h2 className="text-xl font-semibold text-slate-950">{tq.relations_heading ?? 'Relasjoner'}</h2>
            <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{tq.relations_help ?? ''}</p>

            {relations.length === 0 ? (
                <p className="mt-4 text-base text-slate-600">{tq.relations_empty ?? 'Ingen relasjoner er opprettet ennå.'}</p>
            ) : (
                <ul className="mt-4 divide-y divide-slate-100">
                    {relations.map((relation) => (
                        <li key={relation.id} className="flex flex-wrap items-center gap-2 py-2 text-base text-slate-800">
                            <span className="font-semibold">{relation.from_title}</span>
                            <span className="text-slate-500">
                                {relationLabels?.[relation.relation_type] ?? relation.relation_type}
                            </span>
                            <span className="font-semibold">{relation.to_title}</span>
                            {canEdit && (
                                <button
                                    type="button"
                                    className={ROW_DESTRUCTIVE}
                                    onClick={() => {
                                        if (window.confirm(tq.relation_delete_confirm ?? 'Fjern relasjonen?')) {
                                            destroy(`/app/quality/relations/${relation.id}`);
                                        }
                                    }}
                                >
                                    {tq.relation_delete ?? 'Fjern'}
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canEdit && (
                usableTypes.length === 0 ? (
                    <p className="mt-4 text-sm text-slate-500">
                        {tq.relation_no_candidates ?? 'Ingen dokumenter er registrert slik at denne relasjonen kan brukes.'}
                    </p>
                ) : (
                    <form onSubmit={submit} className="mt-6 grid gap-4 md:grid-cols-4">
                        <Field label={tq.relation_type ?? 'Relasjon'} error={errors.relation_type}>
                            <select
                                className={INPUT}
                                value={relationType}
                                onChange={(event) => {
                                    setRelationType(event.target.value);
                                    setData({ from_item_id: '', to_item_id: '', relation_type: '' });
                                }}
                            >
                                {usableTypes.map((entry) => (
                                    <option key={entry.key} value={entry.key}>
                                        {relationLabels?.[entry.key] ?? entry.key}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label={tq.relation_from ?? 'Fra'} error={errors.from_item_id}>
                            <select
                                className={INPUT}
                                value={data.from_item_id}
                                onChange={(event) => setData({ ...data, from_item_id: event.target.value, to_item_id: '' })}
                            >
                                <option value="">—</option>
                                {fromCandidates.map((item) => (
                                    <option key={item.id} value={item.id}>{itemLabel(item)}</option>
                                ))}
                            </select>
                        </Field>

                        <Field label={tq.relation_to ?? 'Til'} error={errors.to_item_id}>
                            <select
                                className={INPUT}
                                value={data.to_item_id}
                                onChange={(event) => setData('to_item_id', event.target.value)}
                            >
                                <option value="">—</option>
                                {toCandidates.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {itemLabel(item)} ({typeLabels?.[item.quality_type] ?? item.quality_type})
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <div className="flex items-end">
                            <button type="submit" className={PRIMARY_ACTION} disabled={processing}>
                                {tq.relation_submit ?? 'Legg til relasjon'}
                            </button>
                        </div>
                    </form>
                )
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
