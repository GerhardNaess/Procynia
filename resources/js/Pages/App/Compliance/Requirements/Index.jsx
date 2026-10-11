import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../../Components/App/EmptyStateBox';
import PageHelpButton from '../../../../Components/App/PageHelpButton';
import StatusBadge from '../../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import { APPLICABILITY_TONES, applicabilityLabel } from '../Library/complianceLibrary';
import RequirementOrigin from '../RequirementOrigin';
import ComplianceSources from './ComplianceSources';
import RequirementForm from './RequirementForm';
import { complianceHelp } from './complianceHelp';
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
 * Etterlevelse og revisjon → Kravregister: which requirements apply to the virksomhet. Each row says
 * where the requirement comes from («ISO 14001:2026 · 6.1.2 · Miljøaspekter»), its relevance or
 * Kontrollvalg, and its status. Whether requirements are met is Etterlevelsesvurdering's, so the
 * register shows no results and no worklist.
 *
 * Everything here is already scoped by the server: the rows, the count, the search and the filters
 * are the user's own customer's, and none at all without compliance.view.
 */
export default function ComplianceRequirementsIndex() {
    const {
        translations = {},
        requirements = [],
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

    const tr = translations?.compliance ?? {};
    const statusLabels = tr.statuses ?? {};
    const canEdit = permissions.can_edit ?? false;

    const [creating, setCreating] = useState(false);
    const [showSources, setShowSources] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [source, setSource] = useState(filters.source ? String(filters.source) : '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [applicability, setApplicability] = useState(filters.applicability ?? '');

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

    const submitSearch = (event) => {
        event.preventDefault();
        router.get('/app/compliance/requirements', {
            search: search || undefined,
            source: source || undefined,
            status: status || undefined,
            applicability: applicability || undefined,
        }, { preserveState: true, replace: true });
    };

    const resetSearch = () => {
        setSearch('');
        setSource('');
        setStatus('');
        setApplicability('');
        router.get('/app/compliance/requirements', {}, { replace: true });
    };

    const submitCreate = (event) => {
        event.preventDefault();
        form.post('/app/compliance/requirements', { preserveScroll: true });
    };

    const filtered = Boolean(filters.search || filters.source || filters.status || filters.applicability);
    const applicabilityLabels = tr.library?.applicability?.register_filter ?? {};
    // The filter lists every source, an activated standard's too — that is how the register is
    // narrowed to one standard («Åpne i kravregisteret» arrives with it selected).
    const sourceOptions = sources.map((option) => ({ id: option.id, label: option.label }));

    return (
        <CustomerAppLayout title={tr.register_title ?? 'Kravregister'} showPageTitle={false}>
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <p className="text-base font-semibold text-violet-700">{tr.module_name ?? 'Etterlevelse og revisjon'}</p>
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">{tr.register_title ?? 'Kravregister'}</h1>
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

                    <p className="mt-4 text-base text-slate-600" data-testid="compliance-count">
                        {countLabel(visibleCount, tr.count_one ?? '1 krav', tr.count ?? ':count krav')}
                    </p>

                    {requirements.length === 0 ? (
                        <EmptyStateBox
                            className="mt-4"
                            title={filtered ? (tr.no_matches ?? 'Ingen krav passer søket.') : (tr.empty_title ?? 'Ingen krav registrert ennå')}
                            description={filtered || ! canEdit ? null : (tr.empty_hint ?? '')}
                        />
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
