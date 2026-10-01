import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const layout = readFileSync(join(here, 'CustomerAppLayout.jsx'), 'utf8');

const block = (start, end) => {
    const from = layout.indexOf(start);
    assert.ok(from > -1, `${start} must exist`);

    return layout.slice(from, layout.indexOf(end, from));
};

/** The whole element carrying a test id, including the attributes written above it. */
const element = (testId, end) => {
    const at = layout.indexOf(`data-testid="${testId}"`);
    assert.ok(at > -1, `${testId} must exist`);

    return layout.slice(layout.lastIndexOf('<', at), layout.indexOf(end, at));
};

/**
 * The top bar had eleven items in one row, and only six of them were steps in the bid workflow.
 *
 * Watch lists is how Kunngjøringer is configured. Kundemiljø and Abonnement are administration.
 * Infosenter is follow-up across every case at once. None of those is a stage a bid passes
 * through, so all four read as peers of "Saksliste" without being anything like it — and the row
 * was long enough that the workflow itself stopped being legible in it.
 *
 * Routes, permissions and pages are untouched throughout: every item below is gated by exactly the
 * flag that gated it before, and moving a label cannot grant access. Source-level guards, the idiom
 * used elsewhere in this suite; the header itself was driven in a browser at 1680, 1280, 1024 and
 * 390 px.
 */
