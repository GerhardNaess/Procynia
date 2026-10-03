import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

const ROLES = ['Kvalitetsleder', 'Wiki-redaktør'];

const roleCheckbox = (page, name) => page.getByRole('checkbox', { name, exact: true });

/**
 * Walks the manual verification for moving role assignment onto Rediger bruker.
 */
test('customer roles are assigned on Rediger bruker and shown in the user table', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);

    // Tilganger defines roles and no longer hands them out.
    await page.goto('/app/customer-environment?tab=permissions');
    await expect(page.getByText('Tildeling av roller')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Egne roller' })).toBeVisible();

    // Edit an ordinary user and tick both roles.
    await page.goto('/app/customer-environment?tab=users');
    const row = page.locator('tbody tr', { hasText: 'e2e.user@procynia.test' }).first();
    await row.getByRole('link', { name: 'Rediger' }).click();
    await page.waitForURL(/\/app\/users\/\d+\/edit/);

    const bidRoleBefore = await page.locator('select[name="bid_role"]').inputValue();
    const qaBefore = await page.locator('input[name="is_qa"]').inputValue();

    for (const name of ROLES) {
        await roleCheckbox(page, name).check();
    }
    await page.getByRole('button', { name: 'Lagre endringer' }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/edit'));

    // Both show as badges in the user table.
    await page.goto('/app/customer-environment?tab=users');
    const updated = page.locator('tbody tr', { hasText: 'e2e.user@procynia.test' }).first();
    for (const name of ROLES) {
        await expect(updated).toContainText(name);
    }

    // Reopening the user shows both ticked, and anbud is unchanged.
    await updated.getByRole('link', { name: 'Rediger' }).click();
    await page.waitForURL(/\/app\/users\/\d+\/edit/);
    for (const name of ROLES) {
        await expect(roleCheckbox(page, name)).toBeChecked();
    }
    expect(await page.locator('select[name="bid_role"]').inputValue()).toBe(bidRoleBefore);
    expect(await page.locator('input[name="is_qa"]').inputValue()).toBe(qaBefore);
});
