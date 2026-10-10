import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * A quality editor places a control on an activity, sees it beside the activity, and removes it.
 *
 * The diagram is not asserted on beyond still being there: controls are shown in the activity panel
 * and as a count in the step list, never drawn. Permission, tenancy and "the flow is not written"
 * are owned by QualityActivityControlTest.
 */
const PROCESS = 'E2E prosess med kunnskap';
const ACTIVITY = 'Vurder anskaffelsen';

test('an activity gets a control, shows it, and loses it again', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    const link = page.getByRole('link', { name: PROCESS });

    if (await link.count() === 0) {
        test.skip(true, `No seeded quality process named "${PROCESS}" in this environment.`);
    }

    await link.first().click();
    await page.getByRole('tab', { name: 'Flyt' }).or(page.getByRole('link', { name: 'Flyt' })).first().click();
    await expect(page.getByRole('img', { name: /Prosessflyt/ })).toBeVisible();

    const title = `E2E kontroll ${Date.now()}`;

    await page.getByRole('button', { name: `Åpne kunnskapen bak ${ACTIVITY}` }).first().click();
    const panel = page.getByRole('dialog');
    const controls = panel.locator('section', { has: page.getByRole('heading', { name: 'Kontroller' }) });

    await controls.getByRole('button', { name: 'Ny kontroll' }).click();
    await controls.getByLabel('Navn på kontrollen').fill(title);
    await controls.getByLabel('Hva skal kontrolleres').fill('Innkjøpsleder bekrefter at terskelverdien er vurdert.');
    await controls.getByRole('button', { name: 'Lagre kontroll' }).click();

    // The dialog stays open on the activity and shows the control with what it checks.
    const row = controls.locator('li', { has: page.getByRole('link', { name: title }) });
    await expect(row).toBeVisible();
    await expect(row.getByText('Innkjøpsleder bekrefter at terskelverdien er vurdert.')).toBeVisible();
    await panel.screenshot({ path: 'test-results/quality-activity-controls.png' });

    // Beside the activity in the step list, as a count.
    await panel.getByRole('button', { name: 'Lukk' }).click();
    await expect(page.getByRole('button', { name: /^\d+ kontroll(er)?$/ }).first()).toBeVisible();
    await page.screenshot({ path: 'test-results/quality-activity-controls-steps.png', fullPage: true });

    // Removed again; the control itself stays in the register.
    await page.getByRole('button', { name: `Åpne kunnskapen bak ${ACTIVITY}` }).first().click();
    page.once('dialog', (dialog) => dialog.accept());
    await controls.getByRole('button', { name: `Fjern kontrollen ${title} fra aktiviteten` }).click();
    await expect(controls.getByRole('link', { name: title })).toHaveCount(0);
});

test('"Rediger steg" shows the activity\'s controls and leads to adding one', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    const link = page.getByRole('link', { name: PROCESS });

    if (await link.count() === 0) {
        test.skip(true, `No seeded quality process named "${PROCESS}" in this environment.`);
    }

    await link.first().click();
    await page.getByRole('tab', { name: 'Flyt' }).or(page.getByRole('link', { name: 'Flyt' })).first().click();
    await expect(page.getByRole('img', { name: /Prosessflyt/ })).toBeVisible();

    const title = `E2E kontroll fra steget ${Date.now()}`;
    const dialog = page.getByRole('dialog');
    const stepControls = dialog.locator('section', { has: page.getByRole('heading', { name: /^Kontroller/ }) });

    // From the step, "Legg til kontroll" opens the activity panel with its form already showing.
    await page.getByRole('button', { name: `Rediger steget ${ACTIVITY}` }).first().click();
    await expect(dialog.getByRole('heading', { name: 'Rediger steg' })).toBeVisible();
    await expect(dialog.getByRole('button', { name: /Kunnskap fra steget/ })).toBeVisible();
    await stepControls.getByRole('button', { name: /Legg til kontroll/ }).click();

    await dialog.getByLabel('Navn på kontrollen').fill(title);
    await dialog.getByLabel('Hva skal kontrolleres').fill('Kontrolleres fra Rediger steg.');
    await dialog.getByRole('button', { name: 'Lagre kontroll' }).click();
    await expect(dialog.getByRole('link', { name: title })).toBeVisible();
    await dialog.getByRole('button', { name: 'Lukk' }).click();

    // Back in "Rediger steg": the control is listed, with a count, and can be opened from there.
    await page.getByRole('button', { name: `Rediger steget ${ACTIVITY}` }).first().click();
    await expect(stepControls.getByRole('heading', { name: /\d+ kontroll(er)?/ })).toBeVisible();
    await expect(stepControls.getByRole('link', { name: title })).toBeVisible();
    await dialog.screenshot({ path: 'test-results/quality-step-edit-controls.png' });

    // Cleaned up through the panel, which is where controls are removed.
    await dialog.getByRole('button', { name: /Kunnskap fra steget/ }).click();
    page.once('dialog', (confirm) => confirm.accept());
    await dialog.getByRole('button', { name: `Fjern kontrollen ${title} fra aktiviteten` }).click();
    await expect(dialog.getByRole('link', { name: title })).toHaveCount(0);
});
