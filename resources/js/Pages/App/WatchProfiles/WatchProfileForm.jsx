import { Link, router } from '@inertiajs/react';

function cpvFieldError(errors, index, field) {
    return errors[`cpv_codes.${index}.${field}`];
}

export default function WatchProfileForm({
    title,
    subtitle,
    form,
    departmentOptions,
    backHref,
    submitLabel,
    onSubmit,
    submitMethod,
    deleteUrl = null,
}) {
    const addCpvRule = () => {
        form.setData('cpv_codes', [
            ...form.data.cpv_codes,
            { cpv_code: '', weight: 1 },
        ]);
    };

    const updateCpvRule = (index, field, value) => {
        form.setData(
            'cpv_codes',
            form.data.cpv_codes.map((row, rowIndex) => (
                rowIndex === index
                    ? { ...row, [field]: value }
                    : row
            )),
        );
    };

    const removeCpvRule = (index) => {
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
                        <span className="text-sm font-medium text-slate-700">Avdeling</span>
                        <select
                            value={form.data.department_id ?? ''}
                            onChange={(event) => form.setData('department_id', event.target.value === '' ? null : Number(event.target.value))}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                        >
                            <option value="">Ingen avdeling</option>
                            {departmentOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
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
                            <p className="text-sm text-slate-500">Legg inn én rad per CPV-kode med tilhørende vekt.</p>
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
                        <div className="space-y-3">
                            {form.data.cpv_codes.map((row, index) => (
                                <div key={`cpv-row-${index}`} className="rounded-xl border border-slate-200 bg-white p-4">
                                    <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_140px_auto] md:items-start">
                                        <label className="space-y-2">
                                            <span className="text-sm font-medium text-slate-700">CPV-kode</span>
                                            <input
                                                type="text"
                                                value={row.cpv_code}
                                                onChange={(event) => updateCpvRule(index, 'cpv_code', event.target.value)}
                                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                            />
                                            {cpvFieldError(form.errors, index, 'cpv_code') ? (
                                                <p className="text-sm text-rose-600">{cpvFieldError(form.errors, index, 'cpv_code')}</p>
                                            ) : null}
                                        </label>

                                        <label className="space-y-2">
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

                                        <div className="flex items-end">
                                            <button
                                                type="button"
                                                onClick={() => removeCpvRule(index)}
                                                className="inline-flex min-h-11 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100"
                                            >
                                                Fjern
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            ))}
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
