import { useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { useFocusFirstError } from '../../../Support/focusManagement';
import { TEXTAREA } from '../../../Support/formStyles';
import { formatLongDate } from '../Improvements/improvementStatus';
import SupplierField, { BUSY, FieldError, RequiredNote, groupA11y } from './SupplierField';
import { useInlinePanelFocus } from './supplierFocus';
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

/**
 * One question in the form: explicit choices, never a toggle, so «Ikke avklart» stays apart from «Nei».
 * The group is described by its hint and error, and its options are aria-invalid while it is wrong.
 */
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
        const listError = form.errors[field] ?? form.errors[`${field}.0`];
        const list = groupA11y(name, { hint: true, error: listError });

        return (
            <fieldset className="rounded-xl border border-slate-200 px-4 py-3" data-testid={`profile-question-${field}`} {...list.groupProps}>
                <legend className="sr-only">{question}</legend>
                <p className={LEGEND} aria-hidden="true">{question}</p>
                <p id={list.hintId} className={HINT}>{field === 'sectors' ? `${hint} ${p.sectors_hint ?? ''}`.trim() : hint}</p>
                <div className="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2">
                    {codes.map((code) => (
                        <label key={code} className="flex min-h-10 items-start gap-2 py-1 text-base text-slate-900">
                            <input
                                type="checkbox"
                                checked={Array.isArray(value) && value.includes(code)}
                                onChange={() => form.setData(field, toggleListAnswer(value, code, codes))}
                                className="mt-1 h-5 w-5 shrink-0"
                                {...list.optionProps}
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
                            {...list.optionProps}
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
                                {...list.optionProps}
                            />
                            <span>{p.answers?.unknown ?? 'Ikke avklart'}</span>
                        </label>
                    )}
                </div>
                <FieldError id={list.errorId} message={listError} className={ERROR} />
            </fieldset>
        );
    }

    const single = groupA11y(name, { error: form.errors[field] });

    return (
        <fieldset className="rounded-xl border border-slate-200 px-4 py-3" data-testid={`profile-question-${field}`} {...single.groupProps}>
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
                            {...single.optionProps}
                        />
                        {profileAnswerLabel(field, choice, tr)}
                    </label>
                ))}
            </div>
            <FieldError id={single.errorId} message={form.errors[field]} className={ERROR} />
        </fieldset>
    );
}

function ProfileForm({ supplierId, profile, fields, onDone, tr }) {
    const p = tr.profile ?? {};
    const options = profile.options ?? {};
    const isChange = profile.answers !== null;
    const form = useForm(profileFormData(fields, profile.answers));
    const visible = visibleProfileFields(fields, profile.basis, form.data);
    const formRef = useRef(null);
    const focusError = useFocusFirstError(formRef);

    const send = (event) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        // A question not asked for the supplier is not sent; the server stores it as not answered.
        form.transform((data) => {
            const payload = { reason: data.reason };

            for (const field of visible) {
                payload[field] = data[field];
            }

            return payload;
        });
        form.post(`/app/supplier-management/${supplierId}/profile`, { preserveScroll: true, onSuccess: onDone, onError: focusError });
    };

    return (
        <form ref={formRef} id="supplier-profile-panel" onSubmit={send} className="mt-4 space-y-6 border-t border-slate-100 pt-5" data-testid="profile-form">
            <p className={HINT}>{p.form_intro ?? 'Svar så godt du kan. Velg «Ikke avklart» når dere ikke vet svaret ennå – det er noe annet enn «Nei».'}</p>
            <FieldError message={form.errors.profile} className={ERROR} summary />

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
                <>
                    <SupplierField id="supplier-profile-reason" label={p.reason_label ?? 'Begrunnelse'} hint={p.reason_hint ?? 'Hvorfor endres profilen?'} error={form.errors.reason} required>
                        {(field) => (
                            <textarea
                                {...field}
                                rows={3}
                                required
                                value={form.data.reason}
                                onChange={(event) => form.setData('reason', event.target.value)}
                                className={`mt-1 ${TEXTAREA}`}
                            />
                        )}
                    </SupplierField>
                    <RequiredNote tr={tr} />
                </>
            )}

            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                <button type="submit" aria-disabled={form.processing || undefined} className={`${PRIMARY_ACTION} ${BUSY}`}>
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
    // The form opens in place of its button: focus in, and back to it (A11Y-014).
    const rememberTrigger = useInlinePanelFocus(open ? 'profile' : null, { panelId: 'supplier-profile-panel', fallbackIds: ['supplier-profile-edit', 'supplier-profile-heading-toggle'] });

    if (! profile) {
        return null;
    }

    const groups = profile.options?.groups ?? {};
    const fields = Object.values(groups).flat();
    const answers = profile.answers;
    const visible = profile.visible ?? fields;
    const unknownUser = tr.unknown_user ?? 'en tidligere bruker';

    return (
        <div data-testid="supplier-profile" onClickCapture={rememberTrigger}>
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
                <button type="button" id="supplier-profile-edit" data-panel-trigger="" onClick={() => setOpen(true)} className={`mt-4 ${answers ? SECONDARY_ACTION : PRIMARY_ACTION}`}>
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
