import { useEffect, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import StatusBadge from '../../../Components/App/StatusBadge';
import WikiKnowledgeHandoffPanel from '../../../Components/App/WikiKnowledgeHandoffPanel';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION } from '../../../Support/actionStyles';
import { formatLongDate } from '../Improvements/improvementStatus';
import SupplierAssessment from './SupplierAssessment';
import SupplierAssuranceStatus from './SupplierAssuranceStatus';
import SupplierControlRequirements from './SupplierControlRequirements';
import { SupplierAttentionFindings } from './SupplierAttention';
import SupplierCriticality from './SupplierCriticality';
import SupplierCriticalityBadge from './SupplierCriticalityBadge';
import SupplierDocuments from './SupplierDocuments';
import SupplierDueDiligence from './SupplierDueDiligence';
import SupplierFollowUpPlan from './SupplierFollowUpPlan';
import SupplierForm from './SupplierForm';
import SupplierHistory from './SupplierHistory';
import { SecurityPrivacyCard, SupplierOverviewSummaries } from './SupplierOverview';
import SupplierImprovementCases from './SupplierImprovementCases';
import SupplierProfile from './SupplierProfile';
import SupplierRequirements from './SupplierRequirements';
import SupplierRisks from './SupplierRisks';
import { SupplierEndForm, SupplierReopenForm } from './SupplierStatusForms';
import { supplierHelp } from './supplierHelp';
import { documentationSummary, securityPrivacySummary, supplierPageTabs, tabForAnchor, tabFromSearch, tabLabel, tabSearch } from './supplierPage';
import { SUPPLIER_STATUS_TONES, categoryLabel, statusLabel } from './supplierManagement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const TERM = 'text-base font-semibold text-slate-600';
const VALUE = 'mt-1 break-words text-base text-slate-900';

function Fact({ term, children }) {
    return (
        <div className="min-w-0">
            <dt className={TERM}>{term}</dt>
            <dd className={VALUE}>{children}</dd>
        </div>
    );
}

/**
 * One supplier: who they are, what they deliver, who follows them up and how to reach them; the
 * status and what can be done with it; and the history. Every action is offered only when the
 * server said this person may do it; the controller refuses it otherwise. A supplier that is not
 * ended can be edited and ended; an ended one only reopened. Status never moves through Rediger.
 */
