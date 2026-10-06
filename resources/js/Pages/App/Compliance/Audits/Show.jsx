import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../../Components/App/PageHelpButton';
import StatusBadge from '../../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION } from '../../../../Support/actionStyles';
import { formatDay } from '../../Improvements/improvementStatus';
import { AttentionReasons } from '../Requirements/ComplianceAttention';
import { complianceHelp } from '../Requirements/complianceHelp';
import AuditFindings from './AuditFindings';
import AuditForm from './AuditForm';
import AuditHistory from './AuditHistory';
import { AuditCancelForm, AuditCompleteForm, AuditReopenForm, AuditStartForm } from './AuditLifecycleForms';
import { AuditProcessScope, AuditRequirementScope } from './AuditScope';
import { AUDIT_STATUS_TONES, auditStatusLabel, auditTypeLabel, lockedNotice } from './complianceAudit';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const TERM = 'text-base font-semibold text-slate-600';
const VALUE = 'mt-1 break-words text-base text-slate-900';

/**
 * One audit, read top to bottom: title and status with the actions the status allows; who is
 * responsible, who audits and when; the scope — the description that governs it, then the
 * requirements and (for someone who can read Kvalitet) the processes it covers; the findings and
 * their follow-up in Avvik og forbedringer; the conclusion; and the status history.
 *
 * Every action is offered only when the server said this person may take it now; the controller
 * refuses it otherwise. Status never moves through Rediger, and Rediger shows only the fields the
 * status leaves open. A completed audit is locked until Gjenåpne; a cancelled one stays read-only.
 */
