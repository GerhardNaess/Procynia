import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * Evidence on a control's own page: added with a name and a description, shown, and removed again.
 * Permissions, tenancy and the optional file are owned by QualityActivityControlTest.
 */
test('evidence is added to a control and removed again', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality?tab=controls');

    const controlLink = page.locator('tr a').first();

    if (await controlLink.count() === 0) {
        test.skip(true, 'No control in the register in this environment.');
    }

    await controlLink.click();

    const evidence = page.locator('section', { has: page.getByRole('heading', { name: 'Evidens', exact: true }) });
    await expect(evidence).toBeVisible();

    const title = `E2E evidens ${Date.now()}`;
    await evidence.getByLabel('Navn').fill(title);
    await evidence.getByLabel('Beskrivelse').fill('Signert skjema ligger i styreportalen.');
    await evidence.getByRole('button', { name: 'Legg til evidens' }).click();

    await expect(evidence.getByText(title)).toBeVisible();
    await expect(evidence.getByText('Signert skjema ligger i styreportalen.')).toBeVisible();
    await page.screenshot({ path: 'test-results/quality-control-evidence.png', fullPage: true });

    page.once('dialog', (dialog) => dialog.accept());
    await evidence.locator('li', { hasText: title }).getByRole('button', { name: 'Fjern' }).click();
    await expect(evidence.getByText(title)).toHaveCount(0);
});
