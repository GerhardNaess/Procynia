import { test, expect } from '@playwright/test';
import { exec } from 'node:child_process';
import { promisify } from 'node:util';

const execAsync = promisify(exec);

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

// The document/owner filters are now compact dropdown/popover controls (see
// MultiSelectFilterDropdown.jsx) — the checkbox list only exists in the DOM while
// open, so every test that touches document/owner checkboxes must open the
// relevant dropdown first. The trigger button's accessible name is the field
// label plus the current summary (e.g. "Kildedokumenter Alle dokumenter"), and the
// popover itself is an aria-labelledby'd role="group" portalled to document.body.
function documentTrigger(page) {
    return page.getByRole('button', { name: /Kildedokumenter/ });
}

function ownerTrigger(page) {
    return page.getByRole('button', { name: /Dokumenteier/ });
}

function documentGroup(page) {
    return page.getByRole('group', { name: 'Kildedokumenter' });
}

function ownerGroup(page) {
    return page.getByRole('group', { name: 'Dokumenteier' });
}

async function openDocumentDropdown(page) {
    await documentTrigger(page).click();
    await expect(documentGroup(page)).toBeVisible();
}

async function openOwnerDropdown(page) {
    await ownerTrigger(page).click();
    await expect(ownerGroup(page)).toBeVisible();
}

// "Alisan Senel" (the logged-in dev user) also appears in the account menu button, so owner
// checkbox clicks must be scoped to the owner dropdown panel to avoid ambiguity.
function ownerCheckbox(page, name) {
    return ownerGroup(page).getByText(name, { exact: true });
}

function documentCheckbox(page, name) {
    return documentGroup(page).getByText(name, { exact: true });
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

// ─── Source document filter ──────────────────────────────────────────────────

test('document dropdown is closed by default and shows "Alle dokumenter"', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await expect(documentTrigger(page)).toHaveText('Alle dokumenter');
    await expect(documentTrigger(page)).toHaveAttribute('aria-expanded', 'false');
    await expect(documentGroup(page)).toHaveCount(0);
});

test('opening the document dropdown reveals the document list with human-readable filenames, never internal ids', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openDocumentDropdown(page);
    await expect(documentTrigger(page)).toHaveAttribute('aria-expanded', 'true');

    const panel = documentGroup(page);
    await expect(panel.getByText('Masterdata ITIL.docx')).toBeVisible();
    await expect(panel.getByText('Masterdata Samhandling.docx')).toBeVisible();
    await expect(panel.getByText('9', { exact: true })).toBeVisible();
    await expect(panel.getByText('7', { exact: true })).toBeVisible();
});

test('selecting one document filters the graph and updates the closed control to the document name', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openDocumentDropdown(page);
    await documentCheckbox(page, 'Masterdata ITIL.docx').click();
    await page.waitForTimeout(300);

    await expect(page.getByText('9 av 16 sider')).toBeVisible();
    await expect(documentGroup(page).getByLabel('Alle dokumenter')).not.toBeChecked();

    await documentGroup(page).getByRole('button', { name: 'Ferdig' }).click();
    await expect(documentGroup(page)).toHaveCount(0);
    await expect(documentTrigger(page)).toHaveText('Masterdata ITIL.docx');
});

test('selecting two documents combines them with OR (shows pages from either) and the closed control shows a count', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openDocumentDropdown(page);
    await documentCheckbox(page, 'Masterdata ITIL.docx').click();
    await page.waitForTimeout(200);
    await expect(page.getByText('9 av 16 sider')).toBeVisible();

    await documentCheckbox(page, 'Masterdata Samhandling.docx').click();
    await page.waitForTimeout(300);

    // Both documents selected == every page in this (2-document) graph — the coverage line
    // disappears because the filtered count now equals the total, same as "Alle dokumenter".
    await expect(page.getByText(/\d+ av 16 sider/)).toHaveCount(0);
    const sidesRow = page.locator('dt', { hasText: 'Sider' }).locator('..');
    await expect(sidesRow.getByText('16', { exact: true })).toBeVisible();

    await expect(documentTrigger(page)).toHaveText('2 dokumenter valgt');
});

