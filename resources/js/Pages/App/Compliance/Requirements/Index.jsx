import { useEffect, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../../Components/App/EmptyStateBox';
import PageHelpButton from '../../../../Components/App/PageHelpButton';
import StatusBadge from '../../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import { APPLICABILITY_TONES, applicabilityLabel } from '../Library/complianceLibrary';
import RequirementOrigin from '../RequirementOrigin';
import { rememberRegister } from '../../../../Support/complianceNavigation';
import ComplianceSources from './ComplianceSources';
import RegisterGroups from './RegisterGroups';
import RequirementForm from './RequirementForm';
import { complianceHelp } from './complianceHelp';
import { REGISTER_URL, VIEW_GROUPED, VIEW_LIST, isFiltered, registerQuery, toggleOpen } from './complianceRegister';
import { REQUIREMENT_STATUS_TONES, countLabel } from './complianceRequirement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const FILTER_LABEL = 'block text-base font-semibold text-slate-700';

/**
 * Relevans for a library requirement — Gjelder, Ikke relevant, Ikke vurdert — or, for an Annex A
 * control, its Kontrollvalg: Inkludert, Utelatt, Ikke valgt. A requirement registered by hand simply
 * applies and shows nothing here.
 */
function Relevance({ item, tr }) {
    if (! item.from_library) {
        return <span className="text-slate-600">—</span>;
    }

    return (
        <StatusBadge tone={APPLICABILITY_TONES[item.applicability] ?? 'slate'}>
            {applicabilityLabel(item.applicability, tr, item.applicability_rule)}
        </StatusBadge>
    );
}

/**
 * Gruppert | Liste. While a search is active the hits are always a list across every source; the
 * chosen view is kept and comes back when the search is cleared.
 */
function ViewSwitch({ view, shownAs, onChange, tr }) {
    const tv = tr.register_view ?? {};
    const searching = view === VIEW_GROUPED && shownAs === VIEW_LIST;
    const options = [
        { value: VIEW_GROUPED, label: tv.grouped ?? 'Gruppert' },
        { value: VIEW_LIST, label: tv.list ?? 'Liste' },
    ];

    return (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
            <div role="group" aria-label={tv.label ?? 'Visning'} className="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1" data-testid="compliance-view-switch">
                {options.map((option) => {
                    const active = shownAs === option.value;

                    return (
                        <button
                            key={option.value}
                            type="button"
                            aria-pressed={active}
                            disabled={searching}
                            onClick={() => onChange(option.value)}
                            className={`min-h-10 rounded-lg px-4 text-base font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-300 disabled:cursor-not-allowed ${active ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-700 hover:text-slate-950'}`}
                        >
                            {option.label}
                        </button>
                    );
                })}
            </div>
            {searching && (
                <p className="text-base text-slate-600" data-testid="compliance-search-as-list">
                    {tv.search_as_list ?? 'Søketreff vises som liste på tvers av kravkildene. Tøm søket for å gå tilbake til gruppert visning.'}
                </p>
            )}
        </div>
    );
}

/**
 * Etterlevelse og revisjon → Kravregister: which requirements apply to the virksomhet. Each row says
 * where the requirement comes from («ISO 14001:2026 · 6.1.2 · Miljøaspekter»), its relevance or
 * Kontrollvalg, and its status. Whether requirements are met is Etterlevelsesvurdering's, so the
 * register shows no results and no worklist.
 *
 * Everything here is already scoped by the server: the rows, the count, the search and the filters
 * are the user's own customer's, and none at all without compliance.view.
 *
 * Gruppert (the default) shows kravkilde → kapittel → krav with counts, and loads requirements only
 * for what is open; Liste shows them all in one table as before. Both read the same filtered register,
 * and the URL holds the view and what is open, so returning to the page restores it.
 */
export default function ComplianceRequirementsIndex() {
    const {
        translations = {},
        requirements = [],
        register = null,
        view = VIEW_GROUPED,
        shown_as: shownAs = VIEW_GROUPED,
        matching_count: matchingCount = 0,
        visible_count: visibleCount = 0,
        filters = {},
        statuses = [],
        applicabilities = [],
        sources = [],
        kinds = [],
        review_intervals: reviewIntervals = [],
        permissions = {},
        owner_options: ownerOptions = [],
    } = usePage().props;

    const { url: pageUrl } = usePage();
    const tr = translations?.compliance ?? {};
    const statusLabels = tr.statuses ?? {};
    const canEdit = permissions.can_edit ?? false;

    const [creating, setCreating] = useState(false);
    const [showSources, setShowSources] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [source, setSource] = useState(filters.source ? String(filters.source) : '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [applicability, setApplicability] = useState(filters.applicability ?? '');
    // Which sources and chapters are open in Gruppert: kept here so a click answers at once, and
    // written to the URL so the server sends the requirements of what is open.
    const [open, setOpen] = useState(() => filters.open ?? []);

    useEffect(() => {
        rememberRegister(pageUrl);
    }, [pageUrl]);

    // Nothing is registered by hand under an activated Kravbibliotek framework.
    const manualSources = sources.filter((option) => ! option.from_library);

    const form = useForm({
        source_id: manualSources.length === 1 ? String(manualSources[0].id) : '',
        reference: '',
        title: '',
        requirement_text: '',
        owner_user_id: '',
        review_interval_months: '',
    });

    const applied = {
        search: filters.search ?? '',
        source: filters.source ?? '',
        status: filters.status ?? '',
        applicability: filters.applicability ?? '',
    };

    const visit = (state, options = {}) => {
        router.get(REGISTER_URL, registerQuery({ ...applied, view, open, ...state }), { preserveState: true, replace: true, ...options });
    };

    const submitSearch = (event) => {
        event.preventDefault();
        visit({ search, source, status, applicability });
    };

    // Nullstill clears the search and the filters; the view and what is open stay.
    const resetSearch = () => {
        setSearch('');
        setSource('');
        setStatus('');
        setApplicability('');
        visit({ search: '', source: '', status: '', applicability: '' });
    };

    const changeView = (next) => {
        visit({ view: next }, { preserveScroll: true });
    };

    const toggle = (key) => {
        const next = toggleOpen(open, key);
        setOpen(next);
        visit({ open: next }, { preserveScroll: true });
    };

    const submitCreate = (event) => {
        event.preventDefault();
        form.post('/app/compliance/requirements', { preserveScroll: true });
    };

    const filtered = isFiltered(filters);
    const groupedSources = register?.sources ?? [];
    const empty = shownAs === VIEW_LIST ? requirements.length === 0 : groupedSources.length === 0;
    const applicabilityLabels = tr.library?.applicability?.register_filter ?? {};
    // The filter lists every source, an activated standard's too — that is how the register is
    // narrowed to one standard («Åpne i kravregisteret» arrives with it selected).
    const sourceOptions = sources.map((option) => ({ id: option.id, label: option.label }));

    return (
        <CustomerAppLayout title={tr.register_title ?? 'Kravregister'} showPageTitle={false} workView={creating ? (tr.create ?? 'Nytt krav') : null}>
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">{tr.module_name ?? 'Etterlevelse og revisjon'}</h1>
                        <p className="max-w-3xl text-base leading-6 text-slate-600">
                            {tr.index_subtitle ?? 'Hvilke krav gjelder for virksomheten? Her ser du hvert krav med standard, punkt, relevans og kontrollvalg. Om kravene er oppfylt, vurderer du under Etterlevelsesvurdering.'}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <PageHelpButton {...complianceHelp(tr, 'index')} />
                        <Link href="/app/compliance/library" className={SECONDARY_ACTION} data-testid="compliance-open-library">
                            {tr.library?.from_library_cta ?? 'Hent krav fra kravbiblioteket'}
                        </Link>
                        <Link href="/app/compliance/assessments" className={SECONDARY_ACTION} data-testid="compliance-open-assessments">
                            {tr.to_assessments ?? 'Til etterlevelsesvurdering'}
                        </Link>
                        {! showSources && (
                            <button type="button" onClick={() => setShowSources(true)} className={SECONDARY_ACTION}>
                                {tr.sources?.manage ?? 'Kravkilder'}
                            </button>
                        )}
                        {canEdit && ! creating && manualSources.length > 0 && (
                            <button type="button" onClick={() => setCreating(true)} className={PRIMARY_ACTION}>
                                {tr.create ?? 'Nytt krav'}
                            </button>
                        )}
                    </div>
                </header>

                {canEdit && manualSources.length === 0 && ! showSources && (
                    <p className="text-base text-slate-700" data-testid="compliance-no-sources-hint">
                        {tr.no_sources_hint ?? 'Opprett en kravkilde under Kravkilder før du registrerer krav.'}
                    </p>
                )}

                {showSources && (
                    <ComplianceSources
                        sources={sources}
                        kinds={kinds}
                        canEdit={canEdit}
                        onClose={() => setShowSources(false)}
                        tr={tr}
                    />
                )}

                {creating && (
                    <section className={CARD} aria-labelledby="compliance-create-heading">
                        <h2 id="compliance-create-heading" className="mb-4 text-xl font-semibold text-slate-950">{tr.create_heading ?? 'Nytt krav'}</h2>
                        <RequirementForm
                            form={form}
                            onSubmit={submitCreate}
                            onCancel={() => { setCreating(false); form.reset(); form.clearErrors(); }}
                            sourceOptions={sourceOptions}
                            ownerOptions={ownerOptions}
                            reviewIntervals={reviewIntervals}
                            tr={tr}
                        />
                    </section>
                )}

                <section className={CARD}>
                    <form onSubmit={submitSearch} className="grid gap-3 sm:flex sm:flex-wrap sm:items-end">
                        <div className="min-w-0 sm:min-w-[16rem] sm:flex-1">
                            <label htmlFor="compliance-search" className={FILTER_LABEL}>{tr.search_label ?? 'Søk'}</label>
                            <input
                                id="compliance-search"
                                type="search"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder={tr.search_placeholder ?? 'Søk i referanse, tittel og kravtekst'}
                                className={`mt-1 ${INPUT}`}
                            />
                        </div>
                        <div className="min-w-0">
                            <label htmlFor="compliance-source-filter" className={FILTER_LABEL}>{tr.source_filter ?? 'Standard / kravkilde'}</label>
                            <select id="compliance-source-filter" value={source} onChange={(event) => setSource(event.target.value)} className={`mt-1 ${INPUT}`}>
                                <option value="">{tr.all_sources ?? 'Alle standarder og kravkilder'}</option>
                                {sourceOptions.map((option) => (
                                    <option key={option.id} value={option.id}>{option.label}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="compliance-status-filter" className={FILTER_LABEL}>{tr.status_filter ?? 'Status'}</label>
                            <select id="compliance-status-filter" value={status} onChange={(event) => setStatus(event.target.value)} className={`mt-1 ${INPUT}`}>
                                <option value="">{tr.all_statuses ?? 'Alle statuser'}</option>
                                {statuses.map((value) => (
                                    <option key={value} value={value}>{statusLabels[value] ?? value}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="compliance-applicability-filter" className={FILTER_LABEL}>{tr.applicability_filter ?? 'Relevans / kontrollvalg'}</label>
                            <select id="compliance-applicability-filter" value={applicability} onChange={(event) => setApplicability(event.target.value)} className={`mt-1 ${INPUT}`}>
                                <option value="">{tr.all_applicabilities ?? 'Alle'}</option>
                                {applicabilities.map((value) => (
                                    <option key={value} value={value}>{applicabilityLabels[value] ?? value}</option>
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

                    <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <p className="text-base text-slate-600">
                            <span data-testid="compliance-count">{countLabel(visibleCount, tr.count_one ?? '1 krav', tr.count ?? ':count krav')}</span>
                            {filtered && (
                                <span data-testid="compliance-matching-count">
                                    {' · '}
                                    {(tr.register_view?.matching ?? ':count passer søket og filtrene').replace(':count', String(matchingCount))}
                                </span>
                            )}
                        </p>
                        <ViewSwitch view={view} shownAs={shownAs} onChange={changeView} tr={tr} />
                    </div>

                    {empty ? (
                        <EmptyStateBox
                            className="mt-4"
                            title={filtered ? (tr.no_matches ?? 'Ingen krav passer søket.') : (tr.empty_title ?? 'Ingen krav registrert ennå')}
                            description={filtered || ! canEdit ? null : (tr.empty_hint ?? '')}
                        />
                    ) : shownAs === VIEW_GROUPED ? (
                        <RegisterGroups sources={groupedSources} isOpen={(key) => open.includes(key)} onToggle={toggle} tr={tr} />
                    ) : (
                        <>
                            {/* Phones: one card per requirement, so nothing has to scroll sideways. */}
                            <ul className="mt-4 divide-y divide-slate-100 md:hidden" data-testid="compliance-requirement-list">
                                {requirements.map((item) => (
                                    <li key={item.id} className="space-y-2 py-4">
                                        <RequirementOrigin origin={item.origin} href={item.url} />
                                        <div className="flex flex-wrap items-center gap-2">
                                            {item.from_library && <Relevance item={item} tr={tr} />}
                                            <StatusBadge tone={REQUIREMENT_STATUS_TONES[item.status] ?? 'slate'}>{statusLabels[item.status] ?? item.status}</StatusBadge>
                                        </div>
                                    </li>
                                ))}
                            </ul>

                            <div className="mt-4 hidden overflow-x-auto md:block">
                                <table className="w-full text-base" data-testid="compliance-requirement-table">
                                    <thead>
                                        <tr className="border-b border-slate-200 text-left text-base font-semibold text-slate-600">
                                            <th className="pb-3 pr-4">{tr.col_requirement ?? 'Krav'}</th>
                                            <th className="px-4 pb-3">{tr.col_relevance ?? 'Relevans / kontrollvalg'}</th>
                                            <th className="pb-3 pl-4">{tr.col_status ?? 'Status'}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {requirements.map((item) => (
                                            <tr key={item.id}>
                                                <td className="py-3 pr-4 align-top">
                                                    <RequirementOrigin origin={item.origin} href={item.url} />
                                                </td>
                                                <td className="px-4 py-3 align-top" data-testid="compliance-relevance-cell"><Relevance item={item} tr={tr} /></td>
                                                <td className="py-3 pl-4 align-top">
                                                    <StatusBadge tone={REQUIREMENT_STATUS_TONES[item.status] ?? 'slate'}>{statusLabels[item.status] ?? item.status}</StatusBadge>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}
                </section>
            </div>
        </CustomerAppLayout>
    );
}