describe('the workflow moved into the Anbud module, and only moved', () => {
    test('the four work areas are there, in the order the work happens', () => {
        const sections = block('const moduleSections = activeModule === \'tenders\'', '];');
        const keys = [...sections.matchAll(/key: '([^']+)'/g)].map((m) => m[1]);

        assert.deepEqual(keys, ['procurements', 'worklist', 'ai', 'suppliers']);
    });

    test('nothing that is not a step in the work is in it', () => {
        const sections = block('const moduleSections = activeModule === \'tenders\'', '];');

        for (const key of ['info-center', 'watch-profiles', 'environment', 'billing', 'wiki-ask', 'wiki']) {
            assert.ok(! sections.includes(`key: '${key}'`), `${key} must not be a work area`);
        }
    });

    test('every item keeps the href it always had', () => {
        const sections = block('const moduleSections = activeModule === \'tenders\'', '];');

        for (const href of ["'/app/notices'", "'/app/ai'", "'/app/suppliers'"]) {
            assert.ok(sections.includes(href), href);
        }

        assert.match(sections, /buildHref\('\/app\/notices', \{ mode: 'saved' \}\)/);
    });

    test('the header no longer carries the workflow row', () => {
        assert.ok(! layout.includes('const mainNavigation = ['), 'the workflow row moved to the rail');
    });

    test('the active pill is unchanged', () => {
        assert.match(layout, /\? 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-200'/);
    });
});

describe('follow-up sits beside the workflow, not inside it', () => {
    test('it is its own entry, pointing at the same page as before', () => {
        const followUp = block('const followUpNavigation = {', '};');

        assert.match(followUp, /key: 'info-center'/);
        assert.match(followUp, /href: '\/app\/info-center'/);
        assert.match(followUp, /label: translations\.frontend\.infosenter_nav/);
    });

    test('a divider separates it, and goes away when the header wraps', () => {
        const divider = element('header-follow-up-divider', '/>');

        assert.match(divider, /aria-hidden="true"/);
        assert.match(divider, /hidden h-6 w-px shrink-0 bg-slate-200 lg:block/);
    });

    test('the order across the bar is search, divider, follow-up, bell, user', () => {
        const order = [
            'href={askWikiNavigation.href}',
            'data-testid="header-follow-up-divider"',
            'data-testid="header-follow-up"',
            '<NotificationBell',
            'aria-haspopup="menu"',
        ].map((needle) => layout.indexOf(needle));

        assert.ok(order.every((index) => index > -1), 'every part of the bar must be present');

        for (let i = 1; i < order.length; i += 1) {
            assert.ok(order[i] > order[i - 1], `part ${i} is out of order`);
        }
    });

    test('it reports its own active state rather than borrowing the workflow nav\'s', () => {
        const link = element('header-follow-up', '</Link>');

        assert.match(link, /aria-current=\{activeMainArea === followUpNavigation\.key \? 'page' : undefined\}/);
    });
});

describe('watch lists belongs to Kunngjøringer', () => {
    test('being on the page lights up Kunngjøringer', () => {
        assert.match(layout, /if \(pathname\.startsWith\('\/app\/watch-profiles'\)\) \{\s*\n\s*return 'procurements';/);
    });

    test('it is a tab in that area, behind the same permission as before', () => {
        const sub = block("if (activeMainArea === 'procurements') {\n            return [", '];');

        assert.match(sub, /key: 'live'/);
        assert.match(sub, /key: 'alerts'/);
        assert.match(sub, /\.\.\.\(watchProfilesHref\s*\n\s*\? \[\{ key: 'watch-profiles', label: navigation\.watch_lists, href: watchProfilesHref \}\]\s*\n\s*: \[\]\),/);
    });

    test('the tab shows as selected when the page is open', () => {
        assert.match(layout, /if \(pathname\.startsWith\('\/app\/watch-profiles'\)\) \{\s*\n\s*return 'watch-profiles';/);
    });

    test('the permission itself is untouched', () => {
        assert.match(layout, /const watchProfilesHref = user\?\.can_manage_watch_profiles \? '\/app\/watch-profiles' : null;/);
    });
});

describe('administration moved to the user menu, and only moved', () => {
    test('both entries are there, at their existing routes', () => {
        const admin = element('user-menu-admin', '</div>\n                                            ) : null}');

        assert.match(admin, /href=\{environmentHref\}/);
        assert.match(admin, /href=\{billingHref\}/);
        assert.match(admin, /navigation\.customer_environment \?\? 'Kundemiljø'/);
        assert.match(admin, /translations\.billing\?\.nav \?\? 'Abonnement'/);
    });

    test('each is gated by the flag that gated it in the main menu', () => {
        assert.match(layout, /const environmentHref = user\?\.can_manage_customer_users \? '\/app\/customer-environment' : null;/);
        assert.match(layout, /const billingHref = user\?\.can_manage_customer_billing \? '\/app\/billing' : null;/);
        assert.match(layout, /\{environmentHref \? \(/);
        assert.match(layout, /\{billingHref \? \(/);
    });

    test('a user with neither sees no empty section', () => {
        assert.match(layout, /\{environmentHref \|\| billingHref \? \(/);
    });

    test('Kundemiljø keeps its own tabs when opened', () => {
        // The area is no longer in the main menu, but it is still an area.
        assert.match(layout, /if \(activeMainArea === 'environment'\) \{/);
        assert.match(layout, /key: 'go-no-go-templates'/);
    });
});

describe('"AI" is only the menu label that changed', () => {
    test('the item still points at the AI workspace', () => {
        const sections = block('const moduleSections = activeModule === \'tenders\'', '];');

        assert.match(sections, /\{ key: 'ai', label: navigation\.ai, href: '\/app\/ai' \}/);
    });

    test('the AI area, its tabs and its case routing are untouched', () => {
        assert.match(layout, /if \(activeMainArea === 'ai'\) \{/);
        assert.match(layout, /key: 'ai-work', label: navigation\.worklist, href: aiWorkHref/);
        assert.match(layout, /key: 'ai-instructions', label: navigation\.ai_instructions, href: aiInstructionsHref/);
        assert.match(layout, /aiWorkHref = currentAiCaseId !== null/);
    });
});

describe('the header does not grow to fit the change', () => {
    test('the right-hand group may wrap on a phone, and does not on a laptop', () => {
        // Measured: at 390px the user button was pushed 33px past the viewport before this.
        assert.match(layout, /className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 lg:flex-nowrap lg:justify-end"/);
    });

    test('the follow-up link and the search icon never shrink away', () => {
        assert.match(layout, /flex h-10 w-10 shrink-0 items-center justify-center rounded-xl transition/);
        assert.match(layout, /'shrink-0 rounded-xl px-3 py-2 text-base font-medium transition'/);
    });
});