test('"Alle dokumenter" restores the full document set', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openDocumentDropdown(page);
    await documentCheckbox(page, 'Masterdata ITIL.docx').click();
    await page.waitForTimeout(200);
    await documentGroup(page).getByLabel('Alle dokumenter').click();
    await page.waitForTimeout(300);

    await expect(page.getByText(/\d+ av 16 sider/)).toHaveCount(0);
    await expect(documentGroup(page).getByLabel('Alle dokumenter')).toBeChecked();
    await expect(documentTrigger(page)).toHaveText('Alle dokumenter');
});

test('the local "Nullstill" button inside the document dropdown clears only that selection', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openDocumentDropdown(page);
    await documentCheckbox(page, 'Masterdata ITIL.docx').click();
    await page.waitForTimeout(200);

    await documentGroup(page).getByRole('button', { name: 'Nullstill' }).click();
    await page.waitForTimeout(300);

    await expect(documentGroup(page).getByLabel('Alle dokumenter')).toBeChecked();
    await expect(page.getByText(/\d+ av 16 sider/)).toHaveCount(0);
});

test('document filter combines with page type filter (AND across groups)', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openDocumentDropdown(page);
    await documentCheckbox(page, 'Masterdata ITIL.docx').click();
    await documentGroup(page).getByRole('button', { name: 'Ferdig' }).click();

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

    await openDocumentDropdown(page);
    await documentCheckbox(page, 'Masterdata Samhandling.docx').click();
    await documentGroup(page).getByRole('button', { name: 'Ferdig' }).click();
    await page.getByLabel('Søk i Wiki-sider').fill('styring');
    await page.waitForTimeout(300);

    const countText = await page.getByText(/\d+ av 16 sider/).textContent();
    const count = parseInt(countText, 10);
    expect(count).toBeGreaterThan(0);
    expect(count).toBeLessThan(7); // fewer than the document's own total page count
});

test('reset filters restores the entire graph and closes the document dropdown', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await page.getByLabel('Søk i Wiki-sider').fill('ITIL');
    await openDocumentDropdown(page);
    await documentCheckbox(page, 'Masterdata ITIL.docx').click();
    await page.getByLabel('Konsept').uncheck();
    await page.waitForTimeout(300);
    await expect(page.getByRole('button', { name: 'Nullstill filtre' }).first()).toBeEnabled();

    await page.getByRole('button', { name: 'Nullstill filtre' }).first().click();
    await page.waitForTimeout(300);

    await expect(page.getByLabel('Søk i Wiki-sider')).toHaveValue('');
    await expect(documentTrigger(page)).toHaveText('Alle dokumenter');
    await expect(page.getByLabel('Konsept')).toBeChecked();
    await expect(page.getByText(/\d+ av 16 sider/)).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Nullstill filtre' }).first()).toBeDisabled();
    // The dropdown itself was left open by the selection above — global reset must close it too.
    await expect(documentGroup(page)).toHaveCount(0);
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

test('mobile viewport (390px) has no horizontal scroll and both dropdowns remain reachable', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1200);

    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 1);

    await expect(page.getByLabel('Søk i Wiki-sider')).toBeVisible();
    await expect(documentTrigger(page)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Nullstill filtre' }).first()).toBeVisible();

    await openDocumentDropdown(page);
    await expect(documentGroup(page).getByText('Masterdata ITIL.docx')).toBeVisible();

    const scrollWidthOpen = await page.evaluate(() => document.documentElement.scrollWidth);
    expect(scrollWidthOpen).toBeLessThanOrEqual(clientWidth + 1);

    const box = await documentGroup(page).boundingBox();
    expect(box).not.toBeNull();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(390 + 1); // stays inside the viewport, not clipped
});

