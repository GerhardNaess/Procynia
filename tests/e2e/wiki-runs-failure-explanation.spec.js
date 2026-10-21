import { test, expect } from '@playwright/test';
import { tinker } from './helpers/risk.js';
import { e2eWikiCustomerId, loginAsWikiReader } from './helpers/wiki.js';

const FIXTURE = '\\Tests\\Support\\WikiRunsFindingsCompactE2EFixture';
const SCREENSHOT_DIR = process.env.E2E_SCREENSHOT_DIR ?? null;
const TITLE = 'Vurdering av betalingsbetingelser og økonomisk informasjon';
const TECHNICAL = ['Exception', 'planned_section_no_evidence', 'failed to generate', 'Run [', 'page [110]', 'Details —'];

/**
 * Kjøringer → «Status og oppfølging» for a run stopped the way Wiki run 26 is stored (an exact copy
 * of its error_message and page plan, in the E2E customer): a short Norwegian explanation, the
 * original message only behind «Vis tekniske detaljer», and a timeline that shows where it stopped.
 */
test.describe.serial('Kjøringer: forståelig feilmelding for en stoppet kjøring', () => {
    let customerId;
    let runId;
    let storedMessage;

    test.beforeAll(async () => {
        customerId = await e2eWikiCustomerId();
        const { stdout } = await tinker(`echo json_encode(${FIXTURE}::seedStoppedRun(${customerId}));`);
        ({ run_id: runId, error_message: storedMessage } = JSON.parse(stdout.trim()));
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup(${customerId});`);
    });

    test('the run explains itself in Norwegian, with the original only behind «Vis tekniske detaljer»', async ({ page }) => {
        const errors = [];
        page.on('pageerror', (err) => errors.push(String(err)));
        await page.setViewportSize({ width: 1366, height: 900 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`[data-run-item][data-run-id="${runId}"]`).first();
        const mainRow = row.locator('[data-run-main-row]');
        for (const fragment of TECHNICAL) {
            await expect(mainRow).not.toContainText(fragment);
        }

        await mainRow.locator('[data-run-details-toggle]').click();
        const status = row.locator('[data-run-status-details]');
        await expect(status).toBeVisible();

        await expect(status.getByRole('heading', { level: 3 })).toHaveText('Kunne ikke generere artikkelen');
        await expect(status.locator('p')).toHaveText([
            `Artikkelen «${TITLE}» mangler kildegrunnlag for fem planlagte avsnitt.`,
            'Kjøringen ble stoppet for å unngå å generere innhold uten tilstrekkelig dokumentasjon.',
            'Det finnes ingen handling for dette i Kjøringer.',
        ]);
        // Nothing on this screen resolves it, so nothing is offered.
        await expect(status.locator('[data-run-primary-action]')).toHaveCount(0);
        for (const fragment of TECHNICAL) {
            await expect(row).not.toContainText(fragment);
        }

        // Where it stopped, under «Vis flere detaljer»: planning and structure finished, «Sider»
        // failed, the rest never ran.
        await row.getByRole('button', { name: 'Vis flere detaljer' }).click();
        await expect(row.locator('[data-progress-step]').first()).toBeVisible();
        expect(await row.locator('[data-progress-step]').evaluateAll((els) => els.map((el) => el.dataset.progressState)))
            .toEqual(['done', 'done', 'done', 'error', 'empty', 'empty']);

        if (SCREENSHOT_DIR) {
            await row.screenshot({ path: `${SCREENSHOT_DIR}/run-failure-explanation-1366.png` });
        }

        // The original diagnostics, on request only, and closed again on request.
        const other = row.locator('[data-run-other-actions]');
        const toggle = other.locator('button[aria-expanded]');
        await expect(toggle).toHaveText('Vis tekniske detaljer');
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expect(toggle).toHaveText('Skjul tekniske detaljer');
        await expect(other.getByText(storedMessage, { exact: true })).toBeVisible();

        if (SCREENSHOT_DIR) {
            await row.screenshot({ path: `${SCREENSHOT_DIR}/run-failure-explanation-details-1366.png` });
        }

        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(other.getByText(storedMessage, { exact: true })).toHaveCount(0);

        const overflows = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
        expect(overflows).toBe(false);
        expect(errors).toEqual([]);
    });

    test('the run is still stored exactly as it was', async () => {
        const { stdout } = await tinker(`echo json_encode(\\App\\Models\\EnterpriseWikiIngestRun::find(${runId})->only(['status', 'failed_phase', 'error_message']));`);
        expect(JSON.parse(stdout.trim())).toEqual({ status: 'failed', failed_phase: null, error_message: storedMessage });
    });
});
