import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';
import StatusBadge from '../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import RiskAttention from './RiskAttention';
import RiskForm from './RiskForm';
import { RISK_LEVEL_TONES } from './riskLevel';
import { RISK_STATUS_TONES } from './riskStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';

/**
 * Risiko — the risk register.
 *
 * Everything on this page is already scoped by the server to the person's fagområder: the
 * rows, the count and the search. Nothing here filters for access; it only renders what it was
 * given.
 */
/**
 * The register when none of the person's roles reaches an area. A regular user is told who can
 * fix that; System Owner, who is that person, is sent to Kundemiljø → Tilganger instead.
 */
function NoAreasState({ accessSetup, tr }) {
    if (! accessSetup) {
        return (
            <EmptyStateBox
                title={tr.no_areas_title ?? 'Du har ingen fagområder for risiko ennå'}
                description={tr.no_areas_hint ?? 'Be System Owner gi en av rollene dine tilgang til et område under Kundemiljø → Tilganger.'}
            />
        );
    }

    const noneExist = ! accessSetup.customer_has_areas;

    return (
        <EmptyStateBox
            title={noneExist
                ? (tr.owner_no_areas_title ?? 'Ingen risikoområder er opprettet ennå')
                : (tr.owner_no_access_title ?? 'Du har ikke tilgang til noen risikoområder ennå')}
            description={noneExist
                ? (tr.owner_no_areas_hint ?? 'Risikoer registreres i risikoområder. Opprett et område under Kundemiljø → Tilganger, og gi en rolle tilgang til det.')
                : (tr.owner_no_access_hint ?? 'Tilgang til risikoer gis til en rolle under Kundemiljø → Tilganger. Gi en av rollene dine tilgang til et område for å se risikoene der.')}
        >
            <div className="mt-5">
                <Link href={accessSetup.manage_url} className={PRIMARY_ACTION}>
                    {noneExist
                        ? (tr.owner_create_area ?? 'Opprett risikoområde')
                        : (tr.owner_manage_access ?? 'Administrer risikotilgang')}
                </Link>
            </div>
        </EmptyStateBox>
    );
}

