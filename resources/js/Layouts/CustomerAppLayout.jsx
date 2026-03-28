import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
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
    const navigation = [
        { key: 'overview', label: translations.frontend.overview_nav, href: null },
        { key: 'notices', label: translations.frontend.procurements_nav, href: '/app/notices' },
        ...(user?.can_manage_customer_users ? [{ key: 'departments', label: 'Avdelinger', href: '/app/departments' }] : []),
        ...(user?.can_manage_customer_users ? [{ key: 'users', label: 'Brukere', href: '/app/users' }] : []),
        ...(user?.can_manage_customer_users ? [{ key: 'watch-profiles', label: 'Watch Profiles', href: '/app/watch-profiles' }] : []),
        { key: 'saved-searches', label: translations.frontend.saved_searches_nav, href: null },
        { key: 'alerts', label: translations.frontend.alerts_nav, href: null },
        { key: 'worklist', label: translations.frontend.worklist_nav, href: null },
    ];

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
                    <div className="mx-auto flex max-w-[1240px] flex-col gap-3 px-4 py-3 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
                        <div className="flex items-center gap-6">
                            <Link href="/app/notices" className="flex items-center gap-3">
                                <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-600 text-base font-semibold text-white">
                                    P
                                </span>
                                <span className="text-[1.85rem] font-semibold tracking-tight text-slate-950">{appName}</span>
                            </Link>

                            <nav className="flex flex-wrap items-center gap-1">
                                {navigation.map((item) => {
                                    const isActive = item.href ? currentUrl.startsWith(item.href) : false;

                                    if (!item.href) {
                                        return (
                                            <span
                                                key={item.key}
                                                className="rounded-lg px-3 py-2 text-sm font-medium text-slate-600"
                                            >
                                                {item.label}
                                            </span>
                                        );
                                    }

                                    return (
                                        <Link
                                            key={item.key}
                                            href={item.href}
                                            className={classNames(
                                                'rounded-lg px-3 py-2 text-sm font-medium transition',
                                                isActive
                                                    ? 'bg-violet-100 text-violet-700'
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
