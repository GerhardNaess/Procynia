import { test, expect } from '@playwright/test';
import { loginAs, USER } from './helpers/auth.js';

test.beforeEach(async ({ page }) => {
    await loginAs(page, USER.email, USER.password);
});

const labels = async (locator) => (await locator.allTextContents())
    .map((text) => text.trim())
    .filter(Boolean);

test('notices page loads for authenticated customer user', async ({ page }) => {
    const response = await page.goto('/app/notices');

    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/app\/notices/);
    // App navigation header contains the Procynia logo image
    await expect(page.locator('img[alt="Procynia"]')).toBeVisible();
});

/**
 * Three levels, the same three in every module.
 *
 * The rail picks a module, the header names what is inside it, the page carries what is inside
 * that. Wiki worked this way from the start; Anbud used to nest its four work areas in the rail
 * instead, which made it the only module with a second level in the left-hand column. The source
 * guards in resources/js/Layouts/headerNavigation.test.js check how the lists are built — this
 * checks what a person actually sees.
 */
test('Anbud and Wiki have the same navigation shape', async ({ page }) => {
    await page.goto('/app/notices');

    const rail = page.getByTestId('module-sidebar');

    // The rail is a module picker: no module nests a second level inside it.
    expect(await rail.locator('ul ul').count()).toBe(0);
    await expect(rail.locator('[aria-current="page"]')).toHaveText('Anbud');

    await expect(page.getByTestId('module-navigation').locator('a, span'))
        .toHaveText(['Bid Status', 'Kunngjøringer', 'Saksliste', 'Besvarelse', 'Konkurrenter']);
    await expect(page.getByTestId('page-navigation').locator('a, span'))
        .toHaveText(['Live søk', 'Varsler', 'Watch lists']);

    // Wiki has no work-area level, so its tabs stay the module navigation and it gains no third row.
    await page.goto('/app/wiki');
    await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText('Wiki');
    expect(await labels(page.getByTestId('module-navigation').locator('a, span')))
        .toEqual(['Kildedokumenter', 'Kjøringer', 'Wiki-sider', 'Grafvisning']);
    await expect(page.getByTestId('page-navigation')).toHaveCount(0);
});

test('both navigation levels follow the URL', async ({ page }) => {
    await page.goto('/app/notices');
    await expect(page.getByTestId('module-navigation').locator('[aria-current="page"]')).toHaveText('Kunngjøringer');
    await expect(page.getByTestId('page-navigation').locator('[aria-current="page"]')).toHaveText('Live søk');

    // A tab change moves the page level only; the area above it stays Kunngjøringer.
    await page.goto('/app/notices?tab=alerts');
    await expect(page.getByTestId('module-navigation').locator('[aria-current="page"]')).toHaveText('Kunngjøringer');
    await expect(page.getByTestId('page-navigation').locator('[aria-current="page"]')).toHaveText('Varsler');

    // An area change moves both.
    await page.goto('/app/notices?mode=saved');
    await expect(page.getByTestId('module-navigation').locator('[aria-current="page"]')).toHaveText('Saksliste');
});

test('a module without work areas shows neither row — Hjem', async ({ page }) => {
    await page.goto('/app/dashboard');

    await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText('Hjem');
    await expect(page.getByTestId('module-navigation')).toHaveCount(0);
    await expect(page.getByTestId('page-navigation')).toHaveCount(0);
});

/**
 * Hjem and Anbud used to be the same page under two names: /app/dashboard served the bid cockpit,
 * so clicking Hjem put you inside Anbud's numbers. The cockpit is unchanged and still exists, as
 * Bid Status inside Anbud. What these check is that the two stay separate.
 */
