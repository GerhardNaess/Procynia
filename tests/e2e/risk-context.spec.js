import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';

/**
 * Risiko → Kontekst, end to end: a risk is linked to a whole Kvalitet process and to an activity in
 * a process, the context is shown on the risk page, and removing the links leaves the process in
 * Kvalitet — whose page still says nothing about the risk. Permissions and tenancy are owned by
 * RiskQualityContextLinkTest.
 */
test('a risk is linked to a Kvalitet process and activity, shown as context, and unlinked', async ({ page }) => {
    const suffix = Math.random().toString(36).slice(2, 8).toUpperCase();
    const areaName = `E2E Kontekst ${suffix}`;
    const roleName = `E2E Risiko og kontekst ${suffix}`;
    const riskTitle = `E2E Feil i godkjenning ${suffix}`;

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

    const panel = page.locator('section', { has: page.getByRole('heading', { name: 'Kontekst', exact: true }) });
    await expect(panel.getByText('Risikoen er ikke koblet til noen prosess eller aktivitet.')).toBeVisible();

    // 1. A whole process, no activity.
    await panel.getByRole('button', { name: 'Koble prosess' }).click();
    const processSelect = page.locator('#risk-context-process');

    if (await processSelect.count() === 0) {
        test.skip(true, 'No process in Kvalitet in this environment.');
    }

    const processValues = await processSelect.locator('option').evaluateAll((options) => options.map((o) => o.value).filter(Boolean));

    // A process whose flow has at least one activity, for step 2.
    let processId = null;
    for (const value of processValues) {
        await processSelect.selectOption(value);
        if (await page.locator('#risk-context-activity option:not([disabled])').count() > 1) {
            processId = value;
            break;
        }
    }

    if (processId === null) {
        test.skip(true, 'No process with activities in Kvalitet in this environment.');
    }

    const processLabel = (await processSelect.locator(`option[value="${processId}"]`).textContent()).trim();
    await panel.getByRole('button', { name: 'Koble', exact: true }).click();
    await expect(page.getByText('Risikoen er koblet til Kvalitet.')).toBeVisible();

    const processLink = panel.getByRole('link', { name: processLabel, exact: true });
    await expect(processLink).toBeVisible();
    await expect(processLink).toHaveAttribute('href', new RegExp(`/app/quality/items/${processId}$`));
    await expect(panel.getByText('Hele prosessen', { exact: true })).toBeVisible();

    // 2. An activity in the same process.
    await panel.getByRole('button', { name: 'Koble prosess' }).click();
    await processSelect.selectOption(processId);
    const activityOption = page.locator('#risk-context-activity option:not([disabled])').nth(1);
    const activityKey = await activityOption.getAttribute('value');
    const activityText = (await activityOption.textContent()).trim();
    await page.locator('#risk-context-activity').selectOption(activityKey);
    await panel.getByRole('button', { name: 'Koble', exact: true }).click();
    await expect(page.getByText('Risikoen er koblet til Kvalitet.')).toBeVisible();

    // 3. Context: the process, and the activity under it, linking into the flow.
    const activityLink = panel.getByRole('link', { name: activityText, exact: true });
    await expect(activityLink).toBeVisible();
    await expect(activityLink).toHaveAttribute('href', new RegExp(`/app/quality/items/${processId}\\?tab=flow&activity=${encodeURIComponent(activityKey)}$`));
    await page.screenshot({ path: 'test-results/risk-context-linked.png', fullPage: true });

    // 4. Remove both links.
    page.on('dialog', (dialog) => dialog.accept());
    await panel.getByRole('button', { name: `Fjern kobling: ${processLabel}` }).click();
    await expect(page.getByText('Koblingen er fjernet. Prosessen finnes fortsatt i Kvalitet.')).toBeVisible();
    await expect(panel.getByText('Hele prosessen', { exact: true })).toHaveCount(0);
    await expect(activityLink).toBeVisible();

    await panel.getByRole('button', { name: /^Fjern kobling: / }).click();
    await expect(panel.getByText('Risikoen er ikke koblet til noen prosess eller aktivitet.')).toBeVisible();

    // The process is still there, and Kvalitet says nothing about the risk.
    await page.goto(`/app/quality/items/${processId}`);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByText(riskTitle)).toHaveCount(0);
});
