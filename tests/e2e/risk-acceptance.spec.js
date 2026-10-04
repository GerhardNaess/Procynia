import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';
import { cleanUpRiskE2eData, fillRiskDescription, riskE2eSuffix } from './helpers/risk.js';

const suffix = riskE2eSuffix();
cleanUpRiskE2eData(suffix);

/**
 * Acceptance of residual risk, end to end: a risk assessed with residual risk is accepted, the
 * decision shows, the acceptance is revoked, and accepted again with the first kept as history.
 * Permissions, the latest-assessment rule and tenancy are owned by RiskAcceptanceTest.
 */
test('residual risk is accepted, revoked and accepted again', async ({ page }) => {
    const areaName = `E2E Drift ${suffix}`;
    const roleName = `E2E Risikobeslutning ${suffix}`;
    const riskTitle = `E2E Strømbrudd i datasenter ${suffix}`;

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
    await page.getByRole('checkbox', { name: 'Vurdere risikoer', exact: true }).check();
    await page.getByRole('checkbox', { name: 'Akseptere restrisiko', exact: true }).check();
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

    const panel = page.locator('section', { has: page.getByRole('heading', { name: 'Risikobeslutning', exact: true }) });
    await expect(panel.getByText(/Risikoen er ikke vurdert ennå/)).toBeVisible();
    await expect(panel.getByRole('button', { name: 'Aksepter restrisiko' })).toHaveCount(0);

    // An assessment with residual risk.
    await page.getByRole('button', { name: 'Ny vurdering' }).click();
    await page.locator('#assessment-inherent-likelihood').selectOption('4');
    await page.locator('#assessment-inherent-consequence').selectOption('4');
    await page.getByRole('button', { name: 'Vurder også restrisiko' }).click();
    await page.locator('#assessment-residual-likelihood').selectOption('2');
    await page.locator('#assessment-residual-consequence').selectOption('3');
    await page.locator('#assessment-rationale').fill('UPS og aggregat finnes, men er ikke testet under last.');
    await page.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Vurderingen er registrert.')).toBeVisible();

    await expect(panel.getByText('Restrisikoen i siste vurdering er ikke akseptert.')).toBeVisible();

    // Aksepter.
    await panel.getByRole('button', { name: 'Aksepter restrisiko' }).click();
    await page.locator('#risk-acceptance-rationale').fill('Kostnaden ved ytterligere redundans står ikke i forhold til restrisikoen.');
    await page.locator('#risk-acceptance-valid-until').fill('2027-06-30');
    await panel.getByRole('button', { name: 'Aksepter', exact: true }).click();
    await expect(page.getByText('Restrisikoen er akseptert.')).toBeVisible();

    await expect(panel.getByText('Akseptert', { exact: true })).toBeVisible();
    await expect(panel.getByText('Kostnaden ved ytterligere redundans står ikke i forhold til restrisikoen.')).toBeVisible();
    await expect(panel.getByText('30.6.2027')).toBeVisible();
    await expect(panel.getByText('Aksepten er utløpt')).toHaveCount(0);
    await expect(panel.getByRole('button', { name: 'Aksepter restrisiko' })).toHaveCount(0);
    await page.screenshot({ path: 'test-results/risk-acceptance-accepted.png', fullPage: true });

    // Trekk tilbake, with confirmation.
    page.once('dialog', (dialog) => dialog.accept());
    await panel.getByRole('button', { name: 'Trekk tilbake aksept' }).click();
    await expect(page.getByText('Aksepten er trukket tilbake.')).toBeVisible();
    await expect(panel.getByText('Restrisikoen i siste vurdering er ikke akseptert.')).toBeVisible();
    await expect(panel.getByText('Tidligere aksepter (1)')).toBeVisible();

    // Aksepter på nytt; the revoked one stays in the folded history.
    await panel.getByRole('button', { name: 'Aksepter restrisiko' }).click();
    await page.locator('#risk-acceptance-rationale').fill('Akseptert på nytt etter gjennomgang med driftsleder.');
    await panel.getByRole('button', { name: 'Aksepter', exact: true }).click();
    await expect(page.getByText('Restrisikoen er akseptert.')).toBeVisible();
    await expect(panel.getByText('Akseptert på nytt etter gjennomgang med driftsleder.')).toBeVisible();
    await expect(panel.getByText('Ingen sluttdato')).toBeVisible();

    await panel.getByText('Tidligere aksepter (1)').click();
    const history = panel.locator('details li');
    await expect(history.getByText('Trukket tilbake', { exact: true })).toBeVisible();
    await expect(history.getByText('Kostnaden ved ytterligere redundans står ikke i forhold til restrisikoen.')).toBeVisible();
    await page.screenshot({ path: 'test-results/risk-acceptance-history.png', fullPage: true });
});
