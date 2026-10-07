import { test, expect } from '@playwright/test';
import { tinker } from './helpers/risk.js';
import { e2eWikiCustomerId, loginAsWikiReader } from './helpers/wiki.js';

const FIXTURE = '\\Tests\\Support\\WikiWordTableE2EFixture';
let CUSTOMER_ID;

test.describe.serial('Word table rendering in a Wiki page', () => {
    test.beforeAll(async () => {
        CUSTOMER_ID = await e2eWikiCustomerId();
        await tinker(`echo ${FIXTURE}::seed(${CUSTOMER_ID});`);
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup(${CUSTOMER_ID});`);
    });

    test('renders a genuine semantic table with correct headers and row order', async ({ page }) => {
        await loginAsWikiReader(page);
        await page.goto('/app/wiki/e2e-word-table-verifisering');
        await page.waitForTimeout(1000);

        const table = page.locator('table');
        await expect(table).toBeVisible();
        await expect(page.locator('thead')).toBeVisible();
        await expect(page.locator('thead th').nth(0)).toHaveText('Tjeneste');
        await expect(page.locator('thead th').nth(1)).toHaveText('SLA');
        await expect(page.locator('thead th').nth(2)).toHaveText('Pris');
        await expect(page.locator('thead th').nth(3)).toHaveText('Beskrivelse');

        const rows = page.locator('tbody tr');
        await expect(rows).toHaveCount(2);
        await expect(rows.nth(0)).toContainText('Administrert klient');
        await expect(rows.nth(1)).toContainText('Standard support');
    });

    test('preserves cell content: numbers, percentages, currency, empty cell, long text, Norwegian characters', async ({ page }) => {
        await loginAsWikiReader(page);
        await page.goto('/app/wiki/e2e-word-table-verifisering');
        await page.waitForTimeout(1000);

        await expect(page.getByRole('cell', { name: '99,5 %' })).toBeVisible();
        await expect(page.getByRole('cell', { name: 'kr 420' })).toBeVisible();
        await expect(page.getByRole('cell', { name: 'kr 100' })).toBeVisible();
        await expect(page.getByText(/Æøå-tegn testes her også/)).toBeVisible();

        // Standard support's SLA cell is empty in the source table — the row/column structure
        // must still hold (4 cells in that row), not collapse or shift.
        const secondRowCells = page.locator('tbody tr').nth(1).locator('td');
        await expect(secondRowCells).toHaveCount(4);
        await expect(secondRowCells.nth(1)).toHaveText('');
    });

    test('shows the table caption and a precise source citation', async ({ page }) => {
        await loginAsWikiReader(page);
        await page.goto('/app/wiki/e2e-word-table-verifisering');
        await page.waitForTimeout(1000);

        await expect(page.getByText('Tabell 1', { exact: true })).toBeVisible();
        await expect(page.getByText(/Plassering i kilden.*Tabell 1/)).toBeVisible();
    });

    test('table cell text is at least 16px', async ({ page }) => {
        await loginAsWikiReader(page);
        await page.goto('/app/wiki/e2e-word-table-verifisering');
        await page.waitForTimeout(1000);

        const fontSize = await page.locator('table td').first().evaluate((el) => window.getComputedStyle(el).fontSize);
        expect(parseFloat(fontSize)).toBeGreaterThanOrEqual(16);
    });

    test('mobile viewport (390px): table scrolls within its own container, page does not', async ({ page }) => {
        await loginAsWikiReader(page);
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/app/wiki/e2e-word-table-verifisering');
        await page.waitForTimeout(1000);

        const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
        const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
        expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 1);

        await expect(page.locator('table')).toBeVisible();
    });

    test('no console errors or failed requests while viewing the table page', async ({ page }) => {
        const consoleErrors = [];
        const failedRequests = [];
        page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
        page.on('requestfailed', (req) => failedRequests.push(`${req.method()} ${req.url()}`));
        page.on('response', (res) => { if (res.status() >= 500) failedRequests.push(`${res.status()} ${res.url()}`); });

        await loginAsWikiReader(page);
        await page.goto('/app/wiki/e2e-word-table-verifisering');
        await page.waitForTimeout(1000);

        expect(consoleErrors).toEqual([]);
        expect(failedRequests).toEqual([]);
    });
});
