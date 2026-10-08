import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION } from '../../../Support/actionStyles';
import { formatLongDate } from '../Improvements/improvementStatus';
import SupplierAssessment from './SupplierAssessment';
import SupplierControlRequirements from './SupplierControlRequirements';
import { SupplierAttentionFindings } from './SupplierAttention';
import SupplierCriticality from './SupplierCriticality';
import SupplierCriticalityBadge from './SupplierCriticalityBadge';
import SupplierDocuments from './SupplierDocuments';
import SupplierForm from './SupplierForm';
import SupplierHistory from './SupplierHistory';
import SupplierImprovementCases from './SupplierImprovementCases';
import SupplierProfile from './SupplierProfile';
import SupplierRequirements from './SupplierRequirements';
import SupplierRisks from './SupplierRisks';
import { SupplierEndForm, SupplierReopenForm } from './SupplierStatusForms';
import { supplierHelp } from './supplierHelp';
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
        attention = [],
        review_intervals: reviewIntervals = [],
        assessments = [],
        criteria = [],
        ratings = [],
        results = [],
        today = '',
        documents = [],
        document_types: documentTypes = [],
        document_standards: documentStandards = [],
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
    // Which panel is open: 'edit', 'end', 'reopen' or none. One at a time.
    const [panel, setPanel] = useState(null);
    // «Følg opp i Avvik og forbedringer», from the supplier ({ assessment: null }) or an assessment.
    const [followUp, setFollowUp] = useState(null);
    const canFollowUp = (improvementHandoff?.area_options ?? []).length > 0;

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
            <div className="space-y-6">
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
                            <button type="button" onClick={() => setPanel('edit')} className={SECONDARY_ACTION}>{tr.edit ?? 'Rediger'}</button>
                        )}
                    </div>
                </header>

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

                {controlRequirements && (
                    <SupplierControlRequirements supplierId={item.id} data={controlRequirements} locale={locale} tr={tr} />
                )}

                <SupplierAssessment
                    supplier={item}
                    assessments={assessments}
                    permissions={permissions}
                    criteria={criteria}
                    ratings={ratings}
                    results={results}
                    today={today}
                    onFollowUp={canFollowUp && followUp === null ? (assessment) => setFollowUp({ assessment }) : null}
                    locale={locale}
                    tr={tr}
                />

                <SupplierDocuments
                    supplierId={item.id}
                    supplierStatus={item.status}
                    documents={documents}
                    types={documentTypes}
                    standards={documentStandards}
                    reconfirmable={controlRequirements?.reconfirmable ?? {}}
                    permissions={permissions}
                    locale={locale}
                    tr={tr}
                />

                <SupplierRisks
                    supplier={item}
                    risks={risks}
                    handoff={riskHandoff}
                    hasEditRight={permissions.has_edit_right ?? false}
                    tr={tr}
                    trRisk={translations?.risk ?? {}}
                />

                <SupplierRequirements
                    supplier={item}
                    requirements={requirements}
                    linking={requirementLinking}
                    hasEditRight={permissions.has_edit_right ?? false}
                    tr={tr}
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

                <section className={CARD} aria-labelledby="supplier-status-heading" data-testid="supplier-status">
                    <h2 id="supplier-status-heading" className="text-xl font-semibold text-slate-950">{tr.status_heading ?? 'Status'}</h2>
                    <p className="mt-3 text-base text-slate-800">{tr.status_text?.[item.status] ?? ''}</p>
                    {/* Ta i bruk has no form of its own; a refusal (the supplier moved on) lands here. */}
                    {panel === null && errors.reason && <p className="mt-2 text-base text-rose-700">{errors.reason}</p>}

                    {panel === null && (permissions.can_activate || permissions.can_end || permissions.can_reopen) && (
                        <div className="mt-4 flex flex-wrap gap-2">
                            {permissions.can_activate && (
                                <button type="button" onClick={activate} className={PRIMARY_ACTION}>{tr.activate ?? 'Ta i bruk'}</button>
                            )}
                            {permissions.can_end && (
                                <button type="button" onClick={() => setPanel('end')} className={WARNING_ACTION}>{tr.end ?? 'Avslutt leverandør'}</button>
                            )}
                            {permissions.can_reopen && (
                                <button type="button" onClick={() => setPanel('reopen')} className={PRIMARY_ACTION}>{tr.reopen ?? 'Gjenåpne leverandør'}</button>
                            )}
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

                <SupplierHistory entries={statusHistory} registered={registered} locale={locale} tr={tr} />
            </div>
        </CustomerAppLayout>
    );
}
