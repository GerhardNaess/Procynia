import { Link, useForm } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

export default function UsersCreate({ roleOptions }) {
    const form = useForm({
        name: '',
        email: '',
        role: 'user',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post('/app/users');
    };

    return (
        <CustomerAppLayout title="Legg til bruker" showPageTitle={false}>
            <div className="mx-auto max-w-3xl">
                <form
                    onSubmit={submit}
                    className="space-y-6 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:p-8"
                >
                    <div className="space-y-1.5">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950">Legg til bruker</h1>
                        <p className="text-sm leading-6 text-slate-500">
                            Opprett en ny bruker for din kunde. Systemet genererer et midlertidig passord som vises én gang etter lagring.
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
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            />
                            {form.errors.email ? <p className="text-sm text-rose-600">{form.errors.email}</p> : null}
                        </label>

                        <label className="space-y-2">
                            <span className="text-sm font-medium text-slate-700">Rolle</span>
                            <select
                                value={form.data.role}
                                onChange={(event) => form.setData('role', event.target.value)}
                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            >
                                {roleOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            {form.errors.role ? <p className="text-sm text-rose-600">{form.errors.role}</p> : null}
                        </label>

                        <div className="space-y-2">
                            <span className="text-sm font-medium text-slate-700">Status</span>
                            <div className="flex min-h-11 items-center rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-600">
                                Aktiv ved opprettelse
                            </div>
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
                            {form.processing ? 'Lagrer...' : 'Opprett bruker'}
                        </button>
                    </div>
                </form>
            </div>
        </CustomerAppLayout>
    );
}
