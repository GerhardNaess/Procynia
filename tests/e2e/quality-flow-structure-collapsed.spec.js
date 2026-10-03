import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * The row-by-row structure editor opens on request, not on arrival.
 *
 * Three tables of keys, roles and dropdowns used to sit open under the diagram on every visit, so
 * the tab read as a form to fill in rather than a flow to check. It is the fallback for a
 * correction the description could not express, and this test holds it to that: the lane, node and
 * edge rows are absent until the user asks for them, and still one click away.
 */
test('the manual structure editor is behind a button', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    const link = page.getByRole('link', { name: /E2E liten prosess/ });

    if (await link.count() === 0) {
        test.skip(true, 'No seeded quality process with a flow in this environment.');
    }

    await link.first().click();
    await page.getByRole('link', { name: 'Flyt' }).first().click();
    await page.waitForURL('**tab=flow');

    // The flow itself is on screen — this is not an empty tab with nothing to edit.
    await expect(page.getByRole('heading', { name: 'Diagram' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Struktur' })).toBeVisible();

    const open = page.getByRole('button', { name: 'Rediger struktur manuelt' });
    await expect(open).toBeVisible();

    await expect(page.getByRole('heading', { name: 'Roller' })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Noder' })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Forbindelser' })).toHaveCount(0);

    // Saving and approving are decisions about the flow, not part of the editor, so they stay.
    await expect(page.getByRole('button', { name: 'Lagre struktur' })).toBeVisible();

    await open.click();

    await expect(page.getByRole('heading', { name: 'Roller' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Noder' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Forbindelser' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Legg til rolle' })).toBeVisible();

    await page.getByRole('button', { name: 'Skjul manuell redigering' }).click();

    await expect(page.getByRole('heading', { name: 'Roller' })).toHaveCount(0);
    await expect(open).toBeVisible();
});
