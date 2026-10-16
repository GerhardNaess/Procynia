import { useEffect, useRef, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import FormField, { RequiredFieldsNote } from '../../../Components/App/FormField';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION } from '../../../Support/actionStyles';
import { focusElement, focusableWithin, refocusIfLost, useFocusFirstError, usePanelFocus } from '../../../Support/focusManagement';
import { ERROR, INPUT as FIELD, LABEL, TEXTAREA } from '../../../Support/formStyles';
import { removalFocusCandidates } from './reviewA11y';
import { fill, formatDay, ownersForArea, sectionTitle } from './reviewSections';

// The review pages' fields are a touch taller (min-h-11) than the shared ones.
const INPUT = FIELD.replace('min-h-10', 'min-h-11');
const PANEL = 'mt-3 space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-4';

const ACTION_STATUS_TONES = { open: 'blue', completed: 'emerald', cancelled: 'slate' };

/** «Felt merket med * må fylles ut.», once per form that has a «*» (A11Y-121). */
function RequiredNote() {
    const ta = usePage().props.translations?.a11y ?? {};

    return <RequiredFieldsNote text={ta.required_fields ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />;
}

/**
 * Registrer beslutning eller tiltak — and Rediger. A tiltak takes a responsible person and a due date
 * and goes straight into their Mine oppgaver; a decision takes neither.
 *
 * Type is one named group with its hint tied to it (A11Y-120); fields carry their errors and a refused
 * save puts focus on the first one that is wrong (A11Y-013).
 */
export function DecisionForm({ reviewId, decision = null, sectionKey = null, sections = [], ownerOptions = [], t, onDone }) {
    const td = t.decisions ?? {};
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);
    const idSuffix = decision ? `-${decision.id}` : '';
    const form = useForm({
        kind: decision?.kind ?? 'action',
        text: decision?.text ?? '',
        section_key: decision?.section_key ?? sectionKey ?? '',
        owner_user_id: decision?.owner_user_id ? String(decision.owner_user_id) : '',
        due_date: decision?.due_date ?? '',
    });
    const action = form.data.kind === 'action';
    const kindHintId = `mr-decision-kind-hint${idSuffix}`;
    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onDone?.(); }, onError: focusFirstError };
        form.transform((data) => ({ ...data, section_key: data.section_key || null }));

        if (decision) {
            form.patch(`/app/management-reviews/${reviewId}/decisions/${decision.id}`, options);
        } else {
            form.post(`/app/management-reviews/${reviewId}/decisions`, options);
        }
    };

    return (
        <form ref={formRef} onSubmit={submit} className={PANEL} data-testid="mr-decision-form">
            <fieldset aria-describedby={kindHintId}>
                <legend className={LABEL}>{td.kind ?? 'Type'}</legend>
                <div className="mt-2 flex flex-wrap gap-5">
                    {['action', 'decision'].map((kind) => (
                        <label key={kind} className="flex min-h-11 items-center gap-2 text-base text-slate-800">
                            <input type="radio" name={`mr-decision-kind${idSuffix}`} value={kind} checked={form.data.kind === kind} onChange={() => form.setData('kind', kind)} className="h-5 w-5" data-testid={`mr-decision-kind-${kind}`} />
                            {t.values?.[`kind_${kind}`] ?? kind}
                        </label>
                    ))}
                </div>
                <p id={kindHintId} className="mt-1 text-base text-slate-600">{action ? td.kind_action_hint : td.kind_decision_hint}</p>
            </fieldset>

            <FormField id={`mr-decision-text${idSuffix}`} label={action ? (td.text_action ?? 'Tiltak') : (td.text ?? 'Beslutning')} error={form.errors.text} required>
                {(field) => (
                    <textarea {...field} rows={2} required maxLength={2000} value={form.data.text} onChange={(event) => form.setData('text', event.target.value)} className={TEXTAREA} />
                )}
            </FormField>

            {action && (
                <div className="grid gap-4 md:grid-cols-2">
                    <FormField id={`mr-decision-owner${idSuffix}`} label={td.owner ?? 'Ansvarlig'} error={form.errors.owner_user_id} required>
                        {(field) => (
                            <select {...field} required value={form.data.owner_user_id} onChange={(event) => form.setData('owner_user_id', event.target.value)} className={INPUT}>
                                <option value="">{td.choose_owner ?? 'Velg ansvarlig'}</option>
                                {ownerOptions.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                            </select>
                        )}
                    </FormField>
                    <FormField id={`mr-decision-due${idSuffix}`} label={td.due_date ?? 'Frist'} error={form.errors.due_date} required>
                        {(field) => (
                            <input {...field} type="date" required value={form.data.due_date} onChange={(event) => form.setData('due_date', event.target.value)} className={INPUT} />
                        )}
                    </FormField>
                </div>
            )}

            <FormField id={`mr-decision-section${idSuffix}`} label={td.section ?? 'Seksjon'} error={form.errors.section_key}>
                {(field) => (
                    <select {...field} value={form.data.section_key} onChange={(event) => form.setData('section_key', event.target.value)} className={INPUT}>
                        <option value="">{td.no_section ?? 'Ingen bestemt seksjon'}</option>
                        {sections.map((section) => <option key={section.key} value={section.key}>{sectionTitle(section.key, t)}</option>)}
                    </select>
                )}
            </FormField>

            <RequiredNote />

            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>{td.save ?? 'Lagre'}</button>
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{t.cancel ?? 'Avbryt'}</button>
            </div>
        </form>
    );
}

