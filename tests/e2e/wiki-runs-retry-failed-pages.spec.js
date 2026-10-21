import { test, expect } from '@playwright/test';
import { tinker } from './helpers/risk.js';
import { e2eWikiCustomerId, loginAsWikiReader } from './helpers/wiki.js';

const FIXTURE = '\\Tests\\Support\\WikiRunsRetryFailedPagesE2EFixture';

const SUCCESS = 'Sidene som feilet, er sendt til ny generering.';
const NOT_ALLOWED = 'Kjøringen kan ikke prøves på nytt akkurat nå.';

/**
 * «Prøv igjen for feilede sider» on the Kjøringer tab (arbeidspakke A4,
 * docs/enterprise-wiki-knowledge-integration-plan.md §4.2).
 *
 * Whether the retry is offered comes from the backend (failed_pages.can_retry) and is covered by
 * WikiRetryFailedPagesControllerTest, as are its authorization and refusals. What is checked here
 * is what only the browser shows: the block, the button only where it is offered, the confirmation,
 * the feedback after it, and that a repeated click sends one request.
 *
 * The retry request itself never reaches the server: a real one would queue page generation, which
 * is an AI call. The spec answers it with the real Kjøringer page, as Inertia receives it after the
 * redirect, carrying the flash the controller would have set.
 */
