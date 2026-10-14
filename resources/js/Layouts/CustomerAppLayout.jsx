import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import ActionDialog from '../Components/App/ActionDialog';
import NotificationBell from '../Components/App/NotificationBell';
import ModuleSidebar from '../Components/App/ModuleSidebar';
import Breadcrumbs from '../Components/App/Breadcrumbs';
import { resolveNavigation } from '../Support/appNavigation';
import { readLastAiCaseId, writeLastAiCaseId } from '../Support/aiWorkspaceState';
import { readModuleSidebarCollapsed, writeModuleSidebarCollapsed } from '../Support/moduleSidebarState';
import { liveRegion } from '../Support/announcer';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function portalToLiveRegion(element) {
    const region = liveRegion('toast');

    return region ? createPortal(element, region) : element;
}

function emptyNotificationsState() {
    return {
        unread_count: 0,
        limit: 10,
        refresh_url: null,
        mark_all_read_url: null,
        items: [],
    };
}

/**
 * How often the bell re-reads itself while the person stays on one page.
 *
 * Notifications are shared on every Inertia visit, so navigating already refreshes them — this is
 * only for the person who is reading, writing or thinking and not clicking anything. A minute is
 * slow enough to cost nothing and fast enough that "somebody sent you a page to review" does not
 * wait for their next navigation.
 */
const NOTIFICATION_POLL_MS = 60000;

/**
 * One row of navigation pills.
 *
 * Rendered twice — once as the header's module navigation, once as the page's own level — because
 * the two are the same control at different depths. Giving the lower one its own markup would have
 * let the pill, the active state and the disabled state drift apart between them.
 */
function NavigationRow({ items, activeKey, disabledHint = '' }) {
    return (
        <nav className="flex flex-wrap items-center gap-2">
            {items.map((item) => {
                const isActive = activeKey === item.key;
                const classes = classNames(
                    'rounded-xl px-3 py-2 text-base font-medium transition',
                    isActive
                        ? 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-200'
                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                );

                if (!item.href) {
                    return (
                        <span
                            key={item.key}
                            className={classNames('rounded-xl px-3 py-2 text-base font-medium cursor-default text-slate-500 select-none')}
                            aria-current={isActive ? 'page' : undefined}
                            aria-disabled="true"
                            title={disabledHint || undefined}
                        >
                            {item.label}
                        </span>
                    );
                }

                if (item.isAnchor) {
                    return (
                        <a
                            key={item.key}
                            href={item.href}
                            className={classes}
                            aria-current={isActive ? 'page' : undefined}
                        >
                            {item.label}
                        </a>
                    );
                }

                return (
                    <Link
                        key={item.key}
                        href={item.href}
                        className={classes}
                        aria-current={isActive ? 'page' : undefined}
                    >
                        {item.label}
                    </Link>
                );
            })}
        </nav>
    );
}

/**
 * `workView` names a work view the page has opened with one of its own buttons — «Registrer
 * leverandør», «Ny revisjon», «Rediger» — while it is open, and null otherwise. It is the page's
 * own state handed to the shared navigation, so the trail can end on the view even where the view
 * has no URL. A permanent form on the page and a dialog over it are not work views.
 */