test('Hjem is a cross-module dashboard, not the bid cockpit', async ({ page }) => {
    await page.goto('/app/dashboard');

    await expect(page.getByTestId('home-module-cards')).toBeVisible();
    await expect(page.getByTestId('home-module-wiki')).toBeVisible();
    await expect(page.getByTestId('home-module-tenders')).toBeVisible();
    await expect(page.getByTestId('home-module-quality')).toBeVisible();

    // Nothing Anbud-specific: no pipeline, no bid-status sections, and no module navigation.
    const body = (await page.evaluate(() => document.body.innerText)).toLowerCase();
    for (const bidWidget of ['bid status', 'pipeline', 'krever oppfølging', 'resultater']) {
        expect(body, `"${bidWidget}" belongs to Anbud, not Hjem`).not.toContain(bidWidget);
    }
    await expect(page.getByTestId('module-navigation')).toHaveCount(0);
});

test('each module card opens its own module', async ({ page }) => {
    for (const [card, url, railLabel] of [
        ['home-module-wiki', /\/app\/wiki/, 'Wiki'],
        ['home-module-tenders', /\/app\/notices\?mode=saved/, 'Anbud'],
        ['home-module-quality', /\/app\/quality/, 'Kvalitet'],
    ]) {
        await page.goto('/app/dashboard');
        await page.getByTestId(card).click();

        await expect(page).toHaveURL(url);
        await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText(railLabel);
    }
});

test('Kvalitet is shown as being built, with no borrowed numbers', async ({ page }) => {
    await page.goto('/app/dashboard');

    await expect(page.getByTestId('home-module-quality-state')).toHaveText('Under arbeid');
    expect(await page.getByTestId('home-module-quality').locator('dl').count()).toBe(0);
});

test('the bid cockpit lives in Anbud now, as Bid Status', async ({ page }) => {
    await page.goto('/app/bid-status');

    await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText('Anbud');
    await expect(page.getByTestId('module-navigation').locator('[aria-current="page"]')).toHaveText('Bid Status');
    await expect(page.getByRole('heading', { level: 2 }).filter({ hasText: 'Krever oppfølging' })).toBeVisible();
});

test('both rows survive a phone, and nothing is pushed off the edge', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/app/notices');

    await expect(page.getByTestId('module-navigation')).toBeVisible();
    await expect(page.getByTestId('page-navigation')).toBeVisible();

    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );

    expect(overflow).toBeLessThanOrEqual(0);
});

test('dashboard page loads for authenticated customer user', async ({ page }) => {
    const response = await page.goto('/app/dashboard');

    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/app\/dashboard/);
});

test('AI workspace page loads without triggering external AI calls', async ({ page }) => {
    // The /app/ai index page only lists existing cases — no OpenAI calls are made
    const response = await page.goto('/app/ai');

    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/app\/ai/);
    await expect(page.locator('img[alt="Procynia"]')).toBeVisible();
});

test('regular customer user is blocked from the billing page', async ({ page }) => {
    const response = await page.goto('/app/billing');

    // BillingController: abort_unless($user->isSystemOwner(), 403)
    expect(response?.status()).toBe(403);
});

/**
 * The rail folds down to icons, and the page gets the space back.
 *
 * Three things have to survive the fold, and all three are checked below rather than assumed:
 * the selected module stays obviously selected, the planned modules stay obviously inert, and a
 * person who can no longer read a label can still find out what an icon is.
 */
test('the rail lists the modules in product order', async ({ page }) => {
    await page.goto('/app/dashboard');

    const rail = page.getByTestId('module-sidebar');

    expect(await labels(rail.locator('ul').first().locator('a')))
        .toEqual(['Hjem', 'Wiki', 'Anbud', 'Kvalitet']);
});

test('each of the four available modules is reachable from the rail', async ({ page }) => {
    await page.goto('/app/dashboard');

    for (const [key, label, url] of [
        ['module-wiki', 'Wiki', /\/app\/wiki/],
        ['module-tenders', 'Anbud', /\/app\/notices/],
        ['module-quality', 'Kvalitet', /\/app\/quality/],
        ['module-home', 'Hjem', /\/app\/dashboard/],
    ]) {
        await page.getByTestId(key).click();
        await expect(page).toHaveURL(url);
        await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText(label);
    }
});

