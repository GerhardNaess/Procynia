import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { INPUT, TEXTAREA } from '../../../Support/formStyles';
import CriticalityFields from './CriticalityFields';
import SupplierField, { BUSY, FieldError, RequiredNote, RequiredStar, groupA11y } from './SupplierField';
import { categoryLabel, statusLabel } from './supplierManagement';

const LABEL = 'block text-base font-semibold text-slate-700';

/**
 * The master data of a supplier, shared by Registrer leverandør and Rediger. The owner list comes
 * from the server, which checks it again on save.
 *
 * There is no status field. Only when registering does the form ask whether the supplier is
 * already in use (initialStatuses given): that decides whether it starts as Aktiv or Under
 * vurdering. After that, status moves only through Ta i bruk, Avslutt and Gjenåpne.
 *
 * Likewise criticality: registering asks how important the supplier is (reviewIntervals given);
 * Rediger never does. After that it changes only through Endre kritikalitet, with a begrunnelse.
 *
 * Every field is tied to its hint and error (A11Y-013); `formRef` is the form element the caller's
 * useFocusFirstError looks in after a rejected save.
 */
export default function SupplierForm({ form, onSubmit, onCancel, categories = [], ownerOptions = [], initialStatuses = null, reviewIntervals = null, formRef = null, tr }) {
    const fields = tr.fields ?? {};
    const errors = form.errors;
    const initialStatus = groupA11y('supplier-initial-status', { error: errors.initial_status });

    return (
        <form ref={formRef} onSubmit={onSubmit} className="space-y-5">
            <div className="grid gap-5 md:grid-cols-2">
                <SupplierField id="supplier-name" label={fields.name ?? 'Navn'} labelClassName={LABEL} hint={fields.name_hint ?? 'Virksomhetens navn.'} hintFirst={false} error={errors.name} required>
                    {(field) => (
                        <input
                            {...field}
                            type="text"
                            required
                            maxLength={255}
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        />
                    )}
                </SupplierField>

                <SupplierField
                    id="supplier-organization-number"
                    label={fields.organization_number ?? 'Organisasjonsnummer'}
                    labelClassName={LABEL}
                    hint={fields.organization_number_hint ?? 'Valgfritt. Samme nummer kan bare registreres én gang.'}
                    hintFirst={false}
                    error={errors.organization_number}
                >
                    {(field) => (
                        <input
                            {...field}
                            type="text"
                            maxLength={50}
                            value={form.data.organization_number}
                            onChange={(event) => form.setData('organization_number', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        />
                    )}
                </SupplierField>
            </div>

            <SupplierField id="supplier-category" label={fields.category ?? 'Kategori'} labelClassName={LABEL} error={errors.category} required>
                {(field) => (
                    <select
                        {...field}
                        required
                        value={form.data.category}
                        onChange={(event) => form.setData('category', event.target.value)}
                        className={`mt-1 ${INPUT} md:max-w-md`}
                    >
                        <option value="">{fields.choose_category ?? 'Velg kategori'}</option>
                        {categories.map((category) => (
                            <option key={category} value={category}>{categoryLabel(category, tr)}</option>
                        ))}
                    </select>
                )}
            </SupplierField>

            <SupplierField
                id="supplier-deliverable"
                label={fields.deliverable_description ?? 'Hva leverer de til oss?'}
                labelClassName={LABEL}
                hint={fields.deliverable_description_hint ?? 'For eksempel «Drift av lønnssystem». Leverer de flere ting, beskriv alt her.'}
                error={errors.deliverable_description}
                required
            >
                {(field) => (
                    <textarea
                        {...field}
                        rows={3}
                        required
                        value={form.data.deliverable_description}
                        onChange={(event) => form.setData('deliverable_description', event.target.value)}
                        className={`mt-1 ${TEXTAREA}`}
                    />
                )}
            </SupplierField>

            <SupplierField
                id="supplier-owner"
                label={fields.owner ?? 'Intern ansvarlig'}
                labelClassName={LABEL}
                hint={fields.owner_hint ?? 'Personen hos dere som følger opp leverandøren. Ansvaret gir ingen ekstra tilgang.'}
                hintFirst={false}
                error={errors.owner_user_id}
                required
            >
                {(field) => (
                    <select
                        {...field}
                        required
                        value={form.data.owner_user_id}
                        onChange={(event) => form.setData('owner_user_id', event.target.value)}
                        className={`mt-1 ${INPUT} md:max-w-md`}
                    >
                        <option value="">{fields.choose_owner ?? 'Velg intern ansvarlig'}</option>
                        {ownerOptions.map((owner) => (
                            <option key={owner.id} value={owner.id}>{owner.name}</option>
                        ))}
                    </select>
                )}
            </SupplierField>

            <fieldset className="space-y-3" aria-describedby="supplier-contact-hint">
                <legend className="text-base font-semibold text-slate-900">{fields.contact_heading ?? 'Kontaktperson hos leverandøren'}</legend>
                <p id="supplier-contact-hint" className="text-base text-slate-600">{fields.contact_hint ?? 'Valgfritt. Én kontaktperson.'}</p>
                <div className="grid gap-5 md:grid-cols-3">
                    <SupplierField id="supplier-contact-name" label={fields.contact_name ?? 'Navn'} labelClassName={LABEL} error={errors.contact_name}>
                        {(field) => (
                            <input {...field} type="text" maxLength={255} value={form.data.contact_name} onChange={(event) => form.setData('contact_name', event.target.value)} className={`mt-1 ${INPUT}`} />
                        )}
                    </SupplierField>
                    <SupplierField id="supplier-contact-email" label={fields.contact_email ?? 'E-post'} labelClassName={LABEL} error={errors.contact_email}>
                        {(field) => (
                            <input {...field} type="email" maxLength={255} value={form.data.contact_email} onChange={(event) => form.setData('contact_email', event.target.value)} className={`mt-1 ${INPUT}`} />
                        )}
                    </SupplierField>
                    <SupplierField id="supplier-contact-phone" label={fields.contact_phone ?? 'Telefon'} labelClassName={LABEL} error={errors.contact_phone}>
                        {(field) => (
                            <input {...field} type="tel" maxLength={50} value={form.data.contact_phone} onChange={(event) => form.setData('contact_phone', event.target.value)} className={`mt-1 ${INPUT}`} />
                        )}
                    </SupplierField>
                </div>
            </fieldset>

            <SupplierField id="supplier-note" label={fields.note ?? 'Notat'} labelClassName={LABEL} hint={fields.note_hint ?? 'Valgfritt.'} error={errors.note}>
                {(field) => (
                    <textarea {...field} rows={3} value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} className={`mt-1 ${TEXTAREA}`} />
                )}
            </SupplierField>

            {initialStatuses && (
                <fieldset className="space-y-2" data-testid="supplier-initial-status" {...initialStatus.groupProps}>
                    <legend className="text-base font-semibold text-slate-700">{fields.initial_status ?? 'Er leverandøren i bruk?'}<RequiredStar /></legend>
                    {initialStatuses.map((status) => (
                        <label key={status} className="flex items-start gap-3 rounded-xl border border-slate-200 px-3 py-2">
                            <input
                                type="radio"
                                name="initial_status"
                                value={status}
                                checked={form.data.initial_status === status}
                                onChange={() => form.setData('initial_status', status)}
                                className="mt-1 h-5 w-5 shrink-0"
                                {...initialStatus.optionProps}
                            />
                            <span className="min-w-0">
                                <span className="block text-base font-semibold text-slate-900">{fields.initial_statuses?.[status] ?? statusLabel(status, tr)}</span>
                                <span className="block text-base text-slate-600">{fields.initial_status_hints?.[status] ?? ''}</span>
                            </span>
                        </label>
                    ))}
                    <FieldError id={initialStatus.errorId} message={errors.initial_status} />
                </fieldset>
            )}

            {reviewIntervals && (
                <section className="space-y-3 border-t border-slate-100 pt-5" aria-labelledby="supplier-criticality-registration-heading">
                    <h3 id="supplier-criticality-registration-heading" className="text-lg font-semibold text-slate-950">
                        {tr.criticality?.registration_heading ?? 'Hvor viktig er leverandøren for oss?'}
                    </h3>
                    <p className="text-base text-slate-600">
                        {tr.criticality?.form_intro ?? 'Svar på de fire spørsmålene, og velg deretter nivået selv. Svarene er grunnlaget for valget ditt og lagres sammen med det.'}
                    </p>
                    <CriticalityFields form={form} reviewIntervals={reviewIntervals} idPrefix="supplier-registration" tr={tr} />
                </section>
            )}

            <RequiredNote tr={tr} />

            <div className="flex flex-wrap justify-end gap-3">
                {onCancel && (
                    <button type="button" onClick={onCancel} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                )}
                <button type="submit" aria-disabled={form.processing || undefined} className={`${PRIMARY_ACTION} ${BUSY}`}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (tr.save ?? 'Lagre')}
                </button>
            </div>
        </form>
    );
}