export default function CustomerAppLayout({ children, title, showPageTitle = true, workView = null }) {
    const page = usePage();
    const { appName, auth, flash, translations } = page.props;
    const navigation = translations?.navigation ?? {};
    const modules = navigation.modules ?? {};
    // Which technical modules this customer holds, resolved server-side from their package
    // entitlements. The rail presents it; the routes enforce it.
    const activeModules = page.props.entitlements?.modules ?? [];
    // What the customer's own roles let this person do, shared on every request. The rail uses it
    // to stop offering a module they have no permission in; the routes enforce the same answer.
    const userPermissions = page.props.access?.permissions ?? [];
    const [showSuccess, setShowSuccess] = useState(true);
    const [isUserMenuOpen, setIsUserMenuOpen] = useState(false);
    const [isNotificationsOpen, setIsNotificationsOpen] = useState(false);
    const [isDeleteUnreadOpen, setIsDeleteUnreadOpen] = useState(false);
    const [notificationState, setNotificationState] = useState(page.props.notifications ?? emptyNotificationsState());
    // Read once at mount rather than in an effect: the app renders client-side only, so there is
    // no server pass to disagree with and no frame where the rail opens and then snaps shut.
    const [sidebarCollapsed, setSidebarCollapsed] = useState(readModuleSidebarCollapsed);
    const userMenuRef = useRef(null);
    const notificationsMenuRef = useRef(null);
    const currentUrl = page.url ?? '';
    const user = auth?.user;
    const locale = page.props.locale ?? 'nb-NO';
    const userName = user?.name ?? '';
    const userEmail = user?.email ?? '';
    const userBidRoleLabel = user?.bid_role_label ?? '';
    const userInitial = userName.trim().charAt(0).toUpperCase() || 'P';
    const environmentHref = user?.can_manage_customer_users ? '/app/customer-environment' : null;
    const billingHref = user?.can_manage_customer_billing ? '/app/billing' : null;

    /**
     * Every navigation level on the page — the rail's module and workspace, the header's module
     * navigation, the page-level row and the breadcrumb trail — from one resolver
     * (Support/appNavigation.js), read off the URL and the page's own props. The page's `title` is
     * what names the object at the end of the trail, so no page describes its own breadcrumb.
     */
    const {
        area: activeMainArea,
        moduleKey: activeModule,
        workspaceKey: activeWorkspace,
        currentAiCaseId,
        aiWorkHref,
        aiInstructionsHref,
        moduleNavigation,
        activeModuleNavigationKey,
        pageNavigation,
        activePageNavigationKey,
        breadcrumbs,
    } = resolveNavigation({ url: currentUrl, props: page.props, title, workView, lastStoredAiCaseId: readLastAiCaseId() });
    const aiCaseNavigationHint = activeMainArea === 'ai' && aiWorkHref === null && aiInstructionsHref === null
        ? (String(locale).toLowerCase().startsWith('en')
            ? 'Open a case first to unlock Work in progress and AI instructions.'
            : 'Velg en sak først for å åpne I arbeid og AI instrukser.')
        : '';

    /**
     * Ask Wiki, in the top bar beside the bell. The magnifying glass is the whole affordance, so
     * the label is carried by title/aria-label instead.
     */
    const askWikiNavigation = {
        key: 'wiki-ask',
        label: translations.wiki?.ask_nav ?? 'Spør Wiki',
        href: '/app/wiki/ask',
    };

    const toggleSidebarCollapsed = () => {
        setSidebarCollapsed((current) => {
            const next = ! current;

            writeModuleSidebarCollapsed(next);

            return next;
        });
    };

    useEffect(() => {
        if (!flash?.success) {
            return;
        }

        setShowSuccess(true);

        const timer = window.setTimeout(() => {
            setShowSuccess(false);
        }, 3000);

        return () => window.clearTimeout(timer);
    }, [flash?.success]);

    useEffect(() => {
        setIsUserMenuOpen(false);
        setIsNotificationsOpen(false);
    }, [currentUrl]);

    useEffect(() => {
        setNotificationState(page.props.notifications ?? emptyNotificationsState());
    }, [page.props.notifications]);

    // Re-read the bell on a timer and whenever the tab comes back to the front. Read-only, and
    // never while the tab is hidden: a backgrounded session should cost nothing, and a poll must
    // never be what marks something as read.
    useEffect(() => {
        const refreshUrl = notificationState?.refresh_url;

        if (! refreshUrl) {
            return undefined;
        }

        let cancelled = false;

        const refresh = async () => {
            if (document.visibilityState === 'hidden') {
                return;
            }

            try {
                const response = await window.axios.get(refreshUrl);

                if (! cancelled && response?.data?.notifications) {
                    setNotificationState(response.data.notifications);
                }
            } catch {
                // A failed poll is not worth interrupting anybody over; the next one will do.
            }
        };

        const timer = window.setInterval(refresh, NOTIFICATION_POLL_MS);
        window.addEventListener('focus', refresh);
        document.addEventListener('visibilitychange', refresh);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
            window.removeEventListener('focus', refresh);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, [notificationState?.refresh_url]);

    useEffect(() => {
        if (currentAiCaseId === null || currentAiCaseId === undefined) {
            return;
        }

        writeLastAiCaseId(currentAiCaseId);
    }, [currentAiCaseId]);

    useEffect(() => {
        if (!isUserMenuOpen && !isNotificationsOpen) {
            return;
        }

        const handlePointerDown = (event) => {
            const clickedUserMenu = userMenuRef.current && userMenuRef.current.contains(event.target);
            const clickedNotificationsMenu = notificationsMenuRef.current && notificationsMenuRef.current.contains(event.target);

            if (!clickedUserMenu && !clickedNotificationsMenu) {
                setIsUserMenuOpen(false);
                setIsNotificationsOpen(false);
            }
        };

        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                setIsUserMenuOpen(false);
                setIsNotificationsOpen(false);
            }
        };

        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [isNotificationsOpen, isUserMenuOpen]);

    const toggleNotifications = () => {
        setIsUserMenuOpen(false);
        setIsNotificationsOpen((value) => !value);
    };

    const logout = () => {
        setIsUserMenuOpen(false);
        router.post('/logout');
    };

    const markNotificationAsRead = async (notification) => {
        if (!notification?.mark_read_url) {
            return;
        }

        try {
            const response = await window.axios.patch(notification.mark_read_url);
            const nextNotificationState = response?.data?.notifications;

            if (nextNotificationState) {
                setNotificationState(nextNotificationState);
            }

            if (notification.target_url) {
                setIsNotificationsOpen(false);
                router.visit(notification.target_url, {
                    preserveScroll: true,
                });
            }
        } catch (error) {
            // Keep the current panel state if the canonical read request fails.
        }
    };

    /**
     * Remove one message. Only the message: whatever work it announced is recorded in the domain
     * and is unaffected, so an open Wiki review or QA assignment stays on the Info Center list.
     *
     * The server returns the whole panel afterwards, so the card disappearing and the unread badge
     * dropping are the same fact rather than two guesses that could disagree.
     */
    const deleteNotification = async (notification) => {
        if (!notification?.delete_url) {
            return;
        }

        try {
            const response = await window.axios.delete(notification.delete_url);

            if (response?.data?.notifications) {
                setNotificationState(response.data.notifications);
            }
        } catch (error) {
            // A message that is already gone is the state the click asked for; leave the panel be.
        }
    };

    const deleteAllUnreadNotifications = async () => {
        setIsDeleteUnreadOpen(false);

        if (!notificationState?.delete_unread_url) {
            return;
        }

        try {
            const response = await window.axios.delete(notificationState.delete_unread_url);

            if (response?.data?.notifications) {
                setNotificationState(response.data.notifications);
            }
        } catch (error) {
            // Same: nothing to clear is not a failure worth interrupting anybody over.
        }
    };

    const markAllNotificationsAsRead = async () => {
        if (!notificationState?.mark_all_read_url) {
            return;
        }

        try {
            const response = await window.axios.patch(notificationState.mark_all_read_url);
            const nextNotificationState = response?.data?.notifications;

            if (nextNotificationState) {
                setNotificationState(nextNotificationState);
            }
        } catch (error) {
            // Keep the current panel state if the canonical read-all request fails.
        }
    };

    return (
        <>
            <Head title={title ? `${title} · ${appName}` : appName} />
            <div className="min-h-screen bg-[#f6f7fb] text-slate-900">
                <header className="sticky top-0 z-[60] border-b border-slate-200/80 bg-white/95 backdrop-blur-sm shadow-[0_1px_0_rgba(15,23,42,0.04)]">
                    <div className="mx-auto max-w-[1600px] px-4 py-3 sm:px-6 lg:px-8">
                        <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:gap-6">
                                <Link href="/app/dashboard" className="flex items-center">
                                    <img
                                        src="/images/procynia_logo.png?v=2"
                                        alt="Procynia"
                                        style={{ height: '40px', width: 'auto', maxWidth: '180px' }}
                                        className="object-contain"
                                    />
                                </Link>

                            </div>

                            {/* Search, the bell and the user, in that order. Oppfølging is not here: it is
                                a place you work, so it is on the rail beside Hjem. Allowed to wrap on
                                a phone, where the user button would otherwise be pushed off the
                                right edge. */}
                            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 lg:flex-nowrap lg:justify-end">
                                {/* Spør Wiki reads the Wiki, so it goes where the Wiki goes: a
                                    person without wiki.view is not offered a search over a Wiki
                                    the rail is not offering them either. */}
                                {userPermissions.includes('wiki.view') && (
                                <Link
                                    href={askWikiNavigation.href}
                                    title={askWikiNavigation.label}
                                    aria-label={askWikiNavigation.label}
                                    aria-current={activeMainArea === askWikiNavigation.key ? 'page' : undefined}
                                    className={classNames(
                                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl transition',
                                        activeMainArea === askWikiNavigation.key
                                            ? 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-200'
                                            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                                    )}
                                >
                                    <svg className="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                        <circle cx="9" cy="9" r="5.25" stroke="currentColor" strokeWidth="1.75" />
                                        <path d="M13 13L17 17" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" />
                                    </svg>
                                </Link>
                                )}

                                <NotificationBell
                                    menuRef={notificationsMenuRef}
                                    hint={translations.frontend.notifications_hint
                                        ?? 'Nye varsler du ikke har lest'}
                                    isOpen={isNotificationsOpen}
                                    locale={locale}
                                    notifications={notificationState}
                                    onToggle={toggleNotifications}
                                    onMarkNotification={markNotificationAsRead}
                                    onMarkAllRead={markAllNotificationsAsRead}
                                    onDeleteNotification={deleteNotification}
                                    onDeleteAllUnread={() => setIsDeleteUnreadOpen(true)}
                                    domainLabels={{
                                        // Notification prefixes → the module names «Mine oppgaver» uses.
                                        supplier: translations.info_center_page?.my_tasks?.modules?.supplier,
                                        risk: translations.info_center_page?.my_tasks?.modules?.risk,
                                        improvement: translations.info_center_page?.my_tasks?.modules?.improvements,
                                        compliance: translations.info_center_page?.my_tasks?.modules?.compliance,
                                        quality: translations.info_center_page?.my_tasks?.modules?.quality,
                                        objective: translations.info_center_page?.my_tasks?.modules?.objectives,
                                        management_review: translations.info_center_page?.my_tasks?.modules?.management_review,
                                    }}
                                />

                                <div ref={userMenuRef} className="relative">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setIsNotificationsOpen(false);
                                            setIsUserMenuOpen((value) => !value);
                                        }}
                                        className={classNames(
                                            'flex max-w-[240px] items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-left shadow-sm transition',
                                            isUserMenuOpen
                                                ? 'border-violet-300 ring-4 ring-violet-100'
                                                : 'hover:border-slate-300 hover:bg-slate-50',
                                        )}
                                        aria-haspopup="menu"
                                        aria-expanded={isUserMenuOpen}
                                    >
                                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-base font-semibold text-slate-700">
                                            {userInitial}
                                        </span>
                                        <div className="min-w-0">
                                            <div className="truncate text-base font-semibold text-slate-900">{userName}</div>
                                        </div>
                                        <svg
                                            className={classNames(
                                                'h-4 w-4 shrink-0 text-slate-400 transition-transform',
                                                isUserMenuOpen ? 'rotate-180' : '',
                                            )}
                                            viewBox="0 0 20 20"
                                            fill="none"
                                            aria-hidden="true"
                                        >
                                            <path
                                                d="M5 7.5L10 12.5L15 7.5"
                                                stroke="currentColor"
                                                strokeWidth="1.75"
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                            />
                                        </svg>
                                    </button>

                                    {isUserMenuOpen ? (
                                        <div className="absolute right-0 top-[calc(100%+0.75rem)] z-[80] w-[320px] max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_20px_60px_rgba(15,23,42,0.18)]">
                                            <div className="space-y-1 border-b border-slate-200 px-4 py-4">
                                                <div className="text-base font-semibold text-slate-950">{userName}</div>
                                                <div className="break-words text-base text-slate-600">{userEmail}</div>
                                                {userBidRoleLabel ? (
                                                    <div className="pt-1 text-base font-medium text-slate-600">
                                                        {userBidRoleLabel}
                                                    </div>
                                                ) : null}
                                            </div>
                                            {/* Administration, not workflow. Both were main-menu
                                                items; both are gated by exactly the capability
                                                that gated them there, so a user who could not see
                                                them before cannot see them here either. */}
                                            {environmentHref || billingHref ? (
                                                <div className="border-b border-slate-200 p-2" data-testid="user-menu-admin">
                                                    {environmentHref ? (
                                                        <Link
                                                            href={environmentHref}
                                                            className="flex w-full items-center rounded-xl px-3 py-2.5 text-base font-medium text-slate-700 transition hover:bg-slate-100 hover:text-slate-950"
                                                        >
                                                            {navigation.customer_environment ?? 'Kundemiljø'}
                                                        </Link>
                                                    ) : null}
                                                    {billingHref ? (
                                                        <Link
                                                            href={billingHref}
                                                            className="flex w-full items-center rounded-xl px-3 py-2.5 text-base font-medium text-slate-700 transition hover:bg-slate-100 hover:text-slate-950"
                                                        >
                                                            {translations.billing?.nav ?? 'Abonnement'}
                                                        </Link>
                                                    ) : null}
                                                </div>
                                            ) : null}

                                            <div className="p-2">
                                                <button
                                                    type="button"
                                                    onClick={logout}
                                                    className="flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-base font-medium text-slate-700 transition hover:bg-slate-100 hover:text-slate-950"
                                                >
                                                    <span>{translations.common.logout}</span>
                                                    <svg className="h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                                        <path
                                                            d="M12.5 5L16.5 10L12.5 15"
                                                            stroke="currentColor"
                                                            strokeWidth="1.75"
                                                            strokeLinecap="round"
                                                            strokeLinejoin="round"
                                                        />
                                                        <path
                                                            d="M16 10H7.5"
                                                            stroke="currentColor"
                                                            strokeWidth="1.75"
                                                            strokeLinecap="round"
                                                            strokeLinejoin="round"
                                                        />
                                                        <path
                                                            d="M10 4.5H6.5C5.39543 4.5 4.5 5.39543 4.5 6.5V13.5C4.5 14.6046 5.39543 15.5 6.5 15.5H10"
                                                            stroke="currentColor"
                                                            strokeWidth="1.75"
                                                            strokeLinecap="round"
                                                            strokeLinejoin="round"
                                                        />
                                                    </svg>
                                                </button>
                                            </div>
                                        </div>
                                    ) : null}
                                </div>
                            </div>
                        </div>

                        {/* The module navigation: what the selected module contains. Anbud's work
                            areas and Wiki's tabs sit in exactly the same place, because they are
                            the same level — the first step inside a module. */}
                        {moduleNavigation.length > 0 ? (
                            <div className="mt-2 border-t border-slate-200/80 pt-2" data-testid="module-navigation">
                                <NavigationRow
                                    items={moduleNavigation}
                                    activeKey={activeModuleNavigationKey}
                                />
                            </div>
                        ) : null}
                    </div>
                </header>

                {/* The rail and the page are one row from lg up, stacked below it. The rail is
                    sticky under the header on wide screens so the module structure stays visible
                    while a long page scrolls; on a phone it simply sits above the page, because
                    the modules are the only navigation left and must not become unreachable. */}
                <div className="mx-auto flex max-w-[1600px] flex-col gap-6 px-4 py-7 sm:px-6 lg:flex-row lg:px-8">
                    <aside
                        data-testid="module-rail"
                        className={classNames(
                            'w-full shrink-0 transition-[width] duration-200',
                            sidebarCollapsed ? 'lg:w-[4.5rem]' : 'lg:w-64',
                        )}
                    >
                        <div className="lg:sticky lg:top-24">
                            <ModuleSidebar
                                modules={modules}
                                activeModules={activeModules}
                                permissions={userPermissions}
                                activeKey={activeModule}
                                activeWorkspace={activeWorkspace}
                                collapsed={sidebarCollapsed}
                                onToggleCollapsed={toggleSidebarCollapsed}
                            />
                        </div>
                    </aside>

                    <main className="min-w-0 flex-1">
                    {/* Where this page sits — above everything the page draws, its title included. */}
                    <Breadcrumbs
                        items={breadcrumbs}
                        ariaLabel={navigation.breadcrumbs?.aria ?? 'Brødsmulesti'}
                        showAllLabel={navigation.breadcrumbs?.show_all ?? 'Vis hele stien'}
                    />

                    {/* The level inside an area — Live søk, Varsler and Watch lists inside
                        Kunngjøringer, and the equivalents inside Saksliste and Besvarelse. It sits
                        on the page rather than in the header because it belongs to one area, not
                        to the module, and the header already names which area that is. */}
                    {pageNavigation.length > 0 ? (
                        <div
                            data-testid="page-navigation"
                            className="mb-6 rounded-2xl border border-slate-200/80 bg-white px-3 py-2 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        >
                            <NavigationRow
                                items={pageNavigation}
                                activeKey={activePageNavigationKey}
                                disabledHint={aiCaseNavigationHint}
                            />
                            {aiCaseNavigationHint !== '' ? (
                                <p className="mt-2 px-1 pb-1 text-base leading-6 text-slate-600">
                                    {aiCaseNavigationHint}
                                </p>
                            ) : null}
                        </div>
                    ) : null}

                    {/* The success toast is drawn into the app-wide live region (Support/announcer.js),
                        which outlives this layout: a toast mounted here would be new on every page,
                        and a screen reader does not read a polite region that arrives with its text. */}
                    {flash?.success && showSuccess ? portalToLiveRegion(
                        <div className="fixed left-1/2 top-4 z-50 -translate-x-1/2 rounded-2xl border border-emerald-200 bg-emerald-50 px-6 py-3 text-base text-emerald-800 shadow-lg">
                            {flash.success}
                        </div>,
                    ) : null}
                    {/* role="alert": a rejected action is announced the moment the message appears. */}
                    {flash?.error ? (
                        <div role="alert" className="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-base text-rose-800 shadow-sm">
                            {flash.error}
                        </div>
                    ) : null}
                    {flash?.warning ? (
                        <div
                            role="status"
                            className="mb-6 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-base text-amber-900 shadow-sm"
                            data-testid="flash-warning"
                        >
                            {flash.warning}
                        </div>
                    ) : null}

                    {title && showPageTitle ? (
                        <div className="mb-8">
                            <h1 className="text-3xl font-semibold tracking-tight text-slate-950">{title}</h1>
                        </div>
                    ) : null}

                    {children}
                    </main>
                </div>

                <footer className="bg-transparent">
                    <div className="mx-auto max-w-[1600px] px-4 py-8 text-center text-base text-slate-500 sm:px-6 lg:px-8">
                        {translations.frontend.customer_footer}
                    </div>
                </footer>
            </div>

            {/* Bulk delete is asked about; a single one is not. Deleting many messages at once is
                worth a moment's pause, and the sentence says the one thing people worry about —
                that clearing the bell might also clear their work. It does not. */}
            <ActionDialog
                isOpen={isDeleteUnreadOpen}
                onClose={() => setIsDeleteUnreadOpen(false)}
                titleId="notifications-delete-unread-title"
            >
                <h2
                    id="notifications-delete-unread-title"
                    className="text-xl font-semibold tracking-tight text-slate-950"
                >
                    Slett alle uleste varsler?
                </h2>
                <p className="mt-2 text-base leading-6 text-slate-600">
                    Dette fjerner alle uleste varsler fra listen. Leste varsler beholdes, og
                    eventuelle oppgaver under Oppfølging påvirkes ikke.
                </p>

                <div className="mt-6 flex flex-wrap gap-3">
                    <button
                        type="button"
                        onClick={deleteAllUnreadNotifications}
                        data-testid="notification-delete-unread-confirm"
                        className="inline-flex min-h-10 items-center justify-center rounded-xl bg-rose-600 px-4 py-2 text-base font-semibold text-white shadow-sm transition hover:bg-rose-700"
                    >
                        Slett varsler
                    </button>
                    <button
                        type="button"
                        onClick={() => setIsDeleteUnreadOpen(false)}
                        className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                    >
                        Avbryt
                    </button>
                </div>
            </ActionDialog>
        </>
    );
}
