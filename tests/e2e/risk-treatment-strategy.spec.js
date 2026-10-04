import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';
import { cleanUpRiskE2eData, fillRiskDescription, riskE2eSuffix } from './helpers/risk.js';

const suffix = riskE2eSuffix();
cleanUpRiskE2eData(suffix);

/**
 * Behandlingsvalg, end to end: a user with risk.edit (and no risk.accept) chooses «Redusere», sees
 * it under Behandling, changes it to «Akseptere», and the page still says the residual risk is not
 * formally accepted. Validation, permissions and tenancy are owned by RiskTreatmentStrategyTest.
 */
test('choosing a treatment strategy never stands in for formal acceptance', async ({ page }) => {
    const areaName = `E2E Lønn ${suffix}`;
    const roleName = `E2E Risikobehandling ${suffix}`;
    const riskTitle = `E2E Feil lønnsutbetaling ${suffix}`;

    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);

    await page.goto('/app/customer-environment?tab=permissions');
    await page.getByRole('button', { name: 'Nytt fagområde' }).click();
    await page.locator('#business-area-name').fill(areaName);
    await page.getByRole('button', { name: 'Lagre fagområde' }).click();
    await expect(page.locator('tbody tr', { hasText: areaName })).toBeVisible();

    await page.getByRole('button', { name: 'Ny rolle' }).click();
    await page.locator('#customer-role-name').fill(roleName);
    await page.getByRole('checkbox', { name: 'Se risikoer', exact: true }).check();
    await page.getByRole('checkbox', { name: 'Opprette risikoer', exact: true }).check();
    await page.getByRole('checkbox', { name: 'Endre risikoer', exact: true }).check();
    await page.getByRole('checkbox', { name: areaName, exact: true }).check();
    await page.getByRole('button', { name: 'Lagre rolle' }).click();
    await expect(page.locator('tr', { hasText: roleName }).first()).toBeVisible();

    await page.goto('/app/customer-environment?tab=users');
    await page.locator('tbody tr', { hasText: USER.email }).first().getByRole('link', { name: 'Rediger' }).click();
    await page.waitForURL(/\/app\/users\/\d+\/edit/);
    await page.getByRole('checkbox', { name: roleName, exact: true }).check();
    await page.getByRole('button', { name: 'Lagre endringer' }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/edit'));

    await page.context().clearCookies();
    await loginAs(page, USER.email, USER.password);

    await page.goto('/app/risk');
    await page.getByRole('button', { name: 'Ny risiko' }).click();
    await page.locator('#risk-title').fill(riskTitle);
    await fillRiskDescription(page);
    await page.locator('#risk-area').selectOption({ label: areaName });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/risk\/risks\/\d+$/);

    const treatment = page.getByRole('region', { name: 'Behandling' });
    await expect(treatment).toContainText('Behandling er ikke besluttet ennå.');

    // Redusere.
    await page.getByRole('button', { name: 'Rediger' }).click();
    await page.locator('#risk-treatment-strategy').selectOption({ label: 'Redusere' });
    await expect(page.locator('#risk-treatment-strategy-hint')).toHaveText('Redusere sannsynlighet eller konsekvens.');
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.locator('#risk-treatment-strategy')).toHaveCount(0);

    await expect(treatment).toContainText('Redusere');
    await expect(treatment).toContainText('Redusere sannsynlighet eller konsekvens.');
    await treatment.screenshot({ path: 'test-results/risk-treatment-reduce.png' });

    // Akseptere — a direction, not an acceptance.
    await page.getByRole('button', { name: 'Rediger' }).click();
    await page.locator('#risk-treatment-strategy').selectOption({ label: 'Akseptere' });
    await expect(page.getByText('Valget registrerer ikke aksept.', { exact: false })).toBeVisible();
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.locator('#risk-treatment-strategy')).toHaveCount(0);

    await expect(treatment).toContainText('Akseptere');
    await expect(treatment).toContainText('Restrisikoen er ikke formelt akseptert før en med rett til å akseptere risiko har registrert beslutningen under Risikobeslutning.');
    await expect(treatment).not.toContainText('formelt akseptert —');

    const decision = page.locator('section', { has: page.getByRole('heading', { name: 'Risikobeslutning', exact: true }) });
    await expect(decision).toContainText('Risikoen er ikke vurdert ennå.');
    await expect(decision.getByRole('button', { name: 'Aksepter restrisiko' })).toHaveCount(0);
    await page.screenshot({ path: 'test-results/risk-treatment-accept.png', fullPage: true });
});
