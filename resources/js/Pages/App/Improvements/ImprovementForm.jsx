import { RequiredFieldsNote } from '../../../Components/App/FormField';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { fieldA11y } from '../../../Support/formFieldA11y';
import { ERROR, HINT, INPUT, LABEL } from '../../../Support/formStyles';
import RequiredMark from '../Risk/RequiredMark';
import { ownersForArea } from '../Risk/riskOwners';
import { descriptionHint } from './improvementStatus';

/**
 * The fields of an avvik or a forbedring, shared by Registrer and Rediger. Kept short on purpose:
 * first the case gets in, then it is handled. The area list holds only the areas the person may
 * edit in, and the owner list follows the chosen area — both from the server, which checks them
 * again on save. There is no status field: a case is registered open and moves only through the
 * lifecycle buttons. Hendelsesdato belongs to an avvik and is not asked for a forbedring.
 *
 * Accessibility (Fase 6): hints and errors are tied to their fields, an error marks its field
 * invalid (Type: the group is described by its error), and `formRef` lets the page put focus on
 * the first one after a rejected save. «Lagre» stays focusable while saving (aria-disabled).
 */
export default function ImprovementForm({ form, onSubmit, onCancel, types = [], areaOptions, ownerOptions, today, formRef = null, tr }) {
    const owners = ownersForArea(ownerOptions, form.data.business_area_id);
    const typeLabels = tr.types ?? {};
    const isDeviation = form.data.type !== 'improvement';
    const errors = form.errors ?? {};
    const wire = (id, error, { hint = true, required = false } = {}) => fieldA11y({ id, hasHint: hint, hasError: Boolean(error), required });
    const title = wire('improvement-title', errors.title, { required: true });
    const description = wire('improvement-description', errors.description, { required: true });
    const area = wire('improvement-area', errors.business_area_id, { required: true });
    const owner = wire('improvement-owner', errors.owner_user_id, { required: true });
    const occurredAt = wire('improvement-occurred-at', errors.occurred_at);
    const dueDate = wire('improvement-due-date', errors.due_date);
    const typeErrorId = 'improvement-type-error';

    const submit = (event) => {
        if (form.processing) {
            event.preventDefault();

            return;
        }

        onSubmit(event);
    };

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
        <form ref={formRef} onSubmit={submit} className="space-y-5">
            <fieldset aria-describedby={errors.type ? typeErrorId : undefined}>
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
                {errors.type && <p id={typeErrorId} className={ERROR}>{errors.type}</p>}
            </fieldset>

            <div>
                <label htmlFor="improvement-title" className={LABEL}>{tr.field_title ?? 'Tittel'}<RequiredMark /></label>
                <p id="improvement-title-hint" className={HINT}>{tr.field_title_hint ?? 'Kort beskrivelse av saken.'}</p>
                <input
                    {...title.controlProps}
                    type="text"
                    required
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {errors.title && <p id={title.errorId} className={ERROR}>{errors.title}</p>}
            </div>

            <div>
                <label htmlFor="improvement-description" className={LABEL}>{tr.field_description ?? 'Beskrivelse'}<RequiredMark /></label>
                <p id="improvement-description-hint" className={HINT}>{descriptionHint(form.data.type, tr)}</p>
                <textarea
                    {...description.controlProps}
                    rows={4}
                    required
                    value={form.data.description}
                    onChange={(event) => form.setData('description', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {errors.description && <p id={description.errorId} className={ERROR}>{errors.description}</p>}
            </div>

            <div className="grid gap-5 md:grid-cols-2">
                <div>
                    <label htmlFor="improvement-area" className={LABEL}>{tr.field_area ?? 'Fagområde'}<RequiredMark /></label>
                    <select
                        {...area.controlProps}
                        required
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
                    {errors.business_area_id && <p id={area.errorId} className={ERROR}>{errors.business_area_id}</p>}
                </div>

                <div>
                    <label htmlFor="improvement-owner" className={LABEL}>{tr.field_owner ?? 'Ansvarlig'}<RequiredMark /></label>
                    <select
                        {...owner.controlProps}
                        required
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
                    {errors.owner_user_id && <p id={owner.errorId} className={ERROR}>{errors.owner_user_id}</p>}
                </div>

                {isDeviation && (
                    <div>
                        <label htmlFor="improvement-occurred-at" className={LABEL}>{tr.field_occurred_at ?? 'Hendelsesdato'}</label>
                        <input
                            {...occurredAt.controlProps}
                            type="date"
                            max={today}
                            value={form.data.occurred_at}
                            onChange={(event) => form.setData('occurred_at', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        />
                        <p id="improvement-occurred-at-hint" className={HINT}>{tr.field_occurred_at_hint ?? 'Valgfritt. Når skjedde dette?'}</p>
                        {errors.occurred_at && <p id={occurredAt.errorId} className={ERROR}>{errors.occurred_at}</p>}
                    </div>
                )}

                <div>
                    <label htmlFor="improvement-due-date" className={LABEL}>{tr.field_due_date ?? 'Frist'}</label>
                    <input
                        {...dueDate.controlProps}
                        type="date"
                        value={form.data.due_date}
                        onChange={(event) => form.setData('due_date', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <p id="improvement-due-date-hint" className={HINT}>{tr.field_due_date_hint ?? 'Valgfritt. Når saken bør være behandlet.'}</p>
                    {errors.due_date && <p id={dueDate.errorId} className={ERROR}>{errors.due_date}</p>}
                </div>
            </div>

            <RequiredFieldsNote text={tr.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />

            <div className="flex flex-wrap justify-end gap-3">
                {onCancel && (
                    <button type="button" onClick={onCancel} className={SECONDARY_ACTION}>
                        {tr.cancel ?? 'Avbryt'}
                    </button>
                )}
                <button type="submit" aria-disabled={form.processing || undefined} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (tr.save ?? 'Lagre')}
                </button>
            </div>
        </form>
    );
}
