import { expect, test } from '@playwright/test';
import { loginAs, USER } from './helpers/auth.js';
import { DESKTOP, PHONE, sidewaysOverflow, textBelow16px } from './helpers/readability.js';
import { cleanUpSupplierE2eData, SUPPLIER_E2E_PASSWORD, supplierE2eSuffix, supplierFixture } from './helpers/suppliers.js';

const suffix = supplierE2eSuffix();
cleanUpSupplierE2eData(suffix);

const trail = (page) => page.getByTestId('breadcrumbs').getByTestId('breadcrumb-item');
const current = (page) => page.getByTestId('breadcrumbs').locator('[aria-current="page"]');
const railCurrent = (page) => page.getByTestId('module-sidebar').locator('[aria-current="page"]');
const headerCurrent = (page) => page.getByTestId('module-navigation').locator('[aria-current="page"]');

/** The trail as a person reads it, and the module the rail and the header mark as open. */
async function expectPlace(page, levels, { rail, tab = null }) {
    await expect(trail(page)).toHaveText(levels);
    await expect(current(page)).toHaveText(levels[levels.length - 1]);
    // The page itself is never a link.
    await expect(page.getByTestId('breadcrumbs').getByRole('link', { name: levels[levels.length - 1], exact: true })).toHaveCount(0);
    await expect(railCurrent(page)).toHaveText(rail);

    if (tab === null) {
        await expect(page.getByTestId('module-navigation')).toHaveCount(0);
    } else {
        await expect(headerCurrent(page)).toHaveText(tab);
    }
}

/**
 * One navigation model across Procynia, end to end in a GRC customer of the run's own: Oppfølging is
 * on the rail, a task opens the supplier's ordinary page, and that page says where it lives —
 * «Styring › Leverandører › name» — however the person got there: from the task, by a direct URL, by
 * a reload, by the browser's back and forward, or by the trail itself.
 */
test('the trail and the menus follow the page, not the route the person took', async ({ page }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Brødsti Drift ${suffix}', 'critical', 12)`);
    const supplierPath = `/app/supplier-management/${supplier.id}`;

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // Oppfølging is on the rail, beside Hjem; the top bar no longer has a button for it.
    await page.goto('/app/info-center');
    await expectPlace(page, ['Oppfølging', 'Mine oppgaver'], { rail: 'Oppfølging' });
    await expect(page.getByTestId('header-follow-up')).toHaveCount(0);
    await expect(page.getByTestId('module-sidebar').getByRole('link', { name: 'Oppfølging', exact: true })).toHaveAttribute('href', '/app/info-center');

    // The task opens the supplier's ordinary page, and the page names its own place.
    await page.getByTestId('info-center-supplier-task').filter({ hasText: supplier.name }).getByRole('link', { name: 'Åpne leverandør' }).click();
    await page.waitForURL((url) => url.pathname === supplierPath);
    await expectPlace(page, ['Styring', 'Leverandører', supplier.name], { rail: 'Leverandører', tab: 'Leverandører' });
    await expect(page.getByTestId('module-governance')).toHaveAttribute('data-active', 'true');
    await expect(page.getByTestId('module-governance-children')).toBeVisible();
    await expect(page.getByRole('heading', { name: supplier.name, level: 1 })).toBeVisible();
    // The trail replaced the old «← Til leverandører»: one way up, not two.
    await expect(page.getByRole('link', { name: '← Til leverandører' })).toHaveCount(0);
    await page.screenshot({ path: 'test-results/breadcrumbs-supplier-desktop.png' });

    // The same page by URL and by reload: the same trail and the same menus.
    await page.goto(supplierPath);
    await expectPlace(page, ['Styring', 'Leverandører', supplier.name], { rail: 'Leverandører', tab: 'Leverandører' });
    await page.reload();
    await expectPlace(page, ['Styring', 'Leverandører', supplier.name], { rail: 'Leverandører', tab: 'Leverandører' });

    // Up one level through the trail, then back and forward with the browser.
    await page.getByTestId('breadcrumbs').getByRole('link', { name: 'Leverandører', exact: true }).click();
    await page.waitForURL((url) => url.pathname === '/app/supplier-management');
    await expectPlace(page, ['Styring', 'Leverandører'], { rail: 'Leverandører', tab: 'Leverandører' });
    await page.goBack();
    await page.waitForURL((url) => url.pathname === supplierPath);
    await expectPlace(page, ['Styring', 'Leverandører', supplier.name], { rail: 'Leverandører', tab: 'Leverandører' });
    await page.goForward();
    await page.waitForURL((url) => url.pathname === '/app/supplier-management');
    await expectPlace(page, ['Styring', 'Leverandører'], { rail: 'Leverandører', tab: 'Leverandører' });

    // Kontrollkrav is a level inside Leverandører; Styring leads to its own landing page.
    await page.goto('/app/supplier-management/control-requirements');
    await expectPlace(page, ['Styring', 'Leverandører', 'Kontrollkrav'], { rail: 'Leverandører', tab: 'Kontrollkrav' });
    await page.getByTestId('breadcrumbs').getByRole('link', { name: 'Styring', exact: true }).click();
    await page.waitForURL((url) => url.pathname === '/app/governance');
    await expectPlace(page, ['Hjem', 'Styring'], { rail: 'Styring' });

    // A module this person cannot open is not offered by the trail either: the customer holds
    // Kvalitet, but this person has no quality.view, so the rail has no Kvalitet to link to.
    await expect(page.getByTestId('module-quality')).toHaveCount(0);
});

