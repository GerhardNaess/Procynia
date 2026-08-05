import { test, expect } from '@playwright/test';
import { exec } from 'node:child_process';
import { promisify } from 'node:util';

const execAsync = promisify(exec);
const FIXTURE = '\\Tests\\Support\\WikiRunsStalledIndicatorE2EFixture';
const CUSTOMER_ID = 4;

async function loginAsDevDataUser(page) {
    await page.goto('/login');
    await page.fill('#email', 'alisan@advania.no');
    await page.fill('#password', 'Opaque01');
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 });
}

/**
 * Verifies the "Ser ut til å stå stille" fix (CLAUDE.md task "Hide stalled warning for
 * human-waiting Wiki runs"): the warning must require BOTH an actively-processing status AND a
 * long idle gap — never elapsed time alone. Both seeded runs share the same 40-minute-old
 * last-activity timestamp; only their status differs.
 */
test.describe.serial('Kjøringer stalled-indicator gating', () => {
    let activeRunId;
    let waitingRunId;

    test.beforeAll(async () => {
        const { stdout } = await execAsync(
            `docker compose exec -T app php artisan tinker --execute="echo json_encode(${FIXTURE}::seed(${CUSTOMER_ID}));"`,
            { cwd: new URL('../..', import.meta.url).pathname },
        );
        const ids = JSON.parse(stdout.trim());
        activeRunId = ids.active_run_id;
        waitingRunId = ids.waiting_run_id;
    });

    test.afterAll(async () => {
        await execAsync(
            `docker compose exec -T app php artisan tinker --execute="${FIXTURE}::cleanup(${CUSTOMER_ID});"`,
            { cwd: new URL('../..', import.meta.url).pathname },
        );
    });

    test('a genuinely idle active run (generating_pages) still shows the stalled warning', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`[data-run-item][data-run-id="${activeRunId}"]`).first();
        const mainRow = row.locator('[data-run-main-row]');
        await expect(row).toBeVisible();
        await expect(mainRow.getByText('Ser ut til å stå stille', { exact: true })).toBeVisible();
        await expect(mainRow.getByText(/Ingen registrert fremdrift siden/)).toBeVisible();
    });

    test('an idle awaiting_document_owner_approval run never shows the stalled warning', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`[data-run-item][data-run-id="${waitingRunId}"]`).first();
        const mainRow = row.locator('[data-run-main-row]');
        await expect(row).toBeVisible();
        await expect(mainRow.getByText('Ser ut til å stå stille', { exact: true })).toHaveCount(0);
        await expect(mainRow.getByText(/Ingen registrert fremdrift siden/)).toHaveCount(0);
    });

    test('the waiting run shows normal owner-approval wait copy instead, without the redundant secondary badge', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`[data-run-item][data-run-id="${waitingRunId}"]`).first();
        const mainRow = row.locator('[data-run-main-row]');
        await expect(row).toBeVisible();
        await expect(mainRow.getByText('Venter på dokumenteiergodkjenning', { exact: true })).toBeVisible();
        await expect(mainRow.getByText('Automatisk behandling fullført', { exact: true })).toBeVisible();
        await expect(mainRow.getByText('Automatisk behandling fullført', { exact: true })).toHaveAttribute(
            'title',
            'Runen fortsetter når alle nødvendige dokumenteiere har godkjent.',
        );
        const rowText = await row.evaluate((el) => el.textContent ?? '');
        expect(rowText).not.toContain('Runen fortsetter når alle nødvendige dokumenteiere har godkjent.');
        const progressRow = row.locator('[data-run-progress-row]');
        await expect(progressRow).toBeVisible();
        await expect(progressRow.locator('[data-progress-step]')).toHaveCount(7);
        await expect(progressRow.locator('[data-progress-connector]')).toHaveCount(6);
        await expect(progressRow.locator('[data-progress-state="waiting"]')).toHaveCount(1);
        await expect(progressRow.getByText('Dokumenteiergodkjenning', { exact: true })).toBeVisible();
        await expect(mainRow.getByText(/Siste fremdrift/)).toHaveCount(0);
    });

    test('the waiting run still shows no cancel action (previous fix stays intact)', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`[data-run-item][data-run-id="${waitingRunId}"]`).first();
        await expect(row.locator('[data-run-main-row]').getByRole('button', { name: 'Avbryt kjøring' })).toHaveCount(0);
    });

    test('no console errors on desktop', async ({ page }) => {
        const errors = [];
        page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
        page.on('pageerror', (err) => errors.push(String(err)));

        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');
        await expect(page.locator(`[data-run-item][data-run-id="${waitingRunId}"]`).first()).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('390px: waiting run still shows normal wait copy, no stalled warning, no console errors, no overflow', async ({ page }) => {
        const errors = [];
        page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
        page.on('pageerror', (err) => errors.push(String(err)));

        await page.setViewportSize({ width: 390, height: 844 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`[data-run-item][data-run-id="${waitingRunId}"]`).first();
        const mobileCard = row.locator('[data-run-mobile-card]');
        await expect(row).toBeVisible();
        await expect(mobileCard.getByText('Ser ut til å stå stille', { exact: true })).toHaveCount(0);
        await expect(mobileCard.getByText('Venter på dokumenteiergodkjenning', { exact: true })).toBeVisible();
        await expect(mobileCard.getByText('Avventer dokumenteiergodkjenning', { exact: true })).toHaveCount(0);

        const bodyOverflows = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        );
        expect(bodyOverflows).toBe(false);
        expect(errors).toEqual([]);
    });
});
