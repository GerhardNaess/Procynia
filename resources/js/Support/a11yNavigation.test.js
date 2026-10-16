import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { describeFocus, documentLang, findFocusTwin, installRouteFocus, isPageChange, MAIN_CONTENT_ID, matchesFocus, pageOwnsFocus, pathOf } from './routeFocus.js';
import { LIVE_REGION_IDS, TOAST_HOST_ID, ensureLiveRegions } from './announcer.js';
import { calendarDayLabel } from '../Components/App/dashboardLogic.js';
import { horizontalShift } from './useKeepInViewport.js';

const here = dirname(fileURLToPath(import.meta.url));
const read = (...parts) => readFileSync(join(here, '..', ...parts), 'utf8');

/**
 * Fase 2 of the WCAG plan (docs/wcag-accessibility-plan.md): layout, navigation, page changes and
 * flash messages. The behaviour in a real browser is in tests/e2e/a11y-navigation.spec.js; these pin
 * the rules.
 */
describe('a page change is told apart from an update of the same page (A11Y-102)', () => {
    test('a different path is a new page', () => {
        assert.equal(isPageChange({ component: 'App/Risk/Index', url: '/app/risk' }, { component: 'App/Risk/Show', url: '/app/risk/4' }), true);
        assert.equal(isPageChange({ component: 'App/Risk/Show', url: '/app/risk/4' }, { component: 'App/Risk/Show', url: '/app/risk/5' }), true);
    });

    test('a search, a filter, a tab or a page of results is not', () => {
        assert.equal(isPageChange({ component: 'App/Risk/Index', url: '/app/risk' }, { component: 'App/Risk/Index', url: '/app/risk?search=brann' }), false);
        assert.equal(isPageChange({ component: 'App/Wiki/Index', url: '/app/wiki?tab=sources' }, { component: 'App/Wiki/Index', url: '/app/wiki?tab=runs&page=2' }), false);
        assert.equal(isPageChange({ component: 'App/Wiki/Index', url: '/app/wiki' }, { component: 'App/Wiki/Index', url: '/app/wiki#runs' }), false);
    });

    test('the same path with another component is a new page (a redirect to a different view)', () => {
        assert.equal(isPageChange({ component: 'App/A', url: '/app/x' }, { component: 'App/B', url: '/app/x' }), true);
    });

    test('one component told apart by its query string is a new page when its title changes (Kunngjøringer → Saksliste)', () => {
        assert.equal(isPageChange({ component: 'App/Notices/Index', url: '/app/notices', title: 'Kunngjøringer · Procynia' }, { component: 'App/Notices/Index', url: '/app/notices?mode=saved', title: 'Saksliste · Procynia' }), true);
        assert.equal(isPageChange({ component: 'App/Notices/Index', url: '/app/notices', title: 'Kunngjøringer · Procynia' }, { component: 'App/Notices/Index', url: '/app/notices?q=vei', title: 'Kunngjøringer · Procynia' }), false);
        assert.equal(isPageChange({ component: 'App/A', url: '/app/x' }, { component: 'App/A', url: '/app/x?y=1', title: 'A' }), false, 'an unknown title is not a change');
    });

    test('nothing to compare with is no change — the initial load is not moved', () => {
        assert.equal(isPageChange(null, { component: 'App/A', url: '/app/x' }), false);
    });

    test('the path ignores query and fragment', () => {
        assert.equal(pathOf('/app/risk?x=1#y'), '/app/risk');
        assert.equal(pathOf(undefined), '');
    });
});

