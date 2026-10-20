import { test, expect } from '@playwright/test';
import { tinker } from './helpers/risk.js';
import { e2eWikiCustomerId, loginAsWikiReader } from './helpers/wiki.js';

const FIXTURE = '\\Tests\\Support\\WikiRunsFindingsCompactE2EFixture';
const SCREENSHOT_DIR = process.env.E2E_SCREENSHOT_DIR ?? null;

// Only in the finding's explanation, which belongs in the behandlingsvisning, never in a row.
const SOURCE_SENTENCE = 'og bør derfor aldri slås sammen til ett tall.';

/**
 * Kjøringer → «Vis detaljer» → Funn: one compact row per QA finding (Funn, Wiki-side, Alvorlighet,
 * Status, Handling). The explanation, claim and source live in the behandlingsvisning the row
 * links to, and the run's own explanation is said once, in «Status og oppfølging».
 */
test.describe.serial('Kjøringer: kompakt QA-funnliste', () => {
    let customerId;
    let runId;
    let claimIds;

    test.beforeAll(async () => {
        customerId = await e2eWikiCustomerId();
        const { stdout } = await tinker(`echo json_encode(${FIXTURE}::seed(${customerId}));`);
        ({ run_id: runId, claim_ids: claimIds } = JSON.parse(stdout.trim()));
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup(${customerId});`);
    });

    async function openRunWithKeyboard(page) {
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`[data-run-item][data-run-id="${runId}"]`).first();
        // Below lg the row is its mobile card, with its own «Vis detaljer».
        const container = page.viewportSize().width < 1024 ? '[data-run-mobile-card]' : '[data-run-main-row]';
        const toggle = row.locator(`${container} [data-run-details-toggle]`);
        await toggle.focus();
        await page.keyboard.press('Enter');
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');

        return row;
    }

    test('findings are compact rows without explanations or a second expansion level', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 900 });
        const row = await openRunWithKeyboard(page);

        // An escalated run with findings opens on Funn.
        await expect(row.locator('[data-run-section="findings"]')).toHaveAttribute('aria-pressed', 'true');
        const table = row.locator('[data-run-findings-table]');
        await expect(table).toBeVisible();

        const findingRows = table.locator('[data-finding-row]');
        // Default «Åpne»: two normative findings, two unsupported claims and the pending suggestion.
        await expect(findingRows).toHaveCount(5);

        const firstClaimRow = table.locator(`[data-finding-row="claim-defect-${claimIds[0]}"]`);
        await expect(firstClaimRow).toContainText(`#${claimIds[0]} · Endret normativ betydning`);
        await expect(firstClaimRow).toContainText('Deteksjonsgap og responsgap');
        await expect(firstClaimRow).toContainText('Advarsel');

        // A normal finding is one short row; a very long page name may take a third line, never more.
        const heights = Object.fromEntries(await findingRows.evaluateAll(
            (els) => els.map((el) => [el.dataset.findingRow, el.getBoundingClientRect().height]),
        ));
        for (const claimId of claimIds.slice(0, 2)) {
            expect(heights[`claim-defect-${claimId}`]).toBeLessThanOrEqual(72);
        }
        for (const height of Object.values(heights)) {
            expect(height).toBeLessThanOrEqual(96);
        }

        const detail = row.locator('[data-run-detail-panel]');
        await expect(table).not.toContainText(SOURCE_SENTENCE);
        await expect(table).not.toContainText('Systemet anbefaler');
        await expect(table.locator('[aria-expanded]')).toHaveCount(0);

        // The run's explanation is said once, in «Status og oppfølging», not again above the table.
        const statusText = (await row.locator('[data-run-status-details] li').first().textContent()).trim();
        const occurrences = (await detail.textContent()).split(statusText).length - 1;
        expect(occurrences).toBe(1);

        // Technical details stay behind their toggle.
        const technicalToggle = row.getByRole('button', { name: 'Vis tekniske detaljer' });
        await expect(row.getByText('QA gate escalated', { exact: false })).toHaveCount(0);
        await technicalToggle.click();
        await expect(row.getByText('QA gate escalated', { exact: false })).toBeVisible();
        await technicalToggle.click();

        if (SCREENSHOT_DIR) {
            await row.screenshot({ path: `${SCREENSHOT_DIR}/runs-findings-compact-1366.png` });
        }
    });

    test('Funn filters show the matching rows and say their counts', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 900 });
        const row = await openRunWithKeyboard(page);
        const table = row.locator('[data-run-findings-table]');
        const findingRows = table.locator('[data-finding-row]');

        for (const [filterKey, name, expected] of [
            ['all', 'Alle 6', 6],
            ['resolved', 'Løst 1', 1],
            ['blocking', 'Blokkerende 0', 0],
            ['informative', 'Informasjon 0', 0],
            ['open', 'Åpne 5', 5],
        ]) {
            const chip = row.locator(`[data-findings-filter="${filterKey}"]`);
            await expect(chip).toHaveAccessibleName(name);
            await chip.focus();
            await page.keyboard.press('Enter');
            await expect(chip).toHaveAttribute('aria-pressed', 'true');
            await expect(findingRows).toHaveCount(expected);
            if (expected === 0) {
                await expect(table).toContainText('Ingen funn i dette utvalget.');
            }
        }
    });

    test('the row action opens the finding in the behandlingsvisning with its full explanation', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 900 });
        const row = await openRunWithKeyboard(page);

        const link = row.locator(`[data-finding-row="claim-defect-${claimIds[0]}"] a`);
        await expect(link).toBeVisible();
        await link.focus();
        await page.keyboard.press('Enter');

        await expect(page).toHaveURL(new RegExp(`claim_id=${claimIds[0]}`));
        const explanation = page.getByText(SOURCE_SENTENCE, { exact: false }).first();
        await expect(explanation).toBeVisible();

        if (SCREENSHOT_DIR) {
            await explanation.evaluate((el) => el.scrollIntoView({ block: 'center' }));
            await page.waitForTimeout(500);
            await page.screenshot({ path: `${SCREENSHOT_DIR}/runs-finding-show-1366.png`, fullPage: false });
        }
    });

    for (const [index, explanation] of [
        [2, 'Kilden sier ikke hvem som leder gjennomgangen.'],
        [3, 'Kilden nevner ikke styrets godkjenning av rapporten.'],
    ]) {
        test(`a finding outside a source-based block keeps its own explanation in the behandlingsvisning (${index === 2 ? 'open' : 'decided'})`, async ({ page }) => {
            await page.setViewportSize({ width: 1366, height: 900 });
            const row = await openRunWithKeyboard(page);
            await row.locator('[data-findings-filter="all"]').click();

            const findingRow = row.locator(`[data-finding-row="claim-defect-${claimIds[index]}"]`);
            await expect(findingRow).not.toContainText(explanation);
            await findingRow.locator('a').click();

            await expect(page).toHaveURL(new RegExp(`claim_id=${claimIds[index]}`));
            await expect(page.getByText(explanation, { exact: true }).first()).toBeVisible();
        });
    }

    test('«Tilbake til funn» reopens the run on the finding and focuses its row', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 900 });
        await loginAsWikiReader(page);
        await page.goto(`/app/wiki?tab=runs&focus_run=${runId}&focus_finding=claim-defect-${claimIds[1]}`);

        const target = page.locator(`[data-run-item][data-run-id="${runId}"] [data-finding-row="claim-defect-${claimIds[1]}"]`);
        await expect(target).toBeVisible();
        await expect(target).toBeFocused();
    });

    for (const width of [1280, 1366, 390]) {
        test(`no page overflow and no overlapping finding text at ${width}px`, async ({ page }) => {
            const errors = [];
            page.on('pageerror', (err) => errors.push(String(err)));

            await page.setViewportSize({ width, height: 900 });
            const row = await openRunWithKeyboard(page);
            await row.locator('[data-findings-filter="all"]').click();
            await expect(row.locator('[data-finding-row]')).toHaveCount(6);

            // From lg up the table fits its panel; only the phone layout scrolls it sideways.
            if (width >= 1024) {
                const fits = await row.locator('[data-run-findings-table]').evaluate(
                    (tableEl) => tableEl.getBoundingClientRect().width <= tableEl.parentElement.clientWidth + 1,
                );
                expect(fits).toBe(true);
                for (const claimId of claimIds.slice(0, 2)) {
                    const height = await row.locator(`[data-finding-row="claim-defect-${claimId}"]`)
                        .evaluate((el) => el.getBoundingClientRect().height);
                    expect(height).toBeLessThanOrEqual(72);
                }
            }

            const bodyOverflows = await page.evaluate(
                () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
            );
            expect(bodyOverflows).toBe(false);

            const overlaps = await row.locator('[data-run-findings-table]').evaluate((tableEl) => {
                const rects = Array.from(tableEl.querySelectorAll('td > *'))
                    .map((el) => el.getBoundingClientRect())
                    .filter((r) => r.width > 0 && r.height > 0);
                let count = 0;
                for (let i = 0; i < rects.length; i++) {
                    for (let j = i + 1; j < rects.length; j++) {
                        const a = rects[i];
                        const b = rects[j];
                        if (Math.min(a.right, b.right) - Math.max(a.left, b.left) > 2
                            && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 2) count++;
                    }
                }
                return count;
            });
            expect(overlaps).toBe(0);
            expect(errors).toEqual([]);

            if (SCREENSHOT_DIR && width !== 1366) {
                await row.screenshot({ path: `${SCREENSHOT_DIR}/runs-findings-compact-${width}.png` });
            }
        });
    }
});
