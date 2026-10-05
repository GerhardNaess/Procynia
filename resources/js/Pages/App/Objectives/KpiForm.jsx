import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import RequiredMark from '../Risk/RequiredMark';

const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-sm font-semibold text-slate-700';

/**
 * The form data a KPI starts from: its stored values when editing, the defaults when new.
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
        target_min: kpi?.target_min ?? '',
        target_max: kpi?.target_max ?? '',
        tolerance: kpi?.tolerance ?? '',
        frequency: kpi?.frequency ?? '',
        reporting_grace_days: String(kpi?.reporting_grace_days ?? options.default_grace_days ?? 7),
        owner_user_id: kpi?.owner_user_id ? String(kpi.owner_user_id) : '',
    };
}

/**
 * The fields of a KPI, shared by Ny KPI and Rediger. The unit decides which detail field shows:
 * a currency code for currency, a free label for counts and numbers, nothing otherwise — and a
 * field that does not belong is emptied, so the server never receives it. The bounds, tolerance
 * and owner are checked again on save. There is no status field: a KPI is created active and
 * leaves that state only by being retired.
 *
 * Once the KPI has measurements its unit, unit label and currency are locked (unitLocked): the
 * fields show what they are but cannot change, and the server refuses a change all the same.
 */
export default function KpiForm({ form, onSubmit, onCancel, options = {}, tr, unitLocked = false }) {
    const unitLabels = tr.units ?? {};
    const frequencyLabels = tr.frequencies ?? {};
    const labelledUnits = options.labelled_units ?? ['count', 'number'];
    const showsLabel = labelledUnits.includes(form.data.unit);
    const showsCurrency = form.data.unit === 'currency';
    const objectiveOwner = options.objective_owner_name;

    const changeUnit = (unit) => {
        form.setData((data) => ({
            ...data,
            unit,
            unit_label: labelledUnits.includes(unit) ? data.unit_label : '',
            currency_code: unit === 'currency' ? (data.currency_code || options.default_currency_code || 'NOK') : '',
        }));
    };

    const error = (field) => form.errors[field] && <p className="mt-1 text-sm text-rose-600">{form.errors[field]}</p>;

    return (
        <form onSubmit={onSubmit} className="space-y-4">
            <div>
                <label htmlFor="kpi-title" className={LABEL}>{tr.field_title ?? 'Tittel'}<RequiredMark /></label>
                <p id="kpi-title-hint" className="text-sm text-slate-600">
                    {tr.field_title_hint ?? 'Hva som måles, for eksempel «Oppetid» eller «Alvorlige avvik».'}
                </p>
                <input
                    id="kpi-title"
                    type="text"
                    required
                    aria-required="true"
                    aria-describedby="kpi-title-hint"
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {error('title')}
            </div>

            <div>
                <label htmlFor="kpi-description" className={LABEL}>{tr.field_description ?? 'Beskrivelse'}</label>
                <p id="kpi-description-hint" className="text-sm text-slate-600">
                    {tr.field_description_hint ?? 'Valgfritt. Hvordan KPI-en måles, og hvor tallet hentes fra.'}
                </p>
                <textarea
                    id="kpi-description"
                    rows={3}
                    aria-describedby="kpi-description-hint"
                    value={form.data.description}
                    onChange={(event) => form.setData('description', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {error('description')}
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <div>
                    <label htmlFor="kpi-unit" className={LABEL}>{tr.field_unit ?? 'Enhet'}<RequiredMark /></label>
                    <select
                        id="kpi-unit"
                        required
                        aria-required="true"
                        disabled={unitLocked}
                        aria-describedby={unitLocked ? 'kpi-unit-locked-hint' : undefined}
                        value={form.data.unit}
                        onChange={(event) => changeUnit(event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{tr.choose_unit ?? 'Velg enhet'}</option>
                        {(options.units ?? []).map((unit) => (
                            <option key={unit} value={unit}>{unitLabels[unit] ?? unit}</option>
                        ))}
                    </select>
                    {unitLocked && (
                        <p id="kpi-unit-locked-hint" className="mt-1 text-sm text-slate-500">
                            {tr.unit_locked_hint ?? 'Enheten er låst fordi KPI-en har målinger.'}
                        </p>
                    )}
                    {error('unit')}
                </div>

                {showsLabel && (
                    <div>
                        <label htmlFor="kpi-unit-label" className={LABEL}>{tr.field_unit_label ?? 'Benevning'}</label>
                        <input
                            id="kpi-unit-label"
                            type="text"
                            disabled={unitLocked}
                            aria-describedby="kpi-unit-label-hint"
                            value={form.data.unit_label}
                            onChange={(event) => form.setData('unit_label', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        />
                        <p id="kpi-unit-label-hint" className="mt-1 text-sm text-slate-500">
                            {tr.field_unit_label_hint ?? 'Valgfritt. For eksempel saker, hendelser eller brukere.'}
                        </p>
                        {error('unit_label')}
                    </div>
                )}

                {showsCurrency && (
                    <div>
                        <label htmlFor="kpi-currency-code" className={LABEL}>{tr.field_currency_code ?? 'Valuta'}<RequiredMark /></label>
                        <input
                            id="kpi-currency-code"
                            type="text"
                            disabled={unitLocked}
                            required
                            aria-required="true"
                            maxLength={3}
                            aria-describedby="kpi-currency-code-hint"
                            value={form.data.currency_code}
                            onChange={(event) => form.setData('currency_code', event.target.value.toUpperCase())}
                            className={`mt-1 ${INPUT}`}
                        />
                        <p id="kpi-currency-code-hint" className="mt-1 text-sm text-slate-500">
                            {tr.field_currency_code_hint ?? 'Tre bokstaver, for eksempel NOK.'}
                        </p>
                        {error('currency_code')}
                    </div>
                )}
            </div>

            <fieldset className="rounded-2xl border border-slate-200 p-4">
                <legend className="px-1 text-base font-semibold text-slate-900">{tr.target_heading ?? 'Målverdi'}</legend>
                <p className="text-sm text-slate-600">
                    {tr.target_hint ?? 'Fyll inn minst én grense. Fyller du inn begge, er målet et intervall.'}
                </p>
                <div className="mt-3 grid gap-4 md:grid-cols-3">
                    <div>
                        <label htmlFor="kpi-target-min" className={LABEL}>{tr.field_target_min ?? 'Minst'}</label>
                        <input
                            id="kpi-target-min"
                            type="text"
                            inputMode="decimal"
                            value={form.data.target_min}
                            onChange={(event) => form.setData('target_min', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        />
                        {error('target_min')}
                    </div>
                    <div>
                        <label htmlFor="kpi-target-max" className={LABEL}>{tr.field_target_max ?? 'Høyst'}</label>
                        <input
                            id="kpi-target-max"
                            type="text"
                            inputMode="decimal"
                            value={form.data.target_max}
                            onChange={(event) => form.setData('target_max', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        />
                        {error('target_max')}
                    </div>
                    <div>
                        <label htmlFor="kpi-tolerance" className={LABEL}>{tr.field_tolerance ?? 'Slingringsmonn'}</label>
                        <input
                            id="kpi-tolerance"
                            type="text"
                            inputMode="decimal"
                            aria-describedby="kpi-tolerance-hint"
                            value={form.data.tolerance}
                            onChange={(event) => form.setData('tolerance', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        />
                        {error('tolerance')}
                    </div>
                </div>
                <p id="kpi-tolerance-hint" className="mt-2 text-sm text-slate-500">
                    {tr.field_tolerance_hint ?? 'Valgfritt. Tomt eller 0 betyr at resultatet enten er på mål eller utenfor.'}
                </p>
            </fieldset>

            <div className="grid gap-4 md:grid-cols-3">
                <div>
                    <label htmlFor="kpi-frequency" className={LABEL}>{tr.field_frequency ?? 'Frekvens'}</label>
                    <select
                        id="kpi-frequency"
                        value={form.data.frequency}
                        onChange={(event) => form.setData('frequency', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{tr.no_frequency ?? 'Ingen fast frekvens'}</option>
                        {(options.frequencies ?? []).map((frequency) => (
                            <option key={frequency} value={frequency}>{frequencyLabels[frequency] ?? frequency}</option>
                        ))}
                    </select>
                    {error('frequency')}
                </div>

                <div>
                    <label htmlFor="kpi-grace-days" className={LABEL}>{tr.field_grace_days ?? 'Innrapporteringsfrist (dager)'}<RequiredMark /></label>
                    <input
                        id="kpi-grace-days"
                        type="number"
                        min={0}
                        max={365}
                        step={1}
                        required
                        aria-required="true"
                        aria-describedby="kpi-grace-days-hint"
                        value={form.data.reporting_grace_days}
                        onChange={(event) => form.setData('reporting_grace_days', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <p id="kpi-grace-days-hint" className="mt-1 text-sm text-slate-500">
                        {tr.field_grace_days_hint ?? 'Antall dager etter at en periode er slutt før målingen for perioden regnes som manglende.'}
                    </p>
                    {error('reporting_grace_days')}
                </div>

                <div>
                    <label htmlFor="kpi-owner" className={LABEL}>{tr.field_owner ?? 'Ansvarlig'}</label>
                    <select
                        id="kpi-owner"
                        aria-describedby="kpi-owner-hint"
                        value={form.data.owner_user_id}
                        onChange={(event) => form.setData('owner_user_id', event.target.value)}
                        className={`mt-1 ${INPUT}`}
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
                    <p id="kpi-owner-hint" className="mt-1 text-sm text-slate-500">
                        {tr.field_owner_hint ?? 'Valgfritt. Uten egen ansvarlig er målets ansvarlige også ansvarlig for KPI-en.'}
                    </p>
                    {error('owner_user_id')}
                </div>
            </div>

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
