import { test, expect } from '@playwright/test';
import { loginAs, USER } from './helpers/auth.js';

test.beforeEach(async ({ page }) => {
    await loginAs(page, USER.email, USER.password);
});

test('notices page loads for authenticated customer user', async ({ page }) => {
    const response = await page.goto('/app/notices');

    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/app\/notices/);
    // App navigation header contains the Procynia logo image
    await expect(page.locator('img[alt="Procynia"]')).toBeVisible();
});

test('dashboard page loads for authenticated customer user', async ({ page }) => {
    const response = await page.goto('/app/dashboard');

    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/app\/dashboard/);
});

test('AI workspace page loads without triggering external AI calls', async ({ page }) => {
    // The /app/ai index page only lists existing cases — no OpenAI calls are made
    const response = await page.goto('/app/ai');

    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/app\/ai/);
    await expect(page.locator('img[alt="Procynia"]')).toBeVisible();
});

test('regular customer user is blocked from the billing page', async ({ page }) => {
    const response = await page.goto('/app/billing');

    // BillingController: abort_unless($user->isSystemOwner(), 403)
    expect(response?.status()).toBe(403);
});
