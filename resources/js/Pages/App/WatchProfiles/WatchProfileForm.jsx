import { Link, router, usePage } from '@inertiajs/react';
import { PRIMARY_COLOURS } from '../../../Support/actionStyles';
import { useEffect, useId, useRef, useState } from 'react';
import InfoHint from '../../../Components/App/InfoHint';

function cpvFieldError(errors, index, field) {
    return errors[`cpv_codes.${index}.${field}`];
}

export default function WatchProfileForm({
    title,
    subtitle,
    showHeader = true,
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
    const { translations = {} } = usePage().props;
    const wp = translations?.watch_profile_page ?? {};
    const cpvSearchId = useId();
    const cpvResultsId = useId();
    const [cpvQuery, setCpvQuery] = useState('');
    const [cpvSuggestions, setCpvSuggestions] = useState([]);
    const [cpvLookupLoading, setCpvLookupLoading] = useState(false);
    const requestSequenceRef = useRef(0);
    const canChooseDepartmentOwner = ownerOptions.some((option) => option.value === 'department');
    const selectedOwnerOption = ownerOptions.find((option) => option.value === form.data.owner_scope);
    const selectedCodes = new Set(
        form.data.cpv_codes.map((row) => String(row?.cpv_code ?? '').trim()).filter(Boolean),
    );
    const selectedCpvCodesSignature = [...selectedCodes].join('|');
    const trimmedCpvQuery = cpvQuery.trim();

    // The catalog is searched by description, so nobody has to know a CPV number to begin with.
    useEffect(() => {
        if (!cpvSuggestionsUrl || trimmedCpvQuery === '') {
            setCpvSuggestions([]);
            setCpvLookupLoading(false);

            return undefined;
        }

        const requestId = requestSequenceRef.current + 1;
        const abortController = new AbortController();
        const url = new URL(cpvSuggestionsUrl, window.location.origin);

        requestSequenceRef.current = requestId;
        url.searchParams.set('query', trimmedCpvQuery);
        url.searchParams.set('limit', '10');

        if (selectedCpvCodesSignature !== '') {
            url.searchParams.set('selected', selectedCpvCodesSignature.split('|').join(','));
        }

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

                const items = Array.isArray(payload.data) ? payload.data : [];

                // Watch profiles return {code, description}; the notices endpoint returns {code, label}.
                setCpvSuggestions(items.map((item) => ({
                    code: item.code,
                    label: item.description || item.label || item.code,
                })));
            })
            .catch((error) => {
                if (error.name === 'AbortError' || requestSequenceRef.current !== requestId) {
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
    }, [cpvSuggestionsUrl, trimmedCpvQuery, selectedCpvCodesSignature]);

    const addCpvCode = (suggestion) => {
        if (selectedCodes.has(suggestion.code)) {
            return;
        }

        form.setData('cpv_codes', [
            ...form.data.cpv_codes,
            { cpv_code: suggestion.code, description: suggestion.label, weight: 1 },
        ]);
    };

    const setCpvWeight = (code, value) => {
        form.setData(
            'cpv_codes',
            form.data.cpv_codes.map((row) => (
                row.cpv_code === code ? { ...row, weight: value } : row
            )),
        );
    };

    const removeCpvCode = (code) => {
        form.setData(
            'cpv_codes',
            form.data.cpv_codes.filter((row) => row.cpv_code !== code),
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
                {showHeader ? (
                    <div className="space-y-1.5">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950">{title}</h1>
                        <p className="text-base leading-6 text-slate-600">{subtitle}</p>
                    </div>
                ) : null}

                <div className="grid gap-5 md:grid-cols-2">
                    <label className="space-y-2 md:col-span-2">
                        <span className="text-base font-medium text-slate-700">Navn</span>
                        <input
                            type="text"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                        />
                        {form.errors.name ? <p className="text-base text-rose-700">{form.errors.name}</p> : null}
                    </label>

                    <label className="space-y-2">
                        <span className="flex items-center gap-2 text-base font-medium text-slate-700">
                            Eierskap
                            <InfoHint size="sm" label="Vis forklaring for Eierskap" text={wp.hint_owner_scope ?? 'Bestemmer om profilen er din egen eller tilhører en avdeling. Avdelingsprofiler er synlige for alle som er medlem av avdelingen.'} />
                        </span>
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
                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            >
                                {ownerOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base text-slate-700">
                                {selectedOwnerOption?.label}
                            </div>
                        )}
                        {selectedOwnerOption?.description ? (
                            <p className="text-base text-slate-600">{selectedOwnerOption.description}</p>
                        ) : null}
                        {form.errors.owner_scope ? <p className="text-base text-rose-700">{form.errors.owner_scope}</p> : null}
                    </label>

                    <label className="space-y-2">
                        <span className="text-base font-medium text-slate-700">Avdeling</span>
                        {form.data.owner_scope === 'department' ? (
                            <>
                                <select
                                    value={form.data.department_id ?? ''}
                                    onChange={(event) => form.setData('department_id', event.target.value === '' ? null : Number(event.target.value))}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                >
                                    <option value="">Velg avdeling</option>
                                    {departmentOptions.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                {departmentOptions.length === 0 && canChooseDepartmentOwner ? (
                                    <p className="text-base text-slate-600">Ingen avdeling er tilgjengelig for denne brukeren.</p>
                                ) : null}
                            </>
                        ) : (
                            <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base text-slate-600">
                                Personlige watch profiles knyttes til deg og ikke til en avdeling.
                            </div>
                        )}
                        {form.errors.department_id ? <p className="text-base text-rose-700">{form.errors.department_id}</p> : null}
                    </label>

                    <label className="space-y-2">
                        <span className="flex items-center gap-2 text-base font-medium text-slate-700">
                            Status
                            <InfoHint size="sm" label="Vis forklaring for Status" text={wp.hint_status ?? 'Bare aktive profiler brukes til å finne nye treff. Inaktive beholder kriteriene sine.'} />
                        </span>
                        <label className="flex min-h-11 items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 text-base text-slate-700">
                            <input
                                type="checkbox"
                                checked={form.data.is_active}
                                onChange={(event) => form.setData('is_active', event.target.checked)}
                                className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                            />
                            <span>{form.data.is_active ? 'Aktiv' : 'Inaktiv'}</span>
                        </label>
                        {form.errors.is_active ? <p className="text-base text-rose-700">{form.errors.is_active}</p> : null}
                    </label>

                    <label className="space-y-2 md:col-span-2">
                        <span className="flex items-center gap-2 text-base font-medium text-slate-700">
                            Beskrivelse
                            <InfoHint size="sm" label="Vis forklaring for Beskrivelse" text={wp.hint_description ?? 'Valgfritt notat om hva profilen skal følge. Beskrivelsen påvirker ikke hvilke kunngjøringer som gir treff.'} />
                        </span>
                        <textarea
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                            rows={4}
                            className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                        />
                        {form.errors.description ? <p className="text-base text-rose-700">{form.errors.description}</p> : null}
                    </label>

                    <label className="space-y-2 md:col-span-2">
                        <span className="flex items-center gap-2 text-base font-medium text-slate-700">
                            Nøkkelord
                            <InfoHint size="sm" label="Vis forklaring for Nøkkelord" text={wp.hint_keywords ?? 'Ord eller fraser som letes etter i kunngjøringens tittel, beskrivelse og oppdragsgiver. Ett nøkkelord per linje.'} />
                        </span>
                        <textarea
                            value={form.data.keywords}
                            onChange={(event) => form.setData('keywords', event.target.value)}
                            rows={6}
                            className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                        />
                        <p className="text-base text-slate-600">{wp.keywords_help ?? 'Ett nøkkelord per linje.'}</p>
                        {form.errors.keywords ? <p className="text-base text-rose-700">{form.errors.keywords}</p> : null}
                    </label>
                </div>

                <section className="space-y-5 rounded-[20px] border border-slate-200 bg-slate-50/70 p-5">
                    <div>
                        <h2 className="flex items-center gap-2 text-lg font-semibold text-slate-950">
                            CPV-koder
                            <InfoHint size="sm" label="Vis forklaring for CPV-koder" text={wp.hint_cpv ?? 'CPV er den offisielle kategoriseringen av offentlige anskaffelser. Velg kodene som dekker det profilen skal følge.'} />
                        </h2>
                        <p className="text-base leading-6 text-slate-600">
                            Beskriv hva du leter etter, så foreslår vi koder fra CPV-katalogen. Du kan også skrive en kode direkte.
                        </p>
                    </div>

                    {form.errors.cpv_codes ? <p className="text-base text-rose-700">{form.errors.cpv_codes}</p> : null}

                    <div className="space-y-2">
                        <label className="text-base font-medium text-slate-700" htmlFor={cpvSearchId}>
                            Søk etter CPV
                        </label>
                        <input
                            id={cpvSearchId}
                            type="text"
                            value={cpvQuery}
                            onChange={(event) => setCpvQuery(event.target.value)}
                            placeholder="Søk etter f.eks. nettverk, IT-drift eller datasenter"
                            aria-describedby={cpvResultsId}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition placeholder:text-slate-500 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                        />
                    </div>

                    <div id={cpvResultsId} aria-live="polite" className="space-y-2">
                        {cpvLookupLoading ? (
                            <p className="text-base text-slate-600">Søker i CPV-katalogen...</p>
                        ) : null}

                        {!cpvLookupLoading && trimmedCpvQuery !== '' && cpvSuggestions.length === 0 ? (
                            <p className="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-3 text-base text-slate-600">
                                Ingen treff på «{trimmedCpvQuery}». Prøv et annet ord, eller skriv CPV-koden.
                            </p>
                        ) : null}

                        {cpvSuggestions.length > 0 ? (
                            <ul className="space-y-2">
                                {cpvSuggestions.map((suggestion) => {
                                    const alreadySelected = selectedCodes.has(suggestion.code);

                                    return (
                                        <li
                                            key={suggestion.code}
                                            className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl border border-slate-200 bg-white px-4 py-3"
                                        >
                                            <div className="min-w-0">
                                                <div className="text-base font-medium text-slate-950">{suggestion.label}</div>
                                                <div className="text-base text-slate-600">{suggestion.code}</div>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => addCpvCode(suggestion)}
                                                disabled={alreadySelected}
                                                className="inline-flex min-h-10 shrink-0 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2 text-base font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-500"
                                            >
                                                {alreadySelected ? 'Allerede valgt' : 'Legg til'}
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        ) : null}
                    </div>

                    <div className="space-y-2 border-t border-slate-200 pt-4">
                        <h3 className="text-base font-semibold text-slate-950">
                            Valgte CPV-koder{form.data.cpv_codes.length > 0 ? ` (${form.data.cpv_codes.length})` : ''}
                        </h3>

                        {form.data.cpv_codes.length === 0 ? (
                            <p className="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-6 text-base text-slate-600">
                                Ingen CPV-koder er lagt til ennå.
                            </p>
                        ) : (
                            <ul className="grid gap-2 lg:grid-cols-2">
                                {form.data.cpv_codes.map((row, index) => (
                                    <li
                                        key={row.cpv_code || index}
                                        className="rounded-xl border border-slate-200 bg-white px-4 py-3"
                                    >
                                        <div className="min-w-0">
                                            <div className="text-base font-medium text-slate-950">
                                                {row.description || row.cpv_code}
                                            </div>
                                            <div className="text-base text-slate-600">{row.cpv_code}</div>
                                            {cpvFieldError(form.errors, index, 'cpv_code') ? (
                                                <p className="text-base text-rose-700">{cpvFieldError(form.errors, index, 'cpv_code')}</p>
                                            ) : null}
                                        </div>

                                        <div className="mt-3 flex items-center justify-between gap-3">
                                            <label className="flex items-center gap-2">
                                                <span className="flex items-center gap-1.5 text-base text-slate-600">
                                                    Vekt
                                                    <InfoHint size="sm" label="Vis forklaring for Vekt" text={wp.hint_weight ?? 'Hvor mye denne koden teller når en kunngjøring scores. Høyere vekt gir treffet større betydning enn koder med lavere vekt.'} />
                                                </span>
                                                <input
                                                    type="number"
                                                    min="1"
                                                    value={row.weight}
                                                    onChange={(event) => setCpvWeight(row.cpv_code, event.target.value)}
                                                    aria-label={`Vekt for ${row.description || row.cpv_code}`}
                                                    className="h-10 w-20 rounded-xl border border-slate-200 bg-white px-3 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                />
                                            </label>
                                            <button
                                                type="button"
                                                onClick={() => removeCpvCode(row.cpv_code)}
                                                aria-label={`Fjern ${row.description || row.cpv_code}`}
                                                className="inline-flex min-h-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-base font-semibold text-slate-700 transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700"
                                            >
                                                Fjern
                                            </button>
                                        </div>

                                        {cpvFieldError(form.errors, index, 'weight') ? (
                                            <p className="mt-2 text-base text-rose-700">{cpvFieldError(form.errors, index, 'weight')}</p>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </section>

                <div className="flex flex-col gap-3 sm:flex-row sm:justify-between">
                    <div>
                        {deleteUrl ? (
                            <button
                                type="button"
                                onClick={deleteWatchProfile}
                                disabled={form.processing}
                                className="inline-flex min-h-11 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-base font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                Slett Watch Profile
                            </button>
                        ) : null}
                    </div>

                    <div className="flex flex-col gap-3 sm:flex-row">
                        <Link
                            href={backHref}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                        >
                            Tilbake
                        </Link>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 ${PRIMARY_COLOURS}`}
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
