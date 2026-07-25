import { test, expect } from '@playwright/test';
import { loginAs, USER } from './helpers/auth.js';

test.beforeEach(async ({ page }) => {
    await loginAs(page, USER.email, USER.password);
});

test('InfoHint near the left viewport edge does not clip (Infosenter, light variant, align=right)', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/app/info-center?view=awaiting_response');

    const helpButton = page.getByRole('button', { name: 'Vis forklaring for Venter på svar' });
    await helpButton.hover();

    const tooltip = page.getByRole('tooltip');
    await expect(tooltip).toBeVisible();

    const box = await tooltip.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(390);
});

test('InfoHint near the right viewport edge does not clip (Infosenter, light variant, align=right)', async ({ page }) => {
    await page.goto('/app/info-center?view=inbound');

    const helpButton = page.getByRole('button', { name: 'Vis forklaring for Innkommende' });
    await helpButton.hover();

    const tooltip = page.getByRole('tooltip');
    await expect(tooltip).toBeVisible();

    const box = await tooltip.boundingBox();
    const viewportWidth = page.viewportSize().width;
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(viewportWidth);
});

test('InfoHint light variant uses the readable text standard', async ({ page }) => {
    await page.goto('/app/info-center?view=my_tasks');

    const helpButton = page.getByRole('button', { name: 'Vis forklaring for Mine oppgaver' });
    await helpButton.hover();

    const tooltip = page.getByRole('tooltip');
    await expect(tooltip).toBeVisible();

    const fontSize = await tooltip.evaluate((el) => window.getComputedStyle(el).fontSize);
    const lineHeight = await tooltip.evaluate((el) => window.getComputedStyle(el).lineHeight);
    const color = await tooltip.evaluate((el) => window.getComputedStyle(el).color);

    expect(parseFloat(fontSize)).toBeGreaterThanOrEqual(16);
    expect(parseFloat(lineHeight)).toBeGreaterThanOrEqual(24);
    // Tailwind slate-700 — never the lighter slate-600.
    expect(color).toBe('oklch(0.372 0.044 257.287)');
});

test('clicking outside closes an open InfoHint', async ({ page }) => {
    await page.goto('/app/info-center?view=my_tasks');

    const helpButton = page.getByRole('button', { name: 'Vis forklaring for Mine oppgaver' });
    await helpButton.focus();
    await expect(page.getByRole('tooltip')).toBeVisible();

    // Click on an unrelated part of the page, far from the hint.
    await page.locator('h1', { hasText: 'Infosenter' }).click();
    await expect(page.getByRole('tooltip')).toHaveCount(0);
});

test('Escape closes an open InfoHint and returns focus to the trigger', async ({ page }) => {
    await page.goto('/app/info-center?view=my_tasks');

    const helpButton = page.getByRole('button', { name: 'Vis forklaring for Mine oppgaver' });
    await helpButton.focus();
    await expect(page.getByRole('tooltip')).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(page.getByRole('tooltip')).toHaveCount(0);
    await expect(helpButton).toBeFocused();
});

test('an already-migrated InfoHint consumer on the dashboard still renders correctly (regression)', async ({ page }) => {
    const response = await page.goto('/app/dashboard');
    expect(response?.status()).toBe(200);

    // DashboardCockpit's InfoButton is a thin adapter already delegating to InfoHint —
    // target it by its accessible-name prefix rather than a specific (translated) title.
    const helpButton = page.getByRole('button', { name: /^Vis forklaring for /i }).first();
    await expect(helpButton).toBeVisible();
    await helpButton.hover();

    const tooltip = page.getByRole('tooltip');
    await expect(tooltip).toBeVisible();

    const fontSize = await tooltip.evaluate((el) => window.getComputedStyle(el).fontSize);
    expect(parseFloat(fontSize)).toBeGreaterThanOrEqual(16);

    const box = await tooltip.boundingBox();
    const viewportWidth = page.viewportSize().width;
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(viewportWidth);
});

test('no console errors or failed requests when opening/closing InfoHint across pages', async ({ page }) => {
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
    page.on('requestfailed', (req) => {
        failedRequests.push(`${req.method()} ${req.url()} — ${req.failure()?.errorText}`);
    });

    await page.goto('/app/info-center?view=my_tasks');
    await page.getByRole('button', { name: 'Vis forklaring for Mine oppgaver' }).hover();
    await page.mouse.move(0, 0);

    await page.goto('/app/dashboard');
    await page.getByRole('button', { name: /^Vis forklaring for /i }).first().hover();
    await page.mouse.move(0, 0);

    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
});