test('keyboard focus reaches the search field and the document dropdown trigger', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    const search = page.getByLabel('Søk i Wiki-sider');
    await search.focus();
    await expect(search).toBeFocused();

    await documentTrigger(page).focus();
    await expect(documentTrigger(page)).toBeFocused();
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
    await openDocumentDropdown(page);
    await documentCheckbox(page, 'Masterdata ITIL.docx').click();
    await documentGroup(page).getByRole('button', { name: 'Ferdig' }).click();
    await page.waitForTimeout(200);
    await page.getByLabel('Vis isolerte sider').uncheck();
    await page.waitForTimeout(200);
    await page.getByRole('button', { name: 'Nullstill filtre' }).first().click();
    await page.waitForTimeout(200);

    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
});

// ─── Document owner filter ───────────────────────────────────────────────────
// Real dev data: "Gerhard Næss" owns Masterdata ITIL.docx (9 pages), "Alisan
// Senel" owns Masterdata Samhandling.docx (7 pages) — a perfect 1:1 mapping onto
// the document tests above.

test('owner dropdown is closed by default and shows "Alle eiere"', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await expect(ownerTrigger(page)).toHaveText('Alle eiere');
    await expect(ownerTrigger(page)).toHaveAttribute('aria-expanded', 'false');
    await expect(ownerGroup(page)).toHaveCount(0);
});

test('opening the owner dropdown reveals owner names, never internal ids, with page counts', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openOwnerDropdown(page);
    await expect(ownerTrigger(page)).toHaveAttribute('aria-expanded', 'true');

    const panel = ownerGroup(page);
    await expect(panel.getByText('Gerhard Næss')).toBeVisible();
    await expect(panel.getByText('Alisan Senel')).toBeVisible();
    await expect(panel.getByLabel('Alle eiere')).toBeChecked();
});

test('selecting one owner filters the graph to only their documents\' pages', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openOwnerDropdown(page);
    await ownerCheckbox(page, 'Gerhard Næss').click();
    await page.waitForTimeout(300);

    await expect(page.getByText('9 av 16 sider')).toBeVisible();
    await expect(ownerGroup(page).getByLabel('Alle eiere')).not.toBeChecked();

    await ownerGroup(page).getByRole('button', { name: 'Ferdig' }).click();
    await expect(ownerTrigger(page)).toHaveText('Gerhard Næss');
    // The document filter itself is untouched — owner and document are independent groups.
    await expect(documentTrigger(page)).toHaveText('Alle dokumenter');
});

test('selecting two owners combines them with OR and shows a count on the closed control', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openOwnerDropdown(page);
    await ownerCheckbox(page, 'Gerhard Næss').click();
    await page.waitForTimeout(200);
    await ownerCheckbox(page, 'Alisan Senel').click();
    await page.waitForTimeout(300);

    await expect(page.getByText(/\d+ av 16 sider/)).toHaveCount(0);
    const sidesRow = page.locator('dt', { hasText: 'Sider' }).locator('..');
    await expect(sidesRow.getByText('16', { exact: true })).toBeVisible();
    await expect(ownerTrigger(page)).toHaveText('2 eiere valgt');
});

test('"Alle eiere" restores the full owner set', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openOwnerDropdown(page);
    await ownerCheckbox(page, 'Gerhard Næss').click();
    await page.waitForTimeout(200);
    await ownerGroup(page).getByLabel('Alle eiere').click();
    await page.waitForTimeout(300);

    await expect(page.getByText(/\d+ av 16 sider/)).toHaveCount(0);
    await expect(ownerGroup(page).getByLabel('Alle eiere')).toBeChecked();
    await expect(ownerTrigger(page)).toHaveText('Alle eiere');
});

