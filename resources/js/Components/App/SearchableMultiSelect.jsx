import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { filterPickerOptions } from './pickerFilter';
import { chipFocusAfterRemoval, pickerResultAnnouncement } from './searchableMultiSelectA11y';
import { announce } from '../../Support/announcer';

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
 * Accessibility (Fase 6, A11Y-111): the number of matches is spoken once the user stops typing — never
 * when the list just opens. The option under the arrow keys has a violet outline, not only a grey
 * fill. Removing a chosen item moves focus to the chip in its place, the one before, or the search
 * field, never to <body>. `invalid` / `required` give the search field aria-invalid / aria-required,
 * so a caller using FormField can pass its error state through.
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
    invalid = false,
    required = false,
    testId,
}) {
    const a11y = usePage().props?.translations?.a11y ?? {};
    const a11yRef = useRef(a11y);
    a11yRef.current = a11y;
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const rootRef = useRef(null);
    const inputRef = useRef(null);
    const chipRefs = useRef(new Map());
    const [chipToFocus, setChipToFocus] = useState(null);
    const focusWithoutOpeningRef = useRef(false);
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

    // The hit count, said once the typing settles (A11Y-111). Keyed on the query only: opening the list,
    // choosing an option or a parent re-render says nothing.
    const visibleCountRef = useRef(visible.length);
    visibleCountRef.current = visible.length;
    const queryRef = useRef(query);
    useEffect(() => {
        if (queryRef.current === query) {
            return undefined;
        }

        queryRef.current = query;
        const timer = window.setTimeout(() => {
            const message = pickerResultAnnouncement(query, visibleCountRef.current, a11yRef.current);
            if (message) announce(message);
        }, 500);

        return () => window.clearTimeout(timer);
    }, [query]);

    // After a chip removed itself, focus the chip that took its place — once it has rendered.
    useEffect(() => {
        if (chipToFocus === null) return;
        const chip = chipToFocus === false ? null : chipRefs.current.get(chipToFocus);
        if (chip) {
            chip.focus();
        } else {
            // Focus lands on the field without opening the list: the user removed something, they
            // did not ask to choose.
            if (inputRef.current && document.activeElement !== inputRef.current) {
                focusWithoutOpeningRef.current = true;
                inputRef.current.focus();
            }
        }
        setChipToFocus(null);
    }, [chipToFocus]);

    const removeChip = (value) => {
        const next = chipFocusAfterRemoval(chosen.map((option) => option.value), value);
        onChange(values.filter((item) => item !== value));
        setChipToFocus(next === null ? false : next);
    };

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
                    aria-invalid={invalid || undefined}
                    aria-required={required || undefined}
                    title={searchLabel}
                    placeholder={placeholder}
                    value={query}
                    onChange={(event) => { setQuery(event.target.value); setOpen(true); }}
                    onFocus={() => {
                        if (focusWithoutOpeningRef.current) {
                            focusWithoutOpeningRef.current = false;

                            return;
                        }
                        setOpen(true);
                    }}
                    onClick={() => setOpen(true)}
                    onKeyDown={onKeyDown}
                    className="min-h-11 w-full rounded-xl border border-field-border bg-white py-2 pl-3 pr-10 text-base text-slate-900 shadow-sm placeholder:text-slate-500 focus:border-slate-400 focus:outline-none"
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
                                // Keyboard use goes through the search field (aria-activedescendant); this
                                // only answers an assistive technology that sends keys to the option itself.
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter' || event.key === ' ') {
                                        event.preventDefault();
                                        toggle(option.value);
                                    }
                                }}
                                onMouseEnter={() => setActive(index)}
                                className={`flex min-h-11 cursor-pointer items-center gap-3 rounded-lg px-3 py-2 ${index === active ? 'bg-slate-100 outline outline-2 -outline-offset-2 outline-violet-600' : ''}`}
                                data-testid={testId ? `${testId}-option-${option.value}` : undefined}
                            >
                                <span className={`flex h-5 w-5 shrink-0 items-center justify-center rounded border ${selected ? 'border-violet-600 bg-violet-600 text-white' : 'border-slate-500 bg-white'}`} aria-hidden="true">
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
                                ref={(element) => {
                                    if (element) chipRefs.current.set(option.value, element);
                                    else chipRefs.current.delete(option.value);
                                }}
                                type="button"
                                onClick={() => removeChip(option.value)}
                                aria-label={(removeLabel ?? ':name').replace(':name', option.label)}
                                className="inline-flex min-h-9 max-w-full items-center gap-1.5 rounded-full border border-violet-200 bg-violet-50 py-1 pl-3 pr-2 text-base text-violet-900 hover:border-violet-300 hover:bg-violet-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600"
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
