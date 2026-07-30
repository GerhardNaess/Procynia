import { test, expect } from '@playwright/test';
import { exec } from 'node:child_process';
import { promisify } from 'node:util';

const execAsync = promisify(exec);
const FIXTURE = '\\Tests\\Support\\WikiBestPracticeE2EFixture';
const CUSTOMER_ID = 4;

async function loginAsDevDataUser(page) {
    await page.goto('/login');
    await page.fill('#email', 'alisan@advania.no');
    await page.fill('#password', 'Opaque01');
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 });
}

/**
 * Verifies the reader-facing distinction between source-based content and best-practice
 * guidance (the fix for ingest run 482 escalating on correctly-intentioned best-practice text).
 */
test.describe.serial('Best-practice vs. source content distinction in a Wiki page', () => {
    test.beforeAll(async () => {
        await execAsync(
            `docker compose exec -T app php artisan tinker --execute="echo ${FIXTURE}::seed(${CUSTOMER_ID});"`,
            { cwd: new URL('../..', import.meta.url).pathname },
        );
    });

    test.afterAll(async () => {
        await execAsync(
            `docker compose exec -T app php artisan tinker --execute="${FIXTURE}::cleanup(${CUSTOMER_ID});"`,
            { cwd: new URL('../..', import.meta.url).pathname },
        );
    });

    test('the best-practice block is clearly labeled and the source block is not', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki/e2e-best-practice-verifisering');
        await page.waitForTimeout(1000);

        const article = page.locator('.wiki-article');
        await expect(article.getByText('Figuren under illustrerer samhandlingsprosessen')).toBeVisible();
        await expect(article.getByText('Det anbefales å definere tydelige roller')).toBeVisible();

        // The label appears exactly once within the article body, attached to the best-practice
        // block only — the source-based block above it carries no such label.
        await expect(article.getByText('Beste praksis', { exact: true })).toHaveCount(1);
    });

    test('no console errors or failed requests while viewing the page', async ({ page }) => {
        const consoleErrors = [];
        const failedRequests = [];
        page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
        page.on('requestfailed', (req) => failedRequests.push(`${req.method()} ${req.url()}`));
        page.on('response', (res) => { if (res.status() >= 500) failedRequests.push(`${res.status()} ${res.url()}`); });

        await loginAsDevDataUser(page);
        await page.goto('/app/wiki/e2e-best-practice-verifisering');
        await page.waitForTimeout(1000);

        expect(consoleErrors).toEqual([]);
        expect(failedRequests).toEqual([]);
    });
});
