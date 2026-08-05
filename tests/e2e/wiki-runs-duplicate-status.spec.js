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
 * Verifies the owner-approval wait UI: for awaiting_document_owner_approval, only one main status
 * badge should appear, and it must read as a waiting state rather than active processing.
 * Reuses WikiRunsStalledIndicatorE2EFixture (same fixture as the stalled-indicator spec): it
 * already seeds one awaiting_document_owner_approval run and one genuinely active run side by
 * side, which is exactly what's needed to prove the waiting copy is isolated to the correct row.
 */
test.describe.serial('Kjøringer duplicate status badge removal', () => {
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

    test('1. awaiting_document_owner_approval shows only one highlighted status badge, not two', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`tr:has-text("${waitingRunId}")`).first();
        await expect(row).toBeVisible();

        await expect(row.getByText('Venter på dokumenteiergodkjenning', { exact: true })).toHaveCount(1);
        await expect(row.getByText('Avventer dokumenteiergodkjenning', { exact: true })).toHaveCount(0);
    });

    test('2. the main status badge (Venter på dokumenteiergodkjenning) is still shown', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`tr:has-text("${waitingRunId}")`).first();
        await expect(row.getByText('Venter på dokumenteiergodkjenning', { exact: true })).toBeVisible();
    });

    test('3. the explanation text says automatic processing is complete and the run is waiting', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`tr:has-text("${waitingRunId}")`).first();
        await expect(row.getByText('Automatisk behandling fullført', { exact: true })).toBeVisible();
        await expect(row.getByText('Automatisk behandling fullført', { exact: true })).toHaveAttribute(
            'title',
            'Runen fortsetter når alle nødvendige dokumenteiere har godkjent.',
        );
        const rowText = await row.evaluate((el) => el.textContent ?? '');
        expect(rowText).not.toContain('Runen fortsetter når alle nødvendige dokumenteiere har godkjent.');
        await expect(row.locator('ol')).toHaveCount(0);
        await expect(row.getByText(/Siste fremdrift/)).toHaveCount(0);
    });

    test('4. the document-owner approval step indicator is shown below the row', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`tr:has-text("${waitingRunId}")`).first();
        const progressRow = row.locator('xpath=following-sibling::tr[1]');
        await expect(progressRow).toBeVisible();
        await expect(progressRow.getByText('Dokumenteiergodkjenning', { exact: true })).toBeVisible();
    });

    test('4b. the redundant old waiting badge is not shown', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`tr:has-text("${waitingRunId}")`).first();
        await expect(row.getByText('Avventer dokumenteiergodkjenning', { exact: true })).toHaveCount(0);
        await expect(row.locator('[class*="animate-pulse"]')).toHaveCount(0);
    });

    test('5. an active run still shows a progress line, unlike the waiting run', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`tr:has-text("${activeRunId}")`).first();
        await expect(row).toBeVisible();
        await expect(row.getByText('Genererer sider', { exact: true })).toBeVisible();
        await expect(row.getByText(/Siste fremdrift/)).toBeVisible();
        await expect(row.getByText('Automatisk behandling fullført', { exact: true })).toHaveCount(0);
    });

    test('6. desktop layout renders both rows without console errors', async ({ page }) => {
        const errors = [];
        page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
        page.on('pageerror', (err) => errors.push(String(err)));

        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        await expect(page.locator(`tr:has-text("${waitingRunId}")`).first()).toBeVisible();
        await expect(page.locator(`tr:has-text("${activeRunId}")`).first()).toBeVisible();
        const bodyOverflows = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        );
        expect(bodyOverflows).toBe(false);
        expect(errors).toEqual([]);
    });

    test('7. 390px layout renders both rows without console errors or page-level horizontal overflow', async ({ page }) => {
        const errors = [];
        page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
        page.on('pageerror', (err) => errors.push(String(err)));

        await page.setViewportSize({ width: 390, height: 844 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        await expect(page.locator(`tr:has-text("${waitingRunId}")`).first()).toBeVisible();
        await expect(page.locator(`tr:has-text("${activeRunId}")`).first()).toBeVisible();

        const bodyOverflows = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        );
        expect(bodyOverflows).toBe(false);
        expect(errors).toEqual([]);
    });

    test('8. no overlapping text in the waiting run row at 390px', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`tr:has-text("${waitingRunId}")`).first();
        await expect(row).toBeVisible();

        const overlaps = await row.evaluate((rowEl) => {
            const textEls = Array.from(rowEl.querySelectorAll('span, p, td')).filter((el) => el.textContent.trim());
            const rects = textEls.map((el) => el.getBoundingClientRect()).filter((r) => r.width > 0 && r.height > 0);
            let overlapCount = 0;
            for (let i = 0; i < rects.length; i++) {
                for (let j = i + 1; j < rects.length; j++) {
                    const a = rects[i];
                    const b = rects[j];
                    const aContainsB = a.left <= b.left && a.right >= b.right && a.top <= b.top && a.bottom >= b.bottom;
                    const bContainsA = b.left <= a.left && b.right >= a.right && b.top <= a.top && b.bottom >= a.bottom;
                    if (aContainsB || bContainsA) continue;
                    const overlapX = Math.min(a.right, b.right) - Math.max(a.left, b.left);
                    const overlapY = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
                    if (overlapX > 2 && overlapY > 2) overlapCount++;
                }
            }
            return overlapCount;
        });

        expect(overlaps).toBe(0);
    });
});