test('collapsing the rail hands its width to the page, and expanding gives it back', async ({ page }) => {
    await page.goto('/app/wiki');

    const rail = page.getByTestId('module-rail');
    const sidebar = page.getByTestId('module-sidebar');
    const toggle = page.getByTestId('module-sidebar-toggle');

    await expect(sidebar).toHaveAttribute('data-collapsed', 'false');
    const expandedRail = (await rail.boundingBox()).width;
    const expandedMain = (await page.locator('main').boundingBox()).width;

    await toggle.click();

    await expect(sidebar).toHaveAttribute('data-collapsed', 'true');
    await expect(async () => {
        const collapsedRail = (await rail.boundingBox()).width;
        const collapsedMain = (await page.locator('main').boundingBox()).width;

        expect(collapsedRail).toBeLessThan(expandedRail - 100);
        expect(collapsedMain).toBeGreaterThan(expandedMain + 100);
    }).toPass();

    await toggle.click();
    await expect(sidebar).toHaveAttribute('data-collapsed', 'false');
});

test('a collapsed rail still shows which module you are in, and what the icons mean', async ({ page }) => {
    await page.goto('/app/wiki');
    await page.getByTestId('module-sidebar-toggle').click();

    const rail = page.getByTestId('module-sidebar');

    // Selected: exactly one module, and still the one the URL is in.
    await expect(rail.locator('[aria-current="page"]')).toHaveCount(1);
    await expect(rail.locator('[aria-current="page"]')).toHaveAttribute('data-testid', 'module-wiki');
    await expect(rail.locator('[aria-current="page"]')).toHaveClass(/bg-violet-50/);

    // The label is gone from the layout, so the icon carries a tooltip instead.
    await expect(page.getByTestId('module-wiki')).toHaveAttribute('title', 'Wiki');
    await expect(page.getByTestId('module-quality')).toHaveAttribute('title', 'Kvalitet');

    // Planned modules stay inert and dimmed, and say so on hover. (Risiko is built now; a built
    // module the person holds no permission in is left off the rail rather than dimmed, so a
    // module that is still planned is the one to check here.)
    await expect(page.getByTestId('module-suppliers')).toHaveAttribute('aria-disabled', 'true');
    await expect(page.getByTestId('module-suppliers')).toHaveClass(/text-slate-400/);
    await expect(page.getByTestId('module-suppliers')).toHaveAttribute('title', /Leverandører — /);

    // Expanding puts the labels back and drops the now-redundant tooltips.
    await page.getByTestId('module-sidebar-toggle').click();
    await expect(page.getByTestId('module-wiki')).not.toHaveAttribute('title', 'Wiki');
});

test('the rail comes back the way you left it', async ({ page }) => {
    await page.goto('/app/wiki');
    await page.getByTestId('module-sidebar-toggle').click();
    await expect(page.getByTestId('module-sidebar')).toHaveAttribute('data-collapsed', 'true');

    await page.goto('/app/notices');
    await expect(page.getByTestId('module-sidebar')).toHaveAttribute('data-collapsed', 'true');

    await page.reload();
    await expect(page.getByTestId('module-sidebar')).toHaveAttribute('data-collapsed', 'true');
});

test('a phone never gets a collapsed rail, and never gets a sideways scrollbar', async ({ page }) => {
    // Collapse on a wide screen first, then shrink: the stored preference must not follow the
    // person onto a phone, where the rail is the only module navigation there is.
    await page.goto('/app/notices');
    await page.getByTestId('module-sidebar-toggle').click();

    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload();

    await expect(page.getByTestId('module-sidebar-toggle')).toBeHidden();
    await expect(page.getByTestId('module-wiki')).toContainText('Wiki');
    // A module every E2E user reaches, rather than Risiko, which this user holds no permission in.
    await expect(page.getByTestId('module-tenders')).toContainText('Anbud');
    await expect(page.getByTestId('module-suppliers')).toContainText('Leverandører');

    const railWidth = (await page.getByTestId('module-rail').boundingBox()).width;
    expect(railWidth).toBeGreaterThan(200);

    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );

    expect(overflow).toBeLessThanOrEqual(0);
});
