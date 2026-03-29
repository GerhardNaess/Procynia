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

export default function UsersIndex({ users }) {
    const { locale, flash } = usePage().props;
    const [togglingUserId, setTogglingUserId] = useState(null);

    const toggleActive = (user) => {
        if (!user.can_toggle_active) {
            return;
        }

        if (user.is_active && !window.confirm(`Er du sikker på at du vil deaktivere ${user.name}?`)) {
            return;
        }

        setTogglingUserId(user.id);

        router.patch(user.toggle_active_url, {}, {
            preserveScroll: true,
            onFinish: () => setTogglingUserId(null),
        });
    };

    return (
        <CustomerAppLayout title="Brukere" showPageTitle={false}>
            <div className="space-y-6">
                <section className="flex flex-col gap-4 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:flex-row sm:items-end sm:justify-between">
                    <div className="space-y-1.5">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950">Brukere</h1>
                        <p className="max-w-2xl text-sm leading-6 text-slate-500">
                            Administrer brukere for din egen kunde. Nye brukere opprettes som aktive og får et midlertidig passord.
                        </p>
                    </div>
                    <Link
                        href="/app/users/create"
                        className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700"
                    >
                        Legg til bruker
                    </Link>
                </section>

                {flash?.userCreated ? (
                    <section className="rounded-[24px] border border-amber-200 bg-amber-50 p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="text-sm font-semibold text-amber-900">Midlertidig passord</div>
                        <p className="mt-2 text-sm leading-6 text-amber-900">
                            Brukeren <span className="font-semibold">{flash.userCreated.name}</span> er opprettet for{' '}
                            <span className="font-semibold">{flash.userCreated.email}</span>.
                        </p>
                        <div className="mt-4 inline-flex rounded-xl border border-amber-300 bg-white px-4 py-3 text-sm font-semibold tracking-[0.04em] text-slate-900">
                            {flash.userCreated.temporaryPassword}
                        </div>
                        <p className="mt-3 text-xs leading-5 text-amber-800">
                            Dette passordet vises kun denne gangen. Del det sikkert med brukeren og be dem endre det ved første anledning.
                        </p>
                    </section>
                ) : null}

                {users.length === 0 ? (
                    <section className="rounded-[24px] border border-dashed border-slate-300 bg-white px-6 py-14 text-center shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="text-lg font-semibold text-slate-900">Ingen brukere ennå</div>
                        <p className="mt-2 text-sm text-slate-500">Legg til den første brukeren for å gi flere tilgang til kundeområdet.</p>
                    </section>
                ) : (
                    <>
                        <section className="grid gap-3 md:hidden">
                            {users.map((user) => (
                                <article
                                    key={user.id}
                                    className="rounded-[20px] border border-slate-200 bg-white p-5 shadow-[0_8px_22px_rgba(15,23,42,0.04)]"
                                >
                                    <div className="space-y-3">
                                        <div>
                                            <div className="text-base font-semibold text-slate-950">{user.name}</div>
                                            <div className="text-sm text-slate-500">{user.email}</div>
                                            <div className="mt-1 text-sm text-slate-500">
                                                {user.department_name ? `Avdeling: ${user.department_name}` : 'Ingen avdeling'}
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap gap-2 text-xs font-medium">
                                            <span className="rounded-full bg-violet-100 px-3 py-1 text-violet-700">{user.role}</span>
                                            <span
                                                className={
                                                    user.is_active
                                                        ? 'rounded-full bg-emerald-100 px-3 py-1 text-emerald-700'
                                                        : 'rounded-full bg-slate-200 px-3 py-1 text-slate-700'
                                                }
                                            >
                                                {user.is_active ? 'Aktiv' : 'Inaktiv'}
                                            </span>
                                        </div>
                                        <div className="text-xs text-slate-400">Opprettet {formatDate(user.created_at, locale)}</div>
                                        <div className="flex flex-col gap-2 sm:flex-row">
                                            <Link
                                                href={user.edit_url}
                                                className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                            >
                                                Rediger
                                            </Link>
                                            <button
                                                type="button"
                                                disabled={!user.can_toggle_active || togglingUserId === user.id}
                                                onClick={() => toggleActive(user)}
                                                className={
                                                    user.can_toggle_active
                                                        ? user.is_active
                                                            ? 'inline-flex min-h-10 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100'
                                                            : 'inline-flex min-h-10 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100'
                                                        : 'inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-400'
                                                }
                                            >
                                                {user.is_active
                                                    ? user.is_self
                                                        ? 'Egen konto'
                                                        : togglingUserId === user.id
                                                            ? 'Deaktiverer...'
                                                            : 'Deaktiver'
                                                    : togglingUserId === user.id
                                                        ? 'Aktiverer...'
                                                        : 'Aktiver'}
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
                                            <th className="px-6 py-4">E-post</th>
                                            <th className="px-6 py-4">Avdeling</th>
                                            <th className="px-6 py-4">Rolle</th>
                                            <th className="px-6 py-4">Status</th>
                                            <th className="px-6 py-4">Opprettet</th>
                                            <th className="px-6 py-4">Handlinger</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {users.map((user) => (
                                            <tr key={user.id} className="text-sm text-slate-700">
                                                <td className="px-6 py-4 font-medium text-slate-950">{user.name}</td>
                                                <td className="px-6 py-4">{user.email}</td>
                                                <td className="px-6 py-4 text-slate-500">{user.department_name ?? '—'}</td>
                                                <td className="px-6 py-4">{user.role}</td>
                                                <td className="px-6 py-4">
                                                    <span
                                                        className={
                                                            user.is_active
                                                                ? 'inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700'
                                                                : 'inline-flex rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700'
                                                        }
                                                    >
                                                        {user.is_active ? 'Aktiv' : 'Inaktiv'}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 text-slate-500">{formatDate(user.created_at, locale)}</td>
                                                <td className="px-6 py-4">
                                                    <div className="flex flex-wrap gap-2">
                                                        <Link
                                                            href={user.edit_url}
                                                            className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                        >
                                                            Rediger
                                                        </Link>
                                                        <button
                                                            type="button"
                                                            disabled={!user.can_toggle_active || togglingUserId === user.id}
                                                            onClick={() => toggleActive(user)}
                                                            className={
                                                                user.can_toggle_active
                                                                    ? user.is_active
                                                                        ? 'inline-flex min-h-10 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100'
                                                                        : 'inline-flex min-h-10 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100'
                                                                    : 'inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-400'
                                                            }
                                                        >
                                                            {user.is_active
                                                                ? user.is_self
                                                                    ? 'Egen konto'
                                                                    : togglingUserId === user.id
                                                                        ? 'Deaktiverer...'
                                                                        : 'Deaktiver'
                                                                : togglingUserId === user.id
                                                                    ? 'Aktiverer...'
                                                                    : 'Aktiver'}
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
