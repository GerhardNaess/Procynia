import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * The `⋯` menu on a row in the Prosesser list.
 *
 * What this is really guarding is clipping. The menu lives in a table cell inside an
 * `overflow-x-auto` wrapper, and a scroll container clips on both axes — an absolutely positioned
 * menu would be cut off at the row it belongs to, which no assertion about the DOM would notice.
 * So the test opens it and checks it is actually on screen, inside the viewport, and that the
 * confirmation names what the delete keeps.
 *
 * It deliberately stops at Avbryt. Deleting a seeded process to prove the button works would cost
 * the next run its fixture; the endpoint itself is covered in QualityItemTest.
 *
 * The row is a seeded process that has never been approved. An approved one is retired, never
 * deleted (phase 0 step 12), and its menu says so instead — the second test.
 */
test('a process row offers Slett prosess and explains what survives', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality?tab=processes');

    const trigger = page.getByRole('button', { name: 'Handlinger – E2E prosess uten struktur', exact: true });
    // The menu closes on scroll (it is fixed to where the trigger was). The row may sit below the
    // fold, so it is scrolled into view first and the scroll's events delivered — two animation
    // frames — before the click; otherwise the click's own scroll-into-view closes what it opened.
    await trigger.scrollIntoViewIfNeeded();
    await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    await trigger.click();

    const deleteItem = page.getByRole('button', { name: 'Slett prosess', exact: true });
    await expect(deleteItem).toBeVisible();

    // Not clipped by the table's scroll container: the whole button is inside the viewport.
    const box = await deleteItem.boundingBox();
    const viewport = page.viewportSize();
    expect(box).not.toBeNull();
    expect(box.width).toBeGreaterThan(0);
    expect(box.height).toBeGreaterThan(0);
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.y).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(viewport.width);
    expect(box.y + box.height).toBeLessThanOrEqual(viewport.height);

    await deleteItem.click();

    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: 'Slett prosessen?' })).toBeVisible();
    await expect(dialog.getByText('Dette slettes')).toBeVisible();
    await expect(dialog.getByText('Dette beholdes')).toBeVisible();
    await expect(dialog.getByText(/Kunnskap som allerede er produsert forsvinner ikke/)).toBeVisible();

    await dialog.getByRole('button', { name: 'Avbryt' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);

    // Nothing was deleted.
    await expect(page).toHaveURL(/\/app\/quality\?tab=processes/);
});

test('an approved process offers no delete and says to retire it instead', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality?tab=processes');

    await page.getByRole('button', { name: 'Handlinger – E2E godkjent prosess', exact: true }).click();

    await expect(page.getByText(/har en godkjent versjon og kan ikke slettes/)).toBeVisible();
    await expect(page.getByText(/Sett statusen til «Utgått»/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Slett prosess', exact: true })).toHaveCount(0);
});
