import { FieldsetField } from './FormField';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

/**
 * Pick one or more departments. A group of checkboxes under one question, so it is a fieldset named
 * by the question (1.3.1, A11Y-120): before, the question was a loose <span>, and a screen reader on
 * a checkbox heard only the department's name — not whether ticking it meant "belongs to" or
 * "manages". The hint and the error describe the group, and an error marks the options invalid so a
 * rejected submit lands focus here. The helper text is slate-600 (it was slate-400, about 2.6:1).
 */
export default function DepartmentCheckboxGroup({ label, fieldName, options, selectedIds, onToggle, helperText, error }) {
    return (
        <FieldsetField
            id={`department-group-${fieldName}`}
            legend={label}
            hint={helperText}
            error={error}
            className="space-y-2 md:col-span-2"
            legendClassName="text-sm font-medium text-slate-700"
            hintClassName="text-xs text-slate-600"
            errorClassName="text-sm text-rose-700"
        >
            {(optionProps) => (options.length > 0 ? (
                <div className="max-h-[280px] overflow-y-auto pr-1">
                    <div className="grid gap-2 sm:grid-cols-2">
                        {options.map((option) => {
                            const checked = selectedIds.includes(option.value);

                            return (
                                <label
                                    key={option.value}
                                    className={classNames(
                                        'flex cursor-pointer items-center gap-3 rounded-2xl border px-4 py-3 text-sm transition',
                                        checked
                                            ? 'border-violet-300 bg-violet-50 text-violet-900'
                                            : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50',
                                    )}
                                >
                                    <input
                                        {...optionProps}
                                        type="checkbox"
                                        name={`${fieldName}[]`}
                                        value={option.value}
                                        checked={checked}
                                        onChange={() => onToggle(option.value)}
                                        className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                                    />
                                    <span>{option.label}</span>
                                </label>
                            );
                        })}
                    </div>
                </div>
            ) : (
                <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-600">
                    Ingen tilgjengelige avdelinger.
                </div>
            ))}
        </FieldsetField>
    );
}
