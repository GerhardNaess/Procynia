/**
 * Which rail groups are open — Styring, and a module with work areas under it (Etterlevelse og
 * revisjon) — remembered per browser.
 *
 * Like the rail's own width (moduleSidebarState.js) this is a view preference, not data, so it
 * stays in localStorage and never travels to the server. Every group is open unless the person
 * closed it: an open group is the state that needs no explanation, and it is what a browser with
 * no storage gets.
 *
 * The page always wins over the preference. A group that holds the page you are on is opened when
 * you arrive there (`requiredOpenGroups`), so the current page can never sit hidden under a closed
 * parent. Arriving does not write anything: only a click on a chevron is remembered.
 */
const STORAGE_KEY = 'procynia.app.navigationGroups';

/**
 * Whether a rail entry has anything to fold away — a workspace's modules, or a module's work
 * areas. Only these get a chevron.
 */
export function hasChildren(entry) {
    return (entry?.children?.length ?? 0) > 0 || (entry?.subAreas?.length ?? 0) > 0;
}

/**
 * Open unless the person closed it.
 */
export function isGroupOpen(openGroups, key) {
    return openGroups?.[key] !== false;
}

export function toggleGroup(openGroups, key) {
    return { ...openGroups, [key]: ! isGroupOpen(openGroups, key) };
}

/**
 * The groups the current page sits inside: its workspace, and the module itself when the module
 * folds work areas under it. On /app/compliance/audits that is Styring and Etterlevelse og
 * revisjon.
 */
export function requiredOpenGroups(entries = [], { activeWorkspace = null, activeKey = null } = {}) {
    const keys = [];

    for (const entry of entries) {
        if (entry.children && entry.key === activeWorkspace) {
            keys.push(entry.key);
        }

        for (const module of [entry, ...(entry.children ?? [])]) {
            if (module.key === activeKey && (module.subAreas?.length ?? 0) > 0) {
                keys.push(module.key);
            }
        }
    }

    return keys;
}

export function withGroupsOpen(openGroups, keys) {
    if (keys.every((key) => isGroupOpen(openGroups, key))) {
        return openGroups;
    }

    const next = { ...openGroups };

    for (const key of keys) {
        next[key] = true;
    }

    return next;
}

export function readOpenGroups() {
    if (typeof window === 'undefined') {
        return {};
    }

    try {
        const stored = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '{}');

        return stored && typeof stored === 'object' && ! Array.isArray(stored) ? stored : {};
    } catch {
        return {};
    }
}

export function writeOpenGroups(openGroups) {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(openGroups));
    } catch {
        // Keep the choice for this page only when storage is unavailable.
    }
}
