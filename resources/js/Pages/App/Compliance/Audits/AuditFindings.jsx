import { useEffect, useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { RequiredFieldsNote } from '../../../../Components/App/FormField';
import StatusBadge from '../../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import { BUSY_STYLE, focusableWithin, focusElement, refocusIfLost, submitWhenIdle } from '../../../../Support/focusManagement';
import { ERROR, HINT, INPUT, LABEL } from '../../../../Support/formStyles';
import { formatDay } from '../../Improvements/improvementStatus';
import RequiredMark from '../RequiredMark';
import FieldError from '../FieldError';
import { fieldStateProps, radioGroupProps, removalFocusCandidates } from '../complianceA11y';
import { focusInPageTarget, useFocusOnRejectedSubmit, usePanelSwitchFocus } from '../useComplianceFocus';
import { ownersForArea } from '../../Risk/riskOwners';
import { qualityItemLabel } from '../Requirements/complianceQuality';
import {
    FINDING_TYPE_TONES,
    findingFormData,
    findingTypeLabel,
    findingsNotice,
    improvementTypeLabel,
    requirementLabel,
    splitByScope,
} from './complianceAudit';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const TERM = 'text-base font-semibold text-slate-600';
// Ids of a finding card's heading and its buttons, for focus return and the buttons' description.
const headingId = (id) => `compliance-finding-heading-${id}`;
const editId = (id) => `compliance-finding-edit-${id}`;
const deleteId = (id) => `compliance-finding-delete-${id}`;
const handOffId = (id) => `compliance-finding-handoff-${id}`;

/** The finding's current choice stays selectable even when it is no longer offered (retired since). */
function withCurrent(options, current) {
    if (! current || options.some((option) => option.id === current.id)) {
        return options;
    }

    return [current, ...options];
}

/** One optional context select, with what is in the audit's scope grouped first when there is any. */
function ContextSelect({ id, label, value, onChange, options, current, error, labelOf, ta }) {
    const tf = ta.findings ?? {};
    const { inScope, other } = splitByScope(withCurrent(options, current));
    const optionText = (option) => (option.status === 'retired' ? `${labelOf(option)} (${tf.retired ?? 'Utgått'})` : labelOf(option));
    const render = (list) => list.map((option) => <option key={option.id} value={option.id}>{optionText(option)}</option>);

    return (
        <div>
            <label htmlFor={id} className={LABEL}>{label} <span className="font-normal text-slate-600">({tf.optional ?? 'valgfritt'})</span></label>
            <select id={id} {...fieldStateProps(id, error)} value={value} onChange={(event) => onChange(event.target.value)} className={`mt-1 ${INPUT}`}>
                <option value="">{tf.none ?? 'Ingen'}</option>
                {inScope.length > 0 ? (
                    <>
                        <optgroup label={tf.group_in_scope ?? 'I revisjonens scope'}>{render(inScope)}</optgroup>
                        {other.length > 0 && <optgroup label={tf.group_other ?? 'Andre'}>{render(other)}</optgroup>}
                    </>
                ) : render(other)}
            </select>
            <FieldError field={id} message={error} />
        </div>
    );
}

/**
 * Nytt funn and Rediger funn: type, title, description and the optional context. No severity,
 * frist, owner or tiltak — a finding is an observation; follow-up happens in Avvik og forbedringer.
 * The Kvalitet fields are drawn only when the server sent Kvalitet options.
 */
function FindingForm({ auditId, finding = null, draft = null, options, types, onDone, ta }) {
    const tf = ta.findings ?? {};
    const form = useForm(findingFormData(finding, draft));
    const idPrefix = finding ? `compliance-finding-${finding.id}` : 'compliance-finding-new';
    const hasQuality = Array.isArray(options?.processes) && 'quality_process_id' in form.data;
    const formRef = useRef(null);
    useFocusOnRejectedSubmit(form, formRef);

    const submit = (event) => {
        event.preventDefault();
        const done = { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } };

        if (finding) {
            form.patch(`/app/compliance/audits/${auditId}/findings/${finding.id}`, done);
        } else {
            form.post(`/app/compliance/audits/${auditId}/findings`, done);
        }
    };

    return (
        <form ref={formRef} onSubmit={submitWhenIdle(form, submit)} className="mt-4 space-y-4 rounded-2xl border border-slate-200 p-4" data-testid="compliance-finding-form" data-inline-panel={finding ? `edit-${finding.id}` : 'create'}>
            <h3 className="text-lg font-semibold text-slate-950">{finding ? (tf.edit_heading ?? 'Rediger funn') : (tf.create_heading ?? 'Nytt funn')}</h3>

            <fieldset {...radioGroupProps(`${idPrefix}-type`, form.errors.finding_type, { required: true })}>
                <legend className={LABEL}>{tf.field_type ?? 'Type'}<RequiredMark /></legend>
                <div className="mt-2 grid gap-2 sm:grid-cols-3">
                    {types.map((type) => (
                        <label key={type} className="flex min-h-10 items-start gap-3 rounded-xl border border-slate-200 px-3 py-2 text-base text-slate-800">
                            <input
                                type="radio"
                                name={`${idPrefix}-type`}
                                value={type}
                                checked={form.data.finding_type === type}
                                onChange={() => form.setData('finding_type', type)}
                                className="mt-1 h-5 w-5 shrink-0"
                            />
                            <span className="min-w-0 break-words font-semibold">
                                {findingTypeLabel(type, ta)}
                                <span className="block font-normal text-slate-600">{tf.type_hints?.[type] ?? ''}</span>
                            </span>
                        </label>
                    ))}
                </div>
                <FieldError field={`${idPrefix}-type`} message={form.errors.finding_type} />
            </fieldset>

            <div>
                <label htmlFor={`${idPrefix}-title`} className={LABEL}>{tf.field_title ?? 'Tittel'}<RequiredMark /></label>
                <input
                    id={`${idPrefix}-title`}
                    type="text"
                    required
                    aria-required="true"
                    maxLength={255}
                    {...fieldStateProps(`${idPrefix}-title`, form.errors.title)}
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                <FieldError field={`${idPrefix}-title`} message={form.errors.title} />
            </div>

            <div>
                <label htmlFor={`${idPrefix}-description`} className={LABEL}>{tf.field_description ?? 'Beskrivelse'}<RequiredMark /></label>
                <p id={`${idPrefix}-description-hint`} className={HINT}>{tf.field_description_hint ?? 'Hva revisjonen fant, og hva det bygger på.'}</p>
                <textarea
                    id={`${idPrefix}-description`}
                    rows={4}
                    required
                    aria-required="true"
                    {...fieldStateProps(`${idPrefix}-description`, form.errors.description, [`${idPrefix}-description-hint`])}
                    value={form.data.description}
                    onChange={(event) => form.setData('description', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                <FieldError field={`${idPrefix}-description`} message={form.errors.description} />
            </div>

            <div className="space-y-4">
                <p className="text-base text-slate-600">{tf.context_hint ?? 'Valgfritt. Kobler funnet til kravet, prosessen eller kontrollen det gjelder.'}</p>
                {(options?.checks ?? []).length > 0 && (
                    <ContextSelect
                        id={`${idPrefix}-check`}
                        label={tf.field_check ?? 'Kontrollhandling'}
                        value={form.data.check_id}
                        onChange={(value) => form.setData('check_id', value)}
                        options={options.checks}
                        current={finding?.check ?? null}
                        error={form.errors.check_id}
                        labelOf={(check) => check.title}
                        ta={ta}
                    />
                )}
                <ContextSelect
                    id={`${idPrefix}-requirement`}
                    label={tf.field_requirement ?? 'Krav'}
                    value={form.data.requirement_id}
                    onChange={(value) => form.setData('requirement_id', value)}
                    options={options?.requirements ?? []}
                    current={finding?.requirement ?? null}
                    error={form.errors.requirement_id}
                    labelOf={requirementLabel}
                    ta={ta}
                />
                {hasQuality && (
                    <>
                        <ContextSelect
                            id={`${idPrefix}-process`}
                            label={tf.field_process ?? 'Prosess'}
                            value={form.data.quality_process_id}
                            onChange={(value) => form.setData('quality_process_id', value)}
                            options={options.processes}
                            current={finding?.quality_process ?? null}
                            error={form.errors.quality_process_id}
                            labelOf={qualityItemLabel}
                            ta={ta}
                        />
                        <ContextSelect
                            id={`${idPrefix}-control`}
                            label={tf.field_control ?? 'Kontroll'}
                            value={form.data.control_item_id}
                            onChange={(value) => form.setData('control_item_id', value)}
                            options={options.controls ?? []}
                            current={finding?.control ?? null}
                            error={form.errors.control_item_id}
                            labelOf={qualityItemLabel}
                            ta={ta}
                        />
                    </>
                )}
            </div>

            {form.errors.finding && <p className={ERROR} data-form-error-summary="">{form.errors.finding}</p>}

            <RequiredFieldsNote text={ta.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />

            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={() => { form.reset(); form.clearErrors(); onDone(); }} className={SECONDARY_ACTION}>{tf.cancel ?? 'Avbryt'}</button>
                <button type="submit" aria-disabled={form.processing || undefined} className={`${PRIMARY_ACTION} ${BUSY_STYLE}`}>
                    {form.processing ? (tf.saving ?? 'Lagrer...') : (tf.save ?? 'Lagre funn')}
                </button>
            </div>
        </form>
    );
}

/**
 * Følg opp i Avvik og forbedringer: a new case from the finding. The type follows the finding and is
 * shown, not chosen; title and description start as the finding's; the person chooses the area —
 * never guessed — the owner and the frist. A finding's process is proposed as the case's context, in
 * plain sight, and can be left out. Without an area to register in, the form says so and offers
 * nothing to submit.
 */
function HandoffForm({ auditId, finding, handoff, onDone, ta }) {
    const tf = ta.findings ?? {};
    const idPrefix = `compliance-handoff-${finding.id}`;
    const areaOptions = handoff?.area_options ?? [];
    const process = handoff?.can_link_process ? (finding.quality_process ?? null) : null;
    const form = useForm({
        title: finding.title,
        description: finding.description,
        business_area_id: '',
        owner_user_id: '',
        due_date: '',
        link_process: process !== null,
    });
    const owners = ownersForArea(handoff?.owner_options ?? [], form.data.business_area_id);
    const formRef = useRef(null);
    useFocusOnRejectedSubmit(form, formRef);

    const chooseArea = (value) => {
        const stillAllowed = ownersForArea(handoff?.owner_options ?? [], value).some((owner) => String(owner.id) === String(form.data.owner_user_id));
        form.setData((data) => ({ ...data, business_area_id: value, owner_user_id: stillAllowed ? data.owner_user_id : '' }));
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/compliance/audits/${auditId}/findings/${finding.id}/handoff`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <section className="mt-4 space-y-4 rounded-2xl border border-violet-200 bg-violet-50/40 p-4" aria-labelledby={`${idPrefix}-heading`} data-testid="compliance-handoff-form" data-inline-panel={`handoff-${finding.id}`}>
            <div>
                <h4 id={`${idPrefix}-heading`} className="text-lg font-semibold text-slate-950">{tf.handoff_heading ?? 'Følg opp i Avvik og forbedringer'}</h4>
                <p className={HINT}>{tf.handoff_intro ?? 'Det opprettes en ny sak i Avvik og forbedringer. Funnet beholder sin egen tekst og låses når saken er opprettet.'}</p>
            </div>

            {areaOptions.length === 0 ? (
                <>
                    <p className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-base text-amber-900" data-testid="compliance-handoff-no-areas">
                        {tf.handoff_no_areas ?? 'Du har ikke tilgang til å registrere saker i Avvik og forbedringer. Be noen med tilgang om å følge opp funnet.'}
                    </p>
                    <div className="flex justify-end">
                        <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tf.cancel ?? 'Avbryt'}</button>
                    </div>
                </>
            ) : (
                <form ref={formRef} onSubmit={submitWhenIdle(form, submit)} className="space-y-4">
                    <div>
                        <p className={LABEL}>{tf.handoff_type ?? 'Sakstype'}</p>
                        <p className="mt-1 text-base text-slate-900" data-testid="compliance-handoff-type">{improvementTypeLabel(finding.improvement_type, ta)}</p>
                        <p className={HINT}>{tf.handoff_type_hint ?? 'Bestemmes av funnet: Avvik følges opp som avvik, observasjoner og forbedringsmuligheter som forbedring.'}</p>
                    </div>

                    <p className="text-base text-slate-600">{tf.handoff_prefill_hint ?? 'Hentet fra funnet. Endrer du teksten her, endres bare saken – ikke funnet.'}</p>

                    <div>
                        <label htmlFor={`${idPrefix}-title`} className={LABEL}>{tf.handoff_title ?? 'Tittel på saken'}<RequiredMark /></label>
                        <input id={`${idPrefix}-title`} type="text" required aria-required="true" maxLength={255} {...fieldStateProps(`${idPrefix}-title`, form.errors.title)} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} className={`mt-1 ${INPUT}`} />
                        <FieldError field={`${idPrefix}-title`} message={form.errors.title} />
                    </div>

                    <div>
                        <label htmlFor={`${idPrefix}-description`} className={LABEL}>{tf.handoff_description ?? 'Beskrivelse av saken'}<RequiredMark /></label>
                        <textarea id={`${idPrefix}-description`} rows={4} required aria-required="true" {...fieldStateProps(`${idPrefix}-description`, form.errors.description)} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} className={`mt-1 ${INPUT}`} />
                        <FieldError field={`${idPrefix}-description`} message={form.errors.description} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label htmlFor={`${idPrefix}-area`} className={LABEL}>{tf.handoff_area ?? 'Fagområde'}<RequiredMark /></label>
                            <p id={`${idPrefix}-area-hint`} className={HINT}>{tf.handoff_area_hint ?? 'Fagområdet bestemmer hvem som kan se saken.'}</p>
                            <select id={`${idPrefix}-area`} required aria-required="true" {...fieldStateProps(`${idPrefix}-area`, form.errors.business_area_id, [`${idPrefix}-area-hint`])} value={form.data.business_area_id} onChange={(event) => chooseArea(event.target.value)} className={`mt-1 ${INPUT}`}>
                                <option value="">{tf.choose_area ?? 'Velg fagområde'}</option>
                                {areaOptions.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}
                            </select>
                            <FieldError field={`${idPrefix}-area`} message={form.errors.business_area_id} />
                        </div>
                        <div>
                            <label htmlFor={`${idPrefix}-owner`} className={LABEL}>{tf.handoff_owner ?? 'Ansvarlig'}<RequiredMark /></label>
                            <p id={`${idPrefix}-owner-hint`} className={HINT}>{tf.handoff_owner_hint ?? 'Bare personer som kan se saker i valgt fagområde, kan velges.'}</p>
                            <select id={`${idPrefix}-owner`} required aria-required="true" {...fieldStateProps(`${idPrefix}-owner`, form.errors.owner_user_id, [`${idPrefix}-owner-hint`])} disabled={form.data.business_area_id === ''} value={form.data.owner_user_id} onChange={(event) => form.setData('owner_user_id', event.target.value)} className={`mt-1 ${INPUT}`}>
                                <option value="">{form.data.business_area_id === '' ? (tf.choose_area_first ?? 'Velg fagområde først') : (tf.choose_owner ?? 'Velg ansvarlig')}</option>
                                {owners.map((owner) => <option key={owner.id} value={owner.id}>{owner.name}</option>)}
                            </select>
                            <FieldError field={`${idPrefix}-owner`} message={form.errors.owner_user_id} />
                        </div>
                    </div>

                    <div className="sm:max-w-xs">
                        <label htmlFor={`${idPrefix}-due`} className={LABEL}>{tf.handoff_due ?? 'Frist'}</label>
                        <p id={`${idPrefix}-due-hint`} className={HINT}>{tf.handoff_due_hint ?? 'Valgfritt. Når saken bør være behandlet.'}</p>
                        <input id={`${idPrefix}-due`} type="date" {...fieldStateProps(`${idPrefix}-due`, form.errors.due_date, [`${idPrefix}-due-hint`])} value={form.data.due_date} onChange={(event) => form.setData('due_date', event.target.value)} className={`mt-1 ${INPUT}`} />
                        <FieldError field={`${idPrefix}-due`} message={form.errors.due_date} />
                    </div>

                    {process !== null && (
                        <label className="flex items-start gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-800" data-testid="compliance-handoff-process">
                            <input type="checkbox" checked={form.data.link_process} onChange={(event) => form.setData('link_process', event.target.checked)} className="mt-1 h-5 w-5 shrink-0 rounded border-slate-300" />
                            <span className="min-w-0 break-words font-semibold">
                                {(tf.handoff_process ?? 'Koble saken til prosessen «:process»').replace(':process', qualityItemLabel(process))}
                                <span className="block font-normal text-slate-600">{tf.handoff_process_hint ?? 'Foreslått fordi funnet gjelder denne prosessen.'}</span>
                            </span>
                        </label>
                    )}

                    {form.errors.finding && <p className={ERROR} data-form-error-summary="">{form.errors.finding}</p>}

                    <RequiredFieldsNote text={ta.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />

                    <div className="flex flex-wrap justify-end gap-3">
                        <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tf.cancel ?? 'Avbryt'}</button>
                        <button type="submit" aria-disabled={form.processing || undefined} className={`${PRIMARY_ACTION} ${BUSY_STYLE}`}>
                            {form.processing ? (tf.saving ?? 'Lagrer...') : (tf.handoff_submit ?? 'Opprett sak')}
                        </button>
                    </div>
                </form>
            )}
        </section>
    );
}

/**
 * One finding as a card: type, title, description, the optional context, and whether it was handed
 * off. A handed-off finding links to its case only when the server sent the link — the person can
 * read that case — and never shows the case's status. Rediger, Slett and the hand-off wait for the
 * server's permission on the finding.
 */
function FindingCard({ auditId, finding, findingIds, options, types, handoff, panel, setPanel, ta }) {
    const tf = ta.findings ?? {};
    const perms = finding.permissions ?? {};
    const editing = panel === `edit-${finding.id}`;
    const handingOff = panel === `handoff-${finding.id}`;

    const destroy = () => {
        if (! window.confirm(tf.delete_confirm ?? 'Slette funnet? Dette kan ikke angres.')) {
            return;
        }

        // Focus goes to the next finding, the previous one, or Nytt funn (A11Y-014).
        const candidates = removalFocusCandidates(findingIds, finding.id, [editId, deleteId, handOffId], ['compliance-findings-create', 'compliance-audit-findings-heading']);
        router.delete(`/app/compliance/audits/${auditId}/findings/${finding.id}`, { preserveScroll: true, onSuccess: () => refocusIfLost(candidates) });
    };

    if (editing) {
        return (
            <li id={`finding-${finding.id}`} className="py-4" data-testid="compliance-finding">
                <FindingForm auditId={auditId} finding={finding} options={options} types={types} onDone={() => setPanel(null)} ta={ta} />
            </li>
        );
    }

    return (
        <li id={`finding-${finding.id}`} className="space-y-3 py-4" data-testid="compliance-finding">
            <div className="flex flex-wrap items-center gap-2">
                <StatusBadge tone={FINDING_TYPE_TONES[finding.finding_type] ?? 'slate'}>{findingTypeLabel(finding.finding_type, ta)}</StatusBadge>
                {finding.handed_off && <span data-testid="compliance-finding-handed-off"><StatusBadge tone="emerald">{tf.handed_off ?? 'Overført'}</StatusBadge></span>}
            </div>
            <h3 id={headingId(finding.id)} className="break-words text-lg font-semibold text-slate-950">{finding.title}</h3>
            <p className="whitespace-pre-line break-words text-base leading-7 text-slate-800">{finding.description}</p>

            {(finding.check || finding.requirement || finding.quality_process || finding.control) && (
                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="compliance-finding-context">
                    {finding.check && (
                        <div className="min-w-0">
                            <dt className={TERM}>{tf.check_label ?? 'Fra kontrollhandling'}</dt>
                            <dd className="mt-1 break-words text-base" data-testid="compliance-finding-check">
                                <a href={`#check-${finding.check.id}`} onClick={() => focusInPageTarget(`#check-${finding.check.id}`)} className="font-semibold text-violet-700 hover:text-violet-900">{finding.check.title}</a>
                            </dd>
                        </div>
                    )}
                    {finding.requirement && (
                        <div className="min-w-0">
                            <dt className={TERM}>{tf.field_requirement ?? 'Krav'}</dt>
                            <dd className="mt-1 break-words text-base">
                                <Link href={finding.requirement.url} className="font-semibold text-violet-700 hover:text-violet-900">{requirementLabel(finding.requirement)}</Link>
                            </dd>
                        </div>
                    )}
                    {finding.quality_process && (
                        <div className="min-w-0">
                            <dt className={TERM}>{tf.field_process ?? 'Prosess'}</dt>
                            <dd className="mt-1 break-words text-base">
                                <Link href={finding.quality_process.url} className="font-semibold text-violet-700 hover:text-violet-900">{qualityItemLabel(finding.quality_process)}</Link>
                            </dd>
                        </div>
                    )}
                    {finding.control && (
                        <div className="min-w-0">
                            <dt className={TERM}>{tf.field_control ?? 'Kontroll'}</dt>
                            <dd className="mt-1 break-words text-base">
                                <Link href={finding.control.url} className="font-semibold text-violet-700 hover:text-violet-900">{qualityItemLabel(finding.control)}</Link>
                            </dd>
                        </div>
                    )}
                </dl>
            )}

            {finding.handed_off && (
                <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-base text-emerald-900" data-testid="compliance-finding-case">
                    <p className="font-semibold">{tf.handed_off_to ?? 'Overført til Avvik og forbedringer'}</p>
                    {finding.handed_off_at && (
                        <p>{(tf.handed_off_meta ?? 'Overført :date av :name').replace(':date', formatDay(finding.handed_off_at.slice(0, 10))).replace(':name', finding.handed_off_by_name ?? ta.unknown_user ?? 'en tidligere bruker')}</p>
                    )}
                    {finding.case_link && (
                        <Link href={finding.case_link.url} id={`compliance-finding-case-link-${finding.id}`} className="mt-1 inline-block break-words font-semibold text-violet-700 hover:text-violet-900" data-testid="compliance-finding-case-link">
                            {tf.open_case ?? 'Åpne saken'}: {finding.case_link.title}
                        </Link>
                    )}
                </div>
            )}

            {panel === null && (perms.can_edit || perms.can_delete || perms.can_hand_off) && (
                <div className="flex flex-wrap gap-2" data-testid="compliance-finding-actions">
                    {perms.can_hand_off && (
                        <button id={handOffId(finding.id)} type="button" onClick={() => setPanel(`handoff-${finding.id}`)} aria-describedby={headingId(finding.id)} className={PRIMARY_ACTION}>{tf.hand_off ?? 'Følg opp i Avvik og forbedringer'}</button>
                    )}
                    {perms.can_edit && (
                        <button id={editId(finding.id)} type="button" onClick={() => setPanel(`edit-${finding.id}`)} aria-describedby={headingId(finding.id)} className={SECONDARY_ACTION}>{tf.edit ?? 'Rediger'}</button>
                    )}
                    {perms.can_delete && (
                        <button id={deleteId(finding.id)} type="button" onClick={destroy} aria-describedby={headingId(finding.id)} className={DESTRUCTIVE_ACTION}>{tf.delete ?? 'Slett'}</button>
                    )}
                </div>
            )}

            {handingOff && <HandoffForm auditId={auditId} finding={finding} handoff={handoff} onDone={() => setPanel(null)} ta={ta} />}
        </li>
    );
}