/**
 * A long name is cut on screen and keeps its full text; on a phone the trail shows the nearest parent
 * and the page, with the rest behind «…» — and never widens the page.
 */
test('long names and phones', async ({ page }) => {
    test.setTimeout(90_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const longName = `Nordisk Maskin- og Anleggsutleie med Tilhørende Transporttjenester og Service ${suffix}`;
    const supplier = await supplierFixture(`activeSupplier('${suffix}', '${longName}')`);

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/supplier-management/${supplier.id}`);
    await expect(current(page)).toHaveAttribute('title', longName);
    await expect(current(page)).toHaveText(longName);
    expect(await sidewaysOverflow(page)).toEqual([]);

    await page.setViewportSize(PHONE);
    await page.reload();
    const expand = page.getByTestId('breadcrumbs-expand');
    await expect(expand).toBeVisible();
    await expect(expand).toHaveAccessibleName('Vis hele stien');
    await expect(trail(page).nth(0)).toBeHidden();
    await expect(trail(page).nth(1)).toBeVisible();
    await expect(trail(page).nth(2)).toBeVisible();
    expect(await sidewaysOverflow(page)).toEqual([]);
    expect(await textBelow16px(page)).toEqual([]);
    await page.screenshot({ path: 'test-results/breadcrumbs-phone-folded.png' });

    await expand.click();
    await expect(trail(page).nth(0)).toBeVisible();
    await expect(expand).toHaveCount(0);
    expect(await sidewaysOverflow(page)).toEqual([]);
    await page.screenshot({ path: 'test-results/breadcrumbs-phone-expanded.png' });
});

/**
 * The shared customer: Anbud's three levels, Hjem without a trail, and Oppfølging on the rail for a
 * person who works in Anbud too.
 */
test('Anbud, Wiki and Hjem in the shared customer', async ({ page }) => {
    await loginAs(page, USER.email, USER.password);
    await page.setViewportSize(DESKTOP);

    await page.goto('/app/dashboard');
    await expect(page.getByTestId('breadcrumbs')).toHaveCount(0);
    await expect(railCurrent(page)).toHaveText('Hjem');

    await page.goto('/app/notices?tab=alerts');
    await expect(trail(page)).toHaveText(['Anbud', 'Kunngjøringer', 'Varsler']);
    await expect(railCurrent(page)).toHaveText('Anbud');
    await expect(headerCurrent(page)).toHaveText('Kunngjøringer');

    await page.goto('/app/notices?mode=saved');
    await expect(trail(page)).toHaveText(['Anbud', 'Saksliste', 'Lagrede kunngjøringer']);

    await page.goto('/app/info-center');
    await expect(railCurrent(page)).toHaveText('Oppfølging');
    await expect(trail(page).first()).toHaveText('Oppfølging');

    await page.goto('/app/wiki?tab=sources');
    await expect(trail(page)).toHaveText(['Wiki', 'Kildedokumenter']);
    await expect(railCurrent(page)).toHaveText('Wiki');
});

/**
 * A work view opened with a button is the last level while it is open, even without a URL of its
 * own: «Registrer leverandør» on the overview, «Rediger» on a supplier, «Nytt kontrollkrav» on
 * Kontrollkrav. The level above it leads back to the page as it was; closing the view gives the page
 * its own trail back. A dialog (the help) is not a work view and leaves the trail alone.
 */
test('opening and closing a work view moves the last level, and the level above leads back', async ({ page }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    await supplierFixture(`seedAssurance('${suffix}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Arbeidsvisning Drift ${suffix}')`);
    const crumbLink = (name) => page.getByTestId('breadcrumbs').getByRole('link', { name, exact: true });

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // The overview, then the registration view opened from its button.
    await page.goto('/app/supplier-management');
    await expectPlace(page, ['Styring', 'Leverandører'], { rail: 'Leverandører', tab: 'Leverandører' });
    await page.getByRole('button', { name: 'Registrer leverandør' }).click();
    await expectPlace(page, ['Styring', 'Leverandører', 'Registrer leverandør'], { rail: 'Leverandører', tab: 'Leverandører' });
    await expect(crumbLink('Leverandører')).toHaveAttribute('href', '/app/supplier-management');
    await page.screenshot({ path: 'test-results/breadcrumbs-register-supplier.png' });

    // Closing it with Avbryt gives the overview its own trail back.
    await page.getByRole('button', { name: 'Avbryt' }).click();
    await expectPlace(page, ['Styring', 'Leverandører'], { rail: 'Leverandører', tab: 'Leverandører' });

    // Leaving it through the trail lands on the overview, with the form closed.
    await page.getByRole('button', { name: 'Registrer leverandør' }).click();
    await page.locator('#supplier-name').fill('Ikke lagret AS');
    await crumbLink('Leverandører').click();
    await expectPlace(page, ['Styring', 'Leverandører'], { rail: 'Leverandører', tab: 'Leverandører' });
    await expect(page.locator('#supplier-name')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Registrer leverandør' })).toBeVisible();

    // A dialog over the page is not a work view.
    await page.getByRole('button', { name: 'Hjelp', exact: true }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(trail(page)).toHaveText(['Styring', 'Leverandører']);
    await page.keyboard.press('Escape');

    // Rediger on a supplier sits under the supplier, which leads back to it.
    await page.goto(`/app/supplier-management/${supplier.id}`);
    await page.getByRole('button', { name: 'Rediger', exact: true }).click();
    await expectPlace(page, ['Styring', 'Leverandører', supplier.name, 'Rediger'], { rail: 'Leverandører', tab: 'Leverandører' });
    await crumbLink(supplier.name).click();
    await expectPlace(page, ['Styring', 'Leverandører', supplier.name], { rail: 'Leverandører', tab: 'Leverandører' });
    await expect(page.getByRole('button', { name: 'Rediger', exact: true })).toBeVisible();

    // Nytt kontrollkrav sits under Kontrollkrav; Leverandører leads to the register.
    await page.goto('/app/supplier-management/control-requirements');
    await page.getByRole('button', { name: 'Nytt kontrollkrav' }).click();
    await expectPlace(page, ['Styring', 'Leverandører', 'Kontrollkrav', 'Nytt kontrollkrav'], { rail: 'Leverandører', tab: 'Kontrollkrav' });
    await crumbLink('Leverandører').click();
    await page.waitForURL((url) => url.pathname === '/app/supplier-management');
    await expectPlace(page, ['Styring', 'Leverandører'], { rail: 'Leverandører', tab: 'Leverandører' });

    // On a phone the open view is still the last level, and nothing widens the page.
    await page.setViewportSize(PHONE);
    await page.getByRole('button', { name: 'Registrer leverandør' }).click();
    await expect(current(page)).toHaveText('Registrer leverandør');
    expect(await sidewaysOverflow(page)).toEqual([]);
});
