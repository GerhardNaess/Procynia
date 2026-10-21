import { test, expect } from '@playwright/test';
import { tinker } from './helpers/risk.js';
import { e2eWikiCustomerId, loginAsWikiReader } from './helpers/wiki.js';

const FIXTURE = '\\Tests\\Support\\WikiRunsFindingsCompactE2EFixture';
const SCREENSHOT_DIR = process.env.E2E_SCREENSHOT_DIR ?? null;

/**
 * Wiki → Kjøringer → «Vis detaljer» → «Fjern fra oversikten»: a finished run with nothing left to
 * follow up can be cleared from the list after a confirmation. The run and everything referring to
 * it stay in the database; running runs and escalations with follow-up left are never offered.
 */
test.describe.serial('Kjøringer: fjern fra oversikten', () => {
    let customerId;
    let ids;

    test.beforeAll(async () => {
        customerId = await e2eWikiCustomerId();
        const { stdout } = await tinker(`echo json_encode(${FIXTURE}::seedDismissal(${customerId}));`);
        ids = JSON.parse(stdout.trim());
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup(${customerId});`);
    });

    const runRow = (page, runId) => page.locator(`[data-run-item][data-run-id="${runId}"]`).first();
    const toast = (page) => page.locator('#procynia-live-toast');

    async function openDetails(page, runId) {
        const container = page.viewportSize().width < 1024 ? '[data-run-mobile-card]' : '[data-run-main-row]';
        const toggle = runRow(page, runId).locator(`${container} [data-run-details-toggle]`);
        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');

        // «Fjern fra oversikten» is a secondary action, under «Vis flere detaljer».
        const panel = runRow(page, runId).locator('[data-run-detail-panel]');
        await panel.getByRole('button', { name: 'Vis flere detaljer' }).click();
        await expect(panel.locator('[data-run-more-details-panel]')).toBeVisible();

        return panel;
    }

    async function storedRun(runId) {
        const { stdout } = await tinker(`echo json_encode(\\App\\Models\\EnterpriseWikiIngestRun::find(${runId})?->only(['id', 'status', 'dismissed_at', 'dismissed_by_user_id', 'error_message']));`);

        return JSON.parse(stdout.trim());
    }

    test('nothing is offered for a running run or an escalation with follow-up left', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 900 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');

        for (const key of ['active', 'escalated_open']) {
            const status = await openDetails(page, ids[key]);
            await expect(status).toBeVisible();
            await expect(status.locator('[data-run-dismiss]')).toHaveCount(0);
        }

        // The table keeps its columns: the action lives in the detail view only.
        await expect(runRow(page, ids.failed).locator('[data-run-main-row] [data-run-dismiss]')).toHaveCount(0);
    });

    test('a phone shows the action and the dialog without sideways scrolling', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 900 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');

        const status = await openDetails(page, ids.failed);
        await status.locator('[data-run-dismiss]').click();
        const dialog = page.getByRole('dialog', { name: 'Fjerne kjøringen fra oversikten?' });
        await expect(dialog).toBeVisible();

        const overflows = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
        expect(overflows).toBe(false);
        const buttons = await dialog.getByRole('button').evaluateAll((els) => els.map((el) => el.getBoundingClientRect()));
        const [a, b] = buttons;
        const overlap = Math.min(a.right, b.right) - Math.max(a.left, b.left) > 1 && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 1;
        expect(overlap).toBe(false);

        if (SCREENSHOT_DIR) {
            await page.screenshot({ path: `${SCREENSHOT_DIR}/run-dismiss-dialog-390.png` });
        }

        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
    });

    test('A. a failed run: «Avbryt» keeps it, a refused request keeps it, confirming clears it — and it stays stored', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 900 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');

        const status = await openDetails(page, ids.failed);
        // The approved failure text is still what the run says.
        await expect(status).toContainText('Kunne ikke generere artikkelen');
        const action = status.locator('[data-run-dismiss]');
        await expect(action).toHaveText('Fjern fra oversikten');

        if (SCREENSHOT_DIR) {
            await runRow(page, ids.failed).screenshot({ path: `${SCREENSHOT_DIR}/run-dismiss-action-1366.png` });
        }

        // Keyboard: open with Enter, focus starts on the harmless choice, «Avbryt» returns focus.
        await action.focus();
        await page.keyboard.press('Enter');
        const dialog = page.getByRole('dialog', { name: 'Fjerne kjøringen fra oversikten?' });
        await expect(dialog).toBeVisible();
        await expect(dialog).toContainText('Kjøringen fjernes fra Kjøringer. Wiki-artikler, kildedokumentet og historiske data beholdes.');
        await expect(dialog).not.toContainText('Slett');
        await expect(dialog.getByRole('button', { name: 'Avbryt' })).toBeFocused();

        if (SCREENSHOT_DIR) {
            await page.screenshot({ path: `${SCREENSHOT_DIR}/run-dismiss-dialog-1366.png` });
        }

        await page.keyboard.press('Enter');
        await expect(dialog).toHaveCount(0);
        await expect(action).toBeFocused();
        await expect(runRow(page, ids.failed)).toBeVisible();
        expect((await storedRun(ids.failed)).dismissed_at).toBeNull();

        // A failing request: the run stays, the dialog says so, and the action can be tried again.
        await page.route(`**/app/wiki/runs/${ids.failed}/dismiss`, (route) => route.fulfill({ status: 500, contentType: 'text/html', body: '<h1>Server Error</h1>' }));
        await action.click();
        await dialog.getByRole('button', { name: 'Fjern fra oversikten' }).click();
        await expect(dialog.locator('[data-run-dismiss-error]')).toHaveText('Kjøringen ble ikke fjernet fra oversikten. Prøv igjen.');
        await expect(page.getByText('Server Error')).toHaveCount(0);
        await expect(toast(page)).not.toContainText('fjernet fra oversikten.');
        await expect(runRow(page, ids.failed)).toBeVisible();
        await page.unroute(`**/app/wiki/runs/${ids.failed}/dismiss`);

        await dialog.getByRole('button', { name: 'Fjern fra oversikten' }).click();
        await expect(toast(page)).toContainText('Kjøringen er fjernet fra oversikten.');
        await expect(dialog).toHaveCount(0);
        await expect(runRow(page, ids.failed)).toHaveCount(0);

        await page.reload();
        await expect(runRow(page, ids.failed)).toHaveCount(0);

        const stored = await storedRun(ids.failed);
        expect(stored.status).toBe('failed');
        expect(stored.dismissed_at).not.toBeNull();
        expect(stored.error_message).toContain('EnterpriseWikiPlannedSectionEvidenceMissingException');
    });

    for (const key of ['completed', 'cancelled', 'escalated_done']) {
        test(`${key === 'completed' ? 'B' : key === 'cancelled' ? 'C' : 'E'}. a ${key.replace('_', ' ')} run can be cleared, and its data stays`, async ({ page }) => {
            await page.setViewportSize({ width: 1366, height: 900 });
            await loginAsWikiReader(page);
            await page.goto('/app/wiki?tab=runs');

            const { stdout: before } = await tinker(`echo json_encode([\\App\\Models\\EnterpriseWikiIngestRunPage::where('enterprise_wiki_ingest_run_id', ${ids[key]})->count(), \\App\\Models\\EnterpriseWikiIngestRun::find(${ids[key]})->status]);`);

            const status = await openDetails(page, ids[key]);
            await status.locator('[data-run-dismiss]').click();
            await page.getByRole('dialog', { name: 'Fjerne kjøringen fra oversikten?' }).getByRole('button', { name: 'Fjern fra oversikten' }).click();
            await expect(toast(page)).toContainText('Kjøringen er fjernet fra oversikten.');
            await expect(runRow(page, ids[key])).toHaveCount(0);

            const { stdout: after } = await tinker(`echo json_encode([\\App\\Models\\EnterpriseWikiIngestRunPage::where('enterprise_wiki_ingest_run_id', ${ids[key]})->count(), \\App\\Models\\EnterpriseWikiIngestRun::find(${ids[key]})->status]);`);
            expect(JSON.parse(after.trim())).toEqual(JSON.parse(before.trim()));
            expect((await storedRun(ids[key])).dismissed_at).not.toBeNull();
        });
    }

    test('G. the completed run\'s page is still there and still names the run that created it', async () => {
        const { stdout } = await tinker(`echo json_encode([\\App\\Models\\EnterpriseWikiPage::whereKey(${ids.completed_page})->exists(), \\App\\Models\\EnterpriseWikiIngestRunPage::where('enterprise_wiki_page_id', ${ids.completed_page})->value('enterprise_wiki_ingest_run_id')]);`);
        expect(JSON.parse(stdout.trim())).toEqual([true, ids.completed]);
    });
});