function StatusForm({ reviewId, decision, status, t, onDone }) {
    const td = t.decisions ?? {};
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);
    const form = useForm({ status, note: '' });
    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/management-reviews/${reviewId}/decisions/${decision.id}/status`, { preserveScroll: true, onSuccess: onDone, onError: focusFirstError });
    };

    return (
        <form ref={formRef} onSubmit={submit} className={PANEL}>
            {status !== 'open' && (
                <FormField id={`mr-status-note-${decision.id}`} label={status === 'completed' ? td.note : td.cancel_note} error={form.errors.note ?? form.errors.status}>
                    {(field) => (
                        <textarea {...field} rows={2} maxLength={5000} value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} className={TEXTAREA} />
                    )}
                </FormField>
            )}
            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing} className={status === 'cancelled' ? WARNING_ACTION : PRIMARY_ACTION} aria-describedby={`decision-${decision.id}-text`} data-testid={`mr-status-confirm-${status}`}>
                    {status === 'completed' ? td.complete : status === 'cancelled' ? td.cancel_action : td.reopen}
                </button>
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{t.cancel ?? 'Avbryt'}</button>
            </div>
        </form>
    );
}

function ReassignForm({ reviewId, decision, ownerOptions, t, onDone }) {
    const td = t.decisions ?? {};
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);
    const form = useForm({ owner_user_id: decision.owner_user_id ? String(decision.owner_user_id) : '', due_date: decision.due_date ?? '' });
    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/management-reviews/${reviewId}/decisions/${decision.id}/reassign`, { preserveScroll: true, onSuccess: onDone, onError: focusFirstError });
    };

    return (
        <form ref={formRef} onSubmit={submit} className={PANEL}>
            <div className="grid gap-4 md:grid-cols-2">
                <FormField id={`mr-reassign-owner-${decision.id}`} label={td.owner ?? 'Ansvarlig'} error={form.errors.owner_user_id} required>
                    {(field) => (
                        <select {...field} required value={form.data.owner_user_id} onChange={(event) => form.setData('owner_user_id', event.target.value)} className={INPUT}>
                            <option value="">{td.choose_owner ?? 'Velg ansvarlig'}</option>
                            {ownerOptions.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                        </select>
                    )}
                </FormField>
                <FormField id={`mr-reassign-due-${decision.id}`} label={td.due_date ?? 'Frist'} error={form.errors.due_date} required>
                    {(field) => (
                        <input {...field} type="date" required value={form.data.due_date} onChange={(event) => form.setData('due_date', event.target.value)} className={INPUT} />
                    )}
                </FormField>
            </div>
            <RequiredNote />
            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>{td.save ?? 'Lagre'}</button>
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{t.cancel ?? 'Avbryt'}</button>
            </div>
        </form>
    );
}

/**
 * Følg opp i Avvik og forbedringer: a new case, or (when there are cases to choose from) a link to one.
 * The two ways are one named group (A11Y-120), the hint describes the form's first choice.
 */
