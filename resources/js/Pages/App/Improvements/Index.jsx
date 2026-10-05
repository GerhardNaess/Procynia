import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import StatusBadge from '../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import ImprovementForm from './ImprovementForm';
import { improvementHelp } from './improvementHelp';
import { IMPROVEMENT_STATUS_TONES, IMPROVEMENT_TYPE_TONES, formatDay } from './improvementStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const FILTER_LABEL = 'block text-base font-semibold text-slate-700';

/**
 * The register when none of the person's roles reaches an area. A regular user is told who can fix
 * that; System Owner, who is that person, is sent to Kundemiljø → Tilganger instead.
 */
function NoAreasState({ accessSetup, tr }) {
    if (! accessSetup) {
        return (
            <EmptyStateBox
                title={tr.no_areas_title ?? 'Du har ikke tilgang til noen fagområder i Avvik og forbedringer ennå'}
                description={tr.no_areas_hint ?? 'Be System Owner gi en av rollene dine fagområder under Kundemiljø → Tilganger.'}
            />
        );
    }

    const noneExist = ! accessSetup.customer_has_areas;

    return (
        <EmptyStateBox
            title={noneExist
                ? (tr.owner_no_areas_title ?? 'Ingen fagområder er opprettet ennå')
                : (tr.owner_no_access_title ?? 'Du har ikke tilgang til noen fagområder i Avvik og forbedringer ennå')}
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

/** Frist as the register shows it, with «Frist passert» while the case is still being worked. */
function DueDate({ item, tr }) {
    return (
        <span className="flex flex-wrap items-center gap-x-2">
            <span>{formatDay(item.due_date, tr.no_due_date ?? 'Ingen frist')}</span>
            {item.is_overdue && <span className="font-semibold text-amber-800">{tr.overdue ?? 'Frist passert'}</span>}
        </span>
    );
}

/**
 * Avvik og forbedringer — the register. Everything on this page is already scoped by the server to
 * the person's fagområder: the rows, the count, the search and the area filter.
 */
export default function ImprovementsIndex() {
    const {
        translations = {},
        cases = [],
        visible_count: visibleCount = 0,
        filters = {},
        types = [],
        statuses = [],
        filter_area_options: filterAreaOptions = [],
        has_areas: hasAreas = false,
        access_setup: accessSetup = null,
        permissions = {},
        area_options: areaOptions = [],
        owner_options: ownerOptions = [],
        today = '',
    } = usePage().props;

    const tr = translations?.improvements ?? {};
    const statusLabels = tr.statuses ?? {};
    const typeLabels = tr.types ?? {};
    const canCreate = permissions.can_create ?? false;

    const [creating, setCreating] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [type, setType] = useState(filters.type ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [area, setArea] = useState(filters.area ? String(filters.area) : '');

    const form = useForm({
        type: '',
        title: '',
        description: '',
        business_area_id: areaOptions.length === 1 ? String(areaOptions[0].id) : '',
        owner_user_id: '',
        occurred_at: '',
        due_date: '',
    });

    const startCreate = (caseType) => {
        form.setData('type', caseType);
        setCreating(true);
    };

    const submitSearch = (event) => {
        event.preventDefault();
        router.get('/app/improvements', {
            search: search || undefined,
            type: type || undefined,
            status: status || undefined,
            area: area || undefined,
        }, { preserveState: true, replace: true });
    };

    const resetSearch = () => {
        setSearch('');
        setType('');
        setStatus('');
        setArea('');
        router.get('/app/improvements', {}, { replace: true });
    };

    const submitCreate = (event) => {
        event.preventDefault();
        form.post('/app/improvements', { preserveScroll: true });
    };

    const filtered = Boolean(filters.search || filters.type || filters.status || filters.area);
    const count = visibleCount === 1 ? (tr.count_one ?? '1 sak') : (tr.count ?? ':count saker').replace(':count', String(visibleCount));

    return (
        <CustomerAppLayout title={tr.index_title ?? 'Avvik og forbedringer'} showPageTitle={false}>
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">{tr.index_heading ?? 'Avvik og forbedringer'}</h1>
                        <p className="max-w-3xl text-base leading-6 text-slate-600">
                            {tr.index_subtitle ?? 'Det som ikke er som det skal, og det som kan bli bedre – med ansvarlig, frist og behandling samlet på ett sted.'}
                        </p>
                        {hasAreas && (
                            <p className="text-base text-slate-600">{tr.scope_note ?? 'Du ser sakene i fagområdene rollene dine gir deg.'}</p>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <PageHelpButton {...improvementHelp(tr, 'index')} />
                        {canCreate && ! creating && (
                            <>
                                <button type="button" onClick={() => startCreate('deviation')} className={PRIMARY_ACTION}>
                                    {tr.create_deviation ?? 'Registrer avvik'}
                                </button>
                                <button type="button" onClick={() => startCreate('improvement')} className={PRIMARY_ACTION}>
                                    {tr.create_improvement ?? 'Registrer forbedring'}
                                </button>
                            </>
                        )}
                    </div>
                </header>

                {creating && (
                    <section className={CARD} aria-labelledby="improvement-create-heading">
                        <h2 id="improvement-create-heading" className="mb-4 text-xl font-semibold text-slate-950">{tr.create_heading ?? 'Registrer sak'}</h2>
                        <ImprovementForm
                            form={form}
                            onSubmit={submitCreate}
                            onCancel={() => { setCreating(false); form.reset(); form.clearErrors(); }}
                            types={types}
                            areaOptions={areaOptions}
                            ownerOptions={ownerOptions}
                            today={today}
                            tr={tr}
                        />
                    </section>
                )}

                {! hasAreas ? (
                    <NoAreasState accessSetup={accessSetup} tr={tr} />
                ) : (
                    <section className={CARD}>
                        <form onSubmit={submitSearch} className="grid gap-3 sm:flex sm:flex-wrap sm:items-end">
                            <div className="min-w-0 sm:min-w-[16rem] sm:flex-1">
                                <label htmlFor="improvement-search" className={FILTER_LABEL}>{tr.search_label ?? 'Søk'}</label>
                                <input
                                    id="improvement-search"
                                    type="search"
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder={tr.search_placeholder ?? 'Søk i tittel og beskrivelse'}
                                    className={`mt-1 ${INPUT}`}
                                />
                            </div>
                            <div>
                                <label htmlFor="improvement-type-filter" className={FILTER_LABEL}>{tr.type_filter ?? 'Type'}</label>
                                <select id="improvement-type-filter" value={type} onChange={(event) => setType(event.target.value)} className={`mt-1 ${INPUT}`}>
                                    <option value="">{tr.all_types ?? 'Alle typer'}</option>
                                    {types.map((value) => (
                                        <option key={value} value={value}>{typeLabels[value] ?? value}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label htmlFor="improvement-status-filter" className={FILTER_LABEL}>{tr.status_filter ?? 'Status'}</label>
                                <select id="improvement-status-filter" value={status} onChange={(event) => setStatus(event.target.value)} className={`mt-1 ${INPUT}`}>
                                    <option value="">{tr.all_statuses ?? 'Alle statuser'}</option>
                                    {statuses.map((value) => (
                                        <option key={value} value={value}>{statusLabels[value] ?? value}</option>
                                    ))}
                                </select>
                            </div>
                            {filterAreaOptions.length > 1 && (
                                <div>
                                    <label htmlFor="improvement-area-filter" className={FILTER_LABEL}>{tr.area_filter ?? 'Fagområde'}</label>
                                    <select id="improvement-area-filter" value={area} onChange={(event) => setArea(event.target.value)} className={`mt-1 ${INPUT}`}>
                                        <option value="">{tr.all_areas ?? 'Alle fagområder'}</option>
                                        {filterAreaOptions.map((option) => (
                                            <option key={option.id} value={option.id}>{option.name}</option>
                                        ))}
                                    </select>
                                </div>
                            )}
                            <div className="flex flex-wrap gap-2">
                                <button type="submit" className={SECONDARY_ACTION}>{tr.search ?? 'Søk'}</button>
                                {filtered && (
                                    <button type="button" onClick={resetSearch} className={SECONDARY_ACTION}>{tr.reset ?? 'Nullstill'}</button>
                                )}
                            </div>
                        </form>

                        <p className="mt-4 text-base text-slate-600" data-testid="improvement-count">{count}</p>

                        {cases.length === 0 ? (
                            <EmptyStateBox
                                className="mt-4"
                                title={filtered ? (tr.no_matches ?? 'Ingen saker passer søket.') : (tr.empty_title ?? 'Ingen saker her ennå')}
                                description={filtered ? null : (canCreate ? (tr.empty_hint ?? '') : null)}
                            />
                        ) : (
                            <>
                                {/* Phones: one card per case, so nothing has to scroll sideways. */}
                                <ul className="mt-4 divide-y divide-slate-100 md:hidden" data-testid="improvement-list">
                                    {cases.map((item) => (
                                        <li key={item.id} className="space-y-2 py-4">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <StatusBadge tone={IMPROVEMENT_TYPE_TONES[item.type] ?? 'slate'}>{typeLabels[item.type] ?? item.type}</StatusBadge>
                                                <StatusBadge tone={IMPROVEMENT_STATUS_TONES[item.status] ?? 'slate'}>{statusLabels[item.status] ?? item.status}</StatusBadge>
                                            </div>
                                            <Link href={item.url} className="block break-words text-base font-semibold text-violet-700 hover:text-violet-900">{item.title}</Link>
                                            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-base">
                                                <dt className="font-semibold text-slate-600">{tr.col_area ?? 'Fagområde'}</dt>
                                                <dd className="min-w-0 break-words text-slate-800">{item.area_name}</dd>
                                                <dt className="font-semibold text-slate-600">{tr.col_owner ?? 'Ansvarlig'}</dt>
                                                <dd className="min-w-0 break-words text-slate-800">
                                                    {item.owner_name ?? <span className="text-amber-800">{tr.no_owner ?? 'Mangler ansvarlig'}</span>}
                                                </dd>
                                                <dt className="font-semibold text-slate-600">{tr.col_due_date ?? 'Frist'}</dt>
                                                <dd className="text-slate-800"><DueDate item={item} tr={tr} /></dd>
                                            </dl>
                                        </li>
                                    ))}
                                </ul>

                                <div className="mt-4 hidden overflow-x-auto md:block">
                                    <table className="w-full text-base">
                                        <thead>
                                            <tr className="border-b border-slate-200 text-left text-base font-semibold text-slate-600">
                                                <th className="pb-3 pr-4">{tr.col_type ?? 'Type'}</th>
                                                <th className="px-4 pb-3">{tr.col_title ?? 'Tittel'}</th>
                                                <th className="px-4 pb-3">{tr.col_area ?? 'Fagområde'}</th>
                                                <th className="px-4 pb-3">{tr.col_owner ?? 'Ansvarlig'}</th>
                                                <th className="px-4 pb-3">{tr.col_status ?? 'Status'}</th>
                                                <th className="pb-3 pl-4">{tr.col_due_date ?? 'Frist'}</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {cases.map((item) => (
                                                <tr key={item.id}>
                                                    <td className="py-3 pr-4">
                                                        <StatusBadge tone={IMPROVEMENT_TYPE_TONES[item.type] ?? 'slate'}>{typeLabels[item.type] ?? item.type}</StatusBadge>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <Link href={item.url} className="font-semibold text-violet-700 hover:text-violet-900">{item.title}</Link>
                                                    </td>
                                                    <td className="px-4 py-3 text-slate-700">{item.area_name}</td>
                                                    <td className="px-4 py-3 text-slate-700">
                                                        {item.owner_name ?? <span className="text-amber-800">{tr.no_owner ?? 'Mangler ansvarlig'}</span>}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <StatusBadge tone={IMPROVEMENT_STATUS_TONES[item.status] ?? 'slate'}>{statusLabels[item.status] ?? item.status}</StatusBadge>
                                                    </td>
                                                    <td className="py-3 pl-4 text-slate-700"><DueDate item={item} tr={tr} /></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </>
                        )}
                    </section>
                )}
            </div>
        </CustomerAppLayout>
    );
}
