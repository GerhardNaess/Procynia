import { Link, useForm } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

export default function DepartmentsCreate() {
    const form = useForm({
        name: '',
        description: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post('/app/departments');
    };

    return (
        <CustomerAppLayout title="Legg til avdeling" showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Legg til avdeling</h1>
                    <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                        Opprett en avdeling som kan brukes til å organisere profiler og varsler senere.
                    </p>
                </section>

                <div className="mx-auto max-w-3xl">
                <form
                    onSubmit={submit}
                    className="space-y-6 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:p-8"
                >
                    <div className="grid gap-5">
                        <label className="space-y-2">
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
                            <span className="text-sm font-medium text-slate-700">Beskrivelse</span>
                            <textarea
                                value={form.data.description}
                                onChange={(event) => form.setData('description', event.target.value)}
                                rows={5}
                                className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            />
                            {form.errors.description ? <p className="text-sm text-rose-600">{form.errors.description}</p> : null}
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
                            href="/app/departments"
                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                        >
                            Tilbake
                        </Link>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {form.processing ? 'Lagrer...' : 'Opprett avdeling'}
                        </button>
                    </div>
                </form>
                </div>
            </div>
        </CustomerAppLayout>
    );
}
