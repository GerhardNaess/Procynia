import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

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

export default function CustomerAppLayout({ children, title, showPageTitle = true }) {
    const page = usePage();
    const { appName, auth, flash, translations, worklist } = page.props;
    const [showSuccess, setShowSuccess] = useState(true);
    const [isUserMenuOpen, setIsUserMenuOpen] = useState(false);
    const userMenuRef = useRef(null);
    const currentUrl = page.url ?? '';
    const user = auth?.user;
    const customerName = user?.customer?.name ?? translations.frontend.support_mode_customer;
    const customerLabel = user?.customer?.name
        ? translations.frontend.customer_area
        : translations.frontend.support_mode_label;
    const customerInitial = customerName.trim().charAt(0).toUpperCase() || 'P';
    const userName = user?.name ?? '';
    const userEmail = user?.email ?? '';
    const userBidRoleLabel = user?.bid_role_label ?? '';
    const userInitial = userName.trim().charAt(0).toUpperCase() || 'P';
    const { pathname, searchParams } = splitUrl(currentUrl);
    const noticeMode = searchParams.get('mode') ?? 'live';
    const noticeTab = searchParams.get('tab') ?? (noticeMode === 'live' ? 'live' : null);
    const watchProfilesHref = user?.can_manage_watch_profiles ? '/app/watch-profiles' : null;
    const environmentHref = user?.can_manage_customer_users ? '/app/customer-environment' : null;

    const activeMainArea = (() => {
        if (pathname === '/app') {
            return 'worklist';
        }

        if (pathname === '/app/dashboard') {
            return 'overview';
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

        if (pathname.startsWith('/app/inbox/')) {
            return 'inbox';
        }

        if (pathname.startsWith('/app/watch-profiles')) {
            return 'watch-profiles';
        }

        if (
            pathname.startsWith('/app/customer-environment')
            || pathname.startsWith('/app/departments')
            || pathname.startsWith('/app/users')
        ) {
            return 'environment';
        }

        return 'overview';
    })();

    const mainNavigation = [
        { key: 'overview', label: 'Oversikt', href: '/app/dashboard' },
        { key: 'procurements', label: 'Kunngjøringer', href: '/app/notices' },
        { key: 'worklist', label: translations.frontend.worklist_nav, href: buildHref('/app/notices', { mode: 'saved' }) },
        { key: 'inbox', label: 'Inbox', href: '/app/inbox/user' },
        { key: 'suppliers', label: 'Konkurrenter', href: '/app/suppliers' },
        ...(watchProfilesHref ? [{ key: 'watch-profiles', label: 'Watch lists', href: watchProfilesHref }] : []),
        ...(environmentHref ? [{ key: 'environment', label: 'Kundemiljø', href: environmentHref }] : []),
    ];

    const secondaryNavigation = (() => {
        if (activeMainArea === 'procurements') {
            return [
                { key: 'live', label: 'Live søk', href: '/app/notices' },
                { key: 'saved-searches', label: translations.frontend.saved_searches_nav, href: `${buildHref('/app/notices', { tab: 'saved-searches' })}#saved-searches`, isAnchor: true },
                { key: 'alerts', label: translations.frontend.alerts_nav, href: `${buildHref('/app/notices', { tab: 'alerts' })}#alerts-monitoring`, isAnchor: true },
            ];
        }

        if (activeMainArea === 'worklist') {
            return [
                {
                    key: 'saved',
                    label: withMenuCount('Registrerte kunngjøringer', worklist?.saved_count),
                    href: buildHref('/app/notices', { mode: 'saved' }),
                },
                {
                    key: 'history',
                    label: withMenuCount('Historikk', worklist?.history_count),
                    href: buildHref('/app/notices', { mode: 'history' }),
                },
            ];
        }

        if (activeMainArea === 'inbox') {
            return [
                { key: 'user-inbox', label: 'Min innboks', href: '/app/inbox/user' },
                ...(user?.can_access_department_inbox ? [{ key: 'department-inbox', label: 'Avdelingsinnboks', href: '/app/inbox/department' }] : []),
            ];
        }

        return [];
    })();

    const activeSecondaryKey = (() => {
        if (activeMainArea === 'procurements') {
            if (noticeTab === 'saved-searches') {
                return 'saved-searches';
            }

            if (noticeTab === 'alerts') {
                return 'alerts';
            }

            return 'live';
        }

        if (activeMainArea === 'worklist') {
            return noticeMode === 'history' ? 'history' : 'saved';
        }

        if (activeMainArea === 'inbox') {
            return pathname.startsWith('/app/inbox/department') ? 'department-inbox' : 'user-inbox';
        }

        return null;
    })();

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
    }, [currentUrl]);

    useEffect(() => {
        if (!isUserMenuOpen) {
            return;
        }

        const handlePointerDown = (event) => {
            if (userMenuRef.current && !userMenuRef.current.contains(event.target)) {
                setIsUserMenuOpen(false);
            }
        };

        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                setIsUserMenuOpen(false);
            }
        };

        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [isUserMenuOpen]);

    const logout = () => {
        setIsUserMenuOpen(false);
        router.post('/logout');
    };

    return (
        <>
            <Head title={title ? `${title} · ${appName}` : appName} />
            <div className="min-h-screen bg-[#f6f7fb] text-slate-900">
                <header className="border-b border-slate-200/80 bg-white/95 backdrop-blur-sm">
                    <div className="mx-auto max-w-[1600px] px-4 py-3 sm:px-6 lg:px-8">
                        <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:gap-6">
                                <Link href="/app/dashboard" className="flex items-center">
                                    <img
                                        src="/images/procynia_logo.png"
                                        alt="Procynia"
                                        style={{ height: '64px', width: 'auto', maxWidth: '14.4rem' }}
                                        className="object-contain"
                                    />
                                </Link>

                                <nav className="flex flex-wrap items-center gap-1.5">
                                    {mainNavigation.map((item) => {
                                        const isActive = activeMainArea === item.key;

                                        return (
                                            <Link
                                                key={item.key}
                                                href={item.href}
                                                className={classNames(
                                                    'rounded-xl px-3 py-2 text-sm font-medium transition',
                                                    isActive
                                                        ? 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-200'
                                                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                                                )}
                                            >
                                                {item.label}
                                            </Link>
                                        );
                                    })}
                                </nav>
                            </div>

                            <div className="flex items-center justify-between gap-3 lg:justify-end">
                                <div className="flex items-center gap-3 rounded-xl bg-transparent px-1 py-1">
                                    <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-100 text-sm font-semibold text-amber-800">
                                        {customerInitial}
                                    </span>
                                    <div className="min-w-0">
                                        <div className="truncate text-sm font-semibold text-slate-900">{customerName}</div>
                                        <div className="text-xs text-slate-500">{customerLabel}</div>
                                    </div>
                                </div>

                                <div ref={userMenuRef} className="relative">
                                    <button
                                        type="button"
                                        onClick={() => setIsUserMenuOpen((value) => !value)}
                                        className={classNames(
                                            'flex max-w-[240px] items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-left shadow-sm transition',
                                            isUserMenuOpen
                                                ? 'border-violet-300 ring-4 ring-violet-100'
                                                : 'hover:border-slate-300 hover:bg-slate-50',
                                        )}
                                        aria-haspopup="menu"
                                        aria-expanded={isUserMenuOpen}
                                    >
                                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-700">
                                            {userInitial}
                                        </span>
                                        <div className="min-w-0">
                                            <div className="truncate text-sm font-semibold text-slate-900">{userName}</div>
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
                                        <div className="absolute right-0 top-[calc(100%+0.75rem)] z-50 w-[320px] max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_20px_60px_rgba(15,23,42,0.18)]">
                                            <div className="space-y-1 border-b border-slate-200 px-4 py-4">
                                                <div className="text-sm font-semibold text-slate-950">{userName}</div>
                                                <div className="break-words text-sm text-slate-500">{userEmail}</div>
                                                {userBidRoleLabel ? (
                                                    <div className="pt-1 text-xs font-medium uppercase tracking-[0.12em] text-slate-400">
                                                        {userBidRoleLabel}
                                                    </div>
                                                ) : null}
                                            </div>
                                            <div className="p-2">
                                                <button
                                                    type="button"
                                                    onClick={logout}
                                                    className="flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 hover:text-slate-950"
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

                        {secondaryNavigation.length > 0 ? (
                            <div className="mt-2 border-t border-slate-200/80 pt-2">
                                <nav className="flex flex-wrap items-center gap-2">
                                    {secondaryNavigation.map((item) => {
                                        const isActive = activeSecondaryKey === item.key;
                                        const classes = classNames(
                                            'rounded-xl px-3 py-2 text-sm font-medium transition',
                                            isActive
                                                ? 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-200'
                                                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                                        );

                                        if (item.isAnchor) {
                                            return (
                                                <a key={item.key} href={item.href} className={classes}>
                                                    {item.label}
                                                </a>
                                            );
                                        }

                                        return (
                                            <Link key={item.key} href={item.href} className={classes}>
                                                {item.label}
                                            </Link>
                                        );
                                    })}
                                </nav>
                            </div>
                        ) : null}
                    </div>
                </header>

                <main className="mx-auto max-w-[1600px] px-4 py-7 sm:px-6 lg:px-8">
                    {flash?.success && showSuccess ? (
                        <div className="fixed left-1/2 top-4 z-50 -translate-x-1/2 rounded-2xl border border-emerald-200 bg-emerald-50 px-6 py-3 text-sm text-emerald-800 shadow-lg">
                            {flash.success}
                        </div>
                    ) : null}
                    {flash?.error ? (
                        <div className="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 shadow-sm">
                            {flash.error}
                        </div>
                    ) : null}

                    {title && showPageTitle ? (
                        <div className="mb-8">
                            <h1 className="text-3xl font-semibold tracking-tight text-slate-950">{title}</h1>
                        </div>
                    ) : null}

                    {children}
                </main>

                <footer className="bg-transparent">
                    <div className="mx-auto max-w-[1600px] px-4 py-8 text-center text-xs text-slate-400 sm:px-6 lg:px-8">
                        {translations.frontend.customer_footer}
                    </div>
                </footer>
            </div>
        </>
    );
}
