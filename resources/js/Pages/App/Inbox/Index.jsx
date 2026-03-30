import { Link, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import DiscoveryNoticeCard from '../../../Components/App/DiscoveryNoticeCard';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function formatDateTime(value, locale) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

export default function WatchProfileInboxIndex({
    scope,
    title,
    description,
    records,
    switchLinks,
}) {
    const { locale = 'nb-NO', translations } = usePage().props;

    return (
        <CustomerAppLayout title={title} showPageTitle={false}>
            <div className="space-y-6">
                <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div className="space-y-1.5">
                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                Watch Profile Inbox
                            </div>
                            <h1 className="text-3xl font-semibold tracking-tight text-slate-950">{title}</h1>
                            <p className="max-w-3xl text-sm leading-6 text-slate-500">{description}</p>
                        </div>

                        <div className="flex flex-wrap gap-2">
                            <Link
                                href={switchLinks.user}
                                className={classNames(
                                    'inline-flex min-h-11 items-center justify-center rounded-xl border px-4 py-2.5 text-sm font-semibold transition',
                                    scope === 'user'
                                        ? 'border-violet-200 bg-violet-50 text-violet-700'
                                        : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950',
                                )}
                            >
                                Min innboks
                            </Link>
                            {switchLinks.department ? (
                                <Link
                                    href={switchLinks.department}
                                    className={classNames(
                                        'inline-flex min-h-11 items-center justify-center rounded-xl border px-4 py-2.5 text-sm font-semibold transition',
                                        scope === 'department'
                                            ? 'border-violet-200 bg-violet-50 text-violet-700'
                                            : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950',
                                    )}
                                >
                                    Avdelingsinnboks
                                </Link>
                            ) : null}
                        </div>
                    </div>
                </section>

                <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="mb-5 flex items-center justify-between gap-3">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                {scope === 'user' ? 'Personlige treff' : 'Avdelingstreff'}
                            </div>
                            <div className="mt-1 text-[1.7rem] font-semibold tracking-tight text-slate-950">
                                {records.length} {records.length === 1 ? 'treff' : 'treff'}
                            </div>
                        </div>
                    </div>

                    {records.length === 0 ? (
                        <div className="rounded-[22px] border border-dashed border-slate-300 bg-slate-50 px-6 py-14 text-center">
                            <div className="text-lg font-semibold text-slate-900">Ingen treff i innboksen ennå</div>
                            <p className="mt-2 text-sm text-slate-500">
                                Nattlig discovery vil legge nye Doffin-treff her når en aktiv watch profile finner noe relevant.
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-3.5">
                            {records.map((record) => (
                                <DiscoveryNoticeCard
                                    key={record.id}
                                    notice={record}
                                    locale={locale}
                                    canSaveToWorklist
                                    saveButtonLabel={translations?.frontend?.save_button ?? 'Lagre'}
                                    deleteAction={{
                                        href: record.delete_url,
                                        label: 'Slett',
                                        confirmMessage: 'Vil du slette dette treffet fra innboksen?',
                                    }}
                                    provenanceBadges={[
                                        {
                                            key: `score-${record.id}`,
                                            label: `Relevansscore ${record.relevance_score ?? 0}`,
                                            className: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
                                        },
                                        {
                                            key: `date-${record.id}`,
                                            label: `Fanget ${formatDateTime(record.discovered_at, locale)}`,
                                            className: 'bg-amber-50 text-amber-900 ring-amber-200',
                                        },
                                        {
                                            key: `profile-${record.id}`,
                                            label: `Watch Profile: ${record.watch_profile_name || `#${record.watch_profile_id}`}`,
                                            className: 'bg-sky-50 text-sky-800 ring-sky-200',
                                        },
                                    ]}
                                    actions={record.external_url ? (
                                        <a
                                            href={record.external_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex min-w-[108px] items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                        >
                                            Åpne i Doffin
                                        </a>
                                    ) : null}
                                />
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </CustomerAppLayout>
    );
}
