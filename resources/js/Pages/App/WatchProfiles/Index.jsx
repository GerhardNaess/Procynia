import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

function formatDate(value, locale) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}

function pluralize(count, singular, plural) {
    return `${count} ${count === 1 ? singular : plural}`;
}

function buildFilterQuery(filters) {
    return Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== null && value !== undefined && value !== ''),
    );
}

export default function WatchProfilesIndex({ watchProfiles, filters, filterOptions }) {
    const { locale } = usePage().props;
    const [togglingId, setTogglingId] = useState(null);
    const [deletingId, setDeletingId] = useState(null);

    const applyFilters = (nextFilters) => {
        router.get('/app/watch-profiles', buildFilterQuery(nextFilters), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const toggleActive = (watchProfile) => {
        if (watchProfile.is_active && !window.confirm(`Er du sikker på at du vil deaktivere ${watchProfile.name}?`)) {
            return;
        }

        setTogglingId(watchProfile.id);

        router.patch(watchProfile.toggle_active_url, {}, {
            preserveScroll: true,
            onFinish: () => setTogglingId(null),
        });
    };

    const deleteWatchProfile = (watchProfile) => {
        if (!window.confirm(`Er du sikker på at du vil slette ${watchProfile.name}?`)) {
            return;
        }

        setDeletingId(watchProfile.id);

        router.delete(watchProfile.delete_url, {
            preserveScroll: true,
            onFinish: () => setDeletingId(null),
        });
    };

    return (
        <CustomerAppLayout title="Watch Profiles" showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Watch Profiles</h1>
                    <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                        Administrer dine personlige og avdelingsscopede watch profiles som brukes direkte mot Doffin live search.
                    </p>
                </section>

                <section className="rounded-[24px] border border-slate-200 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="mb-4 flex justify-end">
                        <Link
                            href="/app/watch-profiles/create"
                            className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700"
                        >
                            Legg til Watch Profile
                        </Link>
                    </div>

                    <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
                        <label className="space-y-2">
                            <span className="text-sm font-medium text-slate-700">Bruker</span>
                            <select
                                value={filters.user_id ?? ''}
                                onChange={(event) => applyFilters({
                                    ...filters,
                                    user_id: event.target.value === '' ? null : Number(event.target.value),
                                })}
                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            >
                                <option value="">Alle brukere</option>
                                {filterOptions.users.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="space-y-2">
                            <span className="text-sm font-medium text-slate-700">Avdeling</span>
                            <select
                                value={filters.department_id ?? ''}
                                onChange={(event) => applyFilters({
                                    ...filters,
                                    department_id: event.target.value === '' ? null : Number(event.target.value),
                                })}
                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            >
                                <option value="">Alle avdelinger</option>
                                {filterOptions.departments.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <div className="flex md:justify-end">
                            <button
                                type="button"
                                onClick={() => applyFilters({ user_id: null, department_id: null })}
                                className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                            >
                                Nullstill filtre
                            </button>
                        </div>
                    </div>
                </section>

                {watchProfiles.length === 0 ? (
                    <section className="rounded-[24px] border border-dashed border-slate-300 bg-white px-6 py-14 text-center shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="text-lg font-semibold text-slate-900">Ingen Watch Profiles ennå</div>
                        <p className="mt-2 text-sm text-slate-500">Opprett den første profilen for å definere personlige eller avdelingsvise overvåkningskriterier.</p>
                    </section>
                ) : (
                    <>
                        <section className="grid gap-3 md:hidden">
                            {watchProfiles.map((watchProfile) => (
                                <article
                                    key={watchProfile.id}
                                    className="rounded-[20px] border border-slate-200 bg-white p-5 shadow-[0_8px_22px_rgba(15,23,42,0.04)]"
                                >
                                    <div className="space-y-3">
                                        <div>
                                            <div className="text-base font-semibold text-slate-950">{watchProfile.name}</div>
                                            <div className="mt-1 text-sm text-slate-500">
                                                {watchProfile.owner_label}: {watchProfile.owner_reference}
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap gap-2 text-xs font-medium">
                                            <span
                                                className={
                                                    watchProfile.is_active
                                                        ? 'rounded-full bg-emerald-100 px-3 py-1 text-emerald-700'
                                                        : 'rounded-full bg-slate-200 px-3 py-1 text-slate-700'
                                                }
                                            >
                                                {watchProfile.is_active ? 'Aktiv' : 'Inaktiv'}
                                            </span>
                                            <span className="rounded-full bg-violet-100 px-3 py-1 text-violet-700">
                                                {pluralize(watchProfile.cpv_rule_count, 'CPV-regel', 'CPV-regler')}
                                            </span>
                                            <span className="rounded-full bg-slate-100 px-3 py-1 text-slate-700">
                                                {pluralize(watchProfile.keyword_count, 'nøkkelord', 'nøkkelord')}
                                            </span>
                                        </div>
                                        <div className="text-xs text-slate-400">Oppdatert {formatDate(watchProfile.updated_at, locale)}</div>
                                        <div className="flex flex-col gap-2 sm:flex-row">
                                            <Link
                                                href={watchProfile.edit_url}
                                                className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                            >
                                                Rediger
                                            </Link>
                                            <button
                                                type="button"
                                                onClick={() => toggleActive(watchProfile)}
                                                disabled={togglingId === watchProfile.id}
                                                className={
                                                    watchProfile.is_active
                                                        ? 'inline-flex min-h-10 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100 disabled:opacity-60'
                                                        : 'inline-flex min-h-10 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100 disabled:opacity-60'
                                                }
                                            >
                                                {watchProfile.is_active
                                                    ? togglingId === watchProfile.id
                                                        ? 'Deaktiverer...'
                                                        : 'Deaktiver'
                                                    : togglingId === watchProfile.id
                                                        ? 'Aktiverer...'
                                                        : 'Aktiver'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => deleteWatchProfile(watchProfile)}
                                                disabled={deletingId === watchProfile.id}
                                                className="inline-flex min-h-10 items-center justify-center rounded-xl border border-rose-200 bg-white px-3 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-50 disabled:opacity-60"
                                            >
                                                {deletingId === watchProfile.id ? 'Sletter...' : 'Slett'}
                                            </button>
                                        </div>
                                    </div>
                                </article>
                            ))}
                        </section>

                        <section className="hidden overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)] md:block">
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-slate-200">
                                    <thead className="bg-slate-50">
                                        <tr className="text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                            <th className="px-6 py-4">Navn</th>
                                            <th className="px-6 py-4">Eier</th>
                                            <th className="px-6 py-4">CPV-regler</th>
                                            <th className="px-6 py-4">Nøkkelord</th>
                                            <th className="px-6 py-4">Status</th>
                                            <th className="px-6 py-4">Oppdatert</th>
                                            <th className="px-6 py-4">Handlinger</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {watchProfiles.map((watchProfile) => (
                                            <tr key={watchProfile.id} className="text-sm text-slate-700">
                                                <td className="px-6 py-4 font-medium text-slate-950">{watchProfile.name}</td>
                                                <td className="px-6 py-4 text-slate-500">{`${watchProfile.owner_label}: ${watchProfile.owner_reference}`}</td>
                                                <td className="px-6 py-4">{pluralize(watchProfile.cpv_rule_count, 'CPV-regel', 'CPV-regler')}</td>
                                                <td className="px-6 py-4">{pluralize(watchProfile.keyword_count, 'nøkkelord', 'nøkkelord')}</td>
                                                <td className="px-6 py-4">
                                                    <span
                                                        className={
                                                            watchProfile.is_active
                                                                ? 'inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700'
                                                                : 'inline-flex rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700'
                                                        }
                                                    >
                                                        {watchProfile.is_active ? 'Aktiv' : 'Inaktiv'}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 text-slate-500">{formatDate(watchProfile.updated_at, locale)}</td>
                                                <td className="px-6 py-4">
                                                    <div className="flex flex-wrap gap-2">
                                                        <Link
                                                            href={watchProfile.edit_url}
                                                            className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                        >
                                                            Rediger
                                                        </Link>
                                                        <button
                                                            type="button"
                                                            onClick={() => toggleActive(watchProfile)}
                                                            disabled={togglingId === watchProfile.id}
                                                            className={
                                                                watchProfile.is_active
                                                                    ? 'inline-flex min-h-10 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100 disabled:opacity-60'
                                                                    : 'inline-flex min-h-10 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100 disabled:opacity-60'
                                                            }
                                                        >
                                                            {watchProfile.is_active
                                                                ? togglingId === watchProfile.id
                                                                    ? 'Deaktiverer...'
                                                                    : 'Deaktiver'
                                                                : togglingId === watchProfile.id
                                                                    ? 'Aktiverer...'
                                                                    : 'Aktiver'}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => deleteWatchProfile(watchProfile)}
                                                            disabled={deletingId === watchProfile.id}
                                                            className="inline-flex min-h-10 items-center justify-center rounded-xl border border-rose-200 bg-white px-3 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-50 disabled:opacity-60"
                                                        >
                                                            {deletingId === watchProfile.id ? 'Sletter...' : 'Slett'}
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </>
                )}
            </div>
        </CustomerAppLayout>
    );
}
