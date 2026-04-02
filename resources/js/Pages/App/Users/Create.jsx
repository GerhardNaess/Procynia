import { Link, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function DepartmentCheckboxGroup({ label, fieldName, options, selectedIds, onToggle, helperText, error }) {
    return (
        <div className="space-y-2 md:col-span-2">
            <span className="text-sm font-medium text-slate-700">{label}</span>
            {options.length > 0 ? (
                <div className="max-h-[280px] overflow-y-auto pr-1">
                    <div className="grid gap-2 sm:grid-cols-2">
                        {options.map((option) => {
                            const checked = selectedIds.includes(option.value);

                            return (
                                <label
                                    key={option.value}
                                    className={classNames(
                                        'flex cursor-pointer items-center gap-3 rounded-2xl border px-4 py-3 text-sm transition',
                                        checked
                                            ? 'border-violet-300 bg-violet-50 text-violet-900'
                                            : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50',
                                    )}
                                >
                                    <input
                                        type="checkbox"
                                        name={`${fieldName}[]`}
                                        value={option.value}
                                        checked={checked}
                                        onChange={() => onToggle(option.value)}
                                        className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                                    />
                                    <span>{option.label}</span>
                                </label>
                            );
                        })}
                    </div>
                </div>
            ) : (
                <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-500">
                    Ingen tilgjengelige avdelinger.
                </div>
            )}
            <p className="text-xs text-slate-400">{helperText}</p>
            {error ? <p className="text-sm text-rose-600">{error}</p> : null}
        </div>
    );
}

export default function UsersCreate({
    redirectTo,
    bidRoleOptions,
    bidManagerScopeOptions,
    departmentOptions,
    managedDepartmentOptions,
    canEditRole,
    canEditBidManagerScope,
}) {
    const page = usePage();
    const form = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        bid_role: 'contributor',
        bid_manager_scope: bidManagerScopeOptions[0]?.value ?? 'company',
        department_ids: [],
        managed_department_ids: [],
        redirect_to: redirectTo,
    });

    const isBidManager = form.data.bid_role === 'bid_manager';
    const isDepartmentScopedBidManager = isBidManager && form.data.bid_manager_scope === 'departments';
    const pageErrors = page.props.errors ?? {};
    const errors = Object.keys(form.errors).length > 0 ? form.errors : pageErrors;
    const firstError = Object.values(errors)[0] ?? null;
    const csrfToken = typeof document !== 'undefined'
        ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
        : '';

    const toggleDepartmentSelection = (field, departmentId) => {
        const numericId = Number(departmentId);
        const selectedIds = form.data[field] ?? [];
        const nextSelection = selectedIds.includes(numericId)
            ? selectedIds.filter((value) => value !== numericId)
            : [...selectedIds, numericId];

        form.setData(field, nextSelection);
    };

    const handleBidRoleChange = (value) => {
        form.setData('bid_role', value);

        if (value !== 'bid_manager') {
            form.setData('bid_manager_scope', bidManagerScopeOptions[0]?.value ?? 'company');
            form.setData('managed_department_ids', []);
        }
    };

    const handleBidManagerScopeChange = (value) => {
        form.setData('bid_manager_scope', value);

        if (value !== 'departments') {
            form.setData('managed_department_ids', []);
        }
    };

    return (
        <CustomerAppLayout title="Legg til bruker" showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Legg til bruker</h1>
                    <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                        Opprett en ny bruker for din kunde. Rollen styrer ansvarsnivået i kundemiljøet, og du setter passordet direkte ved opprettelse.
                    </p>
                </section>

                <div className="mx-auto max-w-3xl">
                    <form
                        method="post"
                        action="/app/users"
                        className="space-y-6 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:p-8"
                    >
                        {csrfToken ? <input type="hidden" name="_token" value={csrfToken} /> : null}
                        <input type="hidden" name="redirect_to" value={form.data.redirect_to} />

                        {firstError ? (
                            <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                                {firstError}
                            </div>
                        ) : null}

                        <div className="grid gap-5 md:grid-cols-2">
                            <label className="space-y-2 md:col-span-2">
                                <span className="text-sm font-medium text-slate-700">Navn</span>
                                <input
                                    type="text"
                                    name="name"
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                />
                                {errors.name ? <p className="text-sm text-rose-600">{errors.name}</p> : null}
                            </label>

                            <label className="space-y-2 md:col-span-2">
                                <span className="text-sm font-medium text-slate-700">E-post</span>
                                <input
                                    type="email"
                                    name="email"
                                    value={form.data.email}
                                    onChange={(event) => form.setData('email', event.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                />
                                {errors.email ? <p className="text-sm text-rose-600">{errors.email}</p> : null}
                            </label>

                            {canEditRole ? (
                                <label className="space-y-2 md:col-span-2">
                                    <span className="text-sm font-medium text-slate-700">Rolle</span>
                                    <select
                                        name="bid_role"
                                        value={form.data.bid_role}
                                        onChange={(event) => handleBidRoleChange(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    >
                                        {bidRoleOptions.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                    <p className="text-xs text-slate-400">
                                        System Owner har full kontroll over kundemiljøet. Bid Manager kan administrere brukere operativt innenfor sitt ansvarsområde.
                                    </p>
                                    {errors.bid_role ? <p className="text-sm text-rose-600">{errors.bid_role}</p> : null}
                                </label>
                            ) : (
                                <div className="space-y-2 md:col-span-2">
                                    <span className="text-sm font-medium text-slate-700">Rolle</span>
                                    <div className="flex min-h-11 items-center rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-700">
                                        Contributor
                                    </div>
                                    <p className="text-xs text-slate-400">
                                        Bid Manager kan opprette nye brukere som Contributor. Rollen kan bare settes av System Owner.
                                    </p>
                                </div>
                            )}

                            {form.data.bid_role === 'system_owner' ? (
                                <div className="space-y-2 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 md:col-span-2">
                                    <div className="font-semibold">System Owner</div>
                                    <p className="text-xs leading-5 text-amber-800">
                                        System Owner har full kontroll over brukere, roller, avdelingsstruktur og bid-managernes administrative ansvarsområde.
                                    </p>
                                </div>
                            ) : null}

                            {isBidManager && canEditBidManagerScope ? (
                                <label className="space-y-2 md:col-span-2">
                                    <span className="text-sm font-medium text-slate-700">Administrativt ansvarsområde</span>
                                    <select
                                        name="bid_manager_scope"
                                        value={form.data.bid_manager_scope}
                                        onChange={(event) => handleBidManagerScopeChange(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    >
                                        {bidManagerScopeOptions.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                    <p className="text-xs text-slate-400">
                                        Dette styrer hvilke avdelinger bid-manager kan administrere, og er separat fra hvilke avdelinger brukeren tilhører.
                                    </p>
                                    {errors.bid_manager_scope ? <p className="text-sm text-rose-600">{errors.bid_manager_scope}</p> : null}
                                </label>
                            ) : null}

                            <label className="space-y-2 md:col-span-1">
                                <span className="text-sm font-medium text-slate-700">Passord</span>
                                <input
                                    type="password"
                                    name="password"
                                    value={form.data.password}
                                    onChange={(event) => form.setData('password', event.target.value)}
                                    autoComplete="new-password"
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                />
                                <p className="text-xs text-slate-400">Minst 8 tegn.</p>
                                {errors.password ? <p className="text-sm text-rose-600">{errors.password}</p> : null}
                            </label>

                            <label className="space-y-2 md:col-span-1">
                                <span className="text-sm font-medium text-slate-700">Bekreft passord</span>
                                <input
                                    type="password"
                                    name="password_confirmation"
                                    value={form.data.password_confirmation}
                                    onChange={(event) => form.setData('password_confirmation', event.target.value)}
                                    autoComplete="new-password"
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                />
                            </label>

                            <DepartmentCheckboxGroup
                                label="Brukeren tilhører disse avdelingene"
                                fieldName="department_ids"
                                options={departmentOptions}
                                selectedIds={form.data.department_ids}
                                onToggle={(departmentId) => toggleDepartmentSelection('department_ids', departmentId)}
                                helperText="Brukes som vanlig avdelingstilknytning for brukeren. Velg én eller flere avdelinger."
                                error={errors.department_ids ?? errors.department_id}
                            />

                            {isDepartmentScopedBidManager ? (
                                <DepartmentCheckboxGroup
                                    label="Bid-manager kan administrere disse avdelingene"
                                    fieldName="managed_department_ids"
                                    options={managedDepartmentOptions}
                                    selectedIds={form.data.managed_department_ids}
                                    onToggle={(departmentId) => toggleDepartmentSelection('managed_department_ids', departmentId)}
                                    helperText="Dette er administrativt ansvarsområde for bid-manager, ikke vanlig medlemskap. Velg én eller flere avdelinger."
                                    error={errors.managed_department_ids}
                                />
                            ) : null}

                            <div className="space-y-2 md:col-span-2">
                                <span className="text-sm font-medium text-slate-700">Status</span>
                                <div className="flex min-h-11 items-center rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-600">
                                    Aktiv ved opprettelse
                                </div>
                            </div>
                        </div>

                        <div className="flex flex-col gap-3 sm:flex-row sm:justify-end">
                            <Link
                                href={redirectTo}
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
            </div>
        </CustomerAppLayout>
    );
}
