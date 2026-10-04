import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import RiskAcceptancePanel from './RiskAcceptancePanel';
import RiskAssessmentPanel from './RiskAssessmentPanel';
import RiskContextPanel from './RiskContextPanel';
import RiskControlsPanel from './RiskControlsPanel';
import RiskForm from './RiskForm';
import RiskReviewSchedule from './RiskReviewSchedule';
import RiskTreatmentPanel from './RiskTreatmentPanel';
import { RISK_STATUS_TONES } from './riskStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * One risk. Edit, assess and delete are offered only when the server said this person may do them
 * to a risk in this area; the controllers refuse them otherwise. Status (lifecycle) and risk level
 * (latest assessment) are shown apart on purpose.
 */
export default function RiskShow() {
    const {
        translations = {},
        risk,
        statuses = [],
        review_intervals: reviewIntervals = [],
        assessments = [],
        risk_criteria: riskCriteria,
        controls = null,
        control_options: controlOptions = [],
        quality_context: qualityContext = null,
        quality_context_options: qualityContextOptions = [],
        treatment_actions: treatmentActions = [],
        treatment_owner_options: treatmentOwnerOptions = [],
        risk_acceptance: riskAcceptance = null,
        review_schedule: reviewSchedule = null,
        permissions = {},
        area_options: areaOptions = [],
        owner_options: ownerOptions = [],
    } = usePage().props;

    const tr = translations?.risk ?? {};
    const statusLabels = tr.statuses ?? {};
    const [editing, setEditing] = useState(false);

    const form = useForm({
        title: risk.title ?? '',
        description: risk.description ?? '',
        business_area_id: String(risk.business_area_id ?? ''),
        owner_user_id: risk.owner_user_id ? String(risk.owner_user_id) : '',
        status: risk.status,
        review_interval_months: risk.review_interval_months ? String(risk.review_interval_months) : '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.patch(`/app/risk/risks/${risk.id}`, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const destroy = () => {
        if (! window.confirm(tr.delete_confirm ?? 'Slett risikoen? Dette kan ikke angres.')) {
            return;
        }

        router.delete(`/app/risk/risks/${risk.id}`);
    };

    return (
        <CustomerAppLayout title={risk.title} showPageTitle={false}>
            <div className="space-y-6">
                <Link href="/app/risk" className="text-base font-semibold text-violet-700 hover:text-violet-900">
                    ← {tr.back ?? 'Til risikoregisteret'}
                </Link>

                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-2">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950">{risk.title}</h1>
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge tone={RISK_STATUS_TONES[risk.status] ?? 'slate'}>
                                {statusLabels[risk.status] ?? risk.status}
                            </StatusBadge>
                            <StatusBadge tone="slate">{risk.area_name}</StatusBadge>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {permissions.can_edit && ! editing && (
                            <button type="button" onClick={() => setEditing(true)} className={SECONDARY_ACTION}>
                                {tr.edit ?? 'Rediger'}
                            </button>
                        )}
                        {permissions.can_delete && (
                            <button type="button" onClick={destroy} className={DESTRUCTIVE_ACTION}>
                                {tr.delete ?? 'Slett risiko'}
                            </button>
                        )}
                    </div>
                </header>

                {editing ? (
                    <section className={CARD}>
                        <RiskForm
                            form={form}
                            onSubmit={submit}
                            onCancel={() => { setEditing(false); form.reset(); form.clearErrors(); }}
                            areaOptions={areaOptions}
                            ownerOptions={ownerOptions}
                            statuses={statuses}
                            statusLabels={statusLabels}
                            reviewIntervals={reviewIntervals}
                            tr={tr}
                        />
                    </section>
                ) : (
                    <section className={CARD}>
                        <h2 className="text-lg font-semibold text-slate-950">{tr.details ?? 'Detaljer'}</h2>
                        <p className="mt-3 whitespace-pre-line text-base leading-6 text-slate-700">
                            {risk.description || (tr.no_description ?? 'Ingen beskrivelse.')}
                        </p>
                        <dl className="mt-6 grid gap-4 sm:grid-cols-3">
                            <div>
                                <dt className="text-sm font-semibold text-slate-600">{tr.field_owner ?? 'Risikoeier'}</dt>
                                <dd className="mt-1 text-base text-slate-900">{risk.owner_name ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-sm font-semibold text-slate-600">{tr.field_area ?? 'Fagområde'}</dt>
                                <dd className="mt-1 text-base text-slate-900">{risk.area_name}</dd>
                            </div>
                            <div>
                                <dt className="text-sm font-semibold text-slate-600">{tr.updated ?? 'Sist endret'}</dt>
                                <dd className="mt-1 text-base text-slate-900">
                                    {risk.updated_at ? new Date(risk.updated_at).toLocaleString('nb-NO') : '—'}
                                </dd>
                            </div>
                        </dl>
                        {reviewSchedule && <RiskReviewSchedule schedule={reviewSchedule} status={risk.status} tr={tr} />}
                    </section>
                )}

                <RiskAssessmentPanel
                    riskId={risk.id}
                    assessments={assessments}
                    criteria={riskCriteria}
                    canAssess={Boolean(permissions.can_assess)}
                    tr={tr}
                />

                <RiskAcceptancePanel
                    riskId={risk.id}
                    decision={riskAcceptance}
                    canAccept={Boolean(permissions.can_accept)}
                    tr={tr}
                />

                <RiskTreatmentPanel
                    riskId={risk.id}
                    actions={treatmentActions}
                    ownerOptions={treatmentOwnerOptions}
                    canManage={Boolean(permissions.can_manage_actions)}
                    tr={tr}
                />

                {/* null, not empty: the person cannot read Kvalitet, so nothing is said about context. */}
                {qualityContext !== null && (
                    <RiskContextPanel
                        riskId={risk.id}
                        context={qualityContext}
                        options={qualityContextOptions}
                        canLink={Boolean(permissions.can_link_context)}
                        tr={tr}
                    />
                )}

                {/* null, not empty: the person cannot read controls in Kvalitet, so nothing is said about them. */}
                {controls !== null && (
                    <RiskControlsPanel
                        riskId={risk.id}
                        controls={controls}
                        options={controlOptions}
                        canLink={Boolean(permissions.can_link_controls)}
                        tr={tr}
                        qualityStatuses={translations?.quality?.statuses ?? {}}
                    />
                )}
            </div>
        </CustomerAppLayout>
    );
}
