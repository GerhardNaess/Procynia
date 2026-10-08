import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { DESKTOP, PHONE, sidewaysOverflow } from './helpers/readability.js';
import { SUPPLIER_E2E_PASSWORD, cleanUpSupplierE2eData, supplierE2eSuffix, supplierFixture } from './helpers/suppliers.js';

const suffix = supplierE2eSuffix();
cleanUpSupplierE2eData(suffix);

/**
 * Text under 16 px in «Moduler og pakker» and its dialogs. Scoped to the section on purpose: the
 * rest of Abonnement (the AI capacity card) predates this page's package section and is not what
 * is checked here. The «i» glyph of an InfoHint is left out — the button is named by its aria-label.
 */
const smallTextInPackages = (page) => page.evaluate(() => {
    const roots = [document.querySelector('[data-testid="module-packages"]'), ...document.querySelectorAll('[role="dialog"]')].filter(Boolean);
    const found = [];

    for (const root of roots) {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);

        while (walker.nextNode()) {
            const text = walker.currentNode.textContent.trim();
            const element = walker.currentNode.parentElement;

            if (! text || ! element || element.closest('[aria-hidden="true"], .sr-only, button[aria-label][aria-expanded]')) {
                continue;
            }

            const size = parseFloat(getComputedStyle(element).fontSize);

            if (size < 16 && element.getBoundingClientRect().width > 0) {
                found.push(`${size}px «${text.slice(0, 60)}»`);
            }
        }
    }

    return found;
});

async function expectReadable(page, name) {
    for (const [label, size] of [['desktop', DESKTOP], ['phone', PHONE]]) {
        await page.setViewportSize(size);
        expect(await smallTextInPackages(page), `${name} (${label})`).toEqual([]);
        expect(await sidewaysOverflow(page), `${name} (${label}) scrolls sideways`).toEqual([]);
        await page.screenshot({ path: `test-results/packages-${name}-${label}.png`, fullPage: true });
    }

    await page.setViewportSize(DESKTOP);
}

const rail = (page) => page.getByTestId('module-sidebar');

/**
 * Moduler og pakker, end to end, in a customer of the run's own holding Basis, Risiko and
 * Leverandøroppfølging: cancel Leverandøroppfølging alone and order it again. That no supplier row
 * is deleted, that permissions alone open nothing and that another customer cannot be reached is
 * PHP's (PackageChangeTest); this is the flow a System Owner actually takes, with the page and its
 * confirmation checked for text under 16 px and sideways scrolling at 390 px.
 */
test('Leverandøroppfølging is cancelled on its own while Risiko stays, and comes back with its suppliers', async ({ page }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedPackageJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    await page.goto('/app/supplier-management');
    await expect(page.getByRole('link', { name: person.supplier_name })).toBeVisible();
    await expect(rail(page).getByTestId('module-suppliers')).toBeVisible();
    await expect(rail(page).getByTestId('module-risk')).toBeVisible();

    // Basis on its own, active, with nothing to press; then the five options.
    await page.goto('/app/billing');
    const basis = page.getByTestId('package-row-basis');
    await expect(basis).toContainText('Basis er grunnpakken i Procynia.');
    await expect(page.getByTestId('package-status-basis')).toContainText('Aktiv');
    await expect(basis.getByRole('button')).toHaveCount(0);
    for (const [key, status, action] of [
        ['risk', 'Aktiv', 'Avbestill'],
        ['objectives', 'Ikke aktiv', 'Bestill'],
        ['compliance', 'Ikke aktiv', 'Bestill'],
        ['supplier', 'Aktiv', 'Avbestill'],
        ['tender', 'Ikke aktiv', 'Bestill'],
    ]) {
        await expect(page.getByTestId(`package-status-${key}`)).toContainText(status);
        await expect(page.getByTestId(`package-row-${key}`).getByRole('button', { name: action, exact: true })).toBeVisible();
    }
    await expectReadable(page, '01-options');

    // Avbestill Leverandøroppfølging: the confirmation says what is kept.
    await page.getByTestId('package-row-supplier').getByRole('button', { name: 'Avbestill' }).click();
    const dialog = page.getByRole('dialog', { name: 'Avbestill Leverandøroppfølging?' });
    await expect(dialog).toContainText('Registrerte leverandører, vurderinger, dokumentasjon og historikk slettes ikke.');
    await expectReadable(page, '02-cancel-confirm');
    await dialog.getByRole('button', { name: 'Avbestill' }).click();

    await expect(page.getByText('Leverandøroppfølging er avbestilt. Ingen data er slettet.').first()).toBeVisible();
    await expect(page.getByTestId('package-status-supplier')).toContainText('Ikke aktiv');
    await expect(page.getByTestId('package-status-risk')).toContainText('Aktiv');

    // Leverandører is gone from the menu; Risiko is still there.
    await expect(rail(page).getByTestId('module-suppliers')).toHaveCount(0);
    await expect(rail(page).getByTestId('module-risk')).toBeVisible();

    // Bestill again: the supplier registered before is there.
    await page.getByTestId('package-row-supplier').getByRole('button', { name: 'Bestill' }).click();
    await page.getByRole('dialog', { name: 'Bestill Leverandøroppfølging?' }).getByRole('button', { name: 'Bestill' }).click();
    await expect(page.getByTestId('package-status-supplier')).toContainText('Aktiv');
    await expect(rail(page).getByTestId('module-suppliers')).toBeVisible();

    await page.goto('/app/supplier-management');
    await expect(page.getByRole('link', { name: person.supplier_name })).toBeVisible();
});
