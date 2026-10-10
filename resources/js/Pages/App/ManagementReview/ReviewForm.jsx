import RequiredMark from '../Risk/RequiredMark';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';

const INPUT = 'min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';
const HINT = 'mt-1 text-base text-slate-600';
const ERROR = 'mt-1 text-base text-rose-700';

/**
 * The fields of a review, for Ny gjennomgåelse and Rediger. Five that matter — title, period, scope,
 * responsible, frameworks — plus the optional meeting date and purpose. Participants only when
 * creating; afterwards they are managed on Oversikt. Status is never a field.
 */
export default function ReviewForm({ form, onSubmit, onCancel, t, ownerOptions = [], areaOptions = [], frameworkOptions = [], participantOptions = null, submitLabel }) {
    const tf = t.fields ?? {};
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
                <fieldset>
                    <legend className={LABEL}>{tf.frameworks ?? 'Rammeverk'}</legend>
                    <p className={HINT}>{tf.frameworks_hint ?? ''}</p>
                    <div className="mt-2 flex flex-wrap gap-5">
                        {frameworkOptions.map((key) => (
                            <label key={key} className="flex min-h-11 items-center gap-2 text-base text-slate-800">
                                <input type="checkbox" checked={(form.data.frameworks ?? []).includes(key)} onChange={() => toggle('frameworks', key)} className="h-5 w-5 rounded" data-testid={`mr-framework-${key}`} />
                                {t.frameworks?.[key]?.name ?? key}
                            </label>
                        ))}
                    </div>
                </fieldset>
            )}

            {participantOptions && participantOptions.length > 0 && (
                <fieldset>
                    <legend className={LABEL}>{tf.participants ?? 'Deltakere'}</legend>
                    <p className={HINT}>{tf.participants_hint ?? ''}</p>
                    <div className="mt-2 grid gap-x-5 gap-y-1 sm:grid-cols-2">
                        {participantOptions.map((person) => (
                            <label key={person.id} className="flex min-h-11 items-center gap-2 text-base text-slate-800">
                                <input type="checkbox" checked={(form.data.participant_user_ids ?? []).includes(person.id)} onChange={() => toggle('participant_user_ids', person.id)} className="h-5 w-5 rounded" />
                                {person.name}
                            </label>
                        ))}
                    </div>
                </fieldset>
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
