import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../../Components/App/EmptyStateBox';
import PageHelpButton from '../../../../Components/App/PageHelpButton';
import StatusBadge from '../../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import ComplianceAttention, { AttentionReasons } from '../Requirements/ComplianceAttention';
import { complianceHelp } from '../Requirements/complianceHelp';
import { countLabel } from '../Requirements/complianceRequirement';
import AuditForm from './AuditForm';
import { AUDIT_STATUS_TONES, auditStatusLabel, auditTypeLabel, plannedPeriodLabel } from './complianceAudit';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const FILTER_LABEL = 'block text-base font-semibold text-slate-700';

function Responsible({ item, ta }) {
    return item.responsible_name ?? <span className="text-amber-800">{ta.no_responsible ?? 'Mangler ansvarlig'}</span>;
}

/**
 * Etterlevelse og revisjon → Revisjoner: the register. Everything here is already scoped by the
 * server: the rows, the count, the search and the filters are the user's own customer's, and none at
 * all without compliance.view. Ny revisjon is offered only with compliance.audit; the requirements
 * and processes in scope are added on the audit's own page once it exists. «Trenger oppmerksomhet»
 * lists the audits ComplianceAuditAttentionService flagged among those the user can see.
 */
export default function ComplianceAuditsIndex() {
    const {
        translations = {},
        audits = [],
        attention = null,
        visible_count: visibleCount = 0,
        filters = {},
        statuses = [],
        types = [],
        permissions = {},
        responsible_options: responsibleOptions = [],
    } = usePage().props;

    const tr = translations?.compliance ?? {};
    const ta = tr.audits ?? {};
    const canAudit = permissions.can_audit ?? false;

    const [creating, setCreating] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [type, setType] = useState(filters.type ?? '');
    const [attentionOnly, setAttentionOnly] = useState(Boolean(filters.attention));

    const form = useForm({
        title: '',
        audit_type: 'internal',
        responsible_user_id: '',
        auditor_name: '',
        planned_start_date: '',
        planned_end_date: '',
        scope_description: '',
    });

    const submitSearch = (event) => {
        event.preventDefault();
        router.get('/app/compliance/audits', {
            search: search || undefined,
            status: status || undefined,
            type: type || undefined,
            attention: attentionOnly ? 1 : undefined,
        }, { preserveState: true, replace: true });
    };

    const resetSearch = () => {
        setSearch('');
        setStatus('');
        setType('');
        setAttentionOnly(false);
        router.get('/app/compliance/audits', {}, { replace: true });
    };

    const submitCreate = (event) => {
        event.preventDefault();
        form.post('/app/compliance/audits', { preserveScroll: true });
    };

    const filtered = Boolean(filters.search || filters.status || filters.type || filters.attention);

    return (
        <CustomerAppLayout title={ta.index_title ?? 'Revisjoner'} showPageTitle={false}>
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <p className="text-base font-semibold text-violet-700">{tr.module_name ?? 'Etterlevelse og revisjon'}</p>
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">{ta.index_heading ?? 'Revisjoner'}</h1>
                        <p className="max-w-3xl text-base leading-6 text-slate-600">
                            {ta.index_subtitle ?? 'Planlegg og gjennomfør revisjoner av om virksomheten etterlever kravene – med ansvarlig, scope og konklusjon på ett sted.'}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <PageHelpButton {...complianceHelp(tr, 'audit_index')} />
                        {canAudit && ! creating && (
                            <button type="button" onClick={() => setCreating(true)} className={PRIMARY_ACTION}>
                                {ta.create ?? 'Ny revisjon'}
                            </button>
                        )}
                    </div>
                </header>

                {creating && (
                    <section className={CARD} aria-labelledby="compliance-audit-create-heading">
                        <h2 id="compliance-audit-create-heading" className="text-xl font-semibold text-slate-950">{ta.create_heading ?? 'Ny revisjon'}</h2>
                        <p className="mb-4 mt-1 text-base text-slate-600">{ta.create_hint ?? 'Krav og prosesser legges til i scope etter at revisjonen er opprettet.'}</p>
                        <AuditForm
                            form={form}
                            onSubmit={submitCreate}
                            onCancel={() => { setCreating(false); form.reset(); form.clearErrors(); }}
                            types={types}
                            responsibleOptions={responsibleOptions}
                            ta={ta}
                        />
                    </section>
                )}

                <ComplianceAttention attention={attention} tr={ta} testIdPrefix="compliance-audit-attention" />

                <section className={CARD}>
                    <form onSubmit={submitSearch} className="grid gap-3 sm:flex sm:flex-wrap sm:items-end">
                        <div className="min-w-0 sm:min-w-[16rem] sm:flex-1">
                            <label htmlFor="compliance-audit-search" className={FILTER_LABEL}>{ta.search_label ?? 'Søk'}</label>
                            <input
                                id="compliance-audit-search"
                                type="search"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder={ta.search_placeholder ?? 'Søk i tittel, scope og revisor'}
                                className={`mt-1 ${INPUT}`}
                            />
                        </div>
                        <div>
                            <label htmlFor="compliance-audit-status-filter" className={FILTER_LABEL}>{ta.status_filter ?? 'Status'}</label>
                            <select id="compliance-audit-status-filter" value={status} onChange={(event) => setStatus(event.target.value)} className={`mt-1 ${INPUT}`}>
                                <option value="">{ta.all_statuses ?? 'Alle statuser'}</option>
                                {statuses.map((value) => (
                                    <option key={value} value={value}>{auditStatusLabel(value, ta)}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="compliance-audit-type-filter" className={FILTER_LABEL}>{ta.type_filter ?? 'Type'}</label>
                            <select id="compliance-audit-type-filter" value={type} onChange={(event) => setType(event.target.value)} className={`mt-1 ${INPUT}`}>
                                <option value="">{ta.all_types ?? 'Alle typer'}</option>
                                {types.map((value) => (
                                    <option key={value} value={value}>{auditTypeLabel(value, ta)}</option>
                                ))}
                            </select>
                        </div>
                        <label className="flex min-h-10 items-center gap-2 text-base font-semibold text-slate-700">
                            <input
                                type="checkbox"
                                checked={attentionOnly}
                                onChange={(event) => setAttentionOnly(event.target.checked)}
                                className="h-5 w-5 rounded border-slate-300"
                                data-testid="compliance-audit-attention-filter"
                            />
                            {ta.attention?.filter ?? 'Bare revisjoner som trenger oppmerksomhet'}
                        </label>
                        <div className="flex flex-wrap gap-2">
                            <button type="submit" className={SECONDARY_ACTION}>{ta.search ?? 'Søk'}</button>
                            {filtered && (
                                <button type="button" onClick={resetSearch} className={SECONDARY_ACTION}>{ta.reset ?? 'Nullstill'}</button>
                            )}
                        </div>
                    </form>

                    <p className="mt-4 text-base text-slate-600" data-testid="compliance-audit-count">
                        {countLabel(visibleCount, ta.count_one ?? '1 revisjon', ta.count ?? ':count revisjoner')}
                    </p>

                    {audits.length === 0 ? (
                        <EmptyStateBox
                            className="mt-4"
                            title={filtered ? (ta.no_matches ?? 'Ingen revisjoner passer søket.') : (ta.empty_title ?? 'Ingen revisjoner registrert ennå')}
                            description={filtered || ! canAudit ? null : (ta.empty_hint ?? '')}
                        />
                    ) : (
                        <>
                            {/* Phones: one card per audit, so nothing has to scroll sideways. */}
                            <ul className="mt-4 divide-y divide-slate-100 md:hidden" data-testid="compliance-audit-list">
                                {audits.map((item) => (
                                    <li key={item.id} className="space-y-2 py-4">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <StatusBadge tone={AUDIT_STATUS_TONES[item.status] ?? 'slate'}>{auditStatusLabel(item.status, ta)}</StatusBadge>
                                            <span className="text-base font-semibold text-slate-700">{auditTypeLabel(item.audit_type, ta)}</span>
                                        </div>
                                        <Link href={item.url} className="block break-words text-base font-semibold text-violet-700 hover:text-violet-900">{item.title}</Link>
                                        <AttentionReasons reasons={item.attention ?? []} tr={ta} withLabel />
                                        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-base">
                                            <dt className="font-semibold text-slate-600">{ta.col_responsible ?? 'Ansvarlig'}</dt>
                                            <dd className="min-w-0 break-words text-slate-800"><Responsible item={item} ta={ta} /></dd>
                                            <dt className="font-semibold text-slate-600">{ta.col_period ?? 'Planlagt periode'}</dt>
                                            <dd className="min-w-0 text-slate-800">{plannedPeriodLabel(item, ta)}</dd>
                                        </dl>
                                    </li>
                                ))}
                            </ul>

                            <div className="mt-4 hidden overflow-x-auto md:block">
                                <table className="w-full text-base" data-testid="compliance-audit-table">
                                    <thead>
                                        <tr className="border-b border-slate-200 text-left text-base font-semibold text-slate-600">
                                            <th className="pb-3 pr-4">{ta.col_title ?? 'Revisjon'}</th>
                                            <th className="px-4 pb-3">{ta.col_type ?? 'Type'}</th>
                                            <th className="px-4 pb-3">{ta.col_responsible ?? 'Ansvarlig'}</th>
                                            <th className="px-4 pb-3">{ta.col_period ?? 'Planlagt periode'}</th>
                                            <th className="pb-3 pl-4">{ta.col_status ?? 'Status'}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {audits.map((item) => (
                                            <tr key={item.id}>
                                                <td className="py-3 pr-4 align-top">
                                                    <Link href={item.url} className="font-semibold text-violet-700 hover:text-violet-900">{item.title}</Link>
                                                    {(item.attention ?? []).length > 0 && (
                                                        <div className="mt-1">
                                                            <AttentionReasons reasons={item.attention} tr={ta} withLabel />
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 align-top text-slate-700">{auditTypeLabel(item.audit_type, ta)}</td>
                                                <td className="px-4 py-3 align-top text-slate-700"><Responsible item={item} ta={ta} /></td>
                                                <td className="whitespace-nowrap px-4 py-3 align-top text-slate-700">{plannedPeriodLabel(item, ta)}</td>
                                                <td className="py-3 pl-4 align-top">
                                                    <StatusBadge tone={AUDIT_STATUS_TONES[item.status] ?? 'slate'}>{auditStatusLabel(item.status, ta)}</StatusBadge>
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
