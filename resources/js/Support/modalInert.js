/**
 * The page behind an open modal dialog cannot be used (WCAG 2.4.3, 4.1.2; A11Y-020).
 *
 * `aria-modal="true"` asks assistive technology to stay inside the dialog, but not every screen
 * reader honours it in browse mode, and it does nothing for a mouse or for focus moved by script.
 * `inert` does all three: the content is out of the accessibility tree, cannot be clicked and
 * cannot take focus. The dialog itself must stay live, and ActionDialog renders in place — inside
 * the app root — so marking the root inert would switch the dialog off with it. Instead every
 * *sibling* along the path from the dialog up to <body> is made inert: everything except the
 * dialog and the elements that contain it.
 *
 * Kept live on purpose: the app-wide live regions and the toast host (Support/announcer.js). An inert
 * live region is out of the accessibility tree, so a status message sent while a dialog is open
 * would never be spoken.
 *
 * Nesting: a second dialog opened from the first runs the same walk. If the new dialog sits inside
 * something the first one made inert, that ancestor is released for as long as the new dialog is
 * open; everything else the first dialog marked stays marked. Each call returns its own undo, and
 * dialogs close last-opened first, so the undos unwind in order. An element that was already inert
 * before a dialog opened is never touched.
 */

const KEEP_LIVE_SELECTOR = '[aria-live], #procynia-toast';

// Every element this module has made inert and not yet released, so that nothing can be left inert
// once the last dialog is gone — whatever happened in between (see pushModal).
const owned = new Set();

function keepsLive(element) {
    return typeof element.matches === 'function' && element.matches(KEEP_LIVE_SELECTOR);
}

/**
 * Make everything outside `dialogRoot` inert. Returns the function that undoes exactly this call.
 *
 * @param {HTMLElement} dialogRoot The outermost element of the dialog (its backdrop).
 * @returns {() => void}
 */
export function inertOutside(dialogRoot) {
    const madeInert = [];
    const released = [];

    if (! dialogRoot?.ownerDocument) {
        return () => {};
    }

    const body = dialogRoot.ownerDocument.body;
    let node = dialogRoot;

    while (node && node !== body && node.parentElement) {
        const parent = node.parentElement;

        // An ancestor made inert by an outer dialog would switch this one off as well.
        if (parent !== body && parent.hasAttribute('inert')) {
            parent.removeAttribute('inert');
            released.push(parent);
        }

        for (const sibling of Array.from(parent.children)) {
            if (sibling === node || keepsLive(sibling) || sibling.hasAttribute('inert')) {
                continue;
            }

            sibling.setAttribute('inert', '');
            madeInert.push(sibling);
            owned.add(sibling);
        }

        node = parent;
    }

    let undone = false;

    return () => {
        if (undone) {
            return;
        }

        undone = true;

        for (const element of madeInert) {
            element.removeAttribute('inert');
            owned.delete(element);
        }

        // Only while an outer dialog is still open: its marks are what is being given back.
        for (const element of released) {
            if (openDialogs.length > 0) {
                element.setAttribute('inert', '');
                owned.add(element);
            }
        }
    };
}

/**
 * The open modal dialogs, last-opened last. Only the top one answers Escape and Tab: with two open,
 * a single Escape closing both would throw away the outer dialog the user meant to return to.
 */
const openDialogs = [];

export function pushModal(token) {
    openDialogs.push(token);

    return () => {
        const index = openDialogs.lastIndexOf(token);

        if (index !== -1) {
            openDialogs.splice(index, 1);
        }

        // The safety net: with no dialog open, nothing this module marked may stay inert — not after
        // dialogs closed out of order, not after an error between marking and the undo.
        if (openDialogs.length === 0) {
            releaseAllInert();
        }
    };
}

/** Releases everything this module made inert. Called when the last dialog closes. */
export function releaseAllInert() {
    for (const element of owned) {
        element.removeAttribute('inert');
    }

    owned.clear();
}

export function isTopModal(token) {
    return openDialogs.length > 0 && openDialogs[openDialogs.length - 1] === token;
}
