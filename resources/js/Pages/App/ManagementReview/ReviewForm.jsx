import { useRef } from 'react';
import { usePage } from '@inertiajs/react';
import FormField, { FieldsetField, RequiredFieldsNote } from '../../../Components/App/FormField';
import SearchableMultiSelect from '../../../Components/App/SearchableMultiSelect';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { useFocusFirstError } from '../../../Support/focusManagement';
import { ERROR, HINT, INPUT as FIELD, LABEL, TEXTAREA } from '../../../Support/formStyles';

// The review pages' fields are a touch taller (min-h-11) than the shared ones.
const INPUT = FIELD.replace('min-h-10', 'min-h-11');

/**
 * The fields of a review, for Ny gjennomgang and Rediger. Five that matter — title, period, scope,
 * responsible, frameworks — plus the optional meeting date, participants and purpose. Frameworks and
 * participants are searchable pickers, never the whole list on screen. The participants chosen here
 * are people in Procynia; someone outside it is added by name on Oversikt. Status is never a field.
 *
 * Every field is tied to its hint and error; Avgrensning is one group, and the fagområder under it
 * another, each with its name (A11Y-120). A refused save puts focus on the first field that is wrong
 * (A11Y-013): onSubmit gets that request as its second argument, for the visit's onError.
 */