function HandOffForm({ reviewId, reviewTitle, decision, options, t, onDone }) {
    const td = t.decisions ?? {};
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);
    const [mode, setMode] = useState('new');
    const form = useForm({
        title: decision.text.slice(0, 255),
        description: fill(td.case_description_default ?? 'Tiltak besluttet i :review.', { review: reviewTitle }),
        business_area_id: options.area_options.length === 1 ? String(options.area_options[0].id) : '',
        owner_user_id: '',
        due_date: decision.due_date ?? '',
    });
    const link = useForm({ improvement_case_id: '' });
    const owners = ownersForArea(options.owner_options, form.data.business_area_id);
    const hintId = `mr-handoff-hint-${decision.id}`;
    const submit = (event) => {
        event.preventDefault();

        if (mode === 'new') {
            form.post(`/app/management-reviews/${reviewId}/decisions/${decision.id}/handoff`, { preserveScroll: true, onSuccess: onDone, onError: focusFirstError });
        } else {
            link.post(`/app/management-reviews/${reviewId}/decisions/${decision.id}/link`, { preserveScroll: true, onSuccess: onDone, onError: focusFirstError });
        }
    };

    return (
        <form ref={formRef} onSubmit={submit} className={PANEL} data-testid="mr-handoff-form">
            <p id={hintId} className="text-base text-slate-700">{td.hand_off_hint}</p>
            {options.case_options.length > 0 && (
                <fieldset aria-describedby={hintId}>
                    <legend className="sr-only">{td.hand_off ?? 'Følg opp i Avvik og forbedringer'}</legend>
                    <div className="flex flex-wrap gap-5">
                        <label className="flex min-h-11 items-center gap-2 text-base text-slate-800">
                            <input type="radio" name={`mr-handoff-mode-${decision.id}`} checked={mode === 'new'} onChange={() => setMode('new')} className="h-5 w-5" />
                            {td.hand_off_heading}
                        </label>
                        <label className="flex min-h-11 items-center gap-2 text-base text-slate-800">
                            <input type="radio" name={`mr-handoff-mode-${decision.id}`} checked={mode === 'link'} onChange={() => setMode('link')} className="h-5 w-5" />
                            {td.link}
                        </label>
                    </div>
                </fieldset>
            )}

            {mode === 'new' ? (
                <>
                    <FormField id={`mr-case-title-${decision.id}`} label={td.case_title} error={form.errors.title} required>
                        {(field) => (
                            <input {...field} required maxLength={255} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} className={INPUT} />
                        )}
                    </FormField>
                    <FormField id={`mr-case-description-${decision.id}`} label={td.case_description} error={form.errors.description} required>
                        {(field) => (
                            <textarea {...field} required rows={2} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} className={TEXTAREA} />
                        )}
                    </FormField>
                    <div className="grid gap-4 md:grid-cols-3">
                        <FormField id={`mr-case-area-${decision.id}`} label={td.case_area} error={form.errors.business_area_id} required>
                            {(field) => (
                                <select {...field} required value={form.data.business_area_id} onChange={(event) => form.setData('business_area_id', event.target.value)} className={INPUT}>
                                    <option value="">{td.choose_area}</option>
                                    {options.area_options.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}
                                </select>
                            )}
                        </FormField>
                        <FormField id={`mr-case-owner-${decision.id}`} label={td.case_owner} error={form.errors.owner_user_id} required>
                            {(field) => (
                                <select {...field} required value={form.data.owner_user_id} onChange={(event) => form.setData('owner_user_id', event.target.value)} className={INPUT}>
                                    <option value="">{td.choose_owner}</option>
                                    {owners.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                                </select>
                            )}
                        </FormField>
                        <FormField id={`mr-case-due-${decision.id}`} label={td.due_date} error={form.errors.due_date}>
                            {(field) => (
                                <input {...field} type="date" value={form.data.due_date} onChange={(event) => form.setData('due_date', event.target.value)} className={INPUT} />
                            )}
                        </FormField>
                    </div>
                </>
            ) : (
                <FormField id={`mr-link-case-${decision.id}`} label={td.link_choose} error={link.errors.improvement_case_id} required>
                    {(field) => (
                        <select {...field} required value={link.data.improvement_case_id} onChange={(event) => link.setData('improvement_case_id', event.target.value)} className={INPUT}>
                            <option value="">{td.link_choose}</option>
                            {options.case_options.map((item) => <option key={item.id} value={item.id}>{item.title}</option>)}
                        </select>
                    )}
                </FormField>
            )}

            <RequiredNote />

            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing || link.processing} className={PRIMARY_ACTION} data-testid="mr-handoff-submit">{td.save ?? 'Lagre'}</button>
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{t.cancel ?? 'Avbryt'}</button>
            </div>
        </form>
    );
}

/**
 * One decision or tiltak: what was decided, by whom it is followed up, and — for a finalized review —
 * how it stood at finalization beside how it stands now. Only the actions the server allows.
 */
