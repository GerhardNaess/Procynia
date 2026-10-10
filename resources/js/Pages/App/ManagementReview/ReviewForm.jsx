import SearchableMultiSelect from '../../../Components/App/SearchableMultiSelect';
import RequiredMark from '../Risk/RequiredMark';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';

const INPUT = 'min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';
const HINT = 'mt-1 text-base text-slate-600';
const ERROR = 'mt-1 text-base text-rose-700';

/**
 * The fields of a review, for Ny gjennomgåelse and Rediger. Five that matter — title, period, scope,
 * responsible, frameworks — plus the optional meeting date, participants and purpose. Frameworks and
 * participants are searchable pickers, never the whole list on screen. The participants chosen here
 * are people in Procynia; someone outside it is added by name on Oversikt. Status is never a field.
 */
export default function ReviewForm({ form, onSubmit, onCancel, t, ownerOptions = [], areaOptions = [], frameworkOptions = [], participantOptions = null, submitLabel }) {
    const tf = t.fields ?? {};
    const pickerLabels = {
        noResultsLabel: tf.picker_no_results ?? 'Ingen treff.',
        removeLabel: tf.picker_remove ?? 'Fjern :name',
        selectedLabel: tf.picker_selected ?? 'Valgt',
    };
    const toggle = (field, value) => {
        const current = form.data[field] ?? [];
        form.setData(field, current.includes(value) ? current.filter((item) => item !== value) : [...current, value]);
    };

    return (
        <form onSubmit={onSubmit} className="space-y-5" data-testid="management-review-form">
            <div>
                <label htmlFor="mr-title" className={LABEL}>{tf.title ?? 'Tittel'}<RequiredMark /></label>
                <input id="mr-title" type="text" required aria-required="true" maxLength={255} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} className={`mt-1 ${INPUT}`} />
                {form.errors.title && <p className={ERROR}>{form.errors.title}</p>}
            </div>

            <div className="grid gap-5 md:grid-cols-3">
                <div>
                    <label htmlFor="mr-period-start" className={LABEL}>{tf.period_start ?? 'Periode fra'}<RequiredMark /></label>
                    <input id="mr-period-start" type="date" required value={form.data.period_start} onChange={(event) => form.setData('period_start', event.target.value)} className={`mt-1 ${INPUT}`} />
                    {form.errors.period_start && <p className={ERROR}>{form.errors.period_start}</p>}
                </div>
                <div>
                    <label htmlFor="mr-period-end" className={LABEL}>{tf.period_end ?? 'Periode til'}<RequiredMark /></label>
                    <input id="mr-period-end" type="date" required value={form.data.period_end} onChange={(event) => form.setData('period_end', event.target.value)} className={`mt-1 ${INPUT}`} />
                    {form.errors.period_end && <p className={ERROR}>{form.errors.period_end}</p>}
                </div>
                <div>
                    <label htmlFor="mr-meeting-date" className={LABEL}>{tf.meeting_date ?? 'Møtedato'}</label>
                    <input id="mr-meeting-date" type="date" value={form.data.meeting_date ?? ''} onChange={(event) => form.setData('meeting_date', event.target.value)} className={`mt-1 ${INPUT}`} />
                    {form.errors.meeting_date && <p className={ERROR}>{form.errors.meeting_date}</p>}
                </div>
            </div>

            <div>
                <label htmlFor="mr-owner" className={LABEL}>{tf.owner ?? 'Ansvarlig'}<RequiredMark /></label>
                <select id="mr-owner" required value={form.data.owner_user_id ?? ''} onChange={(event) => form.setData('owner_user_id', event.target.value)} className={`mt-1 ${INPUT}`}>
                    <option value="">{t.decisions?.choose_owner ?? 'Velg ansvarlig'}</option>
                    {ownerOptions.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                </select>
                {form.errors.owner_user_id && <p className={ERROR}>{form.errors.owner_user_id}</p>}
            </div>

            <fieldset>
                <legend className={LABEL}>{tf.scope ?? 'Avgrensning'}</legend>
                <p className={HINT}>{tf.scope_hint ?? ''}</p>
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
                    <div className="mt-2 flex flex-wrap gap-x-5 gap-y-1" aria-label={tf.areas ?? 'Fagområder'}>
                        {areaOptions.map((area) => (
                            <label key={area.id} className="flex min-h-11 items-center gap-2 text-base text-slate-800">
                                <input type="checkbox" checked={(form.data.business_area_ids ?? []).includes(area.id)} onChange={() => toggle('business_area_ids', area.id)} className="h-5 w-5 rounded" />
                                {area.name}
                            </label>
                        ))}
                    </div>
                )}
                {form.errors.business_area_ids && <p className={ERROR}>{form.errors.business_area_ids}</p>}
            </fieldset>

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
                        describedBy="mr-frameworks-hint"
                        testId="mr-frameworks"
                        {...pickerLabels}
                    />
                    {form.errors.frameworks && <p className={ERROR}>{form.errors.frameworks}</p>}
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
                        describedBy="mr-participants-hint"
                        testId="mr-participants"
                        {...pickerLabels}
                    />
                    {form.errors.participant_user_ids && <p className={ERROR}>{form.errors.participant_user_ids}</p>}
                </div>
            )}

            <div>
                <label htmlFor="mr-purpose" className={LABEL}>{tf.purpose ?? 'Formål'}</label>
                <p className={HINT}>{tf.purpose_hint ?? ''}</p>
                <textarea id="mr-purpose" rows={3} maxLength={5000} value={form.data.purpose ?? ''} onChange={(event) => form.setData('purpose', event.target.value)} className={`mt-1 ${INPUT}`} />
            </div>

            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>{form.processing ? (t.saving ?? 'Lagrer...') : submitLabel}</button>
                <button type="button" onClick={onCancel} className={SECONDARY_ACTION}>{t.cancel ?? 'Avbryt'}</button>
            </div>
        </form>
    );
}
