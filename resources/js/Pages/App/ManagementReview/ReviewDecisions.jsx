import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import RequiredMark from '../Risk/RequiredMark';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION } from '../../../Support/actionStyles';
import { fill, formatDay, ownersForArea, sectionTitle } from './reviewSections';

const INPUT = 'min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';
const ERROR = 'mt-1 text-base text-rose-700';
const PANEL = 'mt-3 space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-4';

const ACTION_STATUS_TONES = { open: 'blue', completed: 'emerald', cancelled: 'slate' };

/**
 * Registrer beslutning eller tiltak — and Rediger. A tiltak takes a responsible person and a due date
 * and goes straight into their Mine oppgaver; a decision takes neither.
 */
export function DecisionForm({ reviewId, decision = null, sectionKey = null, sections = [], ownerOptions = [], t, onDone }) {
    const td = t.decisions ?? {};
    const form = useForm({
        kind: decision?.kind ?? 'action',
        text: decision?.text ?? '',
        section_key: decision?.section_key ?? sectionKey ?? '',
        owner_user_id: decision?.owner_user_id ? String(decision.owner_user_id) : '',
        due_date: decision?.due_date ?? '',
    });
    const action = form.data.kind === 'action';
    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onDone?.(); } };
        form.transform((data) => ({ ...data, section_key: data.section_key || null }));

        if (decision) {
            form.patch(`/app/management-reviews/${reviewId}/decisions/${decision.id}`, options);
        } else {
            form.post(`/app/management-reviews/${reviewId}/decisions`, options);
        }
    };

    return (
        <form onSubmit={submit} className={PANEL} data-testid="mr-decision-form">
            <fieldset>
                <legend className={LABEL}>{td.kind ?? 'Type'}</legend>
                <div className="mt-2 flex flex-wrap gap-5">
                    {['action', 'decision'].map((kind) => (
                        <label key={kind} className="flex min-h-11 items-center gap-2 text-base text-slate-800">
                            <input type="radio" name="mr-decision-kind" value={kind} checked={form.data.kind === kind} onChange={() => form.setData('kind', kind)} className="h-5 w-5" data-testid={`mr-decision-kind-${kind}`} />
                            {t.values?.[`kind_${kind}`] ?? kind}
                        </label>
                    ))}
                </div>
                <p className="mt-1 text-base text-slate-600">{action ? td.kind_action_hint : td.kind_decision_hint}</p>
            </fieldset>

            <div>
                <label htmlFor="mr-decision-text" className={LABEL}>{action ? (td.text_action ?? 'Tiltak') : (td.text ?? 'Beslutning')}<RequiredMark /></label>
                <textarea id="mr-decision-text" rows={2} required maxLength={2000} value={form.data.text} onChange={(event) => form.setData('text', event.target.value)} className={`mt-1 ${INPUT}`} />
                {form.errors.text && <p className={ERROR}>{form.errors.text}</p>}
            </div>

            {action && (
                <div className="grid gap-4 md:grid-cols-2">
                    <div>
                        <label htmlFor="mr-decision-owner" className={LABEL}>{td.owner ?? 'Ansvarlig'}<RequiredMark /></label>
                        <select id="mr-decision-owner" required value={form.data.owner_user_id} onChange={(event) => form.setData('owner_user_id', event.target.value)} className={`mt-1 ${INPUT}`}>
                            <option value="">{td.choose_owner ?? 'Velg ansvarlig'}</option>
                            {ownerOptions.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                        </select>
                        {form.errors.owner_user_id && <p className={ERROR}>{form.errors.owner_user_id}</p>}
                    </div>
                    <div>
                        <label htmlFor="mr-decision-due" className={LABEL}>{td.due_date ?? 'Frist'}<RequiredMark /></label>
                        <input id="mr-decision-due" type="date" required value={form.data.due_date} onChange={(event) => form.setData('due_date', event.target.value)} className={`mt-1 ${INPUT}`} />
                        {form.errors.due_date && <p className={ERROR}>{form.errors.due_date}</p>}
                    </div>
                </div>
            )}

            <div>
                <label htmlFor="mr-decision-section" className={LABEL}>{td.section ?? 'Seksjon'}</label>
                <select id="mr-decision-section" value={form.data.section_key} onChange={(event) => form.setData('section_key', event.target.value)} className={`mt-1 ${INPUT}`}>
                    <option value="">{td.no_section ?? 'Ingen bestemt seksjon'}</option>
                    {sections.map((section) => <option key={section.key} value={section.key}>{sectionTitle(section.key, t)}</option>)}
                </select>
            </div>

            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>{td.save ?? 'Lagre'}</button>
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{t.cancel ?? 'Avbryt'}</button>
            </div>
        </form>
    );
}

