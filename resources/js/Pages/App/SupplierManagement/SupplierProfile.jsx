import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { formatLongDate } from '../Improvements/improvementStatus';
import RequiredMark from '../Risk/RequiredMark';
import {
    isListField,
    profileAnswerLabel,
    profileChangeLines,
    isListUnknown,
    profileFormData,
    takesListUnknown,
    toggleListAnswer,
    visibleProfileFields,
} from './supplierProfileAnswers';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const TERM = 'text-base font-semibold text-slate-600';
const VALUE = 'mt-1 break-words text-base text-slate-900';
const LEGEND = 'text-base text-slate-900';
const HINT = 'text-base text-slate-600';
const ERROR = 'text-base text-rose-700';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';

/** The values a single-choice question offers. */
function choicesFor(field, options) {
    if (field === 'data_role') {
        return options.data_roles ?? [];
    }

    if (field === 'data_location') {
        return options.data_locations ?? [];
    }

    return options.answers ?? ['yes', 'no', 'unknown'];
}

function FieldError({ message }) {
    return message ? <p className={ERROR}>{message}</p> : null;
}

/** One question in the form: explicit choices, never a toggle, so «Ikke avklart» stays apart from «Nei». */
function Question({ field, form, options, tr }) {
    const p = tr.profile ?? {};
    const question = p.questions?.[field] ?? field;
    const value = form.data[field];
    const name = `supplier-profile-${field}`;

    if (isListField(field)) {
        const codes = options[field] ?? [];
        const noneChosen = Array.isArray(value) && value.length === 0;
        const withUnknown = takesListUnknown(field);
        const hint = withUnknown ? (p.list_hint_unknown ?? 'Velg alle som passer, «Ingen av disse» eller «Ikke avklart».') : (p.list_hint ?? 'Velg alle som passer, eller «Ingen av disse».');

        return (
            <fieldset className="rounded-xl border border-slate-200 px-4 py-3" data-testid={`profile-question-${field}`}>
                <legend className="sr-only">{question}</legend>
                <p className={LEGEND} aria-hidden="true">{question}</p>
                <p className={HINT}>{field === 'sectors' ? `${hint} ${p.sectors_hint ?? ''}`.trim() : hint}</p>
                <div className="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2">
                    {codes.map((code) => (
                        <label key={code} className="flex min-h-10 items-start gap-2 py-1 text-base text-slate-900">
                            <input
                                type="checkbox"
                                checked={Array.isArray(value) && value.includes(code)}
                                onChange={() => form.setData(field, toggleListAnswer(value, code, codes))}
                                className="mt-1 h-5 w-5 shrink-0"
                            />
                            <span className="min-w-0 break-words">{profileAnswerLabel(field, [code], tr)}</span>
                        </label>
                    ))}
                    <label className="flex min-h-10 items-start gap-2 py-1 text-base text-slate-900">
                        <input
                            type="checkbox"
                            checked={noneChosen}
                            onChange={() => form.setData(field, toggleListAnswer(value, null, codes))}
                            className="mt-1 h-5 w-5 shrink-0"
                        />
                        <span>{p.none_selected ?? 'Ingen av disse'}</span>
                    </label>
                    {withUnknown && (
                        <label className="flex min-h-10 items-start gap-2 py-1 text-base text-slate-900">
                            <input
                                type="checkbox"
                                checked={isListUnknown(value)}
                                onChange={() => form.setData(field, toggleListAnswer(value, 'unknown', codes))}
                                className="mt-1 h-5 w-5 shrink-0"
                            />
                            <span>{p.answers?.unknown ?? 'Ikke avklart'}</span>
                        </label>
                    )}
                </div>
                <FieldError message={form.errors[field] ?? form.errors[`${field}.0`]} />
            </fieldset>
        );
    }

    return (
        <fieldset className="rounded-xl border border-slate-200 px-4 py-3" data-testid={`profile-question-${field}`}>
            <legend className="sr-only">{question}</legend>
            <p className={LEGEND} aria-hidden="true">{question}</p>
            <div className="mt-2 flex flex-wrap gap-x-6 gap-y-1">
                {choicesFor(field, options).map((choice) => (
                    <label key={choice} className="flex min-h-10 items-center gap-2 text-base text-slate-900">
                        <input
                            type="radio"
                            name={name}
                            value={choice}
                            checked={value === choice}
                            onChange={() => form.setData(field, choice)}
                            className="h-5 w-5 shrink-0"
                        />
                        {profileAnswerLabel(field, choice, tr)}
                    </label>
                ))}
            </div>
            <FieldError message={form.errors[field]} />
        </fieldset>
    );
}

