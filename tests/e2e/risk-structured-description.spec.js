import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';
import { cleanUpRiskE2eData, fillRiskDescription, riskE2eSuffix } from './helpers/risk.js';

const suffix = riskE2eSuffix();
cleanUpRiskE2eData(suffix);

/**
 * Strukturert risikobeskrivelse, end to end: a new risk is described as årsak → hendelse →
 * konsekvens, the page composes the sentence, an assessment is registered, and after the risk is
 * described again the assessment still shows what was assessed. Validation, legacy risks and
 * access are owned by RiskStructuredDescriptionTest.
 */
test('the assessment history keeps the risk description it was made against', async ({ page }) => {
    const areaName = `E2E Drift ${suffix}`;
    const roleName = `E2E Risikobeskrivelse ${suffix}`;
    const riskTitle = `E2E Datasenter ${suffix}`;

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
    await page.getByRole('checkbox', { name: 'Vurdere risikoer', exact: true }).check();
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
    await expect(page.getByText('Hva kan gjøre hendelsen mulig?')).toBeVisible();
    await expect(page.getByText('Hva kan skje?')).toBeVisible();
    await expect(page.getByText('Hva kan virksomheten bli påvirket av?')).toBeVisible();
    await page.locator('#risk-title').fill(riskTitle);
    await fillRiskDescription(page, {
        cause: 'manglende reservestrøm',
        event: 'strømbrudd i datasenteret',
        consequence: 'at kundene mister tilgang til tjenestene',
    });
    await page.locator('#risk-area').selectOption({ label: areaName });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/risk\/risks\/\d+$/);

    const before = 'På grunn av manglende reservestrøm kan strømbrudd i datasenteret skje, noe som kan føre til at kundene mister tilgang til tjenestene.';
    const description = page.locator('section', { has: page.getByRole('heading', { name: 'Risikobeskrivelse', exact: true }) });
    await expect(description.getByText(before)).toBeVisible();

    await page.getByRole('button', { name: 'Ny vurdering' }).click();
    await page.locator('#assessment-inherent-likelihood').selectOption('3');
    await page.locator('#assessment-inherent-consequence').selectOption('4');
    await page.locator('#assessment-rationale').fill('Ingen reservestrøm er installert.');
    await page.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Vurderingen er registrert.')).toBeVisible();

    const assessments = page.locator('section', { has: page.getByRole('heading', { name: 'Risikovurdering', exact: true }) });
    await expect(assessments.getByText(before)).toBeVisible();

    await page.getByRole('button', { name: 'Rediger' }).click();
    await fillRiskDescription(page, {
        cause: 'aldrende UPS-batterier',
        event: 'kortvarig strømbrudd',
        consequence: 'avbrudd i driften',
    });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();

    const after = 'På grunn av aldrende UPS-batterier kan kortvarig strømbrudd skje, noe som kan føre til avbrudd i driften.';
    await expect(description.getByText(after)).toBeVisible();
    await expect(assessments.getByText(before)).toBeVisible();
    await expect(assessments.getByText(after)).toHaveCount(0);
    await expect(assessments.getByText('Risikobeskrivelsen er endret etter denne vurderingen.')).toBeVisible();

    await page.screenshot({ path: 'test-results/risk-structured-description.png', fullPage: true });
});
