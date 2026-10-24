import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';
import { expectNoAxeViolations } from './helpers/a11y.js';
import { tinker } from './helpers/risk.js';

const FIXTURE = '\\Tests\\Support\\IntegrationsE2EFixture';
const SUFFIX = Math.random().toString(36).slice(2, 8).padEnd(6, '0').toUpperCase();

/*
 * Kundemiljø → Integrasjoner (D13): the System Owner gives an application Procynia has approved
 * read access, changes its roles and takes the access back. The run brings its own application and
 * read role (IntegrationsE2EFixture) and removes them afterwards.
 */
let fixture = null;

test.beforeAll(async () => {
    const { stdout } = await tinker(`echo json_encode(${FIXTURE}::seed('${SUFFIX}'));`);
    fixture = JSON.parse(stdout.trim().split('\n').pop());
});

test.afterAll(async () => {
    await tinker(`${FIXTURE}::cleanup('${SUFFIX}');`);
});

test('a System Owner gives an approved application read access, changes its roles and revokes it', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/customer-environment?tab=integrations');

    await expect(page.getByRole('navigation', { name: 'Kundemiljø' }).getByRole('button', { name: 'Integrasjoner' })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByRole('heading', { name: 'Integrasjoner', level: 2 })).toBeVisible();
    await expectNoAxeViolations(page, 'main', 'Integrasjoner');

    // Gi tilgang, with the read role.
    const available = page.locator('li', { hasText: fixture.client }).filter({ has: page.getByRole('button', { name: 'Gi tilgang' }) });
    await available.getByRole('button', { name: 'Gi tilgang' }).click();

    const grantDialog = page.getByRole('dialog', { name: `Gi ${fixture.client} tilgang` });
    await expect(grantDialog).toBeVisible();
    await grantDialog.getByRole('checkbox', { name: fixture.role }).check();
    await grantDialog.getByRole('button', { name: 'Gi tilgang' }).click();
    await expect(grantDialog).toBeHidden();

    // The grant shows its role and what the integration can read.
    const grant = page.locator(`[data-integration-grant="${fixture.client}"]`);
    await expect(grant).toBeVisible();
    await expect(grant).toContainText(fixture.role);
    await expect(grant).toContainText('Risiko: alle fagområder');
    await expect(grant).toContainText('Ikke brukt ennå');
    await expect(page.locator('li', { hasText: fixture.client }).getByText('Har tilgang')).toBeVisible();

    // Endre roller: without a role it can read nothing.
    await grant.getByRole('button', { name: 'Endre roller' }).click();
    const rolesDialog = page.getByRole('dialog', { name: `Roller for ${fixture.client}` });
    await rolesDialog.getByRole('checkbox', { name: fixture.role }).uncheck();
    await rolesDialog.getByRole('button', { name: 'Lagre' }).click();
    await expect(rolesDialog).toBeHidden();
    await expect(grant).toContainText('Ingen roller');
    await expect(grant).toContainText('Ingenting. Gi integrasjonen en rolle med leserettighet.');

    // Trekk tilbake.
    await grant.getByRole('button', { name: 'Trekk tilbake' }).click();
    const revokeDialog = page.getByRole('dialog', { name: 'Trekke tilbake tilgangen?' });
    await revokeDialog.getByRole('button', { name: 'Trekk tilbake' }).click();
    await expect(revokeDialog).toBeHidden();
    await expect(grant).toHaveCount(0);
    await expect(available.getByRole('button', { name: 'Gi tilgang' })).toBeVisible();
});