function ProfileForm({ supplierId, profile, fields, onDone, tr }) {
    const p = tr.profile ?? {};
    const options = profile.options ?? {};
    const isChange = profile.answers !== null;
    const form = useForm(profileFormData(fields, profile.answers));
    const visible = visibleProfileFields(fields, profile.basis, form.data);

    const send = (event) => {
        event.preventDefault();
        // A question not asked for the supplier is not sent; the server stores it as not answered.
        form.transform((data) => {
            const payload = { reason: data.reason };

            for (const field of visible) {
                payload[field] = data[field];
            }

            return payload;
        });
        form.post(`/app/supplier-management/${supplierId}/profile`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={send} className="mt-4 space-y-6 border-t border-slate-100 pt-5" data-testid="profile-form">
            <p className={HINT}>{p.form_intro ?? 'Svar så godt du kan. Velg «Ikke avklart» når dere ikke vet svaret ennå – det er noe annet enn «Nei».'}</p>
            {form.errors.profile && <p className={ERROR}>{form.errors.profile}</p>}

            {Object.entries(options.groups ?? {}).map(([group, groupFields]) => {
                const shown = groupFields.filter((field) => visible.includes(field));

                return shown.length > 0 && (
                    <section key={group} className="space-y-3" aria-labelledby={`profile-form-group-${group}`}>
                        <h4 id={`profile-form-group-${group}`} className="text-lg font-semibold text-slate-950">{p.groups?.[group] ?? group}</h4>
                        {shown.map((field) => <Question key={field} field={field} form={form} options={options} tr={tr} />)}
                    </section>
                );
            })}

            {isChange && (
                <div>
                    <label htmlFor="supplier-profile-reason" className="block text-base font-semibold text-slate-900">
                        {p.reason_label ?? 'Begrunnelse'}<RequiredMark />
                    </label>
                    <p id="supplier-profile-reason-hint" className={`mt-1 ${HINT}`}>{p.reason_hint ?? 'Hvorfor endres profilen?'}</p>
                    <textarea
                        id="supplier-profile-reason"
                        rows={3}
                        required
                        aria-required="true"
                        aria-describedby="supplier-profile-reason-hint"
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <FieldError message={form.errors.reason} />
                </div>
            )}

            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (p.submit ?? 'Lagre profil')}
                </button>
            </div>
        </form>
    );
}

/**
 * Leverandørprofil, under «Detaljer» on Oversikt: facts about the delivery that decide which control
 * requirements apply — not an assessment. The answers by theme. The four criticality answers the
 * profile is read with are shown under Kritikalitet only, not repeated here; the technical link
 * (profile.basis decides which questions are asked) is unchanged. Profilhistorikk is under
 * Historikk (SupplierProfileHistory). The section heading is the Detaljer section's.
 *
 * «Fyll ut profil» / «Rediger profil» is offered only when the server says this person may change it:
 * supplier.edit on a supplier that is not ended. supplier.assure never gets it.
 */
