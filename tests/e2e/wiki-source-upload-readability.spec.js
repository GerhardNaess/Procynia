import { test, expect } from '@playwright/test';
import { tinker } from './helpers/risk.js';
import { e2eWikiCustomerId, loginAsWikiReader } from './helpers/wiki.js';

const FIXTURE = '\\Tests\\Support\\WikiSourceUploadReadabilityE2EFixture';
let CUSTOMER_ID;
const MIN_READABLE_PX = 16;

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
    test.beforeAll(async () => {
        CUSTOMER_ID = await e2eWikiCustomerId();
        // A run stopped halfway leaves its upload behind, and the same file would then be refused
        // as already uploaded.
        await tinker(`${FIXTURE}::cleanup(${CUSTOMER_ID});`);
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup(${CUSTOMER_ID});`);
    });

    test('1&2&3. no native English file-input text; localized Velg fil button and Ingen fil valgt shown', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=sources');

        await expect(page.getByRole('button', { name: 'Choose File', exact: false })).toHaveCount(0);
        await expect(page.getByText('no file selected', { exact: false })).toHaveCount(0);
        await expect(page.getByText('No file selected', { exact: true })).toHaveCount(0);

        const chooseButton = page.locator('label[for="wiki-source-file"]');
        await expect(chooseButton).toHaveText('Velg fil');
        await expect(page.getByText('Ingen fil valgt', { exact: true })).toBeVisible();
    });

    test('4&6&7. selecting a file shows its filename, uploads it, and the document appears in the list', async ({ page }) => {
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=sources');

        const fileInput = page.locator('#wiki-source-file');
        await expect(fileInput).toHaveAttribute('type', 'file');

        await fileInput.setInputFiles({
            name: 'e2e-source-upload-readability.pdf',
            mimeType: 'application/pdf',
            buffer: Buffer.from('%PDF-1.4 e2e readability check content'),
        });

        // The filename on screen is what the real input's change handler read. The input itself is
        // emptied straight after (208e30cf), so choosing the same file again — to retry a failed
        // upload — still fires `change`; its files list is therefore not the thing to check.
        await expect(page.getByText('e2e-source-upload-readability.pdf', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('Ingen fil valgt', { exact: true })).toHaveCount(0);

        // Choosing the file is the upload (208e30cf): there is no «Last opp kilde» to press. A dialog
        // then says nothing is in the wiki until «Lag Wiki», and has to be dismissed.
        const uploaded = page.getByRole('dialog', { name: 'Kildedokumentet er lastet opp' });
        await expect(uploaded).toBeVisible();
        await uploaded.getByRole('button', { name: 'OK, jeg forstår' }).click();
        await expect(uploaded).toHaveCount(0);
        expect(new URL(page.url()).searchParams.get('tab')).toBe('sources');

        await expect(page.locator('tr', { has: page.getByText('e2e-source-upload-readability.pdf', { exact: true }) }).first()).toBeVisible();
    });

    test('5. clicking Velg fil opens the native file chooser', async ({ page }) => {
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=sources');

        const [chooser] = await Promise.all([
            page.waitForEvent('filechooser'),
            page.locator('label[for="wiki-source-file"]').click(),
        ]);

        expect(chooser.element()).not.toBeNull();
        expect(await chooser.isMultiple()).toBe(false);
    });

    test('8. every readable text in the upload area is at least 16px', async ({ page }) => {
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=sources');

        const chooseButton = page.locator('label[for="wiki-source-file"]');
        const noFileText = page.getByText('Ingen fil valgt', { exact: true });
        const hint = page.getByText('PDF eller DOCX', { exact: false });
        const ownerLabel = page.getByText('Dokumenteier', { exact: true }).first();

        for (const locator of [chooseButton, noFileText, hint, ownerLabel]) {
            await expect(locator).toBeVisible();
            expect(await fontSizePx(locator)).toBeGreaterThanOrEqual(MIN_READABLE_PX);
        }
    });

    test('9. Velg fil uses the violet primary action style', async ({ page }) => {
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=sources');

        const readStyles = (el) => {
            const s = window.getComputedStyle(el);
            return { background: s.backgroundColor, color: s.color };
        };

        const chooseButton = page.locator('label[for="wiki-source-file"]');
        const chooseStyles = await chooseButton.evaluate(readStyles);

        // The shared primary palette (actionStyles.js PRIMARY_COLOURS: violet-50 fill, violet-700
        // text), read off a probe that carries exactly those classes rather than a hardcoded color
        // string, since Tailwind 4 emits oklch() rather than rgb(). It used to be compared against
        // the «Last opp kilde» button, which carried the same palette and is gone since 208e30cf.
        const primaryStyles = await page.evaluate((read) => {
            const probe = document.createElement('span');
            probe.className = 'border border-violet-200 bg-violet-50 text-violet-700';
            document.body.appendChild(probe);
            const styles = new Function(`return (${read})`)()(probe);
            probe.remove();

            return styles;
        }, readStyles.toString());

        expect(chooseStyles.background).toBe(primaryStyles.background);
        expect(chooseStyles.color).toBe(primaryStyles.color);
        expect(chooseStyles.color).not.toBe(chooseStyles.background);
    });

    test('10. focus-visible is shown when the hidden input is focused via keyboard', async ({ page }) => {
        await loginAsWikiReader(page);
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
        await loginAsWikiReader(page);
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
