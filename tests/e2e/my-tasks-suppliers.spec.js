import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { DESKTOP } from './helpers/readability.js';
import { answerCriticality, cleanUpSupplierE2eData, SUPPLIER_E2E_PASSWORD, supplierE2eSuffix, supplierFixture } from './helpers/suppliers.js';

const suffix = supplierE2eSuffix();
cleanUpSupplierE2eData(suffix);

/**
 * Punkt 6: Leverandører in the bell and in «Mine oppgaver», end to end, in a GRC customer of the
 * run's own. Who is told, dedupe, rollback, access and tenant isolation are PHP's
 * (SupplierOwnerNotificationTest, InfoCenterSupplierTaskTest); this is the journey a person takes.
 *
 * The supplier manager registers a Kritisk supplier with a colleague as intern ansvarlig. The
 * colleague finds «Du er tildelt ansvar…» in the bell, marked as coming from Leverandører, and it
 * opens the supplier. Oppfølging opens on «Mine oppgaver», where the supplier is one task with its
 * reason — and, since the colleague may only read suppliers, it says they lack the permission to act.
 * Reading the notification leaves the task where it was.
 */
test('a new intern ansvarlig is told in the bell and finds the supplier under Mine oppgaver', async ({ page }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const colleagueEmail = `e2e.lev.${suffix.toLowerCase()}.kollega@procynia.test`;
    const name = `Stangeland Maskin ${suffix}`;

    // The manager registers the supplier and hands it to the colleague.
    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/supplier-management');
    await page.getByRole('button', { name: 'Registrer leverandør' }).click();
    await page.locator('#supplier-name').fill(name);
    await page.locator('#supplier-category').selectOption({ label: 'IT og skytjenester' });
    await page.locator('#supplier-deliverable').fill('Maskinutleie');
    await page.locator('#supplier-owner').selectOption({ label: person.colleague_name });
    await answerCriticality(page, 'Kritisk', ['supports_critical_delivery']);
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/supplier-management\/\d+$/);
    const supplierPath = new URL(page.url()).pathname;

    // The manager made the choice and is told nothing.
    await expect(page.getByRole('button', { name: 'Åpne varsler' })).not.toContainText('1');

    await page.context().clearCookies();
    await loginAs(page, colleagueEmail, SUPPLIER_E2E_PASSWORD);

    // The bell: one unread message, from Leverandører, naming the supplier.
    await page.goto('/app/info-center');
    await page.getByRole('button', { name: 'Åpne varsler' }).click();
    const message = page.getByRole('button', { name: 'Åpne varsel: Ansvar for leverandør' });
    await expect(message).toBeVisible();
    await expect(message).toContainText(`Du er tildelt ansvar for leverandøren ${name}.`);
    await expect(message).toContainText('Leverandører');

    // Oppfølging opens on «Mine oppgaver»: one task for the supplier, with its reason, and the
    // missing permission said out loud rather than the task hidden.
    await expect(page.getByRole('heading', { name: 'Mine oppgaver', level: 2 })).toBeVisible();
    const task = page.getByTestId('info-center-supplier-task').filter({ hasText: name });
    await expect(task).toHaveCount(1);
    await expect(task).toContainText('Ikke vurdert');
    await expect(task.getByTestId('info-center-supplier-task-missing-permission')).toBeVisible();
    await expect(page.getByTestId('info-center-my-tasks-no_due')).toContainText(name);
    // This customer holds GRC, not Anbud: Oppfølging is «Mine oppgaver» alone, without the Anbud
    // aksjon panels and views that could only ever be empty.
    await expect(page.getByTestId('info-center-panels').getByRole('link')).toHaveCount(1);
    await expect(page.getByText('Venter på svar', { exact: true })).toHaveCount(0);
    await expect(page.getByTestId('info-center-secondary-views')).toHaveCount(0);

    // Opening the message goes to the supplier and marks it read; the task stays.
    await message.click();
    await page.waitForURL((url) => url.pathname === supplierPath);
    await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible();

    await page.goto('/app/info-center');
    await expect(page.getByTestId('info-center-supplier-task').filter({ hasText: name })).toHaveCount(1);

    // And the task opens the supplier too.
    await page.getByTestId('info-center-supplier-task').filter({ hasText: name }).getByRole('link', { name: 'Åpne leverandør' }).click();
    await page.waitForURL((url) => url.pathname === supplierPath);

    // The help explains the page this customer has — «Mine oppgaver» — and none of the Anbud views.
    await page.goto('/app/info-center');
    await page.getByRole('button', { name: 'Hjelp', exact: true }).click();
    const help = page.getByRole('dialog', { name: 'Oppfølging' });
    await expect(help).toBeVisible();
    await expect(help).toContainText('Oppfølging viser oppgavene som er tildelt deg, samlet på ett sted.');
    await expect(help.getByRole('heading', { name: 'Mine oppgaver', exact: true })).toBeVisible();
    for (const anbud of ['Venter på svar', 'Opprettet av meg', 'Innkommende', 'Frister innen 7 dager', 'Arbeidsliste', 'aksjon']) {
        await expect(help).not.toContainText(anbud);
    }
    await page.keyboard.press('Escape');
    await expect(help).toHaveCount(0);

    // At phone width the list does not scroll sideways.
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/app/info-center');
    await expect(page.getByTestId('info-center-supplier-task')).toHaveCount(1);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
