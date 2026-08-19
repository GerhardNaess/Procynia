import { test, expect } from '@playwright/test';
import { loginAs, USER } from './helpers/auth.js';

test.beforeEach(async ({ page }) => {
    await loginAs(page, USER.email, USER.password);
});

// Listed in the order the menu renders them — the workflow order.
const WIKI_NAV_ITEMS = [
    { key: 'sources', label: 'Kildedokumenter', url: '/app/wiki?tab=sources' },
    { key: 'runs', label: 'Kjøringer', url: '/app/wiki?tab=runs' },
    { key: 'pages', label: 'Wiki-sider', url: '/app/wiki?tab=pages' },
    { key: 'graph', label: 'Grafvisning', url: '/app/wiki/graph' },
];

function secondaryNav(page) {
    // The shared secondary navigation sits directly under the main navigation,
    // above the page content — same container used by the AI area.
    return page.locator('nav').filter({ hasText: 'Wiki-sider' });
}

test('Wiki secondary navigation shows all four items in the shared nav pattern', async ({ page }) => {
    const response = await page.goto('/app/wiki');

    expect(response?.status()).toBe(200);

    const nav = secondaryNav(page);
    await expect(nav).toBeVisible();

    for (const { label } of WIKI_NAV_ITEMS) {
        await expect(nav.getByRole('link', { name: label, exact: true })).toBeVisible();
    }

    // The order is the point: source material first, then what the system did with it, then the
    // result, then exploration.
    await expect(nav.getByRole('link')).toHaveText(WIKI_NAV_ITEMS.map(({ label }) => label));
});

for (const { key, label, url } of WIKI_NAV_ITEMS) {
    test(`direct navigation to ${url} marks "${label}" as the active nav item`, async ({ page }) => {
        const response = await page.goto(url);
        expect(response?.status()).toBe(200);

        const activeLink = secondaryNav(page).getByRole('link', { name: label, exact: true });
        await expect(activeLink).toHaveAttribute('aria-current', 'page');

        // Refresh on the same route keeps the same item active.
        await page.reload();
        await expect(activeLink).toHaveAttribute('aria-current', 'page');

        // Every other Wiki nav item must not be marked active at the same time.
        for (const other of WIKI_NAV_ITEMS) {
            if (other.key === key) {
                continue;
            }
            const otherLink = secondaryNav(page).getByRole('link', { name: other.label, exact: true });
            await expect(otherLink).not.toHaveAttribute('aria-current', 'page');
        }
    });
}

test('no local tab row remains inside the Wiki page content', async ({ page }) => {
    await page.goto('/app/wiki');

    // The old local TabBar rendered its own <nav>-less div with underline-active links,
    // separate from the shared secondary navigation. Only one link per label should exist.
    for (const { label } of WIKI_NAV_ITEMS) {
        await expect(page.getByRole('link', { name: label, exact: true })).toHaveCount(1);
    }
});

test('Grafvisning is not present in the Wiki-sider filter bar', async ({ page }) => {
    await page.goto('/app/wiki?tab=pages');

    // Exactly one Grafvisning link on the whole page — the shared secondary nav item — proves
    // it is not duplicated as a separate page action inside the Wiki-sider filter bar/content.
    await expect(page.getByRole('link', { name: 'Grafvisning', exact: true })).toHaveCount(1);

    const graphLink = secondaryNav(page).getByRole('link', { name: 'Grafvisning', exact: true });
    await expect(graphLink).toBeVisible();
    await expect(graphLink).toHaveAttribute('href', '/app/wiki/graph');

    // The one Grafvisning link is not inside the filter bar's search form.
    const searchForm = page.locator('form').filter({ has: page.getByPlaceholder('Søk...') });
    await expect(searchForm.getByRole('link', { name: 'Grafvisning', exact: true })).toHaveCount(0);
});

test('AI secondary navigation is unchanged by the Wiki navigation alignment', async ({ page }) => {
    const response = await page.goto('/app/ai');
    expect(response?.status()).toBe(200);

    const nav = page.locator('nav').filter({ hasText: 'Oversikt' });
    await expect(nav.getByRole('link', { name: 'Oversikt', exact: true })).toBeVisible();
    await expect(nav.getByRole('link', { name: 'Oversikt', exact: true })).toHaveAttribute('aria-current', 'page');
});

test('keyboard focus reaches the Wiki secondary navigation links', async ({ page }) => {
    await page.goto('/app/wiki?tab=pages');

    const sourcesLink = secondaryNav(page).getByRole('link', { name: 'Kildedokumenter', exact: true });
    await sourcesLink.focus();
    await expect(sourcesLink).toBeFocused();
});
