import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';
import { cleanUpRiskE2eData, fillRiskDescription, riskE2eSuffix } from './helpers/risk.js';

const suffix = riskE2eSuffix();
cleanUpRiskE2eData(suffix);

const SUPERSEDE_NOTE = 'En ny vurdering gjør dagens aksept historisk. Ny restrisiko må eventuelt aksepteres på nytt.';

/**
 * Status, severity and Behandling on the risk page, end to end: an assessed and accepted risk
 * still reads «Identifisert» with its residual level beside it, Ny vurdering warns only while an
 * acceptance is current, and Behandling → Endre opens the risk's own edit form at Behandlingsvalg.
 * Domain rules and permissions are owned by RiskStatusPresentationTest.
 */
test('status, acceptance warning and Behandling → Endre explain themselves', async ({ page }) => {
    const areaName = `E2E Innkjøp ${suffix}`;
    const roleName = `E2E Risikostatus ${suffix}`;
    const riskTitle = `E2E Leverandør går konkurs ${suffix}`;

    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);

    await page.goto('/app/customer-environment?tab=permissions');
    await page.getByRole('button', { name: 'Nytt fagområde' }).click();
    await page.locator('#business-area-name').fill(areaName);
    await page.getByRole('button', { name: 'Lagre fagområde' }).click();
    await expect(page.locator('tbody tr', { hasText: areaName })).toBeVisible();

    await page.getByRole('button', { name: 'Ny rolle' }).click();
    await page.locator('#customer-role-name').fill(roleName);
    for (const permission of ['Se risikoer', 'Opprette risikoer', 'Endre risikoer', 'Vurdere risikoer', 'Akseptere restrisiko']) {
        await page.getByRole('checkbox', { name: permission, exact: true }).check();
    }
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
    await expect(page.locator('#risk-status-hint')).toContainText('Settes manuelt');
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/risk\/risks\/\d+$/);

    const header = page.locator('header').filter({ has: page.getByRole('heading', { name: riskTitle }) });
    await expect(header).toContainText('Identifisert');
    await expect(header).toContainText('Restrisiko: Ikke vurdert');
    await expect(header).toContainText('Status sier hvor risikoen er i livsløpet. Risikovurderingen sier hvor alvorlig den er.');

    // First assessment: no acceptance yet, so no warning.
    await page.getByRole('button', { name: 'Ny vurdering' }).click();
    await expect(page.getByText(SUPERSEDE_NOTE)).toHaveCount(0);
    await page.locator('#assessment-inherent-likelihood').selectOption('4');
    await page.locator('#assessment-inherent-consequence').selectOption('4');
    await page.getByRole('button', { name: 'Vurder også restrisiko' }).click();
    await page.locator('#assessment-residual-likelihood').selectOption('2');
    await page.locator('#assessment-residual-consequence').selectOption('3');
    await page.locator('#assessment-rationale').fill('Alternativ leverandør er kvalifisert.');
    await page.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Vurderingen er registrert.')).toBeVisible();

    const decision = page.locator('section', { has: page.getByRole('heading', { name: 'Risikobeslutning', exact: true }) });
    await decision.getByRole('button', { name: 'Aksepter restrisiko' }).click();
    await page.locator('#risk-acceptance-rationale').fill('Restrisikoen er innenfor det vi tåler.');
    await decision.getByRole('button', { name: 'Aksepter', exact: true }).click();
    await expect(page.getByText('Restrisikoen er akseptert.')).toBeVisible();

    // Assessed, accepted — and still «Identifisert», with the severity beside it.
    await expect(header).toContainText('Identifisert');
    await expect(header).toContainText('Restrisiko: Moderat');
    await header.screenshot({ path: 'test-results/risk-status-header.png' });

    // Behandling → Endre opens the risk's edit form at Behandlingsvalg.
    const treatment = page.getByRole('region', { name: 'Behandling' });
    await treatment.getByRole('button', { name: 'Endre' }).click();
    await expect(page.locator('#risk-treatment-strategy')).toBeFocused();
    await page.locator('#risk-treatment-strategy').selectOption({ label: 'Akseptere' });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.locator('#risk-treatment-strategy')).toHaveCount(0);
    await expect(treatment).toContainText('Akseptere');
    await expect(header).toContainText('Identifisert');

    // A current acceptance: Ny vurdering warns before it is made historical.
    await page.getByRole('button', { name: 'Ny vurdering' }).click();
    await expect(page.getByText(SUPERSEDE_NOTE)).toBeVisible();
    await page.screenshot({ path: 'test-results/risk-status-supersede-warning.png', fullPage: true });
    await page.locator('#assessment-inherent-likelihood').selectOption('3');
    await page.locator('#assessment-inherent-consequence').selectOption('3');
    await page.locator('#assessment-rationale').fill('Ny gjennomgang uten restrisiko.');
    await page.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Vurderingen er registrert.')).toBeVisible();

    // The acceptance is now historical, so the next form has nothing to warn about.
    await expect(header).toContainText('Iboende risiko: Moderat');
    await page.getByRole('button', { name: 'Ny vurdering' }).click();
    await expect(page.getByText(SUPERSEDE_NOTE)).toHaveCount(0);
});
