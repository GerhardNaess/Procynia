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
        // Default «Åpne»: two normative findings, the pending unsupported claim and the pending
        // suggestion. The approved unsupported claim is handled, so it is not listed as open.
        await expect(findingRows).toHaveCount(4);

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
            // The resolved lint finding and the approved claim.
            ['resolved', 'Løst 2', 2],
            ['blocking', 'Blokkerende 0', 0],
            ['informative', 'Informasjon 0', 0],
            ['open', 'Åpne 4', 4],
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

        // The article carries one short note on the paragraph, not the explanation.
        const note = page.locator(`[data-testid="inline-qa-note"][data-claim-id="${claimIds[0]}"]`);
        await expect(note).toHaveText(/^QA: Endret normativ betydning – krever vurdering/);
        await expect(page.getByText(SOURCE_SENTENCE, { exact: false })).toHaveCount(0);
        await expect(page.getByText('Vurdering for dette avsnittet')).toHaveCount(0);

        // Its one action moves to the claim's card in the verification basis, which explains it.
        await note.getByTestId('inline-qa-note-open').click();
        const card = page.locator(`article#claim-${claimIds[0]}`);
        await expect(card).toBeFocused();
        const explanation = card.getByText(SOURCE_SENTENCE, { exact: false }).first();
        await expect(explanation).toBeVisible();
        await expect(page).toHaveURL(new RegExp(`claim_id=${claimIds[0]}`));
        await expect(page.getByRole('link', { name: 'Tilbake til funn' }).first()).toBeVisible();

        if (SCREENSHOT_DIR) {
            await explanation.evaluate((el) => el.scrollIntoView({ block: 'center' }));
            await page.waitForTimeout(500);
            await page.screenshot({ path: `${SCREENSHOT_DIR}/runs-finding-show-1366.png`, fullPage: false });
        }
    });

    for (const [index, explanation, noteState] of [
        [2, 'Kilden sier ikke hvem som leder gjennomgangen.', 'krever vurdering'],
        [3, 'Kilden nevner ikke styrets godkjenning av rapporten.', 'vurdert'],
    ]) {
        test(`a finding outside a source-based block keeps its own explanation in the behandlingsvisning (${index === 2 ? 'open' : 'decided'})`, async ({ page }) => {
            await page.setViewportSize({ width: 1366, height: 900 });
            const row = await openRunWithKeyboard(page);
            await row.locator('[data-findings-filter="all"]').click();

            const findingRow = row.locator(`[data-finding-row="claim-defect-${claimIds[index]}"]`);
            await expect(findingRow).not.toContainText(explanation);
            // Kjøringer and the article agree on the claim's state.
            await expect(findingRow).toContainText(noteState === 'vurdert' ? 'Godkjent' : 'Åpent for QA-vurdering');
            await findingRow.locator('a').click();

            await expect(page).toHaveURL(new RegExp(`claim_id=${claimIds[index]}`));

            // A decided claim is never called open in the article.
            const note = page.locator(`[data-testid="inline-qa-note"][data-claim-id="${claimIds[index]}"]`);
            await expect(note).toContainText(`– ${noteState}`);
            if (noteState === 'vurdert') {
                await expect(note).not.toContainText('krever vurdering');
            }
            await expect(page.getByText(explanation, { exact: true })).toHaveCount(0);

            await note.getByTestId('inline-qa-note-open').click();
            const card = page.locator(`article#claim-${claimIds[index]}`);
            await expect(card).toBeFocused();
            await expect(card.getByText(explanation, { exact: true })).toBeVisible();
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

    // ── The whole journey, Kjøringer → article → decision → Kjøringer ────────────
    // These change the fixture's claims, so they run last in this serial file.

    const toast = (page) => page.locator('#procynia-live-toast');
    const findingsRow = (page) => page.locator(`[data-run-item][data-run-id="${runId}"]`).first();

    async function openFindingCard(page, claimId) {
        const row = await openRunWithKeyboard(page);
        await row.locator('[data-findings-filter="all"]').click();
        await row.locator(`[data-finding-row="claim-defect-${claimId}"] a`).click();
        await expect(page).toHaveURL(new RegExp(`claim_id=${claimId}`));
        await page.locator(`[data-testid="inline-qa-note"][data-claim-id="${claimId}"]`).getByTestId('inline-qa-note-open').click();
        const card = page.locator(`article#claim-${claimId}`);
        await expect(card).toBeFocused();

        return card;
    }

    test('A. an open normative finding is corrected and approved, and Kjøringer shows it handled', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 900 });
        const card = await openFindingCard(page, claimIds[0]);

        const field = card.locator(`#claim-text-edit-${claimIds[0]}`);
        await field.fill('Gapene har ulike årsaker, og bør ikke slås sammen til ett tall.');
        await card.getByRole('button', { name: 'Godkjenn redigert tekst' }).click();

        await expect(toast(page)).toContainText('Teksten er lagret, og påstanden er godkjent. QA-funnet er ferdigbehandlet.');
        // The article now calls the finding handled, and the card offers only «Angre beslutning».
        const note = page.locator(`[data-testid="inline-qa-note"][data-claim-id="${claimIds[0]}"]`);
        await expect(note).toContainText('– vurdert');
        await expect(note.getByTestId('inline-qa-note-open')).toHaveText('Vis vurderingen');
        await expect(page.locator(`article#claim-${claimIds[0]}`).getByRole('button', { name: 'Behold teksten' })).toHaveCount(0);

        await page.getByRole('link', { name: 'Tilbake til funn' }).first().click();
        const target = findingsRow(page).locator(`[data-finding-row="claim-defect-${claimIds[0]}"]`);
        await expect(target).toBeFocused();
        await expect(target).toContainText('Redigert og godkjent');
        await expect(target).toContainText('Vis vurderingen');
        await expect(findingsRow(page).locator('[data-findings-filter="open"]')).toHaveAccessibleName('Åpne 3');
    });

    test('E. undoing the decision makes the finding open again in the article and in Kjøringer', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 900 });
        const card = await openFindingCard(page, claimIds[0]);

        await card.getByRole('button', { name: 'Angre beslutning' }).click();
        await expect(toast(page)).toContainText('Beslutningen er angret. Påstanden må vurderes på nytt. QA-funnet er åpent igjen.');
        await expect(page.locator(`[data-testid="inline-qa-note"][data-claim-id="${claimIds[0]}"]`)).toContainText('– krever vurdering');

        await page.getByRole('link', { name: 'Tilbake til funn' }).first().click();
        const target = findingsRow(page).locator(`[data-finding-row="claim-defect-${claimIds[0]}"]`);
        await expect(target).toContainText('Åpent for QA-vurdering');
        await expect(target).toContainText('Åpne og behandle');
        await expect(findingsRow(page).locator('[data-findings-filter="open"]')).toHaveAccessibleName('Åpne 4');
    });

    test('F. a failed save keeps the edited text, says so on the card and can be retried; leaving asks first', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 900 });
        const card = await openFindingCard(page, claimIds[1]);
        const edited = 'Gapene krever ulike tiltak, og de bør derfor ikke aggregeres til ett samlet tall.';

        await card.locator(`#claim-text-edit-${claimIds[1]}`).fill(edited);
        await page.route(`**/claims/${claimIds[1]}/approve`, (route) => route.fulfill({ status: 500, contentType: 'text/html', body: '<h1>Server Error</h1>' }));
        await card.getByRole('button', { name: 'Godkjenn redigert tekst' }).click();

        const error = card.getByTestId('claim-action-error');
        await expect(error).toHaveText('Beslutningen ble ikke lagret. Teksten og kommentaren din er beholdt, og du kan prøve igjen.');
        await expect(card.locator(`#claim-text-edit-${claimIds[1]}`)).toHaveValue(edited);
        await expect(toast(page)).not.toContainText('godkjent');
        await expect(card.getByRole('button', { name: 'Godkjenn redigert tekst' })).not.toHaveAttribute('aria-disabled', 'true');
        await expect(page.getByText('Server Error')).toHaveCount(0);

        // Unsaved text: following a link off the page asks first, and staying keeps the text.
        let asked = null;
        page.once('dialog', (dialog) => {
            asked = dialog.message();
            dialog.dismiss();
        });
        await page.getByRole('link', { name: 'Tilbake til funn' }).first().click();
        await expect.poll(() => asked).toBe('Du har tekst som ikke er lagret. Vil du forlate siden og forkaste endringene?');
        await expect(page).toHaveURL(new RegExp(`claim_id=${claimIds[1]}`));
        await expect(card.locator(`#claim-text-edit-${claimIds[1]}`)).toHaveValue(edited);

        await page.unroute(`**/claims/${claimIds[1]}/approve`);
        await card.getByRole('button', { name: 'Godkjenn redigert tekst' }).click();
        await expect(toast(page)).toContainText('Teksten er lagret, og påstanden er godkjent. QA-funnet er ferdigbehandlet.');
        await expect(page.locator(`article#claim-${claimIds[1]}`).getByTestId('claim-action-error')).toHaveCount(0);
    });

    for (const width of [390, 1366]) {
        test(`the handled card and its note fit at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            const card = await openFindingCard(page, claimIds[1]);

            await expect(card.getByRole('button', { name: 'Angre beslutning' })).toBeVisible();
            const overflows = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
            expect(overflows).toBe(false);

            if (SCREENSHOT_DIR) {
                await card.screenshot({ path: `${SCREENSHOT_DIR}/runs-finding-handled-card-${width}.png` });
            }
        });
    }
});

/**
 * Scenario H: the run escalated on a QA finding. Handling the last open one completes its QA
 * follow-up: the row moves from «Krever oppfølging» to «Oppfølging fullført», while the stored
 * status stays escalated — it records what happened during the run.
 */
test.describe.serial('Kjøringer: QA-oppfølging fullført på en eskalert kjøring', () => {
    let customerId;
    let runId;
    let claimId;

    test.beforeAll(async () => {
        customerId = await e2eWikiCustomerId();
        const { stdout } = await tinker(`echo json_encode(${FIXTURE}::seedFollowUp(${customerId}));`);
        ({ run_id: runId, claim_id: claimId } = JSON.parse(stdout.trim()));
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup(${customerId});`);
    });

    const runRow = (page) => page.locator(`[data-run-item][data-run-id="${runId}"]`).first();

    async function expectRunState(page, { statusLabel, findingStatus, openCount }) {
        const row = runRow(page);
        const mainRow = row.locator('[data-run-main-row]');
        await expect(mainRow).toContainText(statusLabel);
        // The badge says it; no second line repeats it or asks for more.
        await expect(mainRow.locator('[data-run-follow-up]')).toHaveCount(0);
        await expect(mainRow).not.toContainText(statusLabel === 'Krever oppfølging' ? 'Fullført' : 'Krever oppfølging');

        const toggle = mainRow.locator('[data-run-details-toggle]');
        if ((await toggle.getAttribute('aria-expanded')) !== 'true') {
            await toggle.click();
        }
        await row.locator('[data-findings-filter="all"]').click();
        await expect(row.locator(`[data-finding-row="claim-defect-${claimId}"]`)).toContainText(findingStatus);
        await expect(row.locator('[data-findings-filter="open"]')).toHaveAccessibleName(`Åpne ${openCount}`);
    }

    test('H. handling the last open finding completes the follow-up; the stored status stays, also after a reload', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 900 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');

        await expectRunState(page, { statusLabel: 'Krever oppfølging', findingStatus: 'Åpent for QA-vurdering', openCount: 1 });

        await runRow(page).locator(`[data-finding-row="claim-defect-${claimId}"] a`).click();
        await page.locator(`[data-testid="inline-qa-note"][data-claim-id="${claimId}"]`).getByTestId('inline-qa-note-open').click();
        const card = page.locator(`article#claim-${claimId}`);
        await expect(card).toBeFocused();
        await card.getByRole('button', { name: 'Behold teksten' }).click();
        await expect(page.locator('#procynia-live-toast')).toContainText('Påstanden er godkjent, og teksten er beholdt. QA-funnet er ferdigbehandlet.');

        await page.getByRole('link', { name: 'Tilbake til funn' }).first().click();
        await expectRunState(page, { statusLabel: 'Oppfølging fullført', findingStatus: 'Godkjent', openCount: 0 });
        await expect(runRow(page).locator('[data-run-detail-panel]')).toContainText('Alle QA-funnene er nå behandlet');

        await page.reload();
        await expectRunState(page, { statusLabel: 'Oppfølging fullført', findingStatus: 'Godkjent', openCount: 0 });

        // The run's recorded history is unchanged.
        const { stdout } = await tinker(`echo json_encode(\\App\\Models\\EnterpriseWikiIngestRun::find(${runId})->only(['status', 'qa_status', 'qa_result']));`);
        const recorded = JSON.parse(stdout.trim());
        expect([recorded.status, recorded.qa_status, recorded.qa_result.quality_findings]).toEqual(['escalated', 'escalated', ['normative_strengthening']]);
        expect(recorded.qa_result.checks.normative_fidelity.open_strengthened_claim_ids).toEqual([claimId]);
    });

    test('H. the completed follow-up fits a phone', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 900 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');

        const card = runRow(page).locator('[data-run-mobile-card]');
        await expect(card).toContainText('Oppfølging fullført');
        await expect(card.locator('[data-run-follow-up]')).toHaveCount(0);

        if (SCREENSHOT_DIR) {
            await card.screenshot({ path: `${SCREENSHOT_DIR}/run-status-completed-with-remarks-390.png` });
        }
        const overflows = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
        expect(overflows).toBe(false);
    });
});
