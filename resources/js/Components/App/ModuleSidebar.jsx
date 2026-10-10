import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { railEntries } from '../../Support/appModules';
import { hasChildren, isGroupOpen, readOpenGroups, requiredOpenGroups, toggleGroup, withGroupsOpen, writeOpenGroups } from '../../Support/navigationGroups';

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
    // Styring: a grid of areas — the workspace that holds several modules, not any one of them.
    governance: 'M4.5 3.5h3a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-3a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1Z M12.5 3.5h3a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-3a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1Z M4.5 11.5h3a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-3a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1Z M12.5 11.5h3a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-3a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1Z',
    wiki: 'M4 4.5a1.5 1.5 0 0 1 1.5-1.5H15a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H5.5A1.5 1.5 0 0 1 4 14.5v-10Z M4 14.5A1.5 1.5 0 0 1 5.5 13H16 M7.5 6.5h5',
    quality: 'M10 2.5 16.5 5v5c0 3.5-2.6 6.2-6.5 7.5C6.1 16.2 3.5 13.5 3.5 10V5L10 2.5Z M7.5 10 9.3 11.8 12.8 8.3',
    risk: 'M10 3 17.5 16.5H2.5L10 3Z M10 8v3.5 M10 14h.01',
    objectives: 'M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14Z M10 6.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Z M10 10h.01',
    improvements: 'M16.5 10a6.5 6.5 0 1 1-1.9-4.6 M16.5 3v3h-3 M10 7v3.5 M10 13.5h.01',
    suppliers: 'M3 7.5 10 4l7 3.5-7 3.5-7-3.5Z M3 12.5 10 16l7-3.5',
    // Ledelsens gjennomgåelse: a clipboard with a tick — reviewed and decided.
    management_review: 'M7 3.5h6v2H7v-2Z M5.5 4.5h-1a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1v-11a1 1 0 0 0-1-1h-1 M7 11l2 2 4-4',
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

