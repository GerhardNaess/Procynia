import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../../Components/App/PageHelpButton';
import StatusBadge from '../../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import RequirementForm from './RequirementForm';
import RequirementHistory from './RequirementHistory';
import { RequirementReopenForm, RequirementRetireForm } from './RequirementStatusForms';
import { complianceHelp } from './complianceHelp';
import { REQUIREMENT_STATUS_TONES, reviewIntervalLabel } from './complianceRequirement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const TERM = 'text-base font-semibold text-slate-600';
const VALUE = 'mt-1 break-words text-base text-slate-900';

/**
 * One requirement: what it says, where it comes from, who follows it up and how often it is
 * reassessed, its status, and the status history. Every action is offered only when the server
 * said this person may do it; the controller refuses it otherwise. An active requirement can be
 * edited and retired; a retired one only reopened. Status never moves through Rediger.
 */
export default function ComplianceRequirementShow() {
    const {
        translations = {},
        requirement: item,
        status_history: statusHistory = [],
        locale = 'no',
        permissions = {},
        source_options: sourceOptions = [],
        owner_options: ownerOptions = [],
        review_intervals: reviewIntervals = [],
    } = usePage().props;

    const tr = translations?.compliance ?? {};
    const statusLabels = tr.statuses ?? {};
    // Which panel is open: 'edit', 'retire', 'reopen' or none. One at a time.
    const [panel, setPanel] = useState(null);

    const form = useForm({
        source_id: String(item.source_id ?? ''),
        reference: item.reference ?? '',
        title: item.title ?? '',
        requirement_text: item.requirement_text ?? '',
        owner_user_id: item.owner_user_id ? String(item.owner_user_id) : '',
        review_interval_months: item.review_interval_months ? String(item.review_interval_months) : '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.patch(`/app/compliance/requirements/${item.id}`, { preserveScroll: true, onSuccess: () => setPanel(null) });
    };

    const destroy = () => {
        if (! window.confirm(tr.delete_confirm ?? 'Slett kravet? Dette kan ikke angres.')) {
            return;
        }

        router.delete(`/app/compliance/requirements/${item.id}`);
    };

    return (
        <CustomerAppLayout title={item.title} showPageTitle={false}>
            <div className="space-y-6">
                <Link href="/app/compliance/requirements" className="inline-block text-base font-semibold text-violet-700 hover:text-violet-900">
                    ← {tr.back ?? 'Til krav'}
                </Link>

                <header className="space-y-4">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="min-w-0 space-y-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge tone={REQUIREMENT_STATUS_TONES[item.status] ?? 'slate'}>{statusLabels[item.status] ?? item.status}</StatusBadge>
                                {item.reference && <span className="break-words text-base font-semibold text-slate-700" data-testid="compliance-reference">{item.reference}</span>}
                            </div>
                            <h1 className="break-words text-3xl font-semibold tracking-tight text-slate-950">{item.title}</h1>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <PageHelpButton {...complianceHelp(tr, 'requirement')} />
                            {permissions.can_edit && panel === null && (
                                <button type="button" onClick={() => setPanel('edit')} className={SECONDARY_ACTION}>{tr.edit ?? 'Rediger'}</button>
                            )}
                            {permissions.can_delete && (
                                <button type="button" onClick={destroy} className={DESTRUCTIVE_ACTION}>{tr.delete ?? 'Slett krav'}</button>
                            )}
                        </div>
                    </div>
                </header>

                {panel === 'edit' ? (
                    <section className={CARD} aria-label={tr.edit ?? 'Rediger'}>
                        <RequirementForm
                            form={form}
                            onSubmit={submit}
                            onCancel={() => { setPanel(null); form.reset(); form.clearErrors(); }}
                            sourceOptions={sourceOptions}
                            ownerOptions={ownerOptions}
                            reviewIntervals={reviewIntervals}
                            tr={tr}
                        />
                    </section>
                ) : (
                    <section className={CARD} aria-labelledby="compliance-text-heading">
                        <h2 id="compliance-text-heading" className="text-xl font-semibold text-slate-950">{tr.text_heading ?? 'Kravtekst'}</h2>
                        <p className="mt-3 whitespace-pre-line break-words text-base leading-7 text-slate-800" data-testid="compliance-requirement-text">{item.requirement_text}</p>
                    </section>
                )}

                <section className={CARD} aria-labelledby="compliance-details-heading">
                    <h2 id="compliance-details-heading" className="text-xl font-semibold text-slate-950">{tr.details_heading ?? 'Kilde og ansvar'}</h2>
                    <dl className="mt-4 grid gap-4 sm:grid-cols-3" data-testid="compliance-facts">
                        <div>
                            <dt className={TERM}>{tr.field_source ?? 'Kravkilde'}</dt>
                            <dd className={VALUE}>{item.source_label}</dd>
                        </div>
                        <div>
                            <dt className={TERM}>{tr.field_owner ?? 'Ansvarlig'}</dt>
                            <dd className={VALUE}>{item.owner_name ?? <span className="text-amber-800">{tr.no_owner ?? 'Mangler ansvarlig'}</span>}</dd>
                        </div>
                        <div>
                            <dt className={TERM}>{tr.field_review ?? 'Revurderingsintervall'}</dt>
                            <dd className={VALUE}>{reviewIntervalLabel(item.review_interval_months, tr)}</dd>
                        </div>
                    </dl>
                </section>

                <section className={CARD} aria-labelledby="compliance-status-heading" data-testid="compliance-status">
                    <h2 id="compliance-status-heading" className="text-xl font-semibold text-slate-950">{tr.status_heading ?? 'Status'}</h2>
                    <p className="mt-3 text-base text-slate-800">{tr.status_text?.[item.status] ?? ''}</p>

                    {panel === null && (permissions.can_retire || permissions.can_reopen) && (
                        <div className="mt-4 flex flex-wrap gap-2">
                            {permissions.can_retire && (
                                <button type="button" onClick={() => setPanel('retire')} className={SECONDARY_ACTION}>{tr.retire ?? 'Sett som utgått'}</button>
                            )}
                            {permissions.can_reopen && (
                                <button type="button" onClick={() => setPanel('reopen')} className={PRIMARY_ACTION}>{tr.reopen ?? 'Gjenåpne'}</button>
                            )}
                        </div>
                    )}
                </section>

                {panel === 'retire' && <RequirementRetireForm requirementId={item.id} onDone={() => setPanel(null)} tr={tr} />}
                {panel === 'reopen' && <RequirementReopenForm requirementId={item.id} onDone={() => setPanel(null)} tr={tr} />}

                <RequirementHistory entries={statusHistory} locale={locale} tr={tr} />
            </div>
        </CustomerAppLayout>
    );
}
