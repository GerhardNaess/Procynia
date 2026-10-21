import { test, expect } from '@playwright/test';
import { tinker } from './helpers/risk.js';
import { e2eWikiCustomerId, loginAsWikiReader } from './helpers/wiki.js';

const FIXTURE = '\\Tests\\Support\\WikiRunsFindingsCompactE2EFixture';
const SCREENSHOT_DIR = process.env.E2E_SCREENSHOT_DIR ?? null;

/**
 * Wiki → Kjøringer: every run says in one plain word whether it is finished, in progress, failed
 * or needs follow-up. The stored status is unchanged; an escalated run reads «Fullført» only when
 * the backend confirms nothing is left, otherwise «Krever oppfølging».
 */
test.describe.serial('Kjøringer: forståelige statusnavn', () => {
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

    const EXPECTED = {
        completed: ['Fullført', 'emerald', null],
        failed: ['Feilet', 'rose', null],
        active: ['Pågår', 'sky', null],
        escalated_open: ['Krever oppfølging', 'amber', null],
        escalated_failed_page: ['Krever oppfølging', 'amber', '1 feil'],
        // Follow-up done: one «done» word, the same as a completed run.
        escalated_done: ['Fullført', 'emerald', null],
        cancelled: ['Avbrutt', 'slate', null],
    };

    for (const width of [1366, 390]) {
        test(`status, line and colour agree at ${width}px, with no sideways scrolling`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            await loginAsWikiReader(page);
            await page.goto('/app/wiki?tab=runs');
            const container = width < 1024 ? '[data-run-mobile-card]' : '[data-run-main-row]';

            for (const [key, [label, tone, line]] of Object.entries(EXPECTED)) {
                const cell = page.locator(`[data-run-item][data-run-id="${ids[key]}"]`).first().locator(container);
                const badge = cell.getByText(label, { exact: true }).first();
                await expect(badge, key).toBeVisible();
                expect(await badge.getAttribute('class'), key).toContain(`bg-${tone}-`);
                if (line) {
                    await expect(cell.locator('[data-run-follow-up]'), key).toHaveText(line);
                } else {
                    await expect(cell.locator('[data-run-follow-up]'), key).toHaveCount(0);
                }
                for (const word of ['Eskalert', 'Escalated']) {
                    await expect(cell, key).not.toContainText(word);
                }
            }

            // A run that by its own record never ran is not in the list.
            await expect(page.locator(`[data-run-item][data-run-id="${ids.never_ran}"]`)).toHaveCount(0);

            if (width >= 1024) {
                // «Krever oppfølging» fits the status column without a taller row.
                const heights = await Promise.all(['completed', 'escalated_open'].map((key) => page
                    .locator(`[data-run-item][data-run-id="${ids[key]}"] [data-run-main-row]`).first()
                    .evaluate((el) => el.getBoundingClientRect().height)));
                expect(Math.abs(heights[0] - heights[1])).toBeLessThanOrEqual(1);
            }

            const overflows = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
            expect(overflows).toBe(false);

            if (SCREENSHOT_DIR) {
                await page.locator('[data-run-item]').first().evaluate((el) => el.scrollIntoView({ block: 'start' }));
                await page.screenshot({ path: `${SCREENSHOT_DIR}/run-status-names-${width}.png`, fullPage: width < 1024 });
            }
        });
    }

    test('details keep the history and explain it only where it is needed', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 900 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');

        const open = async (key) => {
            const item = page.locator(`[data-run-item][data-run-id="${ids[key]}"]`).first();
            await item.locator('[data-run-main-row] [data-run-details-toggle]').click();
            // The progress and its history note are under «Vis flere detaljer».
            await item.getByRole('button', { name: 'Vis flere detaljer' }).click();

            return item;
        };

        // Follow-up completed: QA stays marked as how the run ended, and one line says why.
        const done = await open('escalated_done');
        const states = await done.locator('[data-progress-step]').evaluateAll((els) => els.map((el) => el.dataset.progressState));
        expect(states[states.length - 1]).toBe('error');
        await expect(done.locator('[data-run-progress-history-note]')).toHaveText('Fremdriften viser resultatet fra den opprinnelige kjøringen. QA-oppfølgingen er senere fullført.');
        await expect(done.getByText('Fremdriften viser resultatet fra den opprinnelige kjøringen.', { exact: false })).toHaveCount(1);

        if (SCREENSHOT_DIR) {
            await done.screenshot({ path: `${SCREENSHOT_DIR}/run-progress-history-note-1366.png` });
        }

        // Not where follow-up is still open, nor on any other run.
        for (const key of ['escalated_open', 'escalated_failed_page', 'completed', 'failed']) {
            const item = await open(key);
            await expect(item.locator('[data-run-progress-history-note]'), key).toHaveCount(0);
        }

        // A failed run's cause is still in the details, while its row says only «Feilet».
        const failed = page.locator(`[data-run-item][data-run-id="${ids.failed}"]`).first();
        await expect(failed.locator('[data-run-status-details]')).toContainText('Kunne ikke generere artikkelen');
        await expect(failed.locator('[data-run-main-row]')).not.toContainText('Stoppet med feil');
    });

    test('the status filter offers the same words and shows the matching runs', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 900 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');

        const filter = page.getByRole('combobox', { name: 'Status' });
        const options = await filter.locator('option').allTextContents();
        expect(options).toEqual(['Alle statuser', 'Venter', 'Pågår', 'Fullført', 'Krever oppfølging', 'Feilet', 'Avbrutt', 'Sideplan lagret']);

        await filter.selectOption({ label: 'Krever oppfølging' });
        await expect(page).toHaveURL(/run_status=needs_follow_up/);
        await expect(page.locator(`[data-run-item][data-run-id="${ids.escalated_open}"]`)).toHaveCount(1);
        await expect(page.locator(`[data-run-item][data-run-id="${ids.escalated_failed_page}"]`)).toHaveCount(1);
        await expect(page.locator(`[data-run-item][data-run-id="${ids.escalated_done}"]`)).toHaveCount(0);

        await filter.selectOption({ label: 'Fullført' });
        await expect(page).toHaveURL(/run_status=completed/);
        await expect(page.locator(`[data-run-item][data-run-id="${ids.escalated_done}"]`)).toHaveCount(1);
        await expect(page.locator(`[data-run-item][data-run-id="${ids.completed}"]`)).toHaveCount(1);
        await expect(page.locator(`[data-run-item][data-run-id="${ids.escalated_open}"]`)).toHaveCount(0);

        // Stored statuses are untouched.
        const { stdout } = await tinker(`echo json_encode(\\App\\Models\\EnterpriseWikiIngestRun::whereIn('id', [${ids.escalated_done}, ${ids.escalated_open}])->pluck('status')->unique()->values());`);
        expect(JSON.parse(stdout.trim())).toEqual(['escalated']);
    });
});
