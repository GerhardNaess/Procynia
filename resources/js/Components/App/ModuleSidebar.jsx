import { Link } from '@inertiajs/react';
import { APP_MODULES } from '../../Support/appModules';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

/**
 * One 20x20 stroke icon per module, inline rather than from a package.
 *
 * The app draws its handful of icons this way already (the search glass and the notification bell
 * in the header), and a rail of fifteen is still small enough that an icon dependency would cost
 * more than it saves.
 */
const MODULE_ICONS = {
    home: 'M3.5 8.5 10 3.5l6.5 5v7a1.5 1.5 0 0 1-1.5 1.5H5a1.5 1.5 0 0 1-1.5-1.5v-7Z M8 16.5v-4.5h4v4.5',
    tenders: 'M5.5 2.5h6l3.5 3.5v11a1 1 0 0 1-1 1h-8.5a1 1 0 0 1-1-1v-13a1 1 0 0 1 1-1Z M11 2.5V6h3.5 M7.5 10.5h5 M7.5 13.5h5',
    wiki: 'M4 4.5a1.5 1.5 0 0 1 1.5-1.5H15a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H5.5A1.5 1.5 0 0 1 4 14.5v-10Z M4 14.5A1.5 1.5 0 0 1 5.5 13H16 M7.5 6.5h5',
    quality: 'M10 2.5 16.5 5v5c0 3.5-2.6 6.2-6.5 7.5C6.1 16.2 3.5 13.5 3.5 10V5L10 2.5Z M7.5 10 9.3 11.8 12.8 8.3',
    risk: 'M10 3 17.5 16.5H2.5L10 3Z M10 8v3.5 M10 14h.01',
    suppliers: 'M3 7.5 10 4l7 3.5-7 3.5-7-3.5Z M3 12.5 10 16l7-3.5',
    contracts: 'M5.5 2.5h9a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-9a1 1 0 0 1-1-1v-13a1 1 0 0 1 1-1Z M7.5 6.5h5 M7.5 9.5h5 M7.5 12.5h3',
    hse: 'M10 3a6 6 0 0 1 6 6v3.5H4V9a6 6 0 0 1 6-6Z M2.5 12.5h15 M10 3v6',
    compliance: 'M10 2.5 16.5 5v5c0 3.5-2.6 6.2-6.5 7.5C6.1 16.2 3.5 13.5 3.5 10V5L10 2.5Z M10 7v3.5 M10 13h.01',
    services: 'M10 3.5a6.5 6.5 0 1 1 0 13 6.5 6.5 0 0 1 0-13Z M10 6.5V10l2.5 1.5',
    projects: 'M3.5 5.5h13 M3.5 10h13 M3.5 14.5h13 M7 3.5v4 M12.5 8v4 M6 12.5v4',
    competence: 'M10 3 18 7l-8 4-8-4 8-4Z M5 9v4c0 1.4 2.2 2.5 5 2.5s5-1.1 5-2.5V9',
    assets: 'M4 7.5 10 4.5l6 3v5l-6 3-6-3v-5Z M10 10.5v6 M4 7.5l6 3 6-3',
    reports: 'M3.5 16.5h13 M6.5 13.5V8 M10 13.5V4.5 M13.5 13.5v-3.5',
    settings: 'M10 7.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5Z M16.2 12.1a1.3 1.3 0 0 0 .26 1.43l.05.05a1.5 1.5 0 1 1-2.13 2.13l-.05-.05a1.3 1.3 0 0 0-1.43-.26 1.3 1.3 0 0 0-.79 1.19v.14a1.5 1.5 0 1 1-3 0v-.07a1.3 1.3 0 0 0-.85-1.19 1.3 1.3 0 0 0-1.43.26l-.05.05a1.5 1.5 0 1 1-2.13-2.13l.05-.05a1.3 1.3 0 0 0 .26-1.43 1.3 1.3 0 0 0-1.19-.79h-.14a1.5 1.5 0 1 1 0-3h.07a1.3 1.3 0 0 0 1.19-.85 1.3 1.3 0 0 0-.26-1.43l-.05-.05a1.5 1.5 0 1 1 2.13-2.13l.05.05a1.3 1.3 0 0 0 1.43.26h.06a1.3 1.3 0 0 0 .79-1.19v-.14a1.5 1.5 0 1 1 3 0v.07a1.3 1.3 0 0 0 .79 1.19 1.3 1.3 0 0 0 1.43-.26l.05-.05a1.5 1.5 0 1 1 2.13 2.13l-.05.05a1.3 1.3 0 0 0-.26 1.43v.06a1.3 1.3 0 0 0 1.19.79h.14a1.5 1.5 0 1 1 0 3h-.07a1.3 1.3 0 0 0-1.19.79Z',
};

