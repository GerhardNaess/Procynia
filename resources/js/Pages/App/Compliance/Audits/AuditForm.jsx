import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import RequiredMark from '../../Risk/RequiredMark';
import { auditTypeLabel } from './complianceAudit';

const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';
const HINT = 'mt-1 text-base text-slate-600';
const ERROR = 'mt-1 text-base text-rose-700';

const ALL_FIELDS = ['title', 'audit_type', 'responsible_user_id', 'auditor_name', 'planned_start_date', 'planned_end_date', 'scope_description'];

/**
 * The fields of an audit, shared by Ny revisjon and Rediger. Only the fields the server says the
 * audit's status leaves open are drawn (`fields`); the server reads nothing else either. There is
 * no status field — an audit moves only through Start, Fullfør, Avbryt and Gjenåpne — and the
 * conclusion is offered only once the audit is under way.
 */
export default function AuditForm({ form, onSubmit, onCancel, fields = ALL_FIELDS, types = [], responsibleOptions = [], ta }) {
    const has = (field) => fields.includes(field);

    return (
        <form onSubmit={onSubmit} className="space-y-5" data-testid="compliance-audit-form">
            {has('title') && (
                <div>
                    <label htmlFor="compliance-audit-title" className={LABEL}>{ta.field_title ?? 'Tittel'}<RequiredMark /></label>
                    <p id="compliance-audit-title-hint" className={HINT}>{ta.field_title_hint ?? 'For eksempel «Internrevisjon tilgangsstyring 2026».'}</p>
                    <input
                        id="compliance-audit-title"
                        type="text"
                        required
                        aria-required="true"
                        aria-describedby="compliance-audit-title-hint"
                        maxLength={255}
                        value={form.data.title}
                        onChange={(event) => form.setData('title', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {form.errors.title && <p className={ERROR}>{form.errors.title}</p>}
                </div>
            )}

            <div className="grid gap-5 md:grid-cols-2">
                {has('audit_type') && (
                    <fieldset>
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
                        {form.errors.audit_type && <p className={ERROR}>{form.errors.audit_type}</p>}
                    </fieldset>
                )}

                {has('responsible_user_id') && (
                    <div>
                        <label htmlFor="compliance-audit-responsible" className={LABEL}>{ta.field_responsible ?? 'Ansvarlig'}<RequiredMark /></label>
                        <select
                            id="compliance-audit-responsible"
                            required
                            aria-required="true"
                            aria-describedby="compliance-audit-responsible-hint"
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
                        {form.errors.responsible_user_id && <p className={ERROR}>{form.errors.responsible_user_id}</p>}
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
                        aria-describedby="compliance-audit-auditor-hint"
                        value={form.data.auditor_name}
                        onChange={(event) => form.setData('auditor_name', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <p id="compliance-audit-auditor-hint" className={HINT}>{ta.field_auditor_hint ?? 'Valgfritt. Navn på revisor eller revisjonsselskap.'}</p>
                    {form.errors.auditor_name && <p className={ERROR}>{form.errors.auditor_name}</p>}
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
                                aria-describedby="compliance-audit-dates-hint"
                                value={form.data.planned_start_date}
                                onChange={(event) => form.setData('planned_start_date', event.target.value)}
                                className={`mt-1 ${INPUT}`}
                            />
                            {form.errors.planned_start_date && <p className={ERROR}>{form.errors.planned_start_date}</p>}
                        </div>
                        <div>
                            <label htmlFor="compliance-audit-end" className={LABEL}>{ta.field_end ?? 'Planlagt slutt'}<RequiredMark /></label>
                            <input
                                id="compliance-audit-end"
                                type="date"
                                required
                                aria-required="true"
                                aria-describedby="compliance-audit-dates-hint"
                                min={form.data.planned_start_date || undefined}
                                value={form.data.planned_end_date}
                                onChange={(event) => form.setData('planned_end_date', event.target.value)}
                                className={`mt-1 ${INPUT}`}
                            />
                            {form.errors.planned_end_date && <p className={ERROR}>{form.errors.planned_end_date}</p>}
                        </div>
                    </div>
                    <p id="compliance-audit-dates-hint" className={HINT}>{ta.field_dates_hint ?? 'Planlagt slutt er påkrevd. Slutt kan ikke være før start.'}</p>
                </div>
            )}

            {has('scope_description') && (
                <div>
                    <label htmlFor="compliance-audit-scope" className={LABEL}>{ta.field_scope ?? 'Scope'}<RequiredMark /></label>
                    <p id="compliance-audit-scope-hint" className={HINT}>{ta.field_scope_hint ?? 'Hva revisjonen omfatter og avgrenses til.'}</p>
                    <textarea
                        id="compliance-audit-scope"
                        rows={4}
                        required
                        aria-required="true"
                        aria-describedby="compliance-audit-scope-hint"
                        value={form.data.scope_description}
                        onChange={(event) => form.setData('scope_description', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {form.errors.scope_description && <p className={ERROR}>{form.errors.scope_description}</p>}
                </div>
            )}

            {has('conclusion') && (
                <div>
                    <label htmlFor="compliance-audit-conclusion" className={LABEL}>{ta.field_conclusion ?? 'Konklusjon'}</label>
                    <p id="compliance-audit-conclusion-hint" className={HINT}>{ta.field_conclusion_hint ?? 'Revisjonens samlede vurdering. Må fylles ut før revisjonen kan fullføres.'}</p>
                    <textarea
                        id="compliance-audit-conclusion"
                        rows={4}
                        aria-describedby="compliance-audit-conclusion-hint"
                        value={form.data.conclusion}
                        onChange={(event) => form.setData('conclusion', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {form.errors.conclusion && <p className={ERROR}>{form.errors.conclusion}</p>}
                </div>
            )}

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
