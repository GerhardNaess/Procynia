import { Link, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function formatDate(value, locale, options = {}) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        ...options,
    }).format(new Date(value));
}

function formatCount(value, locale) {
    return new Intl.NumberFormat(locale).format(Number(value ?? 0));
}

const statDefinitions = [
    {
        key: 'userInbox',
        label: 'Nye treff i min inbox',
        emptyLabel: 'Ingen personlige treff akkurat nå',
    },
    {
        key: 'departmentInbox',
        label: 'Nye treff i avdelingsinnboks',
        emptyLabel: 'Ingen avdelingstreff akkurat nå',
        unavailableLabel: 'Ingen avdeling valgt på brukeren',
    },
    {
        key: 'worklist',
        label: 'Antall i arbeidsliste',
        emptyLabel: 'Arbeidslisten er tom',
    },
    {
        key: 'activeWatchProfiles',
        label: 'Antall aktive watch profiles',
        emptyLabel: 'Ingen aktive watch profiles',
    },
];

export default function DashboardIndex({
    stats = {},
    recentInboxItems = [],
    recentWorklistItems = [],
    watchProfileSummary = {},
    quickLinks = [],
}) {
    const { locale = 'nb-NO' } = usePage().props;

    return (
        <CustomerAppLayout title="Oversikt" showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Oversikt</h1>
                    <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                        Siden gir deg en rask status på overvåkning, inbox, arbeidsliste og watch profiles.
                    </p>
                </section>

                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    {statDefinitions.map((definition) => {
                        const stat = stats[definition.key] ?? {};
                        const isAvailable = Boolean(stat.is_available ?? true);
                        const href = stat.href ?? null;

                        return (
                            <article
                                key={definition.key}
                                className={classNames(
                                    'rounded-[24px] border bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)] transition',
                                    isAvailable ? 'border-slate-200' : 'border-slate-200/80 bg-slate-50/80',
                                )}
                            >
                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                    Status
                                </div>
                                <div className="mt-3 text-3xl font-semibold tracking-tight text-slate-950">
                                    {formatCount(stat.value ?? 0, locale)}
                                </div>
                                <div className="mt-1 text-sm font-medium text-slate-900">{definition.label}</div>
                                <p className="mt-2 text-sm leading-6 text-slate-500">
                                    {!isAvailable
                                        ? definition.unavailableLabel
                                        : Number(stat.value ?? 0) > 0
                                            ? 'Oppdatert fra eksisterende data i kundeportalen.'
                                            : definition.emptyLabel}
                                </p>
                                {href ? (
                                    <Link
                                        href={href}
                                        className="mt-4 inline-flex text-sm font-semibold text-violet-700 transition hover:text-violet-800"
                                    >
                                        Åpne
                                    </Link>
                                ) : (
                                    <span className="mt-4 inline-flex text-sm font-semibold text-slate-400">
                                        Ikke tilgjengelig
                                    </span>
                                )}
                            </article>
                        );
                    })}
                </section>

                <section className="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(320px,0.95fr)]">
                    <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                    Inbox
                                </div>
                                <h2 className="mt-1 text-2xl font-semibold tracking-tight text-slate-950">Siste treff i inbox</h2>
                            </div>
                            <Link
                                href={(stats.userInbox?.href) ?? '/app/inbox/user'}
                                className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                            >
                                Åpne inbox
                            </Link>
                        </div>

                        {recentInboxItems.length === 0 ? (
                            <div className="mt-5 rounded-[20px] border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center">
                                <div className="text-lg font-semibold text-slate-900">Ingen nye treff i inbox akkurat nå</div>
                                <p className="mt-2 text-sm leading-6 text-slate-500">
                                    Når aktive watch profiles finner nye Doffin-treff, dukker de opp her.
                                </p>
                                <div className="mt-5 flex flex-wrap justify-center gap-3">
                                    <Link
                                        href="/app/notices"
                                        className="inline-flex items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700"
                                    >
                                        Gå til anskaffelser
                                    </Link>
                                    <Link
                                        href={(stats.userInbox?.href) ?? '/app/inbox/user'}
                                        className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                    >
                                        Åpne min inbox
                                    </Link>
                                </div>
                            </div>
                        ) : (
                            <div className="mt-5 space-y-3">
                                {recentInboxItems.map((item) => (
                                    <Link
                                        key={`${item.source_label}-${item.id}`}
                                        href={item.href}
                                        className="block rounded-[20px] border border-slate-200 px-4 py-4 transition hover:border-slate-300 hover:bg-slate-50"
                                    >
                                        <div className="flex flex-col gap-2">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="inline-flex rounded-full bg-violet-50 px-2.5 py-1 text-xs font-semibold text-violet-700 ring-1 ring-inset ring-violet-200">
                                                    {item.source_label}
                                                </span>
                                                {item.publication_date ? (
                                                    <span className="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-200">
                                                        Publisert {formatDate(item.publication_date, locale)}
                                                    </span>
                                                ) : null}
                                            </div>
                                            <div className="text-lg font-semibold tracking-tight text-slate-950">{item.title}</div>
                                            <div className="text-sm text-slate-500">
                                                {item.buyer_name || 'Oppdragsgiver ikke angitt'}
                                            </div>
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </section>

                    <div className="space-y-5">
                        <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                        Arbeidsliste
                                    </div>
                                    <h2 className="mt-1 text-xl font-semibold tracking-tight text-slate-950">Siste elementer</h2>
                                </div>
                                <Link
                                    href={(stats.worklist?.href) ?? '/app/notices?mode=saved'}
                                    className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                >
                                    Åpne arbeidsliste
                                </Link>
                            </div>

                            {recentWorklistItems.length === 0 ? (
                                <div className="mt-5 rounded-[20px] border border-dashed border-slate-300 bg-slate-50 px-5 py-8 text-sm leading-6 text-slate-500">
                                    Arbeidslisten er tom akkurat nå. Lagre kunngjøringer fra Anskaffelser eller inbox for å starte arbeidet.
                                </div>
                            ) : (
                                <div className="mt-5 space-y-3">
                                    {recentWorklistItems.map((item) => (
                                        <Link
                                            key={item.id}
                                            href={item.href}
                                            className="block rounded-[20px] border border-slate-200 px-4 py-4 transition hover:border-slate-300 hover:bg-slate-50"
                                        >
                                            <div className="text-base font-semibold text-slate-950">{item.title}</div>
                                            <div className="mt-1 text-sm text-slate-500">{item.buyer_name || 'Oppdragsgiver ikke angitt'}</div>
                                            <div className="mt-2 text-xs font-medium uppercase tracking-[0.12em] text-slate-400">
                                                Lagret {formatDate(item.saved_at, locale, { hour: '2-digit', minute: '2-digit' })}
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </section>

                        <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                        Watch Profiles
                                    </div>
                                    <h2 className="mt-1 text-xl font-semibold tracking-tight text-slate-950">Oppsummering</h2>
                                </div>
                                <Link
                                    href={watchProfileSummary.href ?? '/app/watch-profiles'}
                                    className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                >
                                    Gå til Watch Profiles
                                </Link>
                            </div>

                            <div className="mt-5 grid gap-3 sm:grid-cols-2">
                                <div className="rounded-[20px] bg-slate-50 px-4 py-4">
                                    <div className="text-xs font-medium uppercase tracking-[0.12em] text-slate-400">Personlige profiler</div>
                                    <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                        {formatCount(watchProfileSummary.active_personal_count ?? 0, locale)}
                                    </div>
                                </div>
                                <div className="rounded-[20px] bg-slate-50 px-4 py-4">
                                    <div className="text-xs font-medium uppercase tracking-[0.12em] text-slate-400">Avdelingsprofiler</div>
                                    <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                        {formatCount(watchProfileSummary.active_department_count ?? 0, locale)}
                                    </div>
                                </div>
                            </div>

                            {watchProfileSummary.recent_profiles?.length ? (
                                <div className="mt-5 space-y-3">
                                    {watchProfileSummary.recent_profiles.map((profile) => (
                                        <div key={profile.id} className="rounded-[20px] border border-slate-200 px-4 py-4">
                                            <div className="text-base font-semibold text-slate-950">{profile.name}</div>
                                            <div className="mt-1 text-sm text-slate-500">
                                                {profile.owner_scope === 'department' ? 'Avdeling' : 'Personlig'}: {profile.owner_reference}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="mt-5 rounded-[20px] border border-dashed border-slate-300 bg-slate-50 px-5 py-8 text-sm leading-6 text-slate-500">
                                    Ingen aktive watch profiles er tilgjengelige ennå.
                                </div>
                            )}
                        </section>

                        <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">Snarveier</div>
                            <h2 className="mt-1 text-xl font-semibold tracking-tight text-slate-950">Gå raskt videre</h2>
                            <div className="mt-5 flex flex-wrap gap-3">
                                {quickLinks.map((link) => (
                                    <Link
                                        key={link.key}
                                        href={link.href}
                                        className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                    >
                                        {link.label}
                                    </Link>
                                ))}
                            </div>
                        </section>
                    </div>
                </section>
            </div>
        </CustomerAppLayout>
    );
}
