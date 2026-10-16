import { expect, test } from '@playwright/test';
import { expectNoAxeViolations } from './helpers/a11y.js';
import { loginAs } from './helpers/auth.js';
import { DESKTOP, sidewaysOverflow } from './helpers/readability.js';
import { cleanUpSupplierE2eData, openSupplierTab, SUPPLIER_E2E_PASSWORD, supplierE2eSuffix, supplierFixture } from './helpers/suppliers.js';

const suffix = supplierE2eSuffix();
cleanUpSupplierE2eData(suffix);

const PHONE_320 = { width: 320, height: 720 };

/** The id of the element that has focus, or its tag when it has none. */
const focused = (page) => page.evaluate(() => document.activeElement?.id || document.activeElement?.tagName.toLowerCase());

/** The text of the elements an element is described by. */
const description = (locator) => locator.evaluate((element) => (element.getAttribute('aria-describedby') ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .map((id) => document.getElementById(id)?.textContent ?? '')
    .join(' '));

/** Lets a rejected submit reach the server, so the server's own messages are what is checked. */
const dropRequired = (scope) => scope.locator('[required]').evaluateAll((elements) => elements.forEach((element) => element.removeAttribute('required')));

/**
 * WCAG 2.2 AA, Fase 6 — Leverandøroppfølging (docs/wcag-accessibility-plan.md). In the run's own GRC
 * customer (SupplierE2EFixture). The rules are pinned in resources/js/Support/a11yPhase6Suppliers.test.js;
 * this checks them in a browser: focus into and out of inline panels, focus to the first error after a
 * refused save, the register table, the import steps, removal without losing focus, axe on the
 * surfaces and reflow at 320 px.
 */
test.describe('Leverandøroppfølging – tilgjengelighet', () => {
    test('Registrer leverandør: focus in, focus on the first error after a refused save, and back on Avbryt', async ({ page }) => {
        const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);

        await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
        await page.setViewportSize(DESKTOP);
        await page.goto('/app/supplier-management');

        await page.getByRole('button', { name: 'Registrer leverandør' }).click();
        await expect.poll(() => focused(page)).toBe('supplier-name');
        await expect(page.getByText('Felt merket med * må fylles ut.')).toBeVisible();
        await expect(page.locator('#supplier-name')).toHaveAttribute('aria-required', 'true');

        await dropRequired(page.locator('#supplier-create-panel'));
        await page.getByRole('button', { name: 'Lagre', exact: true }).click();
        await expect(page.locator('#supplier-name')).toHaveAttribute('aria-invalid', 'true');
        await expect.poll(() => focused(page)).toBe('supplier-name');
        expect(await description(page.locator('#supplier-name'))).toContain('Navn må fylles ut.');
        await expect(page.locator('#supplier-owner')).toHaveAttribute('aria-invalid', 'true');
        await expectNoAxeViolations(page, '#supplier-create-panel');

        await page.locator('#supplier-create-panel').getByRole('button', { name: 'Avbryt' }).click();
        await expect.poll(() => focused(page)).toBe('supplier-create-button');
    });

    test('the register: a captioned table with row headers, the hit count said once, and no sideways scroll at 320 px', async ({ page }) => {
        const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
        const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Tabelldrift ${suffix} AS', 'important', 24)`);

        await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
        await page.setViewportSize(DESKTOP);
        await page.goto('/app/supplier-management');

        const table = page.getByTestId('supplier-table');
        await expect(table.locator('caption')).toHaveText('Leverandører');
        await expect(table.getByRole('columnheader', { name: 'Kritikalitet' })).toBeVisible();
        await expect(table.getByRole('rowheader', { name: new RegExp(supplier.name) })).toBeVisible();
        // Kritikalitet is a word, never a colour alone.
        await expect(table.getByRole('row').filter({ hasText: supplier.name })).toContainText('Viktig');
        await expect(page.getByRole('search', { name: 'Søk og filtrer leverandører' })).toBeVisible();
        await expectNoAxeViolations(page, '[data-testid="supplier-table"]');
        await expectNoAxeViolations(page, 'form[role="search"]');

        // A search says the count once.
        await page.locator('#supplier-search').fill(supplier.name);
        await page.getByRole('button', { name: 'Søk', exact: true }).click();
        await expect(page.locator('#procynia-live-polite')).toHaveText('1 leverandør');

        await page.setViewportSize(PHONE_320);
        expect(await sidewaysOverflow(page)).toEqual([]);
    });

    test('the supplier page: Rediger and Avslutt open in place, a refused reason gets focus, and focus returns', async ({ page }) => {
        const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
        const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Panelbygg ${suffix} AS', 'standard', null)`);

        await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
        await page.setViewportSize(DESKTOP);
        await page.goto(`/app/supplier-management/${supplier.id}`);

        // The tabs are links in a named nav; the open one is the current page.
        const tabs = page.getByRole('navigation', { name: 'Leverandørsiden' });
        await expect(tabs.getByRole('link', { name: 'Oversikt' })).toHaveAttribute('aria-current', 'page');

        await page.getByRole('button', { name: 'Rediger', exact: true }).click();
        await expect.poll(() => focused(page)).toBe('supplier-name');
        await expectNoAxeViolations(page, '#supplier-edit-panel');
        await page.locator('#supplier-edit-panel').getByRole('button', { name: 'Avbryt' }).click();
        await expect.poll(() => focused(page)).toBe('supplier-edit-button');

        await page.getByRole('button', { name: 'Avslutt leverandør' }).click();
        await expect.poll(() => focused(page)).toBe('supplier-end-reason');
        // The form sits under the Status heading (h3), so it is an h4.
        await expect(page.getByRole('heading', { name: 'Avslutt leverandør', level: 4 })).toBeVisible();
        await dropRequired(page.locator('#supplier-status-panel'));
        await page.locator('#supplier-status-panel').getByRole('button', { name: 'Avslutt leverandør' }).click();
        await expect(page.locator('#supplier-end-reason')).toHaveAttribute('aria-invalid', 'true');
        await expect.poll(() => focused(page)).toBe('supplier-end-reason');
        expect(await description(page.locator('#supplier-end-reason'))).not.toBe('');
        await expectNoAxeViolations(page, '#supplier-status-panel');
        await page.locator('#supplier-status-panel').getByRole('button', { name: 'Avbryt' }).click();
        await expect.poll(() => focused(page)).toBe('supplier-end-button');

        // An in-page link moves focus with the view (A11Y-015), when the page offers one.
        const inPage = page.getByTestId('supplier-attention').locator('a[href^="#"]');

        if (await inPage.count() > 0) {
            await inPage.first().click();
            await expect.poll(() => focused(page)).not.toBe('body');
        }

        await expectNoAxeViolations(page, '[data-testid="supplier-tab-overview"]');
        await page.setViewportSize(PHONE_320);
        expect(await sidewaysOverflow(page)).toEqual([]);
    });

    test('Dokumentasjon: the form opens with focus, a refused save points at the field, and a deleted row does not drop focus', async ({ page }) => {
        const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
        const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Dokumentdrift ${suffix} AS', 'critical', 12)`);

        await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
        await page.setViewportSize(DESKTOP);
        await page.goto(`/app/supplier-management/${supplier.id}`);
        await openSupplierTab(page, 'Dokumentasjon');

        const section = page.getByTestId('supplier-documents');
        await section.getByRole('button', { name: 'Legg til dokumentasjon' }).click();
        await expect.poll(() => focused(page)).toBe('supplier-document-type');

        await dropRequired(section);
        await section.getByRole('button', { name: 'Lagre dokumentasjon' }).click();
        await expect(section.locator('#supplier-document-type')).toHaveAttribute('aria-invalid', 'true');
        await expect.poll(() => focused(page)).toBe('supplier-document-type');
        await expectNoAxeViolations(page, '[data-testid="supplier-documents"]');

        await section.locator('#supplier-document-type').selectOption({ index: 1 });
        await section.locator('#supplier-document-title').fill(`Rammeavtale ${suffix}`);
        await section.getByRole('button', { name: 'Lagre dokumentasjon' }).click();
        await expect(section.getByTestId('document-entry')).toHaveCount(1);
        // The form closed after the save: focus is back on the button that opened it.
        await expect.poll(() => focused(page)).toBe('supplier-document-add');

        // Each row's buttons are described by the row's title.
        const entry = section.getByTestId('document-entry').first();
        expect(await description(entry.getByRole('button', { name: 'Rediger' }))).toContain(`Rammeavtale ${suffix}`);

        page.once('dialog', (dialog) => dialog.accept());
        await entry.getByRole('button', { name: 'Slett' }).click();
        await expect(section.getByTestId('documents-none')).toBeVisible();
        await expect.poll(() => focused(page)).not.toBe('body');
    });

    test('Importer leverandører: the steps say where they stand, and a refused file puts focus back on the field', async ({ page }) => {
        const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);

        await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
        await page.setViewportSize(DESKTOP);
        await page.goto('/app/supplier-management/import');

        const steps = page.getByTestId('import-steps').getByRole('listitem');
        await expect(steps.nth(0)).toHaveAttribute('aria-current', 'step');
        await expect(steps.nth(0)).toContainText('nåværende steg');
        await expect(steps.nth(1)).toContainText('gjenstår');
        await expect(steps.nth(2)).toContainText('gjenstår');

        await page.locator('#supplier-import-file').setInputFiles({ name: 'leverandorer.xlsx', mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', buffer: Buffer.from('ikke et regneark') });
        await page.getByRole('button', { name: 'Last opp og kontroller' }).click();
        await expect(page.locator('#supplier-import-file')).toHaveAttribute('aria-invalid', 'true');
        await expect.poll(() => focused(page)).toBe('supplier-import-file');
        expect(await description(page.locator('#supplier-import-file'))).not.toBe('');
        // The error describes the field and is announced as an alert (FilePickerField's default).
        await expect(page.locator('#supplier-import-file-error')).toHaveAttribute('role', 'alert');
        await expectNoAxeViolations(page, '[data-testid="import-steps"]');

        await page.setViewportSize(PHONE_320);
        expect(await sidewaysOverflow(page)).toEqual([]);
    });
});
