import { test, expect } from '@playwright/test';
import { loginAs, USER } from './helpers/auth.js';

/**
 * The live-search filter panel starts closed — the results are the point of the page — and folds
 * at two independent levels: the CPV chips on their own, and the whole panel separately. Both are
 * presentation: a collapsed panel must still filter, and reopening must return every value.
 */

const LIVE_SEARCH = '/app/notices?mode=live';

test.beforeEach(async ({ page }) => {
    await loginAs(page, USER.email, USER.password);
});

async function collapseToggle(page) {
    return page.getByRole('button', { name: /Skjul filtre|Vis filtre/ });
}

test('the panel starts closed, offering to show the filters', async ({ page }) => {
    await page.goto(LIVE_SEARCH);

    const toggle = await collapseToggle(page);

    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(toggle).toHaveText(/Vis filtre/);
    await expect(page.locator('#live-filter-panel-body')).toBeHidden();
});

test('the closed header still names the active watch list', async ({ page }) => {
    await page.goto(LIVE_SEARCH);

    const toggle = await collapseToggle(page);
    const header = page.locator('section', { has: toggle }).first();

    // Nobody should have to open the panel just to see what is driving the results.
    await expect(header).toContainText(/Bevakningslister|Watch lists/i);
});

test('opening shows the filters and closing hides them again', async ({ page }) => {
    await page.goto(LIVE_SEARCH);

    const toggle = await collapseToggle(page);
    const body = page.locator('#live-filter-panel-body');

    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(toggle).toHaveText(/Skjul filtre/);
    await expect(body).toBeVisible();
    await expect(page.getByRole('textbox', { name: /Organisasjonsnavn|Organisation name/i }).first()).toBeVisible();

    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(body).toBeHidden();
});

test('a filter value survives closing and reopening the panel', async ({ page }) => {
    await page.goto(LIVE_SEARCH);

    const toggle = await collapseToggle(page);
    await toggle.click();

    const organization = page.getByRole('textbox', { name: /Organisasjonsnavn|Organisation name/i }).first();
    await organization.fill('Testetaten');

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

    await (await collapseToggle(page)).click();

    // CPV now lives in its own section below the search action, so the row holds the short filters.
    const organisation = page.getByRole('textbox', { name: /Organisasjonsnavn|Organisation name/i }).first();
    const keyword = page.getByRole('textbox', { name: /Nøkkelord|Keyword/i }).first();

    const [organisationBox, keywordBox] = await Promise.all([
        organisation.boundingBox(),
        keyword.boundingBox(),
    ]);

    expect(Math.abs(keywordBox.y - organisationBox.y), 'keyword input aligns with the organisation input').toBeLessThanOrEqual(2);
});

test('the search action comes before the CPV section', async ({ page }) => {
    await page.goto(LIVE_SEARCH);

    await (await collapseToggle(page)).click();

    const search = page.getByRole('button', { name: /^Søk$/ }).first();
    const cpvLabel = page.getByText('CPV', { exact: true }).first();

    const [searchBox, cpvBox] = await Promise.all([search.boundingBox(), cpvLabel.boundingBox()]);

    expect(searchBox, 'the search button renders').not.toBeNull();
    expect(cpvBox, 'the CPV section renders').not.toBeNull();
    expect(searchBox.y, 'search sits above CPV').toBeLessThan(cpvBox.y);
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
        await expect(page.locator('#live-filter-panel-body')).toBeHidden();

        const section = page.locator('section', { has: toggle }).first();
        const box = await section.boundingBox();

        expect(box.height, `collapsed filter panel is compact on ${viewport.name}`).toBeLessThan(200);
        expect(box.x, `collapsed filter panel fits ${viewport.name}`).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(viewport.width + 1);
    }
});
