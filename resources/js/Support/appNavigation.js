import { APP_MODULES, APP_WORKSPACES, activeModuleKey, activeWorkspaceKey, railEntries } from './appModules.js';
import { complianceAreaKey } from './complianceNavigation.js';

/**
 * Where the person is, read off the page they are on — one answer for the whole layout.
 *
 * Every navigation level is decided here, from the URL and the props the page already received:
 * the area (and from it the rail's module and workspace), the header's module navigation, the
 * page-level row below it, and the breadcrumb trail. Nothing depends on the link that was clicked
 * to get here, so a direct URL, a reload, a link from Oppfølging, a notification or another module
 * all land in the same state.
 *
 * Pure on purpose: no React, no storage, no window. The one remembered value (the last AI case,
 * kept in localStorage) is read by the layout and handed in, so the whole thing is testable under
 * node:test. Access is never decided here either — the rail's own gates (railEntries) say which
 * modules this person may open, and a breadcrumb only links where the rail would.
 */

export function buildHref(path, query = {}) {
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

export function splitUrl(url) {
    const [pathname, search = ''] = String(url ?? '').split('?');

    return {
        pathname: pathname || '',
        searchParams: new URLSearchParams(search),
    };
}

function withMenuCount(label, count) {
    const normalized = Number(count ?? 0);

    return `${label} (${Number.isFinite(normalized) ? normalized : 0})`;
}

/**
 * The area a path belongs to. The rail's module and workspace follow from it
 * (appModules.activeModuleKey / activeWorkspaceKey), and so do the header's tabs.
 */
export function resolveArea(pathname, searchParams = new URLSearchParams()) {
    const noticeMode = searchParams.get('mode') ?? 'live';

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

    // Oppfølging: its own entry on the rail, across every module.
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
    // inside the Wiki area, so it must not render Wiki sub-tabs.
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

    // Leverandøroppfølging. Its own area, never `suppliers` — that is Anbud's /app/suppliers.
    if (pathname.startsWith('/app/supplier-management')) {
        return 'supplier-management';
    }

    if (pathname.startsWith('/app/management-reviews')) {
        return 'management-review';
    }

    if (pathname.startsWith('/app/billing')) {
        return 'billing';
    }

    return 'overview';
}

/**
 * Which of Kvalitet's main areas the page is in. The index carries it in `?tab=`; an item page
 * uses `?tab=` for its own level below (Dokument / Flyt), so there the area follows the item's
 * type — a process belongs under Prosesser, a control under Kontroller, the rest under Oversikt.
 */
export function qualityAreaTab(pathname, searchParams, item = null) {
    if (pathname.startsWith('/app/quality/items/')) {
        const type = item?.quality_type;

        return type === 'process' ? 'processes' : (type === 'control' ? 'controls' : 'overview');
    }

    return searchParams.get('tab') ?? 'overview';
}

/**
 * Where Besvarelse's I arbeid and AI instrukser lead: the case on the page, else the one the
 * person last worked in (when it is still visible to them), else the first case on the list.
 */
export function aiCaseHrefs(props = {}, lastStoredAiCaseId = null) {
    const currentAiCaseId = props.case?.id ?? null;
    const firstAvailableAiCaseId = props.analysisCases?.[0]?.id
        ? String(props.analysisCases[0].id)
        : null;
    const lastStoredIdIsVisible = lastStoredAiCaseId !== null
        && Array.isArray(props.analysisCases)
        && props.analysisCases.some((c) => String(c.id) === lastStoredAiCaseId);
    const rememberedAiCaseId = currentAiCaseId !== null && currentAiCaseId !== undefined
        ? String(currentAiCaseId)
        : lastStoredIdIsVisible
            ? lastStoredAiCaseId
            : (firstAvailableAiCaseId ?? lastStoredAiCaseId);

    return {
        currentAiCaseId,
        aiWorkHref: rememberedAiCaseId !== null ? `/app/ai/${rememberedAiCaseId}` : null,
        aiInstructionsHref: rememberedAiCaseId !== null ? `/app/ai/${rememberedAiCaseId}/instructions` : null,
    };
}

/**
 * The work areas inside Anbud.
 *
 * These four are the stages one bid passes through, plus the competitor view that informs
 * them. They are the level below the module, not modules themselves — the same relation
 * Kildedokumenter, Kjøringer, Wiki-sider and Grafvisning have to Wiki.
 */
export function moduleSectionsFor(moduleKey, translations = {}) {
    const navigation = translations.navigation ?? {};
    const frontend = translations.frontend ?? {};

    return moduleKey === 'tenders'
        ? [
            // Bid Status is the view across the four stages below it, so it comes first. It is
            // the only item here that is not itself a stage.
            { key: 'bid-status', label: navigation.bid_status ?? 'Bid Status', href: '/app/bid-status' },
            { key: 'procurements', label: navigation.notices ?? 'Kunngjøringer', href: '/app/notices' },
            { key: 'worklist', label: frontend.worklist_nav ?? 'Saksliste', href: buildHref('/app/notices', { mode: 'saved' }) },
            { key: 'ai', label: navigation.ai ?? 'Besvarelse', href: '/app/ai' },
            { key: 'suppliers', label: navigation.competitors ?? 'Konkurrenter', href: '/app/suppliers' },
        ]
        : [];
}

/**
 * The tabs inside one area. Where they are rendered depends on the module: inside Anbud they
 * are a third level and land on the page, while Wiki, Kvalitet, Leverandører, Etterlevelse og
 * revisjon and Kundemiljø have no area level above them, so theirs are the module navigation.
 *
 * Every item is gated exactly as before: an entry whose page a person cannot open is left out
 * (Watch lists, Kundemiljø, Vurderingsmaler) or carries no href (I arbeid without a case).
 */
export function areaTabsFor(area, { translations = {}, user = null, worklist = null, aiWorkHref = null, aiInstructionsHref = null } = {}) {
    const navigation = translations.navigation ?? {};
    const frontend = translations.frontend ?? {};
    const tw = translations.wiki ?? {};
    const tq = translations.quality ?? {};
    const tc = translations.compliance ?? {};
    const tsm = translations.supplier_management ?? {};
    const watchProfilesHref = user?.can_manage_watch_profiles ? '/app/watch-profiles' : null;
    const environmentHref = user?.can_manage_customer_users ? '/app/customer-environment' : null;

    if (area === 'ai') {
        return [
            { key: 'ai-overview', label: navigation.overview ?? 'Oversikt', href: '/app/ai' },
            { key: 'ai-work', label: navigation.worklist ?? 'I arbeid', href: aiWorkHref },
            { key: 'ai-instructions', label: navigation.ai_instructions ?? 'AI instrukser', href: aiInstructionsHref },
        ];
    }

    if (area === 'procurements') {
        return [
            { key: 'live', label: navigation.live_search ?? 'Live søk', href: '/app/notices' },
            { key: 'alerts', label: frontend.alerts_nav ?? 'Varsler', href: buildHref('/app/notices', { tab: 'alerts' }) },
            // Same permission gate as before, in a new place: a user who could not reach the
            // page from the main menu still cannot reach it from here.
            ...(watchProfilesHref
                ? [{ key: 'watch-profiles', label: navigation.watch_lists ?? 'Watch lists', href: watchProfilesHref }]
                : []),
        ];
    }

    if (area === 'worklist') {
        return [
            {
                key: 'saved',
                label: withMenuCount(navigation.registered_notices ?? 'Lagrede kunngjøringer', worklist?.saved_count),
                crumbLabel: navigation.registered_notices ?? 'Lagrede kunngjøringer',
                href: buildHref('/app/notices', { mode: 'saved' }),
            },
            {
                key: 'history',
                label: withMenuCount(navigation.history ?? 'Historikk', worklist?.history_count),
                crumbLabel: navigation.history ?? 'Historikk',
                href: buildHref('/app/notices', { mode: 'history' }),
            },
        ];
    }

    if (area === 'wiki') {
        return [
            // Ordered by the actual workflow: the source documents go in first, the runs show
            // what the system did with them, the pages are the result, and the graph is where
            // the connections are explored. Display order only — the active item is resolved
            // from the tab value, and /app/wiki still opens on Wiki-sider.
            { key: 'wiki-sources', label: tw.tab_sources ?? 'Kildedokumenter', href: buildHref('/app/wiki', { tab: 'sources' }) },
            { key: 'wiki-runs', label: tw.tab_runs ?? 'Kjøringer', href: buildHref('/app/wiki', { tab: 'runs' }) },
            { key: 'wiki-pages', label: tw.tab_pages ?? 'Wiki-sider', href: buildHref('/app/wiki', { tab: 'pages' }) },
            { key: 'wiki-graph', label: tw.tab_graph ?? 'Grafvisning', href: '/app/wiki/graph' },
        ];
    }

    if (area === 'quality') {
        // Same shape as Wiki's tabs: Kvalitet has no work areas of its own, so these sit in
        // the header directly under the rail's selection.
        return [
            { key: 'quality-overview', label: tq.tab_overview ?? 'Oversikt', href: buildHref('/app/quality', { tab: 'overview' }) },
            { key: 'quality-processes', label: tq.tab_processes ?? 'Prosesser', href: buildHref('/app/quality', { tab: 'processes' }) },
            { key: 'quality-controls', label: tq.tab_controls ?? 'Kontroller', href: buildHref('/app/quality', { tab: 'controls' }) },
            { key: 'quality-tools', label: tq.tab_tools ?? 'Verktøy', href: buildHref('/app/quality', { tab: 'tools' }) },
        ];
    }

    if (area === 'supplier-management') {
        // Leverandører: the register and the requirements every supplier is checked against. Same
        // shape as Kvalitet — the module's own tabs, in the header under the rail's selection.
        return [
            { key: 'supplier-suppliers', label: tsm.tabs?.suppliers ?? 'Leverandører', href: '/app/supplier-management' },
            { key: 'supplier-control-requirements', label: tsm.tabs?.control_requirements ?? 'Kontrollkrav', href: '/app/supplier-management/control-requirements' },
        ];
    }

    if (area === 'compliance') {
        // Etterlevelse og revisjon: four work areas in the order the work runs — choose standards,
        // see which requirements apply, assess them, audit them. Like Anbud's areas they sit in
        // the header, one level under the rail's single entry, and follow the person into every
        // page of the module. Free to move between, not a locked sequence.
        return [
            { key: 'compliance-library', label: tc.nav?.library ?? 'Kravbibliotek', href: '/app/compliance/library' },
            { key: 'compliance-requirements', label: tc.nav?.requirements ?? 'Kravregister', href: '/app/compliance/requirements' },
            { key: 'compliance-assessments', label: tc.nav?.assessments ?? 'Etterlevelsesvurdering', href: '/app/compliance/assessments' },
            { key: 'compliance-audits', label: tc.nav?.audits ?? 'Revisjon', href: '/app/compliance/audits' },
        ];
    }

    if (area === 'environment') {
        const items = [];
        if (environmentHref) {
            items.push({ key: 'env-settings', label: navigation.customer_environment ?? 'Kundemiljø', href: environmentHref });
        }
        if (user?.is_system_owner) {
            items.push({ key: 'go-no-go-templates', label: frontend.go_no_go_templates_nav ?? 'Vurderingsmaler', href: '/app/go-no-go-templates' });
        }
        return items;
    }

    return [];
}

/**
 * Which tab of the area is the page's own. Read off the URL, plus the item's type on a Kvalitet
 * item page.
 */
export function activeAreaTabKey(area, pathname, searchParams = new URLSearchParams(), props = {}) {
    const noticeMode = searchParams.get('mode') ?? 'live';
    const noticeTab = searchParams.get('tab') ?? (noticeMode === 'live' ? 'live' : null);

    if (area === 'ai') {
        if (/^\/app\/ai\/[^/]+\/instructions$/.test(pathname)) {
            return 'ai-instructions';
        }

        if (pathname === '/app/ai') {
            return 'ai-overview';
        }

        return 'ai-work';
    }

    if (area === 'procurements') {
        if (pathname.startsWith('/app/watch-profiles')) {
            return 'watch-profiles';
        }

        if (noticeTab === 'alerts') {
            return 'alerts';
        }

        return 'live';
    }

    if (area === 'worklist') {
        return noticeMode === 'history' ? 'history' : 'saved';
    }

    if (area === 'environment') {
        if (pathname.startsWith('/app/go-no-go-templates')) {
            return 'go-no-go-templates';
        }
        return 'env-settings';
    }

    if (area === 'quality') {
        return `quality-${qualityAreaTab(pathname, searchParams, props.item)}`;
    }

    if (area === 'supplier-management') {
        // A supplier's own page and the import belong to the register.
        return pathname.startsWith('/app/supplier-management/control-requirements')
            ? 'supplier-control-requirements'
            : 'supplier-suppliers';
    }

    if (area === 'compliance') {
        return complianceAreaKey(pathname, searchParams);
    }

    if (area === 'wiki') {
        if (pathname === '/app/wiki') {
            return `wiki-${searchParams.get('tab') ?? 'pages'}`;
        }

        if (pathname.startsWith('/app/wiki/graph')) {
            return 'wiki-graph';
        }

        return 'wiki-pages';
    }

    return null;
}

/* ------------------------------------------------------------------------------------------------
 * Breadcrumbs
 *
 * A breadcrumb names where the page lives in Procynia — never the route the person took to it. A
 * supplier opened from a task in Oppfølging reads «Styring › Leverandører › Acme AS», because that
 * is the page that is open.
 *
 * The rules, in one place:
 *  - Built from the same areas and tabs the rail and the header show, so the three never disagree.
 *  - A level links only where the person can actually go: a module only when the rail offers it,
 *    a tab only when the tab carries an href. Otherwise the level is plain text.
 *  - The last level is the page itself and is never a link.
 *  - A level that repeats the name above it is left out (Leverandører › Leverandører).
 *  - A detail page ends with the object's own name — the title the page already gives the layout.
 *  - A detail page's own tabs (Dokument / Flyt, Krav og kvalifikasjoner, …) are local views of the
 *    same page, so they are never levels of their own.
 *  - A trail of one is prefixed with Hjem, so a top-level page still says where it sits.
 *  - Hjem itself has no trail.
 *  - A work view the person opened with a button on the page — «Registrer leverandør», «Ny
 *    revisjon», «Rediger» — is the last level while it is open, even where it has no URL of its
 *    own. The page says so through the layout's `workView` (its own state; nothing is guessed from
 *    what happens to be visible), and the level above it becomes a link back to the page as it was
 *    opened. A form that is a permanent part of a page, and a dialog over it, are not work views.
 * --------------------------------------------------------------------------------------------- */

function crumb(label, href = null) {
    return { label: String(label ?? '').trim(), href: href ?? null };
}

function tabCrumb(tab) {
    return tab ? crumb(tab.crumbLabel ?? tab.label, tab.href) : null;
}

function normalized(label) {
    return String(label ?? '').trim().toLocaleLowerCase('nb-NO');
}

/**
 * The finishing pass every trail goes through: empty and repeated levels out, the current page
 * unlinked, a lone level placed under Hjem.
 */
export function finalizeTrail(levels, homeCrumb) {
    const trail = [];

    for (const level of levels) {
        if (! level || level.label === '') {
            continue;
        }

        if (trail.length > 0 && normalized(trail[trail.length - 1].label) === normalized(level.label)) {
            continue;
        }

        trail.push({ ...level });
    }

    if (trail.length === 0) {
        return [];
    }

    if (trail.length === 1 && homeCrumb) {
        trail.unshift({ ...homeCrumb });
    }

    trail[trail.length - 1].href = null;

    return trail;
}

/**
 * A work view opened on the page goes last, and the page it was opened on — until now the last
 * level — links back to itself as the URL has it, filters included. Leaving the view by that link
 * is an ordinary visit, so the view closes the way it would on any other navigation.
 */
export function withWorkView(levels, workView, currentUrl) {
    const label = String(workView ?? '').trim();

    if (label === '') {
        return levels;
    }

    const present = levels.filter((level) => level && level.label !== '');

    if (present.length === 0) {
        return levels;
    }

    // The page's own level, and any level above it that the trail will merge into it because it
    // carries the same name (Leverandører › Leverandører), lead back to the page.
    const pageLabel = normalized(present[present.length - 1].label);

    for (let index = present.length - 1; index >= 0 && normalized(present[index].label) === pageLabel; index -= 1) {
        present[index] = { ...present[index], href: currentUrl };
    }

    return [...present, crumb(label)];
}

/**
 * The module's own levels: Styring first where the module sits in it, then the module. Each
 * links only when the rail offers it to this person.
 */
function moduleTrail(moduleKey, modulesText, reachable) {
    const module = APP_MODULES.find((candidate) => candidate.key === moduleKey);

    if (! module) {
        return [];
    }

    const levels = [];

    if (module.workspace) {
        const workspace = APP_WORKSPACES.find((candidate) => candidate.key === module.workspace);

        if (workspace) {
            levels.push(crumb(workspace.label(modulesText), reachable.has(workspace.key) ? workspace.href : null));
        }
    }

    levels.push(crumb(module.label(modulesText), reachable.has(module.key) ? module.href : null));

    return levels;
}

/** The segments after a prefix: `/app/risk/risks/12` under `/app/risk/risks` → ['12']. */
function segmentsAfter(pathname, prefix) {
    if (pathname !== prefix && ! pathname.startsWith(`${prefix}/`)) {
        return null;
    }

    return pathname.slice(prefix.length).split('/').filter(Boolean);
}

/**
 * The trail for one page. `title` is what the page handed the layout — on a detail page the
 * object's own name, which is why no page has to describe its own breadcrumb.
 */
export function buildBreadcrumbs({ area, pathname, searchParams = new URLSearchParams(), props = {}, title = '', workView = null, currentUrl = null, moduleTabs = [], activeModuleTabKey = null, pageTabs = [], activePageTabKey = null, reachable = new Set() }) {
    const translations = props.translations ?? {};
    const navigation = translations.navigation ?? {};
    const modulesText = navigation.modules ?? {};
    const breadcrumbText = navigation.breadcrumbs ?? {};
    const user = props.auth?.user ?? null;
    const home = crumb(modulesText.home ?? 'Hjem', '/app/dashboard');
    const leaf = crumb(title);
    const moduleKey = activeModuleKey(area);
    const activeModuleTab = moduleTabs.find((tab) => tab.key === activeModuleTabKey) ?? null;
    const activePageTab = pageTabs.find((tab) => tab.key === activePageTabKey) ?? null;
    const finish = (levels) => finalizeTrail(withWorkView(levels, workView, currentUrl ?? pathname), home);

    // Hjem is the top; it needs no trail to say so.
    if (area === 'overview') {
        return [];
    }

    if (area === 'governance') {
        return finish([crumb(modulesText.governance ?? 'Styring', '/app/governance')]);
    }

    if (area === 'billing') {
        return finish([crumb(translations.billing?.nav || title || 'Abonnement', '/app/billing')]);
    }

    if (area === 'info-center') {
        const infoCenter = props.infoCenter ?? {};
        const activeView = (infoCenter.view_options ?? []).find((option) => option.value === infoCenter.active_view) ?? null;

        return finish([
            crumb(modulesText.follow_up ?? translations.frontend?.infosenter_nav ?? 'Oppfølging', '/app/info-center'),
            activeView ? crumb(activeView.label) : null,
        ]);
    }

    if (area === 'environment') {
        const environmentHref = user?.can_manage_customer_users ? '/app/customer-environment' : null;
        const root = crumb(navigation.customer_environment ?? 'Kundemiljø', environmentHref);
        const tabHref = (tab) => (environmentHref ? `${environmentHref}?tab=${tab}` : null);
        const users = crumb(breadcrumbText.users ?? 'Brukere', tabHref('users'));
        const departments = crumb(breadcrumbText.departments ?? 'Avdelinger', tabHref('departments'));

        if (pathname.startsWith('/app/go-no-go-templates')) {
            const templates = crumb(translations.frontend?.go_no_go_templates_nav ?? 'Vurderingsmaler', '/app/go-no-go-templates');

            return finish([root, templates, pathname === '/app/go-no-go-templates' ? null : leaf]);
        }

        if (pathname.startsWith('/app/users')) {
            return finish([root, users, pathname === '/app/users' ? null : leaf]);
        }

        if (pathname.startsWith('/app/departments')) {
            return finish([root, departments, pathname === '/app/departments' ? null : leaf]);
        }

        const tab = searchParams.get('tab');
        const current = tab === 'users'
            ? users
            : (tab === 'permissions' ? crumb(breadcrumbText.permissions ?? 'Tilganger') : departments);

        return finish([root, current]);
    }

    if (moduleKey === null) {
        return finish([leaf]);
    }

    const levels = moduleTrail(moduleKey, modulesText, reachable);

    // Anbud: the module, its work area, then whatever is inside that area.
    if (moduleKey === 'tenders') {
        levels.push(tabCrumb(activeModuleTab));

        if (area === 'ai') {
            const caseTitle = props.case?.title ?? '';
            const caseId = props.case?.id ?? null;

            if (pathname === '/app/ai' || caseId === null) {
                levels.push(tabCrumb(activePageTab));
            } else if (/^\/app\/ai\/[^/]+\/instructions$/.test(pathname)) {
                levels.push(crumb(caseTitle, `/app/ai/${caseId}`), tabCrumb(activePageTab));
            } else if (/^\/app\/ai\/[^/]+$/.test(pathname)) {
                levels.push(crumb(caseTitle || title));
            } else {
                levels.push(crumb(caseTitle, `/app/ai/${caseId}`), leaf);
            }

            return finish(levels);
        }

        if (area === 'procurements') {
            if (pathname === '/app/notices') {
                levels.push(tabCrumb(activePageTab));
            } else if (pathname.startsWith('/app/watch-profiles')) {
                levels.push(tabCrumb(activePageTab), pathname === '/app/watch-profiles' ? null : leaf);
            } else {
                levels.push(leaf);
            }

            return finish(levels);
        }

        if (area === 'worklist') {
            levels.push(pathname.startsWith('/app/notices/saved/') ? leaf : tabCrumb(activePageTab));

            return finish(levels);
        }

        if (area === 'suppliers') {
            levels.push(pathname === '/app/suppliers' ? null : leaf);
        }

        return finish(levels);
    }

    // Every other module: its own tabs (when it has any), then the object the page is about.
    levels.push(tabCrumb(activeModuleTab));

    switch (area) {
        case 'wiki':
            if (pathname !== '/app/wiki' && ! pathname.startsWith('/app/wiki/graph')) {
                levels.push(leaf);
            }
            break;

        case 'wiki-ask':
            levels.push(crumb(translations.wiki?.ask_nav ?? 'Spør Wiki'));
            break;

        case 'quality':
            if (pathname.startsWith('/app/quality/items/')) {
                levels.push(leaf);
            }
            break;

        case 'risk':
            if ((segmentsAfter(pathname, '/app/risk/risks') ?? []).length > 0) {
                levels.push(leaf);
            }
            break;

        case 'objectives': {
            const rest = segmentsAfter(pathname, '/app/objectives') ?? [];

            if (rest.length >= 3 && rest[1] === 'kpis') {
                // A KPI lives under its objective, which has its own page.
                levels.push(crumb(props.objective?.title, props.objective?.url ?? `/app/objectives/${rest[0]}`), leaf);
            } else if (rest.length >= 1) {
                levels.push(leaf);
            }
            break;
        }

        case 'improvements':
        case 'management-review':
            if (pathname.split('/').filter(Boolean).length > 2) {
                levels.push(leaf);
            }
            break;

        case 'compliance': {
            const rest = segmentsAfter(pathname, '/app/compliance') ?? [];

            if (rest.length >= 2) {
                levels.push(leaf);
            }
            break;
        }

        case 'supplier-management': {
            const rest = segmentsAfter(pathname, '/app/supplier-management') ?? [];

            if (rest.length >= 1 && rest[0] !== 'control-requirements') {
                levels.push(leaf);
            }
            break;
        }

        default:
            break;
    }

    return finish(levels);
}

/**
 * Everything the layout needs to draw the navigation for this page.
 */
export function resolveNavigation({ url = '', props = {}, title = '', workView = null, lastStoredAiCaseId = null } = {}) {
    const { pathname, searchParams } = splitUrl(url);
    const translations = props.translations ?? {};
    const user = props.auth?.user ?? null;
    const area = resolveArea(pathname, searchParams);
    const moduleKey = area === 'governance' ? 'governance' : activeModuleKey(area);
    const workspaceKey = activeWorkspaceKey(area);
    const { currentAiCaseId, aiWorkHref, aiInstructionsHref } = aiCaseHrefs(props, lastStoredAiCaseId);

    const moduleSections = moduleSectionsFor(moduleKey, translations);
    const areaTabs = areaTabsFor(area, { translations, user, worklist: props.worklist, aiWorkHref, aiInstructionsHref });
    const activeAreaTab = activeAreaTabKey(area, pathname, searchParams, props);

    /**
     * Two levels, decided by one fact: whether the active module has work areas of its own.
     *
     * Wiki has none, so its tabs *are* its module navigation — they sit in the header, directly
     * below the rail's selection. Anbud has: the header carries its work areas, and the tabs
     * belonging to whichever of those is open drop one level further down, onto the page.
     */
    const hasModuleAreas = moduleSections.length > 0;
    const moduleNavigation = hasModuleAreas ? moduleSections : areaTabs;
    const activeModuleNavigationKey = hasModuleAreas ? area : activeAreaTab;
    const pageNavigation = hasModuleAreas ? areaTabs : [];
    const activePageNavigationKey = hasModuleAreas ? activeAreaTab : null;

    // What the rail offers this person is what a breadcrumb may link to.
    const rail = railEntries(props.entitlements?.modules ?? [], props.access?.permissions ?? []);
    const reachable = new Set(rail.entries.flatMap((entry) => [entry.key, ...(entry.children ?? []).map((child) => child.key)]));

    const breadcrumbs = buildBreadcrumbs({
        area,
        pathname,
        searchParams,
        props,
        title,
        workView,
        currentUrl: url || pathname,
        moduleTabs: moduleNavigation,
        activeModuleTabKey: activeModuleNavigationKey,
        pageTabs: pageNavigation,
        activePageTabKey: activePageNavigationKey,
        reachable,
    });

    return {
        pathname,
        searchParams,
        area,
        moduleKey,
        workspaceKey,
        currentAiCaseId,
        aiWorkHref,
        aiInstructionsHref,
        moduleNavigation,
        activeModuleNavigationKey,
        pageNavigation,
        activePageNavigationKey,
        breadcrumbs,
    };
}
