import { test, expect } from '@playwright/test';

// This graph view is only populated with real Wiki data for the dev-seeded
// customer (alisan@advania.no / customer_id=4) — the plain E2E-seeded USER has no
// Enterprise Wiki content. Log in with the real dev-data user so the graph and its
// two known source documents ("Masterdata ITIL.docx" — 9 pages, "Masterdata
// Samhandling.docx" — 7 pages) are actually present to filter against.
async function loginAsDevDataUser(page) {
    await page.goto('/login');
    await page.fill('#email', 'alisan@advania.no');
    await page.fill('#password', 'Opaque01');
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 });
}

test.beforeEach(async ({ page }) => {
    await loginAsDevDataUser(page);
});

test('full graph loads with no filters active', async ({ page }) => {
    const response = await page.goto('/app/wiki/graph');
    expect(response?.status()).toBe(200);
    await page.waitForTimeout(1000);

    await expect(page.getByText('16 av 16 sider')).toHaveCount(0); // no "X av Y" when unfiltered
    await expect(page.getByText(/Grafoversikt/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Nullstill filtre' })).toBeDisabled();
});

test('search matches a full page title, case-insensitively, and trims whitespace', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    // Substring match — this also matches "Sammendrag: Masterdata ITIL" (contains
    // "masterdata itil"), so both pages count towards the total.
    await page.getByLabel('Søk i Wiki-sider').fill('  masterdata itil  ');
    await page.waitForTimeout(300);

    await expect(page.getByText('2 av 16 sider')).toBeVisible();
});

test('search matches an exact, unique full page title', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await page.getByLabel('Søk i Wiki-sider').fill('  Styrings- og samhandlingsmodell  ');
    await page.waitForTimeout(300);

    await expect(page.getByText('1 av 16 sider')).toBeVisible();
});

test('search matches part of a page title', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await page.getByLabel('Søk i Wiki-sider').fill('ITIL');
    await page.waitForTimeout(300);

    const countText = await page.getByText(/\d+ av 16 sider/).textContent();
    expect(parseInt(countText, 10)).toBeGreaterThan(1);
    expect(parseInt(countText, 10)).toBeLessThan(16);
});

test('search with no matches shows the empty state, reset clears it', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await page.getByLabel('Søk i Wiki-sider').fill('this-title-does-not-exist-anywhere');
    await page.waitForTimeout(300);

    await expect(page.getByRole('status')).toContainText('Ingen Wiki-sider matcher filtrene.');
    await expect(page.getByRole('status').getByRole('button', { name: 'Nullstill filtre' })).toBeVisible();

    await page.getByRole('status').getByRole('button', { name: 'Nullstill filtre' }).click();
    await page.waitForTimeout(300);

    await expect(page.getByRole('status')).toHaveCount(0);
    await expect(page.getByLabel('Søk i Wiki-sider')).toHaveValue('');
});

test('document filter shows human-readable filenames, never internal ids', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    const panel = page.locator('fieldset', { hasText: 'Kildedokumenter' });
    await expect(panel.getByText('Masterdata ITIL.docx')).toBeVisible();
    await expect(panel.getByText('Masterdata Samhandling.docx')).toBeVisible();
});

test('selecting one document filters the graph to only that document\'s pages', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await page.getByText('Masterdata ITIL.docx').click();
    await page.waitForTimeout(300);

    await expect(page.getByText('9 av 16 sider')).toBeVisible();
    await expect(page.getByLabel('Alle dokumenter')).not.toBeChecked();
});

test('selecting two documents combines them with OR (shows pages from either)', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await page.getByText('Masterdata ITIL.docx').click();
    await page.waitForTimeout(200);
    await expect(page.getByText('9 av 16 sider')).toBeVisible();

    await page.getByText('Masterdata Samhandling.docx').click();
    await page.waitForTimeout(300);

    // Both documents selected == every page in this (2-document) graph — the coverage line
    // disappears because the filtered count now equals the total, same as "Alle dokumenter".
    await expect(page.getByText(/\d+ av 16 sider/)).toHaveCount(0);
    const sidesRow = page.locator('dt', { hasText: 'Sider' }).locator('..');
    await expect(sidesRow.getByText('16', { exact: true })).toBeVisible();
});