export function ModuleIcon({ moduleKey, className = 'h-5 w-5 shrink-0' }) {
    const paths = (MODULE_ICONS[moduleKey] ?? '').split(' M').map((part, index) => (index === 0 ? part : `M${part}`));

    return (
        <svg
            className={className}
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
 * The collapse control's own icon — a chevron that points the way the rail is about to move.
 */
function CollapseIcon({ collapsed }) {
    return (
        <svg
            className="h-4 w-4"
            viewBox="0 0 20 20"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            {collapsed ? (
                <>
                    <path d="M8 5.5 12.5 10 8 14.5" />
                    <path d="M13.5 5.5V14.5" />
                </>
            ) : (
                <>
                    <path d="M12 5.5 7.5 10 12 14.5" />
                    <path d="M6.5 5.5V14.5" />
                </>
            )}
        </svg>
    );
}

/**
 * A group's own chevron: pointing right while the group is closed, down while it is open.
 */
function GroupChevron({ open }) {
    return (
        <svg
            className={classNames('h-4 w-4 transition-transform', open ? 'rotate-90' : '')}
            viewBox="0 0 20 20"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M8 5.5 12.5 10 8 14.5" />
        </svg>
    );
}

/**
 * The module rail — a module picker, and nothing else.
 *
 * Two groups, separated on purpose. At the top is what this person can use today — normal contrast,
 * clickable, one of them selected. Below it, "Planlagt": the structure that does not exist yet,
 * dimmed and inert. A built module the customer has not bought is not on the rail at all: the rail
 * names the places a person works, not the product catalog, and Abonnement is where the catalog
 * lives.
 *
 * What is active is decided in the backend, from the customer's package entitlements, and arrives
 * as `activeModules` — technical modules, never package names. The rail renders that verdict and
 * never computes one of its own. This is presentation only: every gated route is enforced
 * server-side as well.
 *
 * What the rail deliberately does not carry is the level below a module, with one exception.
 * Wiki never put its work areas here, and Anbud nesting its four under "Anbud" made one module look
 * structurally unlike every other; those areas live in the header's module navigation. The
 * nesting the rail does carry runs a level above modules — Styring groups Kvalitet, Risiko, Mål og
 * KPI, Avvik og forbedringer and Etterlevelse og revisjon — plus Etterlevelse og revisjon's two
 * equal work areas, Krav and Revisjoner.
 *
 * Every entry with something under it can be folded away with its own chevron (navigationGroups.js),
 * and only those entries have one. The chevron folds; the name navigates — one row never does both.
 * The group holding the current page is opened on arrival, so the page is never hidden.
 *
 * Collapsing is a desktop-only affordance, and it is done in CSS rather than by branching on a
 * measured viewport. Every label stays in the markup; `lg:sr-only` is what takes it out of the
 * layout from `lg` up, which keeps the accessible name intact (unlike `hidden`) and keeps the
 * narrow rail from ever reaching a phone. Below `lg` the rail is a full-width block above the
 * page and must stay fully legible — it is the only module navigation there — so the collapse
 * control itself is hidden and the collapsed classes simply do not apply.
 */
export default function ModuleSidebar({ modules = {}, activeModules = [], permissions = [], activeKey = null, activeAreaKey = null, activeWorkspace = null, collapsed = false, onToggleCollapsed = null }) {
    // A module this person holds no permission in is deliberately never rendered — it is not
    // dimmed, it is simply not theirs. See appModules.moduleAvailability.
    const groups = railEntries(activeModules, permissions);
    const plannedHint = modules.planned_hint ?? 'Ikke tilgjengelig ennå';

    // Open groups: the person's own choice, with the groups holding this page forced open.
    const requiredKeys = requiredOpenGroups(groups.entries, { activeWorkspace, activeKey }).join('|');
    const [openGroups, setOpenGroups] = useState(() => withGroupsOpen(readOpenGroups(), requiredKeys ? requiredKeys.split('|') : []));

    useEffect(() => {
        setOpenGroups((current) => withGroupsOpen(current, requiredKeys ? requiredKeys.split('|') : []));
    }, [requiredKeys]);

    const onToggleGroup = (key) => {
        const next = toggleGroup(openGroups, key);

        setOpenGroups(next);
        writeOpenGroups(next);
    };
    const toggleLabel = collapsed
        ? (modules.expand ?? 'Utvid menyen')
        : (modules.collapse ?? 'Slå sammen menyen');

    // Only meaningful while collapsed, and only at lg+ — but a title on a visible label costs
    // nothing, and touch screens have no hover to trigger it.
    const labelClass = collapsed ? 'leading-snug lg:sr-only' : 'leading-snug';
    const rowClass = collapsed
        ? 'flex items-center gap-3 rounded-xl px-3 py-2 lg:justify-center lg:gap-0 lg:px-0'
        : 'flex items-center gap-3 rounded-xl px-3 py-2';

    /**
     * The dimmed "Planlagt" group: captioned, inert, and saying why on hover.
     */
    const renderUnavailableGroup = (items, { testId, caption, hint }) => (items.length === 0 ? null : (
        <>
            <p
                data-testid={testId}
                className={classNames(
                    'mt-5 mb-1 px-3 text-base font-semibold text-slate-500',
                    collapsed ? 'lg:sr-only' : '',
                )}
            >
                {caption}
            </p>

            <ul className={classNames('space-y-0.5', collapsed ? 'lg:mt-4 lg:border-t lg:border-slate-200/80 lg:pt-3' : '')}>
                {items.map((module) => {
                    const label = module.label(modules);

                    return (
                        <li key={module.key}>
                            <span
                                data-testid={`module-${module.key}`}
                                aria-disabled="true"
                                title={collapsed ? `${label} — ${hint}` : hint}
                                className={classNames(
                                    rowClass,
                                    'cursor-not-allowed select-none text-base font-medium text-slate-400',
                                )}
                            >
                                <ModuleIcon moduleKey={module.key} />
                                <span className={labelClass}>{label}</span>
                            </span>
                        </li>
                    );
                })}
            </ul>
        </>
    ));

    /**
     * The chevron for a group. Only entries with something under them get one, and it only ever
     * folds — the name beside it is the link. Folded to icons on desktop there is nothing to
     * unfold into, so it steps aside there and the group's icons are all shown.
     */
    const renderGroupToggle = (key, label, controlsId) => {
        const open = isGroupOpen(openGroups, key);
        const toggleLabel = (open ? (modules.collapse_group ?? 'Skjul :label') : (modules.expand_group ?? 'Vis :label')).replace(':label', label);

        return (
            <button
                type="button"
                data-testid={`module-${key}-toggle`}
                onClick={() => onToggleGroup(key)}
                aria-expanded={open}
                aria-controls={controlsId}
                aria-label={toggleLabel}
                title={toggleLabel}
                className={classNames(
                    'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-500',
                    collapsed ? 'lg:hidden' : '',
                )}
            >
                <GroupChevron open={open} />
            </button>
        );
    };

    // A closed group's list leaves the layout — except folded to icons on desktop, where the
    // chevron is gone and every icon has to stay reachable.
    const groupListClass = (key) => (isGroupOpen(openGroups, key) ? '' : classNames('hidden', collapsed ? 'lg:block' : ''));

    const renderLink = (entry, isActive) => {
        const label = entry.label(modules);

        return (
            <Link
                href={entry.href}
                data-testid={`module-${entry.key}`}
                aria-current={isActive ? 'page' : undefined}
                title={collapsed ? label : undefined}
                className={classNames(
                    rowClass,
                    'text-base font-medium transition',
                    isActive
                        ? 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-200'
                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                )}
            >
                <ModuleIcon moduleKey={entry.key} />
                <span className={labelClass}>{label}</span>
            </Link>
        );
    };

    /**
     * A module's work areas, one level further in under a guide line of their own. The module row
     * above keeps its pill while one of them is open; the area carries `aria-current`. Collapsed to
     * icons there is no room for a third level, and the module's icon is enough — the header lists
     * the areas anyway.
     */
    const renderSubAreas = (module) => (
        <ul
            id={`module-${module.key}-areas`}
            data-testid={`module-${module.key}-areas`}
            className={classNames(
                'mt-0.5 ml-3 space-y-0.5 border-l border-slate-200 pl-2',
                collapsed ? 'lg:hidden' : '',
                isGroupOpen(openGroups, module.key) ? '' : 'hidden',
            )}
        >
            {module.subAreas.map((area) => {
                const isActive = activeAreaKey === area.key;

                return (
                    <li key={area.key}>
                        <Link
                            href={area.href}
                            data-testid={`module-area-${area.key}`}
                            aria-current={isActive ? 'page' : undefined}
                            className={classNames(
                                'flex items-center rounded-xl px-3 py-2 text-base leading-snug transition',
                                isActive ? 'font-semibold text-violet-700' : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                            )}
                        >
                            {area.label(modules)}
                        </Link>
                    </li>
                );
            })}
        </ul>
    );

    /**
     * An arbeidsområde and the modules of it this person can open — never the others.
     *
     * The workspace name is a link to its own landing page, and carries the pill only there; inside
     * one of its modules it is marked as the place you are in (violet, semibold, `data-active`)
     * while the module below carries the pill and `aria-current="page"` — one current page, and a
     * parent that is plainly the one it belongs to. The chevron beside it folds the modules away.
     *
     * Children are indented under a guide line in the full rail and drop their icons there; the
     * hierarchy comes from position and the line, not from shrinking the text. Collapsed to icons,
     * the indentation goes and each child is its own icon, as every other module is.
     */
    const renderWorkspace = (workspace) => {
        const label = workspace.label(modules);
        const onLanding = activeKey === workspace.key;
        const inside = activeWorkspace === workspace.key && ! onLanding;

        return (
            <>
                <div className="flex items-center gap-1">
                    <Link
                        href={workspace.href}
                        data-testid={`module-${workspace.key}`}
                        data-active={activeWorkspace === workspace.key ? 'true' : 'false'}
                        aria-current={onLanding ? 'page' : undefined}
                        title={collapsed ? label : undefined}
                        className={classNames(
                            rowClass,
                            'min-w-0 flex-1 text-base transition',
                            onLanding
                                ? 'bg-violet-50 font-semibold text-violet-700 ring-1 ring-inset ring-violet-200'
                                : inside
                                    ? 'font-semibold text-violet-700 hover:bg-slate-100'
                                    : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                        )}
                    >
                        <ModuleIcon moduleKey={workspace.key} />
                        <span className={labelClass}>{label}</span>
                    </Link>
                    {renderGroupToggle(workspace.key, label, `module-${workspace.key}-children`)}
                </div>

                <ul
                    id={`module-${workspace.key}-children`}
                    data-testid={`module-${workspace.key}-children`}
                    className={classNames(
                        'mt-0.5 ml-[1.375rem] space-y-0.5 border-l border-slate-200 pl-2',
                        collapsed ? 'lg:ml-0 lg:border-l-0 lg:pl-0' : '',
                        groupListClass(workspace.key),
                    )}
                >
                    {workspace.children.map((child) => {
                        const isActive = activeKey === child.key;
                        // With one of its work areas open, the area is the current page; the module
                        // keeps its pill as the place you are in, like a workspace does.
                        const areaOpen = isActive && (child.subAreas ?? []).some((area) => area.key === activeAreaKey);
                        const childLabel = child.label(modules);

                        return (
                            <li key={child.key}>
                                <div className="flex items-center gap-1">
                                    <Link
                                        href={child.href}
                                        data-testid={`module-${child.key}`}
                                        data-active={isActive ? 'true' : 'false'}
                                        aria-current={isActive && ! areaOpen ? 'page' : undefined}
                                        title={collapsed ? childLabel : undefined}
                                        className={classNames(
                                            rowClass,
                                            'min-w-0 flex-1 text-base font-medium transition',
                                            isActive
                                                ? 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-200'
                                                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                                        )}
                                    >
                                        <ModuleIcon
                                            moduleKey={child.key}
                                            className={classNames('h-5 w-5 shrink-0', collapsed ? 'hidden lg:block' : 'hidden')}
                                        />
                                        <span className={labelClass}>{childLabel}</span>
                                    </Link>
                                    {hasChildren(child) && renderGroupToggle(child.key, childLabel, `module-${child.key}-areas`)}
                                </div>
                                {child.subAreas && renderSubAreas(child)}
                            </li>
                        );
                    })}
                </ul>
            </>
        );
    };

    return (
        <nav
            data-testid="module-sidebar"
            data-collapsed={collapsed ? 'true' : 'false'}
            aria-label={modules.aria ?? 'Moduler'}
            className="rounded-2xl border border-slate-200/80 bg-white p-3 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
        >
            <ul className="space-y-0.5">
                {groups.entries.map((entry) => (
                    <li key={entry.key}>
                        {entry.children ? renderWorkspace(entry) : renderLink(entry, activeKey === entry.key)}
                    </li>
                ))}
            </ul>

            {renderUnavailableGroup(groups.planned, {
                testId: 'module-sidebar-planned-caption',
                caption: modules.planned_caption ?? 'Planlagt',
                hint: plannedHint,
            })}

            {/* Discreet, and desktop-only: on a phone the rail is the only module navigation
                there is, so nothing may fold it away. */}
            {onToggleCollapsed ? (
                <div
                    className={classNames(
                        'mt-3 hidden border-t border-slate-200/80 pt-2 lg:flex',
                        collapsed ? 'lg:justify-center' : 'lg:justify-end',
                    )}
                >
                    <button
                        type="button"
                        data-testid="module-sidebar-toggle"
                        onClick={onToggleCollapsed}
                        aria-expanded={! collapsed}
                        aria-label={toggleLabel}
                        title={toggleLabel}
                        className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-500"
                    >
                        <CollapseIcon collapsed={collapsed} />
                    </button>
                </div>
            ) : null}
        </nav>
    );
}
