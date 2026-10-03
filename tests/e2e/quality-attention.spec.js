import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * Oversikt opens on what needs attention, and each finding leads to the objects it found. Which
 * objects each rule finds — evidence, placement, governance, retired items, tenancy — is owned by
 * QualityAttentionTest.
 */
test('Oversikt lists what needs attention and leads to the objects', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    const panel = page.locator('section', { has: page.getByRole('heading', { name: 'Trenger oppmerksomhet' }) });
    await expect(panel).toBeVisible();

    for (const title of ['Kontroller uten evidens', 'Kontroller uten aktivitet', 'Prosesser uten styrende dokument', 'Prosesser forfalt til revisjon']) {
        await expect(panel.getByRole('heading', { name: title })).toBeVisible();
    }

    await page.screenshot({ path: 'test-results/quality-attention.png', fullPage: true });

    const show = panel.getByRole('button', { name: 'Vis' });

    if (await show.count() === 0) {
        test.skip(true, 'Nothing in this environment needs attention.');
    }

    await show.first().click();
    const target = panel.getByRole('listitem').getByRole('link').first();
    const href = await target.getAttribute('href');
    await page.screenshot({ path: 'test-results/quality-attention-open.png', fullPage: true });

    await target.click();
    await expect(page).toHaveURL(new RegExp(`${href}$`));
});