function StatusForm({ reviewId, decision, status, t, onDone }) {
    const td = t.decisions ?? {};
    const form = useForm({ status, note: '' });
    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/management-reviews/${reviewId}/decisions/${decision.id}/status`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className={PANEL}>
            {status !== 'open' && (
                <div>
                    <label htmlFor={`mr-status-note-${decision.id}`} className={LABEL}>{status === 'completed' ? td.note : td.cancel_note}</label>
                    <textarea id={`mr-status-note-${decision.id}`} rows={2} maxLength={5000} value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} className={`mt-1 ${INPUT}`} />
                </div>
            )}
            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing} className={status === 'cancelled' ? WARNING_ACTION : PRIMARY_ACTION} data-testid={`mr-status-confirm-${status}`}>
                    {status === 'completed' ? td.complete : status === 'cancelled' ? td.cancel_action : td.reopen}
                </button>
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{t.cancel ?? 'Avbryt'}</button>
            </div>
        </form>
    );
}

function ReassignForm({ reviewId, decision, ownerOptions, t, onDone }) {
    const td = t.decisions ?? {};
    const form = useForm({ owner_user_id: decision.owner_user_id ? String(decision.owner_user_id) : '', due_date: decision.due_date ?? '' });
    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/management-reviews/${reviewId}/decisions/${decision.id}/reassign`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className={PANEL}>
            <div className="grid gap-4 md:grid-cols-2">
                <div>
                    <label htmlFor={`mr-reassign-owner-${decision.id}`} className={LABEL}>{td.owner ?? 'Ansvarlig'}</label>
                    <select id={`mr-reassign-owner-${decision.id}`} required value={form.data.owner_user_id} onChange={(event) => form.setData('owner_user_id', event.target.value)} className={`mt-1 ${INPUT}`}>
                        <option value="">{td.choose_owner ?? 'Velg ansvarlig'}</option>
                        {ownerOptions.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                    </select>
                    {form.errors.owner_user_id && <p className={ERROR}>{form.errors.owner_user_id}</p>}
                </div>
                <div>
                    <label htmlFor={`mr-reassign-due-${decision.id}`} className={LABEL}>{td.due_date ?? 'Frist'}</label>
                    <input id={`mr-reassign-due-${decision.id}`} type="date" required value={form.data.due_date} onChange={(event) => form.setData('due_date', event.target.value)} className={`mt-1 ${INPUT}`} />
                </div>
            </div>
            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>{td.save ?? 'Lagre'}</button>
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{t.cancel ?? 'Avbryt'}</button>
            </div>
        </form>
    );
}

