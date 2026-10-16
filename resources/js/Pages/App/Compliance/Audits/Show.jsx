import { useEffect, useRef, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../../Components/App/PageHelpButton';
import StatusBadge from '../../../../Components/App/StatusBadge';
import WikiKnowledgeHandoffPanel from '../../../../Components/App/WikiKnowledgeHandoffPanel';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION } from '../../../../Support/actionStyles';
import { focusElement } from '../../../../Support/focusManagement';
import { inPageTargetId } from '../complianceA11y';
import { usePanelSwitchFocus } from '../useComplianceFocus';
import { formatDay } from '../../Improvements/improvementStatus';
import { AttentionReasons } from '../Requirements/ComplianceAttention';
import { complianceHelp } from '../Requirements/complianceHelp';
import AuditChecks, { AuditCoverage } from './AuditChecks';
import AuditFindings from './AuditFindings';
import AuditForm from './AuditForm';
import AuditHistory from './AuditHistory';
import { AuditCancelForm, AuditCompleteForm, AuditReopenForm, AuditStartForm } from './AuditLifecycleForms';
import AuditReport from './AuditReport';
import { AuditProcessScope, AuditRequirementScope } from './AuditScope';
import { AUDIT_PHASES, AUDIT_STATUS_TONES, auditPhase, auditPhaseLabel, auditStatusLabel, auditTypeLabel, lockedNotice } from './complianceAudit';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const TERM = 'text-base font-semibold text-slate-600';
const VALUE = 'mt-1 break-words text-base text-slate-900';

/**
 * Where focus goes when a panel closes (A11Y-014): the button that opened it — or, when the step
 * changed the status and that button is gone (Start → Under arbeid), the status itself, so the
 * new status is what is heard next. Rediger's button is always back after the edit.
 */
function panelReturnTargets(key) {
    return [`compliance-audit-action-${key}`, 'compliance-audit-status'];
}

/**
 * The three phases as tabs. The phase the audit is in is read off its status and marked; any tab can
 * be opened, so a completed audit still shows how it was planned and carried out.
 */
function PhaseTabs({ tab, setTab, current, ta }) {
    const move = (event, index) => {
        const next = { ArrowRight: index + 1, ArrowLeft: index - 1 }[event.key];

        if (next === undefined) {
            return;
        }

        event.preventDefault();
        const phase = AUDIT_PHASES[(next + AUDIT_PHASES.length) % AUDIT_PHASES.length];
        setTab(phase);
        document.getElementById(`compliance-audit-tab-${phase}`)?.focus();
    };

    return (
        <div role="tablist" aria-label={ta.phases_label ?? 'Revisjonens faser'} className="grid gap-2 sm:grid-cols-3" data-testid="compliance-audit-phases">
            {AUDIT_PHASES.map((phase, index) => {
                const selected = tab === phase;

                return (
                    <button
                        key={phase}
                        id={`compliance-audit-tab-${phase}`}
                        type="button"
                        role="tab"
                        aria-selected={selected}
                        aria-controls={`compliance-audit-panel-${phase}`}
                        tabIndex={selected ? 0 : -1}
                        onClick={() => setTab(phase)}
                        onKeyDown={(event) => move(event, index)}
                        className={`flex min-h-12 flex-col items-start rounded-2xl border px-4 py-2 text-left text-base ${selected ? 'border-violet-600 bg-violet-50 text-violet-900 ring-1 ring-inset ring-violet-600' : 'border-slate-200 bg-white text-slate-800 hover:border-slate-300'}`}
                    >
                        <span className="font-semibold">{index + 1}. {auditPhaseLabel(phase, ta)}</span>
                        {current === phase && <span className="text-base text-slate-700" data-testid="compliance-audit-current-phase">{ta.phase_current ?? 'Nåværende fase'}</span>}
                    </button>
                );
            })}
        </div>
    );
}

/** The finding or check the address points at (#finding-12, #check-3), if any. */
function linkedItem() {
    return inPageTargetId(typeof window === 'undefined' ? '' : window.location.hash);
}

