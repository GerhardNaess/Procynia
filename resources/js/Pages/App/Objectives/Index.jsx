import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';
import StatusBadge from '../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import ObjectiveForm from './ObjectiveForm';
import { OBJECTIVE_STATUS_TONES, formatTargetDate } from './objectiveStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';

/**
 * The overview when none of the person's roles reaches an area. A regular user is told who can fix
 * that; System Owner, who is that person, is sent to Kundemiljø → Tilganger instead.
 */
function NoAreasState({ accessSetup, tr }) {
    if (! accessSetup) {
        return (
            <EmptyStateBox
                title={tr.no_areas_title ?? 'Du har ikke tilgang til noen fagområder i Mål og KPI ennå'}
                description={tr.no_areas_hint ?? 'Be System Owner gi en av rollene dine fagområder under Kundemiljø → Tilganger.'}
            />
        );
    }

    const noneExist = ! accessSetup.customer_has_areas;

    return (
        <EmptyStateBox
            title={noneExist
                ? (tr.owner_no_areas_title ?? 'Ingen fagområder er opprettet ennå')
                : (tr.owner_no_access_title ?? 'Du har ikke tilgang til noen fagområder i Mål og KPI ennå')}
            description={noneExist ? (tr.owner_no_areas_hint ?? '') : (tr.owner_no_access_hint ?? '')}
        >
            <div className="mt-5">
                <Link href={accessSetup.manage_url} className={PRIMARY_ACTION}>
                    {noneExist ? (tr.owner_create_area ?? 'Opprett fagområde') : (tr.owner_manage_access ?? 'Administrer tilgang')}
                </Link>
            </div>
        </EmptyStateBox>
    );
}

/**
 * Mål — the objectives. Everything on this page is already scoped by the server to the person's
 * fagområder: the rows, the count, the search and the area filter. Nothing here filters for access.
 */
