import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';
import { cleanUpRiskE2eData, fillRiskDescription, riskE2eSuffix } from './helpers/risk.js';

const suffix = riskE2eSuffix();
cleanUpRiskE2eData(suffix);

/**
 * Risiko, end to end: System Owner names a fagområde and a role that reaches it in
 * Kundemiljø → Tilganger, hands the role to an ordinary user, and that user registers and reads a
 * risk. System Owner alone reads no risks — the implicit full grant carries no area.
 */
test('a role with a fagområde lets its holder register and read risks there', async ({ page }) => {
    const areaName = `E2E Beredskap ${suffix}`;
    const roleName = `E2E Risikoansvarlig ${suffix}`;
    const riskTitle = `E2E Strømbrudd ${suffix}`;

    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);

    // System Owner reaches the register but reads nothing in it without a role.
    await page.goto('/app/risk');
    await expect(page.getByRole('heading', { name: 'Risikoregister' })).toBeVisible();
    // Whether areas exist yet depends on earlier runs; either way System Owner is sent to Tilganger.
    await expect(page.getByText(/^(Ingen fagområder er opprettet ennå|Du har ikke tilgang til noen fagområder i Risiko ennå)$/)).toBeVisible();

    // Name the area.
    await page.goto('/app/customer-environment?tab=permissions');
    await expect(page.getByRole('heading', { name: 'Fagområder', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Nytt fagområde' }).click();
    await page.locator('#business-area-name').fill(areaName);
    await page.getByRole('button', { name: 'Lagre fagområde' }).click();
    await expect(page.locator('tbody tr', { hasText: areaName })).toBeVisible();

    // A role with Risiko permissions that reaches the area.
    await page.getByRole('button', { name: 'Ny rolle' }).click();
    await page.locator('#customer-role-name').fill(roleName);
    await page.getByRole('checkbox', { name: 'Se risikoer', exact: true }).check();
    await page.getByRole('checkbox', { name: 'Opprette risikoer', exact: true }).check();
    await page.getByRole('checkbox', { name: areaName, exact: true }).check();
    await page.getByRole('button', { name: 'Lagre rolle' }).click();

    // The Risiko table shows the role with its area, and the area lists the role.
    // Each module's matrix sits in a section that starts closed.
    await page.getByRole('button', { name: /^Risiko/ }).click();
    await expect(page.locator('tr', { hasText: roleName }).filter({ hasText: areaName }).first()).toBeVisible();

    // A second role with Fagområder = Alle reads as «Alle» in the Risiko table and is listed on
    // every area, including the one just created.
    const allRoleName = `E2E Risikoleser alle ${suffix}`;
    await page.getByRole('button', { name: 'Ny rolle' }).click();
    await page.locator('#customer-role-name').fill(allRoleName);
    await page.getByRole('checkbox', { name: 'Se risikoer', exact: true }).check();
    await page.getByRole('radio', { name: /^Alle/ }).check();
    await expect(page.getByRole('checkbox', { name: areaName, exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Lagre rolle' }).click();
    await expect(page.locator('tr', { hasText: allRoleName }).getByRole('button', { name: 'Alle', exact: true })).toBeVisible();
    await expect(page.locator('#business-areas tbody tr', { hasText: areaName })).toContainText(`${allRoleName} (Alle)`);
    await page.screenshot({ path: 'test-results/risk-tilganger.png', fullPage: true });

    // Hand the role to the ordinary user.
    await page.goto('/app/customer-environment?tab=users');
    const row = page.locator('tbody tr', { hasText: USER.email }).first();
    await row.getByRole('link', { name: 'Rediger' }).click();
    await page.waitForURL(/\/app\/users\/\d+\/edit/);
    await page.getByRole('checkbox', { name: roleName, exact: true }).check();
    await page.getByRole('button', { name: 'Lagre endringer' }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/edit'));

    await page.context().clearCookies();
    await loginAs(page, USER.email, USER.password);

    // The rail offers Risiko, and the user registers a risk in the area.
    await page.goto('/app/risk');
    await expect(page.getByRole('link', { name: 'Risiko' }).first()).toBeVisible();
    await page.getByRole('button', { name: 'Ny risiko' }).click();
    await page.locator('#risk-title').fill(riskTitle);
    await fillRiskDescription(page);
    await page.locator('#risk-description').fill('Langvarig strømbrudd på hovedkontoret.');
    await page.locator('#risk-area').selectOption({ label: areaName });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();

    await page.waitForURL(/\/app\/risk\/risks\/\d+$/);
    await expect(page.getByRole('heading', { name: riskTitle })).toBeVisible();
    await expect(page.getByText('Risikoen er registrert.')).toBeVisible();

    await page.goto('/app/risk');
    await expect(page.locator('tbody tr', { hasText: riskTitle })).toContainText(areaName);
    await page.screenshot({ path: 'test-results/risk-register.png', fullPage: true });
});
