import { RequiredFieldsNote } from '../../../Components/App/FormField';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { fieldA11y } from '../../../Support/formFieldA11y';
import { ERROR, HINT, INPUT, LABEL } from '../../../Support/formStyles';
import RequiredMark from './RequiredMark';
import { ownersForArea } from './riskOwners';
import { TREATMENT_EXPLANATIONS, TREATMENT_LABELS } from './RiskTreatmentStrategy';

/**
 * What ties a field to its hint and its error (1.3.1, 3.3.1, A11Y-013): FormField's wiring, kept by
 * hand here so the hints stay where this form has always shown them. Returns the control's props
 * and the error's id.
 */
function wire(id, { hint = false, error = null, required = false } = {}) {
    const { errorId, controlProps } = fieldA11y({ id, hasHint: hint, hasError: Boolean(error), required });

    return { errorId, props: controlProps };
}

function FieldError({ id, message }) {
    return message ? <p id={id} className={ERROR}>{message}</p> : null;
}

// Årsak → hendelse → konsekvens: the risk description itself. The readable sentence is composed
// from these on the server; nobody types it.
const DESCRIPTION_PARTS = [
    { field: 'cause', label: 'Årsak', hint: 'Hva kan gjøre hendelsen mulig?' },
    { field: 'event', label: 'Hendelse', hint: 'Hva kan skje?' },
    { field: 'consequence', label: 'Konsekvens', hint: 'Hva kan virksomheten bli påvirket av?' },
];

/**
 * The fields of a risk, shared by Ny risiko and Rediger. The area list holds only the areas the
 * person may create (or edit) in, and the owner list follows the chosen area — both come from the
 * server, which checks them again on save. `missingStructure` marks an older risk that has no
 * årsak/hendelse/konsekvens yet: it must get them before it can be saved again.
 *
 * `creating` keeps Ny risiko to what the risk is and who owns it. Status starts as the first
 * lifecycle step, and behandlingsvalg and vurderingsintervall are set from the risk page once there
 * is something to decide on — the server takes neither as required.
 *
 * Accessibility (Fase 6): every hint and error is tied to its field, an error marks the field
 * invalid, and `formRef` lets the page put focus on the first one after a rejected save
 * (useFocusFirstError). While saving, «Lagre» stays focusable (aria-disabled) and a second press is
 * ignored here, so pressing it never drops focus to the page.
 */
