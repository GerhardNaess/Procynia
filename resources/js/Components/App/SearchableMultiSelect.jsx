import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { filterPickerOptions } from './pickerFilter';

/**
 * A form field for choosing several items from a list too long to show: one compact search field
 * that opens a list while in use, with the chosen items as removable chips below it. Unlike
 * MultiSelectFilterDropdown — a filter where nothing chosen means «all» — nothing chosen here means
 * nothing chosen.
 *
 * The list opens below the field in the page flow, never as an overlay, so no scrolling ancestor or
 * small screen can clip it. Keyboard: arrows move, Enter chooses or removes, Escape closes, Backspace
 * in an empty field removes the last chip.
 *
 * Selection is controlled by the caller (values / onChange as an array of option values).
 *
 * @param {string} id - id of the search field; the caller's <label htmlFor> points at it.
 * @param {list<{value: string|number, label: string, description?: string, keywords?: string}>} options
 */
export default function SearchableMultiSelect({
    id,
    options,
    values,
    onChange,
    placeholder,
    searchLabel,
    noResultsLabel,
    removeLabel,
    selectedLabel,
    describedBy,
    testId,
}) {
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const rootRef = useRef(null);
    const inputRef = useRef(null);
    const listId = `${useId().replace(/:/g, '')}-list`;

    const chosen = useMemo(() => values.map((value) => options.find((option) => option.value === value)).filter(Boolean), [values, options]);
    const visible = useMemo(() => filterPickerOptions(options, query), [options, query]);

    // Closing forgets the search, so the field opens on the whole list next time.
    const close = () => {
        setOpen(false);
        setQuery('');
    };

    // Closed on the outside click, not on its mousedown: the list sits in the page flow, so closing
    // it at mousedown moves whatever is below it — typically the submit button — before the mouse is
    // released, and the click the user aimed at that button lands on nothing.
    useEffect(() => {
        if (! open) return undefined;
        const onClick = (event) => {
            if (! rootRef.current?.contains(event.target)) close();
        };
        document.addEventListener('click', onClick);

        return () => document.removeEventListener('click', onClick);
    }, [open]);

    useEffect(() => setActive(0), [query]);

    const toggle = (value) => onChange(values.includes(value) ? values.filter((item) => item !== value) : [...values, value]);

    const onKeyDown = (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (! open) {
                setOpen(true);

                return;
            }
            const step = event.key === 'ArrowDown' ? 1 : -1;
            setActive((current) => (visible.length === 0 ? 0 : (current + step + visible.length) % visible.length));
        } else if (event.key === 'Enter') {
            if (open && visible[active]) {
                event.preventDefault();
                toggle(visible[active].value);
            }
        } else if (event.key === 'Escape') {
            if (open) {
                event.preventDefault();
                event.stopPropagation();
                close();
            }
        } else if (event.key === 'Backspace' && query === '' && values.length > 0) {
            onChange(values.slice(0, -1));
        } else if (event.key === 'Tab') {
            close();
        }
    };

    const activeId = open && visible[active] ? `${listId}-${active}` : undefined;

    return (
        <div ref={rootRef} className="mt-1">
            <div className="relative">
                <input
                    ref={inputRef}
                    id={id}
                    type="text"
                    role="combobox"
                    autoComplete="off"
                    aria-expanded={open}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-activedescendant={activeId}
                    aria-describedby={describedBy}
                    title={searchLabel}
                    placeholder={placeholder}
                    value={query}
                    onChange={(event) => { setQuery(event.target.value); setOpen(true); }}
                    onFocus={() => setOpen(true)}
                    onClick={() => setOpen(true)}
                    onKeyDown={onKeyDown}
                    className="min-h-11 w-full rounded-xl border border-slate-200 bg-white py-2 pl-3 pr-10 text-base text-slate-900 shadow-sm placeholder:text-slate-500 focus:border-slate-400 focus:outline-none"
                    data-testid={testId ? `${testId}-input` : undefined}
                />
                <svg className={`pointer-events-none absolute right-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-500 transition-transform ${open ? 'rotate-180' : ''}`} viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clipRule="evenodd" />
                </svg>
            </div>

            {open && (
                <ul id={listId} role="listbox" aria-multiselectable="true" aria-label={searchLabel} className="mt-1 max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-sm" data-testid={testId ? `${testId}-list` : undefined}>
                    {visible.length === 0 ? (
                        <li className="px-3 py-2 text-base text-slate-600">{noResultsLabel}</li>
                    ) : visible.map((option, index) => {
                        const selected = values.includes(option.value);

                        return (
                            <li
                                key={option.value}
                                id={`${listId}-${index}`}
                                role="option"
                                aria-selected={selected}
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => { toggle(option.value); inputRef.current?.focus(); }}
                                onMouseEnter={() => setActive(index)}
                                className={`flex min-h-11 cursor-pointer items-center gap-3 rounded-lg px-3 py-2 ${index === active ? 'bg-slate-100' : ''}`}
                                data-testid={testId ? `${testId}-option-${option.value}` : undefined}
                            >
                                <span className={`flex h-5 w-5 shrink-0 items-center justify-center rounded border ${selected ? 'border-violet-600 bg-violet-600 text-white' : 'border-slate-300 bg-white'}`} aria-hidden="true">
                                    {selected && <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fillRule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.5 7.57a1 1 0 0 1-1.42 0l-3.5-3.53a1 1 0 1 1 1.42-1.408l2.79 2.814 6.79-6.854a1 1 0 0 1 1.414-.006Z" clipRule="evenodd" /></svg>}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block break-words text-base text-slate-900">{option.label}</span>
                                    {option.description && <span className="block break-words text-base text-slate-600">{option.description}</span>}
                                </span>
                                {selected && <span className="sr-only">{selectedLabel}</span>}
                            </li>
                        );
                    })}
                </ul>
            )}

            {chosen.length > 0 && (
                <ul className="mt-2 flex flex-wrap gap-2" aria-label={selectedLabel} data-testid={testId ? `${testId}-chosen` : undefined}>
                    {chosen.map((option) => (
                        <li key={option.value} className="min-w-0 max-w-full">
                            <button
                                type="button"
                                onClick={() => toggle(option.value)}
                                aria-label={(removeLabel ?? ':name').replace(':name', option.label)}
                                className="inline-flex min-h-9 max-w-full items-center gap-1.5 rounded-full border border-violet-200 bg-violet-50 py-1 pl-3 pr-2 text-base text-violet-900 hover:border-violet-300 hover:bg-violet-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-300"
                                data-testid={testId ? `${testId}-chip-${option.value}` : undefined}
                            >
                                <span className="min-w-0 truncate">{option.label}</span>
                                <svg className="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" /></svg>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
