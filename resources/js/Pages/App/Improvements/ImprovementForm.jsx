import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import RequiredMark from '../Risk/RequiredMark';
import { ownersForArea } from '../Risk/riskOwners';
import { descriptionHint } from './improvementStatus';

const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';
const HINT = 'mt-1 text-base text-slate-600';
const ERROR = 'mt-1 text-base text-rose-700';

/**
 * The fields of an avvik or a forbedring, shared by Registrer and Rediger. Kept short on purpose:
 * first the case gets in, then it is handled. The area list holds only the areas the person may
 * edit in, and the owner list follows the chosen area — both from the server, which checks them
 * again on save. There is no status field: a case is registered open and moves only through the
 * lifecycle buttons. Hendelsesdato belongs to an avvik and is not asked for a forbedring.
 */
export default function ImprovementForm({ form, onSubmit, onCancel, types = [], areaOptions, ownerOptions, today, tr }) {
    const owners = ownersForArea(ownerOptions, form.data.business_area_id);
    const typeLabels = tr.types ?? {};
    const isDeviation = form.data.type !== 'improvement';

    const changeArea = (value) => {
        const stillAllowed = ownersForArea(ownerOptions, value).some((owner) => String(owner.id) === String(form.data.owner_user_id));

        form.setData((data) => ({
            ...data,
            business_area_id: value,
            owner_user_id: stillAllowed ? data.owner_user_id : '',
        }));
    };

    const changeType = (value) => {
        form.setData((data) => ({
            ...data,
            type: value,
            occurred_at: value === 'improvement' ? '' : data.occurred_at,
        }));
    };

    return (
        <form onSubmit={onSubmit} className="space-y-5">
            <fieldset>
                <legend className={LABEL}>{tr.field_type ?? 'Type'}<RequiredMark /></legend>
                <div className="mt-2 flex flex-wrap gap-x-6 gap-y-2">
                    {types.map((type) => (
                        <label key={type} className="flex min-h-10 items-center gap-2 text-base text-slate-900">
                            <input
                                type="radio"
                                id={`improvement-type-${type}`}
                                name="improvement-type"
                                value={type}
                                checked={form.data.type === type}
                                onChange={() => changeType(type)}
                                required
                                className="h-5 w-5"
                            />
                            {typeLabels[type] ?? type}
                        </label>
                    ))}
                </div>
                {form.errors.type && <p className={ERROR}>{form.errors.type}</p>}
            </fieldset>

            <div>
                <label htmlFor="improvement-title" className={LABEL}>{tr.field_title ?? 'Tittel'}<RequiredMark /></label>
                <p id="improvement-title-hint" className={HINT}>{tr.field_title_hint ?? 'Kort beskrivelse av saken.'}</p>
                <input
                    id="improvement-title"
                    type="text"
                    required
                    aria-required="true"
                    aria-describedby="improvement-title-hint"
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.title && <p className={ERROR}>{form.errors.title}</p>}
            </div>

            <div>
                <label htmlFor="improvement-description" className={LABEL}>{tr.field_description ?? 'Beskrivelse'}<RequiredMark /></label>
                <p id="improvement-description-hint" className={HINT}>{descriptionHint(form.data.type, tr)}</p>
                <textarea
                    id="improvement-description"
                    rows={4}
                    required
                    aria-required="true"
                    aria-describedby="improvement-description-hint"
                    value={form.data.description}
                    onChange={(event) => form.setData('description', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.description && <p className={ERROR}>{form.errors.description}</p>}
            </div>

            <div className="grid gap-5 md:grid-cols-2">
                <div>
                    <label htmlFor="improvement-area" className={LABEL}>{tr.field_area ?? 'Fagområde'}<RequiredMark /></label>
                    <select
                        id="improvement-area"
                        required
                        aria-required="true"
                        aria-describedby="improvement-area-hint"
                        value={form.data.business_area_id}
                        onChange={(event) => changeArea(event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{tr.choose_area ?? 'Velg fagområde'}</option>
                        {areaOptions.map((area) => (
                            <option key={area.id} value={area.id}>{area.name}</option>
                        ))}
                    </select>
                    <p id="improvement-area-hint" className={HINT}>{tr.field_area_hint ?? 'Bestemmer hvem som kan se saken.'}</p>
                    {form.errors.business_area_id && <p className={ERROR}>{form.errors.business_area_id}</p>}
                </div>

                <div>
                    <label htmlFor="improvement-owner" className={LABEL}>{tr.field_owner ?? 'Ansvarlig'}<RequiredMark /></label>
                    <select
                        id="improvement-owner"
                        required
                        aria-required="true"
                        aria-describedby="improvement-owner-hint"
                        value={form.data.owner_user_id}
                        onChange={(event) => form.setData('owner_user_id', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{tr.choose_owner ?? 'Velg ansvarlig'}</option>
                        {owners.map((owner) => (
                            <option key={owner.id} value={owner.id}>{owner.name}</option>
                        ))}
                    </select>
                    <p id="improvement-owner-hint" className={HINT}>
                        {tr.field_owner_hint ?? 'Personen som følger opp saken. Bare personer som kan se saker i valgt fagområde kan velges.'}
                    </p>
                    {form.errors.owner_user_id && <p className={ERROR}>{form.errors.owner_user_id}</p>}
                </div>

                {isDeviation && (
                    <div>
                        <label htmlFor="improvement-occurred-at" className={LABEL}>{tr.field_occurred_at ?? 'Hendelsesdato'}</label>
                        <input
                            id="improvement-occurred-at"
                            type="date"
                            max={today}
                            aria-describedby="improvement-occurred-at-hint"
                            value={form.data.occurred_at}
                            onChange={(event) => form.setData('occurred_at', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        />
                        <p id="improvement-occurred-at-hint" className={HINT}>{tr.field_occurred_at_hint ?? 'Valgfritt. Når skjedde dette?'}</p>
                        {form.errors.occurred_at && <p className={ERROR}>{form.errors.occurred_at}</p>}
                    </div>
                )}

                <div>
                    <label htmlFor="improvement-due-date" className={LABEL}>{tr.field_due_date ?? 'Frist'}</label>
                    <input
                        id="improvement-due-date"
                        type="date"
                        aria-describedby="improvement-due-date-hint"
                        value={form.data.due_date}
                        onChange={(event) => form.setData('due_date', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <p id="improvement-due-date-hint" className={HINT}>{tr.field_due_date_hint ?? 'Valgfritt. Når saken bør være behandlet.'}</p>
                    {form.errors.due_date && <p className={ERROR}>{form.errors.due_date}</p>}
                </div>
            </div>

            <p className="text-base text-slate-600">{tr.required_note ?? 'Felt merket med * må fylles ut.'}</p>

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
