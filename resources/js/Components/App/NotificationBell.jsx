import { useEffect, useRef, useState } from 'react';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function severityToneClassName(severity) {
    switch (severity) {
        case 'critical':
            return 'border-rose-200 bg-rose-50 text-rose-700';
        case 'warning':
            return 'border-amber-200 bg-amber-50 text-amber-800';
        default:
            return 'border-slate-200 bg-slate-50 text-slate-700';
    }
}

function formatNotificationTime(value, locale) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

/**
 * Where a notification comes from, in words.
 *
 * A case notification names its case, which is the most useful thing it can say. Everything else
 * names its area. The old fallback prettified the raw event_type, which put strings like
 * "Wiki.page Published" on screen — an internal identifier, leaked because nobody had given that
 * type a name. The domain prefix is now the fallback instead, so an event type added later reads as
 * "Wiki" rather than as its own source code.
 */
const EVENT_TYPE_LABELS = {
    'watch_profile.match_found': 'Watch list',
};

const EVENT_DOMAIN_LABELS = {
    wiki: 'Wiki',
    bid: 'Sak',
    watch_profile: 'Watch list',
};

function notificationContextLabel(notification) {
    if (notification?.saved_notice?.title) {
        return `Sak: ${notification.saved_notice.title}`;
    }

    const eventType = notification?.event_type;

    if (!eventType) {
        return 'Varsel';
    }

    if (EVENT_TYPE_LABELS[eventType]) {
        return EVENT_TYPE_LABELS[eventType];
    }

    // Never the raw type: an unnamed event says which area it is from, or nothing at all.
    return EVENT_DOMAIN_LABELS[eventType.split('.')[0]] ?? 'Varsel';
}

/**
 * How much room the panel has, measured rather than assumed.
 *
 * A static cap cannot know where the panel starts: the bell sits at the top of a wide screen and a
 * long way down a narrow one, where the app header wraps. Subtracting a fixed offset from the
 * viewport therefore overflows on exactly the screens with least room to spare. This reads the
 * panel's own position instead and gives it whatever is left below it.
 *
 * Presentation only — it measures and sets a height, and touches nothing about notifications.
 */
function useAvailableHeight(ref, isOpen) {
    const [maxHeight, setMaxHeight] = useState(null);

    useEffect(() => {
        if (! isOpen) {
            return undefined;
        }

        const measure = () => {
            const element = ref.current;

            if (! element) {
                return;
            }

            // A floor, so a cramped viewport leaves a usable panel rather than a sliver.
            setMaxHeight(Math.max(240, window.innerHeight - element.getBoundingClientRect().top - 16));
        };

        measure();
        window.addEventListener('resize', measure);

        return () => window.removeEventListener('resize', measure);
    }, [ref, isOpen]);

    return maxHeight;
}

