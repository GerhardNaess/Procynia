import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { DESKTOP, PHONE, sidewaysOverflow } from './helpers/readability.js';
import { SUPPLIER_E2E_PASSWORD, cleanUpSupplierE2eData, supplierE2eSuffix, supplierFixture } from './helpers/suppliers.js';

const suffix = supplierE2eSuffix();
cleanUpSupplierE2eData(suffix);

/**
 * Text under 16 px in «Moduler og pakker» and its dialogs. Scoped to the section on purpose: the
 * rest of Abonnement (the AI capacity card) predates this change and is not what is checked here.
 * The «i» glyph of an InfoHint is left out — the button is named by its aria-label.
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

/**
 * Moduler og pakker, end to end, in a GRC customer of the run's own: move down to ISO and back up.
 * That no supplier row is deleted, that permissions alone open nothing and that another customer
 * cannot be reached is PHP's (PackageChangeTest); this is the flow a System Owner actually takes,
 * with the page and its dialogs checked for text under 16 px and sideways scrolling at 390 px.
 */
test('a GRC customer moves down to ISO and back, and its suppliers come back with it', async ({ page }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedPackageJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    await page.goto('/app/supplier-management');
    await expect(page.getByRole('link', { name: person.supplier_name })).toBeVisible();

    // One active main package; the steps below are included in it and offer nothing.
    await page.goto('/app/billing');
    await expect(page.getByTestId('package-status-grc')).toContainText('Aktiv');
    for (const key of ['basis', 'governance', 'iso']) {
        await expect(page.getByTestId(`package-status-${key}`)).toContainText('Inkludert i GRC');
        await expect(page.getByTestId(`package-row-${key}`).getByRole('button')).toHaveCount(0);
    }
    await expectReadable(page, '01-grc');

    // Endre pakke → ISO: the confirmation names what goes and says nothing is deleted.
    await page.getByTestId('package-row-grc').getByRole('button', { name: 'Endre pakke' }).click();
    await page.getByRole('radio', { name: 'Bytt ned til ISO' }).check();
    await expectReadable(page, '02-change-select');
    await page.getByRole('button', { name: 'Fortsett' }).click();
    await expect(page.getByRole('heading', { name: 'Bytt fra GRC til ISO?' })).toBeVisible();
    await expect(page.getByText('Leverandøroppfølging blir ikke lenger tilgjengelig.')).toBeVisible();
    await expect(page.getByText(/Registrerte data og historikk slettes ikke/)).toBeVisible();
    await expectReadable(page, '03-change-confirm');
    await page.getByRole('button', { name: 'Bytt til ISO' }).click();

    await expect(page.getByText('Pakken er endret til ISO.').first()).toBeVisible();
    await expect(page.getByTestId('package-status-iso')).toContainText('Aktiv');
    await expect(page.getByTestId('package-status-governance')).toContainText('Inkludert i ISO');
    await expect(page.getByTestId('package-status-grc')).toContainText('Ikke aktiv');

    // Leverandøroppfølging is gone from the product.
    await page.goto('/app/supplier-management');
    await page.waitForURL(/\/app\/dashboard/);
    await expect(page.getByText(/Leverandøroppfølging er ikke aktivert/)).toBeVisible();

    // Oppgrader back to GRC: the supplier registered before is there.
    await page.goto('/app/billing');
    await page.getByTestId('package-row-grc').getByRole('button', { name: 'Oppgrader' }).click();
    await expect(page.getByText('Leverandøroppfølging blir tilgjengelig.')).toBeVisible();
    await page.getByRole('button', { name: 'Bytt til GRC' }).click();
    await expect(page.getByTestId('package-status-grc')).toContainText('Aktiv');

    await page.goto('/app/supplier-management');
    await expect(page.getByRole('link', { name: person.supplier_name })).toBeVisible();
});
