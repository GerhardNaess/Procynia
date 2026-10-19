import { test, expect } from '@playwright/test';
import { tinker } from './helpers/risk.js';
import { loginAs } from './helpers/auth.js';
import { e2eWikiCustomerId, loginAsWikiReader } from './helpers/wiki.js';

const FIXTURE = '\\Tests\\Support\\WikiSourceUploadReadabilityE2EFixture';
let CUSTOMER_ID;
const MIN_READABLE_PX = 16;
const UPLOADED = 'e2e-source-upload-readability.pdf';
const FAILED = 'e2e-source-list-failed-run.docx';
const FAILED_RUN_ERROR_START = 'RuntimeException: Page generation failed';
const READER = { email: 'e2e.wikisources.reader@procynia.test', password: 'e2e-wiki-sources-reader' };

/** The table row (from 1280px) of one document. */
function sourceRow(page, filename) {
    return page.locator('tr', { has: page.getByText(filename, { exact: true }) }).first();
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
    test.beforeAll(async () => {
        CUSTOMER_ID = await e2eWikiCustomerId();
        // A run stopped halfway leaves its upload behind, and the same file would then be refused
        // as already uploaded.
        await tinker(`${FIXTURE}::cleanup(${CUSTOMER_ID});`);
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup(${CUSTOMER_ID});`);
        await tinker(`${FIXTURE}::cleanupListStates(${CUSTOMER_ID});`);
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

    // Runs after the upload in 4&6&7. On a 1280–1366px laptop the table used to be wider than its
    // scroll box with Handlinger as its last column, so «Lag Wiki» sat behind a horizontal scroll
    // macOS does not show. The list now fits: no sideways scroll, and «Lag Wiki» inside the viewport.
    test('14. «Lag Wiki» is visible without scrolling on laptop widths, and the rows are compact', async ({ page }) => {
        await tinker(`${FIXTURE}::markExtracted(${CUSTOMER_ID});`);
        await loginAsWikiReader(page);

        for (const [width, height] of [[1280, 800], [1366, 768], [1440, 900], [1920, 1080]]) {
            await page.setViewportSize({ width, height });
            await page.goto('/app/wiki?tab=sources');

            const row = sourceRow(page, UPLOADED);
            const button = row.getByRole('button', { name: 'Lag Wiki' });
            await expect(button, `${width}px`).toBeEnabled();
            await button.scrollIntoViewIfNeeded();

            const box = await button.boundingBox();
            const region = await row.evaluate((el) => {
                const box = el.closest('table').parentElement;

                return { ...box.getBoundingClientRect().toJSON(), scrolls: box.scrollWidth > box.clientWidth + 1 };
            });
            expect(region.scrolls, `${width}px: the list scrolls sideways`).toBe(false);
            expect(box.x, `${width}px`).toBeGreaterThanOrEqual(region.left);
            expect(box.x + box.width, `${width}px`).toBeLessThanOrEqual(region.right);
            expect(box.x + box.width, `${width}px`).toBeLessThanOrEqual(width);

            // One primary action and a ⋯ button; the old row stacked three buttons (161px).
            expect((await row.boundingBox()).height, `${width}px row height`).toBeLessThan(110);
            await expect(row.getByRole('link', { name: 'Last ned' })).toHaveCount(0);
        }
    });

    test('15. the ⋯ panel holds Last ned and Slett, works from the keyboard and returns focus', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 768 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=sources');

        const row = sourceRow(page, UPLOADED);
        const more = row.getByRole('button', { name: 'Flere handlinger' });
        await expect(more).toHaveAccessibleDescription(UPLOADED);
        await expect(more).toHaveAttribute('aria-expanded', 'false');

        // Keys only after the page is interactive: the markup is there before React is.
        await page.waitForLoadState('networkidle');
        await more.focus();
        await page.keyboard.press('Enter');
        await expect(more).toHaveAttribute('aria-expanded', 'true');

        await page.keyboard.press('Tab');
        const download = row.getByRole('link', { name: 'Last ned' });
        await expect(download).toBeFocused();
        await expect(download).toHaveAttribute('href', /\/app\/wiki\/sources\/\d+\/download$/);
        await page.keyboard.press('Tab');
        await expect(row.getByRole('button', { name: 'Slett' })).toBeFocused();

        await page.keyboard.press('Escape');
        await expect(more).toBeFocused();
        await expect(more).toHaveAttribute('aria-expanded', 'false');
        await expect(row.getByRole('link', { name: 'Last ned' })).toHaveCount(0);

        // Slett still asks first; Avbryt closes the dialog and focus goes back to ⋯.
        await more.click();
        await row.getByRole('button', { name: 'Slett' }).click();
        const dialog = page.getByRole('dialog', { name: 'Bekreft sletting av kildedokument' });
        await expect(dialog).toBeVisible();
        await dialog.getByRole('button', { name: 'Avbryt', exact: true }).click();
        await expect(dialog).toHaveCount(0);
        await expect(more).toBeFocused();
        await expect(sourceRow(page, UPLOADED)).toBeVisible();
    });

    test('16. the document owner is a name; the pencil opens a dialog that saves and returns focus', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 768 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=sources');

        const row = sourceRow(page, UPLOADED);
        await expect(row.locator('select')).toHaveCount(0);

        const edit = row.getByRole('button', { name: 'Endre dokumenteier' });
        await edit.click();
        const dialog = page.getByRole('dialog', { name: 'Endre dokumenteier' });
        const select = dialog.getByLabel('Dokumenteier');
        await expect(select).toBeFocused();

        // Escape leaves without saving.
        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
        await expect(edit).toBeFocused();

        await edit.click();
        await select.selectOption({ label: 'Mangler Dokumenteier' });
        await dialog.getByRole('button', { name: 'Lagre eier' }).click();
        await expect(dialog).toHaveCount(0);
        await expect(page.getByText('Dokumenteier oppdatert.', { exact: false }).first()).toBeVisible();
        await expect(sourceRow(page, UPLOADED)).toContainText('Mangler Dokumenteier');
        expect(new URL(page.url()).searchParams.get('tab')).toBe('sources');

        await sourceRow(page, UPLOADED).getByRole('button', { name: 'Endre dokumenteier' }).click();
        await dialog.getByLabel('Dokumenteier').selectOption({ index: 1 });
        await dialog.getByRole('button', { name: 'Lagre eier' }).click();
        await expect(dialog).toHaveCount(0);
        await expect(sourceRow(page, UPLOADED)).not.toContainText('Mangler Dokumenteier');
    });

    test('17. a failed run is one short Norwegian sentence, and «Se detaljer» opens the run with the full error', async ({ page }) => {
        await tinker(`${FIXTURE}::seedListStates(${CUSTOMER_ID});`);
        await page.setViewportSize({ width: 1366, height: 768 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=sources');

        const row = sourceRow(page, FAILED);
        await expect(row.getByText('Feilet', { exact: true })).toBeVisible();
        await expect(row.getByText('Kjøringen stoppet før Wiki-innholdet var ferdig.')).toBeVisible();
        await expect(row).not.toContainText('RuntimeException');

        const details = row.getByRole('link', { name: 'Se detaljer' });
        await expect(details).toHaveAccessibleDescription(FAILED);
        await details.click();
        await page.waitForURL((url) => url.searchParams.get('tab') === 'runs' && url.searchParams.has('run_src'));
        await expect(page.getByText(FAILED_RUN_ERROR_START, { exact: false }).first()).toBeVisible();
    });

    test('18. someone who may only read the Wiki gets Last ned, and nothing that changes a document', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 768 });
        await loginAs(page, READER.email, READER.password);
        await page.goto('/app/wiki?tab=sources');

        const row = sourceRow(page, FAILED);
        await expect(row).toBeVisible();
        await expect(row.getByRole('button', { name: 'Lag Wiki' })).toHaveCount(0);
        await expect(row.getByRole('button', { name: 'Endre dokumenteier' })).toHaveCount(0);

        await row.getByRole('button', { name: 'Flere handlinger' }).click();
        await expect(row.getByRole('link', { name: 'Last ned' })).toBeVisible();
        await expect(row.getByRole('button', { name: 'Slett' })).toHaveCount(0);
    });

    test('19. below 1280px every document is a card with its actions on screen, and nothing scrolls sideways', async ({ page }) => {
        await loginAsWikiReader(page);

        for (const [width, height] of [[1024, 768], [820, 1180], [390, 844]]) {
            await page.setViewportSize({ width, height });
            await page.goto('/app/wiki?tab=sources');
            await page.waitForLoadState('networkidle');

            await expect(page.locator('table', { has: page.getByText(UPLOADED, { exact: true }) })).toBeHidden();
            const card = page.locator('li', { has: page.getByRole('heading', { name: UPLOADED }) });
            const button = card.getByRole('button', { name: 'Lag Wiki' });
            await button.scrollIntoViewIfNeeded();
            const box = await button.boundingBox();
            expect(box.x + box.width, `${width}px`).toBeLessThanOrEqual(width);

            const more = card.getByRole('button', { name: 'Flere handlinger' });
            await more.click();
            const panel = card.getByRole('link', { name: 'Last ned' });
            await expect(panel).toBeVisible();
            const panelBox = await panel.boundingBox();
            expect(panelBox.x, `${width}px panel`).toBeGreaterThanOrEqual(0);
            expect(panelBox.x + panelBox.width, `${width}px panel`).toBeLessThanOrEqual(width);
            await page.keyboard.press('Escape');
            await expect(more).toBeFocused();

            const bodyOverflows = await page.evaluate(
                () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
            );
            expect(bodyOverflows, `${width}px`).toBe(false);
        }
    });
});
