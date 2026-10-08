import { Link } from '@inertiajs/react';

const TABS = [
    { key: 'suppliers', href: '/app/supplier-management' },
    { key: 'control_requirements', href: '/app/supplier-management/control-requirements' },
];

/** Leverandører | Kontrollkrav — the two pages of Leverandøroppfølging's register. */
export default function SupplierTabs({ current, tr }) {
    const t = tr.tabs ?? {};

    return (
        <nav aria-label={t.label ?? 'Leverandøroppfølging'} className="flex flex-wrap gap-2 border-b border-slate-200" data-testid="supplier-tabs">
            {TABS.map((tab) => {
                const active = tab.key === current;

                return (
                    <Link
                        key={tab.key}
                        href={tab.href}
                        aria-current={active ? 'page' : undefined}
                        className={`-mb-px inline-flex min-h-11 items-center border-b-2 px-3 text-base font-semibold ${active ? 'border-violet-600 text-violet-800' : 'border-transparent text-slate-600 hover:text-slate-900'}`}
                    >
                        {t[tab.key] ?? tab.key}
                    </Link>
                );
            })}
        </nav>
    );
}
