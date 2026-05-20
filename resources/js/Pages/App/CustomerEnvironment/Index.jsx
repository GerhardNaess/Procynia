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

function SectionTabs({ activeTab, onChange, showPermissions = false, showDocumentTemplates = false }) {
    const tabs = [
        { key: 'departments', label: 'Avdelinger' },
        { key: 'users', label: 'Brukere' },
        ...(showDocumentTemplates ? [{ key: 'document-templates', label: 'Dokumentmaler' }] : []),
        ...(showPermissions ? [{ key: 'permissions', label: 'Tilganger' }] : []),
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
    documentTemplates = [],
    bidRoleOptions,
    bidManagerScopeOptions,
    departmentOptions,
    managedDepartmentOptions,
    departmentFilterOptions,
    canCreateDepartments,
    canManageDocumentTemplates = false,
    permissionSettings = null,
    routes,
}) {
    const { locale = 'nb-NO' } = usePage().props;
    const [departmentModalState, setDepartmentModalState] = useState({ mode: null, department: null });
    const [documentTemplateModalState, setDocumentTemplateModalState] = useState({ mode: null, template: null });
    const [togglingDepartmentId, setTogglingDepartmentId] = useState(null);
    const [togglingDocumentTemplateId, setTogglingDocumentTemplateId] = useState(null);
    const [togglingUserId, setTogglingUserId] = useState(null);
    const [userSearch, setUserSearch] = useState('');
    const [userDepartmentFilter, setUserDepartmentFilter] = useState('');
    const [userStatusFilter, setUserStatusFilter] = useState('all');

    const validTabs = [
        'departments',
        'users',
        ...(canManageDocumentTemplates ? ['document-templates'] : []),
        ...(permissionSettings ? ['permissions'] : []),
    ];
    const currentTab = validTabs.includes(activeTab) ? activeTab : 'departments';
    const departmentsTabUrl = `${routes.index}?tab=departments`;
    const usersTabUrl = `${routes.index}?tab=users`;
    const documentTemplatesTabUrl = `${routes.index}?tab=document-templates`;
    const [permissionSaving, setPermissionSaving] = useState(null);
    const userCreateHref = `${routes.users_create}?redirect_to=${encodeURIComponent(usersTabUrl)}`;
    const userEditHref = (user) => `${user.edit_url}?redirect_to=${encodeURIComponent(usersTabUrl)}`;

    const departmentForm = useForm({
        name: '',
        description: '',
        redirect_to: departmentsTabUrl,
    });
    const createDocumentTemplateForm = useForm({
        name: '',
        description: '',
        file_path: null,
        is_active: true,
        is_default: false,
        redirect_to: documentTemplatesTabUrl,
    });
    const editDocumentTemplateForm = useForm({
        name: '',
        description: '',
        is_active: true,
        is_default: false,
        redirect_to: documentTemplatesTabUrl,
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

    const activeDefaultDocumentTemplate = documentTemplates.find((template) => template.is_active && template.is_default) ?? null;
    const activeDocumentTemplateForm = documentTemplateModalState.mode === 'edit'
        ? editDocumentTemplateForm
        : createDocumentTemplateForm;
    const firstDocumentTemplateError = Object.values(activeDocumentTemplateForm.errors)[0] ?? null;
    const resolveDocumentTemplateError = (message) => {
        if (!message) {
            return message;
        }

        return String(message).includes('PROCYNIA_CONTENT')
            ? 'Word-malen mangler innsettingspunktet der Procynia skal sette inn kravbesvarelsen. Se hjelpen for dokumentmaler.'
            : message;
    };

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

    const openCreateDocumentTemplate = () => {
        createDocumentTemplateForm.reset();
        createDocumentTemplateForm.clearErrors();
        createDocumentTemplateForm.setData({
            name: '',
            description: '',
            file_path: null,
            is_active: true,
            is_default: false,
            redirect_to: documentTemplatesTabUrl,
        });
        setDocumentTemplateModalState({ mode: 'create', template: null });
    };

    const openEditDocumentTemplate = (template) => {
        editDocumentTemplateForm.reset();
        editDocumentTemplateForm.clearErrors();
        editDocumentTemplateForm.setData({
            name: template.name ?? '',
            description: template.description ?? '',
            is_active: Boolean(template.is_active),
            is_default: Boolean(template.is_default),
            redirect_to: documentTemplatesTabUrl,
        });
        setDocumentTemplateModalState({ mode: 'edit', template });
    };

    const closeDocumentTemplateModal = () => {
        setDocumentTemplateModalState({ mode: null, template: null });
        createDocumentTemplateForm.reset();
        createDocumentTemplateForm.clearErrors();
        editDocumentTemplateForm.reset();
        editDocumentTemplateForm.clearErrors();
    };

    const submitDocumentTemplate = () => {
        if (documentTemplateModalState.mode === 'edit' && documentTemplateModalState.template) {
            editDocumentTemplateForm.patch(documentTemplateModalState.template.update_url, {
                preserveScroll: true,
                onSuccess: closeDocumentTemplateModal,
            });

            return;
        }

        createDocumentTemplateForm.post(routes.document_templates_store, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: closeDocumentTemplateModal,
        });
    };

    const handleDocumentTemplateSubmit = (event) => {
        event.preventDefault();
        submitDocumentTemplate();
    };

    const toggleDocumentTemplateActive = (template) => {
        const confirmMessage = template.is_active
            ? `Er du sikker på at du vil deaktivere ${template.name}?`
            : `Er du sikker på at du vil aktivere ${template.name}?`;

        if (!window.confirm(confirmMessage)) {
            return;
        }

        setTogglingDocumentTemplateId(template.id);

        router.patch(
            template.toggle_active_url,
            { redirect_to: documentTemplatesTabUrl },
            {
                preserveScroll: true,
                onFinish: () => setTogglingDocumentTemplateId(null),
            },
        );
    };

    const setDefaultDocumentTemplate = (template) => {
        const confirmMessage = `Er du sikker på at du vil sette ${template.name} som standardmal?`;

        if (!window.confirm(confirmMessage)) {
            return;
        }

        router.patch(
            template.set_default_url,
            { redirect_to: documentTemplatesTabUrl },
            {
                preserveScroll: true,
            },
        );
    };

    const deleteDocumentTemplate = (template) => {
        const confirmMessage = `Er du sikker på at du vil slette ${template.name}?`;

        if (!window.confirm(confirmMessage)) {
            return;
        }

        router.delete(template.destroy_url, {
            preserveScroll: true,
            onSuccess: closeDocumentTemplateModal,
        });
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
        closeDocumentTemplateModal();

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

                <SectionTabs
                    activeTab={currentTab}
                    onChange={changeTab}
                    showPermissions={permissionSettings !== null}
                    showDocumentTemplates={canManageDocumentTemplates}
                />

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
                ) : currentTab === 'users' ? (
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
                ) : null}

                {currentTab === 'document-templates' ? (
                    <section className="space-y-5">
                        <div className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div className="space-y-2">
                                    <h2 className="text-lg font-semibold text-slate-950">Dokumentmaler</h2>
                                    <p className="max-w-2xl text-sm leading-6 text-slate-500">
                                        Last opp og administrer kundespesifikke Word-maler som brukes ved eksport av kravbesvarelser.
                                    </p>
                                </div>

                                {canManageDocumentTemplates ? (
                                    <button
                                        type="button"
                                        onClick={openCreateDocumentTemplate}
                                        className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700"
                                    >
                                        Last opp dokumentmal
                                    </button>
                                ) : null}
                            </div>
                        </div>

                        <div className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <div className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                        Standardmal for Word-eksport
                                    </div>
                                    {activeDefaultDocumentTemplate ? (
                                        <>
                                            <div className="mt-2 text-sm font-semibold text-slate-950">
                                                {activeDefaultDocumentTemplate.name}
                                            </div>
                                            <p className="mt-2 text-sm leading-6 text-slate-600">
                                                Denne malen brukes ved eksport av kravbesvarelser.
                                            </p>
                                        </>
                                    ) : (
                                        <>
                                            <div className="mt-2 text-sm font-semibold text-slate-700">
                                                Ingen standardmal valgt
                                            </div>
                                            <p className="mt-2 text-sm leading-6 text-slate-600">
                                                Procynia bruker standardoppsettet inntil en aktiv standardmal er valgt.
                                            </p>
                                        </>
                                    )}
                                </div>
                            </div>
                        </div>

                        <section className="overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            {documentTemplates.length === 0 ? (
                                <div className="px-6 py-16 text-center">
                                    <div className="text-lg font-semibold text-slate-900">
                                        Ingen dokumentmaler er lastet opp
                                    </div>
                                    <p className="mt-2 text-sm text-slate-500">
                                        Last opp en Word-mal hvis kunden skal bruke egen mal ved eksport. Hvis ingen mal er valgt, bruker Procynia standardoppsettet.
                                    </p>
                                    {canManageDocumentTemplates ? (
                                        <div className="mt-6">
                                            <button
                                                type="button"
                                                onClick={openCreateDocumentTemplate}
                                                className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700"
                                            >
                                                Last opp dokumentmal
                                            </button>
                                        </div>
                                    ) : null}
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-slate-200">
                                        <thead className="bg-slate-50">
                                            <tr className="text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                <th className="px-6 py-4">Malnavn</th>
                                                <th className="px-6 py-4">Type</th>
                                                <th className="px-6 py-4">Status</th>
                                                <th className="px-6 py-4">Standardmal</th>
                                                <th className="px-6 py-4">Sist oppdatert</th>
                                                <th className="px-6 py-4">Handlinger</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {documentTemplates.map((template) => (
                                                <tr key={template.id} className="text-sm text-slate-700">
                                                    <td className="px-6 py-4">
                                                        <div className="font-medium text-slate-950">{template.name}</div>
                                                        <div className="mt-1 text-xs text-slate-400">
                                                            {template.original_filename || '—'}
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <span className="inline-flex rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold text-violet-700">
                                                            {template.template_type}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <span
                                                            className={
                                                                template.is_active
                                                                    ? 'inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700'
                                                                    : 'inline-flex rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700'
                                                            }
                                                        >
                                                            {template.is_active ? 'Aktiv' : 'Inaktiv'}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <span
                                                            className={
                                                                template.is_default
                                                                    ? 'inline-flex rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold text-violet-700'
                                                                    : 'inline-flex rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700'
                                                            }
                                                        >
                                                            {template.is_default ? 'Ja' : 'Nei'}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 text-slate-500">
                                                        {formatDate(template.updated_at, locale)}
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        {canManageDocumentTemplates ? (
                                                            <div className="flex flex-wrap gap-2">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => openEditDocumentTemplate(template)}
                                                                    className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                                >
                                                                    Rediger
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setDefaultDocumentTemplate(template)}
                                                                    className="inline-flex min-h-10 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-3 py-2 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                                                >
                                                                    Sett som standard
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    disabled={togglingDocumentTemplateId === template.id}
                                                                    onClick={() => toggleDocumentTemplateActive(template)}
                                                                    className={
                                                                        template.is_active
                                                                            ? 'inline-flex min-h-10 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100 disabled:opacity-60'
                                                                            : 'inline-flex min-h-10 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100 disabled:opacity-60'
                                                                    }
                                                                >
                                                                    {template.is_active
                                                                        ? togglingDocumentTemplateId === template.id
                                                                            ? 'Deaktiverer...'
                                                                            : 'Deaktiver'
                                                                        : togglingDocumentTemplateId === template.id
                                                                            ? 'Aktiverer...'
                                                                            : 'Aktiver'}
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => deleteDocumentTemplate(template)}
                                                                    className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                                >
                                                                    Slett
                                                                </button>
                                                            </div>
                                                        ) : (
                                                            <span className="text-xs font-medium text-slate-400">Kun interne brukere kan endre dokumentmaler</span>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>
                    </section>
                ) : null}

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

                <EnvironmentModal
                    isOpen={documentTemplateModalState.mode !== null}
                    title={documentTemplateModalState.mode === 'edit' ? 'Rediger dokumentmal' : 'Last opp dokumentmal'}
                    description="Dokumentmalen styrer layouten i Word-eksporten, men påvirker ikke AI-generering eller kildegrunnlag."
                    onClose={closeDocumentTemplateModal}
                    maxWidthClass="max-w-2xl"
                    footer={(
                        <div className="flex flex-col gap-3 sm:flex-row sm:justify-end">
                            <button
                                type="button"
                                onClick={closeDocumentTemplateModal}
                                className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                            >
                                Avbryt
                            </button>
                            <button
                                type="submit"
                                form="document-template-environment-form"
                                disabled={activeDocumentTemplateForm.processing}
                                className="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {activeDocumentTemplateForm.processing
                                    ? 'Lagrer...'
                                    : documentTemplateModalState.mode === 'edit'
                                        ? 'Lagre dokumentmal'
                                        : 'Last opp dokumentmal'}
                            </button>
                        </div>
                    )}
                >
                    <form id="document-template-environment-form" onSubmit={handleDocumentTemplateSubmit} className="space-y-6">
                        {firstDocumentTemplateError ? (
                            <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                                {resolveDocumentTemplateError(firstDocumentTemplateError)}
                            </div>
                        ) : null}

                        <label className="block space-y-2">
                            <span className="text-sm font-medium text-slate-700">Navn</span>
                            <input
                                type="text"
                                value={activeDocumentTemplateForm.data.name}
                                onChange={(event) => activeDocumentTemplateForm.setData('name', event.target.value)}
                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            />
                            {activeDocumentTemplateForm.errors.name ? <p className="text-sm text-rose-600">{activeDocumentTemplateForm.errors.name}</p> : null}
                        </label>

                        <label className="block space-y-2">
                            <span className="text-sm font-medium text-slate-700">Beskrivelse</span>
                            <textarea
                                rows={4}
                                value={activeDocumentTemplateForm.data.description}
                                onChange={(event) => activeDocumentTemplateForm.setData('description', event.target.value)}
                                className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                            />
                            {activeDocumentTemplateForm.errors.description ? <p className="text-sm text-rose-600">{activeDocumentTemplateForm.errors.description}</p> : null}
                        </label>

                        {documentTemplateModalState.mode === 'create' ? (
                            <label className="block space-y-2">
                                <span className="text-sm font-medium text-slate-700">Word-fil (.docx)</span>
                                <input
                                    type="file"
                                    accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                                    onChange={(event) => activeDocumentTemplateForm.setData('file_path', event.target.files?.[0] ?? null)}
                                    className="block w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-violet-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-violet-700"
                                />
                                {activeDocumentTemplateForm.errors.file_path ? (
                                    <p className="text-sm text-rose-600">
                                        {resolveDocumentTemplateError(activeDocumentTemplateForm.errors.file_path)}
                                    </p>
                                ) : null}
                                <p className="text-xs leading-5 text-slate-400">
                                    Velg en Word-fil i .docx-format. Se hjelpen for krav til innsettingspunkt.
                                </p>
                            </label>
                        ) : (
                            <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                                Word-filen kan ikke byttes i denne versjonen. Opprett en ny mal dersom filen skal erstattes.
                            </div>
                        )}

                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-4">
                                <input
                                    type="checkbox"
                                    checked={activeDocumentTemplateForm.data.is_active}
                                    onChange={(event) => activeDocumentTemplateForm.setData('is_active', event.target.checked)}
                                    className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                                />
                                <div className="space-y-0.5">
                                    <div className="text-sm font-semibold text-slate-900">
                                        Aktiv
                                    </div>
                                    <p className="text-xs leading-5 text-slate-500">
                                        Aktive maler kan velges og brukes i Word-eksporten.
                                    </p>
                                </div>
                            </label>

                            <label className="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-4">
                                <input
                                    type="checkbox"
                                    checked={activeDocumentTemplateForm.data.is_default}
                                    onChange={(event) => activeDocumentTemplateForm.setData('is_default', event.target.checked)}
                                    className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                                />
                                <div className="space-y-0.5">
                                    <div className="text-sm font-semibold text-slate-900">
                                        Standardmal
                                    </div>
                                    <p className="text-xs leading-5 text-slate-500">
                                        Standardmalen brukes automatisk i eksporten når den er aktiv.
                                    </p>
                                </div>
                            </label>
                        </div>
                    </form>
                </EnvironmentModal>

                {currentTab === 'permissions' && permissionSettings ? (
                    <section className="space-y-5">
                        <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <h2 className="text-lg font-semibold text-slate-950">Tilganger</h2>
                            <p className="mt-1 text-sm leading-6 text-slate-500">
                                Styr hvilke roller som har tilgang til å utføre spesifikke handlinger i kundemiljøet. System Owner har alltid full tilgang og kan ikke endres.
                            </p>

                            <div className="mt-6 overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-slate-200">
                                            <th className="pb-3 pr-6 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                Handling
                                            </th>
                                            {permissionSettings.role_columns.map((col) => (
                                                <th key={col.value} className="pb-3 px-4 text-center text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                    {col.label}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {permissionSettings.permission_rows.map((row) => (
                                            <tr key={row.key}>
                                                <td className="py-4 pr-6 font-medium text-slate-900">
                                                    {row.label}
                                                </td>
                                                {permissionSettings.role_columns.map((col) => {
                                                    const checked = row.roles.includes(col.value);
                                                    const locked = col.locked;

                                                    return (
                                                        <td key={col.value} className="px-4 py-4 text-center">
                                                            <input
                                                                type="checkbox"
                                                                checked={checked}
                                                                disabled={locked || permissionSaving === row.key}
                                                                onChange={() => {
                                                                    const nextRoles = checked
                                                                        ? row.roles.filter((r) => r !== col.value)
                                                                        : [...row.roles, col.value];

                                                                    setPermissionSaving(row.key);

                                                                    router.patch(
                                                                        permissionSettings.update_url,
                                                                        { permission: row.key, roles: nextRoles },
                                                                        {
                                                                            preserveScroll: true,
                                                                            preserveState: false,
                                                                            onFinish: () => setPermissionSaving(null),
                                                                        },
                                                                    );
                                                                }}
                                                                className="h-4 w-4 cursor-pointer rounded border-slate-300 text-violet-600 focus:ring-violet-300 disabled:cursor-not-allowed disabled:opacity-50"
                                                            />
                                                        </td>
                                                    );
                                                })}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>
                ) : null}

            </div>
        </CustomerAppLayout>
    );
}