/**
 * One audit, as the work runs: Planlegging (purpose, omfang, who and when, the requirements and —
 * for someone who can read Kvalitet — processes in scope), Gjennomføring (what is to be checked,
 * the kontrollhandlinger and the findings), and Rapport og oppfølging (the overview, the conclusion
 * and Fullfør). The phases are tabs over the existing status — never a status of their own — and
 * the page opens in the one the audit is in. The header keeps the status and the lifecycle actions;
 * the status history and the Wiki hand-off sit below every phase.
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
        checks = [],
        findings = [],
        finding_options: findingOptions = null,
        finding_types: findingTypes = [],
        handoff = null,
        knowledge_handoff: knowledgeHandoff = null,
        status_history: statusHistory = [],
        permissions = {},
        editable_fields: editableFields = [],
        types = [],
        responsible_options: responsibleOptions = [],
        locale = 'no',
        errors = {},
    } = usePage().props;

    const tr = translations?.compliance ?? {};
    const ta = tr.audits ?? {};
    const current = auditPhase(audit.status);
    const [tab, setTab] = useState(current ?? 'planning');
    // Which panel is open: 'edit', 'start', 'complete', 'cancel', 'reopen' or none. One at a time.
    const [panel, setPanel] = useState(null);
    const closePanel = () => setPanel(null);
    const pageRef = useRef(null);
    usePanelSwitchFocus(panel, { containerRef: pageRef, returnTargets: panelReturnTargets });
    // «Registrer funn» on a kontrollhandling: opens Nytt funn with that check chosen.
    const [findingDraft, setFindingDraft] = useState(null);

    // A link to one finding or check (#finding-12, as the case page's provenance sends) opens
    // Gjennomføring, where it is, whatever phase the audit is in — and puts focus on it, so the next
    // Tab continues from there rather than from the top of the page (2.4.3, A11Y-015).
    const linkedTarget = useRef(null);
    useEffect(() => {
        const targetId = linkedItem();

        if (targetId) {
            linkedTarget.current = targetId;
            setTab('execution');
        }
    }, []);

    // Once Gjennomføring is drawn, the finding or check is there to scroll to and focus.
    useEffect(() => {
        const targetId = linkedTarget.current;

        if (! targetId || tab !== 'execution') {
            return;
        }

        linkedTarget.current = null;
        window.requestAnimationFrame(() => {
            const target = document.getElementById(targetId);
            target?.scrollIntoView({ block: 'start' });
            focusElement(target, { preventScroll: true });
        });
    }, [tab]);

    // A lifecycle step moves the page along to the phase the audit is now in — only when the status
    // actually changes, never on the first render.
    const shownStatus = useRef(audit.status);
    useEffect(() => {
        if (shownStatus.current !== audit.status && current) {
            setTab(current);
        }

        shownStatus.current = audit.status;
    }, [audit.status]);

    const form = useForm({
        title: audit.title ?? '',
        purpose: audit.purpose ?? '',
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

    const openPanel = (name, phase = null) => {
        if (phase) {
            setTab(phase);
        }

        setPanel(name);
    };

    const newFindingFrom = (check) => setFindingDraft({
        key: `${check.id}-${Date.now()}`,
        check_id: check.id,
        requirement_id: check.requirements.length === 1 ? check.requirements[0].id : null,
    });

    const locked = lockedNotice(audit.status, ta);
    const hasLifecycleAction = permissions.can_start || permissions.can_complete || permissions.can_cancel || permissions.can_reopen;

    return (
        <CustomerAppLayout title={audit.title} showPageTitle={false} workView={panel === 'edit' ? (ta.edit ?? 'Rediger') : null}>
            <div ref={pageRef} className="space-y-6">
                <header className="space-y-4">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="min-w-0 space-y-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <span id="compliance-audit-status" data-testid="compliance-audit-status">
                                    <StatusBadge tone={AUDIT_STATUS_TONES[audit.status] ?? 'slate'}>{auditStatusLabel(audit.status, ta)}</StatusBadge>
                                </span>
                                <span className="text-base font-semibold text-slate-700">{auditTypeLabel(audit.audit_type, ta)}</span>
                            </div>
                            <h1 className="break-words text-3xl font-semibold tracking-tight text-slate-950">{audit.title}</h1>
                            <AttentionReasons reasons={attention} tr={ta} withLabel testId="compliance-audit-show-attention" />
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <PageHelpButton {...complianceHelp(tr, 'audit')} />
                            {permissions.can_edit && panel === null && (
                                <button type="button" onClick={() => openPanel('edit', 'planning')} id="compliance-audit-action-edit" className={SECONDARY_ACTION}>{ta.edit ?? 'Rediger'}</button>
                            )}
                            {permissions.can_delete && (
                                <button type="button" onClick={destroy} className={DESTRUCTIVE_ACTION}>{ta.delete ?? 'Slett revisjon'}</button>
                            )}
                        </div>
                    </div>

                    {/* A step the audit's status no longer allows — scope, checks, findings — answers on
                        `audit`; shown once here, whichever form or button sent it. */}
                    {errors.audit && (
                        <p role="alert" className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-base text-rose-800" data-testid="compliance-audit-error">{errors.audit}</p>
                    )}

                    {locked && (
                        <p className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-base text-slate-800" data-testid="compliance-audit-locked">{locked}</p>
                    )}

                    {panel === null && hasLifecycleAction && (
                        <div className="flex flex-wrap gap-2" data-testid="compliance-audit-actions">
                            {permissions.can_start && (
                                <button type="button" onClick={() => openPanel('start')} id="compliance-audit-action-start" className={PRIMARY_ACTION}>{ta.start ?? 'Start revisjon'}</button>
                            )}
                            {permissions.can_complete && (
                                <button type="button" onClick={() => openPanel('complete', 'report')} id="compliance-audit-action-complete" className={PRIMARY_ACTION}>{ta.complete ?? 'Fullfør revisjon'}</button>
                            )}
                            {permissions.can_reopen && (
                                <button type="button" onClick={() => openPanel('reopen')} id="compliance-audit-action-reopen" className={SECONDARY_ACTION}>{ta.reopen ?? 'Gjenåpne revisjon'}</button>
                            )}
                            {permissions.can_cancel && (
                                <button type="button" onClick={() => openPanel('cancel')} id="compliance-audit-action-cancel" className={WARNING_ACTION}>{ta.cancel_audit ?? 'Avbryt revisjon'}</button>
                            )}
                        </div>
                    )}
                </header>

                {panel === 'start' && <div data-inline-panel="start"><AuditStartForm auditId={audit.id} onDone={closePanel} ta={ta} /></div>}
                {panel === 'cancel' && <div data-inline-panel="cancel"><AuditCancelForm auditId={audit.id} onDone={closePanel} ta={ta} /></div>}
                {panel === 'reopen' && <div data-inline-panel="reopen"><AuditReopenForm auditId={audit.id} onDone={closePanel} ta={ta} /></div>}

                <PhaseTabs tab={tab} setTab={setTab} current={current} ta={ta} />
                {audit.status === 'cancelled' && (
                    <p className="text-base text-slate-700">{ta.phase_cancelled ?? 'Revisjonen er avbrutt. Fasene vises som de var da den ble avbrutt.'}</p>
                )}

                <div
                    role="tabpanel"
                    id={`compliance-audit-panel-${tab}`}
                    aria-labelledby={`compliance-audit-tab-${tab}`}
                    className="space-y-6"
                    data-testid={`compliance-audit-phase-${tab}`}
                >
                    <p className="text-base text-slate-600">{ta.phase_intros?.[tab] ?? ''}</p>

                    {tab === 'planning' && (
                        <>
                            {panel === 'edit' ? (
                                <section className={CARD} aria-label={ta.edit ?? 'Rediger'} data-inline-panel="edit">
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
                                    <dl className="mt-6 grid gap-4 md:grid-cols-2">
                                        <div className="min-w-0">
                                            <dt className={TERM}>{ta.field_purpose ?? 'Formål'}</dt>
                                            <dd className="mt-1 whitespace-pre-line break-words text-base leading-7 text-slate-800" data-testid="compliance-audit-purpose">
                                                {audit.purpose ?? <span className="text-slate-600">{ta.no_purpose ?? 'Ikke angitt'}</span>}
                                            </dd>
                                        </div>
                                        <div className="min-w-0">
                                            <dt className={TERM}>{ta.scope_heading ?? 'Omfang'}</dt>
                                            <dd className="mt-1 whitespace-pre-line break-words text-base leading-7 text-slate-800" data-testid="compliance-audit-scope">
                                                {audit.scope_description ?? <span className="text-slate-600">{ta.no_scope_text ?? 'Ingen beskrivelse. Omfanget er kravene og prosessene nedenfor.'}</span>}
                                            </dd>
                                        </div>
                                    </dl>
                                </section>
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

                            {permissions.can_start && panel === null && (
                                <section className={`${CARD} flex flex-wrap items-center justify-between gap-3`} data-testid="compliance-audit-start-hint">
                                    <p className="min-w-0 text-base text-slate-800">{ta.start_hint ?? 'Når planleggingen er klar, velger du «Start revisjon».'}</p>
                                    <button type="button" onClick={() => openPanel('start')} className={PRIMARY_ACTION}>{ta.start ?? 'Start revisjon'}</button>
                                </section>
                            )}
                        </>
                    )}

                    {tab === 'execution' && (
                        <>
                            <AuditCoverage requirements={requirements} ta={ta} />
                            <AuditChecks
                                audit={audit}
                                checks={checks}
                                scopeRequirements={requirements}
                                canRecord={Boolean(permissions.can_record_checks)}
                                canRecordFindings={Boolean(permissions.can_record_findings)}
                                onNewFinding={newFindingFrom}
                                ta={ta}
                            />
                            <AuditFindings
                                audit={audit}
                                findings={findings}
                                options={findingOptions}
                                types={findingTypes}
                                canRecord={Boolean(permissions.can_record_findings)}
                                handoff={handoff}
                                draft={findingDraft}
                                ta={ta}
                            />
                        </>
                    )}

                    {tab === 'report' && (
                        <AuditReport audit={audit} checks={checks} requirements={requirements} findings={findings} ta={ta}>
                            {panel === 'complete' && <div data-inline-panel="complete"><AuditCompleteForm audit={audit} onDone={closePanel} ta={ta} /></div>}
                        </AuditReport>
                    )}
                </div>

                <WikiKnowledgeHandoffPanel handoff={knowledgeHandoff} idPrefix={`compliance-audit-${audit.id}`} />

                <AuditHistory entries={statusHistory} locale={locale} ta={ta} />
            </div>
        </CustomerAppLayout>
    );
}
