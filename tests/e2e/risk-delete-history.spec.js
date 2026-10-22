import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';
import { cleanUpRiskE2eData, fillRiskDescription, riskE2eSuffix } from './helpers/risk.js';

const suffix = riskE2eSuffix();
cleanUpRiskE2eData(suffix);

/**
 * Decision B3, end to end: «Slett risiko» is for a risk registered by mistake. Once the risk has an
 * assessment it is no longer offered; the page says why and points to «Lukket» instead.
 */
test('a risk registered by mistake is deleted; an assessed risk says why it is closed instead', async ({ page }) => {
    const areaName = `E2E Drift ${suffix}`;
    const roleName = `E2E Risikosletting ${suffix}`;

    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);

    await page.goto('/app/customer-environment?tab=permissions');
    await page.getByRole('button', { name: 'Nytt fagområde' }).click();
    await page.locator('#business-area-name').fill(areaName);
    await page.getByRole('button', { name: 'Lagre fagområde' }).click();
    await expect(page.locator('tbody tr', { hasText: areaName })).toBeVisible();

    await page.getByRole('button', { name: 'Ny rolle' }).click();
    await page.locator('#customer-role-name').fill(roleName);
    for (const label of ['Se risikoer', 'Opprette risikoer', 'Endre risikoer', 'Vurdere risikoer', 'Slette risikoer']) {
        await page.getByRole('checkbox', { name: label, exact: true }).check();
    }
    await page.getByRole('checkbox', { name: areaName, exact: true }).check();
    await page.getByRole('button', { name: 'Lagre rolle' }).click();
    await expect(page.locator('tr', { hasText: roleName }).filter({ visible: true }).first()).toBeVisible();

    await page.goto('/app/customer-environment?tab=users');
    await page.locator('tbody tr', { hasText: USER.email }).first().getByRole('link', { name: 'Rediger' }).click();
    await page.waitForURL(/\/app\/users\/\d+\/edit/);
    await page.getByRole('checkbox', { name: roleName, exact: true }).check();
    await page.getByRole('button', { name: 'Lagre endringer' }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/edit'));

    await page.context().clearCookies();
    await loginAs(page, USER.email, USER.password);

    const register = async (title) => {
        await page.goto('/app/risk');
        await page.getByRole('button', { name: 'Ny risiko' }).click();
        await page.locator('#risk-title').fill(title);
        await fillRiskDescription(page);
        await page.locator('#risk-area').selectOption({ label: areaName });
        await page.getByRole('button', { name: 'Lagre', exact: true }).click();
        await page.waitForURL(/\/app\/risk\/risks\/\d+$/);
    };

    // Registered by mistake: «Slett risiko» deletes it, after a confirmation.
    await register(`E2E Feilregistrert ${suffix}`);
    await expect(page.getByTestId('risk-delete-blocked')).toHaveCount(0);
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Slett risiko' }).click();
    await page.waitForURL(/\/app\/risk$/);
    await expect(page.getByText('Risikoen er slettet.')).toBeVisible();

    // Assessed: no «Slett risiko», and the reason where it was.
    await register(`E2E Vurdert og lukket ${suffix}`);
    await page.getByRole('button', { name: 'Ny vurdering' }).click();
    await page.locator('#assessment-inherent-likelihood').selectOption('3');
    await page.locator('#assessment-inherent-consequence').selectOption('3');
    await page.locator('#assessment-rationale').fill('Vurdert før lukking.');
    await page.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Vurderingen er registrert.')).toBeVisible();

    await expect(page.getByRole('button', { name: 'Slett risiko' })).toHaveCount(0);
    const reason = page.getByTestId('risk-delete-blocked');
    await expect(reason).toBeVisible();
    await expect(reason).toContainText('kan ikke slettes');
    await expect(reason).toContainText('«Lukket»');
    expect(parseFloat(await reason.evaluate((el) => getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(16);

    // The way out it names works: «Rediger» → status «Lukket».
    await page.getByRole('button', { name: 'Rediger' }).click();
    await page.locator('#risk-status').selectOption({ label: 'Lukket' });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Risikoen er oppdatert.')).toBeVisible();
    await expect(page.getByText('Vurdert før lukking.')).toBeVisible();

    // Narrow screens: the reason wraps instead of pushing the page sideways.
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(reason).toBeVisible();
    const overflows = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    expect(overflows).toBe(false);
});