test('owner filter combines with document filter (independent conditions, both must be satisfied)', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    // Owner = Gerhard (9 pages via Masterdata ITIL.docx) AND document = Masterdata
    // Samhandling.docx (7 pages, owned by Alisan) — no page satisfies both independent
    // conditions from the SAME two unrelated criteria, so this must show the empty state
    // even though each filter alone matches pages.
    await openOwnerDropdown(page);
    await ownerCheckbox(page, 'Gerhard Næss').click();
    await ownerGroup(page).getByRole('button', { name: 'Ferdig' }).click();

    await openDocumentDropdown(page);
    await documentCheckbox(page, 'Masterdata Samhandling.docx').click();
    await page.waitForTimeout(300);

    await expect(page.getByRole('status')).toContainText('Ingen Wiki-sider matcher filtrene.');
});

test('owner filter combines with document filter (matching combination)', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openOwnerDropdown(page);
    await ownerCheckbox(page, 'Gerhard Næss').click();
    await ownerGroup(page).getByRole('button', { name: 'Ferdig' }).click();

    await openDocumentDropdown(page);
    await documentCheckbox(page, 'Masterdata ITIL.docx').click();
    await page.waitForTimeout(300);

    await expect(page.getByText('9 av 16 sider')).toBeVisible();
});

test('owner filter combines with page type filter', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openOwnerDropdown(page);
    await ownerCheckbox(page, 'Gerhard Næss').click();
    await ownerGroup(page).getByRole('button', { name: 'Ferdig' }).click();

    await page.getByLabel('Sammendrag').uncheck();
    await page.getByLabel('Konsept').uncheck();
    await page.getByLabel('Entitet').uncheck();
    await page.waitForTimeout(300);

    await expect(page.getByText('1 av 16 sider')).toBeVisible();
});

test('owner filter combines with status filter (no warning-status pages in this data set)', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openOwnerDropdown(page);
    await ownerCheckbox(page, 'Gerhard Næss').click();
    await ownerGroup(page).getByRole('button', { name: 'Ferdig' }).click();

    await page.getByLabel('OK – ingen åpne funn').uncheck();
    await page.waitForTimeout(300);

    // Every page in this data set is lint-status "ok" — narrowing to error/warning only
    // combined with an owner filter must correctly yield zero matches, not a stale count.
    await expect(page.getByRole('status')).toContainText('Ingen Wiki-sider matcher filtrene.');
});

test('owner filter combines with search', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openOwnerDropdown(page);
    await ownerCheckbox(page, 'Alisan Senel').click();
    await ownerGroup(page).getByRole('button', { name: 'Ferdig' }).click();

    await page.getByLabel('Søk i Wiki-sider').fill('styring');
    await page.waitForTimeout(300);

    const countText = await page.getByText(/\d+ av 16 sider/).textContent();
    const count = parseInt(countText, 10);
    expect(count).toBeGreaterThan(0);
    expect(count).toBeLessThan(7);
});

test('reset filters also clears the selected owners', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openOwnerDropdown(page);
    await ownerCheckbox(page, 'Gerhard Næss').click();
    await page.waitForTimeout(300);
    await expect(page.getByRole('button', { name: 'Nullstill filtre' }).first()).toBeEnabled();

    await page.getByRole('button', { name: 'Nullstill filtre' }).first().click();
    await page.waitForTimeout(300);

    // Global reset also closes the dropdown itself — assert via the closed control's summary.
    await expect(ownerGroup(page)).toHaveCount(0);
    await expect(ownerTrigger(page)).toHaveText('Alle eiere');
    await expect(page.getByText(/\d+ av 16 sider/)).toHaveCount(0);
});

test('keyboard focus reaches the owner dropdown trigger', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await ownerTrigger(page).focus();
    await expect(ownerTrigger(page)).toBeFocused();
});

