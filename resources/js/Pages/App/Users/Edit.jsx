import { Link, router, useForm, usePage } from '@inertiajs/react';
import { PRIMARY_COLOURS } from '../../../Support/actionStyles';
import { useRef, useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import CustomerEnvironmentHeader from '../CustomerEnvironment/CustomerEnvironmentHeader';
import AlertBox from '../../../Components/App/AlertBox';
import DepartmentCheckboxGroup from '../../../Components/App/DepartmentCheckboxGroup';
import FormButtonRow from '../../../Components/App/FormButtonRow';
import InfoHint from '../../../Components/App/InfoHint';
import FormField from '../../../Components/App/FormField';
import { useFocusFirstError } from '../../../Support/focusManagement';
import { withOldInput } from './userFormOldInput';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

/**
 * A field on this page, wired through FormField (A11Y-001, A11Y-013): the label names the control by
 * for/id, the «i» sits beside the label instead of inside it — inside, it took the label for itself and
 * left the select without a name — and hint and error are tied to the control.
 */
function UserField({ span = 'md:col-span-2', ...props }) {
    return (
        <FormField
            {...props}
            className={`space-y-2 ${span}`}
            labelClassName="text-sm font-medium text-slate-700"
            controlClassName=""
            hintClassName="text-xs text-slate-600"
            errorClassName="text-sm text-rose-700"
        />
    );
}

/** A checkbox whose label has an «i» beside it — kept out of the <label> for the same reason. */
function CheckboxWithInfo({ id, checked, onChange, label, info, error }) {
    const errorId = `${id}-error`;

    return (
        <>
            <div className="flex items-center gap-2.5">
                <input
                    id={id}
                    type="checkbox"
                    checked={checked}
                    onChange={onChange}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? errorId : undefined}
                    className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-300"
                />
                <label htmlFor={id} className="text-sm font-medium text-slate-700">{label}</label>
                {info}
            </div>
            {error ? <p id={errorId} className="text-sm text-rose-700">{error}</p> : null}
        </>
    );
}

