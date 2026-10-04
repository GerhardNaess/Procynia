import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';
import { cleanUpRiskE2eData, riskE2eSuffix } from './helpers/risk.js';

const suffix = riskE2eSuffix();
cleanUpRiskE2eData(suffix);

/**
 * System Owner with no role reaching a fagområde is not told to ask System Owner: the empty
 * register sends them to Kundemiljø → Tilganger, where they give their own role an area, and the
 * register opens. Nothing is granted implicitly — the role is what lets them in.
 */
test('system owner goes from the empty register to Tilganger and back with access', async ({ page }) => {
    const areaName = `E2E Eget område ${suffix}`;
    const roleName = `E2E Egen risikorolle ${suffix}`;

    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);

    await page.goto('/app/risk');
    await expect(page.getByText('Be System Owner gi')).toHaveCount(0);
    const cta = page.getByRole('link', { name: /^(Opprett fagområde|Administrer risikotilgang)$/ });
    await expect(cta).toBeVisible();
    await page.screenshot({ path: 'test-results/risk-owner-empty.png', fullPage: true });

    await cta.click();
    await page.waitForURL(/\/app\/customer-environment\?tab=permissions#business-areas$/);
    await expect(page.locator('#business-areas')).toBeInViewport();

    await page.getByRole('button', { name: 'Nytt fagområde' }).click();
    await page.locator('#business-area-name').fill(areaName);
    await page.getByRole('button', { name: 'Lagre fagområde' }).click();
    await expect(page.locator('tbody tr', { hasText: areaName })).toBeVisible();

    await page.getByRole('button', { name: 'Ny rolle' }).click();
    await page.locator('#customer-role-name').fill(roleName);
    await page.getByRole('checkbox', { name: 'Se risikoer', exact: true }).check();
    await page.getByRole('checkbox', { name: areaName, exact: true }).check();
    await page.getByRole('button', { name: 'Lagre rolle' }).click();
    await expect(page.locator('tr', { hasText: roleName }).first()).toBeVisible();

    // Give the role to oneself through Rediger bruker.
    const editOwn = async (check) => {
        await page.goto('/app/customer-environment?tab=users');
        await page.locator('tbody tr', { hasText: SYSTEM_OWNER.email }).first().getByRole('link', { name: 'Rediger' }).click();
        await page.waitForURL(/\/app\/users\/\d+\/edit/);
        const box = page.getByRole('checkbox', { name: roleName, exact: true });
        await (check ? box.check() : box.uncheck());
        await page.getByRole('button', { name: 'Lagre endringer' }).click();
        await page.waitForURL((url) => !url.pathname.endsWith('/edit'));
    };
    await editOwn(true);

    await page.goto('/app/risk');
    await expect(cta).toHaveCount(0);
    await expect(page.getByText('Du ser risikoene i fagområdene rollene dine gir deg.')).toBeVisible();
    await expect(page.getByPlaceholder('Søk i tittel og beskrivelse')).toBeVisible();
    await page.screenshot({ path: 'test-results/risk-owner-access.png', fullPage: true });

    // Leave System Owner without risk access again, so the empty state stays reproducible.
    await editOwn(false);
});
