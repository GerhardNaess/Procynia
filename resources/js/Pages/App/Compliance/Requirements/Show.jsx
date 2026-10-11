import { useEffect, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../../Components/App/PageHelpButton';
import StatusBadge from '../../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import { requirementBackTarget, worklistUrl } from '../../../../Support/complianceNavigation';
import RequirementGuidance from '../Library/RequirementGuidance';
import { AttentionReasons } from './ComplianceAttention';
import RequirementApplicability from './RequirementApplicability';
import RequirementCompliance from './RequirementCompliance';
import RequirementForm from './RequirementForm';
import RequirementHistory from './RequirementHistory';
import RequirementQualityContext from './RequirementQualityContext';
import { RequirementReopenForm, RequirementRetireForm } from './RequirementStatusForms';
import { complianceHelp } from './complianceHelp';
import { REQUIREMENT_STATUS_TONES, reviewIntervalLabel } from './complianceRequirement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const TERM = 'text-base font-semibold text-slate-600';
const VALUE = 'mt-1 break-words text-base text-slate-900';

/**
 * One requirement, read top to bottom as the assessment is done: where it comes from
 * («ISO 14001:2026 · 6.1.2 · Miljøaspekter»), what it says and the library's guidance, its relevance
 * or Kontrollvalg, then Etterlevelse — the current result, «Vurder etterlevelse» and the history.
 * Optional references to Kvalitet come after that: a process description is not in itself evidence
 * that a requirement is met, so linking one is never asked for.
 *
 * The page is shared by Kravregister and Etterlevelsesvurdering. Opened from the worklist
 * (`from=assessments`), the header keeps Etterlevelsesvurdering and «Tilbake» returns to the
 * worklist as it was left; `assess=1` opens the assessment form straight away.
 *
 * Every action is offered only when the server said this person may do it; the controller refuses
 * it otherwise. An active requirement can be edited, assessed and retired; a retired one only
 * reopened. Status never moves through Rediger.
 */
export default function ComplianceRequirementShow() {
    const page = usePage();
    const {
        translations = {},
        requirement: item,
        status_history: statusHistory = [],
        compliance = null,
        attention = [],
        assessments = [],
        assessment_results: assessmentResults = [],
        quality_context: qualityContext = null,
        quality_options: qualityOptions = null,
        locale = 'no',
        permissions = {},
        source_options: sourceOptions = [],
        owner_options: ownerOptions = [],
        review_intervals: reviewIntervals = [],
        library = null,
        applicability = null,
    } = page.props;

    const tr = translations?.compliance ?? {};
    const statusLabels = tr.statuses ?? {};
    const query = new URLSearchParams(String(page.url ?? '').split('?')[1] ?? '');
    const back = requirementBackTarget(query.get('from'));
    const [backHref, setBackHref] = useState(back.href ?? '/app/compliance/assessments');
    // Which panel is open: 'edit', 'assess', 'retire', 'reopen' or none. One at a time. «Vurder» in
    // the worklist arrives with assess=1 and the form open.
    const [panel, setPanel] = useState(() => (query.get('assess') === '1' && permissions.can_assess ? 'assess' : null));

    useEffect(() => {
        if (back.href === null) {
            setBackHref(worklistUrl());
        }
    }, [back.href]);

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
                <Link href={backHref} className="inline-block text-base font-semibold text-violet-700 hover:text-violet-900" data-testid="compliance-back">
                    ← {back.area === 'compliance-assessments' ? (tr.back_to_assessments ?? 'Tilbake til etterlevelsesvurdering') : (tr.back ?? 'Tilbake til kravregisteret')}
                </Link>

                <header className="space-y-4">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="min-w-0 space-y-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge tone={REQUIREMENT_STATUS_TONES[item.status] ?? 'slate'}>{statusLabels[item.status] ?? item.status}</StatusBadge>
                                {item.origin?.source && <span className="break-words text-base font-semibold text-slate-700" data-testid="compliance-origin-source">{item.origin.source}</span>}
                                {item.reference && <span className="break-words text-base font-semibold text-slate-700" data-testid="compliance-reference">{item.reference}</span>}
                            </div>
                            <h1 className="break-words text-3xl font-semibold tracking-tight text-slate-950">{item.title}</h1>
                            <AttentionReasons reasons={attention} tr={tr} withLabel testId="compliance-show-attention" />
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
                            fromLibrary={library !== null}
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
                    <h2 id="compliance-details-heading" className="text-xl font-semibold text-slate-950">{tr.details_heading ?? 'Opphav og oppfølging'}</h2>
                    <dl className="mt-4 grid gap-4 sm:grid-cols-3" data-testid="compliance-facts">
                        <div>
                            <dt className={TERM}>{tr.field_origin ?? 'Opphav'}</dt>
                            <dd className={VALUE} data-testid="compliance-facts-origin">{item.origin?.source ?? item.source_label}{item.reference ? ` · ${item.reference}` : ''}</dd>
                        </div>
                        <div>
                            <dt className={TERM}>{tr.field_owner_optional ?? 'Ansvarlig (valgfritt)'}</dt>
                            <dd className={VALUE}>{item.owner_name ?? <span className="text-slate-600">{tr.no_owner ?? 'Ingen valgt'}</span>}</dd>
                        </div>
                        <div>
                            <dt className={TERM}>{tr.field_review ?? 'Revurderingsintervall'}</dt>
                            <dd className={VALUE}>{reviewIntervalLabel(item.review_interval_months, tr)}</dd>
                        </div>
                    </dl>
                </section>

                {library && (
                    <section className={CARD} aria-labelledby="compliance-library-context-heading" data-testid="compliance-library-context">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <h2 id="compliance-library-context-heading" className="text-xl font-semibold text-slate-950">{tr.library?.from_library_heading ?? 'Fra kravbiblioteket'}</h2>
                            {! library.content_verified && <StatusBadge tone="amber">{tr.library?.unverified_badge ?? 'Ikke faglig kvalitetssikret'}</StatusBadge>}
                        </div>
                        <p className="mt-2 text-base text-slate-700">
                            {(tr.library?.from_library_note ?? 'Kravet kommer fra :name.').replace(':name', library.framework_label)}
                            {' '}
                            <Link href={library.framework_url} className="font-semibold text-violet-700 hover:text-violet-900">{tr.library?.nav ?? 'Kravbibliotek'} →</Link>
                        </p>
                        {library.withdrawn && <p className="mt-2 text-base text-amber-800">{tr.library?.withdrawn_hint ?? ''}</p>}
                        <div className="mt-4">
                            <RequirementGuidance details={library} tr={tr} idPrefix="compliance-requirement-library" />
                        </div>
                    </section>
                )}

                {applicability && (
                    <RequirementApplicability
                        requirementId={item.id}
                        applicability={applicability}
                        rule={library?.applicability_rule}
                        canChange={Boolean(permissions.can_set_applicability)}
                        locale={locale}
                        tr={tr}
                    />
                )}

                <RequirementCompliance
                    item={item}
                    compliance={compliance}
                    assessments={assessments}
                    results={assessmentResults}
                    canAssess={permissions.can_assess && panel === null}
                    assessing={panel === 'assess'}
                    onAssess={() => setPanel('assess')}
                    onDone={() => setPanel(null)}
                    locale={locale}
                    tr={tr}
                    control={library?.applicability_rule === 'control'}
                />

                {qualityContext && (
                    <RequirementQualityContext
                        item={item}
                        context={qualityContext}
                        options={qualityOptions}
                        canManage={Boolean(permissions.can_manage_quality_links)}
                        tr={tr}
                        qualityTr={translations?.quality ?? {}}
                    />
                )}

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