test.describe.serial('Kjøringer: Prøv igjen for feilede sider', () => {
    let customerId;
    let transientRunId;
    let planDefectRunId;

    test.beforeAll(async () => {
        customerId = await e2eWikiCustomerId();
        const { stdout } = await tinker(`echo json_encode(${FIXTURE}::seed(${customerId}));`);
        ({ transient_run_id: transientRunId, plan_defect_run_id: planDefectRunId } = JSON.parse(stdout.trim()));
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup(${customerId});`);
    });

    /**
     * Answers every retry-failed-pages request in the browser. Returns the list of runs a request
     * was made for, so a test can count them.
     */
    async function interceptRetry(page, flash) {
        const requests = [];

        await page.route('**/app/wiki/runs/*/retry-failed-pages', async (route) => {
            const request = route.request();
            requests.push(request.url().match(/runs\/(\d+)\//)[1]);

            // Long enough for a second click to land while the first request is in flight.
            await new Promise((resolve) => setTimeout(resolve, 1000));

            const headers = { ...request.headers() };
            delete headers['content-type'];
            delete headers['content-length'];

            const response = await route.fetch({ url: new URL('/app/wiki?tab=runs', request.url()).toString(), method: 'GET', headers });
            const inertiaPage = await response.json();
            inertiaPage.props.flash = { ...(inertiaPage.props.flash ?? {}), ...flash };

            await route.fulfill({ response, json: inertiaPage });
        });

        return requests;
    }

    /**
     * The collapsed row says «1 feil» under its status; the failed-pages explanation
     * and its retry live in the row's detail view, opened from «Vis detaljer» under Handlinger.
     */
    async function openDetails(page, runId) {
        const item = page.locator(`[data-run-item][data-run-id="${runId}"]`).first();
        const mainRow = item.locator('[data-run-main-row]');
        const toggle = mainRow.locator('[data-run-details-toggle]');

        await expect(mainRow.locator('[data-run-follow-up]')).toHaveText('1 feil');
        await expect(toggle).toHaveAccessibleName('Vis detaljer');
        await expect(toggle).toHaveAccessibleDescription(/E2E Retry Failed Pages/);
        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');

        return item.locator('[data-run-status-details]');
    }

    async function openRuns(page, errors) {
        page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
        page.on('pageerror', (err) => errors.push(String(err)));

        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');
    }

    test('a plan defect explains why, and offers no retry', async ({ page }) => {
        const errors = [];
        await openRuns(page, errors);

        const row = await openDetails(page, planDefectRunId);

        await expect(row.getByRole('heading', { level: 3 })).toHaveText('Én Wiki-side ble ikke laget eller oppdatert');
        await expect(row.getByText(/Feilen skyldes sideplanen eller kildegrunnlaget/)).toBeVisible();
        await expect(row.locator('[data-run-primary-action]')).toHaveCount(0);

        // One detail view: the progress and the pages are under «Vis flere detaljer», still with no
        // retry, and the row's Sider count closes the view it opened.
        const item = page.locator(`[data-run-item][data-run-id="${planDefectRunId}"]`).first();
        await item.getByRole('button', { name: 'Vis flere detaljer' }).click();
        await expect(item.locator('[data-run-failed-pages="1"]')).toBeVisible();
        await expect(item.getByRole('button', { name: 'Prøv igjen for feilede sider' })).toHaveCount(0);
        await expect(item.locator('[data-run-progress-row]')).toBeVisible();
        await expect(item.locator('[data-run-section="pages"]')).toHaveAttribute('aria-pressed', 'true');
        await expect(item.getByRole('heading', { name: 'Berørte Wiki-sider' })).toBeVisible();
        const pagesCount = item.locator('[data-run-main-row]').getByRole('button', { name: 'Vis 2 berørte Wiki-sider' });
        await pagesCount.click();
        await expect(item.locator('[data-run-detail-panel]')).toHaveCount(0);
        await pagesCount.click();
        await expect(item.locator('[data-run-progress-row]')).toBeVisible();
        expect(errors).toEqual([]);
    });

    test('«Vis detaljer» under Handlinger opens and closes the detail view, also from the keyboard', async ({ page }) => {
        const errors = [];
        await openRuns(page, errors);

        const item = page.locator(`[data-run-item][data-run-id="${transientRunId}"]`).first();
        const button = item.locator('[data-run-main-row]').getByRole('button', { name: 'Vis detaljer' });

        await expect(button).toHaveAttribute('aria-expanded', 'false');
        await expect(item.locator('[data-run-detail-panel]')).toHaveCount(0);

        await button.click();
        const hide = item.locator('[data-run-main-row]').getByRole('button', { name: 'Skjul detaljer' });
        await expect(hide).toHaveAttribute('aria-expanded', 'true');
        await expect(item.locator('[data-run-status-details]')).toBeVisible();
        // The retry is the one main action; the progress waits under «Vis flere detaljer».
        await expect(item.locator('[data-run-primary-action]')).toHaveText('Prøv igjen for feilede sider');
        await expect(item.locator('[data-run-progress-row]')).toHaveCount(0);

        await hide.focus();
        await page.keyboard.press('Enter');
        await expect(item.locator('[data-run-detail-panel]')).toHaveCount(0);
        await expect(button).toBeFocused();
        expect(errors).toEqual([]);
    });

    test('a transient failure offers the retry; confirming twice sends one request and shows the result', async ({ page }) => {
        const errors = [];
        const requests = await interceptRetry(page, { success: SUCCESS });
        await openRuns(page, errors);

        const row = await openDetails(page, transientRunId);
        await expect(row.getByText('Feilen ser ut til å være midlertidig.')).toBeVisible();

        const retry = row.getByRole('button', { name: 'Prøv igjen for feilede sider' });
        await expect(retry).toBeVisible();
        await retry.click();

        const dialog = page.getByRole('dialog', { name: 'Prøv igjen for feilede sider?' });
        await expect(dialog).toBeVisible();
        await expect(dialog.getByText(/Bare sidene som feilet, genereres på nytt/)).toBeVisible();

        const confirm = dialog.getByRole('button', { name: 'Prøv igjen', exact: true });
        await confirm.dblclick();
        await expect(confirm).toHaveAttribute('aria-disabled', 'true');

        await expect(page.getByTestId('flash-success')).toHaveText(SUCCESS);
        await expect(dialog).toBeHidden();
        expect(requests).toEqual([String(transientRunId)]);
        expect(errors).toEqual([]);
    });

    test('a refusal from the backend is shown as an error, and the dialog closes', async ({ page }) => {
        const errors = [];
        const requests = await interceptRetry(page, { error: NOT_ALLOWED });
        await openRuns(page, errors);

        await (await openDetails(page, transientRunId)).getByRole('button', { name: 'Prøv igjen for feilede sider' }).click();
        const dialog = page.getByRole('dialog', { name: 'Prøv igjen for feilede sider?' });
        await dialog.getByRole('button', { name: 'Prøv igjen', exact: true }).click();

        await expect(page.getByRole('alert').filter({ hasText: NOT_ALLOWED })).toBeVisible();
        await expect(dialog).toBeHidden();
        await expect(page.getByTestId('flash-success')).toHaveCount(0);
        expect(requests).toEqual([String(transientRunId)]);
        expect(errors).toEqual([]);
    });
});
