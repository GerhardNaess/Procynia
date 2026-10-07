import { test, expect } from '@playwright/test';
import { tinker } from './helpers/risk.js';
import { e2eWikiCustomerId, loginAsWikiReader } from './helpers/wiki.js';

const FIXTURE = '\\Tests\\Support\\WikiBestPracticeE2EFixture';
let CUSTOMER_ID;

/**
 * Verifies the reader-facing distinction between source-based content and best-practice
 * guidance (the fix for ingest run 482 escalating on correctly-intentioned best-practice text).
 */
test.describe.serial('Best-practice vs. source content distinction in a Wiki page', () => {
    test.beforeAll(async () => {
        CUSTOMER_ID = await e2eWikiCustomerId();
        await tinker(`echo ${FIXTURE}::seed(${CUSTOMER_ID});`);
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup(${CUSTOMER_ID});`);
    });

    test('the best-practice block is clearly labeled and the source block is not', async ({ page }) => {
        await loginAsWikiReader(page);
        await page.goto('/app/wiki/e2e-best-practice-verifisering');
        await page.waitForTimeout(1000);

        const article = page.locator('.wiki-article');
        await expect(article.getByText('Figuren under illustrerer samhandlingsprosessen')).toBeVisible();
        await expect(article.getByText('Det anbefales å definere tydelige roller')).toBeVisible();

        // The label appears exactly once within the article body, attached to the best-practice
        // block only — the source-based block above it carries no such label. It names the open
        // finding as well («Beste praksis (funn ID: …)»), so it is matched from its start.
        await expect(article.getByText(/^Beste praksis\b/)).toHaveCount(1);
    });

    test('no console errors or failed requests while viewing the page', async ({ page }) => {
        const consoleErrors = [];
        const failedRequests = [];
        page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
        page.on('requestfailed', (req) => failedRequests.push(`${req.method()} ${req.url()}`));
        page.on('response', (res) => { if (res.status() >= 500) failedRequests.push(`${res.status()} ${res.url()}`); });

        await loginAsWikiReader(page);
        await page.goto('/app/wiki/e2e-best-practice-verifisering');
        await page.waitForTimeout(1000);

        expect(consoleErrors).toEqual([]);
        expect(failedRequests).toEqual([]);
    });
});
