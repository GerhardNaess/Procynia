import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * Evidence on a control's own page: added with a name and a description, shown, and removed.
 * «Fjern» takes it out of the list; the history is kept by itself (A29) and is one click away under
 * «Historikk». Permissions, tenancy, history and the optional file are owned by
 * QualityActivityControlTest and QualityEvidenceWithdrawalTest.
 *
 * On the seeded standing control (E2ETestSeeder, E2E-KONTROLL), because the history stays: a control
 * another spec created would keep it too.
 */
test('evidence is added to a control and removed, and the history is kept', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality?tab=controls');

    await page.getByRole('link', { name: 'E2E fast kontroll' }).first().click();

    const evidence = page.locator('section', { has: page.getByRole('heading', { name: 'Evidens', exact: true }) });
    await expect(evidence).toBeVisible();

    const title = `E2E evidens ${Date.now()}`;
    await evidence.getByLabel('Navn').fill(title);
    await evidence.getByLabel('Beskrivelse').fill('Signert skjema ligger i styreportalen.');
    await evidence.getByRole('button', { name: 'Legg til evidens' }).click();

    await expect(evidence.getByText(title)).toBeVisible();
    await page.screenshot({ path: 'test-results/quality-control-evidence.png', fullPage: true });

    page.once('dialog', (dialog) => dialog.accept());
    await evidence.getByRole('button', { name: `Fjern – ${title}` }).click();
    await expect(evidence.getByText(title)).toHaveCount(0);

    // Kept by itself, and there when asked for.
    await evidence.locator('summary', { hasText: /^Historikk \(\d+ fjernet\)$/ }).click();
    await expect(evidence.getByText(title)).toBeVisible();
    await expect(evidence.locator('li', { hasText: title }).getByText(/Fjernet \d{4}-\d{2}-\d{2} av /)).toBeVisible();
});