function HandOffForm({ reviewId, reviewTitle, decision, options, t, onDone }) {
    const td = t.decisions ?? {};
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
    const submit = (event) => {
        event.preventDefault();

        if (mode === 'new') {
            form.post(`/app/management-reviews/${reviewId}/decisions/${decision.id}/handoff`, { preserveScroll: true, onSuccess: onDone });
        } else {
            link.post(`/app/management-reviews/${reviewId}/decisions/${decision.id}/link`, { preserveScroll: true, onSuccess: onDone });
        }
    };

    return (
        <form onSubmit={submit} className={PANEL} data-testid="mr-handoff-form">
            <p className="text-base text-slate-700">{td.hand_off_hint}</p>
            {options.case_options.length > 0 && (
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
            )}

            {mode === 'new' ? (
                <>
                    <div>
                        <label htmlFor={`mr-case-title-${decision.id}`} className={LABEL}>{td.case_title}<RequiredMark /></label>
                        <input id={`mr-case-title-${decision.id}`} required maxLength={255} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} className={`mt-1 ${INPUT}`} />
                        {form.errors.title && <p className={ERROR}>{form.errors.title}</p>}
                    </div>
                    <div>
                        <label htmlFor={`mr-case-description-${decision.id}`} className={LABEL}>{td.case_description}<RequiredMark /></label>
                        <textarea id={`mr-case-description-${decision.id}`} required rows={2} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} className={`mt-1 ${INPUT}`} />
                        {form.errors.description && <p className={ERROR}>{form.errors.description}</p>}
                    </div>
                    <div className="grid gap-4 md:grid-cols-3">
                        <div>
                            <label htmlFor={`mr-case-area-${decision.id}`} className={LABEL}>{td.case_area}<RequiredMark /></label>
                            <select id={`mr-case-area-${decision.id}`} required value={form.data.business_area_id} onChange={(event) => form.setData('business_area_id', event.target.value)} className={`mt-1 ${INPUT}`}>
                                <option value="">{td.choose_area}</option>
                                {options.area_options.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}
                            </select>
                            {form.errors.business_area_id && <p className={ERROR}>{form.errors.business_area_id}</p>}
                        </div>
                        <div>
                            <label htmlFor={`mr-case-owner-${decision.id}`} className={LABEL}>{td.case_owner}<RequiredMark /></label>
                            <select id={`mr-case-owner-${decision.id}`} required value={form.data.owner_user_id} onChange={(event) => form.setData('owner_user_id', event.target.value)} className={`mt-1 ${INPUT}`}>
                                <option value="">{td.choose_owner}</option>
                                {owners.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                            </select>
                            {form.errors.owner_user_id && <p className={ERROR}>{form.errors.owner_user_id}</p>}
                        </div>
                        <div>
                            <label htmlFor={`mr-case-due-${decision.id}`} className={LABEL}>{td.due_date}</label>
                            <input id={`mr-case-due-${decision.id}`} type="date" value={form.data.due_date} onChange={(event) => form.setData('due_date', event.target.value)} className={`mt-1 ${INPUT}`} />
                        </div>
                    </div>
                </>
            ) : (
                <div>
                    <label htmlFor={`mr-link-case-${decision.id}`} className={LABEL}>{td.link_choose}<RequiredMark /></label>
                    <select id={`mr-link-case-${decision.id}`} required value={link.data.improvement_case_id} onChange={(event) => link.setData('improvement_case_id', event.target.value)} className={`mt-1 ${INPUT}`}>
                        <option value="">{td.link_choose}</option>
                        {options.case_options.map((item) => <option key={item.id} value={item.id}>{item.title}</option>)}
                    </select>
                    {link.errors.improvement_case_id && <p className={ERROR}>{link.errors.improvement_case_id}</p>}
                </div>
            )}

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
export function DecisionCard({ review, decision, sections, ownerOptions, handoffOptions, finalized, t }) {
    const td = t.decisions ?? {};
    const [panel, setPanel] = useState(null);
    const close = () => setPanel(null);
    const action = decision.kind === 'action';
    const perms = decision.permissions ?? {};
    const destroy = () => {
        if (window.confirm(td.delete_confirm ?? 'Slett beslutningen?')) {
            router.delete(`/app/management-reviews/${review.id}/decisions/${decision.id}`, { preserveScroll: true });
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
            <p className="whitespace-pre-line break-words text-base font-semibold text-slate-950">{decision.text}</p>

            {action && (
                <dl className="flex flex-wrap gap-x-5 gap-y-1 text-base">
                    <div className="flex gap-1"><dt className="text-slate-600">{td.owner ?? 'Ansvarlig'}:</dt><dd className="text-slate-900">{decision.owner_name ?? '—'}</dd></div>
                    <div className="flex gap-1"><dt className="text-slate-600">{td.due_date ?? 'Frist'}:</dt><dd className="text-slate-900">{formatDay(decision.due_date)}</dd></div>
                    <div className="flex min-w-0 gap-1">
                        <dt className="text-slate-600">{td.follow_up ?? 'Oppfølging'}:</dt>
                        <dd className="min-w-0 break-words text-slate-900">
                            {decision.follow_up === 'own' && (td.followed_here ?? 'Følges opp i Ledelsens gjennomgåelse')}
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
                            <button type="button" onClick={() => setPanel('completed')} className={SECONDARY_ACTION} data-testid="mr-action-complete">{td.complete ?? 'Fullfør'}</button>
                            <button type="button" onClick={() => setPanel('cancelled')} className={SECONDARY_ACTION}>{td.cancel_action ?? 'Avbryt tiltak'}</button>
                        </>
                    )}
                    {perms.can_follow_up && decision.status !== 'open' && (
                        <button type="button" onClick={() => setPanel('open')} className={SECONDARY_ACTION}>{td.reopen ?? 'Gjenåpne'}</button>
                    )}
                    {perms.can_hand_off && handoffOptions && (
                        <button type="button" onClick={() => setPanel('handoff')} className={SECONDARY_ACTION} data-testid="mr-action-handoff">{td.hand_off ?? 'Følg opp i Avvik og forbedringer'}</button>
                    )}
                    {finalized && perms.can_reassign && decision.status === 'open' && (
                        <button type="button" onClick={() => setPanel('reassign')} className={SECONDARY_ACTION}>{td.reassign ?? 'Endre ansvarlig eller frist'}</button>
                    )}
                    {perms.can_edit && (
                        <>
                            <button type="button" onClick={() => setPanel('edit')} className={SECONDARY_ACTION}>{td.edit ?? 'Rediger'}</button>
                            <button type="button" onClick={destroy} className={DESTRUCTIVE_ACTION}>{td.delete ?? 'Slett'}</button>
                        </>
                    )}
                </div>
            )}

            {['completed', 'cancelled', 'open'].includes(panel) && <StatusForm reviewId={review.id} decision={decision} status={panel} t={t} onDone={close} />}
            {panel === 'reassign' && <ReassignForm reviewId={review.id} decision={decision} ownerOptions={ownerOptions} t={t} onDone={close} />}
            {panel === 'handoff' && <HandOffForm reviewId={review.id} reviewTitle={review.title} decision={decision} options={handoffOptions} t={t} onDone={close} />}
            {panel === 'edit' && <DecisionForm reviewId={review.id} decision={decision} sections={sections} ownerOptions={ownerOptions} t={t} onDone={close} />}
        </li>
    );
}

/** A list of decisions, with «Registrer» when the review is still a draft. */
export function DecisionList({ review, decisions, sections, ownerOptions, handoffOptions, canEdit, finalized, sectionKey = null, t }) {
    const td = t.decisions ?? {};
    const [adding, setAdding] = useState(false);

    return (
        <div>
            {decisions.length === 0 ? (
                <p className="text-base text-slate-600">{td.empty ?? 'Ingen beslutninger eller tiltak er registrert.'}</p>
            ) : (
                <ul className="divide-y divide-slate-100">
                    {decisions.map((decision) => (
                        <DecisionCard key={decision.id} review={review} decision={decision} sections={sections} ownerOptions={ownerOptions} handoffOptions={handoffOptions} finalized={finalized} t={t} />
                    ))}
                </ul>
            )}
            {canEdit && (adding ? (
                <DecisionForm reviewId={review.id} sectionKey={sectionKey} sections={sections} ownerOptions={ownerOptions} t={t} onDone={() => setAdding(false)} />
            ) : (
                <button type="button" onClick={() => setAdding(true)} className={`mt-3 ${SECONDARY_ACTION}`} data-testid="mr-add-decision">{td.add ?? 'Registrer beslutning eller tiltak'}</button>
            ))}
        </div>
    );
}