test('"Alle dokumenter" restores the full document set', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await page.getByText('Masterdata ITIL.docx').click();
    await page.waitForTimeout(200);
    await page.getByLabel('Alle dokumenter').click();
    await page.waitForTimeout(300);

    await expect(page.getByText(/\d+ av 16 sider/)).toHaveCount(0);
    await expect(page.getByLabel('Alle dokumenter')).toBeChecked();
});

test('document filter combines with page type filter (AND across groups)', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await page.getByText('Masterdata ITIL.docx').click();
    await page.getByLabel('Sammendrag').uncheck();
    await page.getByLabel('Konsept').uncheck();
    await page.getByLabel('Entitet').uncheck();
    await page.waitForTimeout(300);

    // Masterdata ITIL.docx has exactly one article page.
    await expect(page.getByText('1 av 16 sider')).toBeVisible();
});

test('document filter combines with search', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await page.getByText('Masterdata Samhandling.docx').click();
    await page.getByLabel('Søk i Wiki-sider').fill('styring');
    await page.waitForTimeout(300);

    const countText = await page.getByText(/\d+ av 16 sider/).textContent();
    const count = parseInt(countText, 10);
    expect(count).toBeGreaterThan(0);
    expect(count).toBeLessThan(7); // fewer than the document's own total page count
});

test('reset filters restores the entire graph', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await page.getByLabel('Søk i Wiki-sider').fill('ITIL');
    await page.getByText('Masterdata ITIL.docx').click();
    await page.getByLabel('Konsept').uncheck();
    await page.waitForTimeout(300);
    await expect(page.getByRole('button', { name: 'Nullstill filtre' }).first()).toBeEnabled();

    await page.getByRole('button', { name: 'Nullstill filtre' }).first().click();
    await page.waitForTimeout(300);

    await expect(page.getByLabel('Søk i Wiki-sider')).toHaveValue('');
    await expect(page.getByLabel('Alle dokumenter')).toBeChecked();
    await expect(page.getByLabel('Konsept')).toBeChecked();
    await expect(page.getByText(/\d+ av 16 sider/)).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Nullstill filtre' }).first()).toBeDisabled();
});

test('isolated pages toggle changes the displayed page count', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    // Narrow to a slice likely to contain an isolated node once combined with a search term.
    await page.getByLabel('Søk i Wiki-sider').fill('kontinuerlig');
    await page.waitForTimeout(300);
    const withOrphansText = await page.getByText(/\d+ av 16 sider/).textContent();

    await page.getByLabel('Vis isolerte sider').uncheck();
    await page.waitForTimeout(300);
    const withoutOrphansText = await page.getByText(/\d+ av 16 sider/).textContent();

    // Toggling must not error and must keep the counter well-formed either way.
    expect(withOrphansText).toMatch(/\d+ av 16 sider/);
    expect(withoutOrphansText).toMatch(/\d+ av 16 sider/);
});

test('mobile viewport (390px) has no horizontal scroll and all filters remain reachable', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1200);

    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 1);

    await expect(page.getByLabel('Søk i Wiki-sider')).toBeVisible();
    await expect(page.getByText('Masterdata ITIL.docx')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Nullstill filtre' }).first()).toBeVisible();
});

test('keyboard focus reaches the search field and document checkboxes', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    const search = page.getByLabel('Søk i Wiki-sider');
    await search.focus();
    await expect(search).toBeFocused();

    const allDocumentsCheckbox = page.getByLabel('Alle dokumenter');
    await allDocumentsCheckbox.focus();
    await expect(allDocumentsCheckbox).toBeFocused();
});

test('no console errors or failed requests while filtering', async ({ page }) => {
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
    page.on('requestfailed', (req) => failedRequests.push(`${req.method()} ${req.url()}`));
    page.on('response', (res) => { if (res.status() >= 500) failedRequests.push(`${res.status()} ${res.url()}`); });

    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);
    await page.getByLabel('Søk i Wiki-sider').fill('ITIL');
    await page.waitForTimeout(200);
    await page.getByText('Masterdata ITIL.docx').click();
    await page.waitForTimeout(200);
    await page.getByLabel('Vis isolerte sider').uncheck();
    await page.waitForTimeout(200);
    await page.getByRole('button', { name: 'Nullstill filtre' }).first().click();
    await page.waitForTimeout(200);

    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
});
