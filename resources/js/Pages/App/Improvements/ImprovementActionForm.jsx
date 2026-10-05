import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import RequiredMark from '../Risk/RequiredMark';

const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';
const HINT = 'mt-1 text-base text-slate-600';
const ERROR = 'mt-1 text-base text-rose-700';

/**
 * Nytt tiltak and Rediger tiltak: what is to be done, by whom and by when. Nothing more — no
 * priority, percentage or estimate. There is no status field: a tiltak starts planned and moves only
 * through its lifecycle buttons. The owner list holds the people who can read cases in the case's
 * area; the server checks it again on save. One form is open at a time, so the field ids are fixed.
 */
export default function ImprovementActionForm({ form, onSubmit, onCancel, heading, ownerOptions = [], tr }) {
    const t = tr.actions ?? {};

    return (
        <form onSubmit={onSubmit} className="space-y-5" aria-label={heading}>
            <h3 className="text-lg font-semibold text-slate-950">{heading}</h3>

            <div>
                <label htmlFor="improvement-action-title" className={LABEL}>{t.field_title ?? 'Tittel'}<RequiredMark /></label>
                <p id="improvement-action-title-hint" className={HINT}>{t.field_title_hint ?? 'Hva skal gjøres?'}</p>
                <input
                    id="improvement-action-title"
                    type="text"
                    required
                    aria-required="true"
                    aria-describedby="improvement-action-title-hint"
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.title && <p className={ERROR}>{form.errors.title}</p>}
            </div>

            <div>
                <label htmlFor="improvement-action-description" className={LABEL}>{t.field_description ?? 'Beskrivelse'}</label>
                <p id="improvement-action-description-hint" className={HINT}>{t.field_description_hint ?? 'Valgfritt. Utdyp hva tiltaket innebærer.'}</p>
                <textarea
                    id="improvement-action-description"
                    rows={3}
                    aria-describedby="improvement-action-description-hint"
                    value={form.data.description}
                    onChange={(event) => form.setData('description', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.description && <p className={ERROR}>{form.errors.description}</p>}
            </div>

            <div className="grid gap-5 md:grid-cols-2">
                <div>
                    <label htmlFor="improvement-action-owner" className={LABEL}>{t.field_owner ?? 'Ansvarlig'}<RequiredMark /></label>
                    <select
                        id="improvement-action-owner"
                        required
                        aria-required="true"
                        aria-describedby="improvement-action-owner-hint"
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
                    {form.errors.owner_user_id && <p className={ERROR}>{form.errors.owner_user_id}</p>}
                </div>

                <div>
                    <label htmlFor="improvement-action-due-date" className={LABEL}>{t.field_due_date ?? 'Frist'}<RequiredMark /></label>
                    <input
                        id="improvement-action-due-date"
                        type="date"
                        required
                        aria-required="true"
                        aria-describedby="improvement-action-due-date-hint"
                        value={form.data.due_date}
                        onChange={(event) => form.setData('due_date', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <p id="improvement-action-due-date-hint" className={HINT}>{t.field_due_date_hint ?? 'Når tiltaket skal være gjennomført.'}</p>
                    {form.errors.due_date && <p className={ERROR}>{form.errors.due_date}</p>}
                </div>
            </div>

            <p className="text-base text-slate-600">{tr.required_note ?? 'Felt merket med * må fylles ut.'}</p>

            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onCancel} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (tr.save ?? 'Lagre')}
                </button>
            </div>
        </form>
    );
}
