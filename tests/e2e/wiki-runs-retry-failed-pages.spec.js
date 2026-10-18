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

    function mainRow(page, runId) {
        return page.locator(`[data-run-item][data-run-id="${runId}"]`).first().locator('[data-run-main-row]');
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

        const row = mainRow(page, planDefectRunId);
        const block = row.locator('[data-run-failed-pages="1"]');

        await expect(block).toBeVisible();
        await expect(block.getByText('1 side(r) kunne ikke genereres og er ufullstendige.')).toBeVisible();
        await expect(block.getByText(/Feilen skyldes sideplanen eller kildegrunnlaget/)).toBeVisible();
        await expect(row.getByRole('button', { name: 'Prøv igjen for feilede sider' })).toHaveCount(0);
        expect(errors).toEqual([]);
    });

    test('a transient failure offers the retry; confirming twice sends one request and shows the result', async ({ page }) => {
        const errors = [];
        const requests = await interceptRetry(page, { success: SUCCESS });
        await openRuns(page, errors);

        const row = mainRow(page, transientRunId);
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

        await mainRow(page, transientRunId).getByRole('button', { name: 'Prøv igjen for feilede sider' }).click();
        const dialog = page.getByRole('dialog', { name: 'Prøv igjen for feilede sider?' });
        await dialog.getByRole('button', { name: 'Prøv igjen', exact: true }).click();

        await expect(page.getByRole('alert').filter({ hasText: NOT_ALLOWED })).toBeVisible();
        await expect(dialog).toBeHidden();
        await expect(page.getByTestId('flash-success')).toHaveCount(0);
        expect(requests).toEqual([String(transientRunId)]);
        expect(errors).toEqual([]);
    });
});
