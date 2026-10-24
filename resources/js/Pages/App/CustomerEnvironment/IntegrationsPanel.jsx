import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { PRIMARY_COLOURS, SECONDARY_COLOURS, WARNING_COLOURS } from '../../../Support/actionStyles';
import { integrationAccessLines } from './integrationAccess';

/**
 * Kundemiljø → Integrasjoner (System Owner only).
 *
 * Applications approved by Procynia can be given read access to this customer's data through the
 * external API. The System Owner chooses which of the customer's roles the integration holds; only
 * the read rights in those roles count, and only for modules the customer has ordered. Each grant
 * shows what the integration can actually read, so the effect of the roles is visible, not only
 * their names. Revoking takes effect on the application's next call.
 */
function formatDateTime(value, locale) {
    if (!value) {
        return null;
    }

    return new Date(value).toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' });
}

function RoleChoice({ roles, selected, onChange, t, error }) {
    const toggle = (id) => onChange(selected.includes(id) ? selected.filter((value) => value !== id) : [...selected, id]);

    return (
        <fieldset aria-describedby="integration-roles-hint">
            <legend className="text-base font-semibold text-slate-900">{t.roles_label ?? 'Roller'}</legend>
            <p id="integration-roles-hint" className="mt-1 text-base leading-6 text-slate-600">
                {t.roles_hint ?? 'Velg roller fra Tilganger. Bare leserettighetene i rollene gjelder for en integrasjon.'}
            </p>
            {roles.length === 0 ? (
                <p className="mt-3 text-base text-slate-600">{t.roles_none_defined ?? 'Kundemiljøet har ingen aktive roller. Opprett en rolle under Tilganger først.'}</p>
            ) : (
                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                    {roles.map((role) => (
                        <label key={role.id} className="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-base text-slate-900">
                            <input
                                type="checkbox"
                                checked={selected.includes(role.id)}
                                onChange={() => toggle(role.id)}
                                className="h-5 w-5 rounded border-field-border text-violet-600 focus:ring-violet-300"
                            />
                            {role.name}
                        </label>
                    ))}
                </div>
            )}
            {error ? <p className="mt-2 text-base text-rose-700">{error}</p> : null}
        </fieldset>
    );
}