export default function ReviewForm({ form, onSubmit, onCancel, t, ownerOptions = [], areaOptions = [], frameworkOptions = [], participantOptions = null, submitLabel }) {
    const ta = usePage().props.translations?.a11y ?? {};
    const tf = t.fields ?? {};
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);
    const pickerLabels = {
        noResultsLabel: tf.picker_no_results ?? 'Ingen treff.',
        removeLabel: tf.picker_remove ?? 'Fjern :name',
        selectedLabel: tf.picker_selected ?? 'Valgt',
    };
    const toggle = (field, value) => {
        const current = form.data[field] ?? [];
        form.setData(field, current.includes(value) ? current.filter((item) => item !== value) : [...current, value]);
    };
    const describedBy = (...ids) => ids.filter(Boolean).join(' ') || undefined;

    return (
        <form ref={formRef} onSubmit={(event) => onSubmit(event, focusFirstError)} className="space-y-5" data-testid="management-review-form">
            <FormField id="mr-title" label={tf.title ?? 'Tittel'} error={form.errors.title} required>
                {(field) => (
                    <input {...field} type="text" required maxLength={255} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} className={INPUT} />
                )}
            </FormField>

            <div className="grid gap-5 md:grid-cols-3">
                <FormField id="mr-period-start" label={tf.period_start ?? 'Periode fra'} error={form.errors.period_start} required>
                    {(field) => (
                        <input {...field} type="date" required value={form.data.period_start} onChange={(event) => form.setData('period_start', event.target.value)} className={INPUT} />
                    )}
                </FormField>
                <FormField id="mr-period-end" label={tf.period_end ?? 'Periode til'} error={form.errors.period_end} required>
                    {(field) => (
                        <input {...field} type="date" required value={form.data.period_end} onChange={(event) => form.setData('period_end', event.target.value)} className={INPUT} />
                    )}
                </FormField>
                <FormField id="mr-meeting-date" label={tf.meeting_date ?? 'Møtedato'} error={form.errors.meeting_date}>
                    {(field) => (
                        <input {...field} type="date" value={form.data.meeting_date ?? ''} onChange={(event) => form.setData('meeting_date', event.target.value)} className={INPUT} />
                    )}
                </FormField>
            </div>

            <FormField id="mr-owner" label={tf.owner ?? 'Ansvarlig'} error={form.errors.owner_user_id} required>
                {(field) => (
                    <select {...field} required value={form.data.owner_user_id ?? ''} onChange={(event) => form.setData('owner_user_id', event.target.value)} className={INPUT}>
                        <option value="">{t.decisions?.choose_owner ?? 'Velg ansvarlig'}</option>
                        {ownerOptions.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                    </select>
                )}
            </FormField>

            <FieldsetField
                id="mr-scope"
                legend={tf.scope ?? 'Avgrensning'}
                hint={tf.scope_hint || null}
                error={form.errors.business_area_ids}
            >
                {(optionProps) => (
                    <>
                        <div className="mt-2 flex flex-wrap gap-4">
                            <label className="flex min-h-11 items-center gap-2 text-base text-slate-800">
                                <input type="radio" name="mr-scope" checked={Boolean(form.data.all_business_areas)} onChange={() => form.setData('all_business_areas', true)} className="h-5 w-5" />
                                {t.scope_all ?? 'Hele virksomheten'}
                            </label>
                            {areaOptions.length > 0 && (
                                <label className="flex min-h-11 items-center gap-2 text-base text-slate-800">
                                    <input type="radio" name="mr-scope" checked={! form.data.all_business_areas} onChange={() => form.setData('all_business_areas', false)} className="h-5 w-5" />
                                    {t.scope_areas ?? 'Valgte fagområder'}
                                </label>
                            )}
                        </div>
                        {! form.data.all_business_areas && (
                            <div className="mt-2 flex flex-wrap gap-x-5 gap-y-1" role="group" aria-label={tf.areas ?? 'Fagområder'}>
                                {areaOptions.map((area) => (
                                    <label key={area.id} className="flex min-h-11 items-center gap-2 text-base text-slate-800">
                                        <input {...optionProps} type="checkbox" checked={(form.data.business_area_ids ?? []).includes(area.id)} onChange={() => toggle('business_area_ids', area.id)} className="h-5 w-5 rounded" />
                                        {area.name}
                                    </label>
                                ))}
                            </div>
                        )}
                    </>
                )}
            </FieldsetField>

            {frameworkOptions.length > 0 && (
                <div>
                    <label htmlFor="mr-frameworks" className={LABEL}>{tf.frameworks ?? 'Rammeverk'}</label>
                    <p id="mr-frameworks-hint" className={HINT}>{tf.frameworks_hint ?? ''}</p>
                    <SearchableMultiSelect
                        id="mr-frameworks"
                        options={frameworkOptions.map((framework) => ({ value: framework.key, label: framework.label, description: `${framework.type_label} · ${framework.domain_label}` }))}
                        values={form.data.frameworks ?? []}
                        onChange={(next) => form.setData('frameworks', next)}
                        placeholder={tf.frameworks_placeholder ?? 'Velg rammeverk'}
                        searchLabel={tf.frameworks_search ?? 'Søk på navn eller fagområde'}
                        describedBy={describedBy('mr-frameworks-hint', form.errors.frameworks && 'mr-frameworks-error')}
                        invalid={Boolean(form.errors.frameworks)}
                        testId="mr-frameworks"
                        {...pickerLabels}
                    />
                    {form.errors.frameworks && <p id="mr-frameworks-error" className={ERROR}>{form.errors.frameworks}</p>}
                </div>
            )}

            {participantOptions && participantOptions.length > 0 && (
                <div>
                    <label htmlFor="mr-participants" className={LABEL}>{tf.participants ?? 'Deltakere'}</label>
                    <p id="mr-participants-hint" className={HINT}>{tf.participants_hint ?? ''}</p>
                    <SearchableMultiSelect
                        id="mr-participants"
                        options={participantOptions.map((person) => ({ value: person.id, label: person.name }))}
                        values={form.data.participant_user_ids ?? []}
                        onChange={(next) => form.setData('participant_user_ids', next)}
                        placeholder={tf.participants_placeholder ?? 'Velg deltakere'}
                        searchLabel={tf.participants_search ?? 'Søk på navn'}
                        describedBy={describedBy('mr-participants-hint', form.errors.participant_user_ids && 'mr-participants-error')}
                        invalid={Boolean(form.errors.participant_user_ids)}
                        testId="mr-participants"
                        {...pickerLabels}
                    />
                    {form.errors.participant_user_ids && <p id="mr-participants-error" className={ERROR}>{form.errors.participant_user_ids}</p>}
                </div>
            )}

            <div>
                <label htmlFor="mr-purpose" className={LABEL}>{tf.purpose ?? 'Formål'}</label>
                <p id="mr-purpose-hint" className={HINT}>{tf.purpose_hint ?? ''}</p>
                <textarea
                    id="mr-purpose"
                    rows={3}
                    maxLength={5000}
                    aria-describedby={describedBy(tf.purpose_hint && 'mr-purpose-hint', form.errors.purpose && 'mr-purpose-error')}
                    aria-invalid={form.errors.purpose ? true : undefined}
                    value={form.data.purpose ?? ''}
                    onChange={(event) => form.setData('purpose', event.target.value)}
                    className={`mt-1 ${TEXTAREA}`}
                />
                {form.errors.purpose && <p id="mr-purpose-error" className={ERROR}>{form.errors.purpose}</p>}
            </div>

            <RequiredFieldsNote text={ta.required_fields ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />

            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>{form.processing ? (t.saving ?? 'Lagrer...') : submitLabel}</button>
                <button type="button" onClick={onCancel} className={SECONDARY_ACTION}>{t.cancel ?? 'Avbryt'}</button>
            </div>
        </form>
    );
}
