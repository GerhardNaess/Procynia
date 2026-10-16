import { useRef } from 'react';
import { RequiredFieldsNote } from '../../../../Components/App/FormField';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import { BUSY_STYLE, submitWhenIdle } from '../../../../Support/focusManagement';
import { HINT, INPUT, LABEL } from '../../../../Support/formStyles';
import RequiredMark from '../RequiredMark';
import FieldError from '../FieldError';
import { fieldStateProps } from '../complianceA11y';
import { useFocusOnRejectedSubmit } from '../useComplianceFocus';
import { reviewIntervalLabel } from './complianceRequirement';

/**
 * The fields of a requirement, shared by Nytt krav and Rediger. The source and owner lists come from
 * the server, which checks both again on save. There is no status field: a requirement is created
 * active and moves only through Sett som utgått and Gjenåpne. Nor is there anything about whether
 * the requirement is met — that is assessed elsewhere. For a requirement from Kravbiblioteket only
 * the person responsible and the review interval are the customer's to change.
 */
export default function RequirementForm({ form, onSubmit, onCancel, sourceOptions = [], ownerOptions = [], reviewIntervals = [], fromLibrary = false, tr }) {
    const formRef = useRef(null);
    useFocusOnRejectedSubmit(form, formRef);

    return (
        <form ref={formRef} onSubmit={submitWhenIdle(form, onSubmit)} className="space-y-5">
            {/* A requirement from Kravbiblioteket: source, reference, title and text follow the library. */}
            {fromLibrary ? (
                <p className="text-base text-slate-700" data-testid="compliance-library-owned-fields">
                    {tr.library?.library_owned_fields ?? 'Punkt, tittel og kravtekst følger kravbiblioteket.'}
                </p>
            ) : (
                <>
                    <div className="grid gap-5 md:grid-cols-2">
                        <div>
                            <label htmlFor="compliance-requirement-source" className={LABEL}>{tr.field_source ?? 'Kravkilde'}<RequiredMark /></label>
                            <select
                                id="compliance-requirement-source"
                                required
                                aria-required="true"
                                {...fieldStateProps('compliance-requirement-source', form.errors.source_id, ['compliance-requirement-source-hint'])}
                                value={form.data.source_id}
                                onChange={(event) => form.setData('source_id', event.target.value)}
                                className={`mt-1 ${INPUT}`}
                            >
                                <option value="">{tr.choose_source ?? 'Velg kravkilde'}</option>
                                {sourceOptions.map((source) => (
                                    <option key={source.id} value={source.id}>{source.label}</option>
                                ))}
                            </select>
                            <p id="compliance-requirement-source-hint" className={HINT}>{tr.field_source_hint ?? 'Hvor kravet kommer fra.'}</p>
                            <FieldError field="compliance-requirement-source" message={form.errors.source_id} />
                        </div>

                        <div>
                            <label htmlFor="compliance-requirement-reference" className={LABEL}>{tr.field_reference ?? 'Referanse'}</label>
                            <input
                                id="compliance-requirement-reference"
                                type="text"
                                maxLength={100}
                                {...fieldStateProps('compliance-requirement-reference', form.errors.reference, ['compliance-requirement-reference-hint'])}
                                value={form.data.reference}
                                onChange={(event) => form.setData('reference', event.target.value)}
                                className={`mt-1 ${INPUT}`}
                            />
                            <p id="compliance-requirement-reference-hint" className={HINT}>
                                {tr.field_reference_hint ?? 'Valgfritt. For eksempel «A.5.1» eller «§ 4-2». Må være unik innen kravkilden.'}
                            </p>
                            <FieldError field="compliance-requirement-reference" message={form.errors.reference} />
                        </div>
                    </div>

                    <div>
                        <label htmlFor="compliance-requirement-title" className={LABEL}>{tr.field_title ?? 'Tittel'}<RequiredMark /></label>
                        <p id="compliance-requirement-title-hint" className={HINT}>{tr.field_title_hint ?? 'Kort navn på kravet.'}</p>
                        <input
                            id="compliance-requirement-title"
                            type="text"
                            required
                            aria-required="true"
                            {...fieldStateProps('compliance-requirement-title', form.errors.title, ['compliance-requirement-title-hint'])}
                            value={form.data.title}
                            onChange={(event) => form.setData('title', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        />
                        <FieldError field="compliance-requirement-title" message={form.errors.title} />
                    </div>

                    <div>
                        <label htmlFor="compliance-requirement-text" className={LABEL}>{tr.field_requirement_text ?? 'Kravtekst'}<RequiredMark /></label>
                        <p id="compliance-requirement-text-hint" className={HINT}>{tr.field_requirement_text_hint ?? 'Hva kravet sier – gjerne ordrett fra kilden.'}</p>
                        <textarea
                            id="compliance-requirement-text"
                            rows={5}
                            required
                            aria-required="true"
                            {...fieldStateProps('compliance-requirement-text', form.errors.requirement_text, ['compliance-requirement-text-hint'])}
                            value={form.data.requirement_text}
                            onChange={(event) => form.setData('requirement_text', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        />
                        <FieldError field="compliance-requirement-text" message={form.errors.requirement_text} />
                    </div>
                </>
            )}

            <div className="grid gap-5 md:grid-cols-2">
                <div>
                    <label htmlFor="compliance-requirement-owner" className={LABEL}>{tr.field_owner_optional ?? 'Ansvarlig (valgfritt)'}</label>
                    <select
                        id="compliance-requirement-owner"
                        {...fieldStateProps('compliance-requirement-owner', form.errors.owner_user_id, ['compliance-requirement-owner-hint'])}
                        value={form.data.owner_user_id}
                        onChange={(event) => form.setData('owner_user_id', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{tr.no_owner ?? 'Ingen valgt'}</option>
                        {ownerOptions.map((owner) => (
                            <option key={owner.id} value={owner.id}>{owner.name}</option>
                        ))}
                    </select>
                    <p id="compliance-requirement-owner-hint" className={HINT}>
                        {tr.field_owner_hint ?? 'Valgfritt. Ansvaret følger arbeidet: vurderingen registrerer hvem som vurderte, revisjoner og avvik har egne ansvarlige.'}
                    </p>
                    <FieldError field="compliance-requirement-owner" message={form.errors.owner_user_id} />
                </div>

                <div>
                    <label htmlFor="compliance-requirement-review" className={LABEL}>{tr.field_review ?? 'Revurderingsintervall'}</label>
                    <select
                        id="compliance-requirement-review"
                        {...fieldStateProps('compliance-requirement-review', form.errors.review_interval_months, ['compliance-requirement-review-hint'])}
                        value={form.data.review_interval_months}
                        onChange={(event) => form.setData('review_interval_months', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{reviewIntervalLabel(null, tr)}</option>
                        {reviewIntervals.map((months) => (
                            <option key={months} value={months}>{reviewIntervalLabel(months, tr)}</option>
                        ))}
                    </select>
                    <p id="compliance-requirement-review-hint" className={HINT}>{tr.field_review_hint ?? 'Hvor ofte kravet skal vurderes på nytt.'}</p>
                    <FieldError field="compliance-requirement-review" message={form.errors.review_interval_months} />
                </div>
            </div>

            <RequiredFieldsNote text={tr.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />

            <div className="flex flex-wrap justify-end gap-3">
                {onCancel && (
                    <button type="button" onClick={onCancel} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                )}
                <button type="submit" aria-disabled={form.processing || undefined} className={`${PRIMARY_ACTION} ${BUSY_STYLE}`}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (tr.save ?? 'Lagre')}
                </button>
            </div>
        </form>
    );
}
