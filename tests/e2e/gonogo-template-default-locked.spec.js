import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';
import { PHONE, sidewaysOverflow } from './helpers/readability.js';

/**
 * Vurderingsmaler: the default template's «Deaktiver» is disabled, because the server refuses to
 * deactivate the default. That refusal was only ever reachable through the disabled button, so the
 * reason was never shown. The card now says why, and the button is described by that sentence.
 * Nothing is changed by this spec.
 */
const REASON = 'Standardmalen kan ikke deaktiveres. Sett en annen mal som standard først.';

for (const viewport of [{ width: 1366, height: 900 }, PHONE]) {
    test(`the default template explains its disabled Deaktiver (${viewport.width} px)`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
        await page.goto('/app/go-no-go-templates');

        const card = page.getByRole('listitem').filter({ has: page.getByText('Standard', { exact: true }) }).first();
        const deactivate = card.getByRole('button', { name: /^Deaktiver / });

        await expect(deactivate).toBeDisabled();
        await expect(card.getByText(REASON)).toBeVisible();
        await expect(deactivate).toHaveAccessibleDescription(REASON);

        // A non-default template carries no such note.
        const others = page.getByRole('listitem').filter({ hasNot: page.getByText('Standard', { exact: true }) });
        if (await others.count() > 0) {
            await expect(others.first().getByText(REASON)).toHaveCount(0);
        }

        expect(await sidewaysOverflow(page)).toEqual([]);
    });
}

test('Vurderingsmaler stays a System Owner page', async ({ page }) => {
    await loginAs(page, USER.email, USER.password);
    const response = await page.goto('/app/go-no-go-templates');
    expect(response.status()).toBe(403);
});
