import { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../../Components/App/EmptyStateBox';
import PageHelpButton from '../../../../Components/App/PageHelpButton';
import ScrollRegion from '../../../../Components/App/ScrollRegion';
import StatusBadge from '../../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import { INPUT } from '../../../../Support/formStyles';
import { useTransitionAnnouncement } from '../../../../Support/statusAnnouncement';
import { filterSignature } from '../complianceA11y';
import { rememberWorklist } from '../../../../Support/complianceNavigation';
import { formatLongDate } from '../../Improvements/improvementStatus';
import RequirementOrigin from '../RequirementOrigin';
import { complianceHelp } from '../Requirements/complianceHelp';
import { countLabel } from '../Requirements/complianceRequirement';
import {
    GROUP_TONES,
    SOURCE_COLUMNS,
    STAT_TILES,
    filterOptions,
    groupLabel,
    progressPercent,
    progressText,
    statLabel,
    worklistQuery,
} from './complianceAssessments';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const FILTER_LABEL = 'block text-base font-semibold text-slate-700';

/**
 * The figures for what is chosen under Standard. A figure with a filter of its own is a link to it,
 * so «Ikke vurdert: 12» leads straight to those twelve. Vurdert is the sum of the three results and
 * has no list of its own.
 */
function Figures({ stats, filters, ta }) {
    const percent = progressPercent(stats);
    const href = (filter) => {
        const query = new URLSearchParams(worklistQuery({ source: filters.source ?? '', status: filter }));
        const text = query.toString();

        return `/app/compliance/assessments${text ? `?${text}` : ''}`;
    };

    return (
        <section className={CARD} aria-labelledby="compliance-assessments-figures-heading" data-testid="compliance-assessments-figures">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="compliance-assessments-figures-heading" className="text-xl font-semibold text-slate-950">{ta.figures_heading ?? 'Status'}</h2>
                <p className="text-base text-slate-600" data-testid="compliance-assessments-progress">{progressText(stats, ta.progress)}</p>
            </div>
            <div className="mt-3 h-3 w-full overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent} aria-label={progressText(stats, ta.progress)}>
                <div className="h-full rounded-full bg-violet-500" style={{ width: `${percent}%` }} />
            </div>
            <dl className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                {STAT_TILES.map(({ key, filter }) => {
                    const value = stats[key] ?? 0;
                    const tone = key === 'non_compliant' && value > 0 ? 'text-rose-700' : key === 'partially_compliant' && value > 0 ? 'text-amber-800' : 'text-slate-950';
                    const figure = <dd className={`mt-1 text-3xl font-semibold ${tone}`}>{value}</dd>;

                    // A dt and its dd stay direct children of the tile (1.3.1: a link round them broke
                    // the list); the link sits in the dt and stretches over the tile, which draws the
                    // focus outline for it.
                    return filter === null ? (
                        <div key={key} className="rounded-2xl border border-slate-200 bg-slate-50 p-4" data-testid={`compliance-assessments-stat-${key}`}>
                            <dt className="text-base font-semibold text-slate-600">{statLabel(key, ta)}</dt>
                            {figure}
                        </div>
                    ) : (
                        <div key={key} className="relative rounded-2xl border border-slate-200 bg-slate-50 p-4 hover:border-violet-300 hover:bg-violet-50 has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-offset-2 has-[a:focus-visible]:outline-violet-600 has-[a:focus-visible]:outline-solid" data-testid={`compliance-assessments-stat-${key}`}>
                            <dt className="text-base font-semibold text-slate-600">
                                <Link href={href(filter)} preserveScroll className="after:absolute after:inset-0 after:rounded-2xl focus-visible:outline-none" data-focus-indicator="own" aria-label={`${statLabel(key, ta)}: ${value}. ${ta.show_these ?? 'Vis disse kravene'}`}>
                                    {statLabel(key, ta)}
                                </Link>
                            </dt>
                            {figure}
                        </div>
                    );
                })}
            </dl>
            <ul className="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-base text-slate-700" data-testid="compliance-assessments-outside">
                {stats.overdue > 0 && (
                    <li data-testid="compliance-assessments-stat-overdue">
                        <Link href={href('overdue')} className="font-semibold text-amber-800 hover:text-amber-900">{statLabel('overdue', ta)}: {stats.overdue}</Link>
                    </li>
                )}
                <li data-testid="compliance-assessments-stat-not_applicable">
                    <Link href={href('not_applicable')} className="hover:text-violet-700">{statLabel('not_applicable', ta)}: {stats.not_applicable ?? 0}</Link>
                </li>
                <li data-testid="compliance-assessments-stat-relevance_open">
                    <Link href={href('relevance_open')} className="hover:text-violet-700">{statLabel('relevance_open', ta)}: {stats.relevance_open ?? 0}</Link>
                </li>
            </ul>
            <p className="mt-3 text-base text-slate-600">{ta.outside_note ?? 'Krav som er vurdert ikke relevante, utelatte Annex A-kontroller og krav der relevans eller kontrollvalg ikke er avklart, holdes utenfor tallene over. De er ikke mangler.'}</p>
        </section>
    );
}

/** The same figures per standard or kravkilde. Each requirement is counted under exactly one. */
function PerSource({ rows, filters, ta }) {
    if (rows.length < 2) {
        return null;
    }

    const filterHref = (id) => `/app/compliance/assessments?${new URLSearchParams(worklistQuery({ source: id, status: filters.status ?? '' })).toString()}`;

    return (
        <section className={CARD} aria-labelledby="compliance-assessments-sources-heading" data-testid="compliance-assessments-per-source">
            <h2 id="compliance-assessments-sources-heading" className="text-xl font-semibold text-slate-950">{ta.per_source_heading ?? 'Per standard'}</h2>
            <p className="mt-1 text-base text-slate-600">{ta.per_source_intro ?? 'Hvert krav hører til én standard eller kravkilde og telles bare der.'}</p>

            <ul className="mt-4 divide-y divide-slate-100 lg:hidden">
                {rows.map((row) => (
                    <li key={row.id} className="space-y-2 py-3" data-testid={`compliance-assessments-source-${row.id}`}>
                        <Link href={filterHref(row.id)} className="font-semibold text-violet-700 hover:text-violet-900">{row.label}</Link>
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-base sm:grid-cols-3">
                            {SOURCE_COLUMNS.map((key) => (
                                <div key={key} className="flex justify-between gap-2">
                                    <dt className="text-slate-600">{statLabel(key, ta)}</dt>
                                    <dd className="font-semibold text-slate-900">{row.stats[key] ?? 0}</dd>
                                </div>
                            ))}
                        </dl>
                    </li>
                ))}
            </ul>

            <ScrollRegion className="mt-4 hidden overflow-x-auto lg:block" label={ta.per_source_heading ?? 'Per standard'}>
                <table className="w-full text-base">
                    <caption className="sr-only">{ta.per_source_heading ?? 'Per standard'}</caption>
                    <thead>
                        <tr className="border-b border-slate-200 text-left font-semibold text-slate-600">
                            <th scope="col" className="pb-3 pr-4">{ta.col_standard ?? 'Standard'}</th>
                            {SOURCE_COLUMNS.map((key) => <th key={key} scope="col" className="px-3 pb-3 text-right">{statLabel(key, ta)}</th>)}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((row) => (
                            <tr key={row.id} data-testid={`compliance-assessments-source-row-${row.id}`}>
                                <th scope="row" className="py-3 pr-4 text-left font-normal"><Link href={filterHref(row.id)} className="font-semibold text-violet-700 hover:text-violet-900">{row.label}</Link></th>
                                {SOURCE_COLUMNS.map((key) => <td key={key} className="px-3 py-3 text-right text-slate-800">{row.stats[key] ?? 0}</td>)}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </ScrollRegion>
        </section>
    );
}

/** One requirement in the worklist: where it comes from, where it stands, and what to do next. */
function WorklistRow({ item, ta, locale }) {
    const assessedLine = item.assessed_at
        ? (ta.assessed_line ?? 'Vurdert :date av :name')
            .replace(':date', formatLongDate(item.assessed_at, locale))
            .replace(':name', item.assessed_by_name ?? (ta.unknown_user ?? 'en tidligere bruker'))
        : null;
    const relevanceAction = item.is_control ? (ta.choose_control ?? 'Gjør kontrollvalg') : (ta.clarify_relevance ?? 'Avklar relevans');

    return (
        <li className="flex flex-wrap items-start justify-between gap-3 py-4" data-testid="compliance-assessments-row" data-requirement-id={item.id}>
            <div className="min-w-0 flex-1 space-y-2">
                <RequirementOrigin origin={item.origin} href={item.url} testId="compliance-assessments-origin" />
                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge tone={GROUP_TONES[item.group] ?? 'slate'}>{groupLabel(item.group, item.is_control, ta)}</StatusBadge>
                    {item.is_overdue && <StatusBadge tone="amber">{statLabel('overdue', ta)}</StatusBadge>}
                    {assessedLine && <span className="text-base text-slate-600">{assessedLine}</span>}
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                {item.assess_url && (
                    <Link href={item.assess_url} className={item.group === 'not_assessed' ? PRIMARY_ACTION : SECONDARY_ACTION} aria-label={`${ta.assess ?? 'Vurder'}: ${item.origin?.reference ?? ''} ${item.origin?.title ?? ''}`.trim()}>
                        {item.group === 'not_assessed' ? (ta.assess ?? 'Vurder') : (ta.reassess ?? 'Vurder på nytt')}
                    </Link>
                )}
                {item.relevance_url && (
                    <Link href={item.relevance_url} className={SECONDARY_ACTION} aria-label={`${relevanceAction}: ${item.origin?.reference ?? ''} ${item.origin?.title ?? ''}`.trim()}>
                        {relevanceAction}
                    </Link>
                )}
            </div>
        </li>
    );
}

/**
 * Etterlevelse og revisjon → Etterlevelsesvurdering: the central work area. Which requirements have
 * been assessed, which are met, and where the gaps are — across every standard and kravkilde.
 *
 * Everything is the server's: the rows are the customer's active requirements the person can see,
 * each with the group ComplianceAssessmentOverview gave it from the latest assessment. The figures
 * follow the Standard filter; «Vis» and the search narrow the list only. A requirement opens on its
 * own page, with the assessment form already open from «Vurder». The URL with its filters is
 * remembered so «Tilbake» on the requirement returns to the same view.
 */
export default function ComplianceAssessmentsIndex() {
    const page = usePage();
    const {
        translations = {},
        stats = {},
        per_source: perSource = [],
        requirements = [],
        visible_count: visibleCount = 0,
        filters = {},
        status_filters: statusFilters = [],
        sources = [],
        register_url: registerUrl = '/app/compliance/requirements',
        locale = 'no',
    } = page.props;

    const tr = translations?.compliance ?? {};
    const ta = tr.assessments_page ?? {};
    const [source, setSource] = useState(filters.source ? String(filters.source) : '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [search, setSearch] = useState(filters.search ?? '');

    useEffect(() => {
        rememberWorklist(page.url);
    }, [page.url]);

    useEffect(() => {
        setSource(filters.source ? String(filters.source) : '');
        setStatus(filters.status ?? '');
        setSearch(filters.search ?? '');
    }, [filters.source, filters.status, filters.search]);

    const submit = (event) => {
        event.preventDefault();
        router.get('/app/compliance/assessments', worklistQuery({ source, status, search }), { preserveState: true, replace: true });
    };

    const reset = () => {
        router.get('/app/compliance/assessments', {}, { replace: true });
    };

    const filtered = Boolean(filters.source || filters.status || filters.search);
    const countText = countLabel(visibleCount, ta.count_one ?? '1 krav vises', ta.count ?? ':count krav vises');

    // The count is on screen; it is also spoken once after each Vis (4.1.3, A11Y-111).
    useTransitionAnnouncement(filterSignature(filters, ['source', 'status', 'search']), () => ({ message: countText }));
    const empty = (stats.assessable ?? 0) + (stats.not_applicable ?? 0) + (stats.relevance_open ?? 0) === 0;

    return (
        <CustomerAppLayout title={ta.title ?? 'Etterlevelsesvurdering'} showPageTitle={false}>
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">{tr.module_name ?? 'Etterlevelse og revisjon'}</h1>
                        <p className="max-w-3xl text-base leading-6 text-slate-600">{ta.subtitle ?? 'Hvilke krav har vi vurdert, hvilke oppfyller vi, og hvor har vi mangler?'}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <PageHelpButton {...complianceHelp(tr, 'assessments')} />
                    </div>
                </header>

                {empty && ! filtered ? (
                    <EmptyStateBox
                        title={ta.empty_title ?? 'Ingen krav å vurdere ennå'}
                        description={ta.empty_hint ?? 'Aktiver en standard i Kravbiblioteket, eller registrer egne krav i Kravregisteret. Kravene som gjelder, vises her.'}
                    />
                ) : (
                    <>
                        <Figures stats={stats} filters={filters} ta={ta} />
                        <PerSource rows={perSource} filters={filters} ta={ta} />

                        <section className={CARD} aria-labelledby="compliance-assessments-list-heading">
                            <h2 id="compliance-assessments-list-heading" className="text-xl font-semibold text-slate-950">{ta.list_heading ?? 'Krav'}</h2>
                            <form onSubmit={submit} className="mt-4 grid gap-3 sm:flex sm:flex-wrap sm:items-end" data-testid="compliance-assessments-filters">
                                <div className="min-w-0">
                                    <label htmlFor="compliance-assessments-source" className={FILTER_LABEL}>{ta.filter_source ?? 'Standard'}</label>
                                    <select id="compliance-assessments-source" value={source} onChange={(event) => setSource(event.target.value)} className={`mt-1 ${INPUT}`}>
                                        <option value="">{ta.all_sources ?? 'Alle standarder og kravkilder'}</option>
                                        {sources.map((option) => <option key={option.id} value={option.id}>{option.label}</option>)}
                                    </select>
                                </div>
                                <div className="min-w-0">
                                    <label htmlFor="compliance-assessments-status" className={FILTER_LABEL}>{ta.filter_status ?? 'Vis'}</label>
                                    <select id="compliance-assessments-status" value={status} onChange={(event) => setStatus(event.target.value)} className={`mt-1 ${INPUT}`}>
                                        {filterOptions(statusFilters, ta).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                                    </select>
                                </div>
                                <div className="min-w-0 sm:min-w-[16rem] sm:flex-1">
                                    <label htmlFor="compliance-assessments-search" className={FILTER_LABEL}>{ta.search_label ?? 'Søk'}</label>
                                    <input
                                        id="compliance-assessments-search"
                                        type="search"
                                        value={search}
                                        onChange={(event) => setSearch(event.target.value)}
                                        placeholder={ta.search_placeholder ?? 'Punkt, tittel eller kravtekst'}
                                        className={`mt-1 ${INPUT}`}
                                    />
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <button type="submit" className={SECONDARY_ACTION}>{ta.apply ?? 'Vis'}</button>
                                    {filtered && <button type="button" onClick={reset} className={SECONDARY_ACTION}>{ta.reset ?? 'Nullstill'}</button>}
                                </div>
                            </form>

                            <p className="mt-4 text-base text-slate-600" data-testid="compliance-assessments-count">
                                {countText}
                            </p>

                            {filters.status === 'relevance_open' && (
                                <p className="mt-2 text-base text-slate-700">
                                    {ta.relevance_open_note ?? 'Relevans og kontrollvalg avklares på kravet i Kravregisteret. Først når kravet gjelder eller kontrollen er inkludert, kan det vurderes.'}
                                    {' '}
                                    <Link href={`${registerUrl}?applicability=not_assessed`} className="font-semibold text-violet-700 hover:text-violet-900">{ta.open_register ?? 'Åpne Kravregisteret'} →</Link>
                                </p>
                            )}

                            {requirements.length === 0 ? (
                                <EmptyStateBox className="mt-4" title={ta.no_matches ?? 'Ingen krav passer filteret.'} />
                            ) : (
                                <ul className="mt-2 divide-y divide-slate-100" data-testid="compliance-assessments-list">
                                    {requirements.map((item) => <WorklistRow key={item.id} item={item} ta={ta} locale={locale} />)}
                                </ul>
                            )}
                        </section>
                    </>
                )}
            </div>
        </CustomerAppLayout>
    );
}
