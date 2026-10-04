import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { ownersForArea } from './riskOwners';

const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-sm font-semibold text-slate-700';

/**
 * The fields of a risk, shared by Ny risiko and Rediger. The area list holds only the areas the
 * person may create (or edit) in, and the owner list follows the chosen area — both come from the
 * server, which checks them again on save.
 */
export default function RiskForm({ form, onSubmit, onCancel, areaOptions, ownerOptions, statuses, statusLabels, reviewIntervals = [], tr }) {
    const trReview = tr.review ?? {};
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
                <label htmlFor="risk-title" className={LABEL}>{tr.field_title ?? 'Tittel'}</label>
                <input
                    id="risk-title"
                    type="text"
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.title && <p className="mt-1 text-sm text-rose-600">{form.errors.title}</p>}
            </div>

            <div>
                <label htmlFor="risk-description" className={LABEL}>{tr.field_description ?? 'Kort beskrivelse'}</label>
                <textarea
                    id="risk-description"
                    rows={3}
                    value={form.data.description}
                    onChange={(event) => form.setData('description', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.description && <p className="mt-1 text-sm text-rose-600">{form.errors.description}</p>}
            </div>

            <div className="grid gap-4 md:grid-cols-3">
                <div>
                    <label htmlFor="risk-area" className={LABEL}>{tr.field_area ?? 'Fagområde'}</label>
                    <select
                        id="risk-area"
                        value={form.data.business_area_id}
                        onChange={(event) => changeArea(event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{tr.choose_area ?? 'Velg område'}</option>
                        {areaOptions.map((area) => (
                            <option key={area.id} value={area.id}>{area.name}</option>
                        ))}
                    </select>
                    <p className="mt-1 text-sm text-slate-500">{tr.field_area_hint ?? 'Bestemmer hvem som kan se risikoen.'}</p>
                    {form.errors.business_area_id && <p className="mt-1 text-sm text-rose-600">{form.errors.business_area_id}</p>}
                </div>

                <div>
                    <label htmlFor="risk-owner" className={LABEL}>{tr.field_owner ?? 'Risikoeier'}</label>
                    <select
                        id="risk-owner"
                        value={form.data.owner_user_id}
                        onChange={(event) => form.setData('owner_user_id', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{tr.no_owner ?? 'Ingen eier valgt'}</option>
                        {owners.map((owner) => (
                            <option key={owner.id} value={owner.id}>{owner.name}</option>
                        ))}
                    </select>
                    <p className="mt-1 text-sm text-slate-500">
                        {tr.field_owner_hint ?? 'Bare personer som kan se risikoer i valgt område kan være eier.'}
                    </p>
                    {form.errors.owner_user_id && <p className="mt-1 text-sm text-rose-600">{form.errors.owner_user_id}</p>}
                </div>

                <div>
                    <label htmlFor="risk-status" className={LABEL}>{tr.field_status ?? 'Status'}</label>
                    <select
                        id="risk-status"
                        value={form.data.status}
                        onChange={(event) => form.setData('status', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        {statuses.map((status) => (
                            <option key={status} value={status}>{statusLabels[status] ?? status}</option>
                        ))}
                    </select>
                    {form.errors.status && <p className="mt-1 text-sm text-rose-600">{form.errors.status}</p>}
                </div>
            </div>

            <div className="md:w-1/3 md:pr-3">
                <label htmlFor="risk-review-interval" className={LABEL}>{trReview.field_interval ?? 'Vurderingsintervall'}</label>
                <select
                    id="risk-review-interval"
                    value={form.data.review_interval_months}
                    onChange={(event) => form.setData('review_interval_months', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                >
                    <option value="">{trReview.no_interval ?? 'Ingen fast intervall'}</option>
                    {reviewIntervals.map((months) => (
                        <option key={months} value={months}>{trReview.intervals?.[months] ?? months}</option>
                    ))}
                </select>
                <p className="mt-1 text-sm text-slate-500">
                    {trReview.field_interval_hint ?? 'Hvor ofte risikoen skal vurderes på nytt. Neste vurdering beregnes fra siste risikovurdering.'}
                </p>
                {form.errors.review_interval_months && <p className="mt-1 text-sm text-rose-600">{form.errors.review_interval_months}</p>}
            </div>

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
