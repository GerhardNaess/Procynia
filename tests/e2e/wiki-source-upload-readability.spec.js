import { test, expect } from '@playwright/test';
import { exec } from 'node:child_process';
import { promisify } from 'node:util';

const execAsync = promisify(exec);
const FIXTURE = '\\Tests\\Support\\WikiSourceUploadReadabilityE2EFixture';
const CUSTOMER_ID = 4;
const MIN_READABLE_PX = 16;

async function loginAsDevDataUser(page) {
    await page.goto('/login');
    await page.fill('#email', 'alisan@advania.no');
    await page.fill('#password', 'Opaque01');
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 });
}

async function fontSizePx(locator) {
    return locator.evaluate((el) => parseFloat(window.getComputedStyle(el).fontSize));
}

/**
 * Verifies "Rett lesbarhet, språk og handlingsstyrke i Enterprise Wiki → Kildedokumenter": the
 * native browser file input (with its English "Choose File"/"no file selected" strings) is
 * replaced by a hidden real input plus a styled, localized violet button and a separate filename
 * display, and every readable text in the upload area is at least 16px.
 */
test.describe.serial('Wiki source upload readability', () => {
    test.afterAll(async () => {
        await execAsync(
            `docker compose exec -T app php artisan tinker --execute="${FIXTURE}::cleanup(${CUSTOMER_ID});"`,
            { cwd: new URL('../..', import.meta.url).pathname },
        );
    });

    test('1&2&3. no native English file-input text; localized Velg fil button and Ingen fil valgt shown', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=sources');

        await expect(page.getByRole('button', { name: 'Choose File', exact: false })).toHaveCount(0);
        await expect(page.getByText('no file selected', { exact: false })).toHaveCount(0);
        await expect(page.getByText('No file selected', { exact: true })).toHaveCount(0);

        const chooseButton = page.locator('label[for="wiki-source-file"]');
        await expect(chooseButton).toHaveText('Velg fil');
        await expect(page.getByText('Ingen fil valgt', { exact: true })).toBeVisible();
    });

    test('4&6. selecting a file shows its filename and reaches the real hidden input', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=sources');

        const fileInput = page.locator('#wiki-source-file');
        await expect(fileInput).toHaveAttribute('type', 'file');

        await fileInput.setInputFiles({
            name: 'e2e-source-upload-readability.pdf',
            mimeType: 'application/pdf',
            buffer: Buffer.from('%PDF-1.4 e2e readability check content'),
        });

        await expect(page.getByText('e2e-source-upload-readability.pdf', { exact: true })).toBeVisible();
        await expect(page.getByText('Ingen fil valgt', { exact: true })).toHaveCount(0);

        const uploadedCount = await fileInput.evaluate((el) => el.files.length);
        expect(uploadedCount).toBe(1);
    });

    test('5. clicking Velg fil opens the native file chooser', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=sources');

        const [chooser] = await Promise.all([
            page.waitForEvent('filechooser'),
            page.locator('label[for="wiki-source-file"]').click(),
        ]);

        expect(chooser.element()).not.toBeNull();
        expect(await chooser.isMultiple()).toBe(false);
    });

    test('6&7. upload submits through the real input and document appears in the list', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=sources');

        await page.locator('#wiki-source-file').setInputFiles({
            name: 'e2e-source-upload-readability.pdf',
            mimeType: 'application/pdf',
            buffer: Buffer.from('%PDF-1.4 e2e readability check content'),
        });

        await page.getByRole('button', { name: 'Last opp kilde' }).click();
        await page.waitForURL((url) => url.searchParams.get('tab') === 'sources');

        await expect(page.getByText('e2e-source-upload-readability.pdf', { exact: true })).toBeVisible();
    });

    test('8. every readable text in the upload area is at least 16px', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=sources');

        const chooseButton = page.locator('label[for="wiki-source-file"]');
        const noFileText = page.getByText('Ingen fil valgt', { exact: true });
        const hint = page.getByText('PDF eller DOCX', { exact: false });
        const ownerLabel = page.getByText('Dokumenteier', { exact: true }).first();
        const uploadButton = page.getByRole('button', { name: 'Last opp kilde' });

        for (const locator of [chooseButton, noFileText, hint, ownerLabel, uploadButton]) {
            await expect(locator).toBeVisible();
            expect(await fontSizePx(locator)).toBeGreaterThanOrEqual(MIN_READABLE_PX);
        }
    });

    test('9. Velg fil uses the violet primary action style', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=sources');

        const readStyles = (el) => {
            const s = window.getComputedStyle(el);
            return { background: s.backgroundColor, color: s.color };
        };

        const chooseButton = page.locator('label[for="wiki-source-file"]');
        const uploadButton = page.getByRole('button', { name: 'Last opp kilde' });
        const chooseStyles = await chooseButton.evaluate(readStyles);
        const uploadStyles = await uploadButton.evaluate(readStyles);

        // Same violet primary palette as the upload submit button (Tailwind's bg-violet-600 /
        // white text) — compared against that button rather than a hardcoded color string, since
        // Tailwind 4 emits oklch() rather than rgb().
        expect(chooseStyles.background).toBe(uploadStyles.background);
        expect(chooseStyles.color).toBe(uploadStyles.color);
        expect(chooseStyles.color).not.toBe(chooseStyles.background);
    });

    test('10. focus-visible is shown when the hidden input is focused via keyboard', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=sources');

        await page.locator('#wiki-source-file').focus();

        const chooseButton = page.locator('label[for="wiki-source-file"]');
        const outlineWidth = await chooseButton.evaluate((el) => window.getComputedStyle(el).outlineWidth);
        expect(outlineWidth).not.toBe('0px');
    });

    test('11&12&13. desktop and 390px render the upload area with no console errors or overflow', async ({ page }) => {
        const errors = [];
        page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
        page.on('pageerror', (err) => errors.push(String(err)));

        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=sources');
        await expect(page.locator('label[for="wiki-source-file"]')).toBeVisible();

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/app/wiki?tab=sources');
        await expect(page.locator('label[for="wiki-source-file"]')).toBeVisible();
        await expect(page.getByText('Ingen fil valgt', { exact: true })).toBeVisible();

        const bodyOverflows = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        );
        expect(bodyOverflows).toBe(false);
        expect(errors).toEqual([]);
    });
});
