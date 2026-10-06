import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../../Components/App/EmptyStateBox';
import PageHelpButton from '../../../../Components/App/PageHelpButton';
import StatusBadge from '../../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import ComplianceAttention, { AttentionReasons } from './ComplianceAttention';
import ComplianceSources from './ComplianceSources';
import RequirementForm from './RequirementForm';
import { complianceHelp } from './complianceHelp';
import { REQUIREMENT_STATUS_TONES, countLabel, registerCompliance, reviewIntervalLabel } from './complianceRequirement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const FILTER_LABEL = 'block text-base font-semibold text-slate-700';

function Owner({ item, tr }) {
    return item.owner_name ?? <span className="text-amber-800">{tr.no_owner ?? 'Mangler ansvarlig'}</span>;
}

/**
 * The Etterlevelse cell: the current result with «Revurdering forfalt» beside it, or — for a
 * retired requirement — the last result as plain history text, so it never reads as active.
 */
function ComplianceCell({ item, tr }) {
    const cell = registerCompliance(item.compliance, item.status, tr);

    if (cell.kind === 'none') {
        return <span className="text-slate-600">—</span>;
    }

    if (cell.kind === 'historic') {
        return <span className="text-slate-600">{cell.label}</span>;
    }

    return (
        <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <StatusBadge tone={cell.tone}>{cell.label}</StatusBadge>
            {cell.overdue && <span className="font-semibold text-amber-800">{tr.assessment?.overdue ?? 'Revurdering forfalt'}</span>}
        </span>
    );
}

/**
 * Etterlevelse og revisjon → Krav: the register. Everything here is already scoped by the server:
 * the rows, the count, the search and the source filter are the user's own customer's, and none at
 * all without compliance.view. Whether a requirement is met comes from its latest assessment, as
 * the server's ComplianceStatusResolver computed it; nothing here derives it on its own — nor which
 * requirements need attention, which ComplianceAttentionService decided on the same rows.
 */
