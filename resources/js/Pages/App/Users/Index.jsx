import { Link, router, usePage } from '@inertiajs/react';
import { PRIMARY_COLOURS } from '../../../Support/actionStyles';
import { useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import ScrollRegion from '../../../Components/App/ScrollRegion';
import { rowErrorId, trackRowAction, useRowActionError } from '../../../Support/rowActionError';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';
import InfoHint from '../../../Components/App/InfoHint';
import PageHelpButton from '../../../Components/App/PageHelpButton';

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

export default function UsersIndex({ users }) {
    const { locale, translations = {}, flash = {} } = usePage().props;
    const rowError = useRowActionError(flash?.error ?? null);
    const tu = translations?.user ?? {};
    const a11y = translations?.a11y ?? {};
    const [togglingUserId, setTogglingUserId] = useState(null);

    const toggleActive = (user) => {
        if (!user.can_toggle_active) {
            return;
        }

        if (user.is_active && !window.confirm(`Er du sikker på at du vil deaktivere ${user.name}?`)) {
            return;
        }

        setTogglingUserId(user.id);

        // A refusal is shown beside the row's button, not only in the banner at the top (A11Y-137).
        router.patch(user.toggle_active_url, {}, trackRowAction(`user-toggle-${user.id}`, {
            preserveScroll: true,
            onFinish: () => setTogglingUserId(null),
        }));
    };

    return (
        <CustomerAppLayout title="Brukere" showPageTitle={false}>
            <div className="space-y-7">
                <section className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-1.5">
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Brukere</h1>
                        <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                            Administrer brukere og roller for din egen kunde. Nye brukere opprettes som aktive med passordet du setter.
                        </p>
                    </div>
                    <PageHelpButton
                        buttonLabel={tu.page_help_button ?? 'Hjelp'}
                        title={tu.page_help_title ?? 'Om Brukere'}
                        intro={tu.page_help_intro ?? 'Brukersiden brukes til å administrere hvem som har tilgang til kundemiljøet, hvilken rolle de har og hvilken avdeling de tilhører.'}
                        sections={[
                            {
                                title: tu.page_help_section_roles ?? 'Roller og tilgang',
                                items: [
                                    {
                                        title: tu.page_help_item_system_owner_title ?? 'System Owner',
                                        text: tu.page_help_item_system_owner_text ?? 'Har full tilgang: kan administrere brukere, avdelinger, bevakningsprofiler og abonnement.',
                                    },
                                    {
                                        title: tu.page_help_item_bid_manager_title ?? 'Bid-manager',
                                        text: tu.page_help_item_bid_manager_text ?? 'Administrerer saker i tildelte avdelinger og kan ha utvidet innsyn i porteføljen.',
                                    },
                                    {
                                        title: tu.page_help_item_contributor_title ?? 'Bidragsyter',
                                        text: tu.page_help_item_contributor_text ?? 'Kan jobbe med saker de er tilknyttet. Tilgangen styres av bid-manager og System Owner.',
                                    },
                                ],
                            },
                        ]}
                    />
                </section>

                <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="flex justify-end">
                        <Link
                            href="/app/users/create"
                            className={`inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold transition ${PRIMARY_COLOURS}`}
                        >
                            Legg til bruker
                        </Link>
                    </div>
                </section>

                {users.length === 0 ? (
                    <EmptyStateBox
                        title="Ingen brukere ennå"
                        description="Legg til den første brukeren for å gi flere tilgang til kundeområdet."
                    />
                ) : (
                    <>
                        <section className="grid gap-3 md:hidden">
                            {users.map((user) => (
                                <article
                                    key={user.id}
                                    className="rounded-[20px] border border-slate-200 bg-white p-5 shadow-[0_8px_22px_rgba(15,23,42,0.04)]"
                                >
                                    <div className="space-y-3">
                                        <div>
                                            <div className="text-base font-semibold text-slate-950">{user.name}</div>
                                            <div className="text-sm text-slate-500">{user.email}</div>
                                            <div className="mt-1 text-sm text-slate-500">
                                                {user.department_name ? `Avdeling: ${user.department_name}` : 'Ingen avdeling'}
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap gap-2 text-xs font-medium">
                                            <span className="rounded-full bg-sky-100 px-3 py-1 text-sky-700">{user.bid_role}</span>
                                            {user.is_qa ? (
                                                <span className="rounded-full bg-teal-100 px-3 py-1 text-teal-700">QA</span>
                                            ) : null}
                                            {user.bid_manager_scope_summary ? (
                                                <span className="rounded-full bg-amber-100 px-3 py-1 text-amber-700">
                                                    {user.bid_manager_scope_summary}
                                                </span>
                                            ) : null}
                                            {(user.customer_roles ?? []).map((role) => (
                                                <span
                                                    key={role.id}
                                                    className={
                                                        role.is_active
                                                            ? 'rounded-full bg-violet-100 px-3 py-1 text-violet-700'
                                                            : 'rounded-full bg-slate-200 px-3 py-1 text-slate-600'
                                                    }
                                                >
                                                    {role.name}
                                                </span>
                                            ))}
                                            <span
                                                className={
                                                    user.is_active
                                                        ? 'rounded-full bg-emerald-100 px-3 py-1 text-emerald-700'
                                                        : 'rounded-full bg-slate-200 px-3 py-1 text-slate-700'
                                                }
                                            >
                                                {user.is_active ? 'Aktiv' : 'Inaktiv'}
                                            </span>
                                        </div>
                                        <div className="text-xs text-slate-600">Opprettet {formatDate(user.created_at, locale)}</div>
                                        <div className="flex flex-col gap-2 sm:flex-row">
                                            <Link
                                                href={user.edit_url}
                                                aria-label={`Rediger ${user.name}`}
                                                className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                            >
                                                Rediger
                                            </Link>
                                            <button
                                                type="button"
                                                disabled={!user.can_toggle_active || togglingUserId === user.id}
                                                onClick={() => toggleActive(user)}
                                                                                                aria-describedby={rowError(`user-toggle-${user.id}`) ? rowErrorId(`user-toggle-${user.id}-card`) : undefined}
aria-label={user.is_active && user.is_self ? undefined : `${user.is_active ? 'Deaktiver' : 'Aktiver'} ${user.name}`}
                                                className={
                                                    user.can_toggle_active
                                                        ? user.is_active
                                                            ? 'inline-flex min-h-10 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100'
                                                            : 'inline-flex min-h-10 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100'
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
                                        {rowError(`user-toggle-${user.id}`) ? (
                                            <p id={rowErrorId(`user-toggle-${user.id}-card`)} className="text-sm text-rose-700">{rowError(`user-toggle-${user.id}`)}</p>
                                        ) : null}
                                    </div>
                                </article>
                            ))}
                        </section>

                        <section className="hidden overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)] md:block">
                            <ScrollRegion label={a11y.table_users ?? 'Brukere'} edgeRoom={false}>
                                <table className="min-w-full divide-y divide-slate-200">
                                    <caption className="sr-only">{a11y.table_users ?? 'Brukere'}</caption>
                                    <thead className="bg-slate-50">
                                        <tr className="text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">
                                            <th scope="col" className="px-6 py-4">Navn</th>
                                            <th scope="col" className="px-6 py-4">E-post</th>
                                            <th scope="col" className="px-6 py-4">Avdeling</th>
                                            <th scope="col" className="px-6 py-4">
                                                <span className="inline-flex items-center gap-1.5">
                                                    Rolle
                                                    <InfoHint size="sm" align="left" label="Vis forklaring for Rolle" text={tu.hint_role_column} />
                                                </span>
                                            </th>
                                            <th scope="col" className="px-6 py-4">{tu.col_customer_roles ?? 'Egne roller'}</th>
                                            <th scope="col" className="px-6 py-4">Status</th>
                                            <th scope="col" className="px-6 py-4">Opprettet</th>
                                            <th scope="col" className="px-6 py-4">Handlinger</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {users.map((user) => (
                                            <tr key={user.id} className="text-sm text-slate-700">
                                                <th scope="row" className="px-6 py-4 text-left font-medium text-slate-950">{user.name}</th>
                                                <td className="px-6 py-4">{user.email}</td>
                                                <td className="px-6 py-4 text-slate-500">{user.department_name ?? '—'}</td>
                                                <td className="px-6 py-4">
                                                    <div className="space-y-1">
                                                        <div className="flex items-center gap-1.5">
                                                            <span>{user.bid_role}</span>
                                                            {user.is_qa ? (
                                                                <span className="inline-flex rounded-full bg-teal-100 px-2 py-0.5 text-xs font-semibold text-teal-700">
                                                                    QA
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                        {user.bid_manager_scope_summary ? (
                                                            <div className="text-xs font-medium text-slate-500">{user.bid_manager_scope_summary}</div>
                                                        ) : null}
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4">
                                                    {(user.customer_roles ?? []).length === 0 ? (
                                                        <span className="text-slate-500">{tu.no_customer_roles ?? 'Ingen'}</span>
                                                    ) : (
                                                        <div className="flex flex-wrap gap-1.5">
                                                            {user.customer_roles.map((role) => (
                                                                <span
                                                                    key={role.id}
                                                                    className={
                                                                        role.is_active
                                                                            ? 'inline-flex rounded-full bg-violet-100 px-2.5 py-0.5 text-xs font-semibold text-violet-700'
                                                                            : 'inline-flex rounded-full bg-slate-200 px-2.5 py-0.5 text-xs font-semibold text-slate-600'
                                                                    }
                                                                >
                                                                    {role.name}
                                                                </span>
                                                            ))}
                                                        </div>
                                                    )}
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
                                                <td className="px-6 py-4 text-slate-500">{formatDate(user.created_at, locale)}</td>
                                                <td className="px-6 py-4">
                                                    <div className="flex flex-wrap gap-2">
                                                        <Link
                                                            href={user.edit_url}
                                                            aria-label={`Rediger ${user.name}`}
                                                            className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                        >
                                                            Rediger
                                                        </Link>
                                                        <button
                                                            type="button"
                                                            disabled={!user.can_toggle_active || togglingUserId === user.id}
                                                            onClick={() => toggleActive(user)}
                                                                                                                        aria-describedby={rowError(`user-toggle-${user.id}`) ? rowErrorId(`user-toggle-${user.id}-table`) : undefined}
aria-label={user.is_active && user.is_self ? undefined : `${user.is_active ? 'Deaktiver' : 'Aktiver'} ${user.name}`}
                                                            className={
                                                                user.can_toggle_active
                                                                    ? user.is_active
                                                                        ? 'inline-flex min-h-10 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100'
                                                                        : 'inline-flex min-h-10 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100'
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
                                                    {rowError(`user-toggle-${user.id}`) ? (
                                                        <p id={rowErrorId(`user-toggle-${user.id}-table`)} className="mt-2 max-w-xs text-sm text-rose-700">{rowError(`user-toggle-${user.id}`)}</p>
                                                    ) : null}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </ScrollRegion>
                        </section>
                    </>
                )}
            </div>
        </CustomerAppLayout>
    );
}
