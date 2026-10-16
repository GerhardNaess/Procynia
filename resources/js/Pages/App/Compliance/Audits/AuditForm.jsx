import { useRef } from 'react';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import { HINT, INPUT, LABEL } from '../../../../Support/formStyles';
import RequiredMark from '../RequiredMark';
import FieldError from '../FieldError';
import { fieldStateProps, radioGroupProps } from '../complianceA11y';
import { useFocusOnRejectedSubmit } from '../useComplianceFocus';
import { auditTypeLabel } from './complianceAudit';

const ALL_FIELDS = ['title', 'purpose', 'audit_type', 'responsible_user_id', 'auditor_name', 'planned_start_date', 'planned_end_date', 'scope_description'];

/**
 * The fields of an audit, shared by Ny revisjon and Rediger. Only the fields the server says the
 * audit's status leaves open are drawn (`fields`); the server reads nothing else either. There is
 * no status field — an audit moves only through Start, Fullfør, Avbryt and Gjenåpne — and the
 * conclusion is offered only once the audit is under way.
 *
 * Formål says why, Omfang what the audit covers; both are optional text, since the omfang can be the
 * requirements in scope alone. Ny revisjon puts its requirement selection (`children`) between the
 * omfang and the buttons, so planning is one form.
 */
export default function AuditForm({ form, onSubmit, onCancel, fields = ALL_FIELDS, types = [], responsibleOptions = [], ta, children = null }) {
    const has = (field) => fields.includes(field);
    const formRef = useRef(null);
    useFocusOnRejectedSubmit(form, formRef);
    const dateHint = 'compliance-audit-dates-hint';

    return (
        <form ref={formRef} onSubmit={onSubmit} className="space-y-5" data-testid="compliance-audit-form">
            {has('title') && (
                <div>
                    <label htmlFor="compliance-audit-title" className={LABEL}>{ta.field_title ?? 'Tittel'}<RequiredMark /></label>
                    <p id="compliance-audit-title-hint" className={HINT}>{ta.field_title_hint ?? 'For eksempel «Internrevisjon tilgangsstyring 2026».'}</p>
                    <input
                        id="compliance-audit-title"
                        type="text"
                        required
                        aria-required="true"
                        {...fieldStateProps('compliance-audit-title', form.errors.title, ['compliance-audit-title-hint'])}
                        maxLength={255}
                        value={form.data.title}
                        onChange={(event) => form.setData('title', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <FieldError field="compliance-audit-title" message={form.errors.title} />
                </div>
            )}

            <div className="grid gap-5 md:grid-cols-2">
                {has('audit_type') && (
                    <fieldset {...radioGroupProps('compliance-audit-type', form.errors.audit_type, { required: true })}>
                        <legend className={LABEL}>{ta.field_type ?? 'Type'}<RequiredMark /></legend>
                        <div className="mt-2 flex flex-wrap gap-4">
                            {types.map((type) => (
                                <label key={type} className="flex min-h-10 items-center gap-2 text-base text-slate-800">
                                    <input
                                        id={`compliance-audit-type-${type}`}
                                        type="radio"
                                        name="audit_type"
                                        value={type}
                                        required
                                        checked={form.data.audit_type === type}
                                        onChange={() => form.setData('audit_type', type)}
                                        className="h-5 w-5 border-slate-300"
                                    />
                                    {auditTypeLabel(type, ta)}
                                </label>
                            ))}
                        </div>
                        <FieldError field="compliance-audit-type" message={form.errors.audit_type} />
                    </fieldset>
                )}

                {has('responsible_user_id') && (
                    <div>
                        <label htmlFor="compliance-audit-responsible" className={LABEL}>{ta.field_responsible ?? 'Ansvarlig'}<RequiredMark /></label>
                        <select
                            id="compliance-audit-responsible"
                            required
                            aria-required="true"
                            {...fieldStateProps('compliance-audit-responsible', form.errors.responsible_user_id, ['compliance-audit-responsible-hint'])}
                            value={form.data.responsible_user_id}
                            onChange={(event) => form.setData('responsible_user_id', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        >
                            <option value="">{ta.choose_responsible ?? 'Velg ansvarlig'}</option>
                            {responsibleOptions.map((person) => (
                                <option key={person.id} value={person.id}>{person.name}</option>
                            ))}
                        </select>
                        <p id="compliance-audit-responsible-hint" className={HINT}>
                            {ta.field_responsible_hint ?? 'Personen i virksomheten som har ansvaret for revisjonen.'}
                        </p>
                        <FieldError field="compliance-audit-responsible" message={form.errors.responsible_user_id} />
                    </div>
                )}
            </div>

            {has('auditor_name') && (
                <div>
                    <label htmlFor="compliance-audit-auditor" className={LABEL}>{ta.field_auditor ?? 'Revisor'}</label>
                    <input
                        id="compliance-audit-auditor"
                        type="text"
                        maxLength={255}
                        {...fieldStateProps('compliance-audit-auditor', form.errors.auditor_name, ['compliance-audit-auditor-hint'])}
                        value={form.data.auditor_name}
                        onChange={(event) => form.setData('auditor_name', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <p id="compliance-audit-auditor-hint" className={HINT}>{ta.field_auditor_hint ?? 'Valgfritt. Navn på revisor eller revisjonsselskap.'}</p>
                    <FieldError field="compliance-audit-auditor" message={form.errors.auditor_name} />
                </div>
            )}

            {has('planned_end_date') && (
                <div>
                    <div className="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label htmlFor="compliance-audit-start" className={LABEL}>{ta.field_start ?? 'Planlagt start'}</label>
                            <input
                                id="compliance-audit-start"
                                type="date"
                                {...fieldStateProps('compliance-audit-start', form.errors.planned_start_date, [dateHint])}
                                value={form.data.planned_start_date}
                                onChange={(event) => form.setData('planned_start_date', event.target.value)}
                                className={`mt-1 ${INPUT}`}
                            />
                            <FieldError field="compliance-audit-start" message={form.errors.planned_start_date} />
                        </div>
                        <div>
                            <label htmlFor="compliance-audit-end" className={LABEL}>{ta.field_end ?? 'Planlagt slutt'}<RequiredMark /></label>
                            <input
                                id="compliance-audit-end"
                                type="date"
                                required
                                aria-required="true"
                                {...fieldStateProps('compliance-audit-end', form.errors.planned_end_date, [dateHint])}
                                min={form.data.planned_start_date || undefined}
                                value={form.data.planned_end_date}
                                onChange={(event) => form.setData('planned_end_date', event.target.value)}
                                className={`mt-1 ${INPUT}`}
                            />
                            <FieldError field="compliance-audit-end" message={form.errors.planned_end_date} />
                        </div>
                    </div>
                    <p id="compliance-audit-dates-hint" className={HINT}>{ta.field_dates_hint ?? 'Planlagt slutt er påkrevd. Slutt kan ikke være før start.'}</p>
                </div>
            )}

            {has('purpose') && (
                <div>
                    <label htmlFor="compliance-audit-purpose" className={LABEL}>{ta.field_purpose ?? 'Formål'}</label>
                    <p id="compliance-audit-purpose-hint" className={HINT}>{ta.field_purpose_hint ?? 'Valgfritt. Hvorfor revisjonen gjennomføres.'}</p>
                    <textarea
                        id="compliance-audit-purpose"
                        rows={3}
                        {...fieldStateProps('compliance-audit-purpose', form.errors.purpose, ['compliance-audit-purpose-hint'])}
                        value={form.data.purpose}
                        onChange={(event) => form.setData('purpose', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <FieldError field="compliance-audit-purpose" message={form.errors.purpose} />
                </div>
            )}

            {has('scope_description') && (
                <div>
                    <label htmlFor="compliance-audit-scope" className={LABEL}>{ta.field_scope ?? 'Omfang'}</label>
                    <p id="compliance-audit-scope-hint" className={HINT}>{ta.field_scope_hint ?? 'Valgfritt. Hva revisjonen omfatter. Beskriv med tekst, velg krav, eller begge deler.'}</p>
                    <textarea
                        id="compliance-audit-scope"
                        rows={4}
                        {...fieldStateProps('compliance-audit-scope', form.errors.scope_description, ['compliance-audit-scope-hint'])}
                        value={form.data.scope_description}
                        onChange={(event) => form.setData('scope_description', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <FieldError field="compliance-audit-scope" message={form.errors.scope_description} />
                </div>
            )}

            {has('conclusion') && (
                <div>
                    <label htmlFor="compliance-audit-conclusion" className={LABEL}>{ta.field_conclusion ?? 'Konklusjon'}</label>
                    <p id="compliance-audit-conclusion-hint" className={HINT}>{ta.field_conclusion_hint ?? 'Revisjonens samlede vurdering. Må fylles ut før revisjonen kan fullføres.'}</p>
                    <textarea
                        id="compliance-audit-conclusion"
                        rows={4}
                        {...fieldStateProps('compliance-audit-conclusion', form.errors.conclusion, ['compliance-audit-conclusion-hint'])}
                        value={form.data.conclusion}
                        onChange={(event) => form.setData('conclusion', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <FieldError field="compliance-audit-conclusion" message={form.errors.conclusion} />
                </div>
            )}

            {children}

            <p className="text-base text-slate-600">{ta.required_note ?? 'Felt merket med * må fylles ut.'}</p>

            <div className="flex flex-wrap justify-end gap-3">
                {onCancel && (
                    <button type="button" onClick={onCancel} className={SECONDARY_ACTION}>{ta.cancel ?? 'Avbryt'}</button>
                )}
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                    {form.processing ? (ta.saving ?? 'Lagrer...') : (ta.save ?? 'Lagre')}
                </button>
            </div>
        </form>
    );
}