describe('focus after a page change goes to the new page, unless the page placed it itself', () => {
    const main = { contains: (element) => element.inMain === true };
    const body = { id: 'body' };

    test('focus left on <body> or nothing is moved', () => {
        assert.equal(pageOwnsFocus(body, main, body), false);
        assert.equal(pageOwnsFocus(null, main, body), false);
    });

    test('focus a page put on a field of its own is left there', () => {
        assert.equal(pageOwnsFocus({ inMain: true, closest: () => null }, main, body), true);
    });

    test('focus inside an open modal dialog is left there', () => {
        assert.equal(pageOwnsFocus({ inMain: false, closest: (selector) => (selector === '[aria-modal="true"]' ? {} : null) }, main, body), true);
    });

    test('focus still on the pressed rail or header link (outside <main>) is moved', () => {
        assert.equal(pageOwnsFocus({ inMain: false, closest: () => null }, main, body), false);
    });

    test('the listener moves focus only on a real change, after the frame, without scrolling', () => {
        const handlers = [];
        const router = { on: (name, handler) => { handlers.push({ name, handler }); return () => {}; } };
        const focused = [];
        const heading = { matches: () => false, hasAttribute: () => false, setAttribute() {}, focus: (options) => focused.push(options) };
        const mainElement = { querySelector: (selector) => (selector === 'h1' ? heading : null), contains: () => false };
        const doc = {
            title: 'Hjem · Procynia',
            documentElement: { lang: 'nb-NO' },
            body: {},
            activeElement: null,
            getElementById: (id) => (id === MAIN_CONTENT_ID ? mainElement : null),
            querySelector: () => null,
        };
        doc.activeElement = doc.body;
        const win = { requestAnimationFrame: (callback) => callback(), setTimeout: (callback) => callback() };

        installRouteFocus(router, { initialPage: { component: 'App/Home/Index', url: '/app/dashboard' }, doc, win });
        assert.deepEqual(handlers.map((entry) => entry.name), ['start', 'navigate', 'finish']);
        const on = (name) => handlers.find((entry) => entry.name === name).handler;
        const navigate = (component, url, locale = 'no') => on('navigate')({ detail: { page: { component, url, props: { locale } } } });

        navigate('App/Home/Index', '/app/dashboard');
        assert.equal(focused.length, 0, 'the initial navigate event moves nothing');

        navigate('App/Risk/Index', '/app/risk', 'en');
        assert.deepEqual(focused, [{ preventScroll: true }]);
        assert.equal(doc.documentElement.lang, 'en', '<html lang> follows the shared locale (A11Y-124)');

        navigate('App/Risk/Index', '/app/risk?search=brann', 'en');
        assert.equal(focused.length, 1, 'a search on the same page leaves focus alone');
    });

    test('a title that <Head> writes after the frame still makes a same-path visit a page change (OPPF-001)', () => {
        const handlers = [];
        const router = { on: (name, handler) => { handlers.push({ name, handler }); return () => {}; } };
        const on = (name) => handlers.find((entry) => entry.name === name).handler;
        const focused = [];
        const heading = { matches: () => false, hasAttribute: () => false, setAttribute() {}, focus: (options) => focused.push(options) };
        const mainElement = { querySelector: (selector) => (selector === 'h1' ? heading : null), contains: () => false };
        const link = { tagName: 'A', id: '', textContent: 'Saksliste', getAttribute: (name) => (name === 'href' ? '/app/notices?mode=saved' : null), closest: () => null };
        const observers = [];
        class FakeObserver {
            constructor(callback) { this.callback = callback; this.connected = false; observers.push(this); }
            observe() { this.connected = true; }
            disconnect() { this.connected = false; }
        }
        const timers = [];
        const doc = {
            title: 'Kunngjøringer · Procynia',
            documentElement: { lang: 'no' },
            head: {},
            body: { tagName: 'BODY' },
            activeElement: link,
            getElementById: (id) => (id === MAIN_CONTENT_ID ? mainElement : null),
            querySelector: () => null,
            querySelectorAll: () => [],
        };
        const win = {
            requestAnimationFrame: (callback) => callback(),
            setTimeout: (callback, delay) => { if (delay) { timers.push(callback); } else { callback(); } return timers.length; },
            clearTimeout() {},
            MutationObserver: FakeObserver,
        };
        installRouteFocus(router, { initialPage: { component: 'App/Notices/Index', url: '/app/notices' }, doc, win });

        // The visit: the frame has passed, but the title is still the old one.
        on('start')();
        on('navigate')({ detail: { page: { component: 'App/Notices/Index', url: '/app/notices?mode=saved', props: {} } } });
        on('finish')();
        assert.equal(focused.length, 0, 'with the old title it looks like a filter');
        assert.equal(observers.length, 1);

        // A mutation that leaves the title as it was changes nothing.
        observers[0].callback();
        assert.equal(focused.length, 0);

        // Then <Head> writes the new title, while focus is still on the pressed link.
        doc.title = 'I arbeid · Procynia';
        observers[0].callback();
        assert.deepEqual(focused, [{ preventScroll: true }]);
        assert.equal(observers[0].connected, false, 'the watch ends with the title');

        // A filter whose title never changes: the watch ends at the next visit, and nothing moves.
        on('start')();
        on('navigate')({ detail: { page: { component: 'App/Notices/Index', url: '/app/notices?mode=saved&q=vei', props: {} } } });
        assert.equal(observers.length, 2);
        on('start')();
        assert.equal(observers[1].connected, false);
        assert.equal(focused.length, 1);

        // A late title after the user has moved on does not pull focus back.
        doc.activeElement = link;
        on('start')();
        on('navigate')({ detail: { page: { component: 'App/Notices/Index', url: '/app/notices', props: {} } } });
        doc.activeElement = { tagName: 'INPUT', id: 'elsewhere', textContent: '', getAttribute: () => null, closest: () => null };
        doc.title = 'Kunngjøringer · Procynia';
        observers[2].callback();
        assert.equal(focused.length, 1);
    });

    test('the locale becomes a valid lang attribute', () => {
        assert.equal(documentLang('nb_NO'), 'nb-NO');
        assert.equal(documentLang('en'), 'en');
        assert.equal(documentLang(''), null);
    });
});

