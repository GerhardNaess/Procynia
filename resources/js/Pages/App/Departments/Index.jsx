import { Link, router } from '@inertiajs/react';
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

export default function DepartmentsIndex({ departments }) {
    const [togglingDepartmentId, setTogglingDepartmentId] = useState(null);
    const locale = document.documentElement.lang || 'no';

    const toggleActive = (department) => {
        if (department.is_active && !window.confirm(`Er du sikker på at du vil deaktivere ${department.name}?`)) {
            return;
        }

        setTogglingDepartmentId(department.id);

        router.patch(department.toggle_active_url, {}, {
            preserveScroll: true,
            onFinish: () => setTogglingDepartmentId(null),
        });
    };

    return (
        <CustomerAppLayout title="Avdelinger" showPageTitle={false}>
            <div className="space-y-6">
                <section className="flex flex-col gap-4 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:flex-row sm:items-end sm:justify-between">
                    <div className="space-y-1.5">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950">Avdelinger</h1>
                        <p className="max-w-2xl text-sm leading-6 text-slate-500">
                            Organiser kunden i avdelinger som kan brukes for struktur i profiler og varsler i senere faser.
                        </p>
                    </div>
                    <Link
                        href="/app/departments/create"
                        className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700"
                    >
                        Legg til avdeling
                    </Link>
                </section>

                {departments.length === 0 ? (
                    <section className="rounded-[24px] border border-dashed border-slate-300 bg-white px-6 py-14 text-center shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="text-lg font-semibold text-slate-900">Ingen avdelinger ennå</div>
                        <p className="mt-2 text-sm text-slate-500">Legg til den første avdelingen for å strukturere kunden bedre.</p>
                    </section>
                ) : (
                    <>
                        <section className="grid gap-3 md:hidden">
                            {departments.map((department) => (
                                <article
                                    key={department.id}
                                    className="rounded-[20px] border border-slate-200 bg-white p-5 shadow-[0_8px_22px_rgba(15,23,42,0.04)]"
                                >
                                    <div className="space-y-3">
                                        <div>
                                            <div className="text-base font-semibold text-slate-950">{department.name}</div>
                                            <div className="mt-1 text-sm text-slate-500">{department.description || 'Ingen beskrivelse ennå.'}</div>
                                        </div>
                                        <div className="flex flex-wrap gap-2 text-xs font-medium">
                                            <span
                                                className={
                                                    department.is_active
                                                        ? 'rounded-full bg-emerald-100 px-3 py-1 text-emerald-700'
                                                        : 'rounded-full bg-slate-200 px-3 py-1 text-slate-700'
                                                }
                                            >
                                                {department.is_active ? 'Aktiv' : 'Inaktiv'}
                                            </span>
                                        </div>
                                        <div className="text-xs text-slate-400">Opprettet {formatDate(department.created_at, locale)}</div>
                                        <div className="flex flex-col gap-2 sm:flex-row">
                                            <Link
                                                href={department.edit_url}
                                                className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                            >
                                                Rediger
                                            </Link>
                                            <button
                                                type="button"
                                                onClick={() => toggleActive(department)}
                                                disabled={togglingDepartmentId === department.id}
                                                className={
                                                    department.is_active
                                                        ? 'inline-flex min-h-10 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100 disabled:opacity-60'
                                                        : 'inline-flex min-h-10 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100 disabled:opacity-60'
                                                }
                                            >
                                                {department.is_active
                                                    ? togglingDepartmentId === department.id
                                                        ? 'Deaktiverer...'
                                                        : 'Deaktiver'
                                                    : togglingDepartmentId === department.id
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
                                            <th className="px-6 py-4">Beskrivelse</th>
                                            <th className="px-6 py-4">Status</th>
                                            <th className="px-6 py-4">Opprettet</th>
                                            <th className="px-6 py-4">Handlinger</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {departments.map((department) => (
                                            <tr key={department.id} className="text-sm text-slate-700">
                                                <td className="px-6 py-4 font-medium text-slate-950">{department.name}</td>
                                                <td className="px-6 py-4 text-slate-500">{department.description || '—'}</td>
                                                <td className="px-6 py-4">
                                                    <span
                                                        className={
                                                            department.is_active
                                                                ? 'inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700'
                                                                : 'inline-flex rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700'
                                                        }
                                                    >
                                                        {department.is_active ? 'Aktiv' : 'Inaktiv'}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 text-slate-500">{formatDate(department.created_at, locale)}</td>
                                                <td className="px-6 py-4">
                                                    <div className="flex flex-wrap gap-2">
                                                        <Link
                                                            href={department.edit_url}
                                                            className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                        >
                                                            Rediger
                                                        </Link>
                                                        <button
                                                            type="button"
                                                            onClick={() => toggleActive(department)}
                                                            disabled={togglingDepartmentId === department.id}
                                                            className={
                                                                department.is_active
                                                                    ? 'inline-flex min-h-10 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100 disabled:opacity-60'
                                                                    : 'inline-flex min-h-10 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100 disabled:opacity-60'
                                                            }
                                                        >
                                                            {department.is_active
                                                                ? togglingDepartmentId === department.id
                                                                    ? 'Deaktiverer...'
                                                                    : 'Deaktiver'
                                                                : togglingDepartmentId === department.id
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
