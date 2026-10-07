import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import StatusBadge from '../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import SupplierForm from './SupplierForm';
import { supplierHelp } from './supplierHelp';
import SupplierCriticalityBadge from './SupplierCriticalityBadge';
import { formatLongDate } from '../Improvements/improvementStatus';
import { SUPPLIER_STATUS_TONES, categoryLabel, countLabel, criticalityLabel, emptyCriticality, nextReviewText, statusLabel } from './supplierManagement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const FILTER_LABEL = 'block text-base font-semibold text-slate-700';
const REGISTER_URL = '/app/supplier-management';

function Owner({ item, tr }) {
    return item.owner_name ?? <span className="text-amber-800">{tr.no_owner ?? 'Mangler ansvarlig'}</span>;
}

/**
 * Leverandøroppfølging → Leverandører: the register. Everything here is already scoped by the
 * server: the rows, the count and the search are the user's own customer's, and none at all
 * without supplier.view. «Registrer leverandør» is offered only with supplier.edit; the server
 * refuses it otherwise.
 *
 * The status filter starts at «Ikke avsluttet»: ended suppliers stay in the register, one choice
 * away.
 */
export default function SupplierManagementIndex() {
    const {
        translations = {},
        suppliers = [],
        visible_count: visibleCount = 0,
        filters = {},
        statuses = [],
        initial_statuses: initialStatuses = [],
        categories = [],
        criticalities = [],
        review_intervals: reviewIntervals = [],
        permissions = {},
        owner_options: ownerOptions = [],
        locale = 'no',
    } = usePage().props;

    const tr = translations?.supplier_management ?? {};
    const canEdit = permissions.can_edit ?? false;
    const nextReview = (item) => nextReviewText(item, tr, (date) => formatLongDate(date, locale));

    const [creating, setCreating] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [category, setCategory] = useState(filters.category ?? '');
    const [criticality, setCriticality] = useState(filters.criticality ?? '');

    const form = useForm({
        name: '',
        organization_number: '',
        category: '',
        deliverable_description: '',
        owner_user_id: '',
        contact_name: '',
        contact_email: '',
        contact_phone: '',
        note: '',
        initial_status: 'active',
        ...emptyCriticality(),
    });

    const submitSearch = (event) => {
        event.preventDefault();
        router.get(REGISTER_URL, {
            search: search || undefined,
            status: status || undefined,
            category: category || undefined,
            criticality: criticality || undefined,
        }, { preserveState: true, replace: true });
    };

    const resetSearch = () => {
        setSearch('');
        setStatus('');
        setCategory('');
        setCriticality('');
        router.get(REGISTER_URL, {}, { replace: true });
    };

    const submitCreate = (event) => {
        event.preventDefault();
        form.post(REGISTER_URL, { preserveScroll: true });
    };

    const filtered = Boolean(filters.search || filters.status || filters.category || filters.criticality);

    return (
        <CustomerAppLayout title={tr.index_title ?? 'Leverandører'} showPageTitle={false}>
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <p className="text-base font-semibold text-violet-700">{tr.module_name ?? 'Leverandøroppfølging'}</p>
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">{tr.index_heading ?? 'Leverandører'}</h1>
                        <p className="max-w-3xl text-base leading-6 text-slate-600">
                            {tr.index_intro ?? 'Leverandørene virksomheten er avhengig av, hva de leverer og hvem hos dere som følger dem opp.'}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <PageHelpButton {...supplierHelp(tr, 'index')} />
                        {canEdit && ! creating && (
                            <button type="button" onClick={() => setCreating(true)} className={PRIMARY_ACTION}>
                                {tr.create ?? 'Registrer leverandør'}
                            </button>
                        )}
                    </div>
                </header>

                {creating && (
                    <section className={CARD} aria-labelledby="supplier-create-heading">
                        <h2 id="supplier-create-heading" className="mb-4 text-xl font-semibold text-slate-950">{tr.create_heading ?? 'Registrer leverandør'}</h2>
                        <SupplierForm
                            form={form}
                            onSubmit={submitCreate}
                            onCancel={() => { setCreating(false); form.reset(); form.clearErrors(); }}
                            categories={categories}
                            ownerOptions={ownerOptions}
                            initialStatuses={initialStatuses}
                            reviewIntervals={reviewIntervals}
                            tr={tr}
                        />
                    </section>
                )}

                {visibleCount === 0 ? (
                    <EmptyStateBox
                        title={tr.empty_title ?? 'Ingen leverandører er registrert ennå'}
                        description={canEdit ? (tr.empty_hint ?? '') : (tr.empty_text ?? '')}
                    />
                ) : (
                    <section className={CARD}>
                        <form onSubmit={submitSearch} className="grid gap-3 sm:flex sm:flex-wrap sm:items-end">
                            <div className="min-w-0 sm:min-w-[16rem] sm:flex-1">
                                <label htmlFor="supplier-search" className={FILTER_LABEL}>{tr.search_label ?? 'Søk'}</label>
                                <input
                                    id="supplier-search"
                                    type="search"
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder={tr.search_placeholder ?? 'Søk på navn eller organisasjonsnummer'}
                                    className={`mt-1 ${INPUT}`}
                                />
                            </div>
                            <div className="min-w-0">
                                <label htmlFor="supplier-status-filter" className={FILTER_LABEL}>{tr.status_filter ?? 'Status'}</label>
                                <select id="supplier-status-filter" value={status} onChange={(event) => setStatus(event.target.value)} className={`mt-1 ${INPUT}`}>
                                    <option value="">{tr.status_filter_open ?? 'Ikke avsluttet'}</option>
                                    <option value="all">{tr.status_filter_all ?? 'Alle statuser'}</option>
                                    {statuses.map((value) => (
                                        <option key={value} value={value}>{statusLabel(value, tr)}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="min-w-0">
                                <label htmlFor="supplier-category-filter" className={FILTER_LABEL}>{tr.category_filter ?? 'Kategori'}</label>
                                <select id="supplier-category-filter" value={category} onChange={(event) => setCategory(event.target.value)} className={`mt-1 ${INPUT}`}>
                                    <option value="">{tr.all_categories ?? 'Alle kategorier'}</option>
                                    {categories.map((value) => (
                                        <option key={value} value={value}>{categoryLabel(value, tr)}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="min-w-0">
                                <label htmlFor="supplier-criticality-filter" className={FILTER_LABEL}>{tr.criticality_filter ?? 'Kritikalitet'}</label>
                                <select id="supplier-criticality-filter" value={criticality} onChange={(event) => setCriticality(event.target.value)} className={`mt-1 ${INPUT}`}>
                                    <option value="">{tr.all_criticalities ?? 'Alle nivåer'}</option>
                                    {criticalities.map((value) => (
                                        <option key={value} value={value}>{criticalityLabel(value, tr)}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <button type="submit" className={SECONDARY_ACTION}>{tr.search ?? 'Søk'}</button>
                                {filtered && (
                                    <button type="button" onClick={resetSearch} className={SECONDARY_ACTION}>{tr.reset ?? 'Nullstill'}</button>
                                )}
                            </div>
                        </form>

                        <p className="mt-4 text-base text-slate-600" data-testid="supplier-count">{countLabel(visibleCount, tr)}</p>

                        {suppliers.length === 0 ? (
                            <EmptyStateBox
                                className="mt-4"
                                title={filtered ? (tr.no_matches ?? 'Ingen leverandører passer søket.') : (tr.no_open ?? 'Ingen leverandører er under vurdering eller aktive. Velg «Alle statuser» for å se de avsluttede.')}
                            />
                        ) : (
                            <>
                                {/* Phones: one card per supplier, so nothing has to scroll sideways. */}
                                <ul className="mt-4 divide-y divide-slate-100 md:hidden" data-testid="supplier-list">
                                    {suppliers.map((item) => (
                                        <li key={item.id} className="space-y-2 py-4">
                                            <StatusBadge tone={SUPPLIER_STATUS_TONES[item.status] ?? 'slate'}>{statusLabel(item.status, tr)}</StatusBadge>
                                            <Link href={item.url} className="block break-words text-base font-semibold text-violet-700 hover:text-violet-900">{item.name}</Link>
                                            {item.organization_number && <p className="break-words text-base text-slate-600">{item.organization_number}</p>}
                                            <p className="break-words text-base text-slate-800">{item.deliverable_description}</p>
                                            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-base">
                                                <dt className="font-semibold text-slate-600">{tr.col_category ?? 'Kategori'}</dt>
                                                <dd className="min-w-0 break-words text-slate-800">{categoryLabel(item.category, tr)}</dd>
                                                <dt className="font-semibold text-slate-600">{tr.col_criticality ?? 'Kritikalitet'}</dt>
                                                <dd className="min-w-0 break-words text-slate-800"><SupplierCriticalityBadge level={item.criticality} tr={tr} /></dd>
                                                <dt className="font-semibold text-slate-600">{tr.col_next_review ?? 'Neste vurdering'}</dt>
                                                <dd className="min-w-0 break-words text-slate-800">{nextReview(item)}</dd>
                                                <dt className="font-semibold text-slate-600">{tr.col_owner ?? 'Intern ansvarlig'}</dt>
                                                <dd className="min-w-0 break-words text-slate-800"><Owner item={item} tr={tr} /></dd>
                                            </dl>
                                        </li>
                                    ))}
                                </ul>

                                <div className="mt-4 hidden overflow-x-auto md:block">
                                    <table className="w-full text-base" data-testid="supplier-table">
                                        <thead>
                                            <tr className="border-b border-slate-200 text-left text-base font-semibold text-slate-600">
                                                <th className="pb-3 pr-4">{tr.col_name ?? 'Leverandør'}</th>
                                                <th className="px-4 pb-3">{tr.col_category ?? 'Kategori'}</th>
                                                <th className="px-4 pb-3">{tr.col_criticality ?? 'Kritikalitet'}</th>
                                                <th className="px-4 pb-3">{tr.col_deliverable ?? 'Leverer'}</th>
                                                <th className="px-4 pb-3">{tr.col_owner ?? 'Intern ansvarlig'}</th>
                                                <th className="px-4 pb-3">{tr.col_next_review ?? 'Neste vurdering'}</th>
                                                <th className="pb-3 pl-4">{tr.col_status ?? 'Status'}</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {suppliers.map((item) => (
                                                <tr key={item.id}>
                                                    <td className="py-3 pr-4 align-top">
                                                        <Link href={item.url} className="font-semibold text-violet-700 hover:text-violet-900">{item.name}</Link>
                                                        {item.organization_number && <p className="text-base text-slate-600">{item.organization_number}</p>}
                                                    </td>
                                                    <td className="px-4 py-3 align-top text-slate-700">{categoryLabel(item.category, tr)}</td>
                                                    <td className="px-4 py-3 align-top"><SupplierCriticalityBadge level={item.criticality} tr={tr} /></td>
                                                    <td className="max-w-md px-4 py-3 align-top text-slate-700"><span className="line-clamp-2 break-words">{item.deliverable_description}</span></td>
                                                    <td className="px-4 py-3 align-top text-slate-700"><Owner item={item} tr={tr} /></td>
                                                    <td className="px-4 py-3 align-top text-slate-700">{nextReview(item)}</td>
                                                    <td className="py-3 pl-4 align-top">
                                                        <StatusBadge tone={SUPPLIER_STATUS_TONES[item.status] ?? 'slate'}>{statusLabel(item.status, tr)}</StatusBadge>
                                                    </td>
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
