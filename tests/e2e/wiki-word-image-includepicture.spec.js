import { test, expect } from '@playwright/test';
import { tinker } from './helpers/risk.js';
import { e2eWikiCustomerId, loginAsWikiReader } from './helpers/wiki.js';

const FIXTURE = '\\Tests\\Support\\WikiIncidentManagementIllustrationE2EFixture';
let CUSTOMER_ID;

/**
 * Regression for ingest run 475: a real production document ("Incident Management Illustration.docx")
 * whose image was pasted from a web page (Word INCLUDEPICTURE field), with no formal alt-text or
 * caption at all — only a preceding paragraph explicitly introducing the figure. Before the fix,
 * Word's auto-generated docPr name ("Picture 1") was mistaken for real alt-text, and the figure had
 * no deterministic signal pulling it toward 'informative' when no caption/alt-text existed.
 */
test.describe.serial('Word image with no alt-text/caption, introduced only by preceding text', () => {
    test.beforeAll(async () => {
        CUSTOMER_ID = await e2eWikiCustomerId();
        await tinker(`echo ${FIXTURE}::seed(${CUSTOMER_ID});`);
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup(${CUSTOMER_ID});`);
    });

    test('the figure renders even with no formal alt-text or caption', async ({ page }) => {
        await loginAsWikiReader(page);
        await page.goto('/app/wiki/e2e-incident-management-illustration-verifisering');
        await page.waitForTimeout(1000);

        const figure = page.locator('figure');
        await expect(figure).toBeVisible();

        const img = figure.locator('img');
        await expect(img).toBeVisible();
        // Word's auto-generated "Picture 1" name must never surface as the alt attribute.
        await expect(img).toHaveAttribute('alt', '');

        // No Word caption exists, so the fallback caption ("Figur 1") is shown instead of a blank
        // figcaption.
        await expect(figure.locator('figcaption')).toContainText('Figur 1');
        await expect(page.getByText(/Plassering i kilden.*Figur 1/)).toBeVisible();
    });

    test('the image loads via the authenticated route with correct MIME type', async ({ page }) => {
        await loginAsWikiReader(page);
        await page.goto('/app/wiki/e2e-incident-management-illustration-verifisering');
        await page.waitForTimeout(1000);

        const img = page.locator('figure img');
        const src = await img.getAttribute('src');
        expect(src).toContain('/images/img0');

        const response = await page.request.get(src);
        expect(response.ok()).toBeTruthy();
        expect(response.headers()['content-type']).toBe('image/png');
        expect(response.headers()['content-disposition']).toContain('inline');
    });

    test('no console errors or failed requests while viewing the page', async ({ page }) => {
        const consoleErrors = [];
        const failedRequests = [];
        page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
        page.on('requestfailed', (req) => failedRequests.push(`${req.method()} ${req.url()}`));
        page.on('response', (res) => { if (res.status() >= 500) failedRequests.push(`${res.status()} ${res.url()}`); });

        await loginAsWikiReader(page);
        await page.goto('/app/wiki/e2e-incident-management-illustration-verifisering');
        await page.waitForTimeout(1000);

        expect(consoleErrors).toEqual([]);
        expect(failedRequests).toEqual([]);
    });
});
