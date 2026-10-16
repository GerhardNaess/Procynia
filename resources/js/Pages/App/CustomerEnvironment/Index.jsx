import { router, useForm, usePage } from '@inertiajs/react';
import { PRIMARY_COLOURS, SECONDARY_COLOURS, WARNING_COLOURS } from '../../../Support/actionStyles';
import { useEffect, useId, useRef, useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import ActionDialog, { DialogTitleBar } from '../../../Components/App/ActionDialog';
import FormField from '../../../Components/App/FormField';
import ScrollRegion from '../../../Components/App/ScrollRegion';
import { announce } from '../../../Support/announcer';
import { domSafeId } from '../../../Support/formFieldA11y';
import { useFocusFirstError } from '../../../Support/focusManagement';
import { rowErrorId, trackRowAction, useRowActionError } from '../../../Support/rowActionError';
import CustomerEnvironmentHeader from './CustomerEnvironmentHeader';
import CustomerRolesPanel from './CustomerRolesPanel';
import PermissionSection, { MATRIX } from './PermissionSection';
import { BASE_SECTION_KEY, allOpen, countLabel, sectionKeys, toggleSection } from './permissionSections';

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

const MODAL_SIZES = { 'max-w-xl': 'md', 'max-w-2xl': 'lg', 'max-w-3xl': 'xl' };

/**
 * The dialog for this page and its panels (avdeling, rolle, fagområde) — ActionDialog with this
 * page's own header and footer bands (A11Y-002): named by its title, described by its description,
 * focus kept inside and handed back on close, the page behind it inert.
 */
function EnvironmentModal({ isOpen, title, description, onClose, children, footer, maxWidthClass = 'max-w-2xl', closeDisabled = false }) {
    const { translations = {} } = usePage().props;
    const baseId = `environment-modal-${domSafeId(useId())}`;
    const titleId = `${baseId}-title`;
    const descriptionId = description ? `${baseId}-description` : undefined;

    return (
        <ActionDialog
            isOpen={isOpen}
            onClose={onClose}
            closeDisabled={closeDisabled}
            titleId={titleId}
            descriptionId={descriptionId}
            size={MODAL_SIZES[maxWidthClass] ?? 'lg'}
            padded={false}
        >
            <div className="flex max-h-[calc(100vh-2rem)] flex-col">
                <DialogTitleBar
                    titleId={titleId}
                    title={title}
                    descriptionId={descriptionId}
                    description={description}
                    onClose={onClose}
                    closeDisabled={closeDisabled}
                    closeLabel={translations.a11y?.close_dialog ?? 'Lukk dialog'}
                />
                <div className="min-h-0 flex-1 overflow-y-auto px-6 py-6">{children}</div>
                {footer ? <div className="shrink-0 border-t border-slate-200 bg-white px-6 py-5">{footer}</div> : null}
            </div>
        </ActionDialog>
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
                                        'flex cursor-pointer items-center gap-3 rounded-2xl border px-4 py-3 text-base transition',
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
                <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-5 text-base text-slate-600">
                    Ingen tilgjengelige avdelinger.
                </div>
            )}
            <p className="text-base leading-6 text-slate-600">
                {helperText}
            </p>
            {inactiveSelections.length > 0 ? (
                <div className="space-y-2">
                    <div className="text-base font-medium uppercase tracking-[0.12em] text-slate-600">Historiske inaktive avdelinger</div>
                    <div className="flex flex-wrap gap-2">
                        {inactiveSelections.map((department) => (
                            <span
                                key={department.id}
                                className="inline-flex items-center rounded-full bg-slate-200 px-3 py-1.5 text-base font-semibold leading-6 text-slate-700"
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
    permissionSettings = null,
    customerRoles = null,
    routes,
}) {
    const { locale = 'nb-NO', translations = {}, flash = {} } = usePage().props;
    const rowError = useRowActionError(flash?.error ?? null);
    const tce = translations?.customer_env ?? {};
    const a11y = translations?.a11y ?? {};
    const [departmentModalState, setDepartmentModalState] = useState({ mode: null, department: null });
    const [togglingDepartmentId, setTogglingDepartmentId] = useState(null);
    const [togglingUserId, setTogglingUserId] = useState(null);
    const [userSearch, setUserSearch] = useState('');
    const [userDepartmentFilter, setUserDepartmentFilter] = useState('');
    const [userStatusFilter, setUserStatusFilter] = useState('all');

    const validTabs = ['departments', 'users', ...(permissionSettings ? ['permissions'] : [])];
    const currentTab = validTabs.includes(activeTab) ? activeTab : 'departments';
    const departmentsTabUrl = `${routes.index}?tab=departments`;
    const usersTabUrl = `${routes.index}?tab=users`;
    const [permissionSaving, setPermissionSaving] = useState(null);
    // Tilganger opens with every module closed, so the first thing an administrator reads is the
    // list of modules. Opening one is page state only — it never touches a permission.
    const [openSections, setOpenSections] = useState([]);
    const tcs = tce.roles?.sections ?? {};
    const permissionSectionKeys = sectionKeys(customerRoles?.domains ?? [], permissionSettings !== null);
    const toggleOpenSection = (key) => setOpenSections((open) => toggleSection(open, key));
    const userCreateHref = `${routes.users_create}?redirect_to=${encodeURIComponent(usersTabUrl)}`;
    const userEditHref = (user) => `${user.edit_url}?redirect_to=${encodeURIComponent(usersTabUrl)}`;

    const departmentForm = useForm({
        name: '',
        description: '',
        redirect_to: departmentsTabUrl,
    });
    const departmentFormRef = useRef(null);
    const focusFirstDepartmentError = useFocusFirstError(departmentFormRef);

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

    // The table changes as the administrator types or picks a filter; the count is said once the
    // filters settle, not on every keystroke (4.1.3, A11Y-111). The visible table needs no copy.
    const filterKey = `${normalizedSearch}|${userDepartmentFilter}|${userStatusFilter}`;
    const filterKeyRef = useRef(filterKey);
    useEffect(() => {
        if (filterKeyRef.current === filterKey) {
            return undefined;
        }

        filterKeyRef.current = filterKey;
        const timer = window.setTimeout(() => {
            announce(filteredUsers.length === 0
                ? (a11y.results_none ?? 'Ingen treff')
                : (a11y.results_count ?? ':count treff').replace(':count', String(filteredUsers.length)));
        }, 600);

        return () => window.clearTimeout(timer);
    }, [filterKey, filteredUsers.length, a11y.results_none, a11y.results_count]);

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
                onError: focusFirstDepartmentError,
            });

            return;
        }

        departmentForm.post(routes.departments_store, {
            preserveScroll: true,
            onSuccess: closeDepartmentModal,
            onError: focusFirstDepartmentError,
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
            // A refusal («minst én aktiv systemeier») is shown beside this row's button, not only in
            // the banner at the top of the page (A11Y-137).
            trackRowAction(`user-toggle-${user.id}`, {
                preserveScroll: true,
                onFinish: () => setTogglingUserId(null),
            }),
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

    const baseSection = permissionSettings ? (
        <PermissionSection
            sectionKey={BASE_SECTION_KEY}
            title={tcs.base_title ?? 'Anbudsroller og kundemiljø'}
            subtitle={tcs.base_subtitle ?? 'Avdelinger, brukere, saker og Wiki-kontroll for de faste anbudsrollene. System Owner har alltid full tilgang og kan ikke endres.'}
            meta={countLabel(permissionSettings.permission_rows.length, { one: tcs.permissions_one, other: tcs.permissions_other ?? ':count rettigheter' })}
            open={openSections.includes(BASE_SECTION_KEY)}
            onToggle={() => toggleOpenSection(BASE_SECTION_KEY)}
        >
            <ScrollRegion
                label={(a11y.table_permissions ?? 'Rettigheter: :section').replace(':section', tcs.base_title ?? 'Anbudsroller og kundemiljø')}
                edgeRoom={false}
                className={MATRIX.wrapper}
            >
                <table className={MATRIX.table}>
                    <thead>
                        <tr className={MATRIX.headRow}>
                            <th scope="col" className={`${MATRIX.stickyHead} min-w-[18rem]`}>
                                Handling
                            </th>
                            {permissionSettings.role_columns.map((col) => (
                                <th key={col.value} scope="col" className={MATRIX.head}>
                                    {col.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className={MATRIX.body}>
                        {permissionSettings.permission_rows.map((row) => (
                            <tr key={row.key} className={MATRIX.row}>
                                <th scope="row" className={MATRIX.stickyCell}>
                                    <span className="font-medium">{row.label}</span>
                                    {/* Only the rows that are genuinely easy to
                                        confuse carry one; the rest read fine alone. */}
                                    {row.description ? (
                                        <span className="mt-0.5 block max-w-md text-base font-normal leading-6 text-slate-500">
                                            {row.description}
                                        </span>
                                    ) : null}
                                </th>
                                {permissionSettings.role_columns.map((col) => {
                                    const checked = row.roles.includes(col.value);
                                    const locked = col.locked;

                                    const saving = permissionSaving === row.key;

                                    return (
                                        <td key={col.value} className={MATRIX.cell}>
                                            {/* While the row saves the box is aria-disabled, not disabled: a
                                                disabled control drops focus to <body>, and the next Tab
                                                started at the top of the page (A11Y-029). */}
                                            <input
                                                type="checkbox"
                                                checked={checked}
                                                disabled={locked}
                                                aria-disabled={saving ? true : undefined}
                                                aria-label={(tcs.checkbox_label ?? ':permission – :role').replace(':permission', row.label).replace(':role', col.label)}
                                                onChange={() => {
                                                    if (saving) {
                                                        return;
                                                    }

                                                    const nextRoles = checked
                                                        ? row.roles.filter((r) => r !== col.value)
                                                        : [...row.roles, col.value];

                                                    setPermissionSaving(row.key);

                                                    router.patch(
                                                        permissionSettings.update_url,
                                                        { permission: row.key, roles: nextRoles },
                                                        {
                                                            preserveScroll: true,
                                                            // Keeps the open sections open; the
                                                            // props carry the saved roles.
                                                            preserveState: true,
                                                            onSuccess: () => announce(a11y.saved ?? 'Lagret'),
                                                            onError: () => announce(a11y.save_failed ?? 'Endringen ble ikke lagret.', { politeness: 'assertive' }),
                                                            onFinish: () => setPermissionSaving(null),
                                                        },
                                                    );
                                                }}
                                                className={MATRIX.checkbox}
                                            />
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </ScrollRegion>
        </PermissionSection>
    ) : null;

    return (
        <CustomerAppLayout title="Kundemiljø" showPageTitle={false}>
            <div className="space-y-7">
                <CustomerEnvironmentHeader activeTab={currentTab} onChangeTab={changeTab} showPermissions={permissionSettings !== null} />

                {currentTab === 'departments' ? (
                    <section className="space-y-5">
                        <div className="flex flex-col gap-3 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 className="text-lg font-semibold text-slate-950">Avdelinger</h2>
                                <p className="mt-1 text-base leading-6 text-slate-600">
                                    Opprett og vedlikehold aktive avdelinger som kan brukes for organisering av brukere og videre arbeid i kundemiljøet.
                                </p>
                            </div>
                            {canCreateDepartments ? (
                                <button
                                    type="button"
                                    onClick={openCreateDepartment}
                                    className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition ${PRIMARY_COLOURS}`}
                                >
                                    Opprett avdeling
                                </button>
                            ) : (
                                <div className="text-base text-slate-600">
                                    Kun systemeier kan opprette nye avdelinger.
                                </div>
                            )}
                        </div>

                        <section className="overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            {departments.length === 0 ? (
                                <div className="px-6 py-16 text-center">
                                    <div className="text-lg font-semibold text-slate-900">Ingen avdelinger ennå</div>
                                    <p className="mt-2 text-base text-slate-600">Opprett den første avdelingen for å strukturere kundemiljøet.</p>
                                </div>
                            ) : (
                                <ScrollRegion label={a11y.table_departments ?? 'Avdelinger'} edgeRoom={false}>
                                    <table className="min-w-full divide-y divide-slate-200">
                                        <caption className="sr-only">{a11y.table_departments ?? 'Avdelinger'}</caption>
                                        <thead className="bg-slate-50">
                                            <tr className="text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                <th scope="col" className="px-6 py-4">Navn</th>
                                                <th scope="col" className="px-6 py-4">Beskrivelse</th>
                                                <th scope="col" className="px-6 py-4">Brukere</th>
                                                <th scope="col" className="px-6 py-4">Status</th>
                                                <th scope="col" className="px-6 py-4">Oppdatert</th>
                                                <th scope="col" className="px-6 py-4">Handlinger</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {departments.map((department) => (
                                                <tr key={department.id} className="text-base text-slate-700">
                                                    <th scope="row" className="px-6 py-4 text-left font-medium text-slate-950">{department.name}</th>
                                                    <td className="px-6 py-4 text-slate-600">{department.description || '—'}</td>
                                                    <td className="px-6 py-4 text-slate-600">{department.user_count}</td>
                                                    <td className="px-6 py-4">
                                                        <span
                                                            className={
                                                                department.is_active
                                                                    ? 'inline-flex items-center rounded-full bg-emerald-100 px-3 py-1.5 text-base font-semibold leading-6 text-emerald-700'
                                                                    : 'inline-flex items-center rounded-full bg-slate-200 px-3 py-1.5 text-base font-semibold leading-6 text-slate-700'
                                                            }
                                                        >
                                                            {department.is_active ? 'Aktiv' : 'Inaktiv'}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 text-slate-600">{formatDate(department.updated_at, locale)}</td>
                                                    <td className="px-6 py-4">
                                                        <div className="flex flex-wrap gap-2">
                                                            {canCreateDepartments ? (
                                                                <>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => openEditDepartment(department)}
                                                                        aria-label={`Rediger ${department.name}`}
                                                                        className={`inline-flex min-h-10 items-center justify-center rounded-xl px-3 py-2 text-base font-semibold transition ${SECONDARY_COLOURS}`}
                                                                    >
                                                                        Rediger
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => toggleDepartmentActive(department)}
                                                                        disabled={togglingDepartmentId === department.id}
                                                                        aria-label={`${department.is_active ? 'Deaktiver' : 'Aktiver'} ${department.name}`}
                                                                        className={
                                                                            department.is_active
                                                                                ? `inline-flex min-h-10 items-center justify-center rounded-xl px-3 py-2 text-base font-semibold transition disabled:opacity- ${WARNING_COLOURS}`
                                                                                : `inline-flex min-h-10 items-center justify-center rounded-xl px-3 py-2 text-base font-semibold transition disabled:opacity- ${PRIMARY_COLOURS}`
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
                                                                <span className="text-base font-medium text-slate-600">
                                                                    Kun systemeier kan endre avdelinger
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </ScrollRegion>
                            )}
                        </section>
                    </section>
                ) : currentTab === 'users' ? (
                    <section className="space-y-5">
                        <div className="flex flex-col gap-4 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h2 className="text-lg font-semibold text-slate-950">Brukere</h2>
                                    <p className="mt-1 text-base leading-6 text-slate-600">
                                        Administrer brukere, bid-roller og avdelingstilknytning for eget kundemiljø.
                                    </p>
                                </div>
                                <a
                                    href={userCreateHref}
                                    className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition ${PRIMARY_COLOURS}`}
                                >
                                    Opprett bruker
                                </a>
                            </div>

                            <div className="grid gap-3 lg:grid-cols-[minmax(0,1.4fr)_220px_180px]">
                                <label className="space-y-2">
                                    <span className="text-base font-medium text-slate-700">Søk</span>
                                    <input
                                        type="text"
                                        value={userSearch}
                                        onChange={(event) => setUserSearch(event.target.value)}
                                        placeholder="Søk på navn eller e-post"
                                        className="h-11 w-full rounded-xl border border-field-border bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    />
                                </label>
                                <label className="space-y-2">
                                    <span className="text-base font-medium text-slate-700">Avdeling</span>
                                    <select
                                        value={userDepartmentFilter}
                                        onChange={(event) => setUserDepartmentFilter(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-field-border bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
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
                                    <span className="text-base font-medium text-slate-700">Status</span>
                                    <select
                                        value={userStatusFilter}
                                        onChange={(event) => setUserStatusFilter(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-field-border bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
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
                                    <p className="mt-2 text-base text-slate-600">
                                        Juster filtrene, eller opprett den første brukeren for kundemiljøet.
                                    </p>
                                </div>
                            ) : (
                                <ScrollRegion label={a11y.table_users ?? 'Brukere'} edgeRoom={false}>
                                    <table className="min-w-full divide-y divide-slate-200">
                                        <caption className="sr-only">{a11y.table_users ?? 'Brukere'}</caption>
                                        <thead className="bg-slate-50">
                                            <tr className="text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                <th scope="col" className="px-6 py-4">Navn</th>
                                                <th scope="col" className="px-6 py-4">E-post</th>
                                                <th scope="col" className="px-6 py-4">Rolle</th>
                                                <th scope="col" className="px-6 py-4">{tce.col_customer_roles ?? 'Egne roller'}</th>
                                                <th scope="col" className="px-6 py-4">Avdelinger</th>
                                                <th scope="col" className="px-6 py-4">Status</th>
                                                <th scope="col" className="px-6 py-4">Handlinger</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {filteredUsers.map((user) => (
                                                <tr key={user.id} className="text-base text-slate-700">
                                                    <th scope="row" className="px-6 py-4 text-left font-normal">
                                                        <div className="font-medium text-slate-950">{user.name}</div>
                                                        <div className="mt-1 text-base text-slate-600">
                                                            Opprettet {formatDate(user.created_at, locale)}
                                                        </div>
                                                    </th>
                                                    <td className="px-6 py-4 text-slate-600">{user.email}</td>
                                                    <td className="px-6 py-4">
                                                        <span
                                                            className={
                                                                user.bid_role_value === 'system_owner'
                                                                    ? 'inline-flex items-center rounded-full bg-amber-100 px-3 py-1.5 text-base font-semibold leading-6 text-amber-700'
                                                                    : 'inline-flex items-center rounded-full bg-sky-100 px-3 py-1.5 text-base font-semibold leading-6 text-sky-700'
                                                            }
                                                        >
                                                            {user.bid_role}
                                                        </span>
                                                        {user.is_qa ? (
                                                            <span className="ml-2 inline-flex items-center rounded-full bg-teal-100 px-3 py-1.5 text-base font-semibold leading-6 text-teal-700">
                                                                QA
                                                            </span>
                                                        ) : null}
                                                        {user.bid_manager_scope_summary ? (
                                                            <div className="mt-2 text-base font-medium text-slate-600">
                                                                {user.bid_manager_scope_summary}
                                                            </div>
                                                        ) : null}
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        {(user.customer_roles ?? []).length === 0 ? (
                                                            <span className="text-slate-600">
                                                                {tce.no_customer_roles ?? 'Ingen'}
                                                            </span>
                                                        ) : (
                                                            <div className="flex flex-wrap gap-2">
                                                                {user.customer_roles.map((role) => (
                                                                    <span
                                                                        key={role.id}
                                                                        className={
                                                                            role.is_active
                                                                                ? 'inline-flex items-center rounded-full bg-violet-100 px-3 py-1.5 text-base font-semibold leading-6 text-violet-700'
                                                                                : 'inline-flex items-center rounded-full bg-slate-200 px-3 py-1.5 text-base font-semibold leading-6 text-slate-700'
                                                                        }
                                                                    >
                                                                        {role.name}
                                                                    </span>
                                                                ))}
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <div className="space-y-3">
                                                            <div className="space-y-2">
                                                                <div className="text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                    Brukeren tilhører
                                                                </div>
                                                                {user.departments.length > 0 ? (
                                                                    <div className="flex flex-wrap gap-2">
                                                                        {user.departments.map((department) => (
                                                                            <span
                                                                                key={department.id}
                                                                                className={
                                                                                    department.is_active
                                                                                        ? 'inline-flex items-center rounded-full bg-violet-100 px-3 py-1.5 text-base font-semibold leading-6 text-violet-700'
                                                                                        : 'inline-flex items-center rounded-full bg-slate-200 px-3 py-1.5 text-base font-semibold leading-6 text-slate-700'
                                                                                }
                                                                            >
                                                                                {department.name}
                                                                            </span>
                                                                        ))}
                                                                    </div>
                                                                ) : (
                                                                    <span className="text-slate-600">Ingen avdeling</span>
                                                                )}
                                                            </div>

                                                            {user.bid_role_value === 'system_owner' ? (
                                                                <div className="space-y-2">
                                                                    <div className="text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                        Administrativt ansvarsområde
                                                                    </div>
                                                                    <span className="inline-flex items-center rounded-full bg-amber-100 px-3 py-1.5 text-base font-semibold leading-6 text-amber-700">
                                                                        Hele kundemiljøet
                                                                    </span>
                                                                </div>
                                                            ) : null}

                                                            {user.bid_role_value === 'bid_manager' ? (
                                                                <div className="space-y-2">
                                                                    <div className="text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                                        Administrativt ansvarsområde
                                                                    </div>
                                                                    {user.bid_manager_scope_value === 'company' ? (
                                                                        <span className="inline-flex items-center rounded-full bg-amber-100 px-3 py-1.5 text-base font-semibold leading-6 text-amber-700">
                                                                            Hele selskapet
                                                                        </span>
                                                                    ) : user.managed_departments.length > 0 ? (
                                                                        <div className="flex flex-wrap gap-2">
                                                                            {user.managed_departments.map((department) => (
                                                                                <span
                                                                                    key={department.id}
                                                                                    className={
                                                                                        department.is_active
                                                                                            ? 'inline-flex items-center rounded-full bg-amber-100 px-3 py-1.5 text-base font-semibold leading-6 text-amber-700'
                                                                                            : 'inline-flex items-center rounded-full bg-slate-200 px-3 py-1.5 text-base font-semibold leading-6 text-slate-700'
                                                                                    }
                                                                                >
                                                                                    {department.name}
                                                                                </span>
                                                                            ))}
                                                                        </div>
                                                                    ) : (
                                                                        <span className="text-slate-600">Ingen avdelinger</span>
                                                                    )}
                                                                </div>
                                                            ) : null}
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <span
                                                            className={
                                                                user.is_active
                                                                    ? 'inline-flex items-center rounded-full bg-emerald-100 px-3 py-1.5 text-base font-semibold leading-6 text-emerald-700'
                                                                    : 'inline-flex items-center rounded-full bg-slate-200 px-3 py-1.5 text-base font-semibold leading-6 text-slate-700'
                                                            }
                                                        >
                                                            {user.is_active ? 'Aktiv' : 'Inaktiv'}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <div className="flex flex-wrap gap-2">
                                                            <a
                                                                href={userEditHref(user)}
                                                                aria-label={`Rediger ${user.name}`}
                                                                className={`inline-flex min-h-10 items-center justify-center rounded-xl px-3 py-2 text-base font-semibold transition ${SECONDARY_COLOURS}`}
                                                            >
                                                                Rediger
                                                            </a>
                                                            <button
                                                                type="button"
                                                                disabled={!user.can_toggle_active || togglingUserId === user.id}
                                                                onClick={() => toggleUserActive(user)}
                                                                aria-label={user.is_active && user.is_self ? undefined : `${user.is_active ? 'Deaktiver' : 'Aktiver'} ${user.name}`}
                                                                aria-describedby={rowError(`user-toggle-${user.id}`) ? rowErrorId(`user-toggle-${user.id}`) : undefined}
                                                                className={
                                                                    user.can_toggle_active
                                                                        ? user.is_active
                                                                            ? `inline-flex min-h-10 items-center justify-center rounded-xl px-3 py-2 text-base font-semibold transition ${WARNING_COLOURS}`
                                                                            : `inline-flex min-h-10 items-center justify-center rounded-xl px-3 py-2 text-base font-semibold transition ${PRIMARY_COLOURS}`
                                                                        : 'inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-slate-100 px-3 py-2 text-base font-semibold text-slate-600'
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
                                                        {rowError(`user-toggle-${user.id}`) ? (
                                                            <p id={rowErrorId(`user-toggle-${user.id}`)} className="mt-2 max-w-xs text-base text-rose-700">
                                                                {rowError(`user-toggle-${user.id}`)}
                                                            </p>
                                                        ) : null}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </ScrollRegion>
                            )}
                        </section>
                    </section>
                ) : null}

                <EnvironmentModal
                    isOpen={departmentModalState.mode !== null}
                    title={departmentModalState.mode === 'edit' ? 'Rediger avdeling' : 'Opprett avdeling'}
                    description="Alle endringer gjelder kun deres eget kundemiljø."
                    onClose={closeDepartmentModal}
                    closeDisabled={departmentForm.processing}
                >
                    <form ref={departmentFormRef} id="department-environment-form" onSubmit={handleDepartmentSubmit} className="space-y-6">
                        {firstDepartmentError ? (
                            <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-base leading-6 text-rose-800">
                                {firstDepartmentError}
                            </div>
                        ) : null}

                        <FormField
                            id="department-environment-name"
                            label="Navn"
                            error={departmentForm.errors.name}
                            className="space-y-2"
                            labelClassName="text-base font-medium text-slate-700"
                            controlClassName=""
                            errorClassName="text-base text-rose-700"
                        >
                            {(field) => (
                                <input
                                    {...field}
                                    type="text"
                                    value={departmentForm.data.name}
                                    onChange={(event) => departmentForm.setData('name', event.target.value)}
                                    className="h-11 w-full rounded-xl border border-field-border bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                />
                            )}
                        </FormField>

                        <FormField
                            id="department-environment-description"
                            label="Beskrivelse"
                            error={departmentForm.errors.description}
                            className="space-y-2"
                            labelClassName="text-base font-medium text-slate-700"
                            controlClassName=""
                            errorClassName="text-base text-rose-700"
                        >
                            {(field) => (
                                <textarea
                                    {...field}
                                    rows={4}
                                    value={departmentForm.data.description}
                                    onChange={(event) => departmentForm.setData('description', event.target.value)}
                                    className="w-full rounded-2xl border border-field-border bg-white px-4 py-3 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                />
                            )}
                        </FormField>

                        <div className="sticky bottom-0 -mx-6 mt-6 border-t border-slate-200 bg-white/95 px-6 pt-5 backdrop-blur-sm">
                            <div className="flex flex-col gap-3 sm:flex-row sm:justify-end">
                                <button
                                    type="button"
                                    onClick={closeDepartmentModal}
                                    className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                >
                                    Avbryt
                                </button>
                                <button
                                    type="submit"
                                    disabled={departmentForm.processing}
                                    className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 ${PRIMARY_COLOURS}`}
                                >
                                    {departmentForm.processing ? 'Lagrer...' : departmentModalState.mode === 'edit' ? 'Lagre avdeling' : 'Opprett avdeling'}
                                </button>
                            </div>
                        </div>
                    </form>
                </EnvironmentModal>

                {currentTab === 'permissions' && permissionSettings ? (
                    <section className="space-y-5">
                        <div className="flex flex-col gap-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <h2 className="text-xl font-semibold text-slate-950">{tce.permissions_heading ?? 'Tilganger'}</h2>
                                <p className="mt-1 max-w-3xl text-base leading-6 text-slate-600">
                                    {tcs.intro ?? 'Styr hvilke roller som har tilgang til å utføre spesifikke handlinger i kundemiljøet. Åpne en modul for å se og endre rettighetene.'}
                                </p>
                            </div>
                            <div className="flex shrink-0 flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={() => setOpenSections(permissionSectionKeys)}
                                    disabled={allOpen(openSections, permissionSectionKeys)}
                                    className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2 text-base font-semibold transition disabled:cursor-not-allowed disabled:opacity-50 ${SECONDARY_COLOURS}`}
                                >
                                    {tcs.expand_all ?? 'Utvid alle'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setOpenSections([])}
                                    disabled={openSections.length === 0}
                                    className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2 text-base font-semibold transition disabled:cursor-not-allowed disabled:opacity-50 ${SECONDARY_COLOURS}`}
                                >
                                    {tcs.collapse_all ?? 'Lukk alle'}
                                </button>
                            </div>
                        </div>

                        {customerRoles ? (
                            // Customer-defined roles follow the fixed matrix, never inside it: the
                            // matrix is Procynia's anbud vocabulary, the domains are the customer's own.
                            <CustomerRolesPanel
                                customerRoles={customerRoles}
                                modal={EnvironmentModal}
                                t={tce.roles ?? {}}
                                openSections={openSections}
                                onToggleSection={toggleOpenSection}
                            >
                                {baseSection}
                            </CustomerRolesPanel>
                        ) : (
                            <div className="space-y-4">{baseSection}</div>
                        )}
                    </section>
                ) : null}

            </div>
        </CustomerAppLayout>
    );
}
