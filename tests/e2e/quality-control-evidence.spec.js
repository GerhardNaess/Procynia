import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * Evidence on a control's own page: added with a name and a description, shown, and withdrawn.
 * Withdrawn evidence is never deleted (A29): it moves to «Trukket tilbake» with who and when.
 * Permissions, tenancy, history and the optional file are owned by QualityActivityControlTest and
 * QualityEvidenceWithdrawalTest.
 *
 * On the seeded standing control (E2ETestSeeder, E2E-KONTROLL), because the evidence stays: a
 * control another spec created would keep it too.
 */
test('evidence is added to a control and withdrawn, and stays as history', async ({ page }) => {
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
    await expect(evidence.getByText('Signert skjema ligger i styreportalen.').first()).toBeVisible();
    await page.screenshot({ path: 'test-results/quality-control-evidence.png', fullPage: true });

    page.once('dialog', (dialog) => dialog.accept());
    await evidence.locator('li', { hasText: title }).getByRole('button', { name: /^Trekk tilbake/ }).click();
    await expect(page.getByText('Evidensen er trukket tilbake. Den står i historikken.').first()).toBeVisible();

    // Still there, apart, as withdrawn — and nothing left to do with it.
    const withdrawn = evidence.locator('div', { has: page.getByRole('heading', { name: 'Trukket tilbake', exact: true }) });
    const row = withdrawn.locator('li', { hasText: title });
    await expect(row).toBeVisible();
    await expect(row.getByText(/Trukket tilbake \d{4}-\d{2}-\d{2} av /)).toBeVisible();
    await expect(row.getByRole('button')).toHaveCount(0);
});
