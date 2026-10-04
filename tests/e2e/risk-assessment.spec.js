import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';

/**
 * Risikovurdering, end to end: a role that may assess but not edit lets its holder register an
 * assessment — with the level previewed before saving — and a correction lands as a new entry
 * while the earlier one stays in the history.
 */
test('a fagperson with risk.assess but not risk.edit assesses a risk and keeps its history', async ({ page }) => {
    const suffix = Math.random().toString(36).slice(2, 8).toUpperCase();
    const areaName = `E2E Drift ${suffix}`;
    const roleName = `E2E Risikovurderer ${suffix}`;
    const riskTitle = `E2E Serverbrann ${suffix}`;

    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);

    await page.goto('/app/customer-environment?tab=permissions');
    await page.getByRole('button', { name: 'Nytt område' }).click();
    await page.locator('#risk-area-name').fill(areaName);
    await page.getByRole('button', { name: 'Lagre område' }).click();
    await expect(page.locator('tbody tr', { hasText: areaName })).toBeVisible();

    await page.getByRole('button', { name: 'Ny rolle' }).click();
    await page.locator('#customer-role-name').fill(roleName);
    await page.getByRole('checkbox', { name: 'Se risikoer', exact: true }).check();
    await page.getByRole('checkbox', { name: 'Opprette risikoer', exact: true }).check();
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
    await page.locator('#risk-title').fill(riskTitle);
    await page.locator('#risk-area').selectOption({ label: areaName });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/risk\/risks\/\d+$/);

    // No risk.edit: the risk itself cannot be rewritten, but it can be assessed.
    await expect(page.getByRole('button', { name: 'Rediger' })).toHaveCount(0);
    await expect(page.getByText('Risikoen er ikke vurdert ennå')).toBeVisible();

    await page.getByRole('button', { name: 'Ny vurdering' }).click();
    await page.locator('#assessment-inherent-likelihood').selectOption('4');
    await page.locator('#assessment-inherent-consequence').selectOption('4');
    const form = page.locator('form', { hasText: 'Ny risikovurdering' });
    await expect(form.getByText('Score 16')).toBeVisible();
    await expect(form.getByText('Høy', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Vurder også restrisiko' }).click();
    await page.locator('#assessment-residual-likelihood').selectOption('2');
    await page.locator('#assessment-residual-consequence').selectOption('3');
    await expect(form.getByText('Moderat', { exact: true })).toBeVisible();
    await page.locator('#assessment-rationale').fill('Sprinkleranlegg finnes, men serverrommet mangler gasslukking.');
    await page.getByRole('button', { name: 'Lagre vurdering' }).click();

    await expect(page.getByText('Vurderingen er registrert.')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Siste vurdering' })).toBeVisible();
    await expect(page.getByText('Sprinkleranlegg finnes, men serverrommet mangler gasslukking.')).toBeVisible();
    await expect(page.getByText(/Vurdert av /).first()).toBeVisible();

    // A correction is a new assessment; the first one moves into the history.
    await page.getByRole('button', { name: 'Ny vurdering' }).click();
    await page.locator('#assessment-inherent-likelihood').selectOption('3');
    await page.locator('#assessment-inherent-consequence').selectOption('4');
    await page.locator('#assessment-rationale').fill('Korrigert: hendelsesfrekvensen var overvurdert.');
    await page.getByRole('button', { name: 'Lagre vurdering' }).click();

    await expect(page.getByText('Korrigert: hendelsesfrekvensen var overvurdert.')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Tidligere vurderinger' })).toBeVisible();
    await expect(page.locator('ol li', { hasText: 'Sprinkleranlegg finnes' })).toBeVisible();
    await page.screenshot({ path: 'test-results/risk-assessment.png', fullPage: true });
});
