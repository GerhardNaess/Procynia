import { test, expect } from '@playwright/test';
import { tinker } from './helpers/risk.js';
import { e2eWikiCustomerId, loginAsWikiReader } from './helpers/wiki.js';

const FIXTURE = '\\Tests\\Support\\WikiRunsNextStepE2EFixture';
const SUCCESS = 'Sidene som feilet, er sendt til ny generering.';

/**
 * The top of a run's detail view: what happened, «Hva bør du gjøre?» with at most one main action,
 * what is already done — and everything else under «Vis flere detaljer».
 *
 * Which action is offered comes from the backend (WikiRetryFailedPagesControllerTest); the words
 * from getRunNextStep() (runFindingsLogic.test.js). What is checked here is what only the browser
 * shows: the order, the one main action, the way to the article and back, the keyboard, and the
 * layout at 1366 and 390 px.
 *
 * The one retry request is answered in the browser: a real one would queue page generation, which
 * is an AI call.
 */
test.describe.serial('Kjøringer: hva bør du gjøre?', () => {
    let customerId;
    let ids;

    test.beforeAll(async () => {
        customerId = await e2eWikiCustomerId();
        const { stdout } = await tinker(`echo json_encode(${FIXTURE}::seed(${customerId}));`);
        ids = JSON.parse(stdout.trim());
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup(${customerId});`);
    });

    async function openRuns(page, errors, width = 1366) {
        page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
        page.on('pageerror', (err) => errors.push(String(err)));

        await page.setViewportSize({ width, height: 900 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');
    }

    function runItem(page, runId) {
        return page.locator(`[data-run-item][data-run-id="${runId}"]`).first();
    }

    async function openDetails(page, runId) {
        const item = runItem(page, runId);
        const toggle = item.locator('[data-run-main-row], [data-run-mobile-card]').locator('visible=true').locator('[data-run-details-toggle]').first();
        await toggle.click();
        await expect(item.locator('[data-run-detail-panel]')).toBeVisible();

        return item;
    }

    async function noHorizontalScroll(page) {
        const { scrollWidth, clientWidth } = await page.evaluate(() => ({
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
        }));
        expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
    }

    test('#963: the article in review is the task — one link to it, no retry, findings already done', async ({ page }) => {
        const errors = [];
        await openRuns(page, errors);

        const item = runItem(page, ids.review_run_id);
        await expect(item.locator('[data-run-main-row] [data-run-follow-up]')).toHaveText('Venter på gjennomgang');

        await openDetails(page, ids.review_run_id);
        const status = item.locator('[data-run-status-details]');

        await expect(status.getByRole('heading', { level: 3 })).toHaveText('Én Wiki-artikkel venter på gjennomgang');
        await expect(status.getByText('Procynia kunne ikke oppdatere «E2E Incident Commander» fordi artikkelen er til gjennomgang.')).toBeVisible();
        await expect(status.getByRole('heading', { name: 'Hva bør du gjøre?' })).toBeVisible();
        await expect(status.locator('[data-run-primary-action]')).toHaveCount(1);
        await expect(status.getByRole('link', { name: 'Åpne «E2E Incident Commander»' })).toBeVisible();
        await expect(status.getByText('Oppdateringen kan prøves igjen når gjennomgangen er avsluttet.')).toBeVisible();
        await expect(status.locator('[data-run-done]')).toHaveText('Begge kvalitetsfunnene er ferdigbehandlet.');
        await expect(status.getByText(/midlertidig|ufullstendig/)).toHaveCount(0);

        // Details are there, but closed; no retry anywhere while the review is open.
        const more = item.getByRole('button', { name: 'Vis flere detaljer' });
        await expect(more).toHaveAttribute('aria-expanded', 'false');
        await expect(item.locator('[data-run-progress-row]')).toHaveCount(0);
        await expect(item.getByRole('button', { name: /Prøv/ })).toHaveCount(0);

        // From the keyboard: open, everything that used to be shown is reachable, close again.
        await more.focus();
        await page.keyboard.press('Enter');
        await expect(item.getByRole('button', { name: 'Skjul flere detaljer' })).toHaveAttribute('aria-expanded', 'true');
        await expect(item.locator('[data-run-progress-row]')).toBeVisible();
        await expect(item.locator('[data-run-section="pages"]')).toBeVisible();
        await expect(item.locator('[data-run-section="findings"]')).toBeVisible();
        await expect(item.getByRole('button', { name: 'Vis tekniske detaljer' })).toBeVisible();
        await expect(item.getByRole('button', { name: /Prøv/ })).toHaveCount(0);
        await expect(item.locator('[data-run-dismiss]')).toHaveCount(0);

        // The findings list opens on «Alle» here: none is open, and an empty «Åpne» list reads as
        // "nothing found".
        await item.locator('[data-run-section="findings"]').click();
        await expect(item.locator('[data-findings-filter="all"]')).toHaveAttribute('aria-pressed', 'true');

        await item.getByRole('button', { name: 'Skjul flere detaljer' }).focus();
        await page.keyboard.press('Enter');
        await expect(item.locator('[data-run-more-details-panel]')).toHaveCount(0);
        await expect(item.getByRole('button', { name: 'Vis flere detaljer' })).toBeFocused();

        await noHorizontalScroll(page);
        expect(errors).toEqual([]);
    });

    test('the article opens from the run, and its back link returns to the same run, read fresh', async ({ page }) => {
        const errors = [];
        await openRuns(page, errors);

        const item = await openDetails(page, ids.review_run_id);
        await item.getByRole('link', { name: 'Åpne «E2E Incident Commander»' }).click();

        await expect(page).toHaveURL(new RegExp(`/app/wiki/${ids.review_page_slug}\\?back_url=`));
        await expect(page.getByRole('heading', { level: 1, name: 'E2E Incident Commander' })).toBeVisible();
        const back = page.getByRole('link', { name: 'Tilbake til Kjøringer' });
        await expect(back).toBeVisible();

        // Meanwhile the reviewer closes the review elsewhere.
        await tinker(`${FIXTURE}::setReviewOpen(${customerId}, false);`);
        await back.click();

        await expect(page).toHaveURL(new RegExp(`/app/wiki\\?(?=.*tab=runs)(?=.*focus_run=${ids.review_run_id})`));
        const returned = runItem(page, ids.review_run_id);
        const heading = returned.locator('[data-run-status-details]').getByRole('heading', { level: 3 });
        await expect(heading).toHaveText('Oppdateringen kan prøves igjen');
        await expect(heading).toBeFocused();
        await expect(returned.locator('[data-run-primary-action]')).toHaveText('Prøv oppdateringen igjen');

        await tinker(`${FIXTURE}::setReviewOpen(${customerId}, true);`);
        expect(errors).toEqual([]);
    });

    test('once the review is closed, «Prøv oppdateringen igjen» is the main action and sends one request', async ({ page }) => {
        await tinker(`${FIXTURE}::setReviewOpen(${customerId}, false);`);
        const errors = [];
        const requests = [];

        await page.route('**/app/wiki/runs/*/retry-failed-pages', async (route) => {
            const request = route.request();
            requests.push(request.url().match(/runs\/(\d+)\//)[1]);
            const headers = { ...request.headers() };
            delete headers['content-type'];
            delete headers['content-length'];
            const response = await route.fetch({ url: new URL('/app/wiki?tab=runs', request.url()).toString(), method: 'GET', headers });
            const inertiaPage = await response.json();
            inertiaPage.props.flash = { ...(inertiaPage.props.flash ?? {}), success: SUCCESS };
            await route.fulfill({ response, json: inertiaPage });
        });

        await openRuns(page, errors);
        const item = await openDetails(page, ids.review_run_id);
        const status = item.locator('[data-run-status-details]');

        await expect(status.locator('[data-run-primary-action]')).toHaveCount(1);
        await status.getByRole('button', { name: 'Prøv oppdateringen igjen' }).click();

        const dialog = page.getByRole('dialog', { name: 'Prøv igjen for feilede sider?' });
        await dialog.getByRole('button', { name: 'Prøv igjen', exact: true }).click();

        await expect(page.getByTestId('flash-success')).toHaveText(SUCCESS);
        expect(requests).toEqual([String(ids.review_run_id)]);

        await tinker(`${FIXTURE}::setReviewOpen(${customerId}, true);`);
        expect(errors).toEqual([]);
    });

    test('open findings come first: «Behandle kvalitetsfunn» opens them on «Åpne», and the failed page is still named', async ({ page }) => {
        const errors = [];
        await openRuns(page, errors);

        const item = await openDetails(page, ids.findings_run_id);
        const status = item.locator('[data-run-status-details]');

        await expect(status.getByRole('heading', { level: 3 })).toHaveText('2 kvalitetsfunn trenger vurdering');
        await expect(status.getByText(/I tillegg ble én Wiki-side ikke laget eller oppdatert/)).toBeVisible();
        await expect(status.locator('[data-run-primary-action]')).toHaveCount(1);

        await status.getByRole('button', { name: 'Behandle kvalitetsfunn' }).click();

        await expect(item.locator('[data-findings-filter="open"]')).toHaveAttribute('aria-pressed', 'true');
        await expect(item.locator('#run-panel-' + ids.findings_run_id + '-sections')).toBeFocused();
        // The failed page's own retry stays one step away, as a secondary action.
        await expect(item.locator('[data-run-other-actions]').getByRole('button', { name: 'Prøv igjen for feilede sider' })).toBeVisible();
        expect(errors).toEqual([]);
    });

    test('a completed run asks nothing; «Fjern fra oversikten» is under «Vis flere detaljer»', async ({ page }) => {
        const errors = [];
        await openRuns(page, errors);

        const item = await openDetails(page, ids.completed_run_id);
        const status = item.locator('[data-run-status-details]');

        await expect(status.getByRole('heading', { level: 3 })).toHaveText('Kjøringen er fullført');
        await expect(status.getByText('Ingen handling nødvendig.')).toBeVisible();
        await expect(status.locator('[data-run-primary-action]')).toHaveCount(0);
        await expect(status.getByRole('heading', { name: 'Hva bør du gjøre?' })).toHaveCount(0);

        await item.getByRole('button', { name: 'Vis flere detaljer' }).click();
        await expect(item.locator('[data-run-dismiss]')).toBeVisible();
        expect(errors).toEqual([]);
    });

    test('#963 now: nothing is left — no failure, no retry, the drafts are ordinary Wiki work', async ({ page }) => {
        const errors = [];
        await openRuns(page, errors);

        const item = runItem(page, ids.settled_run_id);
        const mainRow = item.locator('[data-run-main-row]');
        await expect(mainRow.getByText('Fullført', { exact: true })).toBeVisible();
        await expect(mainRow).not.toContainText('Krever oppfølging');
        await expect(mainRow.locator('[data-run-follow-up]')).toHaveCount(0);

        await openDetails(page, ids.settled_run_id);
        const status = item.locator('[data-run-status-details]');
        await expect(status.getByRole('heading', { level: 3 })).toHaveText('Oppfølgingen er fullført');
        await expect(status.getByText('Kjøringen opprettet 2 nye Wiki-artikler. De ligger som utkast og kan sendes til gjennomgang når de er klare.', { exact: false })).toBeVisible();
        await expect(status.locator('[data-run-primary-action]')).toHaveCount(0);
        await expect(item.getByRole('button', { name: /Prøv/ })).toHaveCount(0);
        await expect(item.getByText(/må oppdateres|Kunne ikke generere/)).toHaveCount(0);

        // History is still there, under «Vis flere detaljer».
        await item.getByRole('button', { name: 'Vis flere detaljer' }).click();
        await expect(item.locator('[data-run-progress-row]')).toBeVisible();
        await expect(item.getByRole('button', { name: 'Vis tekniske detaljer' })).toBeVisible();
        await expect(item.locator('[data-run-dismiss]')).toBeVisible();
        await expect(item.getByRole('button', { name: /Prøv/ })).toHaveCount(0);

        // Each page says what the run did and where the article stands now — no counter, no
        // Dokumenteier status, no «Åpne og behandle» leading nowhere.
        await item.locator('[data-run-section="pages"]').click();
        const rows = item.getByTestId('run-page-row');
        await expect(rows).toHaveCount(3);
        const byTitle = (title) => rows.filter({ hasText: title });
        await expect(byTitle('E2E Ny artikkel').getByTestId('run-page-result')).toHaveText('Opprettet');
        await expect(byTitle('E2E Ny artikkel')).toContainText('Utkast');
        await expect(byTitle('E2E Nytt sammendrag').getByTestId('run-page-result')).toHaveText('Opprettet');
        await expect(byTitle('E2E Referert side').getByTestId('run-page-result')).toHaveText('Ingen endring nødvendig');
        await expect(byTitle('E2E Referert side')).toContainText('Publisert');
        await expect(item.getByText(/ferdigbehandlet|Dokumenteier|Avventer|Åpne og behandle/)).toHaveCount(0);
        await expect(item.getByRole('link', { name: /Åpne side/ })).toHaveCount(3);

        await noHorizontalScroll(page);
        expect(errors).toEqual([]);
    });

    for (const width of [1366, 390]) {
        test(`#963 now reads cleanly at ${width} px`, async ({ page }, testInfo) => {
            const errors = [];
            await openRuns(page, errors, width);

            const item = await openDetails(page, ids.settled_run_id);
            await noHorizontalScroll(page);
            await item.screenshot({ path: testInfo.outputPath(`run-963-settled-${width}.png`) });

            await item.getByRole('button', { name: 'Vis flere detaljer' }).click();
            await item.locator('[data-run-section="pages"]').click();
            await expect(item.getByTestId('run-page-row')).toHaveCount(3);
            await noHorizontalScroll(page);
            await item.screenshot({ path: testInfo.outputPath(`run-963-settled-${width}-pages.png`) });
            expect(errors).toEqual([]);
        });
    }

    for (const width of [1366, 390]) {
        test(`#963 reads cleanly at ${width} px`, async ({ page }, testInfo) => {
            const errors = [];
            await openRuns(page, errors, width);

            const item = await openDetails(page, ids.review_run_id);
            await expect(item.locator('[data-run-primary-action]')).toBeVisible();
            await noHorizontalScroll(page);
            await item.screenshot({ path: testInfo.outputPath(`run-963-${width}.png`) });

            await item.getByRole('button', { name: 'Vis flere detaljer' }).click();
            await expect(item.locator('[data-run-progress-row]')).toBeVisible();
            await noHorizontalScroll(page);
            await item.screenshot({ path: testInfo.outputPath(`run-963-${width}-details.png`) });
            expect(errors).toEqual([]);
        });
    }
});
