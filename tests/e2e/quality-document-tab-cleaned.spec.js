import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * A process's Dokument tab carries no structure and no relations.
 *
 * Steg, input and output were a second place to describe the run that the Flyt tab already owns,
 * so a kvalitetsleder could answer the same question twice and get two different answers. They are
 * gone from here, and so is the Relasjoner list. What has to survive is the rest of the tab: the
 * metadata form is still the way to rename a process, so the test saves one and reads it back.
 */
test('a process Dokument tab has no structure or relations and still saves', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    const link = page.getByRole('link', { name: /E2E liten prosess/ });

    if (await link.count() === 0) {
        test.skip(true, 'No seeded quality process in this environment.');
    }

    await link.first().click();
    await page.waitForURL(/\/app\/quality\/items\/\d+/);

    // The tab is the document one, and the flow is still one click away.
    await expect(page.getByRole('heading', { name: 'Styringsinformasjon' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Flyt' })).toBeVisible();

    await expect(page.getByRole('heading', { name: 'Struktur' })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Relasjoner' })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Steg' })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Input' })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Output' })).toHaveCount(0);

    // Everything else the tab is for is untouched. Exact: the tab also has a «Styrende dokumenter»
    // card, and a substring match would pass on that one even if the Dokumenter card were gone.
    await expect(page.getByRole('heading', { name: 'Dokumenter', exact: true })).toBeVisible();

    const purpose = page.getByLabel('Formål');
    const marker = `E2E opprydding ${Date.now()}`;
    await purpose.fill(marker);
    await page.getByRole('button', { name: 'Lagre' }).first().click();

    await expect(page.getByLabel('Formål')).toHaveValue(marker);
});
