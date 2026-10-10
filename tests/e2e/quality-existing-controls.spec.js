import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';
import { DESKTOP, PHONE, expectReadable } from './helpers/readability.js';

/**
 * A control that already exists in the register is placed on an activity — from the activity in the
 * flow, and from the control's own page — without being created again, and taken off again without
 * being deleted. Rules (tenant, retired, working version, no copy) are owned by
 * QualityActivityControlTest; this checks that both entry points reach them and the views refresh.
 */
const PROCESS = 'E2E prosess med kunnskap';
const ACTIVITY = 'Vurder anskaffelsen';

async function createControl(page, title) {
    const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    const created = await page.request.post('/app/quality/items', {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'text/html' },
        form: { quality_type: 'control', title },
    });
    expect(created.ok()).toBeTruthy();
}

test('an existing control is chosen from the activity and removed from its own page', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    const processLink = page.getByRole('link', { name: PROCESS });

    if (await processLink.count() === 0) {
        test.skip(true, `No seeded quality process named "${PROCESS}" in this environment.`);
    }

    const title = `E2E eksisterende kontroll ${Date.now()}`;
    await createControl(page, title);

    await page.goto('/app/quality');
    await processLink.first().click();
    await page.getByRole('link', { name: 'Flyt' }).first().click();
    await page.getByRole('button', { name: `Åpne kunnskapen bak ${ACTIVITY}` }).first().click();

    const panel = page.getByRole('dialog');
    const controls = panel.locator('section', { has: page.getByRole('heading', { name: 'Kontroller' }) });

    await controls.getByRole('button', { name: 'Velg eksisterende kontroll' }).click();
    const picker = controls.getByLabel('Eksisterende kontroller');
    await picker.fill(title);
    await controls.getByRole('option', { name: new RegExp(title) }).click();
    await controls.getByRole('button', { name: 'Knytt til aktiviteten' }).click();

    // The dialog stays open on the activity and lists the control at once.
    await expect(controls.getByRole('link', { name: title })).toBeVisible();
    await panel.screenshot({ path: 'test-results/quality-existing-control-activity.png' });

    // The control's page shows where it is used, and takes it off there.
    await controls.getByRole('link', { name: title }).click();
    await page.waitForURL(/\/app\/quality\/items\/\d+$/);
    const placements = page.getByTestId('control-placements');
    await expect(placements).toContainText(ACTIVITY);

    page.once('dialog', (dialog) => dialog.accept());
    await placements.getByRole('button', { name: /Fjern kontrollen fra/ }).click();
    await expect(placements).toContainText('Kontrollen er ikke koblet til noen prosessaktivitet.');
    await expect(page.getByRole('heading', { name: title })).toBeVisible();
});

test('a control without an activity is linked from its own page and leaves the attention list', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    if (await page.getByRole('link', { name: PROCESS }).count() === 0) {
        test.skip(true, `No seeded quality process named "${PROCESS}" in this environment.`);
    }

    const title = `E2E kontroll uten aktivitet ${Date.now()}`;
    await createControl(page, title);

    // Oversikt lists it under «Kontroller uten aktivitet».
    await page.goto('/app/quality');
    const finding = page.locator('li', { has: page.getByRole('heading', { name: 'Kontroller uten aktivitet', exact: true }) });
    await finding.getByRole('button', { name: 'Vis' }).click();
    await finding.getByRole('link', { name: title }).click();
    await page.waitForURL(/\/app\/quality\/items\/\d+$/);
    const controlUrl = page.url();

    const placements = page.getByTestId('control-placements');
    const picker = placements.getByLabel('Knytt til aktivitet');
    await picker.fill(`${PROCESS} ${ACTIVITY}`);
    await placements.getByRole('option', { name: new RegExp(`${PROCESS} › ${ACTIVITY}`) }).first().click();
    await placements.getByRole('button', { name: 'Knytt til', exact: true }).click();
    await expect(placements.getByRole('link', { name: PROCESS })).toBeVisible();
    await expect(placements).toContainText(ACTIVITY);
    await expectReadable(page, 'quality-existing-controls', 'control');
    await page.setViewportSize(DESKTOP);

    // Oversikt no longer lists it under the finding.
    await page.goto('/app/quality');
    const show = finding.getByRole('button', { name: 'Vis' });
    if (await show.count() > 0) {
        await show.click();
    }
    await expect(finding.getByRole('link', { name: title })).toHaveCount(0);

    // Clean up: off the activity again, the control stays.
    await page.goto(controlUrl);
    page.once('dialog', (dialog) => dialog.accept());
    await placements.getByRole('button', { name: /Fjern kontrollen fra/ }).click();
    await expect(placements).toContainText('Kontrollen er ikke koblet til noen prosessaktivitet.');
});

test('the activity picker fits a phone', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    if (await page.getByRole('link', { name: PROCESS }).count() === 0) {
        test.skip(true, `No seeded quality process named "${PROCESS}" in this environment.`);
    }

    const title = `E2E kontroll mobil ${Date.now()}`;
    await createControl(page, title);
    await page.goto('/app/quality?tab=controls');
    await page.getByRole('link', { name: title }).first().click();
    await page.waitForURL(/\/app\/quality\/items\/\d+$/);

    await page.setViewportSize(PHONE);
    const placements = page.getByTestId('control-placements');
    await placements.getByLabel('Knytt til aktivitet').click();
    await expect(placements.getByRole('listbox')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(PHONE.width);
    await page.screenshot({ path: 'test-results/quality-existing-control-phone.png', fullPage: true });
});