export function DecisionCard({ review, decision, sections, ownerOptions, handoffOptions, finalized, t, focusAfterRemoval = () => [] }) {
    const td = t.decisions ?? {};
    const [panel, setPanel] = useState(null);
    const action = decision.kind === 'action';
    const perms = decision.permissions ?? {};
    const textId = `decision-${decision.id}-text`;
    const buttonId = (name) => `decision-${decision.id}-${name}`;

    // A panel opened from the card takes focus (its first control) and gives it back to the button
    // that opened it; when that button is gone — a completed tiltak has no «Fullfør» — to the
    // decision's own text (2.4.3, A11Y-014).
    const panelRef = useRef(null);
    const triggerRef = useRef(null);
    const [panelFocusRequest, setPanelFocusRequest] = useState(0);

    useEffect(() => {
        if (panelFocusRequest > 0) {
            focusElement(focusableWithin(panelRef.current)[0] ?? panelRef.current);
        }
    }, [panelFocusRequest]);

    const open = (name) => {
        triggerRef.current = buttonId(name);
        setPanel(name);
        setPanelFocusRequest((count) => count + 1);
    };

    const close = () => {
        setPanel(null);
        refocusIfLost([triggerRef.current, textId]);
    };

    // window.confirm stays; once the decision is gone, focus goes to the next one, the one before, or
    // the list's own action or heading — never to <body>.
    const destroy = () => {
        if (window.confirm(td.delete_confirm ?? 'Slett beslutningen?')) {
            router.delete(`/app/management-reviews/${review.id}/decisions/${decision.id}`, {
                preserveScroll: true,
                onSuccess: () => refocusIfLost(focusAfterRemoval(decision.id)),
            });
        }
    };

    return (
        <li id={`decision-${decision.id}`} className="space-y-2 py-4" data-testid="mr-decision">
            <div className="flex flex-wrap items-center gap-2">
                <StatusBadge tone={action ? 'violet' : 'slate'}>{t.values?.[`kind_${decision.kind}`] ?? decision.kind}</StatusBadge>
                {action && decision.follow_up === 'own' && (
                    <StatusBadge tone={ACTION_STATUS_TONES[decision.status] ?? 'slate'}>{t.values?.[`action_${decision.status}`] ?? decision.status}</StatusBadge>
                )}
                {decision.overdue && <span className="text-base font-semibold text-rose-700">{td.overdue ?? 'Frist passert'}</span>}
                {decision.section_key && <span className="text-base text-slate-600">{sectionTitle(decision.section_key, t)}</span>}
            </div>
            <p id={textId} className="whitespace-pre-line break-words text-base font-semibold text-slate-950">{decision.text}</p>

            {action && (
                <dl className="flex flex-wrap gap-x-5 gap-y-1 text-base">
                    <div className="flex gap-1"><dt className="text-slate-600">{td.owner ?? 'Ansvarlig'}:</dt><dd className="text-slate-900">{decision.owner_name ?? '—'}</dd></div>
                    <div className="flex gap-1"><dt className="text-slate-600">{td.due_date ?? 'Frist'}:</dt><dd className="text-slate-900">{formatDay(decision.due_date)}</dd></div>
                    <div className="flex min-w-0 gap-1">
                        <dt className="text-slate-600">{td.follow_up ?? 'Oppfølging'}:</dt>
                        <dd className="min-w-0 break-words text-slate-900">
                            {decision.follow_up === 'own' && (td.followed_here ?? 'Følges opp i Ledelsens gjennomgang')}
                            {decision.case && ! decision.case.hidden && (
                                <>
                                    <Link href={decision.case.url} className="font-semibold text-violet-700 hover:text-violet-900">{fill(td.in_case ?? 'Følges opp i saken «:title»', { title: decision.case.title })}</Link>
                                    {' · '}{t.values?.[`case_${decision.case.status}`] ?? decision.case.status}
                                    {decision.case.owner_name ? ` · ${decision.case.owner_name}` : ''}
                                </>
                            )}
                            {decision.case?.hidden && <span title={td.case_hidden_hint}>{td.case_hidden ?? 'Følges opp i Avvik og forbedringer'} – {td.case_hidden_hint}</span>}
                        </dd>
                    </div>
                </dl>
            )}
            {decision.completion_note && <p className="break-words text-base text-slate-700">{decision.completion_note}</p>}

            {finalized && decision.at_finalization && (
                <p className="text-base text-slate-600" data-testid="mr-decision-at-finalization">
                    {td.at_finalization ?? 'Ved ferdigstilling'}: {Object.values(decision.at_finalization.cells ?? {}).filter(Boolean).join(' · ')}
                </p>
            )}

            {panel === null && (perms.can_edit || perms.can_follow_up || perms.can_hand_off || (finalized && perms.can_reassign)) && (
                <div className="flex flex-wrap gap-2">
                    {perms.can_follow_up && decision.status === 'open' && (
                        <>
                            <button type="button" id={buttonId('completed')} onClick={() => open('completed')} aria-describedby={textId} className={SECONDARY_ACTION} data-testid="mr-action-complete">{td.complete ?? 'Fullfør'}</button>
                            <button type="button" id={buttonId('cancelled')} onClick={() => open('cancelled')} aria-describedby={textId} className={SECONDARY_ACTION}>{td.cancel_action ?? 'Avbryt tiltak'}</button>
                        </>
                    )}
                    {perms.can_follow_up && decision.status !== 'open' && (
                        <button type="button" id={buttonId('open')} onClick={() => open('open')} aria-describedby={textId} className={SECONDARY_ACTION}>{td.reopen ?? 'Gjenåpne'}</button>
                    )}
                    {perms.can_hand_off && handoffOptions && (
                        <button type="button" id={buttonId('handoff')} onClick={() => open('handoff')} aria-describedby={textId} className={SECONDARY_ACTION} data-testid="mr-action-handoff">{td.hand_off ?? 'Følg opp i Avvik og forbedringer'}</button>
                    )}
                    {finalized && perms.can_reassign && decision.status === 'open' && (
                        <button type="button" id={buttonId('reassign')} onClick={() => open('reassign')} aria-describedby={textId} className={SECONDARY_ACTION}>{td.reassign ?? 'Endre ansvarlig eller frist'}</button>
                    )}
                    {perms.can_edit && (
                        <>
                            <button type="button" id={buttonId('edit')} onClick={() => open('edit')} aria-describedby={textId} className={SECONDARY_ACTION}>{td.edit ?? 'Rediger'}</button>
                            <button type="button" id={buttonId('delete')} onClick={destroy} aria-describedby={textId} className={DESTRUCTIVE_ACTION}>{td.delete ?? 'Slett'}</button>
                        </>
                    )}
                </div>
            )}

            {panel !== null && (
                <div ref={panelRef}>
                    {['completed', 'cancelled', 'open'].includes(panel) && <StatusForm reviewId={review.id} decision={decision} status={panel} t={t} onDone={close} />}
                    {panel === 'reassign' && <ReassignForm reviewId={review.id} decision={decision} ownerOptions={ownerOptions} t={t} onDone={close} />}
                    {panel === 'handoff' && <HandOffForm reviewId={review.id} reviewTitle={review.title} decision={decision} options={handoffOptions} t={t} onDone={close} />}
                    {panel === 'edit' && <DecisionForm reviewId={review.id} decision={decision} sections={sections} ownerOptions={ownerOptions} t={t} onDone={close} />}
                </div>
            )}
        </li>
    );
}

