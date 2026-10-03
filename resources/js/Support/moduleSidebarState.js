/**
 * Whether the module rail is collapsed, remembered per browser.
 *
 * This is a view preference, not data: it belongs to the person and the screen they are sitting
 * at, not to their account, so it stays in localStorage rather than travelling to the server.
 * Everything here degrades to "expanded" when storage is unavailable (private mode, blocked site
 * data), because an expanded rail is the state that works without explanation.
 */
const STORAGE_KEY = 'procynia.app.moduleSidebarCollapsed';

export function readModuleSidebarCollapsed() {
    if (typeof window === 'undefined') {
        return false;
    }

    try {
        return window.localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

export function writeModuleSidebarCollapsed(collapsed) {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
    } catch {
        // Keep the choice for this page only when storage is unavailable.
    }
}