export default function ObjectivesIndex() {
    const {
        translations = {},
        objectives = [],
        visible_count: visibleCount = 0,
        filters = {},
        statuses = [],
        filter_area_options: filterAreaOptions = [],
        has_areas: hasAreas = false,
        access_setup: accessSetup = null,
        permissions = {},
        area_options: areaOptions = [],
        owner_options: ownerOptions = [],
    } = usePage().props;

    const tr = translations?.objectives ?? {};
    const statusLabels = tr.statuses ?? {};
    const canCreate = permissions.can_create ?? false;

    const [creating, setCreating] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [area, setArea] = useState(filters.area ? String(filters.area) : '');

    const form = useForm({
        title: '',
        description: '',
        business_area_id: areaOptions.length === 1 ? String(areaOptions[0].id) : '',
        owner_user_id: '',
        target_date: '',
    });

    const submitSearch = (event) => {
        event.preventDefault();
        router.get('/app/objectives', {
            search: search || undefined,
            status: status || undefined,
            area: area || undefined,
        }, { preserveState: true, replace: true });
    };

    const resetSearch = () => {
        setSearch('');
        setStatus('');
        setArea('');
        router.get('/app/objectives', {}, { replace: true });
    };

    const submitCreate = (event) => {
        event.preventDefault();
        form.post('/app/objectives', { preserveScroll: true });
    };

    const filtered = Boolean(filters.search || filters.status || filters.area);

    return (
        <CustomerAppLayout title={tr.index_title ?? 'Mål og KPI'} showPageTitle={false}>
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-2">
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">{tr.index_heading ?? 'Mål'}</h1>
                        <p className="max-w-3xl text-base leading-6 text-slate-600">
                            {tr.index_subtitle ?? 'Det virksomheten skal oppnå, samlet med ansvarlig, fagområde og måldato.'}
                        </p>
                        {hasAreas && (
                            <p className="text-sm text-slate-500">{tr.scope_note ?? 'Du ser målene i fagområdene rollene dine gir deg.'}</p>
                        )}
                    </div>
                    {canCreate && ! creating && (
                        <button type="button" onClick={() => setCreating(true)} className={PRIMARY_ACTION}>
                            {tr.create ?? 'Nytt mål'}
                        </button>
                    )}
                </header>

                {creating && (
                    <section className={CARD}>
                        <h2 className="mb-4 text-lg font-semibold text-slate-950">{tr.create_heading ?? 'Nytt mål'}</h2>
                        <ObjectiveForm
                            form={form}
                            onSubmit={submitCreate}
                            onCancel={() => { setCreating(false); form.clearErrors(); }}
                            areaOptions={areaOptions}
                            ownerOptions={ownerOptions}
                            tr={tr}
                        />
                    </section>
                )}

                {! hasAreas ? (
                    <NoAreasState accessSetup={accessSetup} tr={tr} />
                ) : (
                    <section className={CARD}>
                        <form onSubmit={submitSearch} className="flex flex-wrap items-end gap-3">
                            <div className="min-w-[16rem] flex-1">
                                <label htmlFor="objective-search" className="block text-sm font-semibold text-slate-700">
                                    {tr.search_label ?? 'Søk'}
                                </label>
                                <input
                                    id="objective-search"
                                    type="search"
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder={tr.search_placeholder ?? 'Søk i tittel og beskrivelse'}
                                    className={`mt-1 ${INPUT}`}
                                />
                            </div>
                            <div>
                                <label htmlFor="objective-status-filter" className="block text-sm font-semibold text-slate-700">
                                    {tr.status_filter ?? 'Status'}
                                </label>
                                <select
                                    id="objective-status-filter"
                                    value={status}
                                    onChange={(event) => setStatus(event.target.value)}
                                    className={`mt-1 ${INPUT}`}
                                >
                                    <option value="">{tr.all_statuses ?? 'Alle statuser'}</option>
                                    {statuses.map((value) => (
                                        <option key={value} value={value}>{statusLabels[value] ?? value}</option>
                                    ))}
                                </select>
                            </div>
                            {filterAreaOptions.length > 1 && (
                                <div>
                                    <label htmlFor="objective-area-filter" className="block text-sm font-semibold text-slate-700">
                                        {tr.area_filter ?? 'Fagområde'}
                                    </label>
                                    <select
                                        id="objective-area-filter"
                                        value={area}
                                        onChange={(event) => setArea(event.target.value)}
                                        className={`mt-1 ${INPUT}`}
                                    >
                                        <option value="">{tr.all_areas ?? 'Alle fagområder'}</option>
                                        {filterAreaOptions.map((option) => (
                                            <option key={option.id} value={option.id}>{option.name}</option>
                                        ))}
                                    </select>
                                </div>
                            )}
                            <button type="submit" className={SECONDARY_ACTION}>{tr.search ?? 'Søk'}</button>
                            {filtered && (
                                <button type="button" onClick={resetSearch} className={SECONDARY_ACTION}>
                                    {tr.reset ?? 'Nullstill'}
                                </button>
                            )}
                        </form>

                        <p className="mt-4 text-sm text-slate-500">
                            {(tr.count ?? ':count mål').replace(':count', String(visibleCount))}
                        </p>

                        {objectives.length === 0 ? (
                            <EmptyStateBox
                                className="mt-4"
                                title={filtered ? (tr.no_matches ?? 'Ingen mål passer søket.') : (tr.empty_title ?? 'Ingen mål her ennå')}
                                description={filtered ? null : (canCreate ? (tr.empty_hint ?? '') : null)}
                            />
                        ) : (
                            <div className="mt-4 overflow-x-auto">
                                <table className="w-full text-base">
                                    <thead>
                                        <tr className="border-b border-slate-200 text-left text-sm font-semibold text-slate-600">
                                            <th className="pb-3 pr-4">{tr.col_title ?? 'Mål'}</th>
                                            <th className="px-4 pb-3">{tr.col_area ?? 'Fagområde'}</th>
                                            <th className="px-4 pb-3">{tr.col_owner ?? 'Ansvarlig'}</th>
                                            <th className="px-4 pb-3">{tr.col_status ?? 'Status'}</th>
                                            <th className="pb-3 pl-4">{tr.col_target_date ?? 'Måldato'}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {objectives.map((objective) => (
                                            <tr key={objective.id}>
                                                <td className="py-3 pr-4">
                                                    <Link href={objective.url} className="font-semibold text-violet-700 hover:text-violet-900">
                                                        {objective.title}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3 text-slate-700">{objective.area_name}</td>
                                                <td className="px-4 py-3 text-slate-700">
                                                    {objective.owner_name ?? <span className="text-amber-700">{tr.no_owner ?? 'Mangler ansvarlig'}</span>}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <StatusBadge tone={OBJECTIVE_STATUS_TONES[objective.status] ?? 'slate'}>
                                                        {statusLabels[objective.status] ?? objective.status}
                                                    </StatusBadge>
                                                </td>
                                                <td className="py-3 pl-4 text-slate-700">
                                                    {formatTargetDate(objective.target_date, tr.running ?? 'Løpende')}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                )}
            </div>
        </CustomerAppLayout>
    );
}