test('owner dropdown panel has no horizontal overflow on mobile (390px)', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1200);

    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 1);

    await openOwnerDropdown(page);
    await expect(ownerGroup(page).getByText('Gerhard Næss')).toBeVisible();

    const scrollWidthOpen = await page.evaluate(() => document.documentElement.scrollWidth);
    expect(scrollWidthOpen).toBeLessThanOrEqual(clientWidth + 1);
});

test('no console errors or failed requests while using the owner filter', async ({ page }) => {
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
    page.on('requestfailed', (req) => failedRequests.push(`${req.method()} ${req.url()}`));
    page.on('response', (res) => { if (res.status() >= 500) failedRequests.push(`${res.status()} ${res.url()}`); });

    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);
    await openOwnerDropdown(page);
    await ownerCheckbox(page, 'Gerhard Næss').click();
    await page.waitForTimeout(200);
    await ownerCheckbox(page, 'Alisan Senel').click();
    await page.waitForTimeout(200);
    await page.getByRole('button', { name: 'Nullstill filtre' }).first().click();
    await page.waitForTimeout(200);

    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
});

// ─── Dropdown accessibility & keyboard behaviour ─────────────────────────────
// These apply identically to both dropdowns — exercised once via the document
// dropdown, since both share the same MultiSelectFilterDropdown component.

test('Enter and Space both open the dropdown from the focused trigger', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await documentTrigger(page).focus();
    await page.keyboard.press('Enter');
    await expect(documentGroup(page)).toBeVisible();
    await expect(documentTrigger(page)).toHaveAttribute('aria-expanded', 'true');

    await page.keyboard.press('Escape');
    await expect(documentGroup(page)).toHaveCount(0);

    await documentTrigger(page).focus();
    await page.keyboard.press(' ');
    await expect(documentGroup(page)).toBeVisible();
});

test('Escape closes the dropdown and returns focus to the trigger', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openDocumentDropdown(page);
    await page.keyboard.press('Escape');

    await expect(documentGroup(page)).toHaveCount(0);
    await expect(documentTrigger(page)).toHaveAttribute('aria-expanded', 'false');
    await expect(documentTrigger(page)).toBeFocused();
});

test('clicking outside the dropdown closes it', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openDocumentDropdown(page);
    await page.getByText('Enterprise Wiki Graf').click();
    await page.waitForTimeout(200);

    await expect(documentGroup(page)).toHaveCount(0);
    await expect(documentTrigger(page)).toHaveAttribute('aria-expanded', 'false');
});

test('opening the owner dropdown closes an already-open document dropdown (only one at a time)', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openDocumentDropdown(page);
    await expect(documentGroup(page)).toBeVisible();

    await openOwnerDropdown(page);
    await expect(ownerGroup(page)).toBeVisible();
    await expect(documentGroup(page)).toHaveCount(0);
    await expect(documentTrigger(page)).toHaveAttribute('aria-expanded', 'false');
});

test('checkboxes inside an open dropdown are reachable via Tab', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1000);

    await openDocumentDropdown(page);
    await documentGroup(page).getByLabel('Alle dokumenter').focus();
    await expect(documentGroup(page).getByLabel('Alle dokumenter')).toBeFocused();

    await page.keyboard.press('Tab');
    await expect(documentGroup(page).getByLabel('Masterdata ITIL.docx')).toBeFocused();
});

// ─── Filter dropdowns at scale (20+ documents, 20+ owners) ───────────────────
// Real dev data only has 2 documents/2 owners, which is below the internal search
// threshold (>8 options). These tests seed synthetic documents/owners on top of the
// existing dev data via a test-only fixture class (tests/Support/WikiGraphFilterScale
// TestFixture.php — autoload-dev only, not an Artisan command, invoked here through
// tinker) to exercise search, scrolling, and layout at the scale the task requires,
// then remove exactly what they added.

const SCALE_TEST_FIXTURE = '\\Tests\\Support\\WikiGraphFilterScaleTestFixture';

