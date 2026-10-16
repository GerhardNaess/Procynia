import { useEffect, useRef, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import StatusBadge from '../../../Components/App/StatusBadge';
import WikiKnowledgeHandoffPanel from '../../../Components/App/WikiKnowledgeHandoffPanel';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION } from '../../../Support/actionStyles';
import { focusElement, refocusIfLost, useFocusFirstError } from '../../../Support/focusManagement';
import { formatLongDate } from '../Improvements/improvementStatus';
import SupplierAssessment from './SupplierAssessment';
import SupplierRequirementStatus, { SupplierDecisionHistory } from './SupplierAssuranceStatus';
import SupplierControlRequirements from './SupplierControlRequirements';
import SupplierCriticality, { SupplierCriticalityHistory } from './SupplierCriticality';
import SupplierCriticalityBadge from './SupplierCriticalityBadge';
import SupplierDetailsSection from './SupplierDetailsSection';
import SupplierDocuments from './SupplierDocuments';
import SupplierDueDiligence from './SupplierDueDiligence';
import SupplierForm from './SupplierForm';
import SupplierHistory from './SupplierHistory';
import { OverviewAttention, SecurityPrivacyCard, SupplierInfoDetails, SupplierKeyFacts, SupplierNextControl } from './SupplierOverview';
import SupplierImprovementCases from './SupplierImprovementCases';
import SupplierProfile, { SupplierProfileHistory } from './SupplierProfile';
import SupplierEarlierComplianceLinks from './SupplierEarlierComplianceLinks';
import SupplierRisks from './SupplierRisks';
import { SupplierEndForm, SupplierReopenForm } from './SupplierStatusForms';
import { supplierHelp } from './supplierHelp';
import { inPageFocusTarget } from './supplierA11y';
import { useInlinePanelFocus } from './supplierFocus';
import {
    OVERVIEW_DETAILS,
    criticalitySummary,
    detailForAnchor,
    linkedCountSummary,
    nextControl,
    profileSummary,
    securityPrivacySummary,
    supplierPageTabs,
    tabForAnchor,
    tabFromSearch,
    tabLabel,
    tabSearch,
} from './supplierPage';
import { SUPPLIER_STATUS_TONES, statusLabel } from './supplierManagement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * One supplier. Oversikt answers who the supplier is, what it delivers, who is responsible, what
 * needs following up and when the next control is — «Trenger oppfølging» and «Om leverandøren» —
 * with everything else under «Detaljer», closed until opened, and «Andre handlinger» last. The work
 * itself is on the other tabs; the history of status, criticality and profile is on Historikk.
 *
 * Every action is offered only when the server said this person may do it; the controller refuses
 * it otherwise. A supplier that is not ended can be edited and ended; an ended one only reopened.
 * Status never moves through Rediger.
 */
