import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { PRIMARY_COLOURS, SECONDARY_COLOURS, WARNING_COLOURS } from '../../../Support/actionStyles';
import { rolesInDomain } from './customerRoleMatrix';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

/**
 * Customer-defined roles, below the fixed bid-role matrix in Kundemiljø → Tilganger.
 *
 * One table per domain, because that is how an administrator reads it — "who may approve a Wiki
 * page" is a question about the Wiki, not about the role list. The same role appears in both
 * tables when it holds permissions in both, which is the point: the customer decides that their
 * «Kvalitetsdirektør» also publishes Wiki pages, and nothing in the model objects.
 *
 * A domain table lists only the roles that hold at least one permission in that domain. A
 * «Wiki-ansvarlig» with no quality permission is not an unanswered question under Kvalitet, it is
 * simply not part of that conversation — showing it as an empty row only adds noise. The filter is
 * presentation alone: the role list below shows every role, and the edit dialog always offers both
 * permission groups, so extending a role into the other domain stays one checkbox away.
 */
export default function CustomerRolesPanel({ customerRoles, users, modal: Modal, t = {} }) {
    const { domains = [], roles = [], store_url: storeUrl } = customerRoles;

    const [roleModal, setRoleModal] = useState({ mode: null, role: null });
    const [assignModal, setAssignModal] = useState({ user: null });
    const [savingRoleId, setSavingRoleId] = useState(null);

    const roleForm = useForm({
        name: '',
        description: '',
        is_active: true,
        permissions: [],
    });

    const assignForm = useForm({ role_ids: [] });

    const openCreateRole = () => {
        roleForm.clearErrors();
        roleForm.setData({ name: '', description: '', is_active: true, permissions: [] });
        setRoleModal({ mode: 'create', role: null });
    };

    const openEditRole = (role) => {
        roleForm.clearErrors();
        roleForm.setData({
            name: role.name,
            description: role.description ?? '',
            is_active: role.is_active,
            permissions: [...role.permission_keys],
        });
        setRoleModal({ mode: 'edit', role });
    };

    const closeRoleModal = () => {
        setRoleModal({ mode: null, role: null });
        roleForm.clearErrors();
    };

    const submitRole = (event) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => closeRoleModal() };

        if (roleModal.mode === 'edit' && roleModal.role) {
            roleForm.patch(roleModal.role.update_url, options);

            return;
        }

        roleForm.post(storeUrl, options);
    };

    // A single checkbox in a domain table sends only the permission set. The role's name and
    // active state are not on screen here, so they must not be part of what this write asserts.
    const togglePermission = (role, permissionKey) => {
        const next = role.permission_keys.includes(permissionKey)
            ? role.permission_keys.filter((key) => key !== permissionKey)
            : [...role.permission_keys, permissionKey];

        setSavingRoleId(role.id);

        router.patch(
            role.update_url,
            { permissions: next },
            {
                preserveScroll: true,
                preserveState: false,
                onFinish: () => setSavingRoleId(null),
            },
        );
    };

    const toggleRoleActive = (role) => {
        setSavingRoleId(role.id);

        router.patch(
            role.update_url,
            { is_active: !role.is_active },
            {
                preserveScroll: true,
                preserveState: false,
                onFinish: () => setSavingRoleId(null),
            },
        );
    };

    const deleteRole = (role) => {
        if (!window.confirm(t.delete_confirm ?? 'Slett rollen?')) {
            return;
        }

        router.delete(role.delete_url, { preserveScroll: true, preserveState: false });
    };

    const openAssign = (user) => {
        assignForm.clearErrors();
        assignForm.setData('role_ids', [...(user.customer_role_ids ?? [])]);
        setAssignModal({ user });
    };

    const submitAssignment = (event) => {
        event.preventDefault();

        if (!assignModal.user) {
            return;
        }

        assignForm.patch(assignModal.user.roles_update_url, {
            preserveScroll: true,
            onSuccess: () => setAssignModal({ user: null }),
        });
    };

    const toggleFormPermission = (permissionKey) => {
        const current = roleForm.data.permissions ?? [];

        roleForm.setData(
            'permissions',
            current.includes(permissionKey)
                ? current.filter((key) => key !== permissionKey)
                : [...current, permissionKey],
        );
    };

    const toggleAssignedRole = (roleId) => {
        const current = assignForm.data.role_ids ?? [];

        assignForm.setData(
            'role_ids',
            current.includes(roleId) ? current.filter((id) => id !== roleId) : [...current, roleId],
        );
    };

    return (
        <>
            <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-950">{t.heading ?? 'Egne roller'}</h2>
                        <p className="mt-1 max-w-3xl text-base leading-6 text-slate-600">
                            {t.subtitle
                                ?? 'Dere bestemmer navnet, Procynia bestemmer hva en rolle kan inneholde.'}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={openCreateRole}
                        className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition ${PRIMARY_COLOURS}`}
                    >
                        {t.create ?? 'Ny rolle'}
                    </button>
                </div>

                {roles.length === 0 ? (
                    <div className="mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center">
                        <p className="text-base font-semibold text-slate-900">{t.no_roles_title ?? 'Ingen egne roller ennå'}</p>
                        <p className="mt-1 text-base leading-6 text-slate-600">{t.no_roles_hint ?? ''}</p>
                    </div>
                ) : (
                    <div className="mt-6 space-y-8">
                        {domains.map((domain) => {
                            const domainRoles = rolesInDomain(roles, domain);

                            return (
                                <div key={domain.key}>
                                    <h3 className="text-base font-semibold text-slate-900">{domain.label}</h3>
                                    {domainRoles.length === 0 ? (
                                        <p className="mt-3 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-base leading-6 text-slate-600">
                                            {t.domain_no_roles ?? 'Ingen av rollene har rettigheter her ennå. Rediger en rolle for å gi den rettigheter i dette området.'}
                                        </p>
                                    ) : (
                                        <div className="mt-3 overflow-x-auto">
                                            <table className="w-full text-base">
                                                <thead>
                                                    <tr className="border-b border-slate-200">
                                                        <th className="pb-3 pr-6 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                            {t.col_role ?? 'Rolle'}
                                                        </th>
                                                        {domain.permissions.map((permission) => (
                                                            <th
                                                                key={permission.key}
                                                                className="px-3 pb-3 text-center text-sm font-semibold leading-5 text-slate-600"
                                                            >
                                                                {permission.label}
                                                            </th>
                                                        ))}
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-100">
                                                    {domainRoles.map((role) => (
                                                        <tr key={role.id}>
                                                            <td className="py-4 pr-6 text-slate-900">
                                                                <span className="font-medium">{role.name}</span>
                                                                {!role.is_active ? (
                                                                    <span className="ml-2 inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-sm font-semibold text-slate-600">
                                                                        {t.inactive ?? 'Inaktiv'}
                                                                    </span>
                                                                ) : null}
                                                            </td>
                                                            {domain.permissions.map((permission) => {
                                                                const checked = role.permission_keys.includes(permission.key);

                                                                return (
                                                                    <td key={permission.key} className="px-3 py-4 text-center">
                                                                        <input
                                                                            type="checkbox"
                                                                            checked={checked}
                                                                            disabled={savingRoleId === role.id}
                                                                            onChange={() => togglePermission(role, permission.key)}
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
                                    )}
                                </div>
                            );
                        })}

                        <div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-base">
                                    <thead>
                                        <tr className="border-b border-slate-200">
                                            <th className="pb-3 pr-6 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                {t.col_role ?? 'Rolle'}
                                            </th>
                                            <th className="px-4 pb-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                {t.col_users ?? 'Brukere'}
                                            </th>
                                            <th className="px-4 pb-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                {t.col_status ?? 'Status'}
                                            </th>
                                            <th className="pb-3 pl-4 text-right text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                {t.col_actions ?? 'Handlinger'}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {roles.map((role) => (
                                            <tr key={role.id}>
                                                <td className="py-4 pr-6 text-slate-900">
                                                    <span className="font-medium">{role.name}</span>
                                                    {role.description ? (
                                                        <span className="mt-0.5 block max-w-md text-base font-normal leading-6 text-slate-500">
                                                            {role.description}
                                                        </span>
                                                    ) : null}
                                                </td>
                                                <td className="px-4 py-4 text-slate-600">{role.user_count}</td>
                                                <td className="px-4 py-4">
                                                    <span
                                                        className={classNames(
                                                            'inline-flex items-center rounded-full px-2.5 py-0.5 text-sm font-semibold',
                                                            role.is_active
                                                                ? 'bg-emerald-50 text-emerald-700'
                                                                : 'bg-slate-100 text-slate-600',
                                                        )}
                                                    >
                                                        {role.is_active ? (t.active ?? 'Aktiv') : (t.inactive ?? 'Inaktiv')}
                                                    </span>
                                                </td>
                                                <td className="py-4 pl-4 text-right">
                                                    <div className="inline-flex flex-wrap justify-end gap-2">
                                                        <button
                                                            type="button"
                                                            onClick={() => openEditRole(role)}
                                                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-3 py-2 text-base font-semibold transition ${SECONDARY_COLOURS}`}
                                                        >
                                                            {t.edit ?? 'Rediger'}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            disabled={savingRoleId === role.id}
                                                            onClick={() => toggleRoleActive(role)}
                                                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-3 py-2 text-base font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 ${SECONDARY_COLOURS}`}
                                                        >
                                                            {role.is_active ? (t.deactivate ?? 'Deaktiver') : (t.activate ?? 'Aktiver')}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => deleteRole(role)}
                                                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-3 py-2 text-base font-semibold transition ${WARNING_COLOURS}`}
                                                        >
                                                            {t.delete ?? 'Slett'}
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                <h2 className="text-lg font-semibold text-slate-950">{t.assignment_heading ?? 'Tildeling av roller'}</h2>
                <p className="mt-1 max-w-3xl text-base leading-6 text-slate-600">
                    {t.assignment_subtitle ?? ''}
                </p>

                <div className="mt-6 overflow-x-auto">
                    <table className="w-full text-base">
                        <thead>
                            <tr className="border-b border-slate-200">
                                <th className="pb-3 pr-6 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                    {t.col_user ?? 'Bruker'}
                                </th>
                                <th className="px-4 pb-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                    {t.col_bid_role ?? 'Anbudsrolle'}
                                </th>
                                <th className="px-4 pb-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                    {t.col_customer_roles ?? 'Egne roller'}
                                </th>
                                <th className="pb-3 pl-4 text-right text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                    {t.col_actions ?? 'Handlinger'}
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {users.map((user) => (
                                <tr key={user.id}>
                                    <td className="py-4 pr-6 text-slate-900">
                                        <span className="font-medium">{user.name}</span>
                                        <span className="mt-0.5 block text-base font-normal leading-6 text-slate-500">
                                            {user.email}
                                        </span>
                                    </td>
                                    <td className="px-4 py-4 text-slate-600">{user.bid_role}</td>
                                    <td className="px-4 py-4">
                                        {(user.customer_roles ?? []).length === 0 ? (
                                            <span className="text-slate-500">{t.no_roles_assigned ?? 'Ingen'}</span>
                                        ) : (
                                            <div className="flex flex-wrap gap-1.5">
                                                {user.customer_roles.map((role) => (
                                                    <span
                                                        key={role.id}
                                                        className={classNames(
                                                            'inline-flex items-center rounded-full px-2.5 py-0.5 text-sm font-semibold',
                                                            role.is_active
                                                                ? 'bg-violet-50 text-violet-700'
                                                                : 'bg-slate-100 text-slate-500',
                                                        )}
                                                    >
                                                        {role.name}
                                                    </span>
                                                ))}
                                            </div>
                                        )}
                                    </td>
                                    <td className="py-4 pl-4 text-right">
                                        <button
                                            type="button"
                                            onClick={() => openAssign(user)}
                                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-3 py-2 text-base font-semibold transition ${SECONDARY_COLOURS}`}
                                        >
                                            {t.assign ?? 'Endre roller'}
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Modal
                isOpen={roleModal.mode !== null}
                title={roleModal.mode === 'edit' ? (t.modal_edit_title ?? 'Rediger rolle') : (t.modal_create_title ?? 'Ny rolle')}
                description={t.modal_description ?? ''}
                onClose={closeRoleModal}
            >
                <form onSubmit={submitRole} className="space-y-5">
                    <div>
                        <label htmlFor="customer-role-name" className="block text-base font-semibold text-slate-900">
                            {t.field_name ?? 'Rollenavn'}
                        </label>
                        <input
                            id="customer-role-name"
                            type="text"
                            value={roleForm.data.name}
                            onChange={(event) => roleForm.setData('name', event.target.value)}
                            placeholder={t.field_name_placeholder ?? ''}
                            className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-2.5 text-base text-slate-900 focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-200"
                        />
                        {roleForm.errors.name ? (
                            <p className="mt-1.5 text-base text-rose-600">{roleForm.errors.name}</p>
                        ) : null}
                    </div>

                    <div>
                        <label htmlFor="customer-role-description" className="block text-base font-semibold text-slate-900">
                            {t.field_description ?? 'Beskrivelse'}
                        </label>
                        <textarea
                            id="customer-role-description"
                            rows={3}
                            value={roleForm.data.description}
                            onChange={(event) => roleForm.setData('description', event.target.value)}
                            className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-2.5 text-base text-slate-900 focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-200"
                        />
                        {roleForm.errors.description ? (
                            <p className="mt-1.5 text-base text-rose-600">{roleForm.errors.description}</p>
                        ) : null}
                    </div>

                    <label className="flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 px-4 py-3">
                        <input
                            type="checkbox"
                            checked={roleForm.data.is_active}
                            onChange={(event) => roleForm.setData('is_active', event.target.checked)}
                            className="mt-1 h-4 w-4 cursor-pointer rounded border-slate-300 text-violet-600 focus:ring-violet-300"
                        />
                        <span>
                            <span className="block text-base font-semibold text-slate-900">{t.field_active ?? 'Rollen er aktiv'}</span>
                            <span className="block text-base leading-6 text-slate-600">{t.field_active_hint ?? ''}</span>
                        </span>
                    </label>

                    <div className="space-y-4">
                        <p className="text-base font-semibold text-slate-900">{t.field_permissions ?? 'Rettigheter'}</p>
                        {domains.map((domain) => (
                            <div key={domain.key} className="rounded-2xl border border-slate-200 p-4">
                                <p className="text-base font-semibold text-slate-800">{domain.label}</p>
                                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                    {domain.permissions.map((permission) => {
                                        const checked = (roleForm.data.permissions ?? []).includes(permission.key);

                                        return (
                                            <label
                                                key={permission.key}
                                                className={classNames(
                                                    'flex cursor-pointer items-center gap-3 rounded-xl border px-3 py-2.5 text-base transition',
                                                    checked
                                                        ? 'border-violet-300 bg-violet-50 text-violet-900'
                                                        : 'border-slate-200 text-slate-700 hover:border-slate-300',
                                                )}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={checked}
                                                    onChange={() => toggleFormPermission(permission.key)}
                                                    className="h-4 w-4 cursor-pointer rounded border-slate-300 text-violet-600 focus:ring-violet-300"
                                                />
                                                {permission.label}
                                            </label>
                                        );
                                    })}
                                </div>
                            </div>
                        ))}
                    </div>

                    <div className="flex flex-wrap justify-end gap-3">
                        <button
                            type="button"
                            onClick={closeRoleModal}
                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition ${SECONDARY_COLOURS}`}
                        >
                            {t.cancel ?? 'Avbryt'}
                        </button>
                        <button
                            type="submit"
                            disabled={roleForm.processing}
                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 ${PRIMARY_COLOURS}`}
                        >
                            {roleForm.processing ? (t.saving ?? 'Lagrer...') : (t.save ?? 'Lagre rolle')}
                        </button>
                    </div>
                </form>
            </Modal>

            <Modal
                isOpen={assignModal.user !== null}
                title={(t.modal_assign_title ?? 'Roller for :name').replace(':name', assignModal.user?.name ?? '')}
                description={t.modal_assign_description ?? ''}
                onClose={() => setAssignModal({ user: null })}
            >
                <form onSubmit={submitAssignment} className="space-y-5">
                    {roles.length === 0 ? (
                        <p className="text-base leading-6 text-slate-600">{t.no_roles_to_assign ?? ''}</p>
                    ) : (
                        <div className="grid gap-2 sm:grid-cols-2">
                            {roles.map((role) => {
                                const checked = (assignForm.data.role_ids ?? []).includes(role.id);

                                return (
                                    <label
                                        key={role.id}
                                        className={classNames(
                                            'flex cursor-pointer items-center gap-3 rounded-xl border px-3 py-2.5 text-base transition',
                                            checked
                                                ? 'border-violet-300 bg-violet-50 text-violet-900'
                                                : 'border-slate-200 text-slate-700 hover:border-slate-300',
                                        )}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={checked}
                                            onChange={() => toggleAssignedRole(role.id)}
                                            className="h-4 w-4 cursor-pointer rounded border-slate-300 text-violet-600 focus:ring-violet-300"
                                        />
                                        <span>
                                            {role.name}
                                            {!role.is_active ? (
                                                <span className="ml-2 text-sm font-semibold text-slate-500">
                                                    ({t.inactive ?? 'Inaktiv'})
                                                </span>
                                            ) : null}
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                    )}

                    <div className="flex flex-wrap justify-end gap-3">
                        <button
                            type="button"
                            onClick={() => setAssignModal({ user: null })}
                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition ${SECONDARY_COLOURS}`}
                        >
                            {t.cancel ?? 'Avbryt'}
                        </button>
                        <button
                            type="submit"
                            disabled={assignForm.processing || roles.length === 0}
                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 ${PRIMARY_COLOURS}`}
                        >
                            {assignForm.processing ? (t.saving ?? 'Lagrer...') : (t.save ?? 'Lagre')}
                        </button>
                    </div>
                </form>
            </Modal>
        </>
    );
}
