import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * A control placed on an activity shows up in the Kontroller register, and leads back to the
 * activity in the flow. What the register holds — unplaced controls, removed activities, tenancy —
 * is owned by QualityActivityControlTest.
 */
const PROCESS = 'E2E prosess med kunnskap';
const ACTIVITY = 'Vurder anskaffelsen';

test('a control placed on an activity is in the register and leads back to the activity', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    const link = page.getByRole('link', { name: PROCESS });

    if (await link.count() === 0) {
        test.skip(true, `No seeded quality process named "${PROCESS}" in this environment.`);
    }

    await link.first().click();
    await page.getByRole('link', { name: 'Flyt' }).first().click();

    const title = `E2E registerkontroll ${Date.now()}`;
    const criterion = 'Innkjøpsleder bekrefter at terskelverdien er vurdert.';

    await page.getByRole('button', { name: `Åpne kunnskapen bak ${ACTIVITY}` }).first().click();
    const controls = page.getByRole('dialog').locator('section', { has: page.getByRole('heading', { name: 'Kontroller' }) });
    await controls.getByRole('button', { name: 'Ny kontroll' }).click();
    await controls.getByLabel('Navn på kontrollen').fill(title);
    await controls.getByLabel('Hva skal kontrolleres').fill(criterion);
    await controls.getByRole('button', { name: 'Lagre kontroll' }).click();
    await expect(controls.getByRole('link', { name: title })).toBeVisible();

    // The register, not the generic document table.
    await page.goto('/app/quality?tab=controls');
    await expect(page.getByRole('heading', { name: 'Kontrollregister' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Styrende dokumenter' })).toHaveCount(0);

    const row = page.locator('tr', { has: page.getByRole('link', { name: title }) });
    await expect(row.getByText(criterion)).toBeVisible();
    await expect(row.getByText(ACTIVITY)).toBeVisible();
    await page.screenshot({ path: 'test-results/quality-control-register.png', fullPage: true });

    // The control's own page lists where it is used.
    await row.getByRole('link', { name: title }).click();
    const usedIn = page.locator('section', { has: page.getByRole('heading', { name: 'Brukes i prosessaktiviteter' }) });
    await expect(usedIn.getByText(ACTIVITY)).toBeVisible();

    // Back to the process, with the activity open.
    await usedIn.getByRole('link', { name: PROCESS }).click();
    await expect(page).toHaveURL(/[?&]activity=.*tab=flow|tab=flow.*[?&]activity=/);
    const panel = page.getByRole('dialog');
    await expect(panel.getByRole('link', { name: title })).toBeVisible();
    await page.screenshot({ path: 'test-results/quality-control-register-back.png', fullPage: true });

    // Clean up the placement; the control itself stays in the register.
    page.once('dialog', (dialog) => dialog.accept());
    await panel.getByRole('button', { name: `Fjern kontrollen ${title} fra aktiviteten` }).click();
    await expect(panel.getByRole('link', { name: title })).toHaveCount(0);

    await page.goto('/app/quality?tab=controls');
    const unplaced = page.locator('tr', { has: page.getByRole('link', { name: title }) });
    await expect(unplaced.getByText('Ikke koblet til noen aktivitet')).toBeVisible();
});
