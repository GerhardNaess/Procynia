/**
 * E2E authentication helpers.
 *
 * Uses the real login forms — no test-only backdoor routes.
 * Credentials match what E2ETestSeeder creates.
 *
 * NOTE: The main /login route only accepts customer users (canAccessCustomerFrontend() must be true).
 * Super admins (role=super_admin, customer_id=null) must use Filament's /admin/login.
 */

export const SUPER_ADMIN = {
    email: 'e2e.superadmin@procynia.test',
    password: 'E2eAdmin123!',
};

export const SYSTEM_OWNER = {
    email: 'e2e.systemowner@procynia.test',
    password: 'E2eUser123!',
};

export const USER = {
    email: 'e2e.user@procynia.test',
    password: 'E2eUser123!',
};

/**
 * Log in a customer user via the /login form and wait for redirect into /app.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} email
 * @param {string} password
 */
export async function loginAs(page, email, password) {
    await page.goto('/login');
    await page.fill('#email', email);
    await page.fill('#password', password);
    await page.click('button[type="submit"]');

    // Wait until the browser leaves /login (redirect after successful auth)
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), {
        timeout: 15_000,
    });
}

/**
 * Log in an internal super admin via Filament's /admin/login form.
 * Super admins are blocked from /login (canAccessCustomerFrontend() returns false).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} email
 * @param {string} password
 */
export async function loginAsAdmin(page, email, password) {
    await page.goto('/admin/login');

    // Filament uses Livewire — inputs have id="form.email" / id="form.password"
    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill(password);
    await page.locator('button[type="submit"]').click();

    // Wait until the browser leaves /admin/login
    await page.waitForURL((url) => !url.pathname.startsWith('/admin/login'), {
        timeout: 15_000,
    });
}