/**
 * A list of decisions, with «Registrer» when the review is still a draft. «Registrer» opens the form
 * in place (focus to its first field, back to the button on close, A11Y-014); `headingId` is where
 * focus lands when the last decision of the list is deleted.
 */
export function DecisionList({ review, decisions, sections, ownerOptions, handoffOptions, canEdit, finalized, sectionKey = null, headingId = null, t }) {
    const td = t.decisions ?? {};
    const [adding, setAdding] = useState(false);
    const addPanelRef = useRef(null);
    const addButtonRef = useRef(null);
    const addButtonId = `mr-add-decision-${sectionKey ?? 'all'}`;
    usePanelFocus(adding, { panelRef: addPanelRef, returnFocusRef: addButtonRef });

    const focusAfterRemoval = (removedId) => removalFocusCandidates(
        decisions.map((decision) => decision.id),
        removedId,
        (id) => `decision-${id}-text`,
        [addButtonId, headingId].filter(Boolean),
    );

    return (
        <div>
            {decisions.length === 0 ? (
                <p className="text-base text-slate-600">{td.empty ?? 'Ingen beslutninger eller tiltak er registrert.'}</p>
            ) : (
                <ul className="divide-y divide-slate-100">
                    {decisions.map((decision) => (
                        <DecisionCard key={decision.id} review={review} decision={decision} sections={sections} ownerOptions={ownerOptions} handoffOptions={handoffOptions} finalized={finalized} t={t} focusAfterRemoval={focusAfterRemoval} />
                    ))}
                </ul>
            )}
            {canEdit && (adding ? (
                <div ref={addPanelRef}>
                    <DecisionForm reviewId={review.id} sectionKey={sectionKey} sections={sections} ownerOptions={ownerOptions} t={t} onDone={() => setAdding(false)} />
                </div>
            ) : (
                <button type="button" id={addButtonId} ref={addButtonRef} onClick={() => setAdding(true)} className={`mt-3 ${SECONDARY_ACTION}`} data-testid="mr-add-decision">{td.add ?? 'Registrer beslutning eller tiltak'}</button>
            ))}
        </div>
    );
}
