import { test, expect } from '@playwright/test';
import { loginAs, SYSTEM_OWNER } from './helpers/auth.js';

test('system owner can access the billing page', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    const response = await page.goto('/app/billing');

    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/app\/billing/);
    // Page rendered — Stripe data is absent in the test env but rescue() handles it safely
    await expect(page.locator('nav').first()).toBeVisible();
});