export default function RiskForm({ form, onSubmit, onCancel, areaOptions, ownerOptions, statuses, statusLabels, reviewIntervals = [], treatmentStrategies = [], missingStructure = false, creating = false, formRef = null, tr }) {
    const trReview = tr.review ?? {};
    const ts = tr.structured ?? {};
    const tt = tr.treatment_strategy ?? {};
    const strategy = form.data.treatment_strategy;
    const owners = ownersForArea(ownerOptions, form.data.business_area_id);
    const errors = form.errors ?? {};

    const changeArea = (value) => {
        const stillAllowed = ownersForArea(ownerOptions, value).some((owner) => String(owner.id) === String(form.data.owner_user_id));

        form.setData((data) => ({
            ...data,
            business_area_id: value,
            owner_user_id: stillAllowed ? data.owner_user_id : '',
        }));
    };

    const submit = (event) => {
        if (form.processing) {
            event.preventDefault();

            return;
        }

        onSubmit(event);
    };

    const title = wire('risk-title', { error: errors.title, required: true });
    const description = wire('risk-description', { hint: true, error: errors.description });
    const area = wire('risk-area', { hint: true, error: errors.business_area_id, required: true });
    const owner = wire('risk-owner', { hint: true, error: errors.owner_user_id });
    const status = wire('risk-status', { hint: true, error: errors.status });
    const interval = wire('risk-review-interval', { hint: true, error: errors.review_interval_months });
    const treatment = wire('risk-treatment-strategy', { hint: true, error: errors.treatment_strategy });

    return (
        <form ref={formRef} onSubmit={submit} className="space-y-4">
            <div>
                <label htmlFor="risk-title" className={LABEL}>{tr.field_title ?? 'Tittel'}<RequiredMark /></label>
                <input
                    {...title.props}
                    type="text"
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                <FieldError id={title.errorId} message={errors.title} />
            </div>

            <fieldset className="space-y-4 rounded-2xl border border-slate-200 p-4">
                <legend className="px-1 text-base font-semibold text-slate-950">{ts.heading ?? 'Risikobeskrivelse'}</legend>
                {missingStructure && (
                    <p className="rounded-xl bg-amber-50 px-3 py-2 text-base text-amber-900">
                        {ts.missing_form_note ?? 'Denne risikoen ble registrert før strukturert risikobeskrivelse. Fyll ut årsak, hendelse og konsekvens for å lagre. Tidligere tekst ligger urørt under Utfyllende informasjon.'}
                    </p>
                )}
                {DESCRIPTION_PARTS.map(({ field, label, hint }) => {
                    const part = wire(`risk-${field}`, { hint: true, error: errors[field], required: true });

                    return (
                        <div key={field}>
                            <label htmlFor={`risk-${field}`} className={LABEL}>{ts[`field_${field}`] ?? label}<RequiredMark /></label>
                            <p id={`risk-${field}-hint`} className="text-base text-slate-600">{ts[`field_${field}_hint`] ?? hint}</p>
                            <textarea
                                {...part.props}
                                rows={2}
                                required
                                value={form.data[field]}
                                onChange={(event) => form.setData(field, event.target.value)}
                                className={`mt-1 ${INPUT}`}
                            />
                            <FieldError id={part.errorId} message={errors[field]} />
                        </div>
                    );
                })}
            </fieldset>

            <div>
                <label htmlFor="risk-description" className={LABEL}>{tr.field_description ?? 'Utfyllende informasjon'}</label>
                <p id="risk-description-hint" className="text-base text-slate-600">
                    {tr.field_description_hint ?? 'Valgfritt. Bakgrunn og detaljer — inngår ikke i selve risikobeskrivelsen.'}
                </p>
                <textarea
                    {...description.props}
                    rows={3}
                    value={form.data.description}
                    onChange={(event) => form.setData('description', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                <FieldError id={description.errorId} message={errors.description} />
            </div>

            <div className={`grid gap-4 ${creating ? 'md:grid-cols-2' : 'md:grid-cols-3'}`}>
                <div>
                    <label htmlFor="risk-area" className={LABEL}>{tr.field_area ?? 'Fagområde'}<RequiredMark /></label>
                    <select
                        {...area.props}
                        value={form.data.business_area_id}
                        onChange={(event) => changeArea(event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{tr.choose_area ?? 'Velg område'}</option>
                        {areaOptions.map((option) => (
                            <option key={option.id} value={option.id}>{option.name}</option>
                        ))}
                    </select>
                    <p id="risk-area-hint" className={HINT}>{tr.field_area_hint ?? 'Bestemmer hvem som kan se risikoen.'}</p>
                    <FieldError id={area.errorId} message={errors.business_area_id} />
                </div>

                <div>
                    <label htmlFor="risk-owner" className={LABEL}>{tr.field_owner ?? 'Risikoeier'}</label>
                    <select
                        {...owner.props}
                        value={form.data.owner_user_id}
                        onChange={(event) => form.setData('owner_user_id', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{tr.no_owner ?? 'Ingen eier valgt'}</option>
                        {owners.map((option) => (
                            <option key={option.id} value={option.id}>{option.name}</option>
                        ))}
                    </select>
                    <p id="risk-owner-hint" className={HINT}>
                        {tr.field_owner_hint ?? 'Bare personer som kan se risikoer i valgt område kan være eier.'}
                    </p>
                    <FieldError id={owner.errorId} message={errors.owner_user_id} />
                </div>

                {! creating && (
                    <div>
                        <label htmlFor="risk-status" className={LABEL}>{tr.field_status ?? 'Status'}</label>
                        <select
                            {...status.props}
                            value={form.data.status}
                            onChange={(event) => form.setData('status', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        >
                            {statuses.map((value) => (
                                <option key={value} value={value}>{statusLabels[value] ?? value}</option>
                            ))}
                        </select>
                        <p id="risk-status-hint" className={HINT}>
                            {tr.field_status_hint ?? 'Hvor risikoen er i livsløpet. Settes manuelt når arbeidet med risikoen går videre.'}
                        </p>
                        <FieldError id={status.errorId} message={errors.status} />
                    </div>
                )}
            </div>

            {! creating && (
                <div className="grid gap-4 md:grid-cols-3">
                    <div>
                        <label htmlFor="risk-review-interval" className={LABEL}>{trReview.field_interval ?? 'Vurderingsintervall'}</label>
                        <select
                            {...interval.props}
                            value={form.data.review_interval_months}
                            onChange={(event) => form.setData('review_interval_months', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        >
                            <option value="">{trReview.no_interval ?? 'Ingen fast intervall'}</option>
                            {reviewIntervals.map((months) => (
                                <option key={months} value={months}>{trReview.intervals?.[months] ?? months}</option>
                            ))}
                        </select>
                        <p id="risk-review-interval-hint" className={HINT}>
                            {trReview.field_interval_hint ?? 'Hvor ofte risikoen skal vurderes på nytt. Neste vurdering beregnes fra siste risikovurdering.'}
                        </p>
                        <FieldError id={interval.errorId} message={errors.review_interval_months} />
                    </div>

                    <div className="md:col-span-2">
                        <label htmlFor="risk-treatment-strategy" className={LABEL}>{tt.field ?? 'Behandlingsvalg'}</label>
                        <select
                            {...treatment.props}
                            value={strategy ?? ''}
                            onChange={(event) => form.setData('treatment_strategy', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        >
                            <option value="">{tt.none ?? 'Ikke besluttet ennå'}</option>
                            {treatmentStrategies.map((key) => (
                                <option key={key} value={key}>{tt.options?.[key] ?? TREATMENT_LABELS[key] ?? key}</option>
                            ))}
                        </select>
                        <p id="risk-treatment-strategy-hint" className={HINT}>
                            {strategy
                                ? (tt.explanations?.[strategy] ?? TREATMENT_EXPLANATIONS[strategy])
                                : (tt.field_hint ?? 'Overordnet retning for hvordan risikoen skal håndteres. Tiltak og aksept av restrisiko registreres i egne seksjoner.')}
                        </p>
                        {strategy === 'accept' && (
                            <p className="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-base text-amber-900">
                                {tt.accept_form_note ?? '«Akseptere» er bare retningen. Selve aksepten av restrisikoen registreres under Aksept av restrisiko, av en med rett til å akseptere risiko.'}
                            </p>
                        )}
                        <FieldError id={treatment.errorId} message={errors.treatment_strategy} />
                    </div>
                </div>
            )}

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