export default function ComplianceAuditShow() {
    const {
        translations = {},
        audit,
        attention = [],
        requirements = [],
        requirement_options: requirementOptions = null,
        processes = null,
        process_options: processOptions = null,
        findings = [],
        finding_options: findingOptions = null,
        finding_types: findingTypes = [],
        handoff = null,
        status_history: statusHistory = [],
        permissions = {},
        editable_fields: editableFields = [],
        types = [],
        responsible_options: responsibleOptions = [],
        locale = 'no',
    } = usePage().props;

    const tr = translations?.compliance ?? {};
    const ta = tr.audits ?? {};
    // Which panel is open: 'edit', 'start', 'complete', 'cancel', 'reopen' or none. One at a time.
    const [panel, setPanel] = useState(null);
    const closePanel = () => setPanel(null);

    const form = useForm({
        title: audit.title ?? '',
        audit_type: audit.audit_type ?? '',
        responsible_user_id: audit.responsible_user_id ? String(audit.responsible_user_id) : '',
        auditor_name: audit.auditor_name ?? '',
        planned_start_date: audit.planned_start_date ?? '',
        planned_end_date: audit.planned_end_date ?? '',
        scope_description: audit.scope_description ?? '',
        conclusion: audit.conclusion ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.patch(`/app/compliance/audits/${audit.id}`, { preserveScroll: true, onSuccess: closePanel });
    };

    const destroy = () => {
        if (! window.confirm(ta.delete_confirm ?? 'Slett revisjonen? Dette kan ikke angres.')) {
            return;
        }

        router.delete(`/app/compliance/audits/${audit.id}`);
    };

    const locked = lockedNotice(audit.status, ta);
    const hasLifecycleAction = permissions.can_start || permissions.can_complete || permissions.can_cancel || permissions.can_reopen;

    return (
        <CustomerAppLayout title={audit.title} showPageTitle={false}>
            <div className="space-y-6">
                <Link href="/app/compliance/audits" className="inline-block text-base font-semibold text-violet-700 hover:text-violet-900">
                    ← {ta.back ?? 'Til revisjoner'}
                </Link>

                <header className="space-y-4">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="min-w-0 space-y-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <span data-testid="compliance-audit-status">
                                    <StatusBadge tone={AUDIT_STATUS_TONES[audit.status] ?? 'slate'}>{auditStatusLabel(audit.status, ta)}</StatusBadge>
                                </span>
                                <span className="text-base font-semibold text-slate-700">{auditTypeLabel(audit.audit_type, ta)}</span>
                            </div>
                            <h1 className="break-words text-3xl font-semibold tracking-tight text-slate-950">{audit.title}</h1>
                        </div>
                            <AttentionReasons reasons={attention} tr={ta} withLabel testId="compliance-audit-show-attention" />
                        <div className="flex flex-wrap items-center gap-2">
                            <PageHelpButton {...complianceHelp(tr, 'audit')} />
                            {permissions.can_edit && panel === null && (
                                <button type="button" onClick={() => setPanel('edit')} className={SECONDARY_ACTION}>{ta.edit ?? 'Rediger'}</button>
                            )}
                            {permissions.can_delete && (
                                <button type="button" onClick={destroy} className={DESTRUCTIVE_ACTION}>{ta.delete ?? 'Slett revisjon'}</button>
                            )}
                        </div>
                    </div>

                    {locked && (
                        <p className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-base text-slate-800" data-testid="compliance-audit-locked">{locked}</p>
                    )}

                    {panel === null && hasLifecycleAction && (
                        <div className="flex flex-wrap gap-2" data-testid="compliance-audit-actions">
                            {permissions.can_start && (
                                <button type="button" onClick={() => setPanel('start')} className={PRIMARY_ACTION}>{ta.start ?? 'Start revisjon'}</button>
                            )}
                            {permissions.can_complete && (
                                <button type="button" onClick={() => setPanel('complete')} className={PRIMARY_ACTION}>{ta.complete ?? 'Fullfør revisjon'}</button>
                            )}
                            {permissions.can_reopen && (
                                <button type="button" onClick={() => setPanel('reopen')} className={SECONDARY_ACTION}>{ta.reopen ?? 'Gjenåpne revisjon'}</button>
                            )}
                            {permissions.can_cancel && (
                                <button type="button" onClick={() => setPanel('cancel')} className={WARNING_ACTION}>{ta.cancel_audit ?? 'Avbryt revisjon'}</button>
                            )}
                        </div>
                    )}
                </header>

                {panel === 'start' && <AuditStartForm auditId={audit.id} onDone={closePanel} ta={ta} />}
                {panel === 'complete' && <AuditCompleteForm audit={audit} onDone={closePanel} ta={ta} />}
                {panel === 'cancel' && <AuditCancelForm auditId={audit.id} onDone={closePanel} ta={ta} />}
                {panel === 'reopen' && <AuditReopenForm auditId={audit.id} onDone={closePanel} ta={ta} />}

                {panel === 'edit' ? (
                    <section className={CARD} aria-label={ta.edit ?? 'Rediger'}>
                        <AuditForm
                            form={form}
                            onSubmit={submit}
                            onCancel={() => { closePanel(); form.reset(); form.clearErrors(); }}
                            fields={editableFields}
                            types={types}
                            responsibleOptions={responsibleOptions}
                            ta={ta}
                        />
                    </section>
                ) : (
                    <>
                        <section className={CARD} aria-labelledby="compliance-audit-info-heading">
                            <h2 id="compliance-audit-info-heading" className="text-xl font-semibold text-slate-950">{ta.info_heading ?? 'Revisjonsinformasjon'}</h2>
                            <dl className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-testid="compliance-audit-facts">
                                <div>
                                    <dt className={TERM}>{ta.field_responsible ?? 'Ansvarlig'}</dt>
                                    <dd className={VALUE}>{audit.responsible_name ?? <span className="text-amber-800">{ta.no_responsible ?? 'Mangler ansvarlig'}</span>}</dd>
                                </div>
                                <div>
                                    <dt className={TERM}>{ta.field_auditor ?? 'Revisor'}</dt>
                                    <dd className={VALUE}>{audit.auditor_name ?? <span className="text-slate-600">{ta.no_auditor ?? 'Ikke angitt'}</span>}</dd>
                                </div>
                                <div>
                                    <dt className={TERM}>{ta.field_start ?? 'Planlagt start'}</dt>
                                    <dd className={VALUE}>{formatDay(audit.planned_start_date, ta.no_start ?? 'Ikke angitt')}</dd>
                                </div>
                                <div>
                                    <dt className={TERM}>{ta.field_end ?? 'Planlagt slutt'}</dt>
                                    <dd className={VALUE}>{formatDay(audit.planned_end_date)}</dd>
                                </div>
                            </dl>
                        </section>

                        <section className={CARD} aria-labelledby="compliance-audit-scope-heading">
                            <h2 id="compliance-audit-scope-heading" className="text-xl font-semibold text-slate-950">{ta.scope_heading ?? 'Scope'}</h2>
                            <p className="mt-3 whitespace-pre-line break-words text-base leading-7 text-slate-800" data-testid="compliance-audit-scope">{audit.scope_description}</p>
                        </section>
                    </>
                )}

                <AuditRequirementScope
                    audit={audit}
                    requirements={requirements}
                    options={requirementOptions}
                    canManage={Boolean(permissions.can_manage_requirements)}
                    ta={ta}
                />

                {processes !== null && (
                    <AuditProcessScope
                        audit={audit}
                        processes={processes}
                        options={processOptions}
                        canManage={Boolean(permissions.can_manage_processes)}
                        ta={ta}
                    />
                )}

                <AuditFindings
                    audit={audit}
                    findings={findings}
                    options={findingOptions}
                    types={findingTypes}
                    canRecord={Boolean(permissions.can_record_findings)}
                    handoff={handoff}
                    ta={ta}
                />

                <section className={CARD} aria-labelledby="compliance-audit-conclusion-heading">
                    <h2 id="compliance-audit-conclusion-heading" className="text-xl font-semibold text-slate-950">{ta.conclusion_heading ?? 'Konklusjon'}</h2>
                    {audit.conclusion ? (
                        <p className="mt-3 whitespace-pre-line break-words text-base leading-7 text-slate-800" data-testid="compliance-audit-conclusion">{audit.conclusion}</p>
                    ) : (
                        <p className="mt-3 text-base text-slate-700" data-testid="compliance-audit-conclusion">
                            {audit.status === 'planned' ? (ta.conclusion_planned ?? 'Konklusjonen skrives når revisjonen er startet.') : (ta.conclusion_empty ?? 'Ingen konklusjon er skrevet ennå.')}
                        </p>
                    )}
                </section>

                <AuditHistory entries={statusHistory} locale={locale} ta={ta} />
            </div>
        </CustomerAppLayout>
    );
}
