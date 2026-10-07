import { test, expect } from '@playwright/test';
import { tinker } from './helpers/risk.js';
import { e2eWikiCustomerId, loginAsWikiReader } from './helpers/wiki.js';

const FIXTURE = '\\Tests\\Support\\WikiTabPreservationE2EFixture';
let CUSTOMER_ID;

/**
 * Verifies "Rett fanebevaring i Enterprise Wiki": a write action performed from the Kjøringer tab
 * (or Kildedokumenter) must redirect back to that SAME tab — not to the default Wiki-sider tab —
 * both on success and on a controlled error. Checked via the URL's tab= query param and the
 * secondary nav's aria-current, which CustomerAppLayout derives directly from the URL, never from
 * stale client state.
 */
test.describe.serial('Wiki tab preservation after actions', () => {
    test.beforeAll(async () => {
        CUSTOMER_ID = await e2eWikiCustomerId();
        await tinker(`${FIXTURE}::seed(${CUSTOMER_ID});`);
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup(${CUSTOMER_ID});`);
    });

    test('cancelling a run from Kjøringer stays on Kjøringer, not Wiki-sider', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator('[data-run-item]', { has: page.getByText('E2E Tab Preservation Run Check.docx', { exact: true }) }).first();
        await expect(row).toBeVisible();

        await row.getByRole('button', { name: 'Avbryt kjøring' }).click();
        await page.getByRole('button', { name: 'Avbryt kjøringen' }).click();

        await page.waitForURL((url) => url.searchParams.get('tab') === 'runs');
        expect(new URL(page.url()).searchParams.get('tab')).toBe('runs');
        await expect(page.getByRole('link', { name: 'Kjøringer' })).toHaveAttribute('aria-current', 'page');
        await expect(page.getByRole('link', { name: 'Wiki-sider' })).not.toHaveAttribute('aria-current', 'page');

        // Success flash shown without a tab jump.
        await expect(page.getByText('Kjøringen ble avbrutt.', { exact: false })).toBeVisible();
    });

    test('assigning a document owner from Kildedokumenter stays on Kildedokumenter', async ({ page }) => {
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=sources');

        const row = page.locator('tr', { has: page.getByText('E2E Tab Preservation Source Check.docx', { exact: true }) });
        await expect(row).toBeVisible();

        const select = row.locator('select');
        const optionValues = await select.locator('option').evaluateAll(
            (options) => options.map((o) => o.value).filter((v) => v !== ''),
        );
        expect(optionValues.length).toBeGreaterThan(0);
        await select.selectOption(optionValues[0]);
        await row.getByRole('button', { name: 'Lagre eier' }).click();

        await page.waitForURL((url) => url.searchParams.get('tab') === 'sources');
        expect(new URL(page.url()).searchParams.get('tab')).toBe('sources');
        await expect(page.getByRole('link', { name: 'Kildedokumenter' })).toHaveAttribute('aria-current', 'page');
        await expect(page.getByRole('link', { name: 'Wiki-sider' })).not.toHaveAttribute('aria-current', 'page');
    });

    test('desktop and 390px render the Kjøringer tab correctly with no console errors', async ({ page }) => {
        const errors = [];
        page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
        page.on('pageerror', (err) => errors.push(String(err)));

        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');
        await expect(page.getByRole('link', { name: 'Kjøringer' })).toHaveAttribute('aria-current', 'page');

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/app/wiki?tab=runs');
        await expect(page.getByRole('link', { name: 'Kjøringer' })).toHaveAttribute('aria-current', 'page');

        const bodyOverflows = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        );
        expect(bodyOverflows).toBe(false);
        expect(errors).toEqual([]);
    });
});
