import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

/**
 * Purpose:
 * Format supplier timestamps for the customer-facing supplier list.
 *
 * Inputs:
 * ISO-8601 timestamp strings.
 *
 * Returns:
 * string
 *
 * Side effects:
 * None.
 */
function formatDateTime(value, locale) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

/**
 * Purpose:
 * Format a supplier total estimated value as a whole-number currency string.
 *
 * Inputs:
 * A numeric amount and an optional currency code from the supplier list payload.
 *
 * Returns:
 * string
 *
 * Side effects:
 * None.
 */
function formatEstimatedValue(amount, currencyCode) {
    if (amount === null || amount === undefined || amount === '') {
        return '—';
    }

    const numericValue = Number(amount);

    if (!Number.isFinite(numericValue)) {
        return '—';
    }

    const formattedAmount = numericValue.toLocaleString('no-NO', {
        maximumFractionDigits: 0,
    });

    return currencyCode ? `${formattedAmount} ${currencyCode}` : formattedAmount;
}

/**
 * Purpose:
 * Render the customer-facing read-only supplier list.
 *
 * Inputs:
 * Paginated supplier rows and the current search filter from Inertia.
 *
 * Returns:
 * JSX.Element
 *
 * Side effects:
 * Performs read-only GET visits for search and pagination.
 */