export default function RiskIndex() {
    const {
        translations = {},
        risks = [],
        visible_count: visibleCount = 0,
        filters = {},
        statuses = [],
        review_intervals: reviewIntervals = [],
        treatment_strategies: treatmentStrategies = [],
        has_areas: hasAreas = false,
        attention = null,
        access_setup: accessSetup = null,
        permissions = {},
        area_options: areaOptions = [],
        owner_options: ownerOptions = [],
    } = usePage().props;

    const tr = translations?.risk ?? {};
    const statusLabels = tr.statuses ?? {};
    const levelLabels = tr.assessment?.levels ?? {};
    const canCreate = permissions.can_create ?? false;

    const [creating, setCreating] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');

    const form = useForm({
        title: '',
        cause: '',
        event: '',
        consequence: '',
        description: '',
        business_area_id: areaOptions.length === 1 ? String(areaOptions[0].id) : '',
        owner_user_id: '',
        status: statuses[0] ?? 'identified',
        review_interval_months: '',
        treatment_strategy: '',
    });

    const submitSearch = (event) => {
        event.preventDefault();
        router.get('/app/risk', { search: search || undefined, status: status || undefined }, { preserveState: true, replace: true });
    };

    const resetSearch = () => {
        setSearch('');
        setStatus('');
        router.get('/app/risk', {}, { replace: true });
    };

    const submitCreate = (event) => {
        event.preventDefault();
        form.post('/app/risk/risks', { preserveScroll: true });
    };

    const filtered = Boolean(filters.search || filters.status);

    return (
        <CustomerAppLayout title={tr.index_title ?? 'Risiko'} showPageTitle={false}>
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-2">
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                            {tr.index_heading ?? 'Risikoregister'}
                        </h1>
                        <p className="max-w-3xl text-base leading-6 text-slate-600">
                            {tr.index_subtitle ?? 'Risikoene dere har ansvar for, samlet med eier, fagområde og status.'}
                        </p>
                        {hasAreas && (
                            <p className="text-sm text-slate-500">
                                {tr.scope_note ?? 'Du ser risikoene i fagområdene rollene dine gir deg.'}
                            </p>
                        )}
                    </div>
                    {canCreate && ! creating && (
                        <button type="button" onClick={() => setCreating(true)} className={PRIMARY_ACTION}>
                            {tr.create ?? 'Ny risiko'}
                        </button>
                    )}
                </header>

                {creating && (
                    <section className={CARD}>
                        <h2 className="mb-4 text-lg font-semibold text-slate-950">{tr.create_heading ?? 'Ny risiko'}</h2>
                        <RiskForm
                            form={form}
                            onSubmit={submitCreate}
                            onCancel={() => { setCreating(false); form.clearErrors(); }}
                            areaOptions={areaOptions}
                            ownerOptions={ownerOptions}
                            statuses={statuses}
                            statusLabels={statusLabels}
                            reviewIntervals={reviewIntervals}
                            treatmentStrategies={treatmentStrategies}
                            tr={tr}
                        />
                    </section>
                )}

                {hasAreas && attention && <RiskAttention attention={attention} tr={tr} />}

                {! hasAreas ? (
                    <NoAreasState accessSetup={accessSetup} tr={tr} />
                ) : (
                    <section className={CARD}>
                        <form onSubmit={submitSearch} className="flex flex-wrap items-end gap-3">
                            <div className="min-w-[16rem] flex-1">
                                <label htmlFor="risk-search" className="block text-sm font-semibold text-slate-700">
                                    {tr.search_label ?? 'Søk'}
                                </label>
                                <input
                                    id="risk-search"
                                    type="search"
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder={tr.search_placeholder ?? 'Søk i tittel og beskrivelse'}
                                    className={`mt-1 ${INPUT}`}
                                />
                            </div>
                            <div>
                                <label htmlFor="risk-status-filter" className="block text-sm font-semibold text-slate-700">
                                    {tr.status_filter ?? 'Status'}
                                </label>
                                <select
                                    id="risk-status-filter"
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
                            <button type="submit" className={SECONDARY_ACTION}>{tr.search ?? 'Søk'}</button>
                            {filtered && (
                                <button type="button" onClick={resetSearch} className={SECONDARY_ACTION}>
                                    {tr.reset ?? 'Nullstill'}
                                </button>
                            )}
                        </form>

                        <p className="mt-4 text-sm text-slate-500">
                            {(tr.count ?? ':count risikoer').replace(':count', String(visibleCount))}
                        </p>

                        {risks.length === 0 ? (
                            <EmptyStateBox
                                className="mt-4"
                                title={filtered ? (tr.no_matches ?? 'Ingen risikoer passer søket.') : (tr.empty_title ?? 'Ingen risikoer her ennå')}
                                description={filtered ? null : (canCreate ? (tr.empty_hint ?? '') : null)}
                            />
                        ) : (
                            <div className="mt-4 overflow-x-auto">
                                <table className="w-full text-base">
                                    <thead>
                                        <tr className="border-b border-slate-200 text-left text-sm font-semibold text-slate-600">
                                            <th className="pb-3 pr-4">{tr.col_title ?? 'Risiko'}</th>
                                            <th className="px-4 pb-3">{tr.col_area ?? 'Fagområde'}</th>
                                            <th className="px-4 pb-3">{tr.col_owner ?? 'Risikoeier'}</th>
                                            <th className="px-4 pb-3">{tr.col_residual ?? 'Restrisiko'}</th>
                                            <th className="pb-3 pl-4">{tr.col_status ?? 'Status'}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {risks.map((risk) => (
                                            <tr key={risk.id}>
                                                <td className="py-3 pr-4">
                                                    <Link href={risk.url} className="font-semibold text-violet-700 hover:text-violet-900">
                                                        {risk.title}
                                                    </Link>
                                                    {risk.has_structured_description ? (
                                                        <p className="mt-0.5 line-clamp-2 max-w-xl text-sm text-slate-500">{risk.event}</p>
                                                    ) : (
                                                        <p className="mt-0.5 text-sm text-amber-700">
                                                            {tr.structured?.missing_short ?? 'Mangler årsak, hendelse og konsekvens'}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-slate-700">{risk.area_name}</td>
                                                <td className="px-4 py-3 text-slate-700">{risk.owner_name ?? '—'}</td>
                                                <td className="px-4 py-3">
                                                    {risk.residual_level ? (
                                                        <StatusBadge tone={RISK_LEVEL_TONES[risk.residual_level] ?? 'slate'}>
                                                            {levelLabels[risk.residual_level] ?? risk.residual_level}
                                                        </StatusBadge>
                                                    ) : (
                                                        <span className="text-sm text-slate-500">{tr.assessment?.residual_none ?? 'Ikke vurdert'}</span>
                                                    )}
                                                </td>
                                                <td className="py-3 pl-4">
                                                    <StatusBadge tone={RISK_STATUS_TONES[risk.status] ?? 'slate'}>
                                                        {statusLabels[risk.status] ?? risk.status}
                                                    </StatusBadge>
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
