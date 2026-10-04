import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';
import { cleanUpRiskE2eData, fillRiskDescription, riskE2eSuffix } from './helpers/risk.js';

const suffix = riskE2eSuffix();
cleanUpRiskE2eData(suffix);

/**
 * Tiltak on a risk, end to end: a new action with a responsible person and a deadline, edited,
 * marked as completed with an outcome note, and reopened. Permissions and tenancy are owned by
 * RiskTreatmentActionTest.
 */
test('a risk gets an action that is edited, completed and reopened', async ({ page }) => {
    const areaName = `E2E Lønn ${suffix}`;
    const roleName = `E2E Risiko og tiltak ${suffix}`;
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

    const panel = page.locator('section', { has: page.getByRole('heading', { name: 'Tiltak', exact: true }) });
    await expect(panel.getByText('Ingen tiltak er registrert på denne risikoen.')).toBeVisible();

    // Nytt tiltak — with a deadline already passed, so it shows as Forfalt.
    await panel.getByRole('button', { name: 'Nytt tiltak' }).click();
    // The result belongs to completing an action, not to creating one.
    await expect(page.locator('#risk-action-new-note')).toHaveCount(0);
    // Ansvarlig is required — marked, and refused in Norwegian when left out.
    await expect(panel.locator('label[for="risk-action-new-owner"]')).toContainText('*');
    await page.locator('#risk-action-new-title').fill('Innfør fire-øyne-kontroll');
    await page.locator('#risk-action-new-due').fill('2026-01-15');
    await panel.getByRole('button', { name: 'Lagre tiltak' }).click();
    await expect(panel.getByText('Ansvarlig må fylles ut.')).toBeVisible();
    await expect(page.getByText('The owner user id field is required.')).toHaveCount(0);
    await page.locator('#risk-action-new-title').fill('Innfør fire-øyne-kontroll');
    await page.locator('#risk-action-new-owner').selectOption({ index: 1 });
    await page.locator('#risk-action-new-due').fill('2026-01-15');
    await panel.getByRole('button', { name: 'Lagre tiltak' }).click();
    await expect(page.getByText('Tiltaket er registrert.')).toBeVisible();

    const item = panel.locator('li', { hasText: 'Innfør fire-øyne-kontroll' });
    await expect(item.getByText('Åpen', { exact: true })).toBeVisible();
    await expect(item.getByText('Forfalt', { exact: true })).toBeVisible();

    // Rediger — move the deadline forward; no longer overdue.
    await item.getByRole('button', { name: 'Rediger' }).click();
    const editTitle = panel.locator('input[id^="risk-action-"][id$="-title"]');
    await editTitle.fill('Innfør fire-øyne-kontroll på lønnskjøring');
    await panel.locator('input[id^="risk-action-"][id$="-due"]').fill('2099-12-31');
    await panel.getByRole('button', { name: 'Lagre tiltak' }).click();
    await expect(page.getByText('Tiltaket er oppdatert.')).toBeVisible();

    const edited = panel.locator('li', { hasText: 'Innfør fire-øyne-kontroll på lønnskjøring' });
    await expect(edited.getByText('Forfalt', { exact: true })).toHaveCount(0);
    await expect(edited.getByText('Frist 31.12.2099')).toBeVisible();

    // Marker som fullført — with an outcome note.
    await edited.getByRole('button', { name: 'Marker som fullført' }).click();
    await edited.locator('textarea').fill('Innført fra oktober.');
    await edited.getByRole('button', { name: 'Fullfør tiltak' }).click();
    await expect(page.getByText('Tiltaket er markert som fullført.')).toBeVisible();

    const completed = panel.locator('div', { has: page.getByRole('heading', { name: 'Fullførte tiltak' }) })
        .locator('li', { hasText: 'Innfør fire-øyne-kontroll på lønnskjøring' });
    await expect(completed.getByText('Fullført', { exact: true })).toBeVisible();
    await expect(completed.getByText('Innført fra oktober.')).toBeVisible();
    await page.screenshot({ path: 'test-results/risk-treatment-completed.png', fullPage: true });

    // Gjenåpne — back among the open actions.
    await completed.getByRole('button', { name: 'Gjenåpne' }).click();
    await expect(page.getByText('Tiltaket er gjenåpnet.')).toBeVisible();
    const reopened = panel.locator('div', { has: page.getByRole('heading', { name: 'Åpne tiltak' }) })
        .locator('li', { hasText: 'Innfør fire-øyne-kontroll på lønnskjøring' });
    await expect(reopened.getByText('Åpen', { exact: true })).toBeVisible();
    // The earlier result stays, as what came of the last attempt.
    await expect(reopened.getByText('Tidligere resultat:')).toBeVisible();
    await expect(reopened.getByText('Innført fra oktober.')).toBeVisible();
    await expect(panel.getByRole('heading', { name: 'Fullførte tiltak' })).toHaveCount(0);
    await page.screenshot({ path: 'test-results/risk-treatment-reopened.png', fullPage: true });
});
