import { INPUT } from '../../../Support/formStyles';
import { FieldError, RequiredStar, groupA11y } from './SupplierField';
import { CRITICALITY_QUESTIONS, chooseCriticality, criticalityLabel, intervalLabel, intervalRequired } from './supplierManagement';

const LEGEND = 'text-base font-semibold text-slate-900';
const HINT = 'text-base text-slate-600';
const ERROR = 'text-base text-rose-700';

/**
 * Hvor kritisk er leverandøren?: the four ja/nei questions first, then the level the person chooses,
 * then how often the supplier should be reviewed. Shared by Registrer leverandør and Vurder/Endre
 * kritikalitet.
 *
 * The answers are the basis for the choice, never its source: nothing here reads them to pick,
 * suggest or limit a level. Choosing a level fills in that level's usual interval, which the person
 * may change; the interval is asked for only once a level is chosen, and is required for Viktig and
 * Kritisk.
 *
 * Each question and the level are groups described by their error, with every option aria-invalid
 * while it is wrong (A11Y-120, A11Y-013); the interval select is tied to its hint and error.
 */
export default function CriticalityFields({ form, reviewIntervals = [], idPrefix, tr }) {
    const c = tr.criticality ?? {};
    const errors = form.errors;
    const level = form.data.criticality;
    const required = intervalRequired(level);
    const levelGroup = groupA11y(`${idPrefix}-criticality-level`, { error: errors.criticality });
    const intervalId = `${idPrefix}-interval`;
    const intervalError = errors.review_interval_months;

    return (
        <div className="space-y-5" data-testid="criticality-fields">
            <fieldset className="space-y-3">
                <legend className={LEGEND}>{c.questions_heading ?? 'Beslutningsgrunnlag'}</legend>
                {CRITICALITY_QUESTIONS.map((question) => {
                    const group = groupA11y(`${idPrefix}-${question}`, { error: errors[question] });

                    return (
                    <fieldset key={question} className="relative rounded-xl border border-slate-200 px-4 py-3" data-testid={`criticality-question-${question}`} {...group.groupProps}>
                        <legend className="sr-only">{c.questions?.[question] ?? question}</legend>
                        <p className="text-base text-slate-900" aria-hidden="true">{c.questions?.[question] ?? question}<RequiredStar /></p>
                        <div className="mt-2 flex flex-wrap gap-x-6 gap-y-2">
                            {[true, false].map((answer) => (
                                <label key={String(answer)} className="flex min-h-10 items-center gap-2 text-base text-slate-900">
                                    <input
                                        type="radio"
                                        name={`${idPrefix}-${question}`}
                                        checked={form.data[question] === answer}
                                        onChange={() => form.setData(question, answer)}
                                        className="h-5 w-5 shrink-0"
                                        {...group.optionProps}
                                    />
                                    {answer ? (c.yes ?? 'Ja') : (c.no ?? 'Nei')}
                                </label>
                            ))}
                        </div>
                        <FieldError id={group.errorId} message={errors[question]} className={ERROR} />
                    </fieldset>
                    );
                })}
            </fieldset>

            <fieldset className="space-y-2" data-testid="criticality-level" {...levelGroup.groupProps}>
                <legend className={LEGEND}>{c.level_label ?? 'Kritikalitet'}<RequiredStar /></legend>
                {['standard', 'important', 'critical'].map((value) => (
                    <label key={value} className="flex items-start gap-3 rounded-xl border border-slate-200 px-3 py-2">
                        <input
                            type="radio"
                            name={`${idPrefix}-criticality`}
                            value={value}
                            checked={level === value}
                            onChange={() => form.setData((data) => chooseCriticality(data, value))}
                            className="mt-1 h-5 w-5 shrink-0"
                            {...levelGroup.optionProps}
                        />
                        <span className="min-w-0">
                            <span className="block text-base font-semibold text-slate-900">{criticalityLabel(value, tr)}</span>
                            <span className="block text-base text-slate-600">{c.level_hints?.[value] ?? ''}</span>
                        </span>
                    </label>
                ))}
                <FieldError id={levelGroup.errorId} message={errors.criticality} className={ERROR} />
            </fieldset>

            {level && (
                <div>
                    <label htmlFor={`${idPrefix}-interval`} className={LEGEND}>
                        {c.interval_label ?? 'Hvor ofte skal leverandøren vurderes?'}
                        {required && <RequiredStar />}
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
                        aria-describedby={intervalError ? `${intervalId}-hint ${intervalId}-error` : `${intervalId}-hint`}
                        aria-invalid={intervalError ? true : undefined}
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
                    <FieldError id={`${intervalId}-error`} message={intervalError} className={ERROR} />
                </div>
            )}
        </div>
    );
}
