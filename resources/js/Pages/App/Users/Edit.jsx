import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
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

export default function UsersEdit({
    redirectTo,
    user,
    bidRoleOptions,
    bidManagerScopeOptions,
    primaryAffiliationScopeOptions,
    departmentOptions,
    managedDepartmentOptions,
    canEditRole,
    canEditBidManagerScope,
}) {
    const page = usePage();
    const [toggling, setToggling] = useState(false);
    const form = useForm({
        name: user.name,
        bid_role: user.bid_role_value,
        bid_manager_scope: user.bid_manager_scope_value ?? (bidManagerScopeOptions[0]?.value ?? 'company'),
        primary_affiliation_scope: user.primary_affiliation_scope_value ?? (primaryAffiliationScopeOptions[0]?.value ?? 'company'),
        primary_department_id: user.primary_department_id ?? '',
        password: '',
        password_confirmation: '',
        department_ids: user.department_ids ?? [],
        managed_department_ids: user.managed_department_ids ?? [],
        redirect_to: redirectTo,
    });

    const isBidManager = form.data.bid_role === 'bid_manager';
    const isDepartmentScopedBidManager = isBidManager && form.data.bid_manager_scope === 'departments';
    const isDepartmentPrimaryAffiliation = form.data.primary_affiliation_scope === 'department';
    const primaryDepartmentOptions = departmentOptions.filter((option) => form.data.department_ids.includes(option.value));
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

        if (field !== 'department_ids') {
            return;
        }

        if (form.data.primary_affiliation_scope !== 'department') {
            return;
        }

        if (nextSelection.length === 0) {
            form.setData('primary_department_id', '');

            return;
        }

        if (!nextSelection.includes(Number(form.data.primary_department_id))) {
            form.setData('primary_department_id', nextSelection[0]);
        }
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

    const handlePrimaryAffiliationScopeChange = (value) => {
        form.setData('primary_affiliation_scope', value);

        if (value !== 'department') {
            form.setData('primary_department_id', '');

            return;
        }

        const firstDepartmentId = form.data.department_ids[0] ?? '';
        form.setData('primary_department_id', firstDepartmentId);
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
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Rediger bruker</h1>
                    <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                        Oppdater rolle, status og passord for brukeren innenfor din egen kunde.
                    </p>
                </section>

                <div className="mx-auto max-w-3xl">
                    <form
                        method="post"
                        action={user.update_url}
                        className="space-y-6 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:p-8"
                    >
                        {csrfToken ? <input type="hidden" name="_token" value={csrfToken} /> : null}
                        <input type="hidden" name="_method" value="put" />
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
                                    value={user.email}
                                    disabled
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-500 outline-none disabled:cursor-not-allowed"
                                />
                                <p className="text-xs text-slate-400">E-post redigeres ikke i denne fasen.</p>
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
                                        System Owner kan endre roller på alle brukere. Bid Manager kan bare administrere Contributor og Viewer innenfor sitt ansvarsområde.
                                    </p>
                                    {errors.bid_role ? <p className="text-sm text-rose-600">{errors.bid_role}</p> : null}
                                </label>
                            ) : (
                                <div className="space-y-2 md:col-span-2">
                                    <span className="text-sm font-medium text-slate-700">Rolle</span>
                                    <div className="flex min-h-11 items-center rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-700">
                                        {user.bid_role_label}
                                    </div>
                                    <p className="text-xs text-slate-400">
                                        Rollen kan ikke endres i denne konteksten.
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
                            ) : isBidManager ? (
                                <div className="space-y-2 md:col-span-2">
                                    <span className="text-sm font-medium text-slate-700">Administrativt ansvarsområde</span>
                                    <div className="flex min-h-11 items-center rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-700">
                                        {user.bid_manager_scope_label ?? 'Ikke satt'}
                                    </div>
                                    <p className="text-xs text-slate-400">
                                        Administrativt ansvarsområde kan bare endres av systemeier.
                                    </p>
                                </div>
                            ) : null}

                            <label className="space-y-2 md:col-span-1">
                                <span className="text-sm font-medium text-slate-700">Nytt passord</span>
                                <input
                                    type="password"
                                    name="password"
                                    value={form.data.password}
                                    onChange={(event) => form.setData('password', event.target.value)}
                                    autoComplete="new-password"
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                />
                                <p className="text-xs text-slate-400">La feltet stå tomt hvis passordet ikke skal endres.</p>
                                {errors.password ? <p className="text-sm text-rose-600">{errors.password}</p> : null}
                            </label>

                            <label className="space-y-2 md:col-span-1">
                                <span className="text-sm font-medium text-slate-700">Bekreft nytt passord</span>
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

                            {isDepartmentScopedBidManager && canEditBidManagerScope ? (
                                <DepartmentCheckboxGroup
                                    label="Bid-manager kan administrere disse avdelingene"
                                    fieldName="managed_department_ids"
                                    options={managedDepartmentOptions}
                                    selectedIds={form.data.managed_department_ids}
                                    onToggle={(departmentId) => toggleDepartmentSelection('managed_department_ids', departmentId)}
                                    helperText="Dette er administrativt ansvarsområde for bid-manager, ikke vanlig medlemskap. Velg én eller flere avdelinger."
                                    error={errors.managed_department_ids}
                                />
                            ) : isDepartmentScopedBidManager ? (
                                <div className="space-y-2 md:col-span-2">
                                    <span className="text-sm font-medium text-slate-700">Bid-manager kan administrere disse avdelingene</span>
                                    {user.managed_departments.length > 0 ? (
                                        <div className="flex flex-wrap gap-2">
                                            {user.managed_departments.map((department) => (
                                                <span
                                                    key={department.id}
                                                    className={
                                                        department.is_active
                                                            ? 'inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700'
                                                            : 'inline-flex rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700'
                                                    }
                                                >
                                                    {department.name}
                                                </span>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-500">
                                            Ingen avdelinger er valgt for administrativt ansvarsområde.
                                        </div>
                                    )}
                                    <p className="text-xs text-slate-400">
                                        Administrativt ansvarsområde kan bare endres av systemeier.
                                    </p>
                                </div>
                            ) : null}

                            <label className="space-y-2 md:col-span-2">
                                <span className="text-sm font-medium text-slate-700">Primær tilhørighet</span>
                                <select
                                    name="primary_affiliation_scope"
                                    value={form.data.primary_affiliation_scope}
                                    onChange={(event) => handlePrimaryAffiliationScopeChange(event.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                >
                                    {primaryAffiliationScopeOptions.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                <p className="text-xs text-slate-400">
                                    Primær tilhørighet styrer brukerens normale organisatoriske hjemsted. Dette er separat fra bid-manager sitt administrative ansvarsområde.
                                </p>
                                {errors.primary_affiliation_scope ? <p className="text-sm text-rose-600">{errors.primary_affiliation_scope}</p> : null}
                            </label>

                            {isDepartmentPrimaryAffiliation ? (
                                <label className="space-y-2 md:col-span-2">
                                    <span className="text-sm font-medium text-slate-700">Primær avdeling</span>
                                    <select
                                        name="primary_department_id"
                                        value={form.data.primary_department_id}
                                        onChange={(event) => form.setData('primary_department_id', event.target.value === '' ? '' : Number(event.target.value))}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    >
                                        <option value="">Velg primær avdeling</option>
                                        {primaryDepartmentOptions.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                    <p className="text-xs text-slate-400">
                                        Primær avdeling må være blant brukerens operative avdelinger.
                                    </p>
                                    {errors.primary_department_id ? <p className="text-sm text-rose-600">{errors.primary_department_id}</p> : null}
                                </label>
                            ) : null}

                            <div className="space-y-2 md:col-span-2">
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
                                {form.processing ? 'Lagrer...' : 'Lagre endringer'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </CustomerAppLayout>
    );
}
