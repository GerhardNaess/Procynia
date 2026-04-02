import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

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

function EnvironmentModal({ isOpen, title, description, onClose, children, footer, maxWidthClass = 'max-w-2xl' }) {
    if (!isOpen) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center overflow-hidden bg-slate-950/45 px-4 py-4">
            <div
                className={classNames(
                    'flex max-h-[90vh] w-full flex-col overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_20px_60px_rgba(15,23,42,0.18)]',
                    maxWidthClass,
                )}
            >
                <div className="shrink-0 flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
                    <div className="space-y-1">
                        <h2 className="text-xl font-semibold text-slate-950">{title}</h2>
                        {description ? <p className="text-sm leading-6 text-slate-500">{description}</p> : null}
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:border-slate-300 hover:text-slate-900"
                    >
                        ×
                    </button>
                </div>
                <div className="min-h-0 flex-1 overflow-y-auto px-6 py-6">{children}</div>
                {footer ? <div className="shrink-0 border-t border-slate-200 bg-white px-6 py-5">{footer}</div> : null}
            </div>
        </div>
    );
}

function SectionTabs({ activeTab, onChange }) {
    const tabs = [
        { key: 'departments', label: 'Avdelinger' },
        { key: 'users', label: 'Brukere' },
    ];

    return (
        <div className="inline-flex rounded-2xl border border-slate-200 bg-white p-1 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
            {tabs.map((tab) => {
                const isActive = activeTab === tab.key;

                return (
                    <button
                        key={tab.key}
                        type="button"
                        onClick={() => onChange(tab.key)}
                        className={classNames(
                            'rounded-xl px-4 py-2.5 text-sm font-semibold transition',
                            isActive
                                ? 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-200'
                                : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900',
                        )}
                    >
                        {tab.label}
                    </button>
                );
            })}
        </div>
    );
}

