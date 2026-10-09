import { mkdirSync, rmSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { DESKTOP, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';
import { cleanUpSupplierE2eData, SUPPLIER_E2E_PASSWORD, supplierE2eSuffix, supplierFixture } from './helpers/suppliers.js';

const suffix = supplierE2eSuffix();
cleanUpSupplierE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'supplier-import', name);

// Inside the app's base path, so the spec (on the host) and the fixture (in the container) agree.
const FOLDER = 'storage/app/e2e-supplier-import';
const repoRoot = new URL('../../', import.meta.url).pathname;
const templatePath = `${FOLDER}/mal-${suffix}.xlsx`;
const filledPath = `${FOLDER}/leverandorer-${suffix}.xlsx`;

test.afterAll(() => {
    rmSync(`${repoRoot}${templatePath}`, { force: true });
    rmSync(`${repoRoot}${filledPath}`, { force: true });
});

/**
 * Importer leverandører, end to end: download the template → fill in suppliers → upload → check the
 * rows → confirm → see the result and the suppliers in the register. The rules — duplicates, existing
 * suppliers, access, tenant isolation, a double confirm — are PHP's (SupplierImportTest); this is the
 * path a person takes, with every step checked for text under 16 px and sideways scrolling at desktop
 * and 390 px.
 */
test('suppliers are imported from the downloaded template after the rows are checked and the import confirmed', async ({ page }) => {
    test.setTimeout(180_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const colleagueEmail = `e2e.lev.${suffix.toLowerCase()}.kollega@procynia.test`;
    const existing = await supplierFixture(`activeSupplier('${suffix}', 'Eksisterende ${suffix} AS')`);
    const drift = `Driftspartner ${suffix} AS`;
    const renhold = `Renhold ${suffix} AS`;

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // Leverandører → Importer leverandører.
    await page.goto('/app/supplier-management');
    await page.getByTestId('supplier-import-link').click();
    await page.waitForURL(/\/app\/supplier-management\/import$/);
    await expect(page.getByRole('heading', { name: 'Importer leverandører', level: 1 })).toBeVisible();
    await expect(page.getByTestId('import-steps').getByRole('listitem').first()).toHaveAttribute('aria-current', 'step');
    await expectPageHelp(page, 'Om import av leverandører', ['Slik fungerer det', 'Duplikater', 'Eksisterende leverandører', 'Hva som lagres']);
    await expectReadable(page, '01-upload');

    // Last ned mal → fyll inn leverandører.
    mkdirSync(`${repoRoot}${FOLDER}`, { recursive: true });
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('import-template-download').click()]);
    expect(download.suggestedFilename()).toBe('leverandorer-importmal.xlsx');
    await download.saveAs(`${repoRoot}${templatePath}`);

    const rows = [
        {
            Leverandørnavn: drift, Organisasjonsnummer: '974 760 673', Kategori: 'IT og skytjenester', 'Hva leverer de til oss?': 'Drift av lønnssystem',
            'Intern ansvarlig (e-post)': colleagueEmail, Kontaktperson: 'Kari Kontakt', 'E-post kontaktperson': 'kari@drift.example',
            Kritikalitet: 'Viktig', 'Vurderingsintervall (måneder)': '24',
            'Behandler personopplysninger på våre vegne': 'Ja', 'Tilgang til våre systemer eller informasjon': 'Ja',
            'Bortfall stopper eller svekker kritisk leveranse': 'Nei', 'Vanskelig å erstatte på kort sikt': 'Nei',
        },
        { Leverandørnavn: renhold, Kategori: 'Annet', 'Hva leverer de til oss?': 'Renhold av kontor', Status: 'Under vurdering' },
        { Leverandørnavn: `Feilfirma ${suffix} AS`, Organisasjonsnummer: '974760674', Kategori: 'Annet', 'Hva leverer de til oss?': 'Noe' },
        { Leverandørnavn: `Kopi ${suffix} AS`, Organisasjonsnummer: '974760673', Kategori: 'Annet', 'Hva leverer de til oss?': 'Samme som rad 2' },
        { Leverandørnavn: existing.name, Kategori: 'Annet', 'Hva leverer de til oss?': 'Drift' },
    ];
    const rowsBase64 = Buffer.from(JSON.stringify(rows)).toString('base64');
    expect(await supplierFixture(`fillImportTemplate('${templatePath}', '${filledPath}', '${rowsBase64}')`)).toEqual({ path: filledPath, rows: 5 });

    // Last opp.
    await page.locator('#supplier-import-file').setInputFiles(`${repoRoot}${filledPath}`);
    await page.getByRole('button', { name: 'Last opp og kontroller' }).click();
    await page.waitForURL(/\/app\/supplier-management\/import\/\d+$/);
    const importUrl = page.url();

    // Kontroller: every row with its status and what will happen; nothing is saved yet.
    await expect(page.getByRole('heading', { name: 'Kontroller radene' })).toBeVisible();
    const importRows = page.getByTestId('import-row');
    await expect(importRows).toHaveCount(5);
    await expect(importRows.nth(0)).toHaveAttribute('data-status', 'new');
    await expect(importRows.nth(0)).toContainText(drift);
    await expect(importRows.nth(0)).toContainText('974760673');
    await expect(importRows.nth(0)).toContainText('Viktig');
    await expect(importRows.nth(0).getByTestId('import-row-action')).toHaveText('→ Opprettes');
    await expect(importRows.nth(1)).toContainText(`${person.name} (deg)`);
    await expect(importRows.nth(2)).toHaveAttribute('data-status', 'error');
    await expect(importRows.nth(2).getByTestId('import-row-errors')).toContainText('Organisasjonsnummeret «974760674» er ikke gyldig');
    await expect(importRows.nth(3)).toHaveAttribute('data-status', 'file_duplicate');
    await expect(importRows.nth(3)).toContainText('Samme leverandør som rad 2');
    await expect(importRows.nth(4)).toHaveAttribute('data-status', 'possible_duplicate');
    await expect(importRows.nth(4).getByTestId('import-row-action')).toHaveText('→ Hoppes over');
    await expectReadable(page, '02-review');

    // The filter narrows the list to one status.
    await page.locator('#import-status-filter').selectOption({ label: 'Feil i raden (1)' });
    await expect(importRows).toHaveCount(1);
    await page.locator('#import-status-filter').selectOption({ index: 0 });

    // Nothing is in the register before Bekreft.
    await page.goto('/app/supplier-management');
    await expect(page.getByText(drift)).toHaveCount(0);
    await page.goto(importUrl);

    // Bekreft: the summary, then the import.
    await page.getByRole('button', { name: 'Fortsett', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Bekreft importen' })).toBeVisible();
    await expect(page.getByTestId('import-summary-new')).toContainText('2');
    await expect(page.getByTestId('import-summary-errors')).toContainText('1');
    await expect(page.getByTestId('import-summary-skipped')).toContainText('3');
    await expectReadable(page, '03-confirm');
    await page.getByTestId('import-confirm').click();

    // The result, row by row.
    await expect(page.getByRole('heading', { name: 'Importen er fullført' })).toBeVisible();
    await expect(page.getByTestId('import-result-created')).toContainText('2');
    await expect(page.getByTestId('import-result-rejected')).toContainText('1');
    await expect(page.getByTestId('import-result-skipped')).toContainText('2');
    await expectReadable(page, '04-result');

    // The imported supplier, as registered.
    await page.getByTestId('import-result-rows').getByRole('link', { name: drift }).click();
    await page.waitForURL(/\/app\/supplier-management\/\d+/);
    await expect(page.getByRole('heading', { name: drift, level: 1 })).toBeVisible();
    await expect(page.getByText(person.colleague_name).first()).toBeVisible();

    // Both new suppliers are in the register; the erroneous one is not.
    await page.goto('/app/supplier-management');
    const register = page.getByTestId('supplier-table');
    await expect(register.getByRole('link', { name: drift })).toBeVisible();
    await expect(register.getByRole('link', { name: renhold })).toBeVisible();
    await expect(page.getByText(`Feilfirma ${suffix} AS`)).toHaveCount(0);

    // A second click, or going back to the import, imports nothing again.
    await page.goto(importUrl);
    await expect(page.getByRole('heading', { name: 'Importen er fullført' })).toBeVisible();
    expect(await supplierFixture(`remaining('${suffix}')`)).toMatchObject({ suppliers: 3, imports: 1 });
});
