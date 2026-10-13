import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { DESKTOP, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';
import { SUPPLIER_E2E_PASSWORD, cleanUpSupplierE2eData, openSupplierTab, supplierE2eSuffix, supplierFixture } from './helpers/suppliers.js';

const suffix = supplierE2eSuffix();
cleanUpSupplierE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'supplier-audit-history', name);

/** Accepts the next confirmation the page asks for. */
function acceptNextConfirm(page) {
    page.once('dialog', (dialog) => dialog.accept());
}

/**
 * Sporbarhet, end to end, in a GRC customer of the run's own. Append-only, rollback, delete
 * conditions, rights and tenant isolation are PHP's (SupplierAuditHistoryTest); this is what a person
 * reads: «Endringshistorikk» on a requirement — on Kontrollkrav and on the supplier, apart from the
 * controls — and «Slettede leverandører» from the register; at desktop and 390 px.
 */
test('retiring and reusing a requirement is read in its Endringshistorikk, and Slett is gone', async ({ page }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const catalogue = await supplierFixture(`seedAssurance('${suffix}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Transport ${suffix} AS', 'important', 24)`);
    const own = await supplierFixture(`ownRequirement('${suffix}', ${supplier.id}, 'Utslippsfrie kjøretøy ${suffix}')`);

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // Kontrollkrav: a catalogue requirement, retired and used again — no history until then.
    await page.goto('/app/supplier-management/control-requirements');
    const row = page.getByTestId('control-catalogue-row').filter({ hasText: catalogue.dpa_title });
    await expect(row.getByTestId('requirement-status-history')).toHaveCount(0);
    await expect(row.getByRole('button', { name: 'Slett', exact: true })).toBeVisible();
    acceptNextConfirm(page);
    await row.getByRole('button', { name: 'Sett som utgått' }).click();
    await expect(row.getByRole('button', { name: 'Ta i bruk igjen' })).toBeVisible();
    await row.getByRole('button', { name: 'Ta i bruk igjen' }).click();
    await expect(row.getByRole('button', { name: 'Sett som utgått' })).toBeVisible();

    // Folded away, then newest first, in words, with who; and Slett is no longer offered.
    const history = row.getByTestId('requirement-status-history');
    await expect(history.locator('summary')).toHaveText('Endringshistorikk (2)');
    await expect(history).not.toHaveAttribute('open');
    await history.locator('summary').click();
    const entries = history.getByTestId('requirement-status-history-entry');
    await expect(entries.nth(0)).toContainText('Tatt i bruk igjen');
    await expect(entries.nth(1)).toContainText('Satt som utgått');
    await expect(entries.nth(0)).toContainText(person.name);
    await expect(row.getByRole('button', { name: 'Slett', exact: true })).toHaveCount(0);
    await expect(row).not.toContainText('retired');
    await expectReadable(page, '01-catalogue-history');

    // On the supplier: its own requirement retired, read under Utgåtte kontrollkrav, then used again.
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/supplier-management/${supplier.id}`);
    await openSupplierTab(page, 'Krav og kvalifikasjoner');
    const section = page.getByTestId('supplier-control-requirements');
    const ownRow = section.getByTestId('control-requirement-row').filter({ hasText: own.title });
    await expect(ownRow.getByTestId('requirement-status-history')).toHaveCount(0);
    acceptNextConfirm(page);
    await ownRow.getByRole('button', { name: 'Sett som utgått' }).click();

    const retiredOwn = section.getByTestId('control-retired-own');
    await retiredOwn.locator(':scope > summary').click();
    const retiredRow = retiredOwn.getByTestId('control-retired-own-row').filter({ hasText: own.title });
    await retiredRow.getByTestId('requirement-status-history').locator('summary').click();
    await expect(retiredRow.getByTestId('requirement-status-history-entry')).toHaveCount(1);
    await expect(retiredRow.getByTestId('requirement-status-history-entry')).toContainText('Satt som utgått');
    await expectReadable(page, '02-retired-own-history');

    await page.setViewportSize(DESKTOP);
    acceptNextConfirm(page);
    await retiredRow.getByRole('button', { name: 'Ta i bruk igjen' }).click();
    await expect(section.getByTestId('control-retired-own')).toHaveCount(0);
    const reused = ownRow.getByTestId('requirement-status-history');
    await expect(reused.locator('summary')).toHaveText('Endringshistorikk (2)');
    // Its controls are their own list; the history never says «Kontroller».
    await reused.locator('summary').click();
    await expect(reused).not.toContainText('Kontroller');
    await expectReadable(page, '03-reused-own-history');
});

test('a deleted supplier is read under Slettede leverandører, reached from the register by those who may delete', async ({ page }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Feilregistrert ${suffix} AS')`);
    await supplierFixture(`activeSupplier('${suffix}', 'Beholdes ${suffix} AS')`);

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // Nothing deleted yet: the log says so.
    await page.goto('/app/supplier-management');
    await page.getByTestId('supplier-deletions-link').click();
    await page.waitForURL(/\/app\/supplier-management\/deleted$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Slettede leverandører' })).toBeVisible();
    await expect(page.getByText('Ingen leverandører er slettet.', { exact: true })).toBeVisible();
    await expectPageHelp(page, 'Om slettede leverandører', ['Slik gjør du det', 'Om loggen']);
    await page.keyboard.press('Escape');

    // Slett leverandør, then the log: name, when and by whom.
    await page.goto(`/app/supplier-management/${supplier.id}`);
    acceptNextConfirm(page);
    await page.getByRole('button', { name: 'Slett leverandør' }).click();
    await page.waitForURL(/\/app\/supplier-management$/);
    await expect(page.getByText('Leverandøren er slettet.', { exact: true })).toBeVisible();

    await page.getByTestId('supplier-deletions-link').click();
    const entries = page.getByTestId('supplier-deletion');
    await expect(entries).toHaveCount(1);
    await expect(entries.nth(0)).toContainText(supplier.name);
    await expect(entries.nth(0)).toContainText(`av ${person.name}`);
    await expect(entries.nth(0).getByRole('link')).toHaveCount(0);
    await expect(page.getByRole('button', { name: /Slett|Rediger|Lagre/ })).toHaveCount(0);
    await expectReadable(page, '04-deleted-suppliers');

    // Back to the register; the breadcrumb leads there too.
    await page.setViewportSize(DESKTOP);
    await page.getByRole('link', { name: 'Til leverandører' }).click();
    await page.waitForURL(/\/app\/supplier-management$/);
    await expect(page.getByRole('link', { name: `Beholdes ${suffix} AS` })).toBeVisible();
});

test('without the right to delete, the register offers no deletion log and the page is refused', async ({ page }) => {
    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    await supplierFixture(`activeSupplier('${suffix}', 'Synlig ${suffix} AS')`);

    // The colleague reads suppliers (supplier.view) but may not delete them.
    await loginAs(page, `e2e.lev.${suffix.toLowerCase()}.kollega@procynia.test`, SUPPLIER_E2E_PASSWORD);
    await page.goto('/app/supplier-management');
    await expect(page.getByRole('link', { name: `Synlig ${suffix} AS` })).toBeVisible();
    await expect(page.getByTestId('supplier-deletions-link')).toHaveCount(0);

    const response = await page.goto('/app/supplier-management/deleted');
    expect(response?.status()).toBe(403);
    expect(person.colleague_name).toBeTruthy();
});