export default function ComplianceRequirementsIndex() {
    const {
        translations = {},
        requirements = [],
        visible_count: visibleCount = 0,
        filters = {},
        statuses = [],
        sources = [],
        kinds = [],
        review_intervals: reviewIntervals = [],
        permissions = {},
        owner_options: ownerOptions = [],
        attention = null,
    } = usePage().props;

    const tr = translations?.compliance ?? {};
    const statusLabels = tr.statuses ?? {};
    const canEdit = permissions.can_edit ?? false;

    const [creating, setCreating] = useState(false);
    const [showSources, setShowSources] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [source, setSource] = useState(filters.source ? String(filters.source) : '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [attentionOnly, setAttentionOnly] = useState(Boolean(filters.attention));

    const form = useForm({
        source_id: sources.length === 1 ? String(sources[0].id) : '',
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
            attention: attentionOnly ? 1 : undefined,
        }, { preserveState: true, replace: true });
    };

    const resetSearch = () => {
        setSearch('');
        setSource('');
        setStatus('');
        setAttentionOnly(false);
        router.get('/app/compliance/requirements', {}, { replace: true });
    };

    const submitCreate = (event) => {
        event.preventDefault();
        form.post('/app/compliance/requirements', { preserveScroll: true });
    };

    const filtered = Boolean(filters.search || filters.source || filters.status || filters.attention);
    const sourceOptions = sources.map((option) => ({ id: option.id, label: option.label }));

    return (
        <CustomerAppLayout title={tr.index_title ?? 'Krav'} showPageTitle={false}>
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <p className="text-base font-semibold text-violet-700">{tr.module_name ?? 'Etterlevelse og revisjon'}</p>
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">{tr.index_heading ?? 'Krav'}</h1>
                        <p className="max-w-3xl text-base leading-6 text-slate-600">
                            {tr.index_subtitle ?? 'Kravene virksomheten skal etterleve – med kilde, ansvarlig og siste etterlevelsesvurdering samlet på ett sted.'}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <PageHelpButton {...complianceHelp(tr, 'index')} />
                        {! showSources && (
                            <button type="button" onClick={() => setShowSources(true)} className={SECONDARY_ACTION}>
                                {tr.sources?.manage ?? 'Kravkilder'}
                            </button>
                        )}
                        {canEdit && ! creating && sources.length > 0 && (
                            <button type="button" onClick={() => setCreating(true)} className={PRIMARY_ACTION}>
                                {tr.create ?? 'Nytt krav'}
                            </button>
                        )}
                    </div>
                </header>

                {canEdit && sources.length === 0 && ! showSources && (
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

                <ComplianceAttention attention={attention} tr={tr} />

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
                            <label htmlFor="compliance-source-filter" className={FILTER_LABEL}>{tr.source_filter ?? 'Kravkilde'}</label>
                            <select id="compliance-source-filter" value={source} onChange={(event) => setSource(event.target.value)} className={`mt-1 ${INPUT}`}>
                                <option value="">{tr.all_sources ?? 'Alle kravkilder'}</option>
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
                        <label className="flex min-h-10 items-center gap-2 text-base font-semibold text-slate-700">
                            <input
                                type="checkbox"
                                checked={attentionOnly}
                                onChange={(event) => setAttentionOnly(event.target.checked)}
                                className="h-5 w-5 rounded border-slate-300"
                                data-testid="compliance-attention-filter"
                            />
                            {tr.attention?.filter ?? 'Bare krav som trenger oppmerksomhet'}
                        </label>
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
                                        <div className="flex flex-wrap items-center gap-2">
                                            <StatusBadge tone={REQUIREMENT_STATUS_TONES[item.status] ?? 'slate'}>{statusLabels[item.status] ?? item.status}</StatusBadge>
                                            {item.reference && <span className="break-words text-base font-semibold text-slate-700">{item.reference}</span>}
                                        </div>
                                        <Link href={item.url} className="block break-words text-base font-semibold text-violet-700 hover:text-violet-900">{item.title}</Link>
                                        <AttentionReasons reasons={item.attention ?? []} tr={tr} withLabel />
                                        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-base">
                                            <dt className="font-semibold text-slate-600">{tr.col_source ?? 'Kravkilde'}</dt>
                                            <dd className="min-w-0 break-words text-slate-800">{item.source_label}</dd>
                                            <dt className="font-semibold text-slate-600">{tr.col_owner ?? 'Ansvarlig'}</dt>
                                            <dd className="min-w-0 break-words text-slate-800"><Owner item={item} tr={tr} /></dd>
                                            <dt className="font-semibold text-slate-600">{tr.col_review ?? 'Revurdering'}</dt>
                                            <dd className="text-slate-800">{reviewIntervalLabel(item.review_interval_months, tr)}</dd>
                                            <dt className="font-semibold text-slate-600">{tr.col_compliance ?? 'Etterlevelse'}</dt>
                                            <dd className="min-w-0 text-slate-800" data-testid="compliance-cell"><ComplianceCell item={item} tr={tr} /></dd>
                                        </dl>
                                    </li>
                                ))}
                            </ul>

                            <div className="mt-4 hidden overflow-x-auto md:block">
                                <table className="w-full text-base" data-testid="compliance-requirement-table">
                                    <thead>
                                        <tr className="border-b border-slate-200 text-left text-base font-semibold text-slate-600">
                                            <th className="pb-3 pr-4">{tr.col_reference ?? 'Referanse'}</th>
                                            <th className="px-4 pb-3">{tr.col_requirement ?? 'Krav'}</th>
                                            <th className="px-4 pb-3">{tr.col_source ?? 'Kravkilde'}</th>
                                            <th className="px-4 pb-3">{tr.col_owner ?? 'Ansvarlig'}</th>
                                            <th className="px-4 pb-3">{tr.col_review ?? 'Revurdering'}</th>
                                            <th className="px-4 pb-3">{tr.col_compliance ?? 'Etterlevelse'}</th>
                                            <th className="pb-3 pl-4">{tr.col_status ?? 'Status'}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {requirements.map((item) => (
                                            <tr key={item.id}>
                                                <td className="py-3 pr-4 align-top text-slate-700">
                                                    {item.reference ?? <span className="text-slate-600">{tr.no_reference ?? 'Ingen referanse'}</span>}
                                                </td>
                                                <td className="px-4 py-3 align-top">
                                                    <Link href={item.url} className="font-semibold text-violet-700 hover:text-violet-900">{item.title}</Link>
                                                    {(item.attention ?? []).length > 0 && (
                                                        <div className="mt-1">
                                                            <AttentionReasons reasons={item.attention} tr={tr} withLabel />
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 align-top text-slate-700">{item.source_label}</td>
                                                <td className="px-4 py-3 align-top text-slate-700"><Owner item={item} tr={tr} /></td>
                                                <td className="px-4 py-3 align-top text-slate-700">{reviewIntervalLabel(item.review_interval_months, tr)}</td>
                                                <td className="px-4 py-3 align-top text-slate-700" data-testid="compliance-cell"><ComplianceCell item={item} tr={tr} /></td>
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
