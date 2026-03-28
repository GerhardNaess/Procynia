import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

export default function UsersEdit({ user, roleOptions }) {
    const [toggling, setToggling] = useState(false);
    const form = useForm({
        name: user.name,
        role: user.role_value,
    });

    const submit = (event) => {
        event.preventDefault();
        form.put(user.update_url);
    };

    const toggleActive = () => {
        if (!user.can_toggle_active || toggling) {
            return;
        }

        if (user.is_active && !window.confirm(`Er du sikker på at du vil deaktivere ${user.name}?`)) {
            return;
        }

        setToggling(true);

        router.patch(user.toggle_active_url, {}, {
            onFinish: () => setToggling(false),
        });
    };

    return (
        <CustomerAppLayout title="Rediger bruker" showPageTitle={false}>
            <div className="mx-auto max-w-3xl">
                <form
                    onSubmit={submit}
                    className="space-y-6 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:p-8"
                >
                    <div className="space-y-1.5">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950">Rediger bruker</h1>
                        <p className="text-sm leading-6 text-slate-500">
                            Oppdater rolle og status for brukeren innenfor din egen kunde.
                        </p>
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

                        <label className="space-y-2 md:col-span-2">
                            <span className="text-sm font-medium text-slate-700">E-post</span>
                            <input
                                type="email"
                                value={user.email}
                                disabled
                                className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-500 outline-none disabled:cursor-not-allowed"
                            />
                            <p className="text-xs text-slate-400">E-post redigeres ikke i denne fasen.</p>
                        </label>

                        <label className="space-y-2">
                            <span className="text-sm font-medium text-slate-700">Rolle</span>
                            <select
                                value={form.data.role}
                                onChange={(event) => form.setData('role', event.target.value)}
                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            >
                                {roleOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                        disabled={option.value === 'user' && !user.can_demote && user.role_value === 'admin'}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            {!user.can_demote && user.role_value === 'admin' ? (
                                <p className="text-xs text-slate-400">Denne brukeren kan ikke nedgraderes fordi kunden må ha minst én aktiv administrator.</p>
                            ) : null}
                            {form.errors.role ? <p className="text-sm text-rose-600">{form.errors.role}</p> : null}
                        </label>

                        <div className="space-y-2">
                            <span className="text-sm font-medium text-slate-700">Status</span>
                            <div className="flex min-h-11 items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-700">
                                <span>{user.is_active ? 'Aktiv' : 'Inaktiv'}</span>
                                <button
                                    type="button"
                                    disabled={!user.can_toggle_active || toggling}
                                    onClick={toggleActive}
                                    className={
                                        user.can_toggle_active
                                            ? user.is_active
                                                ? 'inline-flex min-h-9 items-center justify-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100'
                                                : 'inline-flex min-h-9 items-center justify-center rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100'
                                            : 'inline-flex min-h-9 items-center justify-center rounded-lg border border-slate-200 bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-400'
                                    }
                                >
                                    {user.is_active
                                        ? user.is_self
                                            ? 'Egen konto'
                                            : toggling
                                                ? 'Deaktiverer...'
                                                : 'Deaktiver'
                                        : toggling
                                            ? 'Aktiverer...'
                                            : 'Aktiver'}
                                </button>
                            </div>
                            {user.is_self ? <p className="text-xs text-slate-400">Du kan ikke deaktivere din egen bruker.</p> : null}
                        </div>
                    </div>

                    <div className="flex flex-col gap-3 sm:flex-row sm:justify-end">
                        <Link
                            href="/app/users"
                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                        >
                            Tilbake
                        </Link>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {form.processing ? 'Lagrer...' : 'Lagre endringer'}
                        </button>
                    </div>
                </form>
            </div>
        </CustomerAppLayout>
    );
}
