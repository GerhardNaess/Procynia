import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import RequiredMark from '../Risk/RequiredMark';
import { ownersForArea } from '../Risk/riskOwners';

const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-sm font-semibold text-slate-700';

/**
 * The fields of an objective, shared by Nytt mål and Rediger. The area list holds only the areas the
 * person may edit in, and the owner list follows the chosen area — both come from the server,
 * which checks them again on save. There is no status field: an objective is created active and
 * leaves that state only by being closed.
 */
export default function ObjectiveForm({ form, onSubmit, onCancel, areaOptions, ownerOptions, tr }) {
    const owners = ownersForArea(ownerOptions, form.data.business_area_id);

    const changeArea = (value) => {
        const stillAllowed = ownersForArea(ownerOptions, value).some((owner) => String(owner.id) === String(form.data.owner_user_id));

        form.setData((data) => ({
            ...data,
            business_area_id: value,
            owner_user_id: stillAllowed ? data.owner_user_id : '',
        }));
    };

    return (
        <form onSubmit={onSubmit} className="space-y-4">
            <div>
                <label htmlFor="objective-title" className={LABEL}>{tr.field_title ?? 'Tittel'}<RequiredMark /></label>
                <input
                    id="objective-title"
                    type="text"
                    required
                    aria-required="true"
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.title && <p className="mt-1 text-sm text-rose-600">{form.errors.title}</p>}
            </div>

            <div>
                <label htmlFor="objective-description" className={LABEL}>{tr.field_description ?? 'Beskrivelse'}</label>
                <p id="objective-description-hint" className="text-sm text-slate-600">
                    {tr.field_description_hint ?? 'Valgfritt. Hva målet innebærer og hvorfor det er viktig.'}
                </p>
                <textarea
                    id="objective-description"
                    rows={3}
                    aria-describedby="objective-description-hint"
                    value={form.data.description}
                    onChange={(event) => form.setData('description', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.description && <p className="mt-1 text-sm text-rose-600">{form.errors.description}</p>}
            </div>

            <div className="grid gap-4 md:grid-cols-3">
                <div>
                    <label htmlFor="objective-area" className={LABEL}>{tr.field_area ?? 'Fagområde'}<RequiredMark /></label>
                    <select
                        id="objective-area"
                        required
                        aria-required="true"
                        value={form.data.business_area_id}
                        onChange={(event) => changeArea(event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{tr.choose_area ?? 'Velg fagområde'}</option>
                        {areaOptions.map((area) => (
                            <option key={area.id} value={area.id}>{area.name}</option>
                        ))}
                    </select>
                    <p className="mt-1 text-sm text-slate-500">{tr.field_area_hint ?? 'Bestemmer hvem som kan se målet.'}</p>
                    {form.errors.business_area_id && <p className="mt-1 text-sm text-rose-600">{form.errors.business_area_id}</p>}
                </div>

                <div>
                    <label htmlFor="objective-owner" className={LABEL}>{tr.field_owner ?? 'Ansvarlig'}<RequiredMark /></label>
                    <select
                        id="objective-owner"
                        required
                        aria-required="true"
                        value={form.data.owner_user_id}
                        onChange={(event) => form.setData('owner_user_id', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{tr.choose_owner ?? 'Velg ansvarlig'}</option>
                        {owners.map((owner) => (
                            <option key={owner.id} value={owner.id}>{owner.name}</option>
                        ))}
                    </select>
                    <p className="mt-1 text-sm text-slate-500">
                        {tr.field_owner_hint ?? 'Bare personer som kan se mål i valgt fagområde kan være ansvarlig.'}
                    </p>
                    {form.errors.owner_user_id && <p className="mt-1 text-sm text-rose-600">{form.errors.owner_user_id}</p>}
                </div>

                <div>
                    <label htmlFor="objective-target-date" className={LABEL}>{tr.field_target_date ?? 'Måldato'}</label>
                    <input
                        id="objective-target-date"
                        type="date"
                        aria-describedby="objective-target-date-hint"
                        value={form.data.target_date}
                        onChange={(event) => form.setData('target_date', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <p id="objective-target-date-hint" className="mt-1 text-sm text-slate-500">
                        {tr.field_target_date_hint ?? 'Valgfritt. La stå tom for et løpende mål.'}
                    </p>
                    {form.errors.target_date && <p className="mt-1 text-sm text-rose-600">{form.errors.target_date}</p>}
                </div>
            </div>

            <p className="text-sm text-slate-500">{tr.required_note ?? 'Felt merket med * må fylles ut.'}</p>

            <div className="flex flex-wrap justify-end gap-3">
                {onCancel && (
                    <button type="button" onClick={onCancel} className={SECONDARY_ACTION}>
                        {tr.cancel ?? 'Avbryt'}
                    </button>
                )}
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (tr.save ?? 'Lagre')}
                </button>
            </div>
        </form>
    );
}
