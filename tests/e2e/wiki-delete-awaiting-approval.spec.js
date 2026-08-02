import { test, expect } from '@playwright/test';
import { exec } from 'node:child_process';
import { promisify } from 'node:util';

const execAsync = promisify(exec);
const FIXTURE = '\\Tests\\Support\\WikiDeleteAwaitingApprovalE2EFixture';
const CUSTOMER_ID = 4;

async function loginAsDevDataUser(page) {
    await page.goto('/login');
    await page.fill('#email', 'alisan@advania.no');
    await page.fill('#password', 'Opaque01');
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 });
}

/**
 * Verifies "Rett sletting av kildedokumenter som venter på dokumenteiergodkjenning": a document
 * whose only ingest run is awaiting_document_owner_approval must have an ACTIVE Delete button (no
 * "Dokumentet har en aktiv kjøring" tooltip), and confirming deletion must end the run and remove
 * the document in one step — no prior trip to the Kjøringer tab required. A document with a
 * genuinely active run (generating_pages) must keep the previous, disabled-button behavior.
 */
test.describe.serial('Kildedokumenter delete button for awaiting_document_owner_approval', () => {
    test.beforeAll(async () => {
        await execAsync(
            `docker compose exec -T app php artisan tinker --execute="${FIXTURE}::seed(${CUSTOMER_ID});"`,
            { cwd: new URL('../..', import.meta.url).pathname },
        );
    });

    test.afterAll(async () => {
        await execAsync(
            `docker compose exec -T app php artisan tinker --execute="${FIXTURE}::cleanup(${CUSTOMER_ID});"`,
            { cwd: new URL('../..', import.meta.url).pathname },
        );
    });

    test('1&2. the Delete button is active (not disabled) for awaiting_document_owner_approval, with no active-run tooltip', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=sources');

        const row = page.locator('tr', { has: page.getByText('E2E Delete Awaiting Approval Check.docx', { exact: true }) });
        await expect(row).toBeVisible();

        const deleteButton = row.getByRole('button', { name: 'Slett' });
        await expect(deleteButton).toBeEnabled();
        await expect(deleteButton).not.toHaveAttribute('title', 'Dokumentet har en aktiv kjøring');
    });

    test('9. the Delete button stays disabled with the active-run tooltip for a genuinely active run', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=sources');

        const row = page.locator('tr', { has: page.getByText('E2E Delete Active Run Check.docx', { exact: true }) });
        await expect(row).toBeVisible();

        const deleteButton = row.getByRole('button', { name: 'Slett' });
        await expect(deleteButton).toBeDisabled();
        await expect(deleteButton).toHaveAttribute('title', 'Dokumentet har en aktiv kjøring');
    });

    test('3. clicking Delete opens the dialog with the approval-flow explanation and precise confirm text', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=sources');

        const row = page.locator('tr', { has: page.getByText('E2E Delete Awaiting Approval Check.docx', { exact: true }) });
        await row.getByRole('button', { name: 'Slett' }).click();

        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await expect(dialog.getByText('Dokumentet har en åpen godkjenningsflyt som venter på dokumenteiergodkjenning.', { exact: false })).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Avbryt godkjenningsflyt og slett dokument' })).toBeVisible();

        // Close without confirming — this run/document is reused by the next test.
        await dialog.getByRole('button', { name: 'Avbryt', exact: true }).click();
        await expect(dialog).toBeHidden();
    });

    test('1&4. confirming deletion ends the run and deletes the document in one step', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=sources');

        const row = page.locator('tr', { has: page.getByText('E2E Delete Awaiting Approval Check.docx', { exact: true }) });
        await row.getByRole('button', { name: 'Slett' }).click();

        const dialog = page.getByRole('dialog');
        await dialog.getByRole('button', { name: 'Avbryt godkjenningsflyt og slett dokument' }).click();

        // A single explicit reload of the Kildedokumenter tab, rather than racing Inertia's own
        // client-side redirect (which lands on the default tab, not "sources") with a second
        // page.goto — two navigations back-to-back can abort/interleave with the in-flight visit.
        await page.waitForLoadState('networkidle');
        await page.goto('/app/wiki?tab=sources', { waitUntil: 'load' });
        await expect(page.getByText('E2E Delete Awaiting Approval Check.docx', { exact: true })).toHaveCount(0);
    });

    test('6&7&8. desktop and 390px render both rows with no console errors or overlap', async ({ page }) => {
        const errors = [];
        page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
        page.on('pageerror', (err) => errors.push(String(err)));

        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=sources');
        await expect(page.getByText('E2E Delete Active Run Check.docx', { exact: true })).toBeVisible();

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/app/wiki?tab=sources');
        await expect(page.getByText('E2E Delete Active Run Check.docx', { exact: true })).toBeVisible();

        const bodyOverflows = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        );
        expect(bodyOverflows).toBe(false);
        expect(errors).toEqual([]);
    });
});
