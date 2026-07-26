import { test, expect } from '@playwright/test';
import { exec } from 'node:child_process';
import { promisify } from 'node:util';

const execAsync = promisify(exec);
const FIXTURE = '\\Tests\\Support\\WikiWordImageE2EFixture';
const CUSTOMER_ID = 4;

async function loginAsDevDataUser(page) {
    await page.goto('/login');
    await page.fill('#email', 'alisan@advania.no');
    await page.fill('#password', 'Opaque01');
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 });
}

test.describe.serial('Word image (figure) rendering in a Wiki page', () => {
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

    test('renders a genuine semantic figure with image, caption, and citation', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki/e2e-word-image-verifisering');
        await page.waitForTimeout(1000);

        const figure = page.locator('figure');
        await expect(figure).toBeVisible();

        const img = figure.locator('img');
        await expect(img).toBeVisible();
        await expect(img).toHaveAttribute('alt', /Arkitekturdiagram/);

        await expect(figure.locator('figcaption')).toContainText('Figur 1: Oversikt over systemintegrasjonene – æøå');
        await expect(page.getByText(/Plassering i kilden.*Figur 1/)).toBeVisible();
    });

    test('the decorative logo never appears as ordinary Wiki content', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki/e2e-word-image-verifisering');
        await page.waitForTimeout(1000);

        // Only one figure block was cited/built (the diagram) — the tiny logo image must not
        // surface as a second figure or any other visible content block.
        await expect(page.locator('figure')).toHaveCount(1);
        await expect(page.getByText('Figur 2')).toHaveCount(0);
    });

    test('the image loads through the authenticated route, not a raw storage path', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki/e2e-word-image-verifisering');
        await page.waitForTimeout(1000);

        const img = page.locator('figure img');
        const src = await img.getAttribute('src');

        expect(src).toContain('/images/img0');
        expect(src).not.toContain('/storage/');
        expect(src).not.toContain('.docx');

        const response = await page.request.get(src);
        expect(response.ok()).toBeTruthy();
        expect(response.headers()['content-type']).toBe('image/png');
    });

    test('mobile viewport (390px): figure scales down, page does not scroll horizontally', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/app/wiki/e2e-word-image-verifisering');
        await page.waitForTimeout(1000);

        const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
        const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
        expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 1);

        await expect(page.locator('figure')).toBeVisible();
    });

    test('no console errors or failed requests while viewing the figure page', async ({ page }) => {
        const consoleErrors = [];
        const failedRequests = [];
        page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
        page.on('requestfailed', (req) => failedRequests.push(`${req.method()} ${req.url()}`));
        page.on('response', (res) => { if (res.status() >= 500) failedRequests.push(`${res.status()} ${res.url()}`); });

        await loginAsDevDataUser(page);
        await page.goto('/app/wiki/e2e-word-image-verifisering');
        await page.waitForTimeout(1000);

        expect(consoleErrors).toEqual([]);
        expect(failedRequests).toEqual([]);
    });
});
