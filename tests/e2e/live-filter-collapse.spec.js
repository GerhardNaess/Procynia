import { test, expect } from '@playwright/test';
import { loginAs, USER } from './helpers/auth.js';

/**
 * The live-search filter panel folds at two independent levels: the CPV chips on their own, and
 * the whole panel separately. Both are presentation — a collapsed panel must still filter.
 */

const LIVE_SEARCH = '/app/notices?mode=live';

test.beforeEach(async ({ page }) => {
    await loginAs(page, USER.email, USER.password);
});

async function collapseToggle(page) {
    return page.getByRole('button', { name: /Skjul filtre|Vis filtre/ });
}

test('the whole filter panel collapses and still names the active watch list', async ({ page }) => {
    await page.goto(LIVE_SEARCH);

    const toggle = await collapseToggle(page);
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');

    const body = page.locator('#live-filter-panel-body');
    await expect(body).toBeVisible();

    await toggle.click();

    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(body).toBeHidden();

    // The watch list line survives the collapse, so nobody has to reopen the panel to see it.
    const header = page.locator('section', { has: toggle }).first();
    await expect(header).toContainText(/Bevakningslister|Watch lists/i);

    await toggle.click();
    await expect(body).toBeVisible();
});

test('collapsing the panel keeps the filter values', async ({ page }) => {
    await page.goto(LIVE_SEARCH);

    const organization = page.getByRole('textbox', { name: /Organisasjonsnavn|Organisation name/i }).first();
    await organization.fill('Testetaten');

    const toggle = await collapseToggle(page);
    await toggle.click();
    await expect(page.locator('#live-filter-panel-body')).toBeHidden();

    await toggle.click();
    await expect(organization).toHaveValue('Testetaten');
});

/**
 * The CPV chip level is not driven here: the selector's dropdown closes on a 120 ms blur timer that
 * does not survive automated re-opening, and the CPV catalog is imported rather than seeded, so
 * this environment has nothing to select. That level is covered by filterPanelLogic.test.js
 * (behaviour) and its source guards (real button, aria-expanded, no filter mutation).
 */
test('the filter fields line up across the row', async ({ page }) => {
    await page.goto(LIVE_SEARCH);

    // The CPV label row carries a count and a show/hide toggle. It must still occupy the same
    // height as a plain label, or its input drops below its neighbours'.
    const organisation = page.getByRole('textbox', { name: /Organisasjonsnavn|Organisation name/i }).first();
    const keyword = page.getByRole('textbox', { name: /Nøkkelord|Keyword/i }).first();
    // The CPV combobox sits inside a padded chip container, so compare that container — it is the
    // field box, the same thing the plain inputs are.
    const cpv = page.getByRole('combobox', { name: 'CPV' })
        .locator('xpath=ancestor::div[contains(@class, "rounded-xl")][1]');

    const [organisationBox, cpvBox, keywordBox] = await Promise.all([
        organisation.boundingBox(),
        cpv.boundingBox(),
        keyword.boundingBox(),
    ]);

    expect(Math.abs(cpvBox.y - organisationBox.y), 'CPV input aligns with the organisation input').toBeLessThanOrEqual(2);
    expect(Math.abs(keywordBox.y - organisationBox.y), 'keyword input aligns with the organisation input').toBeLessThanOrEqual(2);
});

test('the collapsed filter panel is short on every viewport', async ({ page }) => {
    for (const viewport of [
        { width: 1440, height: 900, name: 'desktop' },
        { width: 1280, height: 720, name: 'narrow laptop' },
        { width: 390, height: 844, name: 'mobile' },
    ]) {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await page.goto(LIVE_SEARCH);

        const toggle = await collapseToggle(page);
        await toggle.click();
        await expect(page.locator('#live-filter-panel-body')).toBeHidden();

        const section = page.locator('section', { has: toggle }).first();
        const box = await section.boundingBox();

        expect(box.height, `collapsed filter panel is compact on ${viewport.name}`).toBeLessThan(200);
        expect(box.x, `collapsed filter panel fits ${viewport.name}`).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(viewport.width + 1);
    }
});