function DepartmentSelector({
    form,
    field,
    departmentOptions,
    inactiveSelections = [],
    helperText,
}) {
    const selectedIds = form.data[field] ?? [];

    const toggleDepartment = (departmentId) => {
        const numericId = Number(departmentId);
        const next = selectedIds.includes(numericId)
            ? selectedIds.filter((value) => value !== numericId)
            : [...selectedIds, numericId];

        form.setData(field, next);
    };

    return (
        <div className="space-y-3">
            {departmentOptions.length > 0 ? (
                <div className="max-h-[280px] overflow-y-auto pr-1">
                    <div className="grid gap-2 sm:grid-cols-2">
                        {departmentOptions.map((department) => {
                            const checked = selectedIds.includes(department.value);

                            return (
                                <label
                                    key={department.value}
                                    className={classNames(
                                        'flex cursor-pointer items-center gap-3 rounded-2xl border px-4 py-3 text-sm transition',
                                        checked
                                            ? 'border-violet-300 bg-violet-50 text-violet-900'
                                            : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50',
                                    )}
                                >
                                    <input
                                        type="checkbox"
                                        checked={checked}
                                        onChange={() => toggleDepartment(department.value)}
                                        className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                                    />
                                    <span>{department.label}</span>
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
            <p className="text-xs leading-5 text-slate-400">
                {helperText}
            </p>
            {inactiveSelections.length > 0 ? (
                <div className="space-y-2">
                    <div className="text-xs font-medium uppercase tracking-[0.12em] text-slate-400">Historiske inaktive avdelinger</div>
                    <div className="flex flex-wrap gap-2">
                        {inactiveSelections.map((department) => (
                            <span
                                key={department.id}
                                className="inline-flex rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700"
                            >
                                {department.name}
                            </span>
                        ))}
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function CollapsibleSection({ title, description, isOpen, onToggle, children }) {
    return (
        <section className="rounded-[24px] border border-slate-200 bg-slate-50/60">
            <button
                type="button"
                onClick={onToggle}
                className="flex w-full items-start justify-between gap-4 px-5 py-4 text-left"
            >
                <div className="space-y-1">
                    <div className="text-sm font-semibold text-slate-900">{title}</div>
                    {description ? <p className="text-xs leading-5 text-slate-500">{description}</p> : null}
                </div>
                <span className="shrink-0 rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-600">
                    {isOpen ? 'Skjul' : 'Vis'}
                </span>
            </button>
            {isOpen ? <div className="border-t border-slate-200 px-5 py-5">{children}</div> : null}
        </section>
    );
}

export default function CustomerEnvironmentIndex({
    activeTab,
    departments,
    users,
    bidRoleOptions,
    bidManagerScopeOptions,
    departmentOptions,
    managedDepartmentOptions,
    departmentFilterOptions,
    canCreateDepartments,
    routes,
}) {
    const { locale = 'nb-NO' } = usePage().props;
    const [departmentModalState, setDepartmentModalState] = useState({ mode: null, department: null });
    const [togglingDepartmentId, setTogglingDepartmentId] = useState(null);
    const [togglingUserId, setTogglingUserId] = useState(null);
    const [userSearch, setUserSearch] = useState('');
    const [userDepartmentFilter, setUserDepartmentFilter] = useState('');
    const [userStatusFilter, setUserStatusFilter] = useState('all');

    const currentTab = activeTab === 'users' ? 'users' : 'departments';
    const departmentsTabUrl = `${routes.index}?tab=departments`;
    const usersTabUrl = `${routes.index}?tab=users`;
    const userCreateHref = `${routes.users_create}?redirect_to=${encodeURIComponent(usersTabUrl)}`;
    const userEditHref = (user) => `${user.edit_url}?redirect_to=${encodeURIComponent(usersTabUrl)}`;

    const departmentForm = useForm({
        name: '',
        description: '',
        redirect_to: departmentsTabUrl,
    });

    const normalizedSearch = userSearch.trim().toLowerCase();
    const selectedDepartmentId = userDepartmentFilter === '' ? null : Number(userDepartmentFilter);

    const filteredUsers = users.filter((user) => {
        const matchesSearch = normalizedSearch === ''
            || user.name.toLowerCase().includes(normalizedSearch)
            || user.email.toLowerCase().includes(normalizedSearch);

        const matchesDepartment = selectedDepartmentId === null
            || [...(user.department_ids ?? []), ...(user.managed_department_ids ?? [])].includes(selectedDepartmentId);

        const matchesStatus = userStatusFilter === 'all'
            || (userStatusFilter === 'active' && user.is_active)
            || (userStatusFilter === 'inactive' && !user.is_active);

        return matchesSearch && matchesDepartment && matchesStatus;
    });

    const openCreateDepartment = () => {
        departmentForm.reset();
        departmentForm.clearErrors();
        departmentForm.setData({
            name: '',
            description: '',
            redirect_to: departmentsTabUrl,
        });
        setDepartmentModalState({ mode: 'create', department: null });
    };

    const openEditDepartment = (department) => {
        departmentForm.clearErrors();
        departmentForm.setData({
            name: department.name ?? '',
            description: department.description ?? '',
            redirect_to: departmentsTabUrl,
        });
        setDepartmentModalState({ mode: 'edit', department });
    };

    const closeDepartmentModal = () => {
        setDepartmentModalState({ mode: null, department: null });
        departmentForm.reset();
        departmentForm.clearErrors();
    };

    const submitDepartment = () => {
        if (departmentModalState.mode === 'edit' && departmentModalState.department) {
            departmentForm.put(departmentModalState.department.update_url, {
                preserveScroll: true,
                onSuccess: closeDepartmentModal,
            });

            return;
        }

        departmentForm.post(routes.departments_store, {
            preserveScroll: true,
            onSuccess: closeDepartmentModal,
        });
    };

    const handleDepartmentSubmit = (event) => {
        event.preventDefault();
        submitDepartment();
    };

    const toggleDepartmentActive = (department) => {
        const confirmMessage = department.is_active
            ? `Er du sikker på at du vil deaktivere ${department.name}?`
            : `Er du sikker på at du vil aktivere ${department.name}?`;

        if (!window.confirm(confirmMessage)) {
            return;
        }

        setTogglingDepartmentId(department.id);

        router.patch(
            department.toggle_active_url,
            { redirect_to: departmentsTabUrl },
            {
                preserveScroll: true,
                onFinish: () => setTogglingDepartmentId(null),
            },
        );
    };

    const toggleUserActive = (user) => {
        if (!user.can_toggle_active) {
            return;
        }

        const confirmMessage = user.is_active
            ? `Er du sikker på at du vil deaktivere ${user.name}?`
            : `Er du sikker på at du vil aktivere ${user.name}?`;

        if (!window.confirm(confirmMessage)) {
            return;
        }

        setTogglingUserId(user.id);

        router.patch(
            user.toggle_active_url,
            { redirect_to: usersTabUrl },
            {
                preserveScroll: true,
                onFinish: () => setTogglingUserId(null),
            },
        );
    };

    const changeTab = (tab) => {
        closeDepartmentModal();

        router.get(routes.index, { tab }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };
    const firstDepartmentError = Object.values(departmentForm.errors)[0] ?? null;

    return (
        <CustomerAppLayout title="Kundemiljø" showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Kundemiljø</h1>
                    <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                        Administrer avdelinger og brukere for deres virksomhet. All administrasjon er begrenset til eget kundemiljø.
                    </p>
                </section>

                <SectionTabs activeTab={currentTab} onChange={changeTab} />

                {currentTab === 'departments' ? (
                    <section className="space-y-5">
                        <div className="flex flex-col gap-3 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 className="text-lg font-semibold text-slate-950">Avdelinger</h2>
                                <p className="mt-1 text-sm leading-6 text-slate-500">
                                    Opprett og vedlikehold aktive avdelinger som kan brukes for organisering av brukere og videre arbeid i kundemiljøet.
                                </p>
                            </div>
                            {canCreateDepartments ? (
                                <button
                                    type="button"
                                    onClick={openCreateDepartment}
                                    className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700"
                                >
                                    Opprett avdeling
                                </button>
                            ) : (
                                <div className="text-sm text-slate-500">
                                    Kun systemeier kan opprette nye avdelinger.
                                </div>
                            )}
                        </div>

                        <section className="overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            {departments.length === 0 ? (
                                <div className="px-6 py-16 text-center">
                                    <div className="text-lg font-semibold text-slate-900">Ingen avdelinger ennå</div>
                                    <p className="mt-2 text-sm text-slate-500">Opprett den første avdelingen for å strukturere kundemiljøet.</p>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-slate-200">
                                        <thead className="bg-slate-50">
                                            <tr className="text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                <th className="px-6 py-4">Navn</th>
                                                <th className="px-6 py-4">Beskrivelse</th>
                                                <th className="px-6 py-4">Brukere</th>
                                                <th className="px-6 py-4">Status</th>
                                                <th className="px-6 py-4">Oppdatert</th>
                                                <th className="px-6 py-4">Handlinger</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {departments.map((department) => (
                                                <tr key={department.id} className="text-sm text-slate-700">
                                                    <td className="px-6 py-4 font-medium text-slate-950">{department.name}</td>
                                                    <td className="px-6 py-4 text-slate-500">{department.description || '—'}</td>
                                                    <td className="px-6 py-4 text-slate-500">{department.user_count}</td>
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
                                                    <td className="px-6 py-4 text-slate-500">{formatDate(department.updated_at, locale)}</td>
                                                    <td className="px-6 py-4">
                                                        <div className="flex flex-wrap gap-2">
                                                            {canCreateDepartments ? (
                                                                <>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => openEditDepartment(department)}
                                                                        className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                                    >
                                                                        Rediger
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => toggleDepartmentActive(department)}
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
                                                                </>
                                                            ) : (
                                                                <span className="text-xs font-medium text-slate-400">
                                                                    Kun systemeier kan endre avdelinger
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>
                    </section>
                ) : (
                    <section className="space-y-5">
                        <div className="flex flex-col gap-4 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h2 className="text-lg font-semibold text-slate-950">Brukere</h2>
                                    <p className="mt-1 text-sm leading-6 text-slate-500">
                                        Administrer brukere, bid-roller og avdelingstilknytning for eget kundemiljø.
                                    </p>
                                </div>
                                <a
                                    href={userCreateHref}
                                    className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700"
                                >
                                    Opprett bruker
                                </a>
                            </div>

                            <div className="grid gap-3 lg:grid-cols-[minmax(0,1.4fr)_220px_180px]">
                                <label className="space-y-2">
                                    <span className="text-sm font-medium text-slate-700">Søk</span>
                                    <input
                                        type="text"
                                        value={userSearch}
                                        onChange={(event) => setUserSearch(event.target.value)}
                                        placeholder="Søk på navn eller e-post"
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    />
                                </label>
                                <label className="space-y-2">
                                    <span className="text-sm font-medium text-slate-700">Avdeling</span>
                                    <select
                                        value={userDepartmentFilter}
                                        onChange={(event) => setUserDepartmentFilter(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    >
                                        <option value="">Alle avdelinger</option>
                                        {departmentFilterOptions.map((department) => (
                                            <option key={department.value} value={department.value}>
                                                {department.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="space-y-2">
                                    <span className="text-sm font-medium text-slate-700">Status</span>
                                    <select
                                        value={userStatusFilter}
                                        onChange={(event) => setUserStatusFilter(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    >
                                        <option value="all">Alle</option>
                                        <option value="active">Aktive</option>
                                        <option value="inactive">Inaktive</option>
                                    </select>
                                </label>
                            </div>
                        </div>

                        <section className="overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            {filteredUsers.length === 0 ? (
                                <div className="px-6 py-16 text-center">
                                    <div className="text-lg font-semibold text-slate-900">Ingen brukere i utvalget</div>
                                    <p className="mt-2 text-sm text-slate-500">
                                        Juster filtrene, eller opprett den første brukeren for kundemiljøet.
                                    </p>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-slate-200">
                                        <thead className="bg-slate-50">
                                            <tr className="text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                <th className="px-6 py-4">Navn</th>
                                                <th className="px-6 py-4">E-post</th>
                                                <th className="px-6 py-4">Rolle</th>
                                                <th className="px-6 py-4">Avdelinger</th>
                                                <th className="px-6 py-4">Status</th>
                                                <th className="px-6 py-4">Handlinger</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {filteredUsers.map((user) => (
                                                <tr key={user.id} className="text-sm text-slate-700">
                                                    <td className="px-6 py-4">
                                                        <div className="font-medium text-slate-950">{user.name}</div>
                                                        <div className="mt-1 text-xs text-slate-400">
                                                            Opprettet {formatDate(user.created_at, locale)}
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 text-slate-600">{user.email}</td>
                                                    <td className="px-6 py-4">
                                                        <span
                                                            className={
                                                                user.bid_role_value === 'system_owner'
                                                                    ? 'inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700'
                                                                    : 'inline-flex rounded-full bg-sky-100 px-3 py-1 text-xs font-semibold text-sky-700'
                                                            }
                                                        >
                                                            {user.bid_role}
                                                        </span>
                                                        {user.bid_manager_scope_summary ? (
                                                            <div className="mt-2 text-xs font-medium text-slate-500">
                                                                {user.bid_manager_scope_summary}
                                                            </div>
                                                        ) : null}
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <div className="space-y-3">
                                                            <div className="space-y-2">
                                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-400">
                                                                    Brukeren tilhører
                                                                </div>
                                                                {user.departments.length > 0 ? (
                                                                    <div className="flex flex-wrap gap-2">
                                                                        {user.departments.map((department) => (
                                                                            <span
                                                                                key={department.id}
                                                                                className={
                                                                                    department.is_active
                                                                                        ? 'inline-flex rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold text-violet-700'
                                                                                        : 'inline-flex rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700'
                                                                                }
                                                                            >
                                                                                {department.name}
                                                                            </span>
                                                                        ))}
                                                                    </div>
                                                                ) : (
                                                                    <span className="text-slate-400">Ingen avdeling</span>
                                                                )}
                                                            </div>

                                                            {user.bid_role_value === 'system_owner' ? (
                                                                <div className="space-y-2">
                                                                    <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-400">
                                                                        Administrativt ansvarsområde
                                                                    </div>
                                                                    <span className="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">
                                                                        Hele kundemiljøet
                                                                    </span>
                                                                </div>
                                                            ) : null}

                                                            {user.bid_role_value === 'bid_manager' ? (
                                                                <div className="space-y-2">
                                                                    <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-400">
                                                                        Administrativt ansvarsområde
                                                                    </div>
                                                                    {user.bid_manager_scope_value === 'company' ? (
                                                                        <span className="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">
                                                                            Hele selskapet
                                                                        </span>
                                                                    ) : user.managed_departments.length > 0 ? (
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
                                                                        <span className="text-slate-400">Ingen avdelinger</span>
                                                                    )}
                                                                </div>
                                                            ) : null}
                                                        </div>
                                                    </td>
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
                                                    <td className="px-6 py-4">
                                                        <div className="flex flex-wrap gap-2">
                                                            <a
                                                                href={userEditHref(user)}
                                                                className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                            >
                                                                Rediger
                                                            </a>
                                                            <button
                                                                type="button"
                                                                disabled={!user.can_toggle_active || togglingUserId === user.id}
                                                                onClick={() => toggleUserActive(user)}
                                                                className={
                                                                    user.can_toggle_active
                                                                        ? user.is_active
                                                                            ? 'inline-flex min-h-10 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100 disabled:opacity-60'
                                                                            : 'inline-flex min-h-10 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100 disabled:opacity-60'
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
                            )}
                        </section>
                    </section>
                )}

                <EnvironmentModal
                    isOpen={departmentModalState.mode !== null}
                    title={departmentModalState.mode === 'edit' ? 'Rediger avdeling' : 'Opprett avdeling'}
                    description="Alle endringer gjelder kun deres eget kundemiljø."
                    onClose={closeDepartmentModal}
                >
                    <form id="department-environment-form" onSubmit={handleDepartmentSubmit} className="space-y-6">
                        {firstDepartmentError ? (
                            <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                                {firstDepartmentError}
                            </div>
                        ) : null}

                        <label className="block space-y-2">
                            <span className="text-sm font-medium text-slate-700">Navn</span>
                            <input
                                type="text"
                                value={departmentForm.data.name}
                                onChange={(event) => departmentForm.setData('name', event.target.value)}
                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            />
                            {departmentForm.errors.name ? <p className="text-sm text-rose-600">{departmentForm.errors.name}</p> : null}
                        </label>

                        <label className="block space-y-2">
                            <span className="text-sm font-medium text-slate-700">Beskrivelse</span>
                            <textarea
                                rows={4}
                                value={departmentForm.data.description}
                                onChange={(event) => departmentForm.setData('description', event.target.value)}
                                className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            />
                            {departmentForm.errors.description ? <p className="text-sm text-rose-600">{departmentForm.errors.description}</p> : null}
                        </label>

                        <div className="sticky bottom-0 -mx-6 mt-6 border-t border-slate-200 bg-white/95 px-6 pt-5 backdrop-blur-sm">
                            <div className="flex flex-col gap-3 sm:flex-row sm:justify-end">
                                <button
                                    type="button"
                                    onClick={closeDepartmentModal}
                                    className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                >
                                    Avbryt
                                </button>
                                <button
                                    type="submit"
                                    disabled={departmentForm.processing}
                                    className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {departmentForm.processing ? 'Lagrer...' : departmentModalState.mode === 'edit' ? 'Lagre avdeling' : 'Opprett avdeling'}
                                </button>
                            </div>
                        </div>
                    </form>
                </EnvironmentModal>

            </div>
        </CustomerAppLayout>
    );
}
