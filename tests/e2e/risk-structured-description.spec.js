import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';
import { cleanUpRiskE2eData, fillRiskDescription, riskE2eSuffix } from './helpers/risk.js';

const suffix = riskE2eSuffix();
cleanUpRiskE2eData(suffix);

/**
 * Strukturert risikobeskrivelse, end to end: a new risk is described as årsak → hendelse →
 * konsekvens and shown as those three parts — no sentence is stitched together from them. The
 * register summarises the risk by its hendelse and shows its restrisiko once assessed. After the
 * risk is described again, the assessment still shows what was assessed. Validation, legacy risks
 * and access are owned by RiskStructuredDescriptionTest and RiskAssessmentTest.
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
    await expect(page.locator('tr', { hasText: roleName }).filter({ visible: true }).first()).toBeVisible();

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

    const description = page.locator('section', { has: page.getByRole('heading', { name: 'Risikobeskrivelse', exact: true }) });
    await expect(description.getByText('manglende reservestrøm', { exact: true })).toBeVisible();
    await expect(description.getByText('strømbrudd i datasenteret', { exact: true })).toBeVisible();
    await expect(description.getByText('at kundene mister tilgang til tjenestene', { exact: true })).toBeVisible();
    await expect(page.getByText(/På grunn av/)).toHaveCount(0);
    const riskUrl = page.url();

    // Hva er risikoen → hvor alvorlig → hva gjør vi med den → hvor hører den hjemme.
    const order = ['Risikobeskrivelse', 'Risikovurdering', 'Behandling', 'Tiltak', 'Kontroller som håndterer risikoen', 'Aksept av restrisiko', 'Kontekst', 'Kunnskap delt til Wiki'];
    const headings = (await page.locator('main h2').allTextContents()).map((text) => text.trim()).filter((text) => order.includes(text));
    expect(headings).toEqual(order.filter((name) => headings.includes(name)));
    expect(headings).toEqual(expect.arrayContaining(['Risikobeskrivelse', 'Risikovurdering', 'Behandling', 'Tiltak', 'Aksept av restrisiko']));

    const registerRow = () => page.locator('tbody tr', { hasText: riskTitle });
    await page.goto('/app/risk');
    await expect(registerRow()).toContainText('strømbrudd i datasenteret');
    await expect(registerRow()).toContainText('Ikke vurdert');
    await page.goto(riskUrl);

    await page.getByRole('button', { name: 'Ny vurdering' }).click();
    await page.locator('#assessment-inherent-likelihood').selectOption('3');
    await page.locator('#assessment-inherent-consequence').selectOption('4');
    await page.getByRole('button', { name: 'Vurder også restrisiko' }).click();
    await page.locator('#assessment-residual-likelihood').selectOption('1');
    await page.locator('#assessment-residual-consequence').selectOption('3');
    await page.locator('#assessment-rationale').fill('Ingen reservestrøm er installert.');
    await page.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Vurderingen er registrert.')).toBeVisible();

    const assessments = page.locator('section', { has: page.getByRole('heading', { name: 'Risikovurdering', exact: true }) });
    // Unchanged since the assessment: folded, not repeated — but one click away.
    await expect(assessments.getByText('Uendret siden vurderingen', { exact: false })).toBeVisible();
    await expect(assessments.getByText('manglende reservestrøm', { exact: true })).toBeHidden();
    await assessments.getByText('Vurdert risikobeskrivelse', { exact: true }).click();
    const snapshot = assessments.locator('details, div', { has: page.getByText('Vurdert risikobeskrivelse', { exact: true }) }).last();
    await expect(snapshot.getByText('manglende reservestrøm', { exact: true })).toBeVisible();
    await expect(snapshot.getByText('strømbrudd i datasenteret', { exact: true })).toBeVisible();

    await page.goto('/app/risk');
    await expect(registerRow().getByText('Lav', { exact: true })).toBeVisible();
    await page.goto(riskUrl);

    await page.getByRole('button', { name: 'Rediger' }).click();
    await fillRiskDescription(page, {
        cause: 'aldrende UPS-batterier',
        event: 'kortvarig strømbrudd',
        consequence: 'avbrudd i driften',
    });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();

    await expect(description.getByText('aldrende UPS-batterier', { exact: true })).toBeVisible();
    // History keeps what was assessed then, part by part.
    await expect(snapshot.getByText('manglende reservestrøm', { exact: true })).toBeVisible();
    await expect(snapshot.getByText('strømbrudd i datasenteret', { exact: true })).toBeVisible();
    await expect(assessments.getByText('aldrende UPS-batterier')).toHaveCount(0);
    await expect(assessments.getByText('Risikobeskrivelsen er endret etter denne vurderingen.')).toBeVisible();
    await expect(assessments.getByText('Uendret siden vurderingen', { exact: false })).toHaveCount(0);

    await page.screenshot({ path: 'test-results/risk-structured-description.png', fullPage: true });
});