export default function UsersEdit({
    redirectTo,
    customerEnvironment,
    user,
    bidRoleOptions,
    bidManagerScopeOptions,
    primaryAffiliationScopeOptions,
    departmentOptions,
    managedDepartmentOptions,
    canEditRole,
    canEditBidManagerScope,
    canEditCustomerRoles = false,
    customerRoleOptions = [],
    oldInput = null,
}) {
    const page = usePage();
    const { translations = {} } = page.props;
    const usersFormText = translations.users_form ?? {};
    const [toggling, setToggling] = useState(false);
    // After a refused save the form opens with what was entered (F6), except the password.
    const form = useForm(withOldInput({
        name: user.name,
        bid_role: user.bid_role_value,
        is_qa: user.is_qa ?? false,
        is_wiki_approver: user.is_wiki_approver ?? false,
        customer_role_ids: user.customer_role_ids ?? [],
        bid_manager_scope: user.bid_manager_scope_value ?? (bidManagerScopeOptions[0]?.value ?? 'company'),
        primary_affiliation_scope: user.primary_affiliation_scope_value ?? (primaryAffiliationScopeOptions[0]?.value ?? 'company'),
        primary_department_id: user.primary_department_id ?? '',
        password: '',
        password_confirmation: '',
        department_ids: user.department_ids ?? [],
        managed_department_ids: user.managed_department_ids ?? [],
        redirect_to: redirectTo,
    }, oldInput, {
        // Egne roller are only sent when they can be edited; otherwise the user's own stay.
        arrays: ['department_ids', 'managed_department_ids', ...(canEditCustomerRoles ? ['customer_role_ids'] : [])],
        booleans: ['is_qa', 'is_wiki_approver'],
        numbers: ['primary_department_id'],
    }));

    const isBidManager = form.data.bid_role === 'bid_manager';
    const isDepartmentScopedBidManager = isBidManager && form.data.bid_manager_scope === 'departments';
    const isDepartmentPrimaryAffiliation = form.data.primary_affiliation_scope === 'department';
    const primaryDepartmentOptions = departmentOptions.filter((option) => form.data.department_ids.includes(option.value));
    const pageErrors = page.props.errors ?? {};
    const errors = Object.keys(form.errors).length > 0 ? form.errors : pageErrors;
    const firstError = Object.values(errors)[0] ?? null;
    // This form posts natively: a rejected submit comes back as a fresh page carrying the errors, so
    // that page load is the answer to the submit and focus goes to the first wrong field (A11Y-013).
    const formRef = useRef(null);
    useFocusFirstError(formRef, { errorsOnMount: errors });
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

    const toggleCustomerRole = (roleId) => {
        const selected = form.data.customer_role_ids ?? [];

        form.setData(
            'customer_role_ids',
            selected.includes(roleId) ? selected.filter((value) => value !== roleId) : [...selected, roleId],
        );
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
                <CustomerEnvironmentHeader
                    activeTab="users"
                    onChangeTab={(tab) => router.get(customerEnvironment.index_url, { tab })}
                    showPermissions={customerEnvironment.show_permissions}
                />

                <div className="mx-auto max-w-3xl">
                    <form
                        ref={formRef}
                        method="post"
                        action={user.update_url}
                        className="space-y-6 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:p-8"
                    >
                        {csrfToken ? <input type="hidden" name="_token" value={csrfToken} /> : null}
                        <input type="hidden" name="_method" value="put" />
                        <input type="hidden" name="redirect_to" value={form.data.redirect_to} />

                        <div className="space-y-1">
                            <h2 className="text-xl font-semibold tracking-tight text-slate-950">
                                {usersFormText.edit_title ?? 'Rediger bruker'}
                            </h2>
                            <p className="text-sm leading-6 text-slate-500">
                                {usersFormText.edit_subtitle ?? 'Oppdater rolle, status og passord for brukeren innenfor din egen kunde.'}
                            </p>
                        </div>

                        {firstError ? (
                            <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                                {firstError}
                            </div>
                        ) : null}

                        <div className="grid gap-5 md:grid-cols-2">
                            <UserField
                                id="user-name"
                                label="Navn"
                                error={errors.name}
                                span="md:col-span-2"
                            >
                                {(field) => (
                                    <input
                                        {...field}
                                        type="text"
                                        name="name"
                                        value={form.data.name}
                                        onChange={(event) => form.setData('name', event.target.value)}
                                        className="h-11 w-full rounded-xl border border-field-border bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    />
                                )}
                            </UserField>

                            <UserField
                                id="user-email-readonly"
                                label="E-post"
                                hint="E-post redigeres ikke i denne fasen."
                                span="md:col-span-2"
                            >
                                {(field) => (
                                    <input
                                        {...field}
                                        type="email"
                                        value={user.email}
                                        disabled
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-500 outline-none disabled:cursor-not-allowed"
                                    />
                                )}
                            </UserField>

                            {canEditRole ? (
                                <UserField
                                    id="user-bid-role"
                                    label={usersFormText.field_role ?? 'Rolle'}
                                    info={<InfoHint size="sm" label="Vis forklaring for Rolle" text={usersFormText.hint_role} />}
                                    hint="System Owner kan endre roller på alle brukere. Bid Manager kan bare administrere Contributor og Viewer innenfor sitt ansvarsområde."
                                    error={errors.bid_role}
                                    span="md:col-span-2"
                                >
                                    {(field) => (
                                        <select
                                            {...field}
                                            name="bid_role"
                                            value={form.data.bid_role}
                                            onChange={(event) => handleBidRoleChange(event.target.value)}
                                            className="h-11 w-full rounded-xl border border-field-border bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                        >
                                            {bidRoleOptions.map((option) => (
                                                <option key={option.value} value={option.value}>
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                </UserField>
                            ) : (
                                <div className="space-y-2 md:col-span-2">
                                    <span className="text-sm font-medium text-slate-700">Rolle</span>
                                    <div className="flex min-h-11 items-center rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-700">
                                        {user.bid_role_label}
                                    </div>
                                    <p className="text-xs text-slate-600">
                                        Rollen kan ikke endres i denne konteksten.
                                    </p>
                                </div>
                            )}

                            {form.data.bid_role === 'system_owner' ? (
                                <AlertBox title="System Owner" className="space-y-2 md:col-span-2">
                                    <p className="text-xs leading-5 text-amber-800">
                                        System Owner har full kontroll over brukere, roller, avdelingsstruktur og bid-managernes administrative ansvarsområde.
                                    </p>
                                </AlertBox>
                            ) : null}

                            {canEditRole ? (
                                <div className="space-y-2 md:col-span-2">
                                    <input type="hidden" name="is_qa" value={form.data.is_qa ? '1' : '0'} />
                                    <CheckboxWithInfo
                                        id="user-is-qa"
                                        checked={form.data.is_qa}
                                        onChange={(event) => form.setData('is_qa', event.target.checked)}
                                        label={usersFormText.field_is_qa ?? 'QA (kvalitetssikring)'}
                                        info={<InfoHint size="sm" label="Vis forklaring for QA" text={usersFormText.hint_is_qa ?? 'Kan gå gjennom og godkjenne enkeltpåstander mot kildegrunnlaget. Gir ikke rett til å publisere Wiki-sider.'} />}
                                        error={errors.is_qa}
                                    />

                                    {/* Its own capability, not a second meaning for QA: vouching for
                                        a claim against its source and publishing a page are
                                        different decisions, and a user may hold either or both. */}
                                    <input type="hidden" name="is_wiki_approver" value={form.data.is_wiki_approver ? '1' : '0'} />
                                    <CheckboxWithInfo
                                        id="user-is-wiki-approver"
                                        checked={form.data.is_wiki_approver}
                                        onChange={(event) => form.setData('is_wiki_approver', event.target.checked)}
                                        label={usersFormText.field_is_wiki_approver ?? 'Wiki-godkjenner'}
                                        info={<InfoHint size="sm" label="Vis forklaring for Wiki-godkjenner" text={usersFormText.hint_is_wiki_approver ?? 'Kan gjennomgå, godkjenne og publisere Wiki-sider som er sendt til gjennomgang.'} />}
                                        error={errors.is_wiki_approver}
                                    />
                                </div>
                            ) : (user.is_qa || user.is_wiki_approver) ? (
                                <div className="flex flex-wrap gap-2 md:col-span-2">
                                    {user.is_qa ? (
                                        <span className="inline-flex rounded-full bg-teal-100 px-3 py-1 text-xs font-semibold text-teal-700">
                                            QA
                                        </span>
                                    ) : null}
                                    {user.is_wiki_approver ? (
                                        <span className="inline-flex rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold text-violet-700">
                                            {usersFormText.field_is_wiki_approver ?? 'Wiki-godkjenner'}
                                        </span>
                                    ) : null}
                                </div>
                            ) : null}

                            {isBidManager && canEditBidManagerScope ? (
                                <UserField
                                    id="user-bid-manager-scope"
                                    label={usersFormText.admin_scope_label ?? 'Administrativt ansvarsområde'}
                                    info={<InfoHint size="sm" label="Vis forklaring for Administrativt ansvarsområde" text={usersFormText.hint_admin_scope} />}
                                    hint="Dette styrer hvilke avdelinger bid-manager kan administrere, og er separat fra hvilke avdelinger brukeren tilhører."
                                    error={errors.bid_manager_scope}
                                    span="md:col-span-2"
                                >
                                    {(field) => (
                                        <select
                                            {...field}
                                            name="bid_manager_scope"
                                            value={form.data.bid_manager_scope}
                                            onChange={(event) => handleBidManagerScopeChange(event.target.value)}
                                            className="h-11 w-full rounded-xl border border-field-border bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                        >
                                            {bidManagerScopeOptions.map((option) => (
                                                <option key={option.value} value={option.value}>
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                </UserField>
                            ) : isBidManager ? (
                                <div className="space-y-2 md:col-span-2">
                                    <span className="text-sm font-medium text-slate-700">Administrativt ansvarsområde</span>
                                    <div className="flex min-h-11 items-center rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-700">
                                        {user.bid_manager_scope_label ?? 'Ikke satt'}
                                    </div>
                                    <p className="text-xs text-slate-600">
                                        Administrativt ansvarsområde kan bare endres av systemeier.
                                    </p>
                                </div>
                            ) : null}

                            {/* The customer's own roles. Separate from Rolle above, which stays
                                Procynia's anbud vocabulary — a user keeps that role and may in
                                addition hold any number of these. An inactive role is shown only
                                when the user already holds it, and cannot be ticked on. */}
                            {canEditCustomerRoles ? (
                                // A group of checkboxes under one question (A11Y-120): named by the question,
                                // described by its hint and error, with the «i» beside the question, not inside it.
                                <fieldset
                                    className="space-y-2 md:col-span-2"
                                    aria-labelledby="user-customer-roles-legend"
                                    aria-describedby={['user-customer-roles-hint', errors.customer_role_ids ? 'user-customer-roles-error' : null].filter(Boolean).join(' ')}
                                >
                                    <span className="inline-flex items-center gap-1.5 text-sm font-medium text-slate-700">
                                        <span id="user-customer-roles-legend">{usersFormText.customer_roles_label ?? 'Egne roller'}</span>
                                        <InfoHint
                                            size="sm"
                                            label="Vis forklaring for Egne roller"
                                            text={usersFormText.customer_roles_help ?? 'Rollene dere har definert selv under Kundemiljø → Tilganger. Brukeren kan ha ingen, én eller flere. Anbudsrollen over er uendret.'}
                                        />
                                    </span>
                                    {customerRoleOptions.length > 0 ? (
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            {customerRoleOptions.map((role) => {
                                                const checked = (form.data.customer_role_ids ?? []).includes(role.id);

                                                return (
                                                    <label
                                                        key={role.id}
                                                        className={classNames(
                                                            'flex cursor-pointer items-center gap-3 rounded-2xl border px-4 py-3 text-sm transition',
                                                            checked
                                                                ? 'border-violet-300 bg-violet-50 text-violet-900'
                                                                : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50',
                                                        )}
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            aria-invalid={errors.customer_role_ids ? true : undefined}
                                                            name="customer_role_ids[]"
                                                            value={role.id}
                                                            checked={checked}
                                                            disabled={!role.is_active && !checked}
                                                            onChange={() => toggleCustomerRole(role.id)}
                                                            className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500 disabled:cursor-not-allowed"
                                                        />
                                                        <span>
                                                            {role.name}
                                                            {!role.is_active ? (
                                                                <span className="ml-2 text-xs font-semibold text-slate-600">
                                                                    ({usersFormText.customer_roles_inactive ?? 'Inaktiv'})
                                                                </span>
                                                            ) : null}
                                                        </span>
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    ) : (
                                        <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-500">
                                            {usersFormText.customer_roles_none ?? 'Dere har ingen egne roller ennå. Opprett dem under Kundemiljø → Tilganger.'}
                                        </div>
                                    )}
                                    <p id="user-customer-roles-hint" className="text-xs text-slate-600">
                                        {usersFormText.customer_roles_hint ?? 'En inaktiv rolle beholder tildelingen sin, men gir ingen rettigheter.'}
                                    </p>
                                    {errors.customer_role_ids ? (
                                        <p id="user-customer-roles-error" className="text-sm text-rose-700">{errors.customer_role_ids}</p>
                                    ) : null}
                                </fieldset>
                            ) : (
                                <div className="space-y-2 md:col-span-2">
                                    <span className="text-sm font-medium text-slate-700">
                                        {usersFormText.customer_roles_label ?? 'Egne roller'}
                                    </span>
                                    {(user.customer_roles ?? []).length > 0 ? (
                                        <div className="flex flex-wrap gap-2">
                                            {user.customer_roles.map((role) => (
                                                <span
                                                    key={role.id}
                                                    className={classNames(
                                                        'inline-flex rounded-full px-3 py-1 text-xs font-semibold',
                                                        role.is_active
                                                            ? 'bg-violet-100 text-violet-700'
                                                            : 'bg-slate-200 text-slate-600',
                                                    )}
                                                >
                                                    {role.name}
                                                </span>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="flex min-h-11 items-center rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-500">
                                            {usersFormText.customer_roles_empty ?? 'Ingen'}
                                        </div>
                                    )}
                                    <p className="text-xs text-slate-600">
                                        {usersFormText.customer_roles_locked ?? 'Egne roller tildeles av systemeier.'}
                                    </p>
                                </div>
                            )}

                            {oldInput ? (
                                <AlertBox className="md:col-span-2">
                                    {usersFormText.password_reenter_edit ?? 'Skrev du inn et nytt passord, må du skrive det inn på nytt. Av sikkerhetshensyn blir passord ikke tatt vare på når lagringen avvises. Resten av skjemaet er beholdt.'}
                                </AlertBox>
                            ) : null}

                            <UserField
                                id="user-password"
                                label="Nytt passord"
                                hint={usersFormText.password_empty_hint_min ?? 'La feltet stå tomt hvis passordet ikke skal endres. Et nytt passord må ha minst 8 tegn.'}
                                error={errors.password}
                                span="md:col-span-1"
                            >
                                {(field) => (
                                    <input
                                        {...field}
                                        type="password"
                                        name="password"
                                        value={form.data.password}
                                        onChange={(event) => form.setData('password', event.target.value)}
                                        minLength={8}
                                        autoComplete="new-password"
                                        className="h-11 w-full rounded-xl border border-field-border bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    />
                                )}
                            </UserField>

                            <UserField
                                id="user-password-confirmation"
                                label="Bekreft nytt passord"
                                span="md:col-span-1"
                            >
                                {(field) => (
                                    <input
                                        {...field}
                                        type="password"
                                        name="password_confirmation"
                                        value={form.data.password_confirmation}
                                        onChange={(event) => form.setData('password_confirmation', event.target.value)}
                                        autoComplete="new-password"
                                        className="h-11 w-full rounded-xl border border-field-border bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    />
                                )}
                            </UserField>

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
                                    <p className="text-xs text-slate-600">
                                        Administrativt ansvarsområde kan bare endres av systemeier.
                                    </p>
                                </div>
                            ) : null}

                            <UserField
                                id="user-primary-affiliation-scope"
                                label="Primær tilhørighet"
                                hint="Primær tilhørighet styrer brukerens normale organisatoriske hjemsted. Dette er separat fra bid-manager sitt administrative ansvarsområde."
                                error={errors.primary_affiliation_scope}
                                span="md:col-span-2"
                            >
                                {(field) => (
                                    <select
                                        {...field}
                                        name="primary_affiliation_scope"
                                        value={form.data.primary_affiliation_scope}
                                        onChange={(event) => handlePrimaryAffiliationScopeChange(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-field-border bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                    >
                                        {primaryAffiliationScopeOptions.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </UserField>

                            {isDepartmentPrimaryAffiliation ? (
                                <UserField
                                    id="user-primary-department-id"
                                    label="Primær avdeling"
                                    hint="Primær avdeling må være blant brukerens operative avdelinger."
                                    error={errors.primary_department_id}
                                    span="md:col-span-2"
                                >
                                    {(field) => (
                                        <select
                                            {...field}
                                            name="primary_department_id"
                                            value={form.data.primary_department_id}
                                            onChange={(event) => form.setData('primary_department_id', event.target.value === '' ? '' : Number(event.target.value))}
                                            className="h-11 w-full rounded-xl border border-field-border bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                        >
                                            <option value="">Velg primær avdeling</option>
                                            {primaryDepartmentOptions.map((option) => (
                                                <option key={option.value} value={option.value}>
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                </UserField>
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
                                {user.is_self ? <p className="text-xs text-slate-600">Du kan ikke deaktivere din egen bruker.</p> : null}
                            </div>
                        </div>

                        <FormButtonRow>
                            <Link
                                href={redirectTo}
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
