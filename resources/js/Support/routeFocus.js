import { focusElement } from './focusManagement.js';

/**
 * What a keyboard or screen-reader user notices when Inertia swaps the page (WCAG 2.4.3 Focus Order,
 * 4.1.3 Status Messages; docs/wcag-accessibility-plan.md A11Y-102, A11Y-124).
 *
 * A full page load starts the screen reader at the top of a new document and reads its title. An
 * Inertia visit replaces the content without either: focus stays on the link that was pressed (or
 * falls to <body> when that link is gone), and nothing says a new page arrived. So after a *real*
 * page change, focus moves to the page's h1 — which reads the new page's name — or to <main> if the
 * page has none. Moving focus is the announcement; nothing is also spoken through a live region, so
 * the title is never heard twice.
 *
 * A real page change is a different page component, a different path, or a different document
 * title. The title matters because some areas are one component told apart by the query string —
 * Kunngjøringer and Saksliste are both `/app/notices` — and each still has a title of its own. A
 * visit that only changes the query string under the same title — a search, a filter, a tab,
 * pagination — or reloads the same page is an update of what the user is already working in, and
 * focus stays exactly where they are. Inertia does not fire `navigate` for replace visits (most
 * searches and filters here use `replace: true`) or for reloads that do not push history.
 *
 * Focus is also left alone when the new page has already put it somewhere inside <main> itself (an
 * autofocus field, an opened dialog): that page knows better.
 *
 * An update of the same page that re-mounts it (no `preserveState`) drops focus to <body>; then, and
 * only then, focus goes back to the re-rendered twin of the control that had it (A11Y-134).
 */

export const MAIN_CONTENT_ID = 'main-content';

