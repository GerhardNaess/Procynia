import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import RequiredMark from '../Risk/RequiredMark';
import { categoryLabel, statusLabel } from './supplierManagement';

const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';
const HINT = 'mt-1 text-base text-slate-600';
const ERROR = 'mt-1 text-base text-rose-700';

function FieldError({ message }) {
    return message ? <p className={ERROR}>{message}</p> : null;
}

/**
 * The master data of a supplier, shared by Registrer leverandør and Rediger. The owner list comes
 * from the server, which checks it again on save.
 *
 * There is no status field. Only when registering does the form ask whether the supplier is
 * already in use (initialStatuses given): that decides whether it starts as Aktiv or Under
 * vurdering. After that, status moves only through Ta i bruk, Avslutt and Gjenåpne.
 */
export default function SupplierForm({ form, onSubmit, onCancel, categories = [], ownerOptions = [], initialStatuses = null, tr }) {
    const fields = tr.fields ?? {};
    const errors = form.errors;

    return (
        <form onSubmit={onSubmit} className="space-y-5">
            <div className="grid gap-5 md:grid-cols-2">
                <div>
                    <label htmlFor="supplier-name" className={LABEL}>{fields.name ?? 'Navn'}<RequiredMark /></label>
                    <input
                        id="supplier-name"
                        type="text"
                        required
                        aria-required="true"
                        maxLength={255}
                        aria-describedby="supplier-name-hint"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <p id="supplier-name-hint" className={HINT}>{fields.name_hint ?? 'Virksomhetens navn.'}</p>
                    <FieldError message={errors.name} />
                </div>

                <div>
                    <label htmlFor="supplier-organization-number" className={LABEL}>{fields.organization_number ?? 'Organisasjonsnummer'}</label>
                    <input
                        id="supplier-organization-number"
                        type="text"
                        maxLength={50}
                        aria-describedby="supplier-organization-number-hint"
                        value={form.data.organization_number}
                        onChange={(event) => form.setData('organization_number', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <p id="supplier-organization-number-hint" className={HINT}>
                        {fields.organization_number_hint ?? 'Valgfritt. Samme nummer kan bare registreres én gang.'}
                    </p>
                    <FieldError message={errors.organization_number} />
                </div>
            </div>

            <div>
                <label htmlFor="supplier-category" className={LABEL}>{fields.category ?? 'Kategori'}<RequiredMark /></label>
                <select
                    id="supplier-category"
                    required
                    aria-required="true"
                    value={form.data.category}
                    onChange={(event) => form.setData('category', event.target.value)}
                    className={`mt-1 ${INPUT} md:max-w-md`}
                >
                    <option value="">{fields.choose_category ?? 'Velg kategori'}</option>
                    {categories.map((category) => (
                        <option key={category} value={category}>{categoryLabel(category, tr)}</option>
                    ))}
                </select>
                <FieldError message={errors.category} />
            </div>

            <div>
                <label htmlFor="supplier-deliverable" className={LABEL}>{fields.deliverable_description ?? 'Hva leverer de til oss?'}<RequiredMark /></label>
                <p id="supplier-deliverable-hint" className={HINT}>
                    {fields.deliverable_description_hint ?? 'For eksempel «Drift av lønnssystem». Leverer de flere ting, beskriv alt her.'}
                </p>
                <textarea
                    id="supplier-deliverable"
                    rows={3}
                    required
                    aria-required="true"
                    aria-describedby="supplier-deliverable-hint"
                    value={form.data.deliverable_description}
                    onChange={(event) => form.setData('deliverable_description', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                <FieldError message={errors.deliverable_description} />
            </div>

            <div>
                <label htmlFor="supplier-owner" className={LABEL}>{fields.owner ?? 'Intern ansvarlig'}<RequiredMark /></label>
                <select
                    id="supplier-owner"
                    required
                    aria-required="true"
                    aria-describedby="supplier-owner-hint"
                    value={form.data.owner_user_id}
                    onChange={(event) => form.setData('owner_user_id', event.target.value)}
                    className={`mt-1 ${INPUT} md:max-w-md`}
                >
                    <option value="">{fields.choose_owner ?? 'Velg intern ansvarlig'}</option>
                    {ownerOptions.map((owner) => (
                        <option key={owner.id} value={owner.id}>{owner.name}</option>
                    ))}
                </select>
                <p id="supplier-owner-hint" className={HINT}>
                    {fields.owner_hint ?? 'Personen hos dere som følger opp leverandøren. Ansvaret gir ingen ekstra tilgang.'}
                </p>
                <FieldError message={errors.owner_user_id} />
            </div>

            <fieldset className="space-y-3">
                <legend className="text-base font-semibold text-slate-900">{fields.contact_heading ?? 'Kontaktperson hos leverandøren'}</legend>
                <p className="text-base text-slate-600">{fields.contact_hint ?? 'Valgfritt. Én kontaktperson.'}</p>
                <div className="grid gap-5 md:grid-cols-3">
                    <div>
                        <label htmlFor="supplier-contact-name" className={LABEL}>{fields.contact_name ?? 'Navn'}</label>
                        <input
                            id="supplier-contact-name"
                            type="text"
                            maxLength={255}
                            value={form.data.contact_name}
                            onChange={(event) => form.setData('contact_name', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        />
                        <FieldError message={errors.contact_name} />
                    </div>
                    <div>
                        <label htmlFor="supplier-contact-email" className={LABEL}>{fields.contact_email ?? 'E-post'}</label>
                        <input
                            id="supplier-contact-email"
                            type="email"
                            maxLength={255}
                            value={form.data.contact_email}
                            onChange={(event) => form.setData('contact_email', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        />
                        <FieldError message={errors.contact_email} />
                    </div>
                    <div>
                        <label htmlFor="supplier-contact-phone" className={LABEL}>{fields.contact_phone ?? 'Telefon'}</label>
                        <input
                            id="supplier-contact-phone"
                            type="tel"
                            maxLength={50}
                            value={form.data.contact_phone}
                            onChange={(event) => form.setData('contact_phone', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        />
                        <FieldError message={errors.contact_phone} />
                    </div>
                </div>
            </fieldset>

            <div>
                <label htmlFor="supplier-note" className={LABEL}>{fields.note ?? 'Notat'}</label>
                <p id="supplier-note-hint" className={HINT}>{fields.note_hint ?? 'Valgfritt.'}</p>
                <textarea
                    id="supplier-note"
                    rows={3}
                    aria-describedby="supplier-note-hint"
                    value={form.data.note}
                    onChange={(event) => form.setData('note', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                <FieldError message={errors.note} />
            </div>

            {initialStatuses && (
                <fieldset className="space-y-2" data-testid="supplier-initial-status">
                    <legend className="text-base font-semibold text-slate-700">{fields.initial_status ?? 'Er leverandøren i bruk?'}<RequiredMark /></legend>
                    {initialStatuses.map((status) => (
                        <label key={status} className="flex items-start gap-3 rounded-xl border border-slate-200 px-3 py-2">
                            <input
                                type="radio"
                                name="initial_status"
                                value={status}
                                checked={form.data.initial_status === status}
                                onChange={() => form.setData('initial_status', status)}
                                className="mt-1 h-5 w-5 shrink-0"
                            />
                            <span className="min-w-0">
                                <span className="block text-base font-semibold text-slate-900">{fields.initial_statuses?.[status] ?? statusLabel(status, tr)}</span>
                                <span className="block text-base text-slate-600">{fields.initial_status_hints?.[status] ?? ''}</span>
                            </span>
                        </label>
                    ))}
                    <FieldError message={errors.initial_status} />
                </fieldset>
            )}

            <p className="text-base text-slate-600">{tr.required_note ?? 'Felt merket med * må fylles ut.'}</p>

            <div className="flex flex-wrap justify-end gap-3">
                {onCancel && (
                    <button type="button" onClick={onCancel} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                )}
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (tr.save ?? 'Lagre')}
                </button>
            </div>
        </form>
    );
}