export default function SupplierManagementShow() {
    const {
        translations = {},
        supplier: item,
        registered = null,
        criticality = null,
        profile = null,
        control_requirements: controlRequirements = null,
        assurance = null,
        activate_warning: activateWarning = false,
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
        requirements = null,
        requirement_linking: requirementLinking = null,
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
    const tabs = supplierPageTabs({ controlRequirements, requirements, dueDiligence });
    const [tab, setTab] = useState(() => tabFromSearch(typeof window === 'undefined' ? '' : window.location.search, tabs));
    // An in-page link to a section on another tab: the tab opens first, then the section scrolls in.
    const [scrollTo, setScrollTo] = useState(null);

    const openTab = (next, anchor = null) => {
        setTab(next);
        setScrollTo(anchor);

        if (typeof window !== 'undefined') {
            const { pathname, search } = window.location;
            // The tab is in the address, so a reload — and the redirect back after a form — lands on it.
            window.history.replaceState(window.history.state, '', `${pathname}${tabSearch(search, next)}${anchor ? `#${anchor}` : ''}`);
        }
    };

    useEffect(() => {
        if (scrollTo) {
            document.getElementById(scrollTo)?.scrollIntoView?.({ block: 'start' });
            setScrollTo(null);
        }
    }, [scrollTo, tab]);

    // Trenger oppmerksomhet, Neste kontroller and the summaries link to #sections; the ones on another
    // tab are opened there. An anchor on every tab (Kontrollstatus) is left to the browser.
    const followInPageLink = (event) => {
        const link = event.target.closest?.('a[href^="#"]');
        const anchor = link?.getAttribute('href')?.slice(1);
        const target = anchor ? tabForAnchor(anchor) : null;

        if (target && tabs.includes(target)) {
            event.preventDefault();
            openTab(target, anchor);
        }
    };

    // «Følg opp» and «Opprett risiko» open their form in the panels on Oversikt.
    const startFollowUp = (value) => { setFollowUp(value); openTab('overview'); };
    const startRisk = (value) => { setRiskFrom(value); openTab('overview'); };
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
        form.patch(`/app/supplier-management/${item.id}`, { preserveScroll: true, onSuccess: () => setPanel(null) });
    };

    const activate = () => {
        router.post(`/app/supplier-management/${item.id}/activate`, {}, { preserveScroll: true });
    };

    const destroy = () => {
        if (! window.confirm(tr.delete_confirm ?? 'Slett leverandøren for godt? Bruk dette bare når leverandøren ble registrert ved en feil. Dette kan ikke angres.')) {
            return;
        }

        router.delete(`/app/supplier-management/${item.id}`);
    };

    const hasContact = Boolean(item.contact_name || item.contact_email || item.contact_phone);
    const notRegistered = <span className="text-slate-600">{tr.not_registered ?? 'Ikke registrert'}</span>;

    return (
        <CustomerAppLayout title={item.name} showPageTitle={false}>
            <div className="space-y-6" onClickCapture={followInPageLink}>
                <Link href="/app/supplier-management" className="inline-block text-base font-semibold text-violet-700 hover:text-violet-900">
                    ← {tr.back ?? 'Til leverandører'}
                </Link>

                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-3">
                        <div className="flex flex-wrap gap-2" data-testid="supplier-badges">
                            <StatusBadge tone={SUPPLIER_STATUS_TONES[item.status] ?? 'slate'}>{statusLabel(item.status, tr)}</StatusBadge>
                            {item.criticality && <SupplierCriticalityBadge level={item.criticality} tr={tr} />}
                        </div>
                        <h1 className="break-words text-3xl font-semibold tracking-tight text-slate-950">{item.name}</h1>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <PageHelpButton {...supplierHelp(tr, 'supplier')} />
                        {permissions.can_edit && panel === null && (
                            <button type="button" onClick={() => { openTab('overview'); setPanel('edit'); }} className={SECONDARY_ACTION}>{tr.edit ?? 'Rediger'}</button>
                        )}
                    </div>
                </header>

                {/* An ended supplier keeps everything, and is no longer followed up (plan §13.3). */}
                {item.status === 'ended' && (
                    <p className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-base text-slate-800" data-testid="supplier-ended-notice">
                        {tr.status_text?.ended ?? 'Leverandøren er avsluttet. Den kan ikke endres, men alt som er registrert, er tatt vare på. Gjenåpne leverandøren for å endre den.'}
                    </p>
                )}

                {/* Kontrollstatus: Beslutning and Tilstand nå, side by side and never merged (plan §9.5). */}
                {assurance && <SupplierAssuranceStatus supplierId={item.id} data={assurance} showRequirementsLink={tabs.includes('requirements')} locale={locale} tr={tr} />}

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
                        {attention.length > 0 && (
                            <section className="rounded-[24px] border border-amber-200 bg-amber-50 p-6 shadow-sm" aria-labelledby="supplier-attention-heading" data-testid="supplier-attention">
                                <h2 id="supplier-attention-heading" className="text-xl font-semibold text-slate-950">{tr.attention?.heading ?? 'Trenger oppmerksomhet'}</h2>
                                <div className="mt-3">
                                    <SupplierAttentionFindings findings={attention} tr={tr} formatDate={(date) => formatLongDate(date, locale)} withLinks />
                                </div>
                            </section>
                        )}

                        {panel === 'edit' ? (
                            <section className={CARD} aria-labelledby="supplier-edit-heading">
                                <h2 id="supplier-edit-heading" className="mb-4 text-xl font-semibold text-slate-950">{tr.edit_heading ?? 'Rediger leverandør'}</h2>
                                <SupplierForm
                                    form={form}
                                    onSubmit={submit}
                                    onCancel={() => { setPanel(null); form.reset(); form.clearErrors(); }}
                                    categories={categories}
                                    ownerOptions={ownerOptions}
                                    tr={tr}
                                />
                            </section>
                        ) : (
                            <section className={CARD} aria-labelledby="supplier-details-heading">
                                <h2 id="supplier-details-heading" className="text-xl font-semibold text-slate-950">{tr.details_heading ?? 'Om leverandøren'}</h2>
                                <dl className="mt-4 grid gap-4 sm:grid-cols-3" data-testid="supplier-facts">
                                    <Fact term={fields.category ?? 'Kategori'}>{categoryLabel(item.category, tr)}</Fact>
                                    <Fact term={fields.organization_number ?? 'Organisasjonsnummer'}>{item.organization_number ?? notRegistered}</Fact>
                                    <Fact term={fields.owner ?? 'Intern ansvarlig'}>
                                        {item.owner_name ?? <span className="text-amber-800">{tr.no_owner ?? 'Mangler ansvarlig'}</span>}
                                    </Fact>
                                </dl>
                                <dl className="mt-4 space-y-4">
                                    <div>
                                        <dt className={TERM}>{fields.deliverable_description ?? 'Hva leverer de til oss?'}</dt>
                                        <dd className={`${VALUE} whitespace-pre-line`} data-testid="supplier-deliverable">{item.deliverable_description}</dd>
                                    </div>
                                    {item.note && (
                                        <div>
                                            <dt className={TERM}>{fields.note ?? 'Notat'}</dt>
                                            <dd className={`${VALUE} whitespace-pre-line`}>{item.note}</dd>
                                        </div>
                                    )}
                                </dl>

                                <h3 className="mt-6 text-lg font-semibold text-slate-950">{tr.contact_heading ?? 'Kontaktperson hos leverandøren'}</h3>
                                {hasContact ? (
                                    <dl className="mt-3 grid gap-4 sm:grid-cols-3" data-testid="supplier-contact">
                                        <Fact term={fields.contact_name ?? 'Navn'}>{item.contact_name ?? notRegistered}</Fact>
                                        <Fact term={fields.contact_email ?? 'E-post'}>
                                            {item.contact_email
                                                ? <a href={`mailto:${item.contact_email}`} className="font-semibold text-violet-700 hover:text-violet-900">{item.contact_email}</a>
                                                : notRegistered}
                                        </Fact>
                                        <Fact term={fields.contact_phone ?? 'Telefon'}>{item.contact_phone ?? notRegistered}</Fact>
                                    </dl>
                                ) : (
                                    <p className="mt-2 text-base text-slate-600">{tr.no_contact ?? 'Ingen kontaktperson er registrert.'}</p>
                                )}
                            </section>
                        )}

                        <SupplierCriticality
                            supplierId={item.id}
                            criticality={criticality}
                            canChange={permissions.can_change_criticality ?? false}
                            reviewIntervals={reviewIntervals}
                            locale={locale}
                            tr={tr}
                        />

                        <SupplierProfile
                            supplierId={item.id}
                            profile={profile}
                            canEdit={permissions.can_edit_profile ?? false}
                            locale={locale}
                            tr={tr}
                        />

                        <SecurityPrivacyCard summary={security} tr={tr} />

                        <SupplierOverviewSummaries
                            supplier={item}
                            documentation={documentationSummary(documents, today)}
                            assessments={assessments}
                            dueDiligence={dueDiligence}
                            formatDate={date}
                            tr={tr}
                        />

                        <SupplierFollowUpPlan plan={followUpPlan} formatDate={(date) => formatLongDate(date, locale)} tr={tr} />

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

                        <WikiKnowledgeHandoffPanel handoff={knowledgeHandoff} idPrefix={`supplier-${item.id}`} />

                        <section className={CARD} aria-labelledby="supplier-status-heading" data-testid="supplier-status">
                            <h2 id="supplier-status-heading" className="text-xl font-semibold text-slate-950">{tr.status_heading ?? 'Status'}</h2>
                            {/* An ended supplier says so at the top of the page, once. */}
                            {item.status !== 'ended' && <p className="mt-3 text-base text-slate-800">{tr.status_text?.[item.status] ?? ''}</p>}
                            {/* Ta i bruk has no form of its own; a refusal (the supplier moved on) lands here. */}
                            {panel === null && errors.reason && <p className="mt-2 text-base text-rose-700">{errors.reason}</p>}

                            {panel === null && (permissions.can_activate || permissions.can_end || permissions.can_reopen) && (
                                <div className="mt-4 flex flex-wrap gap-2">
                                    {permissions.can_activate && (
                                        <button type="button" onClick={activateWarning ? () => setPanel('activate') : activate} className={PRIMARY_ACTION}>{tr.activate ?? 'Ta i bruk'}</button>
                                    )}
                                    {permissions.can_end && (
                                        <button type="button" onClick={() => setPanel('end')} className={WARNING_ACTION}>{tr.end ?? 'Avslutt leverandør'}</button>
                                    )}
                                    {permissions.can_reopen && (
                                        <button type="button" onClick={() => setPanel('reopen')} className={PRIMARY_ACTION}>{tr.reopen ?? 'Gjenåpne leverandør'}</button>
                                    )}
                                </div>
                            )}

                            {/* Ta i bruk warns, never blocks (plan §9.6): Procynia is not the purchasing system. */}
                            {panel === 'activate' && (
                                <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4" role="alert" data-testid="activate-warning">
                                    <p className="text-lg font-semibold text-slate-950">{a.activate_warning_heading ?? 'Ta i bruk leverandøren?'}</p>
                                    {assurance?.state?.decision_required && <p className="mt-1 text-base text-slate-900">{a.activate_warning_decision_required}</p>}
                                    {assurance?.decision?.decision === 'not_approved' && <p className="mt-1 text-base text-slate-900">{a.activate_warning_not_approved}</p>}
                                    <p className="mt-1 text-base text-slate-800">{a.activate_warning_text}</p>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        <button type="button" onClick={() => { setPanel(null); activate(); }} className={WARNING_ACTION}>{a.activate_anyway ?? 'Ta i bruk likevel'}</button>
                                        <button type="button" onClick={() => setPanel(null)} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                                    </div>
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
                        </section>

                        {panel === 'end' && <SupplierEndForm supplierId={item.id} onDone={() => setPanel(null)} tr={tr} />}
                        {panel === 'reopen' && <SupplierReopenForm supplierId={item.id} onDone={() => setPanel(null)} tr={tr} />}
                    </div>
                )}

                {tab === 'requirements' && (
                    <div className="space-y-6" data-testid="supplier-tab-requirements">
                        {controlRequirements && (
                            <SupplierControlRequirements
                                supplierId={item.id}
                                data={controlRequirements}
                                onFollowUp={canFollowUp && followUp === null ? (evaluation) => startFollowUp({ evaluation }) : null}
                                locale={locale}
                                tr={tr}
                            />
                        )}

                        <SupplierRequirements
                            supplier={item}
                            requirements={requirements}
                            linking={requirementLinking}
                            hasEditRight={permissions.has_edit_right ?? false}
                            tr={tr}
                        />
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
                        <p className="text-base text-slate-600">{tr.page?.history_intro ?? 'Statusendringene står her. Historikken for kritikalitet, profil, kontroller, beslutninger og vurderinger står ved hver av dem.'}</p>
                        <SupplierHistory entries={statusHistory} registered={registered} locale={locale} tr={tr} />
                    </div>
                )}
            </div>
        </CustomerAppLayout>
    );
}
