import { Link, router, usePage } from '@inertiajs/react';
import { PRIMARY_COLOURS } from '../../../Support/actionStyles';
import { useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import ScrollRegion from '../../../Components/App/ScrollRegion';
import { announce } from '../../../Support/announcer';
import PageHelpButton from '../../../Components/App/PageHelpButton';

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
    const { translations = {} } = usePage().props;
    const ts = translations?.suppliers ?? {};
    const a11y = translations?.a11y ?? {};
    const [search, setSearch] = useState(filters.search ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? new Date().toISOString().slice(0, 10));
    const sortField = filters.sort_field ?? 'updated_at';
    const sortDirection = filters.sort_direction ?? 'desc';

    const visitIndex = (params = {}, { announceResults = false } = {}) => {
        router.get('/app/suppliers', {
            search,
            sort_field: sortField,
            sort_direction: sortDirection,
            date_from: dateFrom,
            date_to: dateTo,
            ...params,
        }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            // A search changes the table out of sight of a screen-reader user who stays in the form;
            // the number of hits is said once (4.1.3, A11Y-111). Sorting and paging do not repeat it.
            onSuccess: announceResults
                ? (page) => {
                    const total = Number(page.props?.suppliers?.total ?? 0);
                    announce(total === 0
                        ? (a11y.results_none ?? 'Ingen treff')
                        : (a11y.results_count ?? ':count treff').replace(':count', String(total)));
                }
                : undefined,
        });
    };

    const submitSearch = (event) => {
        event.preventDefault();

        visitIndex({}, { announceResults: true });
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

    // The arrow showed the sort order only to sighted users (A11Y-043). aria-sort on the header cell
    // says it to a screen reader; the button's name says what pressing it does.
    const ariaSort = (field) => (sortField !== field ? undefined : (sortDirection === 'asc' ? 'ascending' : 'descending'));

    // A render function, not a component defined here: a component created on every render would be
    // remounted on every render, and the sort button would lose focus each time it is pressed.
    const sortHeader = (field, label) => (
        <th key={field} scope="col" className="px-6 py-4" aria-sort={ariaSort(field)}>
            <button
                type="button"
                onClick={() => toggleSort(field)}
                aria-label={(a11y.sort_by ?? 'Sorter etter :column').replace(':column', label)}
                className="inline-flex items-center gap-1 transition hover:text-slate-800"
            >
                <span>{label}</span>
                {sortIndicator(field) ? <span aria-hidden="true">{sortIndicator(field)}</span> : null}
            </button>
        </th>
    );

    const rows = suppliers?.data ?? [];

    return (
        <CustomerAppLayout title="Konkurrenter" showPageTitle={false}>
            <div className="space-y-7">
                <section className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-1.5">
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Konkurrenter</h1>
                        <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                            {ts.page_subtitle ?? 'Oversikt over høstede Doffin-konkurrenter og tilknyttede kunngjøringer.'}
                        </p>
                    </div>
                    <PageHelpButton
                        buttonLabel={ts.page_help_button ?? 'Hjelp'}
                        title={ts.page_help_title ?? 'Konkurrenter'}
                        intro={ts.page_help_intro}
                        sections={[
                            {
                                title: ts.page_help_section_about ?? 'Hva vises her?',
                                items: [
                                    { title: ts.page_help_item_harvested_title ?? 'Høstede konkurrenter', text: ts.page_help_item_harvested_text ?? 'Listen viser leverandører registrert som vinnere eller deltakere i kunngjøringer fra Doffin.' },
                                    { title: ts.page_help_item_notices_title ?? 'Tilknyttede kunngjøringer', text: ts.page_help_item_notices_text ?? 'Klikk på en konkurrent for å se hvilke kunngjøringer de er koblet til.' },
                                    { title: ts.page_help_item_search_title ?? 'Søk og filtrering', text: ts.page_help_item_search_text ?? 'Søk på leverandørnavn eller organisasjonsnummer for å finne spesifikke konkurrenter raskt.' },
                                ],
                            },
                        ]}
                    />
                </section>

                <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <form onSubmit={submitSearch} className="flex flex-wrap items-end gap-3">
                        <div className="flex flex-1 flex-col gap-1 min-w-48">
                            <label htmlFor="supplier-search" className="block text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">
                                Leverandørnavn / org.nr.
                            </label>
                            <input
                                id="supplier-search"
                                type="search"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Søk på navn eller org.nr."
                                className="min-h-11 rounded-xl border border-field-border bg-white px-4 py-2.5 text-sm text-slate-900 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-100"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label htmlFor="supplier-date-from" className="block text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">
                                Fra dato
                            </label>
                            <input
                                id="supplier-date-from"
                                type="date"
                                value={dateFrom}
                                onChange={(event) => setDateFrom(event.target.value)}
                                className="min-h-11 rounded-xl border border-field-border bg-white px-4 py-2.5 text-sm text-slate-900 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-100"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label htmlFor="supplier-date-to" className="block text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">
                                Til dato
                            </label>
                            <input
                                id="supplier-date-to"
                                type="date"
                                value={dateTo}
                                onChange={(event) => setDateTo(event.target.value)}
                                className="min-h-11 rounded-xl border border-field-border bg-white px-4 py-2.5 text-sm text-slate-900 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-100"
                            />
                        </div>
                        <button
                            type="submit"
                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-5 py-2.5 text-sm font-semibold transition ${PRIMARY_COLOURS}`}
                        >
                            Søk
                        </button>
                    </form>
                </section>

                {rows.length === 0 ? (
                    <section className="rounded-[24px] border border-dashed border-slate-300 bg-white px-6 py-14 text-center shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="text-lg font-semibold text-slate-900">{ts.not_found_title ?? 'Ingen konkurrenter funnet'}</div>
                        <p className="mt-2 text-sm text-slate-500">
                            {ts.not_found_hint ?? 'Prøv et annet søk eller vent til neste høsting er fullført.'}
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
                                                <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">{ts.col_notices ?? 'Kunngjøringer'}</div>
                                                <div className="mt-1 font-medium text-slate-950">{supplier.notices_count}</div>
                                            </div>
                                            <div>
                                                <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">{ts.col_total_value ?? 'Total verdi'}</div>
                                                <div className="mt-1 font-medium text-slate-950">
                                                    {formatEstimatedValue(
                                                        supplier.total_estimated_value_amount,
                                                        supplier.total_estimated_value_currency_code,
                                                    )}
                                                </div>
                                            </div>
                                            <div>
                                                <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">{ts.col_updated_at ?? 'Oppdatert'}</div>
                                                <div className="mt-1 font-medium text-slate-950">{formatDateTime(supplier.updated_at, locale)}</div>
                                            </div>
                                        </div>
                                        <Link
                                            href={supplier.view_url}
                                            aria-label={`${ts.view_button ?? 'Vis'} ${supplier.supplier_name}`}
                                            className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                        >
                                            {ts.view_button ?? 'Vis'}
                                        </Link>
                                    </div>
                                </article>
                            ))}
                        </section>

                        <section className="hidden overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)] md:block">
                            <ScrollRegion label={a11y.table_suppliers ?? 'Konkurrenter'} edgeRoom={false}>
                                <table className="min-w-full divide-y divide-slate-200">
                                    <caption className="sr-only">{a11y.table_suppliers ?? 'Konkurrenter'}</caption>
                                    <thead className="bg-slate-50">
                                        <tr className="text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">
                                            {sortHeader('supplier_name', ts.col_supplier_name ?? 'Leverandørnavn')}
                                            {sortHeader('organization_number', ts.col_org_number ?? 'Organisasjonsnummer')}
                                            {sortHeader('notices_count', ts.col_notices ?? 'Kunngjøringer')}
                                            {sortHeader('total_estimated_value_amount', ts.col_total_value ?? 'Total verdi')}
                                            {sortHeader('updated_at', ts.col_updated_at ?? 'Oppdatert')}
                                            <th scope="col" className="px-6 py-4">{ts.col_view ?? 'Vis'}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {rows.map((supplier) => (
                                            <tr key={supplier.id} className="text-sm text-slate-700">
                                                <th scope="row" className="px-6 py-4 text-left font-medium text-slate-950">{supplier.supplier_name}</th>
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
                                                        aria-label={`${ts.view_button ?? 'Vis'} ${supplier.supplier_name}`}
                                                        className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                    >
                                                        {ts.view_button ?? 'Vis'}
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </ScrollRegion>
                        </section>

                        <section className="flex flex-col gap-3 rounded-[20px] border border-slate-200 bg-white px-5 py-4 text-sm text-slate-600 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                {suppliers.from && suppliers.to
                                    ? `${suppliers.from}–${suppliers.to} av ${suppliers.total}`
                                    : `${suppliers.total ?? rows.length} konkurrenter`}
                            </div>

                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    disabled={!suppliers.prev_page_url}
                                    onClick={() => suppliers.prev_page_url && router.visit(suppliers.prev_page_url, {
                                        preserveScroll: true,
                                        preserveState: true,
                                    })}
                                    className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:text-slate-400"
                                >
                                    Forrige
                                </button>
                                <button
                                    type="button"
                                    disabled={!suppliers.next_page_url}
                                    onClick={() => suppliers.next_page_url && router.visit(suppliers.next_page_url, {
                                        preserveScroll: true,
                                        preserveState: true,
                                    })}
                                    className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:text-slate-400"
                                >
                                    Neste
                                </button>
                            </div>
                        </section>
                    </>
                )}
            </div>
        </CustomerAppLayout>
    );
}
