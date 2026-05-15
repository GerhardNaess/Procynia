import { test, expect } from '@playwright/test';
import { loginAs, loginAsAdmin, SUPER_ADMIN, USER } from './helpers/auth.js';

test('login page loads with email and password fields', async ({ page }) => {
    await page.goto('/login');

    await expect(page.locator('#email')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toBeVisible();
});

test('customer user logs in and lands in the customer app', async ({ page }) => {
    await loginAs(page, USER.email, USER.password);

    await expect(page).toHaveURL(/\/app\//);
});

test('super admin logs in via admin login and lands in the admin panel', async ({ page }) => {
    // Super admins cannot use /login — it requires canAccessCustomerFrontend().
    // They authenticate at /admin/login (Filament's own login page).
    await loginAsAdmin(page, SUPER_ADMIN.email, SUPER_ADMIN.password);

    await expect(page).toHaveURL(/\/admin/);
});

test('invalid credentials keep user on the login page', async ({ page }) => {
    await page.goto('/login');
    await page.fill('#email', 'not-a-real-user@procynia.test');
    await page.fill('#password', 'wrongpassword');
    await page.click('button[type="submit"]');

    // Inertia re-renders the login page with validation errors on failure
    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('#email')).toBeVisible();
});

test('unauthenticated user is redirected to login when accessing customer app', async ({ page }) => {
    await page.goto('/app/dashboard');

    await expect(page).toHaveURL(/\/login/);
});

test('Filament admin login page loads', async ({ page }) => {
    await page.goto('/admin/login');

    await expect(page.locator('input[type="email"]')).toBeVisible();
    await expect(page.locator('input[type="password"]')).toBeVisible();
});