export default function SuppliersIndex({ suppliers, filters = {} }) {
    const locale = document.documentElement.lang || 'no';
    const [search, setSearch] = useState(filters.search ?? '');
    const sortField = filters.sort_field ?? 'updated_at';
    const sortDirection = filters.sort_direction ?? 'desc';

    const visitIndex = (params = {}) => {
        router.get('/app/suppliers', {
            search,
            sort_field: sortField,
            sort_direction: sortDirection,
            ...params,
        }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const submitSearch = (event) => {
        event.preventDefault();

        visitIndex();
    };

    const toggleSort = (field) => {
        const nextDirection = sortField === field && sortDirection === 'asc' ? 'desc' : 'asc';

        visitIndex({
            sort_field: field,
            sort_direction: nextDirection,
        });
    };

    const sortIndicator = (field) => {
        if (sortField !== field) {
            return null;
        }

        return sortDirection === 'asc' ? '↑' : '↓';
    };

    const rows = suppliers?.data ?? [];

    return (
        <CustomerAppLayout title="Konkurrenter" showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Konkurrenter</h1>
                    <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                        Read-only customer view of harvested Doffin competitors and their linked notice counts.
                    </p>
                </section>

                <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="flex justify-end">
                        <form onSubmit={submitSearch} className="flex w-full max-w-sm gap-2">
                            <input
                                type="search"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Search supplier name or org. number"
                                className="min-h-11 flex-1 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-900 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-100"
                            />
                            <button
                                type="submit"
                                className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700"
                            >
                                Search
                            </button>
                        </form>
                    </div>
                </section>

                {rows.length === 0 ? (
                    <section className="rounded-[24px] border border-dashed border-slate-300 bg-white px-6 py-14 text-center shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="text-lg font-semibold text-slate-900">No competitors found</div>
                        <p className="mt-2 text-sm text-slate-500">
                            Try a different search or wait for more supplier harvests to complete.
                        </p>
                    </section>
                ) : (
                    <>
                        <section className="grid gap-3 md:hidden">
                            {rows.map((supplier) => (
                                <article
                                    key={supplier.id}
                                    className="rounded-[20px] border border-slate-200 bg-white p-5 shadow-[0_8px_22px_rgba(15,23,42,0.04)]"
                                >
                                    <div className="space-y-3">
                                        <div>
                                            <div className="text-base font-semibold text-slate-950">{supplier.supplier_name}</div>
                                            <div className="mt-1 text-sm text-slate-500">{supplier.organization_number || '—'}</div>
                                        </div>
                                        <div className="grid grid-cols-2 gap-3 text-sm">
                                            <div>
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Notices</div>
                                                <div className="mt-1 font-medium text-slate-950">{supplier.notices_count}</div>
                                            </div>
                                            <div>
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Total verdi</div>
                                                <div className="mt-1 font-medium text-slate-950">
                                                    {formatEstimatedValue(
                                                        supplier.total_estimated_value_amount,
                                                        supplier.total_estimated_value_currency_code,
                                                    )}
                                                </div>
                                            </div>
                                            <div>
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Updated at</div>
                                                <div className="mt-1 font-medium text-slate-950">{formatDateTime(supplier.updated_at, locale)}</div>
                                            </div>
                                        </div>
                                        <Link
                                            href={supplier.view_url}
                                            className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                        >
                                            View
                                        </Link>
                                    </div>
                                </article>
                            ))}
                        </section>

                        <section className="hidden overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)] md:block">
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-slate-200">
                                    <thead className="bg-slate-50">
                                        <tr className="text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                            <th className="px-6 py-4">
                                                <button type="button" onClick={() => toggleSort('supplier_name')} className="inline-flex items-center gap-1 transition hover:text-slate-700">
                                                    <span>Supplier name</span>
                                                    {sortIndicator('supplier_name') ? <span>{sortIndicator('supplier_name')}</span> : null}
                                                </button>
                                            </th>
                                            <th className="px-6 py-4">
                                                <button type="button" onClick={() => toggleSort('organization_number')} className="inline-flex items-center gap-1 transition hover:text-slate-700">
                                                    <span>Organization number</span>
                                                    {sortIndicator('organization_number') ? <span>{sortIndicator('organization_number')}</span> : null}
                                                </button>
                                            </th>
                                            <th className="px-6 py-4">
                                                <button type="button" onClick={() => toggleSort('notices_count')} className="inline-flex items-center gap-1 transition hover:text-slate-700">
                                                    <span>Notices</span>
                                                    {sortIndicator('notices_count') ? <span>{sortIndicator('notices_count')}</span> : null}
                                                </button>
                                            </th>
                                            <th className="px-6 py-4">
                                                <button type="button" onClick={() => toggleSort('total_estimated_value_amount')} className="inline-flex items-center gap-1 transition hover:text-slate-700">
                                                    <span>Total verdi</span>
                                                    {sortIndicator('total_estimated_value_amount') ? <span>{sortIndicator('total_estimated_value_amount')}</span> : null}
                                                </button>
                                            </th>
                                            <th className="px-6 py-4">
                                                <button type="button" onClick={() => toggleSort('updated_at')} className="inline-flex items-center gap-1 transition hover:text-slate-700">
                                                    <span>Updated at</span>
                                                    {sortIndicator('updated_at') ? <span>{sortIndicator('updated_at')}</span> : null}
                                                </button>
                                            </th>
                                            <th className="px-6 py-4">View</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {rows.map((supplier) => (
                                            <tr key={supplier.id} className="text-sm text-slate-700">
                                                <td className="px-6 py-4 font-medium text-slate-950">{supplier.supplier_name}</td>
                                                <td className="px-6 py-4 text-slate-500">{supplier.organization_number || '—'}</td>
                                                <td className="px-6 py-4">{supplier.notices_count}</td>
                                                <td className="px-6 py-4">
                                                    {formatEstimatedValue(
                                                        supplier.total_estimated_value_amount,
                                                        supplier.total_estimated_value_currency_code,
                                                    )}
                                                </td>
                                                <td className="px-6 py-4 text-slate-500">{formatDateTime(supplier.updated_at, locale)}</td>
                                                <td className="px-6 py-4">
                                                    <Link
                                                        href={supplier.view_url}
                                                        className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                    >
                                                        View
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section className="flex flex-col gap-3 rounded-[20px] border border-slate-200 bg-white px-5 py-4 text-sm text-slate-600 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                {suppliers.from && suppliers.to
                                    ? `${suppliers.from}–${suppliers.to} of ${suppliers.total}`
                                    : `${suppliers.total ?? rows.length} competitors`}
                            </div>

                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    disabled={!suppliers.prev_page_url}
                                    onClick={() => suppliers.prev_page_url && router.visit(suppliers.prev_page_url, {
                                        preserveScroll: true,
                                        preserveState: true,
                                    })}
                                    className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:text-slate-300"
                                >
                                    Previous
                                </button>
                                <button
                                    type="button"
                                    disabled={!suppliers.next_page_url}
                                    onClick={() => suppliers.next_page_url && router.visit(suppliers.next_page_url, {
                                        preserveScroll: true,
                                        preserveState: true,
                                    })}
                                    className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:text-slate-300"
                                >
                                    Next
                                </button>
                            </div>
                        </section>
                    </>
                )}
            </div>
        </CustomerAppLayout>
    );
}
