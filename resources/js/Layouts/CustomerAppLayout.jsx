import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ActionDialog from '../Components/App/ActionDialog';
import ControlHint from '../Components/App/ControlHint';
import NotificationBell from '../Components/App/NotificationBell';
import ModuleSidebar from '../Components/App/ModuleSidebar';
import { activeModuleKey, activeWorkspaceKey } from '../Support/appModules';
import { readLastAiCaseId, writeLastAiCaseId } from '../Support/aiWorkspaceState';
import { readModuleSidebarCollapsed, writeModuleSidebarCollapsed } from '../Support/moduleSidebarState';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function buildHref(path, query = {}) {
    const params = new URLSearchParams();

    Object.entries(query).forEach(([key, value]) => {
        if (value === null || value === undefined || value === '') {
            return;
        }

        params.set(key, value);
    });

    const search = params.toString();

    return search === '' ? path : `${path}?${search}`;
}

function splitUrl(url) {
    const [pathname, search = ''] = String(url ?? '').split('?');

    return {
        pathname: pathname || '',
        searchParams: new URLSearchParams(search),
    };
}

function formatMenuCount(value) {
    const normalized = Number(value ?? 0);

    return Number.isFinite(normalized) ? normalized : 0;
}

function withMenuCount(label, count) {
    return `${label} (${formatMenuCount(count)})`;
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

export default function CustomerAppLayout({ children, title, showPageTitle = true }) {
    const page = usePage();
    const { appName, auth, flash, translations, worklist } = page.props;
    const navigation = translations?.navigation ?? {};
    const modules = navigation.modules ?? {};
    // Which technical modules this customer holds, resolved server-side from their package
    // entitlements. The rail presents it; the routes enforce it.
    const activeModules = page.props.entitlements?.modules ?? [];
    // What the customer's own roles let this person do, shared on every request. The rail uses it
    // to stop offering a module they have no permission in; the routes enforce the same answer.
    const userPermissions = page.props.access?.permissions ?? [];
    const tw = translations?.wiki ?? {};
    const tq = translations?.quality ?? {};
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
    const { pathname, searchParams } = splitUrl(currentUrl);
    const noticeMode = searchParams.get('mode') ?? 'live';
    const noticeTab = searchParams.get('tab') ?? (noticeMode === 'live' ? 'live' : null);
    const wikiTab = searchParams.get('tab') ?? 'pages';
    // Which of Kvalitet's main areas the page is in. The index carries it in `?tab=`; an item page
    // uses `?tab=` for its own level below (Dokument / Flyt), so there the area follows the item's
    // type — a process belongs under Prosesser, a control under Kontroller, the rest under Oversikt.
    const qualityTab = (() => {
        if (pathname.startsWith('/app/quality/items/')) {
            const type = page.props.item?.quality_type;

            return type === 'process' ? 'processes' : (type === 'control' ? 'controls' : 'overview');
        }

        return searchParams.get('tab') ?? 'overview';
    })();
    const currentAiCaseId = page.props.case?.id ?? null;
    const firstAvailableAiCaseId = page.props.analysisCases?.[0]?.id
        ? String(page.props.analysisCases[0].id)
        : null;
    const lastStoredAiCaseId = readLastAiCaseId();
    const lastStoredIdIsVisible = lastStoredAiCaseId !== null
        && Array.isArray(page.props.analysisCases)
        && page.props.analysisCases.some((c) => String(c.id) === lastStoredAiCaseId);
    const rememberedAiCaseId = currentAiCaseId !== null && currentAiCaseId !== undefined
        ? String(currentAiCaseId)
        : lastStoredIdIsVisible
            ? lastStoredAiCaseId
            : (firstAvailableAiCaseId ?? lastStoredAiCaseId);
    const aiWorkHref = currentAiCaseId !== null && currentAiCaseId !== undefined
        ? `/app/ai/${currentAiCaseId}`
        : rememberedAiCaseId !== null
            ? `/app/ai/${rememberedAiCaseId}`
            : null;
    const aiInstructionsHref = currentAiCaseId !== null && currentAiCaseId !== undefined
        ? `/app/ai/${currentAiCaseId}/instructions`
        : rememberedAiCaseId !== null
            ? `/app/ai/${rememberedAiCaseId}/instructions`
            : null;
    const watchProfilesHref = user?.can_manage_watch_profiles ? '/app/watch-profiles' : null;
    const environmentHref = user?.can_manage_customer_users ? '/app/customer-environment' : null;
    const billingHref = user?.can_manage_customer_billing ? '/app/billing' : null;

    const activeMainArea = (() => {
        if (pathname === '/app') {
            return 'worklist';
        }

        if (pathname === '/app/dashboard') {
            return 'overview';
        }

        // The bid cockpit. It was /app/dashboard until Hjem became cross-module; it is Anbud's
        // own overview now, so it resolves to a Anbud area rather than to Hjem.
        if (pathname === '/app/bid-status') {
            return 'bid-status';
        }

        if (pathname === '/app/notices' || pathname.startsWith('/app/notices/')) {
            if (pathname.startsWith('/app/notices/saved/')) {
                return 'worklist';
            }

            if (noticeMode === 'saved' || noticeMode === 'history') {
                return 'worklist';
            }

            return 'procurements';
        }

        if (pathname === '/app/suppliers' || pathname.startsWith('/app/suppliers/')) {
            return 'suppliers';
        }

        // Watch lists configure which notices reach the customer at all, so the area they belong
        // to is Kunngjøringer. They keep their own route; only the navigation places them.
        if (pathname.startsWith('/app/watch-profiles')) {
            return 'procurements';
        }

        if (pathname.startsWith('/app/info-center')) {
            return 'info-center';
        }

        if (pathname.startsWith('/app/ai')) {
            return 'ai';
        }

        if (
            pathname.startsWith('/app/customer-environment')
            || pathname.startsWith('/app/departments')
            || pathname.startsWith('/app/users')
            || pathname.startsWith('/app/go-no-go-templates')
        ) {
            return 'environment';
        }

        // Checked before the general Wiki area: "Spør Wiki" is its own main-menu action, not a tab
        // inside the Wiki area, so it must not light up the Wiki nav item or render Wiki sub-tabs.
        if (pathname.startsWith('/app/wiki/ask')) {
            return 'wiki-ask';
        }

        if (pathname.startsWith('/app/wiki')) {
            return 'wiki';
        }

        // Styring's own landing page. Its modules keep their own areas below, so a page inside
        // Kvalitet is still `quality` — and Styring is lit through appModules.activeWorkspaceKey.
        if (pathname === '/app/governance') {
            return 'governance';
        }

        if (pathname.startsWith('/app/quality')) {
            return 'quality';
        }

        if (pathname.startsWith('/app/risk')) {
            return 'risk';
        }

        if (pathname.startsWith('/app/objectives')) {
            return 'objectives';
        }

        if (pathname.startsWith('/app/improvements')) {
            return 'improvements';
        }

        if (pathname.startsWith('/app/compliance')) {
            return 'compliance';
        }

        if (pathname.startsWith('/app/billing')) {
            return 'billing';
        }

        return 'overview';
    })();
    const aiCaseNavigationHint = activeMainArea === 'ai' && aiWorkHref === null && aiInstructionsHref === null
        ? (String(locale).toLowerCase().startsWith('en')
            ? 'Open a case first to unlock Work in progress and AI instructions.'
            : 'Velg en sak først for å åpne I arbeid og AI instrukser.')
        : '';

    /**
     * The work areas inside Anbud.
     *
     * These four are the stages one bid passes through, plus the competitor view that informs
     * them. They are the level below the module, not modules themselves — the same relation
     * Kildedokumenter, Kjøringer, Wiki-sider and Grafvisning have to Wiki. Routes, labels and
     * permissions are exactly what they were; only the place they are named has moved.
     */
    const activeModule = activeMainArea === 'governance' ? 'governance' : activeModuleKey(activeMainArea);
    const activeWorkspace = activeWorkspaceKey(activeMainArea);

    const toggleSidebarCollapsed = () => {
        setSidebarCollapsed((current) => {
            const next = ! current;

            writeModuleSidebarCollapsed(next);

            return next;
        });
    };

    const moduleSections = activeModule === 'tenders'
        ? [
            // Bid Status is the view across the four stages below it, so it comes first. It is
            // the only item here that is not itself a stage.
            { key: 'bid-status', label: navigation.bid_status, href: '/app/bid-status' },
            { key: 'procurements', label: navigation.notices, href: '/app/notices' },
            { key: 'worklist', label: translations.frontend.worklist_nav, href: buildHref('/app/notices', { mode: 'saved' }) },
            { key: 'ai', label: navigation.ai, href: '/app/ai' },
            { key: 'suppliers', label: navigation.competitors, href: '/app/suppliers' },
        ]
        : [];

    /**
     * Ask Wiki, between the workflow and the follow-up group. The magnifying glass is the whole
     * affordance, so the label is carried by title/aria-label instead.
     */
    const askWikiNavigation = {
        key: 'wiki-ask',
        label: translations.wiki?.ask_nav ?? 'Spør Wiki',
        href: '/app/wiki/ask',
    };

    /**
     * Follow-up, kept out of the workflow group on purpose.
     *
     * It is not a stage a case passes through — it is everything across every case that is waiting
     * on somebody. Sitting it inside the workflow made it read as a sixth step; a divider and the
     * company of the bell and the user menu say what it actually is.
     */
    const followUpNavigation = {
        key: 'info-center',
        label: translations.frontend.infosenter_nav,
        href: '/app/info-center',
        hint: translations.frontend.follow_up_hint
            ?? 'Oppgaver, beslutninger og avklaringer som krever oppfølging',
    };

    /**
     * The tabs inside one area. Where they are rendered depends on the module: inside Anbud they
     * are a third level and land on the page, while Wiki and Kundemiljø have no area level above
     * them, so theirs are the module navigation itself. See `hasModuleAreas` below.
     */
    const secondaryNavigation = (() => {
        if (activeMainArea === 'ai') {
            return [
                { key: 'ai-overview', label: navigation.overview, href: '/app/ai' },
                { key: 'ai-work', label: navigation.worklist, href: aiWorkHref },
                { key: 'ai-instructions', label: navigation.ai_instructions, href: aiInstructionsHref },
            ];
        }

        if (activeMainArea === 'procurements') {
            return [
                { key: 'live', label: navigation.live_search, href: '/app/notices' },
                { key: 'alerts', label: translations.frontend.alerts_nav, href: buildHref('/app/notices', { tab: 'alerts' }) },
                // Same permission gate as before, in a new place: a user who could not reach the
                // page from the main menu still cannot reach it from here.
                ...(watchProfilesHref
                    ? [{ key: 'watch-profiles', label: navigation.watch_lists, href: watchProfilesHref }]
                    : []),
            ];
        }

        if (activeMainArea === 'worklist') {
            return [
                {
                    key: 'saved',
                    label: withMenuCount(navigation.registered_notices, worklist?.saved_count),
                    href: buildHref('/app/notices', { mode: 'saved' }),
                },
                {
                    key: 'history',
                    label: withMenuCount(navigation.history, worklist?.history_count),
                    href: buildHref('/app/notices', { mode: 'history' }),
                },
            ];
        }

        if (activeMainArea === 'wiki') {
            return [
                // Ordered by the actual workflow: the source documents go in first, the runs show
                // what the system did with them, the pages are the result, and the graph is where
                // the connections are explored. Display order only — the active item is resolved
                // from the tab value below, and /app/wiki still opens on Wiki-sider.
                { key: 'wiki-sources', label: tw.tab_sources ?? 'Kildedokumenter', href: buildHref('/app/wiki', { tab: 'sources' }) },
                { key: 'wiki-runs', label: tw.tab_runs ?? 'Kjøringer', href: buildHref('/app/wiki', { tab: 'runs' }) },
                { key: 'wiki-pages', label: tw.tab_pages ?? 'Wiki-sider', href: buildHref('/app/wiki', { tab: 'pages' }) },
                { key: 'wiki-graph', label: tw.tab_graph ?? 'Grafvisning', href: '/app/wiki/graph' },
            ];
        }

        if (activeMainArea === 'quality') {
            // Same shape as Wiki's tabs: Kvalitet has no work areas of its own, so these sit in
            // the header directly under the rail's selection.
            return [
                { key: 'quality-overview', label: tq.tab_overview ?? 'Oversikt', href: buildHref('/app/quality', { tab: 'overview' }) },
                { key: 'quality-processes', label: tq.tab_processes ?? 'Prosesser', href: buildHref('/app/quality', { tab: 'processes' }) },
                { key: 'quality-controls', label: tq.tab_controls ?? 'Kontroller', href: buildHref('/app/quality', { tab: 'controls' }) },
                { key: 'quality-tools', label: tq.tab_tools ?? 'Verktøy', href: buildHref('/app/quality', { tab: 'tools' }) },
            ];
        }

        if (activeMainArea === 'environment') {
            const items = [];
            if (environmentHref) {
                items.push({ key: 'env-settings', label: navigation.customer_environment ?? 'Kundemiljø', href: environmentHref });
            }
            if (user?.is_system_owner) {
                items.push({ key: 'go-no-go-templates', label: translations.frontend?.go_no_go_templates_nav ?? 'Vurderingsmaler', href: '/app/go-no-go-templates' });
            }
            return items;
        }

        return [];
    })();

    const activeSecondaryKey = (() => {
        if (activeMainArea === 'ai') {
            if (/^\/app\/ai\/[^/]+\/instructions$/.test(pathname)) {
                return 'ai-instructions';
            }

            if (pathname === '/app/ai') {
                return 'ai-overview';
            }

            return 'ai-work';
        }

        if (activeMainArea === 'procurements') {
            if (pathname.startsWith('/app/watch-profiles')) {
                return 'watch-profiles';
            }

            if (noticeTab === 'alerts') {
                return 'alerts';
            }

            return 'live';
        }

        if (activeMainArea === 'worklist') {
            return noticeMode === 'history' ? 'history' : 'saved';
        }

        if (activeMainArea === 'environment') {
            if (pathname.startsWith('/app/go-no-go-templates')) {
                return 'go-no-go-templates';
            }
            return 'env-settings';
        }

        if (activeMainArea === 'quality') {
            return `quality-${qualityTab}`;
        }

        if (activeMainArea === 'wiki') {
            if (pathname === '/app/wiki') {
                return `wiki-${wikiTab}`;
            }

            if (pathname.startsWith('/app/wiki/graph')) {
                return 'wiki-graph';
            }

            return 'wiki-pages';
        }

        return null;
    })();

    /**
     * Two levels, decided by one fact: whether the active module has work areas of its own.
     *
     * Wiki has none, so its tabs *are* its module navigation — they sit in the header, directly
     * below the rail's selection. That is the pattern, and Anbud now follows it: the header carries
     * Kunngjøringer, Saksliste, Besvarelse and Konkurrenter, and the tabs belonging to whichever of
     * those is open drop one level further down, onto the page. Kunngjøringer is where the
     * difference is visible — Live søk, Varsler and Watch lists are a level inside it, not a peer
     * of it, and the page is where that reads correctly.
     *
     * Both lists are the ones that already existed. Nothing here adds, removes or re-gates an item.
     */
    const hasModuleAreas = moduleSections.length > 0;

    const moduleNavigation = hasModuleAreas ? moduleSections : secondaryNavigation;
    const activeModuleNavigationKey = hasModuleAreas ? activeMainArea : activeSecondaryKey;

    const pageNavigation = hasModuleAreas ? secondaryNavigation : [];
    const activePageNavigationKey = hasModuleAreas ? activeSecondaryKey : null;

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

                            {/* Search, follow-up, the bell and the user, in that order. Allowed to wrap on a
                                phone: the group grew by two controls, and on a narrow screen the
                                user button would otherwise be pushed off the right edge. */}
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

                                {/* The line that says the workflow ends here. Hidden where the
                                    header wraps, because a divider between two stacked rows
                                    separates nothing. */}
                                <span
                                    aria-hidden="true"
                                    data-testid="header-follow-up-divider"
                                    className="hidden h-6 w-px shrink-0 bg-slate-200 lg:block"
                                />

                                {/* The counterpart to the bell's explanation. These two are the
                                    only things in the bar that could both be read as "something
                                    needs me", so each says which it is: the bell holds what has
                                    happened, this holds what is still to do. */}
                                <ControlHint text={followUpNavigation.hint}>
                                    <Link
                                        href={followUpNavigation.href}
                                        aria-current={activeMainArea === followUpNavigation.key ? 'page' : undefined}
                                        data-testid="header-follow-up"
                                        className={classNames(
                                            'shrink-0 rounded-xl px-3 py-2 text-base font-medium transition',
                                            activeMainArea === followUpNavigation.key
                                                ? 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-200'
                                                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                                        )}
                                    >
                                        {followUpNavigation.label}
                                    </Link>
                                </ControlHint>

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

                    {flash?.success && showSuccess ? (
                        <div className="fixed left-1/2 top-4 z-50 -translate-x-1/2 rounded-2xl border border-emerald-200 bg-emerald-50 px-6 py-3 text-base text-emerald-800 shadow-lg">
                            {flash.success}
                        </div>
                    ) : null}
                    {flash?.error ? (
                        <div className="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-base text-rose-800 shadow-sm">
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
