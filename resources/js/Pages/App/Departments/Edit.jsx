import { Link, router, useForm } from '@inertiajs/react';
import { PRIMARY_COLOURS } from '../../../Support/actionStyles';
import { useState, useRef } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import FormButtonRow from '../../../Components/App/FormButtonRow';
import FormField from '../../../Components/App/FormField';
import { useFocusFirstError } from '../../../Support/focusManagement';

export default function DepartmentsEdit({ department }) {
    const [toggling, setToggling] = useState(false);
    const form = useForm({
        name: department.name,
        description: department.description || '',
    });

    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);

    const submit = (event) => {
        event.preventDefault();
        form.put(department.update_url, { onError: focusFirstError });
    };

    const toggleActive = () => {
        if (department.is_active && !window.confirm(`Er du sikker på at du vil deaktivere ${department.name}?`)) {
            return;
        }

        setToggling(true);

        router.patch(department.toggle_active_url, {}, {
            onFinish: () => setToggling(false),
        });
    };

    return (
        <CustomerAppLayout title="Rediger avdeling" showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Rediger avdeling</h1>
                    <p className="max-w-3xl text-[15px] leading-7 text-slate-600">
                        Oppdater navn og beskrivelse. Avdelingen brukes kun som organisasjonsstruktur i denne fasen.
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
                            <div className="flex min-h-11 items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-700">
                                <span>{department.is_active ? 'Aktiv' : 'Inaktiv'}</span>
                                <button
                                    type="button"
                                    onClick={toggleActive}
                                    disabled={toggling}
                                    className={
                                        department.is_active
                                            ? 'inline-flex min-h-9 items-center justify-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100 disabled:opacity-60'
                                            : 'inline-flex min-h-9 items-center justify-center rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100 disabled:opacity-60'
                                    }
                                >
                                    {department.is_active
                                        ? toggling
                                            ? 'Deaktiverer...'
                                            : 'Deaktiver'
                                        : toggling
                                            ? 'Aktiverer...'
                                            : 'Aktiver'}
                                </button>
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
                            {form.processing ? 'Lagrer...' : 'Lagre endringer'}
                        </button>
                    </FormButtonRow>
                </form>
                </div>
            </div>
        </CustomerAppLayout>
    );
}
