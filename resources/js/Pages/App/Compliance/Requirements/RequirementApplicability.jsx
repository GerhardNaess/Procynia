import { useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { RequiredFieldsNote } from '../../../../Components/App/FormField';
import StatusBadge from '../../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import { BUSY_STYLE, submitWhenIdle, usePanelFocus } from '../../../../Support/focusManagement';
import { INPUT, LABEL } from '../../../../Support/formStyles';
import RequiredMark from '../RequiredMark';
import FieldError from '../FieldError';
import { fieldStateProps, radioGroupProps } from '../complianceA11y';
import { APPLICABILITY_TONES, applicabilityLabel } from '../Library/complianceLibrary';
import { useFocusOnRejectedSubmit } from '../useComplianceFocus';
import { formatDateTime } from './complianceRequirement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * Relevans for a requirement from Kravbiblioteket: Gjelder, Ikke relevant or Ikke vurdert. Kept apart
 * from Etterlevelse on purpose — this says whether the requirement is part of the work, the
 * assessments say whether it is met. The server sends only the choices the standard allows: a
 * mandatory requirement never offers Ikke relevant, and Ikke relevant always needs a begrunnelse.
 *
 * For a selectable control (rule «control», ISO/IEC 27001 Annex A) the same choice is Kontrollvalg:
 * Inkludert or Utelatt, and both need a begrunnelse — the basis for the Statement of Applicability.
 */
export default function RequirementApplicability({ requirementId, applicability, rule, canChange, locale, tr }) {
    const control = rule === 'control';
    const ta = control ? { ...(tr.library?.applicability ?? {}), ...(tr.library?.applicability?.control ?? {}) } : (tr.library?.applicability ?? {});
    const [editing, setEditing] = useState(false);
    const form = useForm({ applicability: applicability.value, rationale: applicability.rationale ?? '' });
    const needsRationale = form.data.applicability === 'not_applicable' || (control && form.data.applicability === 'applies');
    const formRef = useRef(null);
    const changeRef = useRef(null);
    // «Endre relevans» gives way to the form: focus goes into it, and back to the button when it closes.
    usePanelFocus(editing, { panelRef: formRef, returnFocusRef: changeRef });
    useFocusOnRejectedSubmit(form, formRef);
    const choiceError = form.errors.applicability;

    const send = (event) => {
        event.preventDefault();
        form.post(`/app/compliance/requirements/${requirementId}/applicability`, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    return (
        <section className={CARD} aria-labelledby="compliance-applicability-heading" data-testid="compliance-applicability">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 space-y-2">
                    <h2 id="compliance-applicability-heading" className="text-xl font-semibold text-slate-950">{ta.heading ?? 'Relevans'}</h2>
                    <p className="max-w-3xl text-base text-slate-600">{ta.intro ?? ''}</p>
                </div>
                {canChange && ! editing && (
                    <button ref={changeRef} type="button" onClick={() => setEditing(true)} className={SECONDARY_ACTION}>{ta.change ?? 'Endre relevans'}</button>
                )}
            </div>

            <div className="mt-4 space-y-2">
                <StatusBadge tone={APPLICABILITY_TONES[applicability.value] ?? 'slate'}>{applicabilityLabel(applicability.value, tr, rule)}</StatusBadge>
                {applicability.rationale && (
                    <p className="whitespace-pre-line break-words text-base text-slate-800" data-testid="compliance-applicability-rationale">{applicability.rationale}</p>
                )}
                {applicability.set_at && (
                    <p className="text-base text-slate-600">
                        {(ta.set_by ?? 'Sist endret av :name').replace(':name', applicability.set_by_name ?? (tr.unknown_user ?? 'en tidligere bruker'))}
                        {', '}
                        {formatDateTime(applicability.set_at, locale)}
                    </p>
                )}
                <p className="text-base text-slate-700">{control ? (ta.note ?? '') : rule === 'conditional' ? (ta.conditional_note ?? '') : (ta.mandatory_note ?? '')}</p>
                {applicability.value !== 'applies' && (
                    <p className="text-base font-semibold text-sky-800" data-testid="compliance-applicability-assess-blocked">{ta.assess_blocked ?? ''}</p>
                )}
            </div>

            {editing && (
                <form ref={formRef} onSubmit={submitWhenIdle(form, send)} className="mt-4 space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-4" data-testid="compliance-applicability-form">
                    <fieldset {...radioGroupProps('compliance-applicability', choiceError, { required: true })}>
                        <legend className={LABEL}>{ta.field ?? 'Relevans'}<RequiredMark /></legend>
                        <div className="mt-2 grid gap-2 sm:grid-cols-3">
                            {applicability.options.map((value) => (
                                <label key={value} htmlFor={`compliance-applicability-${value}`} className="flex min-h-11 cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900">
                                    <input
                                        id={`compliance-applicability-${value}`}
                                        type="radio"
                                        name="applicability"
                                        value={value}
                                        checked={form.data.applicability === value}
                                        onChange={() => form.setData('applicability', value)}
                                        className="h-5 w-5"
                                    />
                                    {applicabilityLabel(value, tr, rule)}
                                </label>
                            ))}
                        </div>
                        <FieldError field="compliance-applicability" message={choiceError} />
                    </fieldset>
                    <div>
                        <label htmlFor="compliance-applicability-rationale" className={LABEL}>
                            {ta.field_rationale ?? 'Begrunnelse'}{needsRationale && <RequiredMark />}
                        </label>
                        <p id="compliance-applicability-rationale-hint" className="mt-1 text-base text-slate-600">{ta.rationale_hint ?? ''}</p>
                        <textarea
                            id="compliance-applicability-rationale"
                            rows={4}
                            required={needsRationale}
                            aria-required={needsRationale ? 'true' : undefined}
                            {...fieldStateProps('compliance-applicability-rationale', form.errors.rationale, ['compliance-applicability-rationale-hint'])}
                            value={form.data.rationale}
                            onChange={(event) => form.setData('rationale', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        />
                        <FieldError field="compliance-applicability-rationale" message={form.errors.rationale} />
                    </div>
                    <RequiredFieldsNote text={tr.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />
                    <div className="flex flex-wrap justify-end gap-3">
                        <button type="button" onClick={() => { setEditing(false); form.reset(); form.clearErrors(); }} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                        <button type="submit" aria-disabled={form.processing || undefined} className={`${PRIMARY_ACTION} ${BUSY_STYLE}`}>{form.processing ? (tr.saving ?? 'Lagrer...') : (ta.submit ?? 'Lagre relevans')}</button>
                    </div>
                </form>
            )}
        </section>
    );
}
