import { useEffect, useId, useRef, useState } from 'react';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function filterSelected(options, selectedItems) {
    const selectedCodes = new Set(selectedItems.map((item) => item.code));

    return options.filter((item) => !selectedCodes.has(item.code));
}

export default function CpvSelector({
    endpoint,
    selectedItems,
    onSelectedItemsChange,
    popularItems = [],
}) {
    const inputId = useId();
    const listboxId = useId();
    const inputRef = useRef(null);
    const [inputValue, setInputValue] = useState('');
    const [isOpen, setIsOpen] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const [suggestions, setSuggestions] = useState(() => filterSelected(popularItems, selectedItems));
    const [activeIndex, setActiveIndex] = useState(-1);

    useEffect(() => {
        if (selectedItems.length === 0) {
            setInputValue('');
        }

        setSuggestions(filterSelected(popularItems, selectedItems));
    }, [popularItems, selectedItems]);

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        if (inputValue.trim() === '') {
            const nextSuggestions = filterSelected(popularItems, selectedItems);
            setSuggestions(nextSuggestions);
            setActiveIndex(nextSuggestions.length > 0 ? 0 : -1);

            return;
        }

        const abortController = new AbortController();
        const url = new URL(endpoint, window.location.origin);
        url.searchParams.set('query', inputValue);
        url.searchParams.set('limit', '8');
        setIsLoading(true);

        fetch(url.toString(), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: abortController.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('CPV suggestion request failed.');
                }

                return response.json();
            })
            .then((payload) => {
                const nextSuggestions = filterSelected(payload.data ?? [], selectedItems);
                setSuggestions(nextSuggestions);
                setActiveIndex(nextSuggestions.length > 0 ? 0 : -1);
            })
            .catch((error) => {
                if (error.name === 'AbortError') {
                    return;
                }

                setSuggestions([]);
                setActiveIndex(-1);
            })
            .finally(() => {
                setIsLoading(false);
            });

        return () => {
            abortController.abort();
        };
    }, [endpoint, inputValue, isOpen, popularItems, selectedItems]);

    const selectItem = (item) => {
        onSelectedItemsChange([...selectedItems, item]);
        setInputValue('');
        setIsOpen(true);
        inputRef.current?.focus();
    };

    const removeItem = (code) => {
        onSelectedItemsChange(selectedItems.filter((item) => item.code !== code));
        inputRef.current?.focus();
    };

    const handleKeyDown = (event) => {
        if (event.key === 'Backspace' && inputValue === '' && selectedItems.length > 0) {
            removeItem(selectedItems[selectedItems.length - 1].code);
            return;
        }

        if (event.key === 'Escape') {
            setIsOpen(false);
            setActiveIndex(-1);
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setIsOpen(true);
            setActiveIndex((current) => {
                if (suggestions.length === 0) {
                    return -1;
                }

                return current < suggestions.length - 1 ? current + 1 : 0;
            });
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveIndex((current) => {
                if (suggestions.length === 0) {
                    return -1;
                }

                return current > 0 ? current - 1 : suggestions.length - 1;
            });
            return;
        }

        if (event.key === 'Enter' && isOpen && activeIndex >= 0 && suggestions[activeIndex]) {
            event.preventDefault();
            selectItem(suggestions[activeIndex]);
        }
    };

    const showEmptyState = isOpen && !isLoading && suggestions.length === 0;

    return (
        <label className="space-y-2">
            <span className="text-sm font-medium text-slate-700">CPV</span>
            <div className="relative">
                <div className="rounded-xl border border-slate-200 bg-white px-3 py-3 transition focus-within:border-violet-300 focus-within:ring-4 focus-within:ring-violet-100">
                    <div className="flex flex-wrap items-center gap-1.5">
                        {selectedItems.map((item) => (
                            <span
                                key={item.code}
                                className="inline-flex max-w-full items-center gap-2 rounded-full bg-violet-100 px-3 py-1.5 text-xs font-medium text-violet-800 ring-1 ring-inset ring-violet-200"
                            >
                                <span className="min-w-0 max-w-full overflow-hidden text-ellipsis whitespace-nowrap">
                                    {item.label}
                                </span>
                                <span className="shrink-0 text-violet-500">{item.code}</span>
                                <button
                                    type="button"
                                    onClick={() => removeItem(item.code)}
                                    className="shrink-0 rounded-full text-violet-700 transition hover:text-violet-900"
                                    aria-label={`Fjern ${item.label}`}
                                >
                                    x
                                </button>
                            </span>
                        ))}

                        <div className="flex min-w-[120px] flex-1 items-center py-1">
                            <input
                                id={inputId}
                                ref={inputRef}
                                type="text"
                                value={inputValue}
                                onChange={(event) => {
                                    setInputValue(event.target.value);
                                    setIsOpen(true);
                                }}
                                onFocus={() => setIsOpen(true)}
                                onBlur={() => {
                                    window.setTimeout(() => {
                                        setIsOpen(false);
                                        setActiveIndex(-1);
                                    }, 120);
                                }}
                                onKeyDown={handleKeyDown}
                                placeholder="Søk etter CPV med vanlig språk"
                                className="min-w-[120px] flex-1 border-none bg-transparent p-0 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:ring-0"
                                role="combobox"
                                aria-expanded={isOpen}
                                aria-controls={listboxId}
                                aria-autocomplete="list"
                                aria-activedescendant={activeIndex >= 0 && suggestions[activeIndex] ? `${listboxId}-${suggestions[activeIndex].code}` : undefined}
                            />
                        </div>
                    </div>
                </div>

                {isOpen ? (
                    <div
                        id={listboxId}
                        role="listbox"
                        className="absolute z-20 mt-2 max-h-72 w-full overflow-auto rounded-xl border border-slate-200 bg-white p-2 shadow-[0_16px_40px_rgba(15,23,42,0.12)]"
                    >
                        {inputValue.trim() === '' ? (
                            <div className="px-2 pb-2 text-xs font-medium uppercase tracking-[0.14em] text-slate-400">
                                Populære valg
                            </div>
                        ) : null}

                        {isLoading ? (
                            <div className="px-3 py-3 text-sm text-slate-500">Laster CPV-treff...</div>
                        ) : null}

                        {!isLoading && suggestions.map((item, index) => (
                            <button
                                key={item.code}
                                id={`${listboxId}-${item.code}`}
                                type="button"
                                role="option"
                                aria-selected={index === activeIndex}
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => selectItem(item)}
                                className={classNames(
                                    'flex w-full items-center justify-between rounded-lg px-3 py-2 text-left transition',
                                    index === activeIndex ? 'bg-violet-50 text-violet-900' : 'hover:bg-slate-50',
                                )}
                            >
                                <span className="min-w-0 pr-3 text-sm text-slate-800">{item.label}</span>
                                <span className="shrink-0 text-xs font-medium text-slate-500">{item.code}</span>
                            </button>
                        ))}

                        {showEmptyState ? (
                            <div className="px-3 py-3 text-sm text-slate-500">Ingen CPV-treff</div>
                        ) : null}
                    </div>
                ) : null}
            </div>
            <p className="text-xs text-slate-400">Velg ett eller flere CPV-områder med vanlig språk eller kode.</p>
        </label>
    );
}
