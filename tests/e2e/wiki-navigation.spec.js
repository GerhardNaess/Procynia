import { test, expect } from '@playwright/test';
import { loginAs, USER } from './helpers/auth.js';

test.beforeEach(async ({ page }) => {
    await loginAs(page, USER.email, USER.password);
});

const WIKI_TABS = [
    { tab: 'pages', label: 'Wiki-sider' },
    { tab: 'sources', label: 'Kildedokumenter' },
    { tab: 'runs', label: 'Kjøringer' },
    { tab: 'quality', label: 'Kvalitet' },
];

function secondaryNav(page) {
    // The shared secondary navigation sits directly under the main navigation,
    // above the page content — same container used by the AI area.
    return page.locator('nav').filter({ hasText: 'Wiki-sider' });
}

test('Wiki secondary navigation shows all four tabs in the shared nav pattern', async ({ page }) => {
    const response = await page.goto('/app/wiki');

    expect(response?.status()).toBe(200);

    const nav = secondaryNav(page);
    await expect(nav).toBeVisible();

    for (const { label } of WIKI_TABS) {
        await expect(nav.getByRole('link', { name: label, exact: true })).toBeVisible();
    }
});

for (const { tab, label } of WIKI_TABS) {
    test(`direct navigation to ?tab=${tab} marks "${label}" as the active nav item`, async ({ page }) => {
        const response = await page.goto(`/app/wiki?tab=${tab}`);
        expect(response?.status()).toBe(200);

        const activeLink = secondaryNav(page).getByRole('link', { name: label, exact: true });
        await expect(activeLink).toHaveAttribute('aria-current', 'page');

        // Refresh on the same route keeps the same tab active.
        await page.reload();
        await expect(activeLink).toHaveAttribute('aria-current', 'page');

        // Every other Wiki tab must not be marked active at the same time.
        for (const other of WIKI_TABS) {
            if (other.tab === tab) {
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
    // separate from the shared secondary navigation. Only one Wiki-sider link should exist.
    await expect(page.getByRole('link', { name: 'Wiki-sider', exact: true })).toHaveCount(1);
    await expect(page.getByRole('link', { name: 'Kildedokumenter', exact: true })).toHaveCount(1);
    await expect(page.getByRole('link', { name: 'Kjøringer', exact: true })).toHaveCount(1);
    await expect(page.getByRole('link', { name: 'Kvalitet', exact: true })).toHaveCount(1);
});

test('Grafvisning remains a page action on the Wiki-sider tab, not part of the secondary nav', async ({ page }) => {
    await page.goto('/app/wiki?tab=pages');

    // Not one of the shared secondary nav items.
    await expect(secondaryNav(page).getByRole('link', { name: 'Grafvisning', exact: true })).toHaveCount(0);

    // Still reachable as a page action within the Wiki-sider content.
    const graphLink = page.getByRole('link', { name: 'Grafvisning', exact: true });
    await expect(graphLink).toBeVisible();
    await expect(graphLink).toHaveAttribute('href', '/app/wiki/graph');

    const response = await page.goto('/app/wiki/graph');
    expect(response?.status()).toBe(200);
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