function ModuleIcon({ moduleKey }) {
    const paths = (MODULE_ICONS[moduleKey] ?? '').split(' M').map((part, index) => (index === 0 ? part : `M${part}`));

    return (
        <svg
            className="h-5 w-5 shrink-0"
            viewBox="0 0 20 20"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.6"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            {paths.map((d) => (
                <path key={d} d={d} />
            ))}
        </svg>
    );
}

/**
 * The module rail.
 *
 * Two groups, separated on purpose. Above the line is what the system can do today — normal
 * contrast, clickable, one of them selected. Below it is the planned structure: same names and
 * icons so the shape of the product is readable, but dimmed, not focusable, and captioned
 * "Planlagt" so there is no question about why clicking does nothing. Dimming alone would read as
 * a bug; the caption is what turns it into information.
 */
export default function ModuleSidebar({ modules = {}, activeKey = null, sections = [], activeSectionKey = null }) {
    const available = APP_MODULES.filter((module) => module.available);
    const planned = APP_MODULES.filter((module) => ! module.available);

    const renderSections = (moduleKey) => {
        if (moduleKey !== activeKey || sections.length === 0) {
            return null;
        }

        return (
            <ul className="mt-1 space-y-0.5 border-l border-slate-200 pl-3 ml-4">
                {sections.map((section) => {
                    const isActive = activeSectionKey === section.key;

                    return (
                        <li key={section.key}>
                            <Link
                                href={section.href}
                                aria-current={isActive ? 'page' : undefined}
                                className={classNames(
                                    'block rounded-lg px-2.5 py-1.5 text-sm font-medium transition',
                                    isActive
                                        ? 'text-violet-700'
                                        : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900',
                                )}
                            >
                                {section.label}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        );
    };

    return (
        <nav
            data-testid="module-sidebar"
            aria-label={modules.aria ?? 'Moduler'}
            className="rounded-2xl border border-slate-200/80 bg-white p-3 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
        >
            <ul className="space-y-0.5">
                {available.map((module) => {
                    const isActive = activeKey === module.key;

                    return (
                        <li key={module.key}>
                            <Link
                                href={module.href}
                                data-testid={`module-${module.key}`}
                                aria-current={isActive ? 'page' : undefined}
                                className={classNames(
                                    'flex items-center gap-3 rounded-xl px-3 py-2 text-base font-medium transition',
                                    isActive
                                        ? 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-200'
                                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                                )}
                            >
                                <ModuleIcon moduleKey={module.key} />
                                <span className="leading-snug">{module.label(modules)}</span>
                            </Link>
                            {renderSections(module.key)}
                        </li>
                    );
                })}
            </ul>

            <p
                data-testid="module-sidebar-planned-caption"
                className="mt-4 mb-1 px-3 text-xs font-semibold uppercase tracking-wide text-slate-400"
            >
                {modules.planned_caption ?? 'Planlagt'}
            </p>

            <ul className="space-y-0.5">
                {planned.map((module) => (
                    <li key={module.key}>
                        <span
                            data-testid={`module-${module.key}`}
                            aria-disabled="true"
                            title={modules.planned_hint ?? 'Ikke tilgjengelig ennå'}
                            className="flex cursor-not-allowed select-none items-center gap-3 rounded-xl px-3 py-2 text-base font-medium text-slate-400"
                        >
                            <ModuleIcon moduleKey={module.key} />
                            <span className="leading-snug">{module.label(modules)}</span>
                        </span>
                    </li>
                ))}
            </ul>
        </nav>
    );
}