export default function SupplierManagementShow() {
    const {
        translations = {},
        supplier: item,
        registered = null,
        criticality = null,
        profile = null,
        control_requirements: controlRequirements = null,
        requirement_status: requirementStatus = null,
        decision_history: decisionHistory = [],
        activate_warning: activateWarning = {},
        attention = [],
        follow_up_plan: followUpPlan = null,
        due_diligence: dueDiligence = null,
        review_intervals: reviewIntervals = [],
        assessments = [],
        criteria = [],
        ratings = [],
        results = [],
        today = '',
        documents = [],
        document_types: documentTypes = [],
        document_standards: documentStandards = [],
        document_file_accept: documentFileAccept,
        knowledge_handoff: knowledgeHandoff = null,
        improvement_cases: improvementCases = null,
        improvement_handoff: improvementHandoff = null,
        risks = null,
        risk_handoff: riskHandoff = null,
        earlier_compliance_links: earlierComplianceLinks = null,
        status_history: statusHistory = [],
        permissions = {},
        categories = [],
        owner_options: ownerOptions = [],
        errors = {},
        locale = 'no',
    } = usePage().props;

    const tr = translations?.supplier_management ?? {};
    const fields = tr.fields ?? {};
    const a = tr.assurance ?? {};
    // Which panel is open: 'edit', 'end', 'reopen', 'activate' or none. One at a time.
    const [panel, setPanel] = useState(null);
    // «Følg opp i Avvik og forbedringer», from the supplier ({ assessment: null }), an assessment, a control ({ evaluation }) or an aktsomhetsvurdering ({ dueDiligence }).
    const [followUp, setFollowUp] = useState(null);
    const canFollowUp = (improvementHandoff?.area_options ?? []).length > 0;
    // «Opprett risiko» from an aktsomhetsvurdering: the assessment it comes from, or null.
    const [riskFrom, setRiskFrom] = useState(null);
    const canCreateRisk = (riskHandoff?.area_options ?? []).length > 0;
    const date = (value) => formatLongDate(value, locale);
    const tabs = supplierPageTabs({ controlRequirements, dueDiligence });
    const [tab, setTab] = useState(() => tabFromSearch(typeof window === 'undefined' ? '' : window.location.search, tabs));
    // An in-page link to a section on another tab: the tab opens first, then the section scrolls in.
    const [scrollTo, setScrollTo] = useState(null);
    // Which sections under «Detaljer» are open; all closed at first.
    const [details, setDetails] = useState({});
    const toggleDetail = (key) => setDetails((open) => ({ ...open, [key]: ! open[key] }));
    const openDetail = (key) => setDetails((open) => ({ ...open, [key]: true }));

    const openTab = (next, anchor = null) => {
        setTab(next);
        setScrollTo(anchor);

        if (typeof window !== 'undefined') {
            const { pathname, search } = window.location;
            // The tab is in the address, so a reload — and the redirect back after a form — lands on it.
            window.history.replaceState(window.history.state, '', `${pathname}${tabSearch(search, next)}${anchor ? `#${anchor}` : ''}`);
        }
    };

    // The link moves focus with the view (A11Y-015): to the section's open/close button, or the row.
    useEffect(() => {
        if (scrollTo) {
            const target = document.getElementById(scrollTo);
            target?.scrollIntoView?.({ block: 'start' });
            focusElement(inPageFocusTarget(target), { preventScroll: true });
            setScrollTo(null);
        }
    }, [scrollTo, tab]);

    // Rediger and the status panels open in place of their buttons: focus in, and back (A11Y-014).
    const rememberEditTrigger = useInlinePanelFocus(panel === 'edit' ? 'edit' : null, { panelId: 'supplier-edit-panel', fallbackIds: ['supplier-edit-button', 'supplier-details-heading'] });
    const statusPanel = ['end', 'reopen', 'activate'].includes(panel) ? panel : null;
    const rememberStatusTrigger = useInlinePanelFocus(statusPanel, { panelId: 'supplier-status-panel', fallbackIds: ['supplier-status-heading'] });
    const editFormRef = useRef(null);
    const focusEditError = useFocusFirstError(editFormRef);

    // Trenger oppfølging and Neste kontroll link to #sections; the ones on another tab are opened
    // there, and a section under «Detaljer» is opened before it scrolls in.
    const followInPageLink = (event) => {
        const link = event.target.closest?.('a[href^="#"]');
        const anchor = link?.getAttribute('href')?.slice(1);
        const target = anchor ? tabForAnchor(anchor) : null;

        if (target && tabs.includes(target)) {
            event.preventDefault();
            const detail = detailForAnchor(anchor);

            if (detail) {
                openDetail(detail);
            }

            openTab(target, anchor);
        }
    };

    // «Følg opp» and «Opprett risiko» open their form in its section under «Detaljer» on Oversikt.
    const startFollowUp = (value) => { setFollowUp(value); openDetail('cases'); openTab('overview'); };
    const startRisk = (value) => { setRiskFrom(value); openDetail('risks'); openTab('overview'); };
    const o = tr.page?.overview ?? {};
    const next = nextControl(followUpPlan, item);
    const security = securityPrivacySummary(profile, controlRequirements?.applicable ?? [], tr);

    const form = useForm({
        name: item.name ?? '',
        organization_number: item.organization_number ?? '',
        category: item.category ?? '',
        deliverable_description: item.deliverable_description ?? '',
        owner_user_id: item.owner_user_id ? String(item.owner_user_id) : '',
        contact_name: item.contact_name ?? '',
        contact_email: item.contact_email ?? '',
        contact_phone: item.contact_phone ?? '',
        note: item.note ?? '',
    });

    const submit = (event) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        form.patch(`/app/supplier-management/${item.id}`, { preserveScroll: true, onSuccess: () => setPanel(null), onError: focusEditError });
    };

    const activate = () => {
        // Ta i bruk removes its own button; focus goes to the status section rather than <body>.
        router.post(`/app/supplier-management/${item.id}/activate`, {}, { preserveScroll: true, onFinish: () => refocusIfLost(['supplier-end-button', 'supplier-status-heading']) });
    };

    const destroy = () => {
        if (! window.confirm(tr.delete_confirm ?? 'Slett leverandøren for godt? Bruk dette bare når leverandøren ble registrert ved en feil. Dette kan ikke angres.')) {
            return;
        }

        router.delete(`/app/supplier-management/${item.id}`);
    };

    // Ta i bruk warns — never blocks — when a mandatory requirement is open, or the last decision
    // registered before they were retired was «Ikke godkjent for nye kjøp».
    const warnOnActivate = Boolean(activateWarning.mandatory_open || activateWarning.not_approved_on);

    return (
        <CustomerAppLayout title={item.name} showPageTitle={false} workView={panel === 'edit' ? (tr.edit ?? 'Rediger') : null}>
            <div className="space-y-6" onClickCapture={followInPageLink}>
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-3">
                        <div className="flex flex-wrap gap-2" data-testid="supplier-badges">
                            <StatusBadge tone={SUPPLIER_STATUS_TONES[item.status] ?? 'slate'}>{statusLabel(item.status, tr)}</StatusBadge>
                            {item.criticality && <SupplierCriticalityBadge level={item.criticality} tr={tr} />}
                        </div>
                        <h1 className="break-words text-3xl font-semibold tracking-tight text-slate-950">{item.name}</h1>
                    </div>
                    <div className="flex flex-wrap items-center gap-2" onClickCapture={rememberEditTrigger}>
                        <PageHelpButton {...supplierHelp(tr, 'supplier')} />
                        {permissions.can_edit && panel === null && (
                            <button type="button" id="supplier-edit-button" data-panel-trigger="" onClick={() => { openTab('overview'); setPanel('edit'); }} className={SECONDARY_ACTION}>{tr.edit ?? 'Rediger'}</button>
                        )}
                    </div>
                </header>

                {/* An ended supplier keeps everything, and is no longer followed up (plan §13.3). */}
                {item.status === 'ended' && (
                    <p className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-base text-slate-800" data-testid="supplier-ended-notice">
                        {tr.status_text?.ended ?? 'Leverandøren er avsluttet. Den kan ikke endres, men alt som er registrert, er tatt vare på. Gjenåpne leverandøren for å endre den.'}
                    </p>
                )}

                <nav aria-label={tr.page?.tabs_label ?? 'Leverandørsiden'} className="flex flex-wrap gap-x-1 gap-y-0 border-b border-slate-200" data-testid="supplier-page-tabs">
                    {tabs.map((key) => (
                        <a
                            key={key}
                            href={`${item.url}${tabSearch('', key)}`}
                            onClick={(event) => { event.preventDefault(); openTab(key); }}
                            aria-current={tab === key ? 'page' : undefined}
                            data-tab={key}
                            className={`-mb-px inline-flex min-h-11 items-center border-b-2 px-3 text-base font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600 ${tab === key ? 'border-violet-600 text-violet-800' : 'border-transparent text-slate-600 hover:text-slate-900'}`}
                        >
                            {tabLabel(key, tr)}
                        </a>
                    ))}
                </nav>

                {tab === 'overview' && (
                    <div className="space-y-6" data-testid="supplier-tab-overview">
                        {/* Trenger oppfølging: what is missing and where it is done — the count, never the list. */}
                        <OverviewAttention findings={attention} formatDate={date} tr={tr} />

                        {panel === 'edit' ? (
                            <section id="supplier-edit-panel" className={CARD} aria-labelledby="supplier-edit-heading">
                                <h2 id="supplier-edit-heading" className="mb-4 text-xl font-semibold text-slate-950">{tr.edit_heading ?? 'Rediger leverandør'}</h2>
                                <SupplierForm
                                    form={form}
                                    formRef={editFormRef}
                                    onSubmit={submit}
                                    onCancel={() => { setPanel(null); form.reset(); form.clearErrors(); }}
                                    categories={categories}
                                    ownerOptions={ownerOptions}
                                    tr={tr}
                                />
                            </section>
                        ) : (
                            <SupplierKeyFacts supplier={item} tr={tr} />
                        )}

                        <SupplierNextControl next={next} plan={followUpPlan} formatDate={date} tr={tr} />

                        {/* Detaljer: everything else, closed until opened. A closed section keeps its form. */}
                        <section className={CARD} aria-labelledby="supplier-more-details-heading" data-testid="supplier-details">
                            <h2 id="supplier-more-details-heading" className="text-xl font-semibold text-slate-950">{o.details_heading ?? 'Detaljer'}</h2>
                            <div className="mt-2">
                                <SupplierDetailsSection
                                    headingId={OVERVIEW_DETAILS.info}
                                    testId="details-info"
                                    title={o.info_heading ?? 'Leverandørinformasjon'}
                                    summary={item.note ? (o.info_summary_note ?? 'Notat og registrering') : (o.info_summary ?? 'Registrering')}
                                    open={Boolean(details.info)}
                                    onToggle={() => toggleDetail('info')}
                                >
                                    <SupplierInfoDetails supplier={item} registered={registered} formatDate={date} tr={tr} />
                                </SupplierDetailsSection>

                                <SupplierDetailsSection
                                    headingId={OVERVIEW_DETAILS.criticality}
                                    testId="details-criticality"
                                    title={tr.criticality?.heading ?? 'Kritikalitet'}
                                    summary={criticalitySummary(criticality, tr, date)}
                                    open={Boolean(details.criticality)}
                                    onToggle={() => toggleDetail('criticality')}
                                >
                                    <SupplierCriticality
                                        supplierId={item.id}
                                        criticality={criticality}
                                        canChange={permissions.can_change_criticality ?? false}
                                        reviewIntervals={reviewIntervals}
                                        locale={locale}
                                        tr={tr}
                                    />
                                </SupplierDetailsSection>

                                {profile && (
                                    <SupplierDetailsSection
                                        headingId={OVERVIEW_DETAILS.profile}
                                        testId="details-profile"
                                        title={tr.profile?.heading ?? 'Leverandørprofil'}
                                        summary={profileSummary(profile, tr)}
                                        open={Boolean(details.profile)}
                                        onToggle={() => toggleDetail('profile')}
                                    >
                                        <SupplierProfile
                                            supplierId={item.id}
                                            profile={profile}
                                            canEdit={permissions.can_edit_profile ?? false}
                                            locale={locale}
                                            tr={tr}
                                        />
                                    </SupplierDetailsSection>
                                )}

                                {risks !== null && (
                                    <SupplierDetailsSection
                                        headingId={OVERVIEW_DETAILS.risks}
                                        testId="details-risks"
                                        title={o.risks_heading ?? 'Risikoer'}
                                        summary={linkedCountSummary(risks.length, { none: o.risks_none ?? 'Ingen tilknyttede risikoer', one: o.risks_one ?? '1 risiko', many: o.risks_many ?? ':count risikoer' })}
                                        open={Boolean(details.risks)}
                                        onToggle={() => toggleDetail('risks')}
                                    >
                                        <SupplierRisks
                                            supplier={item}
                                            risks={risks}
                                            handoff={riskHandoff}
                                            hasEditRight={permissions.has_edit_right ?? false}
                                            fromDueDiligence={riskFrom}
                                            onDueDiligenceDone={() => setRiskFrom(null)}
                                            formatDate={date}
                                            tr={tr}
                                            trRisk={translations?.risk ?? {}}
                                        />
                                    </SupplierDetailsSection>
                                )}

                                {improvementCases !== null && (
                                    <SupplierDetailsSection
                                        headingId={OVERVIEW_DETAILS.cases}
                                        testId="details-cases"
                                        title={o.cases_heading ?? 'Avvik og forbedringer'}
                                        summary={linkedCountSummary(improvementCases.length, { none: o.cases_none ?? 'Ingen tilknyttede saker', one: o.cases_one ?? '1 sak', many: o.cases_many ?? ':count saker' })}
                                        open={Boolean(details.cases)}
                                        onToggle={() => toggleDetail('cases')}
                                    >
                                        <SupplierImprovementCases
                                            supplier={item}
                                            cases={improvementCases}
                                            handoff={improvementHandoff}
                                            followUp={followUp}
                                            setFollowUp={setFollowUp}
                                            hasEditRight={permissions.has_edit_right ?? false}
                                            locale={locale}
                                            tr={tr}
                                            ti={translations?.improvements ?? {}}
                                        />
                                    </SupplierDetailsSection>
                                )}
                            </div>
                        </section>

                        {/* Andre handlinger: what is not part of following the supplier up day to day. */}
                        <section className="space-y-4 rounded-[24px] border border-slate-200 bg-slate-50 p-6" aria-labelledby="supplier-other-actions-heading" data-testid="supplier-other-actions">
                            <h2 id="supplier-other-actions-heading" className="text-lg font-semibold text-slate-950">{o.other_actions_heading ?? 'Andre handlinger'}</h2>

                            <div className="rounded-2xl border border-slate-200 bg-white p-5" data-testid="supplier-status" onClickCapture={rememberStatusTrigger}>
                                <h3 id="supplier-status-heading" className="text-lg font-semibold text-slate-950">{tr.status_heading ?? 'Status'}</h3>
                                {/* An ended supplier says so at the top of the page, once. */}
                                {item.status !== 'ended' && <p className="mt-2 text-base text-slate-800">{tr.status_text?.[item.status] ?? ''}</p>}
                                {/* Ta i bruk has no form of its own; a refusal (the supplier moved on) lands here. */}
                                {panel === null && errors.reason && <p className="mt-2 text-base text-rose-700">{errors.reason}</p>}

                                {panel === null && (permissions.can_activate || permissions.can_end || permissions.can_reopen) && (
                                    <div className="mt-4 flex flex-wrap gap-2">
                                        {permissions.can_activate && (
                                            <button type="button" id="supplier-activate-button" data-panel-trigger="" onClick={warnOnActivate ? () => setPanel('activate') : activate} className={PRIMARY_ACTION}>{tr.activate ?? 'Ta i bruk'}</button>
                                        )}
                                        {permissions.can_end && (
                                            <button type="button" id="supplier-end-button" data-panel-trigger="" onClick={() => setPanel('end')} className={WARNING_ACTION}>{tr.end ?? 'Avslutt leverandør'}</button>
                                        )}
                                        {permissions.can_reopen && (
                                            <button type="button" id="supplier-reopen-button" data-panel-trigger="" onClick={() => setPanel('reopen')} className={PRIMARY_ACTION}>{tr.reopen ?? 'Gjenåpne leverandør'}</button>
                                        )}
                                    </div>
                                )}

                                {statusPanel && (
                                    <div id="supplier-status-panel">
                                        {/* Ta i bruk warns, never blocks (plan §9.6): Procynia is not the purchasing system. Focus
                                            goes to «Ta i bruk likevel», which the warning describes — no alert that mounts with its text. */}
                                        {panel === 'activate' && (
                                            <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4" role="group" aria-labelledby="supplier-activate-warning-heading" data-testid="activate-warning">
                                                <p id="supplier-activate-warning-heading" className="text-lg font-semibold text-slate-950">{a.activate_warning_heading ?? 'Ta i bruk leverandøren?'}</p>
                                                <div id="supplier-activate-warning-text">
                                                    {activateWarning.mandatory_open && <p className="mt-1 text-base text-slate-900">{a.activate_warning_mandatory_open ?? 'Et obligatorisk kontrollkrav er ikke dokumentert eller midlertidig akseptert.'}</p>}
                                                    {activateWarning.not_approved_on && <p className="mt-1 text-base text-slate-900">{(a.activate_warning_not_approved ?? 'Siste kontrollbeslutning som ble registrert, var «Ikke godkjent for nye kjøp» (:date).').replace(':date', date(activateWarning.not_approved_on))}</p>}
                                                    <p className="mt-1 text-base text-slate-800">{a.activate_warning_text}</p>
                                                </div>
                                                <div className="mt-3 flex flex-wrap gap-2">
                                                    <button type="button" aria-describedby="supplier-activate-warning-text" onClick={() => { setPanel(null); activate(); }} className={WARNING_ACTION}>{a.activate_anyway ?? 'Ta i bruk likevel'}</button>
                                                    <button type="button" onClick={() => setPanel(null)} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                                                </div>
                                            </div>
                                        )}

                                        {panel === 'end' && <SupplierEndForm supplierId={item.id} onDone={() => setPanel(null)} tr={tr} />}
                                        {panel === 'reopen' && <SupplierReopenForm supplierId={item.id} onDone={() => setPanel(null)} tr={tr} />}
                                    </div>
                                )}

                                {panel === null && permissions.has_delete_right && (
                                    <div className="mt-6 border-t border-slate-100 pt-4" data-testid="supplier-delete">
                                        {permissions.can_delete ? (
                                            <>
                                                <p className="text-base text-slate-600">{tr.delete_hint ?? 'Sletting er bare for en leverandør som ble registrert ved en feil. Er leverandøren ikke lenger i bruk, avslutt den i stedet.'}</p>
                                                <button type="button" onClick={destroy} className={`mt-3 ${DESTRUCTIVE_ACTION}`}>{tr.delete ?? 'Slett leverandør'}</button>
                                            </>
                                        ) : (
                                            <p className="text-base text-slate-600">{tr.not_deletable_hint ?? 'Leverandøren har historikk og kan ikke slettes. Avslutt den i stedet.'}</p>
                                        )}
                                    </div>
                                )}
                            </div>

                            <WikiKnowledgeHandoffPanel handoff={knowledgeHandoff} idPrefix={`supplier-${item.id}`} />
                        </section>
                    </div>
                )}

                {tab === 'requirements' && (
                    <div className="space-y-6" data-testid="supplier-tab-requirements">
                        {/* Kravstatus: the facts about the requirements, above the requirements themselves. */}
                        <SupplierRequirementStatus state={requirementStatus} tr={tr} />

                        {/* Sikkerhet og personvern: a filtered view of these requirements, beside them. */}
                        <SecurityPrivacyCard summary={security} tr={tr} />

                        {controlRequirements && (
                            <SupplierControlRequirements
                                supplierId={item.id}
                                data={controlRequirements}
                                onFollowUp={canFollowUp && followUp === null ? (evaluation) => startFollowUp({ evaluation }) : null}
                                locale={locale}
                                tr={tr}
                            />
                        )}
                    </div>
                )}

                {tab === 'documents' && (
                    <div className="space-y-6" data-testid="supplier-tab-documents">
                        <SupplierDocuments
                            supplierId={item.id}
                            supplierStatus={item.status}
                            documents={documents}
                            types={documentTypes}
                            standards={documentStandards}
                            accept={documentFileAccept}
                            reconfirmable={controlRequirements?.reconfirmable ?? {}}
                            permissions={permissions}
                            locale={locale}
                            tr={tr}
                        />
                    </div>
                )}

                {tab === 'assessments' && (
                    <div className="space-y-6" data-testid="supplier-tab-assessments">
                        <SupplierAssessment
                            supplier={item}
                            assessments={assessments}
                            permissions={permissions}
                            criteria={criteria}
                            ratings={ratings}
                            results={results}
                            today={today}
                            onFollowUp={canFollowUp && followUp === null ? (assessment) => startFollowUp({ assessment }) : null}
                            locale={locale}
                            tr={tr}
                        />
                    </div>
                )}

                {tab === 'due_diligence' && (
                    <div className="space-y-6" data-testid="supplier-tab-due-diligence">
                        <SupplierDueDiligence
                            supplierId={item.id}
                            data={dueDiligence}
                            applicable={controlRequirements?.applicable ?? []}
                            onFollowUp={canFollowUp && followUp === null ? (assessment) => startFollowUp({ dueDiligence: assessment }) : null}
                            onCreateRisk={canCreateRisk && riskFrom === null ? (assessment) => startRisk(assessment) : null}
                            formatDate={date}
                            tr={tr}
                        />
                    </div>
                )}

                {tab === 'history' && (
                    <div className="space-y-6" data-testid="supplier-tab-history">
                        <p className="text-base text-slate-600">{tr.page?.history_intro ?? 'Endringer i status, kritikalitet og leverandørprofil, nyeste først. Historikken for kontroller, dokumentasjon og vurderinger står på fanen der de registreres.'}</p>
                        <SupplierHistory entries={statusHistory} registered={registered} locale={locale} tr={tr} />
                        <SupplierCriticalityHistory criticality={criticality} locale={locale} tr={tr} />
                        <SupplierProfileHistory profile={profile} locale={locale} tr={tr} />
                        <SupplierDecisionHistory history={decisionHistory} locale={locale} tr={tr} />
                        <SupplierEarlierComplianceLinks links={earlierComplianceLinks} tr={tr} />
                    </div>
                )}
            </div>
        </CustomerAppLayout>
    );
}
