import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import CpvSelector from './CpvSelector';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

const worklistSummary = [
    { label: 'Lagret fra søk', count: 3 },
    { label: 'Markert som aktuelt', count: 5 },
    { label: 'Med notater', count: 2 },
];

const publicationOptions = [
    { value: '', label: 'Alltid' },
    { value: '7', label: 'Siste 7 dager' },
    { value: '30', label: 'Siste 30 dager' },
    { value: '90', label: 'Siste 90 dager' },
    { value: '365', label: 'Siste 365 dager' },
];

const statusOptions = [
    { value: '', label: 'Alle statuser' },
    { value: 'ACTIVE', label: 'Aktiv' },
    { value: 'EXPIRED', label: 'Utgått' },
    { value: 'AWARDED', label: 'Tildelt' },
    { value: 'CANCELLED', label: 'Avlyst' },
];

const relevanceOptions = [
    { value: '', label: 'Alle nivåer' },
    { value: 'high', label: 'Høy relevans' },
    { value: 'medium', label: 'Middels relevans' },
    { value: 'low', label: 'Lav relevans' },
];

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function buildNoticeQuery(values) {
    return Object.fromEntries(
        Object.entries(values).filter(([, value]) => {
            if (value === null || value === undefined) {
                return false;
            }

            if (typeof value === 'string' && value.trim() === '') {
                return false;
            }

            return true;
        }),
    );
}

function SearchIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M9 15a6 6 0 1 1 0-12 6 6 0 0 1 0 12Z" />
            <path d="m13.5 13.5 4 4" strokeLinecap="round" />
        </svg>
    );
}

function FilterIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M3 5h14" strokeLinecap="round" />
            <path d="M6 10h8" strokeLinecap="round" />
            <path d="M8.5 15h3" strokeLinecap="round" />
        </svg>
    );
}

function BookmarkIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M6 3.5h8a1 1 0 0 1 1 1V17l-5-3-5 3V4.5a1 1 0 0 1 1-1Z" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function BellIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M10 17a2 2 0 0 0 1.9-1.4" strokeLinecap="round" />
            <path
                d="M5.5 8.2a4.5 4.5 0 1 1 9 0v2.2c0 .9.3 1.7.9 2.4l.3.4a.8.8 0 0 1-.6 1.3H4a.8.8 0 0 1-.6-1.3l.3-.4c.6-.7.9-1.5.9-2.4V8.2Z"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function ListIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M7 5h9" strokeLinecap="round" />
            <path d="M7 10h9" strokeLinecap="round" />
            <path d="M7 15h9" strokeLinecap="round" />
            <path d="M4 5h.01" strokeLinecap="round" />
            <path d="M4 10h.01" strokeLinecap="round" />
            <path d="M4 15h.01" strokeLinecap="round" />
        </svg>
    );
}

function BuildingIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M3.5 16.5h13" strokeLinecap="round" />
            <path d="M5 16V5.5a1 1 0 0 1 1-1h4v11.5" strokeLinejoin="round" />
            <path d="M10 16V8.5a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1V16" strokeLinejoin="round" />
        </svg>
    );
}

function CalendarIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M6 3.5v2" strokeLinecap="round" />
            <path d="M14 3.5v2" strokeLinecap="round" />
            <path d="M4 7h12" strokeLinecap="round" />
            <rect x="4" y="5" width="12" height="11" rx="2" />
        </svg>
    );
}

function ClockIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <circle cx="10" cy="10" r="6.5" />
            <path d="M10 7v3.5l2.5 1.5" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function formatDate(value, locale, options = {}) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        ...options,
    }).format(new Date(value));
}

function pluralize(total) {
    return `${total} ${total === 1 ? 'anskaffelse' : 'anskaffelser'}`;
}

function summarizeText(value) {
    const trimmed = (value ?? '').trim();

    if (trimmed === '') {
        return 'Kort beskrivelse kommer i neste steg.';
    }

    return trimmed;
}

