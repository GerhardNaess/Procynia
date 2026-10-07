import { test, expect } from '@playwright/test';
import { tinker } from './helpers/risk.js';
import { e2eWikiCustomerId, loginAsWikiReader } from './helpers/wiki.js';

const FIXTURE = '\\Tests\\Support\\WikiRunsStalledIndicatorE2EFixture';

/**
 * The "Ser ut til å stå stille" warning on a run that is in an actively-processing status and has
 * been idle for a long time (the fixture backdates it 40 minutes).
 *
 * This spec used to set a run waiting for document-owner approval beside it, to prove the warning
 * is never shown for a human wait. 5d26caf2 retired that status, so no such run can exist; what
 * remains to see in the app is that a run that should be progressing, and is not, says so.
 */
test.describe.serial('Kjøringer stalled indicator', () => {
    let customerId;
    let activeRunId;

    test.beforeAll(async () => {
        customerId = await e2eWikiCustomerId();
        const { stdout } = await tinker(`echo json_encode(${FIXTURE}::seed(${customerId}));`);
        activeRunId = JSON.parse(stdout.trim()).active_run_id;
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup(${customerId});`);
    });

    test('a genuinely idle active run (generating_pages) shows the stalled warning, without console errors', async ({ page }) => {
        const errors = [];
        page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
        page.on('pageerror', (err) => errors.push(String(err)));

        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`[data-run-item][data-run-id="${activeRunId}"]`).first();
        const mainRow = row.locator('[data-run-main-row]');
        await expect(row).toBeVisible();
        await expect(mainRow.getByText('Ser ut til å stå stille', { exact: true })).toBeVisible();
        await expect(mainRow.getByText(/Ingen registrert fremdrift siden/)).toBeVisible();
        expect(errors).toEqual([]);
    });

    test('390px: the stalled warning is on the mobile card, with no console errors and no overflow', async ({ page }) => {
        const errors = [];
        page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
        page.on('pageerror', (err) => errors.push(String(err)));

        await page.setViewportSize({ width: 390, height: 844 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`[data-run-item][data-run-id="${activeRunId}"]`).first();
        await expect(row).toBeVisible();
        await expect(row.locator('[data-run-mobile-card]').getByText('Ser ut til å stå stille', { exact: true })).toBeVisible();

        const bodyOverflows = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        );
        expect(bodyOverflows).toBe(false);
        expect(errors).toEqual([]);
    });
});
