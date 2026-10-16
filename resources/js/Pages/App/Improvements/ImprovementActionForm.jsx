import { RequiredFieldsNote } from '../../../Components/App/FormField';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { fieldA11y } from '../../../Support/formFieldA11y';
import { ERROR, HINT, INPUT, LABEL } from '../../../Support/formStyles';
import RequiredMark from '../Risk/RequiredMark';

/**
 * Nytt tiltak and Rediger tiltak: what is to be done, by whom and by when. Nothing more — no
 * priority, percentage or estimate. There is no status field: a tiltak starts planned and moves only
 * through its lifecycle buttons. The owner list holds the people who can read cases in the case's
 * area; the server checks it again on save. One form is open at a time, so the field ids are fixed.
 *
 * Hints and errors are tied to their fields; `formRef` is for the caller's useFocusFirstError. «Lagre»
 * stays focusable while saving (aria-disabled) and a second press is ignored.
 */
export default function ImprovementActionForm({ form, onSubmit, onCancel, heading, ownerOptions = [], formRef = null, tr }) {
    const t = tr.actions ?? {};
    const errors = form.errors ?? {};
    const title = fieldA11y({ id: 'improvement-action-title', hasHint: true, hasError: Boolean(errors.title), required: true });
    const description = fieldA11y({ id: 'improvement-action-description', hasHint: true, hasError: Boolean(errors.description) });
    const owner = fieldA11y({ id: 'improvement-action-owner', hasHint: true, hasError: Boolean(errors.owner_user_id), required: true });
    const due = fieldA11y({ id: 'improvement-action-due-date', hasHint: true, hasError: Boolean(errors.due_date), required: true });

    const submit = (event) => {
        if (form.processing) {
            event.preventDefault();

            return;
        }

        onSubmit(event);
    };

    return (
        <form ref={formRef} onSubmit={submit} className="space-y-5" aria-label={heading}>
            <h3 className="text-lg font-semibold text-slate-950">{heading}</h3>

            <div>
                <label htmlFor="improvement-action-title" className={LABEL}>{t.field_title ?? 'Tittel'}<RequiredMark /></label>
                <p id="improvement-action-title-hint" className={HINT}>{t.field_title_hint ?? 'Hva skal gjøres?'}</p>
                <input
                    id="improvement-action-title"
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
                <label htmlFor="improvement-action-description" className={LABEL}>{t.field_description ?? 'Beskrivelse'}</label>
                <p id="improvement-action-description-hint" className={HINT}>{t.field_description_hint ?? 'Valgfritt. Utdyp hva tiltaket innebærer.'}</p>
                <textarea
                    id="improvement-action-description"
                    {...description.controlProps}
                    rows={3}
                    value={form.data.description}
                    onChange={(event) => form.setData('description', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {errors.description && <p id={description.errorId} className={ERROR}>{errors.description}</p>}
            </div>

            <div className="grid gap-5 md:grid-cols-2">
                <div>
                    <label htmlFor="improvement-action-owner" className={LABEL}>{t.field_owner ?? 'Ansvarlig'}<RequiredMark /></label>
                    <select
                        id="improvement-action-owner"
                        {...owner.controlProps}
                        required
                        value={form.data.owner_user_id}
                        onChange={(event) => form.setData('owner_user_id', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{t.choose_owner ?? 'Velg ansvarlig'}</option>
                        {ownerOptions.map((owner) => (
                            <option key={owner.id} value={owner.id}>{owner.name}</option>
                        ))}
                    </select>
                    <p id="improvement-action-owner-hint" className={HINT}>
                        {t.field_owner_hint ?? 'Personen som gjennomfører tiltaket. Bare personer som kan se saker i sakens fagområde kan velges.'}
                    </p>
                    {errors.owner_user_id && <p id={owner.errorId} className={ERROR}>{errors.owner_user_id}</p>}
                </div>

                <div>
                    <label htmlFor="improvement-action-due-date" className={LABEL}>{t.field_due_date ?? 'Frist'}<RequiredMark /></label>
                    <input
                        id="improvement-action-due-date"
                        {...due.controlProps}
                        type="date"
                        required
                        value={form.data.due_date}
                        onChange={(event) => form.setData('due_date', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <p id="improvement-action-due-date-hint" className={HINT}>{t.field_due_date_hint ?? 'Når tiltaket skal være gjennomført.'}</p>
                    {errors.due_date && <p id={due.errorId} className={ERROR}>{errors.due_date}</p>}
                </div>
            </div>

            <RequiredFieldsNote text={tr.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />

            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onCancel} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                <button type="submit" aria-disabled={form.processing || undefined} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (tr.save ?? 'Lagre')}
                </button>
            </div>
        </form>
    );
}
