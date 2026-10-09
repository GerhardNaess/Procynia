import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { DESKTOP, expectReadable as expectReadableAt } from './helpers/readability.js';
import { cleanUpSupplierE2eData, SUPPLIER_E2E_PASSWORD, supplierE2eSuffix, supplierFixture } from './helpers/suppliers.js';

const suffix = supplierE2eSuffix();
cleanUpSupplierE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'suppliers', name);

const pdf = (marker) => ({
    name: `Databehandleravtale ${marker}.pdf`,
    mimeType: 'application/pdf',
    buffer: Buffer.from(`%PDF-1.4\n1 0 obj << /Type /Catalog /Title (${marker}) >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n`),
});

/**
 * Leverandøroppfølging v2.1: one private file per documentation row (supplier-assurance-v2-plan §27).
 * The rules — content-based type checks, tenant isolation, the lock once a control rests on a row,
 * clean-up — are PHP's (SupplierDocumentFileTest); this is the journey a person takes: add
 * documentation with its file, download it, replace it, remove it and upload again, and register a
 * renewed edition with its own file — every step readable at desktop and 390 px.
 */
test('documentation is added with its file, downloaded as an attachment, replaced, removed and renewed with a new file', async ({ page }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Fildrift ${suffix} AS', 'critical', 12)`);

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/supplier-management/${supplier.id}?tab=documents`);
    const section = page.getByTestId('supplier-documents');

    // Legg til dokumentasjon with the file.
    await section.getByRole('button', { name: 'Legg til dokumentasjon' }).click();
    await section.locator('#supplier-document-type').selectOption({ label: 'Databehandleravtale' });
    await section.locator('#supplier-document-title').fill('Databehandleravtale drift');
    await section.locator('#supplier-document-file').setInputFiles(pdf('2026'));
    await expectReadable(page, '60-document-file-form');
    await section.getByRole('button', { name: 'Lagre dokumentasjon' }).click();
    await expect(page.getByText('Dokumentasjonen er lagret.', { exact: true })).toBeVisible();

    const entry = section.getByTestId('document-entry').first();
    const download = entry.getByTestId('document-file-download');
    await expect(download).toHaveText('Last ned «Databehandleravtale 2026.pdf»');
    await expect(entry.getByTestId('document-file-summary')).toContainText('PDF ·');
    await expectReadable(page, '61-document-with-file');

    // Downloaded as an attachment with the person's file name; the response is private and never sniffed.
    const [file] = await Promise.all([page.waitForEvent('download'), download.click()]);
    expect(file.suggestedFilename()).toBe('Databehandleravtale 2026.pdf');
    const response = await page.request.get(await download.getAttribute('href'));
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toBe('application/pdf');
    expect(response.headers()['content-disposition']).toMatch(/^attachment;/);
    expect(response.headers()['x-content-type-options']).toBe('nosniff');
    expect(response.headers()['cache-control']).toContain('no-store');
    expect((await response.body()).toString()).toContain('(2026)');

    // Erstatt fil: the new file takes its place.
    await entry.getByRole('button', { name: 'Erstatt fil' }).click();
    await entry.locator('input[type="file"]').setInputFiles(pdf('2026 signert'));
    await entry.getByRole('button', { name: 'Last opp', exact: true }).click();
    await expect(page.getByText('Filen er lastet opp.', { exact: true })).toBeVisible();
    await expect(download).toHaveText('Last ned «Databehandleravtale 2026 signert.pdf»');

    // Fjern fil: the row stays without a file; Last opp fil is offered again.
    page.once('dialog', (dialog) => dialog.accept());
    await entry.getByRole('button', { name: 'Fjern fil' }).click();
    await expect(page.getByText('Filen er fjernet.', { exact: true })).toBeVisible();
    await expect(entry.getByTestId('document-file-none')).toHaveText('Ingen fil er lastet opp.');
    await expect(entry).toContainText('Databehandleravtale drift');

    // A file that is not what its name says is refused, with the reason in Norwegian.
    await entry.getByRole('button', { name: 'Last opp fil' }).click();
    await entry.locator('input[type="file"]').setInputFiles({ name: 'avtale.pdf', mimeType: 'application/pdf', buffer: Buffer.from('ikke en pdf') });
    await entry.getByRole('button', { name: 'Last opp', exact: true }).click();
    await expect(entry).toContainText('innholdet må stemme med filtypen');
    await entry.locator('input[type="file"]').setInputFiles(pdf('2026 signert'));
    await entry.getByRole('button', { name: 'Last opp', exact: true }).click();
    await expect(page.getByText('Filen er lastet opp.', { exact: true })).toBeVisible();

    // Registrer fornyet with the new edition's file; the old row keeps its own.
    await entry.getByRole('button', { name: 'Registrer fornyet' }).click();
    await section.locator('#supplier-document-title').fill('Databehandleravtale drift 2027');
    await section.locator('#supplier-document-file').setInputFiles(pdf('2027'));
    await section.getByRole('button', { name: 'Lagre dokumentasjon' }).click();
    await expect(page.getByText('Den fornyede dokumentasjonen er lagret. Den forrige er markert som erstattet.', { exact: true })).toBeVisible();
    const entries = section.getByTestId('document-entry');
    await expect(entries).toHaveCount(2);
    await expect(entries.nth(0).getByTestId('document-file-download')).toHaveText('Last ned «Databehandleravtale 2027.pdf»');
    await expect(entries.nth(1)).toContainText('Erstattet');
    await expect(entries.nth(1).getByTestId('document-file-download')).toHaveText('Last ned «Databehandleravtale 2026 signert.pdf»');
    await expectReadable(page, '62-documents-renewed-with-files');
});
