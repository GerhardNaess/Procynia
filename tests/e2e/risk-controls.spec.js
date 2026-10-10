import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';
import { cleanUpRiskE2eData, fillRiskDescription, riskE2eSuffix } from './helpers/risk.js';

const suffix = riskE2eSuffix();
cleanUpRiskE2eData(suffix);

/**
 * Risiko → håndteres av → Kontroll, end to end: Risiko is reached from the rail, an existing
 * Kvalitet control is linked to a risk and shown there, and unlinking removes only the link — the
 * control is still in Kvalitet afterwards. Permissions and tenancy are owned by RiskControlLinkTest.
 */
test('a risk is linked to an existing Kvalitet control and unlinked again', async ({ page }) => {
    const areaName = `E2E Lønn ${suffix}`;
    const roleName = `E2E Risiko og kontroll ${suffix}`;
    const riskTitle = `E2E Feil lønnsutbetaling ${suffix}`;

    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);

    await page.goto('/app/customer-environment?tab=permissions');
    await page.getByRole('button', { name: 'Nytt fagområde' }).click();
    await page.locator('#business-area-name').fill(areaName);
    await page.getByRole('button', { name: 'Lagre fagområde' }).click();
    await expect(page.locator('tbody tr', { hasText: areaName })).toBeVisible();

    await page.getByRole('button', { name: 'Ny rolle' }).click();
    await page.locator('#customer-role-name').fill(roleName);
    await page.getByRole('checkbox', { name: 'Se kvalitetssystemet', exact: true }).check();
    await page.getByRole('checkbox', { name: 'Se risikoer', exact: true }).check();
    await page.getByRole('checkbox', { name: 'Opprette risikoer', exact: true }).check();
    await page.getByRole('checkbox', { name: 'Endre risikoer', exact: true }).check();
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

    // Risiko from the ordinary navigation.
    await page.goto('/app/dashboard');
    await page.getByRole('link', { name: 'Risiko' }).first().click();
    await page.waitForURL(/\/app\/risk$/);

    await page.getByRole('button', { name: 'Ny risiko' }).click();
    await page.locator('#risk-title').fill(riskTitle);
    await fillRiskDescription(page);
    await page.locator('#risk-area').selectOption({ label: areaName });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/risk\/risks\/\d+$/);

    const panel = page.locator('section', { has: page.getByRole('heading', { name: 'Kontroller som håndterer risikoen' }) });
    await expect(panel.getByText('Ingen kontroller er koblet til denne risikoen.')).toBeVisible();

    await panel.getByRole('button', { name: 'Koble kontroll' }).click();
    const select = page.locator('#risk-control');

    if (await select.count() === 0) {
        test.skip(true, 'No control in Kvalitet in this environment.');
    }

    const option = select.locator('option').nth(1);
    const controlId = await option.getAttribute('value');
    // The option also says where the control sits in Kvalitet («— Prosess › Aktivitet»); the link is the control alone.
    const controlLabel = (await option.textContent()).split(' — ')[0].trim();
    await select.selectOption(controlId);
    await panel.getByRole('button', { name: 'Koble', exact: true }).click();

    await expect(page.getByText('Kontrollen er koblet til risikoen.')).toBeVisible();
    const link = panel.getByRole('link', { name: controlLabel });
    await expect(link).toBeVisible();
    await expect(link).toHaveAttribute('href', new RegExp(`/app/quality/items/${controlId}$`));
    await page.screenshot({ path: 'test-results/risk-controls-linked.png', fullPage: true });

    page.once('dialog', (dialog) => dialog.accept());
    await panel.locator('li', { hasText: controlLabel }).getByRole('button', { name: 'Fjern kobling' }).click();
    await expect(page.getByText('Koblingen er fjernet. Kontrollen finnes fortsatt i Kvalitet.')).toBeVisible();
    await expect(panel.getByText('Ingen kontroller er koblet til denne risikoen.')).toBeVisible();

    // The control is still there, and its Kvalitet page says nothing about the risk.
    await page.goto(`/app/quality/items/${controlId}`);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByText(riskTitle)).toHaveCount(0);
});
