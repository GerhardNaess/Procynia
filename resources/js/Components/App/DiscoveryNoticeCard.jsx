import { router } from '@inertiajs/react';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function BuildingIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M3.5 16.5h13" strokeLinecap="round" />
            <path d="M5 16V5.5a1 1 0 0 1 1-1h4v11.5" strokeLinejoin="round" />
            <path d="M10 16V8.5a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1V16" strokeLinejoin="round" />
        </svg>
    );
}

function CalendarIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M6 3.5v2" strokeLinecap="round" />
            <path d="M14 3.5v2" strokeLinecap="round" />
            <path d="M4 7h12" strokeLinecap="round" />
            <rect x="4" y="5" width="12" height="11" rx="2" />
        </svg>
    );
}

function ClockIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <circle cx="10" cy="10" r="6.5" />
            <path d="M10 7v3.5l2.5 1.5" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
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

function summarizeText(value) {
    const trimmed = (value ?? '').trim();

    if (trimmed === '') {
        return 'Kort beskrivelse kommer i neste steg.';
    }

    return trimmed;
}

function statusBadge(status, deadline) {
    if (status) {
        return {
            label: status,
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    if (!deadline) {
        return {
            label: 'Kunngjøring',
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    if (new Date(deadline) <= new Date()) {
        return {
            label: 'Utgått',
            className: 'bg-rose-100 text-rose-700 ring-rose-200',
        };
    }

    return {
        label: 'Aktiv',
        className: 'bg-violet-100 text-violet-700 ring-violet-200',
    };
}

function worklistPayloadFromNotice(notice) {
    return {
        notice_id: notice.notice_id,
        title: notice.title,
        buyer_name: notice.buyer_name,
        external_url: notice.external_url,
        summary: notice.summary,
        publication_date: notice.publication_date,
        deadline: notice.deadline,
        status: notice.status,
        cpv_code: notice.cpv_code,
    };
}

export default function DiscoveryNoticeCard({
    notice,
    locale,
    canSaveToWorklist = false,
    saveButtonLabel = 'Lagre',
    deleteAction = null,
    actions = null,
    provenanceBadges = [],
}) {
    const statusTag = statusBadge(notice.status, notice.deadline);
    const deadlineBadge = {
        label: `Frist ${formatDate(notice.deadline, locale)}`,
        className: 'bg-slate-100 text-slate-700 ring-slate-200',
    };
    const canRenderSaveAction = canSaveToWorklist && Boolean(notice.notice_id) && Boolean(notice.title);
    const canDelete = Boolean(deleteAction?.href);
    const hasActions = canRenderSaveAction || canDelete || actions;

    const saveNoticeToWorklist = () => {
        if (!canRenderSaveAction || notice.is_saved) {
            return;
        }

        router.post('/app/notices/save', worklistPayloadFromNotice(notice), {
            preserveScroll: true,
        });
    };

    const deleteNoticeFromInbox = () => {
        if (!canDelete) {
            return;
        }

        const confirmMessage = deleteAction.confirmMessage ?? 'Delete this inbox item?';

        if (typeof window !== 'undefined' && !window.confirm(confirmMessage)) {
            return;
        }

        router.delete(deleteAction.href, {
            preserveScroll: true,
        });
    };

    return (
        <article className="rounded-[20px] border border-slate-200 bg-white p-5 shadow-[0_8px_22px_rgba(15,23,42,0.04)] transition hover:border-slate-300">
            <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0 flex-1">
                    {provenanceBadges.length > 0 ? (
                        <div className="mb-3 flex flex-wrap gap-2">
                            {provenanceBadges.map((badge) => (
                                <span
                                    key={badge.key}
                                    className={classNames(
                                        'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset',
                                        badge.className || 'bg-amber-50 text-amber-800 ring-amber-200',
                                    )}
                                >
                                    {badge.label}
                                </span>
                            ))}
                        </div>
                    ) : null}

                    <div className="flex flex-wrap items-center gap-2.5">
                        <h2 className="text-[1.7rem] font-semibold tracking-tight text-slate-950">
                            {notice.title}
                        </h2>
                    </div>

                    <div className="mt-1.5 flex flex-wrap items-center gap-4 text-sm text-slate-600">
                        <span className="inline-flex items-center gap-2">
                            <BuildingIcon className="h-4 w-4 text-slate-400" />
                            {notice.buyer_name || 'Oppdragsgiver ikke angitt'}
                        </span>
                    </div>

                    <p className="mt-3 max-w-4xl text-sm leading-7 text-slate-600">
                        {summarizeText(notice.summary)}
                    </p>

                    <div className="mt-4 flex flex-wrap gap-2">
                        <span className="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-200">
                            <CalendarIcon className="h-3.5 w-3.5" />
                            Publisert {formatDate(notice.publication_date, locale)}
                        </span>
                        <span
                            className={classNames(
                                'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset',
                                deadlineBadge.className,
                            )}
                        >
                            <ClockIcon className="h-3.5 w-3.5" />
                            {deadlineBadge.label}
                        </span>
                        <span className="inline-flex items-center gap-2 rounded-full bg-blue-100 px-3 py-1.5 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-200">
                            {notice.cpv_code ? `CPV: ${notice.cpv_code}` : 'Kategori: Doffin-kunngjøring'}
                        </span>
                        <span
                            className={classNames(
                                'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset',
                                statusTag.className,
                            )}
                        >
                            {statusTag.label}
                        </span>
                    </div>
                </div>

                {hasActions ? (
                    <div className="flex shrink-0 flex-row gap-3 lg:flex-col">
                        {canRenderSaveAction ? (
                            <button
                                type="button"
                                onClick={saveNoticeToWorklist}
                                disabled={notice.is_saved}
                                className={classNames(
                                    'inline-flex min-w-[132px] items-center justify-center rounded-xl border px-4 py-2.5 text-sm font-semibold transition',
                                    notice.is_saved
                                        ? 'cursor-not-allowed border-emerald-200 bg-emerald-50 text-emerald-700'
                                        : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950',
                                )}
                            >
                                {notice.is_saved ? 'Lagret' : saveButtonLabel}
                            </button>
                        ) : null}
                        {canDelete ? (
                            <button
                                type="button"
                                onClick={deleteNoticeFromInbox}
                                className="inline-flex min-w-[132px] items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100"
                            >
                                {deleteAction.label ?? 'Slett'}
                            </button>
                        ) : null}
                        {actions}
                    </div>
                ) : null}
            </div>
        </article>
    );
}
