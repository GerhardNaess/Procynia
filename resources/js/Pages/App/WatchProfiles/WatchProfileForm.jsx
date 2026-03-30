import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function cpvFieldError(errors, index, field) {
    return errors[`cpv_codes.${index}.${field}`];
}

function selectedCpvCodesExcludingIndex(rows, excludedIndex) {
    return new Set(
        rows
            .filter((_, index) => index !== excludedIndex)
            .map((row) => String(row?.cpv_code ?? '').trim())
            .filter((code) => code !== ''),
    );
}

export default function WatchProfileForm({
    title,
    subtitle,
    form,
    ownerOptions,
    departmentOptions,
    cpvSuggestionsUrl,
    backHref,
    submitLabel,
    onSubmit,
    submitMethod,
    deleteUrl = null,
}) {
    const [activeCpvIndex, setActiveCpvIndex] = useState(null);
    const [cpvSuggestions, setCpvSuggestions] = useState([]);
    const [cpvLookupLoading, setCpvLookupLoading] = useState(false);
    const requestSequenceRef = useRef(0);
    const canChooseDepartmentOwner = ownerOptions.some((option) => option.value === 'department');
    const selectedOwnerOption = ownerOptions.find((option) => option.value === form.data.owner_scope);
    const activeCpvCode = activeCpvIndex === null
        ? ''
        : String(form.data.cpv_codes[activeCpvIndex]?.cpv_code ?? '');
    const selectedCpvCodesSignature = form.data.cpv_codes
        .map((row, index) => (index === activeCpvIndex ? '' : String(row?.cpv_code ?? '').trim()))
        .join('|');
    const shouldShowSuggestions = activeCpvIndex !== null && activeCpvCode.trim() !== '';

    useEffect(() => {
        if (!cpvSuggestionsUrl || activeCpvIndex === null) {
            setCpvSuggestions([]);
            setCpvLookupLoading(false);

            return;
        }

        const activeRow = form.data.cpv_codes[activeCpvIndex];

        if (!activeRow) {
            setActiveCpvIndex(null);
            setCpvSuggestions([]);
            setCpvLookupLoading(false);

            return;
        }

        const query = String(activeRow.cpv_code ?? '').trim();

        if (query === '') {
            setCpvSuggestions([]);
            setCpvLookupLoading(false);

            return;
        }

        const requestId = requestSequenceRef.current + 1;
        const abortController = new AbortController();
        const url = new URL(cpvSuggestionsUrl, window.location.origin);
        const selectedCodes = selectedCpvCodesExcludingIndex(form.data.cpv_codes, activeCpvIndex);

        requestSequenceRef.current = requestId;
        url.searchParams.set('query', query);
        url.searchParams.set('limit', '10');
        setCpvLookupLoading(true);

        fetch(url.toString(), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: abortController.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('CPV lookup request failed.');
                }

                return response.json();
            })
            .then((payload) => {
                if (requestSequenceRef.current !== requestId) {
                    return;
                }

                const nextSuggestions = Array.isArray(payload.data)
                    ? payload.data.filter((item) => !selectedCodes.has(item.code))
                    : [];

                setCpvSuggestions(nextSuggestions);
            })
            .catch((error) => {
                if (error.name === 'AbortError') {
                    return;
                }

                if (requestSequenceRef.current !== requestId) {
                    return;
                }

                setCpvSuggestions([]);
            })
            .finally(() => {
                if (requestSequenceRef.current === requestId) {
                    setCpvLookupLoading(false);
                }
            });

        return () => {
            abortController.abort();
        };
    }, [activeCpvCode, activeCpvIndex, cpvSuggestionsUrl, form.data.cpv_codes, selectedCpvCodesSignature]);

    const closeCpvSuggestions = () => {
        setActiveCpvIndex(null);
        setCpvSuggestions([]);
        setCpvLookupLoading(false);
    };

    const addCpvRule = () => {
        closeCpvSuggestions();

        form.setData('cpv_codes', [
            ...form.data.cpv_codes,
            { cpv_code: '', description: '', weight: 1 },
        ]);
    };

    const updateCpvRule = (index, field, value) => {
        const nextRows = form.data.cpv_codes.map((row, rowIndex) => {
            if (rowIndex !== index) {
                return row;
            }

            if (field === 'cpv_code') {
                return {
                    ...row,
                    cpv_code: value,
                    description: '',
                };
            }

            return {
                ...row,
                [field]: value,
            };
        });

        form.setData('cpv_codes', nextRows);

        if (field === 'cpv_code') {
            setActiveCpvIndex(index);
        }
    };

    const selectCpvSuggestion = (index, suggestion) => {
        form.setData(
            'cpv_codes',
            form.data.cpv_codes.map((row, rowIndex) => (
                rowIndex === index
                    ? {
                        ...row,
                        cpv_code: suggestion.code,
                        description: suggestion.description,
                    }
                    : row
            )),
        );

        closeCpvSuggestions();
    };

    const removeCpvRule = (index) => {
        closeCpvSuggestions();

        form.setData(
            'cpv_codes',
            form.data.cpv_codes.filter((_, rowIndex) => rowIndex !== index),
        );
    };

    const deleteWatchProfile = () => {
        if (!deleteUrl || form.processing) {
            return;
        }

        if (!window.confirm('Er du sikker på at du vil slette denne Watch Profile-en?')) {
            return;
        }

        router.delete(deleteUrl);
    };

    return (
        <div className="mx-auto max-w-4xl">
            <form
                onSubmit={onSubmit}
                className="space-y-6 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:p-8"
            >
                <div className="space-y-1.5">
                    <h1 className="text-3xl font-semibold tracking-tight text-slate-950">{title}</h1>
                    <p className="text-sm leading-6 text-slate-500">{subtitle}</p>
                </div>

                <div className="grid gap-5 md:grid-cols-2">
                    <label className="space-y-2 md:col-span-2">
                        <span className="text-sm font-medium text-slate-700">Navn</span>
                        <input
                            type="text"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                        />
                        {form.errors.name ? <p className="text-sm text-rose-600">{form.errors.name}</p> : null}
                    </label>

                    <label className="space-y-2">
                        <span className="text-sm font-medium text-slate-700">Eierskap</span>
                        {ownerOptions.length > 1 ? (
                            <select
                                value={form.data.owner_scope}
                                onChange={(event) => {
                                    const ownerScope = event.target.value;

                                    form.setData({
                                        ...form.data,
                                        owner_scope: ownerScope,
                                        department_id: ownerScope === 'department'
                                            ? (form.data.department_id ?? departmentOptions[0]?.value ?? null)
                                            : null,
                                    });
                                }}
                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            >
                                {ownerOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                                {selectedOwnerOption?.label}
                            </div>
                        )}
                        {selectedOwnerOption?.description ? (
                            <p className="text-xs text-slate-400">{selectedOwnerOption.description}</p>
                        ) : null}
                        {form.errors.owner_scope ? <p className="text-sm text-rose-600">{form.errors.owner_scope}</p> : null}
                    </label>

                    <label className="space-y-2">
                        <span className="text-sm font-medium text-slate-700">Avdeling</span>
                        {form.data.owner_scope === 'department' ? (
                            <>
                                <select
                                    value={form.data.department_id ?? ''}
                                    onChange={(event) => form.setData('department_id', event.target.value === '' ? null : Number(event.target.value))}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                >
                                    <option value="">Velg avdeling</option>
                                    {departmentOptions.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                {departmentOptions.length === 0 && canChooseDepartmentOwner ? (
                                    <p className="text-xs text-slate-400">Ingen avdeling er tilgjengelig for denne brukeren.</p>
                                ) : null}
                            </>
                        ) : (
                            <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">
                                Personlige watch profiles knyttes til deg og ikke til en avdeling.
                            </div>
                        )}
                        {form.errors.department_id ? <p className="text-sm text-rose-600">{form.errors.department_id}</p> : null}
                    </label>

                    <label className="space-y-2">
                        <span className="text-sm font-medium text-slate-700">Status</span>
                        <label className="flex min-h-11 items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                checked={form.data.is_active}
                                onChange={(event) => form.setData('is_active', event.target.checked)}
                                className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                            />
                            <span>{form.data.is_active ? 'Aktiv' : 'Inaktiv'}</span>
                        </label>
                        {form.errors.is_active ? <p className="text-sm text-rose-600">{form.errors.is_active}</p> : null}
                    </label>

                    <label className="space-y-2 md:col-span-2">
                        <span className="text-sm font-medium text-slate-700">Beskrivelse</span>
                        <textarea
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                            rows={4}
                            className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                        />
                        {form.errors.description ? <p className="text-sm text-rose-600">{form.errors.description}</p> : null}
                    </label>

                    <label className="space-y-2 md:col-span-2">
                        <span className="text-sm font-medium text-slate-700">Nøkkelord</span>
                        <textarea
                            value={form.data.keywords}
                            onChange={(event) => form.setData('keywords', event.target.value)}
                            rows={6}
                            className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                        />
                        <p className="text-xs text-slate-400">Ett nøkkelord per linje. Lagres i samme format som eksisterende Watch Profile-modell forventer.</p>
                        {form.errors.keywords ? <p className="text-sm text-rose-600">{form.errors.keywords}</p> : null}
                    </label>
                </div>

                <section className="space-y-4 rounded-[20px] border border-slate-200 bg-slate-50/70 p-5">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-950">CPV-regler</h2>
                            <p className="text-sm text-slate-500">Søk opp CPV-koder fra katalogen og sett én vekt per rad.</p>
                        </div>
                        <button
                            type="button"
                            onClick={addCpvRule}
                            className="inline-flex min-h-10 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                        >
                            Legg til CPV-rad
                        </button>
                    </div>

                    {form.errors.cpv_codes ? <p className="text-sm text-rose-600">{form.errors.cpv_codes}</p> : null}

                    {form.data.cpv_codes.length === 0 ? (
                        <div className="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-6 text-sm text-slate-500">
                            Ingen CPV-regler ennå.
                        </div>
                    ) : (
                        <div className="max-h-[540px] space-y-3 overflow-y-auto pr-2">
                            {form.data.cpv_codes.map((row, index) => {
                                const isActiveRow = activeCpvIndex === index;
                                const showSuggestions = isActiveRow && shouldShowSuggestions;
                                const showEmptyState = showSuggestions && !cpvLookupLoading && cpvSuggestions.length === 0;

                                return (
                                    <div key={`cpv-row-${index}`} className="rounded-xl border border-slate-200 bg-white p-4">
                                        <div className="space-y-3">
                                            <div className="flex items-start gap-3">
                                                <div
                                                    className="relative min-w-0 flex-1 space-y-2"
                                                    onBlur={(event) => {
                                                        if (!event.currentTarget.contains(event.relatedTarget)) {
                                                            closeCpvSuggestions();
                                                        }
                                                    }}
                                                >
                                                    <span className="text-sm font-medium text-slate-700">CPV-kode</span>
                                                    <input
                                                        type="text"
                                                        value={row.cpv_code}
                                                        onFocus={() => setActiveCpvIndex(index)}
                                                        onChange={(event) => updateCpvRule(index, 'cpv_code', event.target.value)}
                                                        placeholder="Søk etter kode eller beskrivelse"
                                                        autoComplete="off"
                                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                    />

                                                    {showSuggestions ? (
                                                        <div className="absolute z-20 mt-2 w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-[0_16px_40px_rgba(15,23,42,0.12)]">
                                                            {cpvLookupLoading ? (
                                                                <div className="px-4 py-3 text-sm text-slate-500">Laster CPV-treff...</div>
                                                            ) : null}

                                                            {!cpvLookupLoading && cpvSuggestions.map((item) => (
                                                                <button
                                                                    key={`${index}-${item.code}`}
                                                                    type="button"
                                                                    onMouseDown={(event) => event.preventDefault()}
                                                                    onClick={() => selectCpvSuggestion(index, item)}
                                                                    className="grid w-full grid-cols-[104px_minmax(0,1fr)] gap-3 px-4 py-3 text-left transition hover:bg-slate-50"
                                                                >
                                                                    <span className="text-sm font-semibold text-slate-900">{item.code}</span>
                                                                    <span className="text-sm text-slate-600">{item.description || 'No description available.'}</span>
                                                                </button>
                                                            ))}

                                                            {showEmptyState ? (
                                                                <div className="px-4 py-3 text-sm text-slate-500">Ingen treff</div>
                                                            ) : null}
                                                        </div>
                                                    ) : null}

                                                    {cpvFieldError(form.errors, index, 'cpv_code') ? (
                                                        <p className="text-sm text-rose-600">{cpvFieldError(form.errors, index, 'cpv_code')}</p>
                                                    ) : null}
                                                </div>

                                                <label className="w-24 shrink-0 space-y-2">
                                                    <span className="text-sm font-medium text-slate-700">Vekt</span>
                                                    <input
                                                        type="number"
                                                        min="1"
                                                        value={row.weight}
                                                        onChange={(event) => updateCpvRule(index, 'weight', event.target.value)}
                                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                    />
                                                    {cpvFieldError(form.errors, index, 'weight') ? (
                                                        <p className="text-sm text-rose-600">{cpvFieldError(form.errors, index, 'weight')}</p>
                                                    ) : null}
                                                </label>

                                                <div className="flex shrink-0 items-end pt-7">
                                                    <button
                                                        type="button"
                                                        onClick={() => removeCpvRule(index)}
                                                        className="inline-flex min-h-11 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100"
                                                    >
                                                        Fjern
                                                    </button>
                                                </div>
                                            </div>

                                            <div className="space-y-2">
                                                <span className="text-sm font-medium text-slate-700">Forklaring</span>
                                                <div
                                                    className={classNames(
                                                        'min-h-11 w-full break-words rounded-xl border px-4 py-3 text-sm leading-6',
                                                        row.description
                                                            ? 'border-slate-200 bg-slate-50 text-slate-700'
                                                            : 'border-slate-200 bg-white text-slate-400',
                                                    )}
                                                >
                                                    {row.description || 'Velg en CPV-kode for å se beskrivelsen.'}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </section>

                <div className="flex flex-col gap-3 sm:flex-row sm:justify-between">
                    <div>
                        {deleteUrl ? (
                            <button
                                type="button"
                                onClick={deleteWatchProfile}
                                disabled={form.processing}
                                className="inline-flex min-h-11 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                Slett Watch Profile
                            </button>
                        ) : null}
                    </div>

                    <div className="flex flex-col gap-3 sm:flex-row">
                        <Link
                            href={backHref}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                        >
                            Tilbake
                        </Link>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {form.processing
                                ? submitMethod === 'create'
                                    ? 'Lagrer...'
                                    : 'Oppdaterer...'
                                : submitLabel}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    );
}