export default function IntegrationsPanel({ integrations, modal: Modal, t = {}, locale = 'nb-NO' }) {
    const { clients = [], grants = [], roles = [], store_url: storeUrl } = integrations;
    const [dialog, setDialog] = useState({ mode: null, client: null, grant: null });
    const form = useForm({ api_client_id: null, customer_role_ids: [] });
    const [revoking, setRevoking] = useState(false);

    const close = () => {
        setDialog({ mode: null, client: null, grant: null });
        form.reset();
        form.clearErrors();
    };

    const openGrant = (client) => {
        form.clearErrors();
        form.setData({ api_client_id: client.id, customer_role_ids: [] });
        setDialog({ mode: 'grant', client, grant: null });
    };

    const openRoles = (grant) => {
        form.clearErrors();
        form.setData({ api_client_id: grant.client.id, customer_role_ids: grant.role_ids });
        setDialog({ mode: 'roles', client: grant.client, grant });
    };

    const openRevoke = (grant) => setDialog({ mode: 'revoke', client: grant.client, grant });

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: close };

        if (dialog.mode === 'grant') {
            form.post(storeUrl, options);
        } else if (dialog.mode === 'roles' && dialog.grant) {
            form.put(dialog.grant.update_url, options);
        }
    };

    const revoke = () => {
        if (!dialog.grant) {
            return;
        }

        setRevoking(true);
        router.delete(dialog.grant.revoke_url, {
            preserveScroll: true,
            onSuccess: close,
            onFinish: () => setRevoking(false),
        });
    };

    const replace = (text, values) => Object.entries(values).reduce((result, [key, value]) => result.replace(`:${key}`, value ?? ''), text ?? '');

    return (
        <section className="space-y-5" aria-labelledby="integrations-heading">
            <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                <h2 id="integrations-heading" className="text-xl font-semibold text-slate-950">{t.heading ?? 'Integrasjoner'}</h2>
                <p className="mt-1 max-w-3xl text-base leading-6 text-slate-600">
                    {t.intro ?? 'Gi applikasjoner som Procynia har godkjent, lesetilgang til kundens data gjennom API-et. En integrasjon får bare leserettighetene i rollene du velger, i modulene dere har bestilt. Den kan aldri endre, vurdere, godkjenne eller slette noe.'}
                </p>
            </div>

            <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                <h3 className="text-lg font-semibold text-slate-950">{t.grants_heading ?? 'Applikasjoner med tilgang'}</h3>
                {grants.length === 0 ? (
                    <p className="mt-2 text-base text-slate-600">{t.grants_empty ?? 'Ingen applikasjoner har tilgang til kundens data.'}</p>
                ) : (
                    <ul className="mt-4 space-y-4">
                        {grants.map((grant) => {
                            const roleNames = roles.filter((role) => grant.role_ids.includes(role.id)).map((role) => role.name);
                            const accessLines = integrationAccessLines(grant.access, t);

                            return (
                                <li key={grant.id} className="rounded-2xl border border-slate-200 p-5" data-integration-grant={grant.client.name}>
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="text-lg font-semibold text-slate-950">{grant.client.name}</p>
                                            <p className="mt-1 text-base text-slate-600">
                                                {replace(t.granted_meta ?? 'Tilgang gitt :date av :name', {
                                                    date: formatDateTime(grant.granted_at, locale),
                                                    name: grant.granted_by_name ?? (t.unknown_person ?? 'ukjent'),
                                                })}
                                                {' · '}
                                                {grant.last_used_at
                                                    ? replace(t.last_used ?? 'Sist brukt :date', { date: formatDateTime(grant.last_used_at, locale) })
                                                    : (t.never_used ?? 'Ikke brukt ennå')}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 flex-wrap gap-2">
                                            <button
                                                type="button"
                                                onClick={() => openRoles(grant)}
                                                className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2 text-base font-semibold transition ${SECONDARY_COLOURS}`}
                                            >
                                                {t.change_roles ?? 'Endre roller'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => openRevoke(grant)}
                                                className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2 text-base font-semibold transition ${WARNING_COLOURS}`}
                                            >
                                                {t.revoke ?? 'Trekk tilbake'}
                                            </button>
                                        </div>
                                    </div>

                                    {!grant.client.is_enabled ? (
                                        <p className="mt-3 rounded-xl bg-amber-50 px-4 py-3 text-base text-amber-900">
                                            {t.client_disabled ?? 'Procynia har deaktivert applikasjonen. Kall avvises til den aktiveres igjen.'}
                                        </p>
                                    ) : null}

                                    <dl className="mt-4 grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <dt className="text-base font-semibold text-slate-900">{t.roles_label ?? 'Roller'}</dt>
                                            <dd className="mt-1 text-base text-slate-700">
                                                {roleNames.length > 0 ? roleNames.join(', ') : (t.no_roles ?? 'Ingen roller')}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-base font-semibold text-slate-900">{t.access_label ?? 'Kan lese gjennom API-et'}</dt>
                                            <dd className="mt-1 text-base text-slate-700">
                                                {accessLines.length > 0 ? (
                                                    <ul className="space-y-1">
                                                        {accessLines.map((line) => <li key={line}>{line}</li>)}
                                                    </ul>
                                                ) : (t.no_access ?? 'Ingenting. Gi integrasjonen en rolle med leserettighet.')}
                                            </dd>
                                        </div>
                                    </dl>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                <h3 className="text-lg font-semibold text-slate-950">{t.available_heading ?? 'Godkjente applikasjoner'}</h3>
                {clients.length === 0 ? (
                    <p className="mt-2 text-base text-slate-600">{t.available_empty ?? 'Procynia har ikke godkjent noen applikasjoner ennå.'}</p>
                ) : (
                    <ul className="mt-4 divide-y divide-slate-200">
                        {clients.map((client) => (
                            <li key={client.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div className="min-w-0">
                                    <p className="text-base font-semibold text-slate-950">{client.name}</p>
                                    {client.description ? <p className="text-base text-slate-600">{client.description}</p> : null}
                                </div>
                                {client.is_granted ? (
                                    <span className="rounded-full bg-emerald-50 px-3 py-1 text-base font-semibold text-emerald-800">{t.has_access ?? 'Har tilgang'}</span>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => openGrant(client)}
                                        className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2 text-base font-semibold transition ${PRIMARY_COLOURS}`}
                                    >
                                        {t.grant ?? 'Gi tilgang'}
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <Modal
                isOpen={dialog.mode === 'grant' || dialog.mode === 'roles'}
                title={replace(dialog.mode === 'grant' ? (t.grant_title ?? 'Gi :client tilgang') : (t.roles_title ?? 'Roller for :client'), { client: dialog.client?.name })}
                onClose={close}
            >
                <form onSubmit={submit} className="space-y-5">
                    <RoleChoice
                        roles={roles}
                        selected={form.data.customer_role_ids}
                        onChange={(ids) => form.setData('customer_role_ids', ids)}
                        t={t}
                        error={form.errors.customer_role_ids ?? form.errors.api_client_id}
                    />
                    <div className="flex flex-wrap justify-end gap-3">
                        <button
                            type="button"
                            onClick={close}
                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition ${SECONDARY_COLOURS}`}
                        >
                            {t.cancel ?? 'Avbryt'}
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 ${PRIMARY_COLOURS}`}
                        >
                            {form.processing ? (t.saving ?? 'Lagrer...') : dialog.mode === 'grant' ? (t.grant_submit ?? 'Gi tilgang') : (t.save ?? 'Lagre')}
                        </button>
                    </div>
                </form>
            </Modal>

            <Modal
                isOpen={dialog.mode === 'revoke'}
                title={t.revoke_title ?? 'Trekke tilbake tilgangen?'}
                onClose={close}
                closeDisabled={revoking}
            >
                <div className="space-y-5">
                    <p className="text-base leading-6 text-slate-700">
                        {replace(t.revoke_body ?? ':client avvises ved neste kall. Tildelingen beholdes i historikken.', { client: dialog.client?.name })}
                    </p>
                    <div className="flex flex-wrap justify-end gap-3">
                        <button
                            type="button"
                            onClick={close}
                            disabled={revoking}
                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition ${SECONDARY_COLOURS}`}
                        >
                            {t.cancel ?? 'Avbryt'}
                        </button>
                        <button
                            type="button"
                            onClick={revoke}
                            disabled={revoking}
                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-base font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 ${WARNING_COLOURS}`}
                        >
                            {t.revoke_submit ?? 'Trekk tilbake'}
                        </button>
                    </div>
                </div>
            </Modal>
        </section>
    );
}
