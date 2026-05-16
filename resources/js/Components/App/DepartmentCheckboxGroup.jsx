function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

export default function DepartmentCheckboxGroup({ label, fieldName, options, selectedIds, onToggle, helperText, error }) {
    return (
        <div className="space-y-2 md:col-span-2">
            <span className="text-sm font-medium text-slate-700">{label}</span>
            {options.length > 0 ? (
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
                <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-500">
                    Ingen tilgjengelige avdelinger.
                </div>
            )}
            <p className="text-xs text-slate-400">{helperText}</p>
            {error ? <p className="text-sm text-rose-600">{error}</p> : null}
        </div>
    );
}
