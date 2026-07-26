import { useCallback, useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

/**
 * Compact multiselect filter control: a button summarizing the active selection that
 * opens a checkbox-list popover on click. Built for filter groups with many options
 * (source documents, document owners, …) where an always-open checkbox list would make
 * the surrounding panel unreasonably tall.
 *
 * The popover is rendered via a portal into document.body and positioned with
 * `position: fixed` from the trigger's own bounding rect — this is the only reliable way
 * to guarantee it is never clipped by a scrollable ancestor (the left filter sidebar
 * itself scrolls), and it lets the same clamping logic used elsewhere in this app
 * (see InfoHint) keep the panel inside the viewport on any screen size.
 *
 * Selection state is fully controlled by the caller (selectedIds/onChange as a Set) —
 * this component owns only its own open/closed and search-text state, so it composes
 * with existing filter logic unchanged. Open/closed state itself is controlled too
 * (isOpen/onOpenChange), so a parent can coordinate "only one dropdown open at a time"
 * across multiple instances.
 *
 * @param {string} label - Accessible field label, e.g. "Kildedokumenter".
 * @param {string} allLabel - Label for the "no restriction" option, e.g. "Alle dokumenter".
 * @param {list<{id: number, label: string, count?: number}>} options
 * @param {Set<number>} selectedIds
 * @param {(next: Set<number>) => void} onChange
 * @param {boolean} isOpen
 * @param {(open: boolean) => void} onOpenChange
 * @param {string} [searchPlaceholder]
 * @param {string} [noResultsLabel]
 * @param {string} [resetLabel]
 * @param {string} [doneLabel]
 * @param {string} [selectedCountTemplate] - e.g. ":count dokumenter valgt" — used when 2+ are selected.
 * @param {number} [searchThreshold=8] - Show the internal search box only above this many options.
 */
export default function MultiSelectFilterDropdown({
    label,
    allLabel,
    options,
    selectedIds,
    onChange,
    isOpen,
    onOpenChange,
    searchPlaceholder,
    noResultsLabel,
    resetLabel,
    doneLabel,
    selectedCountTemplate,
    searchThreshold = 8,
}) {
    const [searchQuery, setSearchQuery] = useState('');
    const [panelStyle, setPanelStyle] = useState(null);
    const buttonRef = useRef(null);
    const panelRef = useRef(null);
    const searchInputRef = useRef(null);
    const rawId = useId();
    const panelId = `msfd-${rawId.replace(/:/g, '')}`;

    const allSelected = selectedIds.size === 0;
    const showSearch = options.length > searchThreshold;

    const filteredOptions = useMemo(() => {
        const trimmed = searchQuery.trim().toLowerCase();
        if (trimmed === '') {
            return options;
        }
        return options.filter((option) => String(option.label ?? '').toLowerCase().includes(trimmed));
    }, [options, searchQuery]);

    const summaryText = useMemo(() => {
        if (allSelected) {
            return allLabel;
        }
        const selectedOptions = options.filter((option) => selectedIds.has(option.id));
        if (selectedOptions.length === 1) {
            return selectedOptions[0].label;
        }
        return (selectedCountTemplate ?? ':count valgt').replace(':count', String(selectedOptions.length));
    }, [allSelected, allLabel, options, selectedIds, selectedCountTemplate]);

    const toggleOption = useCallback((id) => {
        const next = new Set(selectedIds);
        if (next.has(id)) {
            next.delete(id);
        } else {
            next.add(id);
        }
        onChange(next);
    }, [selectedIds, onChange]);

    const close = useCallback(() => {
        onOpenChange(false);
        setSearchQuery('');
        buttonRef.current?.focus();
    }, [onOpenChange]);

    const recalcPosition = useCallback(() => {
        const button = buttonRef.current;
        if (!button) return;

        const rect = button.getBoundingClientRect();
        const margin = 8;
        const width = Math.max(rect.width, 240);

        let left = rect.left;
        if (left + width > window.innerWidth - margin) {
            left = Math.max(margin, window.innerWidth - margin - width);
        }
        left = Math.max(margin, left);

        const spaceBelow = window.innerHeight - rect.bottom - margin;
        const maxHeight = Math.max(160, Math.min(320, spaceBelow));

        setPanelStyle({
            position: 'fixed',
            top: rect.bottom + 4,
            left,
            width,
            maxHeight,
        });
    }, []);

    useLayoutEffect(() => {
        if (!isOpen) return undefined;

        recalcPosition();
        window.addEventListener('resize', recalcPosition);
        window.addEventListener('scroll', recalcPosition, true);

        return () => {
            window.removeEventListener('resize', recalcPosition);
            window.removeEventListener('scroll', recalcPosition, true);
        };
    }, [isOpen, recalcPosition]);

    useEffect(() => {
        if (!isOpen) return undefined;

        function onDocumentMouseDown(event) {
            if (buttonRef.current?.contains(event.target)) return;
            if (panelRef.current?.contains(event.target)) return;
            close();
        }

        document.addEventListener('mousedown', onDocumentMouseDown);
        return () => document.removeEventListener('mousedown', onDocumentMouseDown);
    }, [isOpen, close]);

    useEffect(() => {
        if (!isOpen) return undefined;

        function onKeyDown(event) {
            if (event.key === 'Escape') {
                event.stopPropagation();
                close();
            }
        }

        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [isOpen, close]);

    useEffect(() => {
        if (isOpen && showSearch) {
            searchInputRef.current?.focus();
        }
    }, [isOpen, showSearch]);

    const labelId = `${panelId}-label`;
    const summaryId = `${panelId}-summary`;

    return (
        <div className="mb-4">
            <span id={labelId} className="mb-2 block text-[11px] font-semibold uppercase tracking-widest text-slate-400">
                {label}
            </span>
            <button
                ref={buttonRef}
                type="button"
                aria-haspopup="true"
                aria-expanded={isOpen}
                aria-controls={panelId}
                aria-labelledby={`${labelId} ${summaryId}`}
                onClick={() => onOpenChange(!isOpen)}
                className={classNames(
                    'flex w-full items-center justify-between gap-2 rounded-lg border px-2.5 py-1.5 text-xs font-medium shadow-sm transition',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-300',
                    allSelected
                        ? 'border-slate-200 bg-white text-slate-700 hover:border-slate-300'
                        : 'border-violet-300 bg-violet-50 text-violet-700 hover:border-violet-400',
                )}
            >
                <span id={summaryId} className="min-w-0 truncate" title={summaryText}>{summaryText}</span>
                <svg
                    className={classNames('h-3.5 w-3.5 shrink-0 transition-transform', isOpen ? 'rotate-180' : '')}
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clipRule="evenodd" />
                </svg>
            </button>

            {isOpen && panelStyle && createPortal(
                <div
                    ref={panelRef}
                    id={panelId}
                    role="group"
                    aria-label={label}
                    style={panelStyle}
                    className="z-50 flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_20px_40px_rgba(15,23,42,0.16)]"
                >
                    {showSearch && (
                        <div className="shrink-0 border-b border-slate-100 p-2">
                            <input
                                ref={searchInputRef}
                                type="search"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                placeholder={searchPlaceholder}
                                aria-label={searchPlaceholder}
                                className="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-300"
                            />
                        </div>
                    )}

                    <div className="min-h-0 flex-1 space-y-1 overflow-y-auto p-2">
                        <label className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-50">
                            <input
                                type="checkbox"
                                checked={allSelected}
                                onChange={() => onChange(new Set())}
                                className="h-3.5 w-3.5 shrink-0 rounded border-slate-300 accent-violet-600"
                            />
                            <span className="text-xs font-semibold text-slate-700">{allLabel}</span>
                        </label>

                        {filteredOptions.length === 0 ? (
                            <p className="px-2 py-3 text-xs text-slate-400">{noResultsLabel}</p>
                        ) : (
                            filteredOptions.map((option) => (
                                <label key={option.id} className="flex cursor-pointer items-start gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-50">
                                    <input
                                        type="checkbox"
                                        checked={selectedIds.has(option.id)}
                                        onChange={() => toggleOption(option.id)}
                                        className="mt-0.5 h-3.5 w-3.5 shrink-0 rounded border-slate-300 accent-violet-600"
                                    />
                                    <span className="min-w-0 flex-1 break-words text-xs text-slate-700" title={option.label}>
                                        {option.label}
                                    </span>
                                    {typeof option.count === 'number' && (
                                        <span className="shrink-0 text-[11px] tabular-nums text-slate-400">
                                            {option.count}
                                        </span>
                                    )}
                                </label>
                            ))
                        )}
                    </div>

                    <div className="flex shrink-0 items-center justify-between gap-2 border-t border-slate-100 p-2">
                        <button
                            type="button"
                            onClick={() => onChange(new Set())}
                            disabled={allSelected}
                            className="rounded-lg px-2 py-1 text-xs font-medium text-slate-500 transition hover:text-slate-800 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            {resetLabel}
                        </button>
                        <button
                            type="button"
                            onClick={close}
                            className="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-300"
                        >
                            {doneLabel}
                        </button>
                    </div>
                </div>,
                document.body,
            )}
        </div>
    );
}