function statusBadge(status, deadline) {
    if (status) {
        return {
            label: status,
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    if (!deadline) {
        return {
            label: 'Kunngjøring',
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    if (new Date(deadline) <= new Date()) {
        return {
            label: 'Utgått',
            className: 'bg-rose-100 text-rose-700 ring-rose-200',
        };
    }

    return {
        label: 'Aktiv',
        className: 'bg-violet-100 text-violet-700 ring-violet-200',
    };
}

export default function NoticeIndex({ notices, filters, savedSearches = [], source, supportMode, cpvSelector }) {
    const { locale, translations } = usePage().props;
    const [searchQuery, setSearchQuery] = useState(filters.q ?? '');
    const [organizationName, setOrganizationName] = useState(filters.organization_name ?? '');
    const [selectedCpvItems, setSelectedCpvItems] = useState(cpvSelector?.selected ?? []);
    const [keywords, setKeywords] = useState(filters.keywords ?? '');
    const [publicationDate, setPublicationDate] = useState(filters.publication_period ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [relevance, setRelevance] = useState(filters.relevance ?? '');
    const hasAppliedSearch = (filters.q ?? '').trim() !== '';
    const hasAppliedRefinements = [filters.organization_name, filters.cpv, filters.keywords, filters.publication_period, filters.status].some(
        (value) => (value ?? '').trim() !== '',
    );
    const emptyStateTitle = hasAppliedSearch || hasAppliedRefinements
        ? 'Ingen Doffin-treff matcher søket ditt.'
        : 'Ingen Doffin-treff er tilgjengelige akkurat nå.';
    const emptyStateBody = hasAppliedRefinements
        ? 'Prøv et bredere søk eller fjern noen av filtrene.'
        : 'Søk i tittel, oppdragsgiver eller organisasjonsnummer for å finne kunngjøringer direkte i Doffin.';

    useEffect(() => {
        setSearchQuery(filters.q ?? '');
        setOrganizationName(filters.organization_name ?? '');
        setSelectedCpvItems(cpvSelector?.selected ?? []);
        setKeywords(filters.keywords ?? '');
        setPublicationDate(filters.publication_period ?? '');
        setStatus(filters.status ?? '');
        setRelevance(filters.relevance ?? '');
    }, [
        cpvSelector?.selected,
        filters.keywords,
        filters.organization_name,
        filters.publication_period,
        filters.q,
        filters.relevance,
        filters.status,
    ]);

    const saveNotice = (notice) => {
        router.post(
            '/app/notices/save',
            {
                notice_id: notice.notice_id,
                title: notice.title,
                buyer_name: notice.buyer_name,
                external_url: notice.external_url,
                summary: notice.summary,
                publication_date: notice.publication_date,
                deadline: notice.deadline,
                status: notice.status,
                cpv_code: notice.cpv_code,
            },
            {
                preserveScroll: true,
            },
        );
    };
    const applyFilters = () => {
        router.get(
            '/app/notices',
            buildNoticeQuery({
                q: searchQuery,
                organization_name: organizationName,
                cpv: selectedCpvItems.map((item) => item.code).join(','),
                keywords,
                publication_period: publicationDate,
                status,
            }),
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    const clearFilters = () => {
        setSearchQuery('');
        setOrganizationName('');
        setSelectedCpvItems([]);
        setKeywords('');
        setPublicationDate('');
        setStatus('');
        setRelevance('');

        router.get(
            '/app/notices',
            {},
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    return (
        <CustomerAppLayout title="Anskaffelser" showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">{translations.frontend.procurements_nav}</h1>
                    <p className="text-[15px] text-slate-500">{translations.frontend.procurements_subtitle}</p>
                </section>

                <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_308px]">
                    <div className="space-y-5">
                        <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:p-5">
                            <div className="mb-3">
                                <div className="text-sm font-medium text-slate-900">Live søk i Doffin</div>
                                <p className="mt-1 text-sm text-slate-500">
                                    Søk direkte i Doffin etter tittel, oppdragsgiver, organisasjonsnummer og beskrivelse.
                                </p>
                            </div>
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    applyFilters();
                                }}
                                className="flex flex-col gap-2.5 lg:flex-row"
                            >
                                <label className="relative flex-1 overflow-hidden rounded-2xl border border-slate-200 bg-white">
                                    <SearchIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
                                    <input
                                        type="search"
                                        value={searchQuery}
                                        onChange={(event) => setSearchQuery(event.target.value)}
                                        placeholder="Søk i tittel, oppdragsgiver, org.nr. eller beskrivelse"
                                        className="h-[54px] w-full border-0 bg-transparent pl-12 pr-4 text-[15px] text-slate-900 outline-none placeholder:text-slate-400 focus:ring-0"
                                    />
                                </label>
                                <button
                                    type="submit"
                                    className="inline-flex h-[54px] items-center justify-center gap-2 rounded-2xl bg-violet-600 px-6 text-sm font-semibold text-white transition hover:bg-violet-700"
                                >
                                    <SearchIcon className="h-4 w-4" />
                                    {translations.frontend.search_button}
                                </button>
                            </form>
                        </section>

                        <section className="rounded-[22px] border border-slate-200 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <div className="mb-4">
                                <div className="flex items-center gap-2.5">
                                    <FilterIcon className="h-5 w-5 text-slate-500" />
                                    <h2 className="text-xl font-semibold text-slate-950">Filtrer Doffin-treff</h2>
                                </div>
                                <p className="mt-1 text-sm text-slate-500">
                                    Bruk filtrene under for å snevre inn live-resultatene fra Doffin.
                                </p>
                            </div>

                            <div className="grid gap-3.5 md:grid-cols-2 xl:grid-cols-3">
                                <label className="space-y-2">
                                    <span className="text-sm font-medium text-slate-700">{translations.frontend.organization_name}</span>
                                    <input
                                        type="text"
                                        value={organizationName}
                                        onChange={(event) => setOrganizationName(event.target.value)}
                                        placeholder="Skriv organisasjonsnavn eller org.nr."
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    />
                                </label>
                                <CpvSelector
                                    endpoint={cpvSelector?.endpoint ?? '/app/notices/cpv-suggestions'}
                                    selectedItems={selectedCpvItems}
                                    onSelectedItemsChange={setSelectedCpvItems}
                                    popularItems={cpvSelector?.popular ?? []}
                                />
                                <label className="space-y-2">
                                    <span className="text-sm font-medium text-slate-700">{translations.frontend.keyword}</span>
                                    <input
                                        type="text"
                                        value={keywords}
                                        onChange={(event) => setKeywords(event.target.value)}
                                        placeholder="For eksempel havn, ferge, drift"
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    />
                                    <p className="text-xs text-slate-400">Kommaseparer ord for å snevre inn hovedsøket.</p>
                                </label>
                                <label className="space-y-2">
                                    <span className="text-sm font-medium text-slate-700">{translations.frontend.publish_date}</span>
                                    <select
                                        value={publicationDate}
                                        onChange={(event) => setPublicationDate(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    >
                                        {publicationOptions.map((option) => (
                                            <option key={option.value || 'empty'} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="space-y-2">
                                    <span className="text-sm font-medium text-slate-700">{translations.common.status}</span>
                                    <select
                                        value={status}
                                        onChange={(event) => setStatus(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    >
                                        {statusOptions.map((option) => (
                                            <option key={option.value || 'empty'} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="space-y-2">
                                    <span className="text-sm font-medium text-slate-700">{translations.frontend.relevance}</span>
                                    <select
                                        value={relevance}
                                        onChange={(event) => setRelevance(event.target.value)}
                                        disabled
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-400 outline-none transition disabled:cursor-not-allowed"
                                    >
                                        {relevanceOptions.map((option) => (
                                            <option key={option.value || 'empty'} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                    <p className="text-xs text-slate-400">Relevans finnes ikke som live Doffin-filter i denne flyten.</p>
                                </label>
                            </div>

                            <div className="mt-5 flex flex-wrap gap-2.5">
                                <button
                                    type="button"
                                    onClick={applyFilters}
                                    className="inline-flex items-center justify-center rounded-xl bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700"
                                >
                                    {translations.frontend.apply_filters}
                                </button>
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                >
                                    {translations.frontend.clear_filters}
                                </button>
                            </div>
                        </section>

                        {supportMode?.active && supportMode?.message ? (
                            <section className="rounded-[20px] border border-amber-200 bg-amber-50 px-5 py-4 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                                <div className="text-sm font-medium text-amber-900">{translations.frontend.support_mode_label}</div>
                                <p className="mt-1 text-sm leading-6 text-amber-800">{supportMode.message}</p>
                            </section>
                        ) : null}

                        <section className="space-y-3.5">
                            <div className="space-y-1">
                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                    {source?.label}
                                </div>
                                <div className="text-[17px] font-semibold text-slate-950">{pluralize(notices.meta.total ?? 0)}</div>
                            </div>

                            {notices.data.length === 0 ? (
                                <div className="rounded-[22px] border border-dashed border-slate-300 bg-white px-6 py-14 text-center shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                                    <div className="text-lg font-semibold text-slate-900">{emptyStateTitle}</div>
                                    <p className="mt-2 text-sm text-slate-500">{emptyStateBody}</p>
                                </div>
                            ) : (
                                <div className="space-y-3.5">
                                    {notices.data.map((notice) => {
                                        const statusTag = statusBadge(notice.status, notice.deadline);

                                        return (
                                            <article
                                                key={notice.id}
                                                className="rounded-[20px] border border-slate-200 bg-white p-5 shadow-[0_8px_22px_rgba(15,23,42,0.04)] transition hover:border-slate-300"
                                            >
                                                <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex flex-wrap items-center gap-2.5">
                                                            <h2 className="text-[1.7rem] font-semibold tracking-tight text-slate-950">
                                                                {notice.title}
                                                            </h2>
                                                        </div>

                                                        <div className="mt-1.5 flex flex-wrap items-center gap-4 text-sm text-slate-600">
                                                            <span className="inline-flex items-center gap-2">
                                                                <BuildingIcon className="h-4 w-4 text-slate-400" />
                                                                {notice.buyer_name || 'Oppdragsgiver ikke angitt'}
                                                            </span>
                                                        </div>

                                                        <p className="mt-3 max-w-4xl text-sm leading-7 text-slate-600">
                                                            {summarizeText(notice.summary)}
                                                        </p>

                                                        <div className="mt-4 flex flex-wrap gap-2">
                                                            <span className="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-200">
                                                                <CalendarIcon className="h-3.5 w-3.5" />
                                                                Publisert {formatDate(notice.publication_date, locale)}
                                                            </span>
                                                            <span className="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-200">
                                                                <ClockIcon className="h-3.5 w-3.5" />
                                                                Frist {formatDate(notice.deadline, locale)}
                                                            </span>
                                                            <span className="inline-flex items-center gap-2 rounded-full bg-blue-100 px-3 py-1.5 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-200">
                                                                {notice.cpv_code ? `CPV: ${notice.cpv_code}` : 'Kategori: Doffin-kunngjøring'}
                                                            </span>
                                                            <span
                                                                className={classNames(
                                                                    'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset',
                                                                    statusTag.className,
                                                                )}
                                                            >
                                                                {statusTag.label}
                                                            </span>
                                                        </div>
                                                    </div>

                                                    <div className="flex shrink-0 flex-row gap-3 lg:flex-col">
                                                        <button
                                                            type="button"
                                                            onClick={() => saveNotice(notice)}
                                                            className="inline-flex min-w-[108px] items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                        >
                                                            {translations.frontend.save_button}
                                                        </button>
                                                        {notice.external_url ? (
                                                            <a
                                                                href={notice.external_url}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="inline-flex min-w-[108px] items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                                            >
                                                                Åpne i Doffin
                                                            </a>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            </article>
                                        );
                                    })}
                                </div>
                            )}
                        </section>

                        <div className="flex flex-col gap-4 rounded-[20px] border border-slate-200 bg-white px-5 py-4 text-sm text-slate-600 shadow-[0_8px_22px_rgba(15,23,42,0.04)] sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                {notices.meta.from && notices.meta.to
                                    ? `${notices.meta.from}–${notices.meta.to} av ${notices.meta.total}`
                                    : pluralize(notices.meta.total ?? 0)}
                            </div>
                            <div className="flex gap-3">
                                <button
                                    type="button"
                                    disabled={!notices.meta.prev_page_url}
                                    onClick={() => notices.meta.prev_page_url && router.visit(notices.meta.prev_page_url)}
                                    className="rounded-xl border border-slate-200 bg-white px-4 py-2 font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    {translations.common.previous}
                                </button>
                                <button
                                    type="button"
                                    disabled={!notices.meta.next_page_url}
                                    onClick={() => notices.meta.next_page_url && router.visit(notices.meta.next_page_url)}
                                    className="rounded-xl border border-slate-200 bg-white px-4 py-2 font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    {translations.common.next}
                                </button>
                            </div>
                        </div>
                    </div>

                    <aside className="space-y-4 xl:sticky xl:top-5 xl:self-start">
                        <section className="rounded-[20px] border border-slate-200 bg-white p-5 shadow-[0_8px_22px_rgba(15,23,42,0.04)]">
                            <div className="mb-5 flex items-center justify-between gap-3">
                                <div className="flex items-center gap-3">
                                    <BookmarkIcon className="h-5 w-5 text-slate-500" />
                                    <h2 className="text-xl font-semibold text-slate-950">{translations.frontend.saved_searches_title}</h2>
                                </div>
                                <button
                                    type="button"
                                    className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                >
                                    {translations.frontend.see_all}
                                </button>
                            </div>
                            <div className="space-y-4">
                                {savedSearches.length === 0 ? (
                                    <div className="rounded-2xl bg-slate-50 px-4 py-4 text-sm text-slate-500">
                                        Ingen lagrede søk er tilgjengelige ennå.
                                    </div>
                                ) : (
                                    savedSearches.map((item) => (
                                        <div key={item.id} className="space-y-2 border-b border-slate-100 pb-4 last:border-b-0 last:pb-0">
                                            <div className="text-base font-semibold text-slate-900">{item.name}</div>
                                            <div className="text-sm leading-6 text-slate-500">{item.summary}</div>
                                            <div className="flex flex-wrap gap-2">
                                                {item.department ? (
                                                    <span className="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                                                        {item.department}
                                                    </span>
                                                ) : null}
                                                {item.frequency ? (
                                                    <span className="inline-flex rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold text-violet-700">
                                                        {item.frequency}
                                                    </span>
                                                ) : null}
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>
                        </section>

                        <section className="rounded-[20px] border border-slate-200 bg-white p-5 shadow-[0_8px_22px_rgba(15,23,42,0.04)]">
                            <div className="mb-5 flex items-center gap-3">
                                <BellIcon className="h-5 w-5 text-slate-500" />
                                <h2 className="text-xl font-semibold text-slate-950">{translations.frontend.alerts_monitoring_title}</h2>
                            </div>

                            <div className="rounded-2xl border border-violet-200 bg-violet-50 px-4 py-4 text-sm leading-6 text-violet-900">
                                {translations.frontend.alert_summary}
                            </div>

                            <div className="mt-5 space-y-3 text-sm text-slate-600">
                                <div className="flex items-center gap-3">
                                    <span className="h-2.5 w-2.5 rounded-full bg-emerald-500" />
                                    {translations.frontend.new_hits_last_day}
                                </div>
                                <div className="flex items-center gap-3">
                                    <span className="h-2.5 w-2.5 rounded-full bg-slate-300" />
                                    {translations.frontend.next_update}
                                </div>
                            </div>

                            <button
                                type="button"
                                className="mt-5 inline-flex w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                            >
                                {translations.frontend.alert_settings}
                            </button>
                        </section>

                        <section className="rounded-[20px] border border-slate-200 bg-white p-5 shadow-[0_8px_22px_rgba(15,23,42,0.04)]">
                            <div className="mb-5 flex items-center justify-between gap-3">
                                <div className="flex items-center gap-3">
                                    <ListIcon className="h-5 w-5 text-slate-500" />
                                    <h2 className="text-xl font-semibold text-slate-950">{translations.frontend.worklist_title}</h2>
                                </div>
                                <button
                                    type="button"
                                    className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                >
                                    {translations.frontend.see_all}
                                </button>
                            </div>

                            <div className="space-y-3">
                                {worklistSummary.map((item) => (
                                    <div key={item.label} className="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3">
                                        <span className="text-sm text-slate-700">{item.label}</span>
                                        <span className="inline-flex h-7 min-w-[28px] items-center justify-center rounded-full bg-slate-200 px-2 text-xs font-semibold text-slate-700">
                                            {item.count}
                                        </span>
                                    </div>
                                ))}
                            </div>

                            <button
                                type="button"
                                className="mt-5 inline-flex w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                            >
                                {translations.frontend.go_to_worklist}
                            </button>
                        </section>
                    </aside>
                </div>
            </div>
        </CustomerAppLayout>
    );
}
