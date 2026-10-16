import { useRef } from 'react';
import FormField, { RequiredFieldsNote } from '../../../Components/App/FormField';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { useFocusFirstError } from '../../../Support/focusManagement';
import { fieldA11y } from '../../../Support/formFieldA11y';
import { ERROR, INPUT, LABEL, TEXTAREA } from '../../../Support/formStyles';
import { ownersForArea } from '../Risk/riskOwners';

/**
 * The fields of an objective, shared by Nytt mål and Rediger. The area list holds only the areas the
 * person may edit in, and the owner list follows the chosen area — both come from the server,
 * which checks them again on save. There is no status field: an objective is created active and
 * leaves that state only by being closed.
 *
 * Every field is tied to its hint and error, and after a refused save focus goes to the first field
 * that is wrong (3.3.1, A11Y-013): onSubmit gets the request as its second argument, for the visit's
 * onError.
 */
export default function ObjectiveForm({ form, onSubmit, onCancel, areaOptions, ownerOptions, tr }) {
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);
    const owners = ownersForArea(ownerOptions, form.data.business_area_id);
    // The description's hint sits above the field, as before; wired by hand the way FormField does it.
    const description = fieldA11y({ id: 'objective-description', hasHint: true, hasError: Boolean(form.errors.description) });

    const changeArea = (value) => {
        const stillAllowed = ownersForArea(ownerOptions, value).some((owner) => String(owner.id) === String(form.data.owner_user_id));

        form.setData((data) => ({
            ...data,
            business_area_id: value,
            owner_user_id: stillAllowed ? data.owner_user_id : '',
        }));
    };

    return (
        <form ref={formRef} onSubmit={(event) => onSubmit(event, focusFirstError)} className="space-y-4">
            <FormField id="objective-title" label={tr.field_title ?? 'Tittel'} error={form.errors.title} required>
                {(field) => (
                    <input
                        {...field}
                        type="text"
                        required
                        value={form.data.title}
                        onChange={(event) => form.setData('title', event.target.value)}
                        className={INPUT}
                    />
                )}
            </FormField>

            <div>
                <label htmlFor="objective-description" className={LABEL}>{tr.field_description ?? 'Beskrivelse'}</label>
                <p id={description.hintId} className="text-base text-slate-600">
                    {tr.field_description_hint ?? 'Valgfritt. Hva målet innebærer og hvorfor det er viktig.'}
                </p>
                <textarea
                    {...description.controlProps}
                    rows={3}
                    value={form.data.description}
                    onChange={(event) => form.setData('description', event.target.value)}
                    className={`mt-1 ${TEXTAREA}`}
                />
                {form.errors.description && <p id={description.errorId} className={ERROR}>{form.errors.description}</p>}
            </div>

            <div className="grid gap-4 md:grid-cols-3">
                <FormField
                    id="objective-area"
                    label={tr.field_area ?? 'Fagområde'}
                    hint={tr.field_area_hint ?? 'Bestemmer hvem som kan se målet.'}
                    error={form.errors.business_area_id}
                    required
                >
                    {(field) => (
                        <select
                            {...field}
                            required
                            value={form.data.business_area_id}
                            onChange={(event) => changeArea(event.target.value)}
                            className={INPUT}
                        >
                            <option value="">{tr.choose_area ?? 'Velg fagområde'}</option>
                            {areaOptions.map((area) => (
                                <option key={area.id} value={area.id}>{area.name}</option>
                            ))}
                        </select>
                    )}
                </FormField>

                <FormField
                    id="objective-owner"
                    label={tr.field_owner ?? 'Ansvarlig'}
                    hint={tr.field_owner_hint ?? 'Bare personer som kan se mål i valgt fagområde kan være ansvarlig.'}
                    error={form.errors.owner_user_id}
                    required
                >
                    {(field) => (
                        <select
                            {...field}
                            required
                            value={form.data.owner_user_id}
                            onChange={(event) => form.setData('owner_user_id', event.target.value)}
                            className={INPUT}
                        >
                            <option value="">{tr.choose_owner ?? 'Velg ansvarlig'}</option>
                            {owners.map((owner) => (
                                <option key={owner.id} value={owner.id}>{owner.name}</option>
                            ))}
                        </select>
                    )}
                </FormField>

                <FormField
                    id="objective-target-date"
                    label={tr.field_target_date ?? 'Måldato'}
                    hint={tr.field_target_date_hint ?? 'Valgfritt. La stå tom for et løpende mål.'}
                    error={form.errors.target_date}
                >
                    {(field) => (
                        <input
                            {...field}
                            type="date"
                            value={form.data.target_date}
                            onChange={(event) => form.setData('target_date', event.target.value)}
                            className={INPUT}
                        />
                    )}
                </FormField>
            </div>

            <RequiredFieldsNote text={tr.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />

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