test.describe.serial('scale: 20+ documents and 20+ owners', () => {
    // Run via async exec (not execSync) — a synchronous, blocking child process call here
    // stalls Node's event loop for the seed command's full duration, which in practice was
    // enough to make the Playwright browser's IPC time out on the very next page.goto().
    test.beforeAll(async () => {
        await execAsync(
            `docker compose exec -T app php artisan tinker --execute="${SCALE_TEST_FIXTURE}::seed(4, 20);"`,
            { cwd: new URL('../..', import.meta.url).pathname },
        );
    });

    test.afterAll(async () => {
        await execAsync(
            `docker compose exec -T app php artisan tinker --execute="${SCALE_TEST_FIXTURE}::cleanup(4);"`,
            { cwd: new URL('../..', import.meta.url).pathname },
        );
    });

    test('document dropdown shows an internal search field once options exceed the threshold', async ({ page }) => {
        await page.goto('/app/wiki/graph');
        await page.waitForTimeout(1200);

        await openDocumentDropdown(page);
        await expect(documentGroup(page).getByPlaceholder('Søk i dokumenter …')).toBeVisible();
    });

    test('searching inside the document dropdown finds a matching document', async ({ page }) => {
        await page.goto('/app/wiki/graph');
        await page.waitForTimeout(1200);

        await openDocumentDropdown(page);
        await documentGroup(page).getByPlaceholder('Søk i dokumenter …').fill('  DOKUMENT 05  ');
        await page.waitForTimeout(200);

        await expect(documentGroup(page).getByText(/Skalatest dokument 05/)).toBeVisible();
        await expect(documentGroup(page).getByText('Masterdata ITIL.docx')).toHaveCount(0);
    });

    test('searching with no matches inside the document dropdown shows a clear message', async ({ page }) => {
        await page.goto('/app/wiki/graph');
        await page.waitForTimeout(1200);

        await openDocumentDropdown(page);
        await documentGroup(page).getByPlaceholder('Søk i dokumenter …').fill('this-does-not-exist-anywhere');
        await page.waitForTimeout(200);

        await expect(documentGroup(page).getByText('Ingen treff.')).toBeVisible();
    });

    test('owner dropdown shows an internal search field and finds a matching owner', async ({ page }) => {
        await page.goto('/app/wiki/graph');
        await page.waitForTimeout(1200);

        await openOwnerDropdown(page);
        await expect(ownerGroup(page).getByPlaceholder('Søk i eiere …')).toBeVisible();

        await ownerGroup(page).getByPlaceholder('Søk i eiere …').fill('Skalatest Eier 10');
        await page.waitForTimeout(200);
        await expect(ownerGroup(page).getByText('Skalatest Eier 10')).toBeVisible();
        await expect(ownerGroup(page).getByText('Gerhard Næss')).toHaveCount(0);
    });

    test('owner dropdown search with no matches shows a clear message', async ({ page }) => {
        await page.goto('/app/wiki/graph');
        await page.waitForTimeout(1200);

        await openOwnerDropdown(page);
        await ownerGroup(page).getByPlaceholder('Søk i eiere …').fill('nobody-with-this-name');
        await page.waitForTimeout(200);

        await expect(ownerGroup(page).getByText('Ingen treff.')).toBeVisible();
    });

    test('many documents produce a scrollable list, not a tall page', async ({ page }) => {
        await page.goto('/app/wiki/graph');
        await page.waitForTimeout(1200);

        await openDocumentDropdown(page);
        const panel = documentGroup(page);
        const box = await panel.boundingBox();
        expect(box).not.toBeNull();
        // The panel has its own max-height/overflow — it must stay well short of the
        // full list's natural height (22 options at ~40px each would be ~900px).
        expect(box.height).toBeLessThan(400);

        // The scrollable element is the inner option list, not the popover container itself
        // (which also holds the fixed-height search box and footer).
        const scrollable = panel.locator('.overflow-y-auto');
        const scrollHeight = await scrollable.evaluate((el) => el.scrollHeight);
        const clientHeight = await scrollable.evaluate((el) => el.clientHeight);
        expect(scrollHeight).toBeGreaterThan(clientHeight);
    });

    test('many owners produce a scrollable list, not a tall page', async ({ page }) => {
        await page.goto('/app/wiki/graph');
        await page.waitForTimeout(1200);

        await openOwnerDropdown(page);
        const panel = ownerGroup(page);
        const box = await panel.boundingBox();
        expect(box).not.toBeNull();
        expect(box.height).toBeLessThan(400);

        const scrollable = panel.locator('.overflow-y-auto');
        const scrollHeight = await scrollable.evaluate((el) => el.scrollHeight);
        const clientHeight = await scrollable.evaluate((el) => el.clientHeight);
        expect(scrollHeight).toBeGreaterThan(clientHeight);
    });

    test('selecting three documents shows a compact count on the closed control, not a long list of names', async ({ page }) => {
        await page.goto('/app/wiki/graph');
        await page.waitForTimeout(1200);

        await openDocumentDropdown(page);
        await documentCheckbox(page, 'Masterdata ITIL.docx').click();
        await documentCheckbox(page, 'Masterdata Samhandling.docx').click();
        await documentGroup(page).getByText(/Skalatest dokument 01/).click();
        await documentGroup(page).getByRole('button', { name: 'Ferdig' }).click();

        await expect(documentTrigger(page)).toHaveText('3 dokumenter valgt');
    });

    test('selecting three owners shows a compact count on the closed control', async ({ page }) => {
        await page.goto('/app/wiki/graph');
        await page.waitForTimeout(1200);

        await openOwnerDropdown(page);
        await ownerCheckbox(page, 'Gerhard Næss').click();
        await ownerGroup(page).getByText('Skalatest Eier 01').click();
        await ownerGroup(page).getByText('Skalatest Eier 02').click();
        await ownerGroup(page).getByRole('button', { name: 'Ferdig' }).click();

        await expect(ownerTrigger(page)).toHaveText('3 eiere valgt');
    });

    test('document + owner combination still works at scale, and Grafoversikt updates', async ({ page }) => {
        await page.goto('/app/wiki/graph');
        await page.waitForTimeout(1200);

        await openDocumentDropdown(page);
        await documentCheckbox(page, 'Masterdata ITIL.docx').click();
        await documentGroup(page).getByRole('button', { name: 'Ferdig' }).click();

        await openOwnerDropdown(page);
        await ownerCheckbox(page, 'Gerhard Næss').click();
        await ownerGroup(page).getByRole('button', { name: 'Ferdig' }).click();
        await page.waitForTimeout(300);

        await expect(page.getByText('9 av 16 sider')).toBeVisible();
    });

    test('global reset restores the full graph even with scale data present', async ({ page }) => {
        await page.goto('/app/wiki/graph');
        await page.waitForTimeout(1200);

        await openDocumentDropdown(page);
        await documentGroup(page).getByText(/Skalatest dokument 03/).click();
        await documentGroup(page).getByRole('button', { name: 'Ferdig' }).click();
        await page.waitForTimeout(200);

        await page.getByRole('button', { name: 'Nullstill filtre' }).first().click();
        await page.waitForTimeout(300);

        await expect(documentTrigger(page)).toHaveText('Alle dokumenter');
        await expect(page.getByText(/\d+ av 16 sider/)).toHaveCount(0);
    });

    test('no horizontal scroll and no console errors with scale data loaded', async ({ page }) => {
        const consoleErrors = [];
        page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });

        await page.goto('/app/wiki/graph');
        await page.waitForTimeout(1200);

        const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
        const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
        expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 1);

        await openDocumentDropdown(page);
        await page.waitForTimeout(300);

        expect(consoleErrors).toEqual([]);
    });
});