export default function NotificationBell({
    menuRef,
    isOpen,
    locale,
    notifications,
    onToggle,
    onMarkNotification,
    onMarkAllRead,
    onDeleteNotification,
    onDeleteAllUnread,
}) {
    const panelRef = useRef(null);
    const panelMaxHeight = useAvailableHeight(panelRef, isOpen);
    const items = Array.isArray(notifications?.items) ? notifications.items : [];
    const unreadCount = Number(notifications?.unread_count ?? 0);
    const badgeLabel = unreadCount > 99 ? '99+' : String(unreadCount);
    const hasUnreadItems = unreadCount > 0;

    return (
        <div ref={menuRef} className="relative">
            <button
                type="button"
                onClick={onToggle}
                className={classNames(
                    'relative flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 shadow-sm transition',
                    isOpen
                        ? 'border-violet-300 ring-4 ring-violet-100'
                        : 'hover:border-slate-300 hover:bg-slate-50 hover:text-slate-700',
                )}
                aria-haspopup="dialog"
                aria-expanded={isOpen}
                aria-controls="app-notifications-panel"
                aria-label="Åpne varsler"
            >
                <svg className="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path
                        d="M10 3.5C8.067 3.5 6.5 5.067 6.5 7V8.2C6.5 9.41 6.12 10.58 5.42 11.56L4.55 12.78C4.2 13.27 4.17 13.93 4.47 14.45C4.77 14.97 5.32 15.3 5.91 15.3H14.09C14.68 15.3 15.23 14.97 15.53 14.45C15.83 13.93 15.8 13.27 15.45 12.78L14.58 11.56C13.88 10.58 13.5 9.41 13.5 8.2V7C13.5 5.067 11.933 3.5 10 3.5Z"
                        stroke="currentColor"
                        strokeWidth="1.6"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    />
                    <path
                        d="M8.5 15.5C8.83 16.45 9.38 17 10 17C10.62 17 11.17 16.45 11.5 15.5"
                        stroke="currentColor"
                        strokeWidth="1.6"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    />
                </svg>

                {unreadCount > 0 ? (
                    <span className="absolute -right-1 -top-1 inline-flex min-w-5 items-center justify-center rounded-full bg-violet-600 px-1.5 py-0.5 text-xs font-semibold leading-none text-white shadow-sm">
                        {badgeLabel}
                    </span>
                ) : null}
            </button>

            {isOpen ? (
                <div
                    ref={panelRef}
                    id="app-notifications-panel"
                    // The Tailwind cap is the first paint, before the measurement lands.
                    style={panelMaxHeight !== null ? { maxHeight: `${panelMaxHeight}px` } : undefined}
                    className="absolute right-0 top-[calc(100%+0.75rem)] z-[80] flex max-h-[calc(100dvh-6rem)] w-[24rem] max-w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_20px_60px_rgba(15,23,42,0.18)]"
                >
                    {/* Stays put while the list moves under it: the actions are about the whole
                        list, so scrolling them away would put them out of reach exactly when there
                        is enough to scroll. */}
                    <div className="shrink-0 border-b border-slate-200 px-4 py-4">
                        <div className="flex items-start justify-between gap-3">
                            <div className="text-base font-semibold text-slate-950">Varsler</div>
                            {/* Two different intents, so two buttons and no shared wording:
                                marking read keeps the messages and clears the badge, deleting
                                removes them. Neither touches the work in Infosenter. */}
                            <div className="flex shrink-0 flex-wrap justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={onMarkAllRead}
                                    disabled={!hasUnreadItems}
                                    className="inline-flex min-h-9 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:text-slate-400"
                                >
                                    Marker alle som lest
                                </button>
                                <button
                                    type="button"
                                    onClick={onDeleteAllUnread}
                                    disabled={!hasUnreadItems}
                                    data-testid="notification-delete-unread"
                                    className="inline-flex min-h-9 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-rose-300 hover:text-rose-700 disabled:cursor-not-allowed disabled:text-slate-400"
                                >
                                    Slett alle uleste
                                </button>
                            </div>
                        </div>
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto p-2">
                        {items.length === 0 ? (
                            <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-10 text-center">
                                <div className="text-base font-semibold text-slate-900">Ingen varsler ennå</div>
                                <p className="mt-1 text-base leading-6 text-slate-600">
                                    Her vil nye varsler vises når noe krever oppmerksomhet.
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                {items.map((notification) => (
                                    /* The card used to be one big <button>. Dismissing needs a
                                       control of its own, and a button inside a button is invalid
                                       markup, so the opening action is now a button covering the
                                       card's content and dismiss sits beside it. */
                                    <div
                                        key={notification.id}
                                        className={classNames(
                                            'relative rounded-2xl border transition',
                                            notification.is_read
                                                ? 'border-slate-200 bg-white hover:border-slate-300'
                                                : 'border-violet-200 bg-violet-50/70 hover:border-violet-300',
                                        )}
                                    >
                                    <button
                                        type="button"
                                        onClick={() => onMarkNotification(notification)}
                                        className="block w-full rounded-2xl px-3 py-3 pr-10 text-left"
                                        aria-label={`Åpne varsel: ${notification.title}`}
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span
                                                        className={classNames(
                                                            'inline-flex h-2.5 w-2.5 shrink-0 rounded-full',
                                                            notification.is_read ? 'bg-slate-300' : 'bg-violet-600',
                                                        )}
                                                        aria-hidden="true"
                                                    />
                                                    <span className="min-w-0 flex-1 text-base font-semibold text-slate-950">
                                                        {notification.title}
                                                    </span>
                                                    {!notification.is_read ? (
                                                        <span className="inline-flex items-center rounded-full bg-violet-100 px-2 py-0.5 text-xs font-semibold uppercase tracking-[0.12em] text-violet-700">
                                                            Ulest
                                                        </span>
                                                    ) : null}
                                                </div>
                                                <p className="mt-1 text-base leading-6 text-slate-600">
                                                    {notification.message}
                                                </p>
                                            </div>

                                            <span
                                                className={classNames(
                                                    'inline-flex shrink-0 rounded-full px-2.5 py-1 text-sm font-semibold ring-1 ring-inset',
                                                    severityToneClassName(notification.severity),
                                                )}
                                            >
                                                {notification.severity_label ?? notification.severity}
                                            </span>
                                        </div>

                                        <div className="mt-3 flex items-center justify-between gap-3 text-sm text-slate-600">
                                            <div className="min-w-0 truncate">
                                                {notificationContextLabel(notification)}
                                            </div>
                                            <time dateTime={notification.created_at ?? undefined} className="shrink-0">
                                                {formatNotificationTime(notification.created_at, locale)}
                                            </time>
                                        </div>
                                    </button>

                                    {/* Quiet by design: a message you want gone should be one
                                        click away, and a message you want to read should not have
                                        to compete with it. */}
                                    <button
                                        type="button"
                                        onClick={() => onDeleteNotification(notification)}
                                        data-testid="notification-delete"
                                        title="Slett varsel"
                                        aria-label={`Slett varsel: ${notification.title}`}
                                        className="absolute right-2 top-2 inline-flex h-7 w-7 items-center justify-center rounded-lg text-slate-400 transition hover:bg-white hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-300"
                                    >
                                        <svg className="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                            <path
                                                d="M6 6l8 8M14 6l-8 8"
                                                stroke="currentColor"
                                                strokeWidth="1.8"
                                                strokeLinecap="round"
                                            />
                                        </svg>
                                    </button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            ) : null}
        </div>
    );
}