/**
 * Funn: what the audit found, in the order it was recorded. Nytt funn while the audit is in progress
 * and the server allows it; afterwards the findings are read-only, and those not yet handed off can
 * still be followed up in Avvik og forbedringer. One form or hand-off open at a time.
 *
 * `draft` is «Registrer funn» on a kontrollhandling: it opens Nytt funn with that check chosen. Each
 * click is a new draft (its key), so the form starts over.
 */
export default function AuditFindings({ audit, findings = [], options = null, types = [], canRecord = false, handoff = null, draft = null, ta }) {
    const tf = ta.findings ?? {};
    // 'create', 'edit-<id>', 'handoff-<id>' or none.
    const [panel, setPanel] = useState(null);
    const notice = findingsNotice(audit.status, ta);
    const sectionRef = useRef(null);
    const findingIds = findings.map((finding) => finding.id);
    // Whether the open Nytt funn came from «Registrer funn» on a check (and so returns there).
    const fromDraft = useRef(false);

    // A form opens in place of its button: focus goes into it, and back when it closes (A11Y-014).
    // Nytt funn from «Registrer funn» returns to that button; a hand-off that went through, whose
    // button is gone, to the new case link or the finding's heading.
    usePanelSwitchFocus(panel, {
        containerRef: sectionRef,
        returnTargets: (key) => {
            if (key === 'create') {
                return [fromDraft.current && draft ? `compliance-check-new-finding-${draft.check_id}` : null, 'compliance-findings-create', 'compliance-audit-findings-heading'].filter(Boolean);
            }

            const id = key.replace(/^(edit|handoff)-/, '');

            return key.startsWith('handoff-')
                ? [handOffId(id), `compliance-finding-case-link-${id}`, headingId(id)]
                : [editId(id), headingId(id)];
        },
    });

    // «Registrer funn» while Nytt funn is already open starts the form over for the other check. The
    // panel does not change, so usePanelSwitchFocus does not move focus — it stayed on the button in
    // Kontrollhandlinger while the view scrolled to the form (2.4.3, A11Y-195). Focus goes into the
    // new form instead, once it has rendered.
    const [refocusDraft, setRefocusDraft] = useState(0);

    useEffect(() => {
        if (draft && canRecord) {
            fromDraft.current = true;
            if (panel === 'create') {
                setRefocusDraft((count) => count + 1);
            }
            setPanel('create');
            document.getElementById('compliance-audit-findings-heading')?.scrollIntoView({ block: 'start' });
        }
    }, [draft?.key]);

    useEffect(() => {
        if (refocusDraft === 0) {
            return;
        }

        const form = sectionRef.current?.querySelector?.('[data-inline-panel="create"]');

        if (form) {
            focusElement(focusableWithin(form)[0] ?? form, { preventScroll: true });
        }
    }, [refocusDraft]);

    return (
        <section ref={sectionRef} className={CARD} aria-labelledby="compliance-audit-findings-heading" data-testid="compliance-audit-findings">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 id="compliance-audit-findings-heading" className="text-xl font-semibold text-slate-950">{tf.heading ?? 'Funn'}</h2>
                    <p className="mt-1 text-base text-slate-600">{tf.intro ?? 'Det revisjonen fant. Et funn er ikke et tiltak – oppfølgingen skjer i Avvik og forbedringer.'}</p>
                </div>
                {canRecord && panel === null && (
                    <button id="compliance-findings-create" type="button" onClick={() => { fromDraft.current = false; setPanel('create'); }} className={SECONDARY_ACTION}>{tf.create ?? 'Nytt funn'}</button>
                )}
            </div>

            {notice && <p className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-base text-slate-800" data-testid="compliance-findings-locked">{notice}</p>}

            {canRecord && panel === 'create' && (
                <FindingForm key={draft?.key ?? 'new'} auditId={audit.id} draft={draft} options={options} types={types} onDone={() => setPanel(null)} ta={ta} />
            )}

            {findings.length === 0 ? (
                <p className="mt-4 text-base text-slate-700">
                    {audit.status === 'planned' ? (tf.empty_planned ?? 'Funn registreres når revisjonen er startet.') : (tf.empty ?? 'Ingen funn er registrert.')}
                </p>
            ) : (
                <ul className="mt-2 divide-y divide-slate-100">
                    {findings.map((finding) => (
                        <FindingCard
                            key={finding.id}
                            auditId={audit.id}
                            finding={finding}
                            findingIds={findingIds}
                            options={options}
                            types={types}
                            handoff={handoff}
                            panel={panel}
                            setPanel={setPanel}
                            ta={ta}
                        />
                    ))}
                </ul>
            )}
        </section>
    );
}
