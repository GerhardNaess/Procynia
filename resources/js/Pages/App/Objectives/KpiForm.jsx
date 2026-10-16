import { useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import FormField, { RequiredFieldsNote } from '../../../Components/App/FormField';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { useFocusFirstError } from '../../../Support/focusManagement';
import { fieldA11y } from '../../../Support/formFieldA11y';
import { ERROR, HINT, INPUT, LABEL, TEXTAREA } from '../../../Support/formStyles';
import { kpiUnitSuffix } from './kpiUnitSuffix';
import { formatKpiAmount, resolvedTarget, targetFields, targetRuleText, toleranceRuleText, TARGET_TYPES, unitProblem, unitProblemMessage } from './kpiTarget';
import UnitInput from './UnitInput';
import { useKpiNumberFields } from './useKpiNumberFields';

// The «*» FormField draws, for the fields wired by hand; the field itself carries aria-required.
const STAR = <span aria-hidden="true" className="ml-0.5 text-rose-700">*</span>;
const STEP = 'space-y-4 rounded-2xl border border-slate-200 p-4';
const LEGEND = 'px-1 text-base font-semibold text-slate-900';
const PERCENT_UNITS = ['percent', 'percent_change'];

/**
 * The form data a KPI starts from: its stored values when editing, the defaults when new. A new
 * KPI starts as «Minst», the most common måltype.
 *
 * @param {object|null} kpi      The KPI detail from the server, or null for Ny KPI.
 * @param {object} options       kpi form options from the server.
 */
export function initialKpiData(kpi, options = {}) {
    return {
        title: kpi?.title ?? '',
        description: kpi?.description ?? '',
        unit: kpi?.unit ?? '',
        unit_label: kpi?.unit_label ?? '',
        currency_code: kpi?.currency_code ?? '',
        target_type: kpi?.form_target_type ?? 'at_least',
        target_min: kpi?.target_min ?? '',
        target_max: kpi?.target_max ?? '',
        tolerance: kpi?.tolerance ?? '',
        baseline_value: kpi?.baseline_value ?? '',
        baseline_date: kpi?.baseline_date ?? options.today ?? '',
        change_percent: kpi?.change_percent ?? '',
        frequency: kpi?.frequency ?? '',
        reporting_grace_days: String(kpi?.reporting_grace_days ?? options.default_grace_days ?? 7),
        owner_user_id: kpi?.owner_user_id ? String(kpi.owner_user_id) : '',
    };
}

/**
 * The fields of a KPI, shared by Ny KPI and Rediger, in the order the person thinks about it:
 *
 *  1. Hva skal måles? — title, description, owner
 *  2. Hvilken enhet brukes? — the unit, and a currency or a label where the unit takes one
 *  3. Hva er ønsket resultat? — the måltype and only its own fields, with the rule stated live:
 *     «Målet er nådd når verdien er minst NOK 1 300 000.» Endring fra startverdi computes the
 *     målverdi from a startverdi and a change in per cent (not for the percent units).
 *  4. Når skal det være oppnådd? — the objective's måldato, which the KPI follows, and how often
 *     it is measured
 *  5. Avanserte innstillinger — toleranse and innrapporteringsfrist, folded away with defaults
 *
 * The server stores min/max; the person never sees them as such. Only the chosen måltype's fields
 * are checked before sending, and the server reads only those (target_type). Numbers are checked
 * against the unit's rules as on the server (KpiUnitRules), and every rule is checked again there.
 *
 * Once the KPI has measurements its unit, unit label and currency are locked (unitLocked): only
 * Prosentandel and Prosentvis endring can still be swapped, since both are written in per cent.
 *
 * Every field is tied to its hint and error, and after a refused save focus goes to the first field
 * that is wrong (A11Y-013): onSubmit gets that request as its second argument, for the visit's onError.
 */
export default function KpiForm({ form, onSubmit, onCancel, options = {}, tr, unitLocked = false }) {
    const unitLabels = tr.units ?? {};
    const unitHints = tr.unit_hints ?? {};
    const typeLabels = tr.target_types ?? {};
    const typeHints = tr.target_type_hints ?? {};
    const frequencyLabels = tr.frequencies ?? {};
    const labelledUnits = options.labelled_units ?? ['count', 'number'];
    const growthUnits = options.growth_units ?? ['currency', 'count', 'number', 'hours', 'days'];
    const rules = options.unit_rules ?? {};
    const { data } = form;
    const showsLabel = labelledUnits.includes(data.unit);
    const showsCurrency = data.unit === 'currency';
    const lockedToPercent = unitLocked && PERCENT_UNITS.includes(data.unit);
    const objectiveOwner = options.objective_owner_name;
    const { locale = 'no' } = usePage().props;
    const deadline = options.deadline ?? null;
    const [advancedOpen, setAdvancedOpen] = useState(Boolean(Number(data.tolerance)) || String(data.reporting_grace_days) !== String(options.default_grace_days ?? 7));

    // The unit shown inside each number field, following the unit chosen above as it changes.
    const suffixFor = (field) => kpiUnitSuffix(data, tr, data[field]);
    const toleranceSuffix = PERCENT_UNITS.includes(data.unit) ? (tr.input_suffix?.percentage_points ?? '%-poeng') : suffixFor('tolerance');

    const ruleProblem = (field, value) => {
        if (field === 'change_percent') {
            return null;
        }

        return unitProblemMessage(unitProblem(value, data.unit, rules, field === 'tolerance'), data.unit, rules, tr);
    };

    const numbers = useKpiNumberFields(form, tr, locale, { ruleProblem });
    // After a refused save — here, or by the server — focus goes to the first field that is wrong.
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);
    const visibleNumberFields = [...targetFields(data.target_type), 'tolerance'];
    const rule = targetRuleText(data, tr, locale, rules);
    const toleranceRule = toleranceRuleText(data, tr, locale, rules);
    const computed = data.target_type === 'growth' ? resolvedTarget(data, rules) : null;

    const submit = (event) => {
        if (! numbers.checkBeforeSubmit(visibleNumberFields)) {
            event.preventDefault();
            setAdvancedOpen((open) => open || Boolean(form.errors.tolerance));
            focusFirstError();

            return;
        }

        onSubmit(event, focusFirstError);
    };

    const changeUnit = (unit) => {
        form.setData((current) => ({
            ...current,
            unit,
            unit_label: labelledUnits.includes(unit) ? current.unit_label : '',
            currency_code: unit === 'currency' ? (current.currency_code || options.default_currency_code || 'NOK') : '',
            // A change from a startverdi is not offered in per cent; fall back to «Minst».
            target_type: current.target_type === 'growth' && ! growthUnits.includes(unit) ? 'at_least' : current.target_type,
        }));
    };

    // The advanced settings open by themselves when the server reports a problem in them.
    const advancedVisible = advancedOpen || Boolean(form.errors.tolerance || form.errors.reporting_grace_days);

    // A number field: label, the unit inside the field, hint and error tied to it (A11Y-013).
    const numberField = (field, id, label, { hint = null, suffix = suffixFor(field), required = true } = {}) => (
        <FormField id={id} label={label} hint={hint} error={form.errors[field]} required={required}>
            {(control) => (
                <UnitInput
                    id={control.id}
                    suffix={suffix}
                    required={required}
                    aria-required={control['aria-required']}
                    describedBy={control['aria-describedby'] ?? null}
                    {...numbers.bind(field)}
                />
            )}
        </FormField>
    );

    // Fields whose hint sits above the control, as before; wired the way FormField does it.
    const title = fieldA11y({ id: 'kpi-title', hasHint: true, hasError: Boolean(form.errors.title), required: true });
    const description = fieldA11y({ id: 'kpi-description', hasHint: true, hasError: Boolean(form.errors.description) });
    const unitHint = unitHints[data.unit] ?? '';
    const unitDescribedBy = [unitHint ? 'kpi-unit-hint' : null, unitLocked ? 'kpi-unit-locked-hint' : null, form.errors.unit ? 'kpi-unit-error' : null]
        .filter(Boolean).join(' ') || undefined;
    const targetTypeDescribedBy = ['kpi-target-type-hint', form.errors.target_type ? 'kpi-target-type-error' : null].filter(Boolean).join(' ');

    return (
        <form ref={formRef} onSubmit={submit} className="space-y-4">
            <fieldset className={STEP}>
                <legend className={LEGEND}>{`1. ${tr.step_what ?? 'Hva skal måles?'}`}</legend>
                <div>
                    <label htmlFor="kpi-title" className={LABEL}>{tr.field_title ?? 'Tittel'}{STAR}</label>
                    <p id={title.hintId} className="text-base text-slate-600">
                        {tr.field_title_hint ?? 'Hva som måles, for eksempel «Oppetid» eller «Alvorlige avvik».'}
                    </p>
                    <input
                        {...title.controlProps}
                        type="text"
                        required
                        value={data.title}
                        onChange={(event) => form.setData('title', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {form.errors.title && <p id={title.errorId} className={ERROR}>{form.errors.title}</p>}
                </div>

                <div>
                    <label htmlFor="kpi-description" className={LABEL}>{tr.field_description ?? 'Beskrivelse'}</label>
                    <p id={description.hintId} className="text-base text-slate-600">
                        {tr.field_description_hint ?? 'Valgfritt. Hvordan KPI-en måles, og hvor tallet hentes fra.'}
                    </p>
                    <textarea
                        {...description.controlProps}
                        rows={3}
                        value={data.description}
                        onChange={(event) => form.setData('description', event.target.value)}
                        className={`mt-1 ${TEXTAREA}`}
                    />
                    {form.errors.description && <p id={description.errorId} className={ERROR}>{form.errors.description}</p>}
                </div>

                <FormField
                    id="kpi-owner"
                    label={tr.field_owner ?? 'Ansvarlig'}
                    hint={tr.field_owner_hint ?? 'Valgfritt. Uten egen ansvarlig er målets ansvarlige også ansvarlig for KPI-en.'}
                    error={form.errors.owner_user_id}
                >
                    {(field) => (
                        <select
                            {...field}
                            value={data.owner_user_id}
                            onChange={(event) => form.setData('owner_user_id', event.target.value)}
                            className={INPUT}
                        >
                            <option value="">
                                {objectiveOwner
                                    ? (tr.owner_fallback_option ?? 'Målets ansvarlig (:name)').replace(':name', objectiveOwner)
                                    : (tr.owner_fallback_option_none ?? 'Målets ansvarlig')}
                            </option>
                            {(options.owner_options ?? []).map((owner) => (
                                <option key={owner.id} value={owner.id}>{owner.name}</option>
                            ))}
                        </select>
                    )}
                </FormField>
            </fieldset>

            <fieldset className={STEP}>
                <legend className={LEGEND}>{`2. ${tr.step_unit ?? 'Hvilken enhet brukes?'}`}</legend>
                <div className="grid gap-4 md:grid-cols-2">
                    <div>
                        <label htmlFor="kpi-unit" className={LABEL}>{tr.field_unit ?? 'Enhet'}{STAR}</label>
                        <select
                            id="kpi-unit"
                            required
                            aria-required="true"
                            aria-invalid={form.errors.unit ? true : undefined}
                            disabled={unitLocked && ! lockedToPercent}
                            aria-describedby={unitDescribedBy}
                            value={data.unit}
                            onChange={(event) => changeUnit(event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        >
                            <option value="">{tr.choose_unit ?? 'Velg enhet'}</option>
                            {(options.units ?? [])
                                .filter((unit) => ! lockedToPercent || PERCENT_UNITS.includes(unit))
                                .map((unit) => (
                                    <option key={unit} value={unit}>{unitLabels[unit] ?? unit}</option>
                                ))}
                        </select>
                        <p id="kpi-unit-hint" className={HINT}>{unitHint}</p>
                        {unitLocked && (
                            <p id="kpi-unit-locked-hint" className={HINT}>
                                {tr.unit_locked_hint ?? 'Enheten er låst fordi KPI-en har målinger.'}
                            </p>
                        )}
                        {form.errors.unit && <p id="kpi-unit-error" className={ERROR}>{form.errors.unit}</p>}
                    </div>

                    {showsLabel && (
                        <FormField
                            id="kpi-unit-label"
                            label={tr.field_unit_label ?? 'Benevning'}
                            hint={tr.field_unit_label_hint ?? 'Valgfritt. For eksempel saker, hendelser eller brukere.'}
                            error={form.errors.unit_label}
                        >
                            {(field) => (
                                <input
                                    {...field}
                                    type="text"
                                    disabled={unitLocked}
                                    value={data.unit_label}
                                    onChange={(event) => form.setData('unit_label', event.target.value)}
                                    className={INPUT}
                                />
                            )}
                        </FormField>
                    )}

                    {showsCurrency && (
                        <FormField
                            id="kpi-currency-code"
                            label={tr.field_currency_code ?? 'Valuta'}
                            hint={tr.field_currency_code_hint ?? 'Tre bokstaver, for eksempel NOK.'}
                            error={form.errors.currency_code}
                            required
                        >
                            {(field) => (
                                <input
                                    {...field}
                                    type="text"
                                    disabled={unitLocked}
                                    required
                                    maxLength={3}
                                    value={data.currency_code}
                                    onChange={(event) => form.setData('currency_code', event.target.value.toUpperCase())}
                                    className={INPUT}
                                />
                            )}
                        </FormField>
                    )}
                </div>
            </fieldset>

            <fieldset className={STEP}>
                <legend className={LEGEND}>{`3. ${tr.step_target ?? 'Hva er ønsket resultat?'}`}</legend>
                <p className="text-base text-slate-600">{tr.target_hint ?? 'Velg hva som regnes som måloppnåelse, og fyll inn feltene som hører til.'}</p>

                <div role="radiogroup" aria-labelledby="kpi-target-type-label" aria-describedby={targetTypeDescribedBy} aria-required="true" aria-invalid={form.errors.target_type ? true : undefined}>
                    <p id="kpi-target-type-label" className={LABEL}>{tr.field_target_type ?? 'Måltype'}{STAR}</p>
                    <div className="mt-2 flex flex-wrap gap-2">
                        {TARGET_TYPES.map((type) => {
                            const unavailable = type === 'growth' && data.unit !== '' && ! growthUnits.includes(data.unit);
                            const selected = data.target_type === type;

                            return (
                                <label
                                    key={type}
                                    className={`inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-base ${
                                        selected ? 'border-violet-500 bg-violet-50 font-semibold text-violet-900' : 'border-field-border bg-white text-slate-800'
                                    } ${unavailable ? 'cursor-not-allowed opacity-50' : ''}`}
                                >
                                    <input
                                        type="radio"
                                        name="kpi-target-type"
                                        value={type}
                                        checked={selected}
                                        disabled={unavailable}
                                        onChange={() => form.setData('target_type', type)}
                                        className="h-4 w-4 accent-violet-700"
                                    />
                                    {typeLabels[type] ?? type}
                                </label>
                            );
                        })}
                    </div>
                    <p id="kpi-target-type-hint" className="mt-2 text-base text-slate-600">
                        {data.unit !== '' && ! growthUnits.includes(data.unit) && data.target_type !== 'growth'
                            ? `${typeHints[data.target_type] ?? ''} ${tr.growth_unavailable ?? ''}`.trim()
                            : (typeHints[data.target_type] ?? '')}
                    </p>
                    {form.errors.target_type && <p id="kpi-target-type-error" className={ERROR}>{form.errors.target_type}</p>}
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    {data.target_type === 'at_least' && numberField('target_min', 'kpi-target-min', tr.target_types?.at_least ?? 'Minst')}
                    {data.target_type === 'at_most' && numberField('target_max', 'kpi-target-max', tr.target_types?.at_most ?? 'Høyst')}
                    {data.target_type === 'exact' && numberField('target_min', 'kpi-target-min', tr.field_target_value ?? 'Målverdi')}
                    {data.target_type === 'range' && (
                        <>
                            {numberField('target_min', 'kpi-target-min', tr.field_range_min ?? 'Fra')}
                            {numberField('target_max', 'kpi-target-max', tr.field_range_max ?? 'Til')}
                        </>
                    )}
                    {data.target_type === 'growth' && (
                        <>
                            {numberField('baseline_value', 'kpi-baseline', tr.field_baseline ?? 'Startverdi', { hint: tr.field_baseline_hint })}
                            <FormField id="kpi-baseline-date" label={tr.field_baseline_date ?? 'Dato for startverdi'} error={form.errors.baseline_date} required>
                                {(field) => (
                                    <input
                                        {...field}
                                        type="date"
                                        required
                                        value={data.baseline_date}
                                        onChange={(event) => form.setData('baseline_date', event.target.value)}
                                        className={INPUT}
                                    />
                                )}
                            </FormField>
                            {numberField('change_percent', 'kpi-change', tr.field_change ?? 'Ønsket endring', { hint: tr.field_change_hint, suffix: '%' })}
                            <div>
                                <p className={LABEL}>{tr.computed_target ?? 'Beregnet målverdi'}</p>
                                <p className="mt-1 min-h-10 py-2 text-base font-semibold text-slate-900" data-testid="kpi-computed-target">
                                    {computed ? formatKpiAmount(computed.min ?? computed.max, data, tr, locale) : '—'}
                                </p>
                            </div>
                        </>
                    )}
                </div>

                <div className="rounded-xl bg-slate-50 px-4 py-3" aria-live="polite" data-testid="kpi-rule-preview">
                    <p className="text-base font-semibold text-slate-700">{tr.rule_label ?? 'Når er målet nådd?'}</p>
                    <p className="mt-1 text-base text-slate-900">{rule ?? (tr.rule_preview_empty ?? 'Fyll inn målverdien for å se når målet er nådd.')}</p>
                    {toleranceRule && <p className="mt-1 text-base text-slate-600">{toleranceRule}</p>}
                </div>
            </fieldset>

            <fieldset className={STEP}>
                <legend className={LEGEND}>{`4. ${tr.step_when ?? 'Når skal det være oppnådd?'}`}</legend>
                <div className="grid gap-4 md:grid-cols-2">
                    <div>
                        <p className={LABEL}>{tr.detail_goal_deadline ?? 'Frist for måloppnåelse'}</p>
                        <p className="mt-1 text-base text-slate-900" data-testid="kpi-form-deadline">
                            {deadline
                                ? (tr.deadline_follows ?? ':date (målets måldato)').replace(':date', deadline.date_display)
                                : (tr.deadline_running ?? 'Løpende – målet har ingen måldato')}
                        </p>
                        <p className="mt-1 text-base text-slate-600">{tr.deadline_form_hint ?? 'KPI-en følger målets måldato. Endre måldatoen på målet.'}</p>
                    </div>
                    <FormField id="kpi-frequency" label={tr.field_frequency ?? 'Målefrekvens'} error={form.errors.frequency}>
                        {(field) => (
                            <select
                                {...field}
                                value={data.frequency}
                                onChange={(event) => form.setData('frequency', event.target.value)}
                                className={INPUT}
                            >
                                <option value="">{tr.no_frequency ?? 'Ingen fast målefrekvens'}</option>
                                {(options.frequencies ?? []).map((frequency) => (
                                    <option key={frequency} value={frequency}>{frequencyLabels[frequency] ?? frequency}</option>
                                ))}
                            </select>
                        )}
                    </FormField>
                </div>
            </fieldset>

            <div className="rounded-2xl border border-slate-200">
                <button
                    type="button"
                    aria-expanded={advancedVisible}
                    aria-controls="kpi-advanced"
                    onClick={() => setAdvancedOpen(! advancedVisible)}
                    className="flex min-h-10 w-full items-center justify-between gap-3 rounded-2xl px-4 py-3 text-left text-base font-semibold text-slate-900 hover:bg-slate-50"
                >
                    <span>
                        {`5. ${tr.advanced_heading ?? 'Avanserte innstillinger'}`}
                        <span className="block text-base font-normal text-slate-600">{tr.advanced_hint ?? 'Toleranse og innrapporteringsfrist.'}</span>
                    </span>
                    <span aria-hidden="true" className="text-slate-600">{advancedVisible ? '▲' : '▼'}</span>
                </button>
                {advancedVisible && (
                    <div id="kpi-advanced" className="grid gap-4 border-t border-slate-200 p-4 md:grid-cols-2">
                        {numberField('tolerance', 'kpi-tolerance', tr.field_tolerance ?? 'Toleranse', {
                            hint: tr.field_tolerance_hint,
                            suffix: toleranceSuffix,
                            required: false,
                        })}
                        <FormField
                            id="kpi-grace-days"
                            label={tr.field_grace_days ?? 'Innrapporteringsfrist (dager)'}
                            hint={tr.field_grace_days_hint ?? 'Antall dager etter at en periode er slutt før målingen for perioden regnes som manglende.'}
                            error={form.errors.reporting_grace_days}
                            required
                        >
                            {(field) => (
                                <input
                                    {...field}
                                    type="number"
                                    min={0}
                                    max={365}
                                    step={1}
                                    required
                                    value={data.reporting_grace_days}
                                    onChange={(event) => form.setData('reporting_grace_days', event.target.value)}
                                    className={INPUT}
                                />
                            )}
                        </FormField>
                    </div>
                )}
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