describe('the layouts give keyboard and screen-reader users a way past the navigation (A11Y-100, -101)', () => {
    const customer = read('Layouts', 'CustomerAppLayout.jsx');
    const publicLayout = read('Layouts', 'PublicLayout.jsx');

    test('the skip link comes before the header in both layouts, and <main> is its target', () => {
        for (const layout of [customer, publicLayout]) {
            assert.ok(layout.indexOf('<SkipLink') > -1 && layout.indexOf('<SkipLink') < layout.indexOf('<header'), 'skip link first');
            assert.match(layout, /<main id=\{MAIN_CONTENT_ID\} tabIndex=\{-1\}/);
        }
    });

    test('every navigation landmark in the app layout has a name', () => {
        assert.match(customer, /<nav aria-label=\{ariaLabel\}/);
        assert.match(customer, /ariaLabel=\{a11y\.module_navigation/);
        assert.match(customer, /ariaLabel=\{a11y\.page_navigation/);
    });

    test('the user menu is a disclosure, not an ARIA menu without the menu keyboard', () => {
        assert.ok(! customer.includes('aria-haspopup'));
        assert.match(customer, /aria-controls="app-user-menu"/);
        assert.match(customer, /id="app-user-menu" data-escape-layer=""/);
    });

    test('the user button\'s screen-reader suffix is clipped with the name, so a long name cannot widen the page', () => {
        assert.match(customer, /<span className="relative block truncate text-base font-semibold text-slate-900">\s*\n\s*\{userName\}/);
    });

    test('the help panel never starts above the window when the header has scrolled away', () => {
        assert.match(read('Components', 'App', 'PageHelpPanel.jsx'), /Math\.max\(0, Math\.round\(header\.getBoundingClientRect\(\)\.bottom\)\)/);
    });

    test('a filter dropdown gives focus back without scrolling, so an outside click is not lost', () => {
        assert.match(read('Components', 'App', 'MultiSelectFilterDropdown.jsx'), /buttonRef\.current\?\.focus\(\{ preventScroll: true \}\)/);
    });

    test('the header is sticky only where it does not take the window (1.4.10)', () => {
        assert.match(customer, /<header ref=\{headerRef\} className="z-\[60\] border-b lg:sticky lg:top-0/);
    });

    test('the public pages have a menu below lg, and no fake language control (A11Y-022, -122)', () => {
        assert.match(publicLayout, /aria-controls="public-mobile-menu"/);
        assert.match(publicLayout, /aria-expanded=\{isMenuOpen\}/);
        assert.ok(! publicLayout.includes('localeLabel'));
        assert.ok(! /<span[^>]*>⌄<\/span>/.test(publicLayout));
    });
});

describe('flash messages stay, can be closed, and are read once (A11Y-012, -020)', () => {
    const flash = read('Components', 'App', 'FlashMessages.jsx');

    test('the toast is never hidden on a timer', () => {
        assert.ok(! /setTimeout/.test(flash));
    });

    test('the close button is portalled beside the live region, not into it', () => {
        assert.match(flash, /liveRegion\('toastActions'\)/);
        assert.match(flash, /aria-label=\{closeLabel\}/);
    });

    test('a warning is announced like an error', () => {
        assert.match(flash, /role="alert"\s*\n\s*className=\{`\$\{bannerClassName\} rounded-2xl border border-amber-200/);
    });

    test('the toast host holds the live region and a silent slot for its button', () => {
        const elements = new Map();
        const make = () => {
            const element = { id: '', className: '', attributes: {}, children: [], setAttribute(name, value) { this.attributes[name] = value; }, appendChild(child) { this.children.push(child); elements.set(child.id, child); } };

            return element;
        };
        const body = make();
        const doc = { body, createElement: make, getElementById: (id) => elements.get(id) ?? null };

        ensureLiveRegions(doc);
        ensureLiveRegions(doc);

        const host = elements.get(TOAST_HOST_ID);
        assert.deepEqual(host.children.map((child) => child.id), [LIVE_REGION_IDS.toast, LIVE_REGION_IDS.toastActions]);
        assert.equal(elements.get(LIVE_REGION_IDS.toast).attributes['aria-live'], 'polite');
        assert.equal(elements.get(LIVE_REGION_IDS.toastActions).attributes['aria-live'], undefined, 'the button slot is not live');
        assert.equal(body.children.filter((child) => child.id === TOAST_HOST_ID).length, 1, 'idempotent');
    });

    test('the toast sits above the header and dialogs, and lets clicks through', () => {
        const css = read('..', 'css', 'app.css');
        assert.match(css, /#procynia-toast \{[^}]*z-index: 80;[^}]*pointer-events: none;/);
        assert.match(css, /#procynia-toast-actions \{[^}]*pointer-events: auto;/);
    });
});

describe('the dashboard calendar says what each day is (A11Y-023)', () => {
    const day = { date: new Date(2026, 9, 14), isToday: false };

    test('full date and the number of deadlines', () => {
        assert.equal(calendarDayLabel(day, 2, 'nb-NO', { deadline_count_many: ':count frister' }), 'onsdag 14. oktober 2026, 2 frister');
        assert.equal(calendarDayLabel(day, 1, 'nb-NO', { deadline_count_one: '1 frist' }), 'onsdag 14. oktober 2026, 1 frist');
    });

    test('a day without deadlines is only its date; today says so', () => {
        assert.equal(calendarDayLabel(day, 0, 'en-GB'), 'Wednesday, 14 October 2026');
        assert.equal(calendarDayLabel({ ...day, isToday: true }, 0, 'nb-NO', { today: 'i dag' }), 'onsdag 14. oktober 2026, i dag');
    });

    test('no links inside the day buttons any more', () => {
        const cockpit = read('Components', 'App', 'DashboardCockpit.jsx');
        assert.ok(! cockpit.includes('DeadlinePopover'));
        assert.match(cockpit, /aria-pressed=\{isSelected\}/);
        assert.match(cockpit, /aria-current=\{day\.isToday \? 'date' : undefined\}/);
    });
});

describe('header panels stay inside a narrow window (1.4.10)', () => {
    test('a panel hanging past the left edge moves right to the gutter', () => {
        assert.equal(horizontalShift({ left: -32, right: 256 }, 320), 48);
    });

    test('a panel past the right edge moves left, but never past the left gutter', () => {
        assert.equal(horizontalShift({ left: 100, right: 340 }, 320), -36);
        assert.equal(horizontalShift({ left: 20, right: 400 }, 320), -4);
    });

    test('a panel that fits is left alone', () => {
        assert.equal(horizontalShift({ left: 40, right: 300 }, 320), 0);
    });
});

describe('focus dropped by an update of the same page goes back to the same control (A11Y-134)', () => {
    const element = (tag, attributes = {}, text = '', id = '') => ({
        tagName: tag.toUpperCase(),
        id,
        textContent: text,
        getAttribute: (name) => attributes[name] ?? null,
        focused: 0,
        focus() { this.focused += 1; },
    });

    test('a control is described by id, or by its tag, attributes and text', () => {
        assert.equal(describeFocus(null), null);
        const body = element('body');
        assert.equal(describeFocus(body, body), null, '<body> is not worth keeping');
        const link = element('a', { href: '/app/notices?page=2' }, ' 2 ');
        assert.deepEqual(describeFocus(link), { id: null, tag: 'a', href: '/app/notices?page=2', name: null, type: null, testId: null, label: null, text: '2' });
    });

    test('the re-rendered twin matches; a neighbour does not', () => {
        const description = describeFocus(element('a', { href: '/app/notices?page=2' }, '2'));
        assert.equal(matchesFocus(element('a', { href: '/app/notices?page=2' }, '2'), description), true);
        assert.equal(matchesFocus(element('a', { href: '/app/notices?page=3' }, '3'), description), false);
        assert.equal(matchesFocus(element('button', {}, '2'), description), false);
    });

    test('an id decides on its own', () => {
        const twin = element('input', { type: 'search' }, '', 'risk-search');
        const doc = { getElementById: (id) => (id === 'risk-search' ? twin : null), querySelectorAll: () => [] };
        assert.equal(findFocusTwin(doc, describeFocus(element('input', { type: 'search' }, '', 'risk-search'))), twin);
    });

    test('after a same-page visit that dropped focus to <body>, the twin gets it back; otherwise nothing moves', () => {
        const handlers = [];
        const router = { on: (name, handler) => { handlers.push({ name, handler }); return () => {}; } };
        const on = (name) => handlers.find((entry) => entry.name === name).handler;
        const pressed = element('a', { href: '/app/x?page=2' }, '2');
        const twin = element('a', { href: '/app/x?page=2' }, '2');
        const body = { tagName: 'BODY' };
        const doc = {
            title: 'X · Procynia',
            documentElement: { lang: 'no' },
            body,
            activeElement: pressed,
            getElementById: () => null,
            querySelector: () => null,
            querySelectorAll: (tag) => (tag === 'a' ? [element('a', { href: '/app/x?page=3' }, '3'), twin] : []),
        };
        const win = { requestAnimationFrame: (callback) => callback(), setTimeout: (callback) => callback() };
        installRouteFocus(router, { initialPage: { component: 'App/X', url: '/app/x' }, doc, win });

        // The page re-mounts: the pressed link is gone and focus is on <body>.
        on('start')();
        doc.activeElement = body;
        on('navigate')({ detail: { page: { component: 'App/X', url: '/app/x?page=2', props: {} } } });
        on('finish')();
        assert.equal(twin.focused, 1);

        // Focus still on a control after the visit (preserveState kept it): nothing is touched.
        doc.activeElement = twin;
        on('start')();
        on('finish')();
        assert.equal(twin.focused, 1);
    });
});
