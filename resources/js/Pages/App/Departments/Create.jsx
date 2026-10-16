import { Link, useForm } from '@inertiajs/react';
import { PRIMARY_COLOURS } from '../../../Support/actionStyles';
import { useRef } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import FormButtonRow from '../../../Components/App/FormButtonRow';
import FormField from '../../../Components/App/FormField';
import { useFocusFirstError } from '../../../Support/focusManagement';

export default function DepartmentsCreate() {
    const form = useForm({
        name: '',
        description: '',
    });

    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);

    const submit = (event) => {
        event.preventDefault();
        form.post('/app/departments', { onError: focusFirstError });
    };

    return (
        <CustomerAppLayout title="Legg til avdeling" showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Legg til avdeling</h1>
                    <p className="max-w-3xl text-[15px] leading-7 text-slate-600">
                        Opprett en avdeling som kan brukes til å organisere profiler og varsler senere.
                    </p>
                </section>

                <div className="mx-auto max-w-3xl">
                <form
                    ref={formRef}
                    onSubmit={submit}
                    className="space-y-6 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:p-8"
                >
                    <div className="grid gap-5">
                        <FormField
                            id="department-name"
                            label="Navn"
                            error={form.errors.name}
                            className="space-y-2"
                            labelClassName="text-sm font-medium text-slate-700"
                            controlClassName=""
                            errorClassName="text-sm text-rose-700"
                        >
                            {(field) => (
                                <input
                                    {...field}
                                    type="text"
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                    className="h-11 w-full rounded-xl border border-field-border bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                />
                            )}
                        </FormField>

                        <FormField
                            id="department-description"
                            label="Beskrivelse"
                            error={form.errors.description}
                            className="space-y-2"
                            labelClassName="text-sm font-medium text-slate-700"
                            controlClassName=""
                            errorClassName="text-sm text-rose-700"
                        >
                            {(field) => (
                                <textarea
                                    {...field}
                                    value={form.data.description}
                                    onChange={(event) => form.setData('description', event.target.value)}
                                    rows={5}
                                    className="w-full rounded-xl border border-field-border bg-white px-4 py-3 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                />
                            )}
                        </FormField>

                        <div className="space-y-2">
                            <span className="text-sm font-medium text-slate-700">Status</span>
                            <div className="flex min-h-11 items-center rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-600">
                                Aktiv ved opprettelse
                            </div>
                        </div>
                    </div>

                    <FormButtonRow>
                        <Link
                            href="/app/departments"
                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                        >
                            Tilbake
                        </Link>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 ${PRIMARY_COLOURS}`}
                        >
                            {form.processing ? 'Lagrer...' : 'Opprett avdeling'}
                        </button>
                    </FormButtonRow>
                </form>
                </div>
            </div>
        </CustomerAppLayout>
    );
}
