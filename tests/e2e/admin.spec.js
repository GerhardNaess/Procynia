import { test, expect } from '@playwright/test';
import { loginAsAdmin, loginAs, SUPER_ADMIN, SYSTEM_OWNER } from './helpers/auth.js';

test('super admin can access the Filament admin panel', async ({ page }) => {
    await loginAsAdmin(page, SUPER_ADMIN.email, SUPER_ADMIN.password);
    await page.goto('/admin');

    await expect(page).toHaveURL(/\/admin/);
    // Panel loaded — user is not sent back to the login wall
    await expect(page).not.toHaveURL(/\/admin\/login/);
});

test('super admin can see the operational deviations resource', async ({ page }) => {
    await loginAsAdmin(page, SUPER_ADMIN.email, SUPER_ADMIN.password);
    const response = await page.goto('/admin/operational-deviations');

    expect(response?.status()).toBe(200);
    await expect(page.locator('body')).toContainText('Avvik og forbedringer');
});

test('customer user (system owner) is blocked from admin resources', async ({ page }) => {
    // System owner is a customer user — canAccessCustomerFrontend() = true, but
    // isInternalAdmin() = false, so Filament resource canAccess() returns false.
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    const response = await page.goto('/admin/operational-deviations');

    const status = response?.status() ?? 0;
    const isBlocked =
        status === 403 ||
        !page.url().includes('/admin/operational-deviations') ||
        !(await page.textContent('body')).includes('Avvik og forbedringer');

    expect(isBlocked).toBe(true);
});

test('unauthenticated user navigating to admin is redirected to admin login', async ({ page }) => {
    await page.goto('/admin');

    await expect(page).toHaveURL(/\/admin\/login/);
});