export default function SupplierProfile({ supplierId, profile, canEdit, locale, tr }) {
    const p = tr.profile ?? {};
    const [open, setOpen] = useState(false);

    if (! profile) {
        return null;
    }

    const groups = profile.options?.groups ?? {};
    const fields = Object.values(groups).flat();
    const answers = profile.answers;
    const visible = profile.visible ?? fields;
    const unknownUser = tr.unknown_user ?? 'en tidligere bruker';

    return (
        <div data-testid="supplier-profile">
            <p className="text-base text-slate-600">{p.intro ?? 'Profilen beskriver forhold ved leveransen som senere brukes til å avgjøre hvilke kontrollkrav som gjelder.'}</p>

            {answers === null ? (
                <p className="mt-4 text-base text-slate-800" data-testid="profile-none">{p.not_filled ?? 'Profilen er ikke fylt ut ennå.'}</p>
            ) : (
                <>
                    <dl className="mt-4 grid gap-4 sm:grid-cols-2">
                        <div className="min-w-0">
                            <dt className={TERM}>{p.last_changed ?? 'Sist endret'}</dt>
                            <dd className={VALUE}>{formatLongDate(profile.updated_at, locale)}</dd>
                        </div>
                        <div className="min-w-0">
                            <dt className={TERM}>{p.changed_by ?? 'Endret av'}</dt>
                            <dd className={VALUE}>{profile.updated_by_name ?? unknownUser}</dd>
                        </div>
                    </dl>
                    {! profile.complete && (
                        <p className="mt-3 text-base text-amber-800" data-testid="profile-incomplete">{p.incomplete ?? 'Noen spørsmål er ikke besvart ennå.'}</p>
                    )}

                    {! open && Object.entries(groups).map(([group, groupFields]) => {
                        const shown = groupFields.filter((field) => visible.includes(field));

                        return shown.length > 0 && (
                            <div key={group} className="mt-5" data-testid={`profile-group-${group}`}>
                                <h4 className="text-lg font-semibold text-slate-950">{p.groups?.[group] ?? group}</h4>
                                <dl className="mt-2 grid gap-4 sm:grid-cols-2">
                                    {shown.map((field) => (
                                        <div key={field} className="min-w-0" data-testid={`profile-answer-${field}`}>
                                            <dt className={TERM}>{p.labels?.[field] ?? field}</dt>
                                            <dd className={`${VALUE} ${answers[field] === null ? 'text-slate-600' : ''}`}>{profileAnswerLabel(field, answers[field], tr)}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                        );
                    })}
                </>
            )}

            {/* Without a criticality some questions are not asked yet; the answers themselves are under Kritikalitet. */}
            {! open && ! profile.basis && (
                <p className="mt-4 text-base text-slate-600" data-testid="profile-basis-missing">{p.basis_missing ?? 'Kritikalitet er ikke vurdert ennå. Noen spørsmål i profilen vises først når den er vurdert.'}</p>
            )}

            {canEdit && ! open && (
                <button type="button" onClick={() => setOpen(true)} className={`mt-4 ${answers ? SECONDARY_ACTION : PRIMARY_ACTION}`}>
                    {answers ? (p.edit ?? 'Rediger profil') : (p.fill ?? 'Fyll ut profil')}
                </button>
            )}

            {canEdit && open && (
                <ProfileForm supplierId={supplierId} profile={profile} fields={fields} onDone={() => setOpen(false)} tr={tr} />
            )}
        </div>
    );
}

/**
 * «Profilhistorikk», under Historikk: every save of the profile, newest first — when, by whom, what
 * changed from what, and why. Read-only; nothing when the profile was never filled in.
 */
export function SupplierProfileHistory({ profile, locale, tr }) {
    const p = tr.profile ?? {};
    const history = profile?.history ?? [];

    if (history.length === 0) {
        return null;
    }

    const fields = Object.values(profile.options?.groups ?? {}).flat();
    const unknownUser = tr.unknown_user ?? 'en tidligere bruker';

    return (
        <section className={CARD} aria-labelledby="supplier-profile-history-heading" data-testid="supplier-profile-history">
            <h2 id="supplier-profile-history-heading" className="text-xl font-semibold text-slate-950">{p.history_heading ?? 'Profilhistorikk'}</h2>
            <ol className="mt-2 divide-y divide-slate-100" data-testid="profile-history">
                {history.map((entry) => (
                    <li key={entry.id} className="space-y-1 py-3" data-testid="profile-history-entry">
                        <p className="text-base font-semibold text-slate-900">
                            {formatLongDate(entry.changed_at, locale)} – {entry.changed_by_name ?? unknownUser}
                        </p>
                        <p className="text-base text-slate-600">{entry.from ? (p.history?.changed ?? 'Profilen ble endret') : (p.history?.first ?? 'Profilen ble fylt ut')}</p>
                        <ul className="list-disc space-y-1 pl-5">
                            {profileChangeLines(entry, fields, tr).map((line) => (
                                <li key={line} className="break-words text-base text-slate-800">{line}</li>
                            ))}
                        </ul>
                        {entry.reason && <p className="whitespace-pre-line break-words text-base text-slate-800">{entry.reason}</p>}
                    </li>
                ))}
            </ol>
        </section>
    );
}
