import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

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

export default function CustomerAppLayout({ children, title, showPageTitle = true }) {
    const page = usePage();
    const { appName, auth, flash, translations } = page.props;
    const [showSuccess, setShowSuccess] = useState(true);
    const currentUrl = page.url ?? '';
    const user = auth?.user;
    const customerName = user?.customer?.name ?? translations.frontend.support_mode_customer;
    const customerLabel = user?.customer?.name
        ? translations.frontend.customer_area
        : translations.frontend.support_mode_label;
    const customerInitial = customerName.trim().charAt(0).toUpperCase() || 'P';
    const { pathname, searchParams } = splitUrl(currentUrl);
    const noticeMode = searchParams.get('mode') ?? 'live';
    const noticeTab = searchParams.get('tab') ?? (noticeMode === 'live' ? 'live' : null);
    const adminLandingHref = user?.can_manage_watch_profiles
        ? '/app/watch-profiles'
        : (user?.can_manage_customer_users ? '/app/departments' : null);
    const activeMainArea = (() => {
        if (pathname === '/app') {
            return 'overview';
        }

        if (pathname === '/app/notices' || pathname.startsWith('/app/notices/')) {
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

        if (
            pathname.startsWith('/app/watch-profiles')
            || pathname.startsWith('/app/departments')
            || pathname.startsWith('/app/users')
        ) {
            return 'admin';
        }

        return 'overview';
    })();
    const mainNavigation = [
        { key: 'overview', label: translations.frontend.overview_nav, href: '/app' },
        { key: 'procurements', label: translations.frontend.procurements_nav, href: '/app/notices' },
        { key: 'suppliers', label: 'Konkurrenter', href: '/app/suppliers' },
        { key: 'inbox', label: 'Inbox', href: '/app/inbox/user' },
        { key: 'worklist', label: translations.frontend.worklist_nav, href: buildHref('/app/notices', { mode: 'saved' }) },
        ...(adminLandingHref ? [{ key: 'admin', label: 'Administrasjon', href: adminLandingHref }] : []),
    ];
    const secondaryNavigation = (() => {
        if (activeMainArea === 'procurements') {
            return [
                { key: 'live', label: 'Live søk', href: '/app/notices' },
                { key: 'saved-searches', label: translations.frontend.saved_searches_nav, href: `${buildHref('/app/notices', { tab: 'saved-searches' })}#saved-searches`, isAnchor: true },
                { key: 'alerts', label: translations.frontend.alerts_nav, href: `${buildHref('/app/notices', { tab: 'alerts' })}#alerts-monitoring`, isAnchor: true },
            ];
        }

        if (activeMainArea === 'inbox') {
            return [
                { key: 'user-inbox', label: 'Min innboks', href: '/app/inbox/user' },
                ...(user?.can_access_department_inbox ? [{ key: 'department-inbox', label: 'Avdelingsinnboks', href: '/app/inbox/department' }] : []),
            ];
        }

        if (activeMainArea === 'admin') {
            return [
                ...(user?.can_manage_watch_profiles ? [{ key: 'watch-profiles', label: 'Watch Profiles', href: '/app/watch-profiles' }] : []),
                ...(user?.can_manage_customer_users ? [{ key: 'departments', label: 'Avdelinger', href: '/app/departments' }] : []),
                ...(user?.can_manage_customer_users ? [{ key: 'users', label: 'Brukere', href: '/app/users' }] : []),
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

        if (activeMainArea === 'inbox') {
            return pathname.startsWith('/app/inbox/department') ? 'department-inbox' : 'user-inbox';
        }

        if (activeMainArea === 'admin') {
            if (pathname.startsWith('/app/departments')) {
                return 'departments';
            }

            if (pathname.startsWith('/app/users')) {
                return 'users';
            }

            return 'watch-profiles';
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

    const logout = () => {
        router.post('/logout');
    };

    return (
        <>
            <Head title={title ? `${title} · ${appName}` : appName} />
            <div className="min-h-screen bg-[#f6f7fb] text-slate-900">
                <header className="border-b border-slate-200/80 bg-white/95 backdrop-blur-sm">
                    <div className="mx-auto max-w-[1240px] px-4 py-3 sm:px-6 lg:px-8">
                        <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:gap-6">
                                <Link href="/app" className="flex items-center gap-3">
                                    <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-600 text-base font-semibold text-white">
                                        P
                                    </span>
                                    <span className="text-[1.85rem] font-semibold tracking-tight text-slate-950">{appName}</span>
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

                                <button
                                    type="button"
                                    onClick={logout}
                                    className="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 transition hover:bg-slate-100 hover:text-slate-900"
                                >
                                    {translations.common.logout}
                                </button>
                            </div>
                        </div>

                        {secondaryNavigation.length > 0 ? (
                            <div className="mt-3 border-t border-slate-200/80 pt-3">
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

                <main className="mx-auto max-w-[1240px] px-4 py-7 sm:px-6 lg:px-8">
                    {flash?.success && showSuccess ? (
                        <div className="fixed top-4 left-1/2 z-50 -translate-x-1/2 rounded-2xl border border-emerald-200 bg-emerald-50 px-6 py-3 text-sm text-emerald-800 shadow-lg">
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
                    <div className="mx-auto max-w-[1240px] px-4 py-8 text-center text-xs text-slate-400 sm:px-6 lg:px-8">
                        {translations.frontend.customer_footer}
                    </div>
                </footer>
            </div>
        </>
    );
}
