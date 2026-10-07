import RequiredMark from '../Risk/RequiredMark';
import { CRITICALITY_QUESTIONS, chooseCriticality, criticalityLabel, intervalLabel, intervalRequired } from './supplierManagement';

const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LEGEND = 'text-base font-semibold text-slate-900';
const HINT = 'text-base text-slate-600';
const ERROR = 'text-base text-rose-700';

function FieldError({ message }) {
    return message ? <p className={ERROR}>{message}</p> : null;
}

/**
 * Hvor kritisk er leverandøren?: the four ja/nei questions first, then the level the person chooses,
 * then how often the supplier should be reviewed. Shared by Registrer leverandør and Vurder/Endre
 * kritikalitet.
 *
 * The answers are the basis for the choice, never its source: nothing here reads them to pick,
 * suggest or limit a level. Choosing a level fills in that level's usual interval, which the person
 * may change; the interval is asked for only once a level is chosen, and is required for Viktig and
 * Kritisk.
 */
export default function CriticalityFields({ form, reviewIntervals = [], idPrefix, tr }) {
    const c = tr.criticality ?? {};
    const errors = form.errors;
    const level = form.data.criticality;
    const required = intervalRequired(level);

    return (
        <div className="space-y-5" data-testid="criticality-fields">
            <fieldset className="space-y-3">
                <legend className={LEGEND}>{c.questions_heading ?? 'Beslutningsgrunnlag'}</legend>
                {CRITICALITY_QUESTIONS.map((question) => (
                    <fieldset key={question} className="relative rounded-xl border border-slate-200 px-4 py-3" data-testid={`criticality-question-${question}`}>
                        <legend className="sr-only">{c.questions?.[question] ?? question}</legend>
                        <p className="text-base text-slate-900" aria-hidden="true">{c.questions?.[question] ?? question}<RequiredMark /></p>
                        <div className="mt-2 flex flex-wrap gap-x-6 gap-y-2">
                            {[true, false].map((answer) => (
                                <label key={String(answer)} className="flex min-h-10 items-center gap-2 text-base text-slate-900">
                                    <input
                                        type="radio"
                                        name={`${idPrefix}-${question}`}
                                        checked={form.data[question] === answer}
                                        onChange={() => form.setData(question, answer)}
                                        className="h-5 w-5 shrink-0"
                                    />
                                    {answer ? (c.yes ?? 'Ja') : (c.no ?? 'Nei')}
                                </label>
                            ))}
                        </div>
                        <FieldError message={errors[question]} />
                    </fieldset>
                ))}
            </fieldset>

            <fieldset className="space-y-2" data-testid="criticality-level">
                <legend className={LEGEND}>{c.level_label ?? 'Kritikalitet'}<RequiredMark /></legend>
                {['standard', 'important', 'critical'].map((value) => (
                    <label key={value} className="flex items-start gap-3 rounded-xl border border-slate-200 px-3 py-2">
                        <input
                            type="radio"
                            name={`${idPrefix}-criticality`}
                            value={value}
                            checked={level === value}
                            onChange={() => form.setData((data) => chooseCriticality(data, value))}
                            className="mt-1 h-5 w-5 shrink-0"
                        />
                        <span className="min-w-0">
                            <span className="block text-base font-semibold text-slate-900">{criticalityLabel(value, tr)}</span>
                            <span className="block text-base text-slate-600">{c.level_hints?.[value] ?? ''}</span>
                        </span>
                    </label>
                ))}
                <FieldError message={errors.criticality} />
            </fieldset>

            {level && (
                <div>
                    <label htmlFor={`${idPrefix}-interval`} className={LEGEND}>
                        {c.interval_label ?? 'Hvor ofte skal leverandøren vurderes?'}
                        {required && <RequiredMark />}
                    </label>
                    <p id={`${idPrefix}-interval-hint`} className={`mt-1 ${HINT}`}>
                        {required
                            ? (c.interval_hint_required ?? 'Viktige og kritiske leverandører må ha et vurderingsintervall.')
                            : (c.interval_hint_optional ?? 'Valgfritt for standard leverandører.')}
                    </p>
                    <select
                        id={`${idPrefix}-interval`}
                        required={required}
                        aria-required={required ? 'true' : undefined}
                        aria-describedby={`${idPrefix}-interval-hint`}
                        value={form.data.review_interval_months}
                        onChange={(event) => form.setData('review_interval_months', event.target.value)}
                        className={`mt-1 ${INPUT} md:max-w-xs`}
                    >
                        {! required && <option value="">{c.interval_none ?? 'Ingen fast vurdering'}</option>}
                        {required && form.data.review_interval_months === '' && <option value="">{c.interval_label ?? 'Hvor ofte skal leverandøren vurderes?'}</option>}
                        {reviewIntervals.map((months) => (
                            <option key={months} value={String(months)}>{intervalLabel(months, tr)}</option>
                        ))}
                    </select>
                    <FieldError message={errors.review_interval_months} />
                </div>
            )}
        </div>
    );
}