/** The path of an Inertia page URL, without query string or fragment. */
export function pathOf(url) {
    return String(url ?? '').split(/[?#]/)[0];
}

/**
 * Whether going from `previous` to `next` is a new page rather than an update of the same one. A
 * title is compared only when both sides know theirs.
 *
 * @param {{ component?: string, url?: string, title?: string }|null} previous
 * @param {{ component?: string, url?: string, title?: string }|null} next
 */
export function isPageChange(previous, next) {
    if (! previous || ! next) {
        return false;
    }

    const titleChanged = previous.title !== undefined && next.title !== undefined && previous.title !== next.title;

    return previous.component !== next.component || pathOf(previous.url) !== pathOf(next.url) || titleChanged;
}

/** The `<html lang>` value for a shared locale («nb_NO» → «nb-NO»), as app.blade.php writes it. */
export function documentLang(locale) {
    const value = String(locale ?? '').trim().replace(/_/g, '-');

    return value === '' ? null : value;
}

/**
 * Whether the page has already placed focus deliberately: on something inside `main` other than
 * `main` itself, or inside an open modal dialog.
 */
export function pageOwnsFocus(active, main, body) {
    if (! active || active === body) {
        return false;
    }

    if (active.closest?.('[aria-modal="true"]')) {
        return true;
    }

    return Boolean(main && active !== main && main.contains(active));
}

/** The element a new page's focus goes to: its first h1 in <main>, otherwise <main>. */
export function routeFocusTarget(doc = document) {
    const main = doc.getElementById(MAIN_CONTENT_ID) ?? doc.querySelector('main');

    if (! main) {
        return null;
    }

    return main.querySelector('h1') ?? main;
}

/**
 * What identifies a focused control well enough to find "the same one" after its page re-rendered:
 * its id, or its tag with the attributes and text a re-render keeps. Null for nothing worth keeping.
 */
export function describeFocus(element, body = null) {
    if (! element || element === body || typeof element.tagName !== 'string') {
        return null;
    }

    const attribute = (name) => element.getAttribute?.(name) ?? null;

    return {
        id: element.id || null,
        tag: element.tagName.toLowerCase(),
        href: attribute('href'),
        name: attribute('name'),
        type: attribute('type'),
        testId: attribute('data-testid'),
        label: attribute('aria-label'),
        text: String(element.textContent ?? '').trim(),
    };
}

/** Whether `element` is the control `description` was taken from (or its re-rendered twin). */
export function matchesFocus(element, description) {
    if (! element || ! description || element.tagName?.toLowerCase() !== description.tag) {
        return false;
    }

    if (description.id) {
        return element.id === description.id;
    }

    const attribute = (name) => element.getAttribute?.(name) ?? null;

    return attribute('href') === description.href
        && attribute('name') === description.name
        && attribute('type') === description.type
        && attribute('data-testid') === description.testId
        && attribute('aria-label') === description.label
        && String(element.textContent ?? '').trim() === description.text;
}

/** The element on the current page matching `description`, or null. */
export function findFocusTwin(doc, description) {
    if (! description) {
        return null;
    }

    if (description.id) {
        const byId = doc.getElementById(description.id);

        return matchesFocus(byId, description) ? byId : null;
    }

    return Array.from(doc.querySelectorAll(description.tag)).find((element) => matchesFocus(element, description)) ?? null;
}

/**
 * Listens to Inertia's router: keeps `<html lang>` in step with the shared locale, moves focus after a
 * real page change, and puts focus back where it was after an update of the same page that dropped it
 * (A11Y-134). Returns the unsubscribe function.
 *
 * The last part: a visit to the same page without `preserveState` — a `<Link>` that only changes the
 * query string, a form that redirects back — re-mounts the page, and the control the user pressed is
 * replaced by a new one. Focus falls to <body>, and the next Tab starts at the top of the document.
 * When that has happened, and only then, focus goes to the re-rendered twin of the control that had it
 * when the visit started. If there is none, focus is left alone rather than sent anywhere.
 *
 * @param {{ on: Function }} router Inertia's router.
 */
export function installRouteFocus(router, { initialPage = null, doc = document, win = window } = {}) {
    // Seeded from the page the app booted with: Inertia may fire the initial `navigate` before this
    // listener exists, and the first real visit must still be recognised as one. Its title is read when
    // a visit starts — by then <Head> has set it; read at boot it would still be the blade default.
    let previous = initialPage ? { component: initialPage.component, url: initialPage.url } : null;
    // The control that had focus when the current visit started, and whether that visit was a page
    // change (then the heading has focus, and nothing is restored).
    let focusAtStart = null;
    let pageChanged = false;

    // `navigate` fires once Inertia has swapped the page; React commits, and <Head> sets the title,
    // on the next frame. Callbacks run in the order they were scheduled.
    const afterRender = (callback) => win.requestAnimationFrame(() => win.setTimeout(callback, 0));

    const stopStart = router.on('start', () => {
        if (previous) {
            previous = { ...previous, title: doc.title };
        }

        focusAtStart = describeFocus(doc.activeElement, doc.body);
        pageChanged = false;
    });

    const stopNavigate = router.on('navigate', (event) => {
        const page = event?.detail?.page ?? null;
        const lang = documentLang(page?.props?.locale);

        if (lang && doc.documentElement.lang !== lang) {
            doc.documentElement.lang = lang;
        }

        if (! page) {
            return;
        }

        afterRender(() => {
            const next = { component: page.component, url: page.url, title: doc.title };
            // The initial load is no change: the browser has already done what a page load does.
            const changed = isPageChange(previous, next);
            previous = next;

            if (! changed) {
                return;
            }

            pageChanged = true;
            const main = doc.getElementById(MAIN_CONTENT_ID) ?? doc.querySelector('main');

            if (pageOwnsFocus(doc.activeElement, main, doc.body)) {
                return;
            }

            // preventScroll: Inertia has just reset or restored the scroll position (back/forward),
            // and focusing must not undo that.
            focusElement(routeFocusTarget(doc), { preventScroll: true });
        });
    });

    // `finish` ends every visit — pushed, replaced, partial, or rejected with validation errors — and
    // comes after `navigate`, so its callback runs after the page-change decision above.
    const stopFinish = router.on('finish', () => {
        const description = focusAtStart;
        focusAtStart = null;

        afterRender(() => {
            const active = doc.activeElement;

            if (pageChanged || ! description || (active && active !== doc.body)) {
                return;
            }

            const twin = findFocusTwin(doc, description);

            if (twin) {
                twin.focus({ preventScroll: true });
            }
        });
    });

    return () => {
        stopStart?.();
        stopNavigate?.();
        stopFinish?.();
    };
}
