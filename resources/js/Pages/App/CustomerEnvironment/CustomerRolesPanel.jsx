import { router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { PRIMARY_COLOURS, SECONDARY_COLOURS, WARNING_COLOURS } from '../../../Support/actionStyles';
import ScrollRegion from '../../../Components/App/ScrollRegion';
import { announce } from '../../../Support/announcer';
import { fieldA11y } from '../../../Support/formFieldA11y';
import { useFocusFirstError } from '../../../Support/focusManagement';
import { rolesInDomain } from './customerRoleMatrix';
import BusinessAreasPanel from './BusinessAreasPanel';
import PermissionSection, { MATRIX } from './PermissionSection';
import { countLabel } from './permissionSections';

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
 *
 * Where a role's rights apply is its Fagområder: «Alle» (every area, also ones created later) or
 * a chosen set. It is one field on the role, shown as a column only in the domains that are scoped
 * by fagområde today (Risiko), so an administrator reads «what may this role do, and where» on
 * one row without learning a separate access mechanism.
 *
 * Each domain is one collapsible PermissionSection. Which sections are open is the page's state
 * (Index owns it, so «Utvid alle» also reaches the bid-role section passed in as `children`);
 * opening or closing one never touches a role.
 *
 * This panel defines roles; it does not hand them out. Assignment lives on Rediger bruker, where
 * the rest of a person's identity is set, so an administrator answers "what is this person" in one
 * place and on one save.
 */
export default function CustomerRolesPanel({ customerRoles, modal: Modal, t = {}, openSections = [], onToggleSection = () => {}, children = null }) {
    const {
        domains = [],
        roles = [],
        store_url: storeUrl,
        business_areas: businessAreas = [],
        business_areas_store_url: businessAreasStoreUrl,
        area_scoped_domains: areaScopedDomains = [],
        explicit_grant_domains: explicitGrantDomains = [],
    } = customerRoles;
    const tba = t.business_areas ?? {};
    const ts = t.sections ?? {};
    const { translations = {} } = usePage().props;
    const a11y = translations.a11y ?? {};
    // A matrix checkbox has no visible label of its own, so it names both its row and its column.
    const checkboxLabel = (permissionLabel, roleName) =>
        (ts.checkbox_label ?? ':permission – :role').replace(':permission', permissionLabel).replace(':role', roleName);
    const isAreaScoped = (domain) => areaScopedDomains.includes(domain.key);
    const areaSummary = (role) => {
        if (role.all_business_areas) {
            return t.areas_all ?? 'Alle';
        }

        const names = businessAreas
            .filter((area) => (role.business_area_ids ?? []).includes(area.id))
            .map((area) => area.name);

        return names.length > 0 ? names.join(', ') : (t.areas_none ?? 'Ingen');
    };

    const [roleModal, setRoleModal] = useState({ mode: null, role: null });
    const [savingRoleId, setSavingRoleId] = useState(null);

    const roleForm = useForm({
        name: '',
        description: '',
        is_active: true,
        permissions: [],
        all_business_areas: false,
        business_area_ids: [],
    });
    const roleFormRef = useRef(null);
    const focusFirstRoleError = useFocusFirstError(roleFormRef);
    const nameField = fieldA11y({ id: 'customer-role-name', hasError: Boolean(roleForm.errors.name) });
    const descriptionField = fieldA11y({ id: 'customer-role-description', hasError: Boolean(roleForm.errors.description) });

    const openCreateRole = () => {
        roleForm.clearErrors();
        roleForm.setData({ name: '', description: '', is_active: true, permissions: [], all_business_areas: false, business_area_ids: [] });
        setRoleModal({ mode: 'create', role: null });
    };

    const openEditRole = (role) => {
        roleForm.clearErrors();
        roleForm.setData({
            name: role.name,
            description: role.description ?? '',
            is_active: role.is_active,
            permissions: [...role.permission_keys],
            all_business_areas: Boolean(role.all_business_areas),
            business_area_ids: [...(role.business_area_ids ?? [])],
        });
        setRoleModal({ mode: 'edit', role });
    };

    const closeRoleModal = () => {
        setRoleModal({ mode: null, role: null });
        roleForm.clearErrors();
    };

    const submitRole = (event) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => closeRoleModal(), onError: focusFirstRoleError };

        if (roleModal.mode === 'edit' && roleModal.role) {
            roleForm.patch(roleModal.role.update_url, options);

            return;
        }

        roleForm.post(storeUrl, options);
    };

    // A single checkbox in a domain table sends only the permission set. The role's name and
    // active state are not on screen here, so they must not be part of what this write asserts.
    const togglePermission = (role, permissionKey) => {
        // The box stays focusable while its row saves (aria-disabled, A11Y-029); a change then is ignored.
        if (savingRoleId === role.id) {
            return;
        }

        const next = role.permission_keys.includes(permissionKey)
            ? role.permission_keys.filter((key) => key !== permissionKey)
            : [...role.permission_keys, permissionKey];

        setSavingRoleId(role.id);

        router.patch(
            role.update_url,
            { permissions: next },
            {
                preserveScroll: true,
                // The props carry the saved state; keeping the page's own state is what keeps the
                // open sections open after a click.
                preserveState: true,
                onSuccess: () => announce(a11y.saved ?? 'Lagret'),
                onError: () => announce(a11y.save_failed ?? 'Endringen ble ikke lagret.', { politeness: 'assertive' }),
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
                preserveState: true,
                onFinish: () => setSavingRoleId(null),
            },
        );
    };

    const deleteRole = (role) => {
        if (!window.confirm(t.delete_confirm ?? 'Slett rollen?')) {
            return;
        }

        router.delete(role.delete_url, { preserveScroll: true, preserveState: true });
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

    const toggleFormArea = (areaId) => {
        const current = roleForm.data.business_area_ids ?? [];

        roleForm.setData(
            'business_area_ids',
            current.includes(areaId)
                ? current.filter((id) => id !== areaId)
                : [...current, areaId],
        );
    };

    return (
        <>
            <div className="space-y-4">
                {children}
                {domains.map((domain) => {
                    const domainRoles = rolesInDomain(roles, domain);

                    return (
                        <PermissionSection
                            key={domain.key}
                            sectionKey={domain.key}
                            title={domain.label}
                            subtitle={ts.descriptions?.[domain.key] ?? null}
                            meta={[
                                countLabel(domain.permissions.length, { one: ts.permissions_one, other: ts.permissions_other ?? ':count rettigheter' }),
                                countLabel(domainRoles.length, { one: ts.roles_one, other: ts.roles_other ?? ':count roller' }),
                            ].join(' · ')}
                            open={openSections.includes(domain.key)}
                            onToggle={() => onToggleSection(domain.key)}
                        >
                            {explicitGrantDomains.includes(domain.key) ? (
                                <p className="mb-4 text-base leading-6 text-slate-600" data-testid={`explicit-grant-note-${domain.key}`}>
                                    {t.explicit_grant_note ?? 'System Owner får ikke disse rettighetene automatisk. Skal du arbeide her, gi deg selv en rolle med rettighetene.'}
                                </p>
                            ) : null}
                            {domainRoles.length === 0 ? (
                                <p className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-base leading-6 text-slate-600">
                                    {t.domain_no_roles ?? 'Ingen av rollene har rettigheter her ennå. Rediger en rolle for å gi den rettigheter i dette området.'}
                                </p>
                            ) : (
                                <ScrollRegion
                                    label={(a11y.table_permissions ?? 'Rettigheter: :section').replace(':section', domain.label)}
                                    edgeRoom={false}
                                    className={MATRIX.wrapper}
                                >
                                    <table className={MATRIX.table}>
                                        <thead>
                                            <tr className={MATRIX.headRow}>
                                                <th scope="col" className={`${MATRIX.stickyHead} min-w-[12rem]`}>
                                                    {t.col_role ?? 'Rolle'}
                                                </th>
                                                {domain.permissions.map((permission) => (
                                                    <th key={permission.key} scope="col" className={MATRIX.head}>
                                                        {permission.label}
                                                    </th>
                                                ))}
                                                {isAreaScoped(domain) ? (
                                                    <th scope="col" className={MATRIX.headLeft}>
                                                        {t.col_areas ?? 'Fagområder'}
                                                    </th>
                                                ) : null}
                                            </tr>
                                        </thead>
                                        <tbody className={MATRIX.body}>
                                            {domainRoles.map((role) => (
                                                <tr key={role.id} className={MATRIX.row}>
                                                    <th scope="row" className={MATRIX.stickyCell}>
                                                        <span className="font-medium">{role.name}</span>
                                                        {!role.is_active ? (
                                                            <span className="ml-2 inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-base font-semibold text-slate-600">
                                                                {t.inactive ?? 'Inaktiv'}
                                                            </span>
                                                        ) : null}
                                                    </th>
                                                    {domain.permissions.map((permission) => {
                                                        const checked = role.permission_keys.includes(permission.key);

                                                        return (
                                                            <td key={permission.key} className={MATRIX.cell}>
                                                                <input
                                                                    type="checkbox"
                                                                    checked={checked}
                                                                    aria-disabled={savingRoleId === role.id ? true : undefined}
                                                                    onChange={() => togglePermission(role, permission.key)}
                                                                    aria-label={checkboxLabel(permission.label, role.name)}
                                                                    className={MATRIX.checkbox}
                                                                />
                                                            </td>
                                                        );
                                                    })}
                                                    {isAreaScoped(domain) ? (
                                                        <td className="px-3 py-3.5 text-left align-middle text-base text-slate-700">
                                                            <button
                                                                type="button"
                                                                onClick={() => openEditRole(role)}
                                                                title={t.areas_edit ?? 'Endre fagområder'}
                                                                aria-label={`${areaSummary(role)} – ${t.areas_edit ?? 'Endre fagområder'}: ${role.name}`}
                                                                className="rounded-lg text-left underline decoration-slate-300 underline-offset-4 hover:text-violet-700 hover:decoration-violet-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-300"
                                                            >
                                                                {areaSummary(role)}
                                                            </button>
                                                        </td>
                                                    ) : null}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </ScrollRegion>
                            )}
                        </PermissionSection>
                    );
                })}
            </div>

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
                    <div className="mt-6">
                        <div>
                            <ScrollRegion label={a11y.table_roles ?? 'Egne roller'}>
                                <table className="w-full text-base">
                                    <caption className="sr-only">{t.heading ?? 'Egne roller'}</caption>
                                    <thead>
                                        <tr className="border-b border-slate-200">
                                            <th scope="col" className="pb-3 pr-6 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                {t.col_role ?? 'Rolle'}
                                            </th>
                                            <th scope="col" className="px-4 pb-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                {t.col_users ?? 'Brukere'}
                                            </th>
                                            <th scope="col" className="px-4 pb-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                {t.col_status ?? 'Status'}
                                            </th>
                                            <th scope="col" className="pb-3 pl-4 text-right text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                {t.col_actions ?? 'Handlinger'}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {roles.map((role) => (
                                            <tr key={role.id}>
                                                <th scope="row" className="py-4 pr-6 text-left font-normal text-slate-900">
                                                    <span className="font-medium">{role.name}</span>
                                                    {role.description ? (
                                                        <span className="mt-0.5 block max-w-md text-base font-normal leading-6 text-slate-600">
                                                            {role.description}
                                                        </span>
                                                    ) : null}
                                                </th>
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
                                                            aria-label={`${t.edit ?? 'Rediger'} ${role.name}`}
                                                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-3 py-2 text-base font-semibold transition ${SECONDARY_COLOURS}`}
                                                        >
                                                            {t.edit ?? 'Rediger'}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            disabled={savingRoleId === role.id}
                                                            onClick={() => toggleRoleActive(role)}
                                                            aria-label={`${role.is_active ? (t.deactivate ?? 'Deaktiver') : (t.activate ?? 'Aktiver')} ${role.name}`}
                                                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-3 py-2 text-base font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 ${SECONDARY_COLOURS}`}
                                                        >
                                                            {role.is_active ? (t.deactivate ?? 'Deaktiver') : (t.activate ?? 'Aktiver')}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => deleteRole(role)}
                                                            aria-label={`${t.delete ?? 'Slett'} ${role.name}`}
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
                            </ScrollRegion>
                        </div>
                    </div>
                )}
            </div>

            <BusinessAreasPanel
                areas={businessAreas}
                roles={roles}
                storeUrl={businessAreasStoreUrl}
                modal={Modal}
                t={tba}
                tAll={t.areas_all ?? 'Alle'}
            />

            <Modal
                isOpen={roleModal.mode !== null}
                title={roleModal.mode === 'edit' ? (t.modal_edit_title ?? 'Rediger rolle') : (t.modal_create_title ?? 'Ny rolle')}
                description={t.modal_description ?? ''}
                onClose={closeRoleModal}
            >
                <form ref={roleFormRef} onSubmit={submitRole} className="space-y-5">
                    <div>
                        <label htmlFor="customer-role-name" className="block text-base font-semibold text-slate-900">
                            {t.field_name ?? 'Rollenavn'}
                        </label>
                        <input
                            {...nameField.controlProps}
                            type="text"
                            value={roleForm.data.name}
                            onChange={(event) => roleForm.setData('name', event.target.value)}
                            placeholder={t.field_name_placeholder ?? ''}
                            className="mt-2 w-full rounded-xl border border-field-border px-4 py-2.5 text-base text-slate-900 focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-200"
                        />
                        {roleForm.errors.name ? (
                            <p id={nameField.errorId} className="mt-1.5 text-base text-rose-700">{roleForm.errors.name}</p>
                        ) : null}
                    </div>

                    <div>
                        <label htmlFor="customer-role-description" className="block text-base font-semibold text-slate-900">
                            {t.field_description ?? 'Beskrivelse'}
                        </label>
                        <textarea
                            {...descriptionField.controlProps}
                            rows={3}
                            value={roleForm.data.description}
                            onChange={(event) => roleForm.setData('description', event.target.value)}
                            className="mt-2 w-full rounded-xl border border-field-border px-4 py-2.5 text-base text-slate-900 focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-200"
                        />
                        {roleForm.errors.description ? (
                            <p id={descriptionField.errorId} className="mt-1.5 text-base text-rose-700">{roleForm.errors.description}</p>
                        ) : null}
                    </div>

                    {/* The title names the box and the hint describes it, instead of both being read as
                        one long name. The whole card still toggles it. */}
                    <label className="grid cursor-pointer grid-cols-[auto_minmax(0,1fr)] items-start gap-x-3 rounded-2xl border border-slate-200 px-4 py-3">
                        <input
                            type="checkbox"
                            checked={roleForm.data.is_active}
                            onChange={(event) => roleForm.setData('is_active', event.target.checked)}
                            aria-labelledby="customer-role-active-title"
                            aria-describedby={t.field_active_hint ? 'customer-role-active-hint' : undefined}
                            className="row-span-2 mt-1 h-4 w-4 cursor-pointer rounded border-slate-300 text-violet-600 focus:ring-violet-300"
                        />
                        <span id="customer-role-active-title" className="block text-base font-semibold text-slate-900">{t.field_active ?? 'Rollen er aktiv'}</span>
                        {t.field_active_hint ? (
                            <span id="customer-role-active-hint" className="block text-base leading-6 text-slate-600">{t.field_active_hint}</span>
                        ) : null}
                    </label>

                    {/* One question per group, so a screen reader entering a checkbox hears which module
                        it belongs to (1.3.1, A11Y-120). */}
                    <fieldset className="space-y-4">
                        <legend className="text-base font-semibold text-slate-900">{t.field_permissions ?? 'Rettigheter'}</legend>
                        {roleForm.errors.permissions ? (
                            <p className="text-base text-rose-700">{roleForm.errors.permissions}</p>
                        ) : null}
                        {domains.map((domain) => (
                            <fieldset key={domain.key} className="rounded-2xl border border-slate-200 p-4">
                                <legend className="px-1 text-base font-semibold text-slate-800">{domain.label}</legend>
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
                            </fieldset>
                        ))}
                    </fieldset>

                    <fieldset className="rounded-2xl border border-slate-200 p-4">
                        <legend className="px-1 text-base font-semibold text-slate-900">{t.field_areas ?? 'Fagområder'}</legend>
                        <p className="text-base leading-6 text-slate-600">
                            {t.field_areas_hint ?? 'Fagområder brukes til å bestemme hvilke deler av virksomheten en rolle kan se og arbeide med.'}
                        </p>
                        <div className="mt-3 space-y-2">
                            <label
                                className={classNames(
                                    'grid cursor-pointer grid-cols-[auto_minmax(0,1fr)] items-start gap-x-3 rounded-xl border px-3 py-2.5 text-base transition',
                                    roleForm.data.all_business_areas
                                        ? 'border-violet-300 bg-violet-50 text-violet-900'
                                        : 'border-slate-200 text-slate-700 hover:border-slate-300',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="customer-role-areas"
                                    checked={roleForm.data.all_business_areas}
                                    onChange={() => roleForm.setData('all_business_areas', true)}
                                    aria-labelledby="customer-role-areas-all-title"
                                    aria-describedby="customer-role-areas-all-hint"
                                    className="row-span-2 mt-1 h-4 w-4 cursor-pointer border-slate-300 text-violet-600 focus:ring-violet-300"
                                />
                                <span id="customer-role-areas-all-title" className="block font-semibold">{t.areas_all ?? 'Alle'}</span>
                                <span id="customer-role-areas-all-hint" className="block leading-6 text-slate-600">
                                    {t.areas_all_hint ?? 'Også fagområder som opprettes senere.'}
                                </span>
                            </label>
                            <label
                                className={classNames(
                                    'flex cursor-pointer items-start gap-3 rounded-xl border px-3 py-2.5 text-base transition',
                                    !roleForm.data.all_business_areas
                                        ? 'border-violet-300 bg-violet-50 text-violet-900'
                                        : 'border-slate-200 text-slate-700 hover:border-slate-300',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="customer-role-areas"
                                    checked={!roleForm.data.all_business_areas}
                                    onChange={() => roleForm.setData('all_business_areas', false)}
                                    className="mt-1 h-4 w-4 cursor-pointer border-slate-300 text-violet-600 focus:ring-violet-300"
                                />
                                <span className="block font-semibold">{t.areas_selected ?? 'Valgte fagområder'}</span>
                            </label>
                        </div>
                        {!roleForm.data.all_business_areas ? (
                            businessAreas.length === 0 ? (
                                <p className="mt-3 text-base text-slate-600">
                                    {tba.none_to_choose ?? 'Ingen fagområder er opprettet ennå.'}
                                </p>
                            ) : (
                                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                    {businessAreas.map((area) => {
                                        const checked = (roleForm.data.business_area_ids ?? []).includes(area.id);

                                        return (
                                            <label
                                                key={area.id}
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
                                                    onChange={() => toggleFormArea(area.id)}
                                                    className="h-4 w-4 cursor-pointer rounded border-slate-300 text-violet-600 focus:ring-violet-300"
                                                />
                                                {area.name}
                                            </label>
                                        );
                                    })}
                                </div>
                            )
                        ) : null}
                        <p className="mt-3 text-base leading-6 text-slate-600">
                            {t.field_areas_scope_note ?? 'Brukes i dag av Risiko.'}
                        </p>
                    </fieldset>

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
        </>
    );
}
