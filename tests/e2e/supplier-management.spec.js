import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { DESKTOP, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';
import { answerCriticality, cleanUpSupplierE2eData, fillAssessment, openSupplierDetails, openSupplierTab, SUPPLIER_E2E_PASSWORD, supplierE2eSuffix, supplierFixture } from './helpers/suppliers.js';

const suffix = supplierE2eSuffix();
cleanUpSupplierE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'suppliers', name);

/**
 * Leverandøroppfølging, end to end, in a GRC customer of the run's own. Access, tenant isolation,
 * the transition rules and the delete rule are PHP's (SupplierRegisterTest); this is the journey
 * a person actually takes: register a supplier under review, open it, edit it, take it into use,
 * end it with a reason, and reopen it — with the history kept and every page and form checked for
 * text under 16 px and sideways scrolling at desktop and 390 px.
 */
test('a supplier is registered, edited, taken into use, ended and reopened, with its history kept', async ({ page }) => {
    test.setTimeout(180_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const name = `Driftspartner ${suffix} AS`;

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // Styring → Leverandører: an empty register that says how to begin.
    await page.goto('/app/governance');
    await page.getByTestId('governance-module-suppliers').click();
    await page.waitForURL(/\/app\/supplier-management$/);
    await expect(page.getByRole('heading', { name: 'Leverandører', level: 1 })).toBeVisible();
    await expect(page.getByText('Ingen leverandører er registrert ennå')).toBeVisible();
    await expectPageHelp(page, 'Om leverandøroppfølging', ['Slik gjør du det', 'Leverandørene', 'Status', 'Trenger oppfølging', 'Tilgang']);
    await expectReadable(page, '01-empty-register');

    // Registrer leverandør: no status select, the server's own messages first.
    await page.getByRole('button', { name: 'Registrer leverandør' }).click();
    await expect(page.locator('#supplier-status')).toHaveCount(0);
    await page.locator('form [required]').evaluateAll((elements) => elements.forEach((element) => element.removeAttribute('required')));
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Navn må fylles ut.')).toBeVisible();
    await expect(page.getByText('Intern ansvarlig må fylles ut.')).toBeVisible();

    await page.locator('#supplier-name').fill(name);
    await page.locator('#supplier-organization-number').fill('987 654 321');
    await page.locator('#supplier-category').selectOption({ label: 'IT og skytjenester' });
    await page.locator('#supplier-deliverable').fill('Drift av lønnssystem');
    await page.locator('#supplier-owner').selectOption({ label: person.colleague_name });
    await page.locator('#supplier-contact-name').fill('Kari Kontakt');
    await page.locator('#supplier-contact-email').fill('kari@driftspartner.example');
    await page.getByLabel('Vi vurderer leverandøren').check();
    // Hvor viktig er leverandøren for oss?: the person chooses; Viktig fills in its interval.
    await answerCriticality(page, 'Viktig', ['has_system_access']);
    await expect(page.locator('#supplier-registration-interval')).toHaveValue('24');
    await expectReadable(page, '02-register-form');
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();

    await page.waitForURL(/\/app\/supplier-management\/\d+$/);
    const supplierUrl = page.url();
    await expect(page.getByText('Leverandøren er registrert.', { exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible();
    // A supplier's own page stays under the Leverandører tab.
    await expect(page.getByTestId('module-navigation').locator('[aria-current="page"]')).toHaveText('Leverandører');
    const facts = page.getByTestId('supplier-facts');
    for (const text of ['IT og skytjenester', '987654321', person.colleague_name]) {
        await expect(facts).toContainText(text);
    }
    await expect(page.getByTestId('supplier-contact')).toContainText('kari@driftspartner.example');
    // Oversikt in order: Oppfølging, Om leverandøren, Neste kontroll, Detaljer (closed), Andre handlinger.
    const overview = page.getByTestId('supplier-tab-overview');
    const areas = await overview.locator(':scope > *').evaluateAll((elements) => elements.map((element) => element.dataset.testid));
    expect(areas).toEqual(['supplier-attention-none', 'supplier-key-facts', 'supplier-next-control', 'supplier-details', 'supplier-other-actions']);
    // Om leverandøren: each field heading larger than its value.
    const sizes = await page.getByTestId('supplier-facts').locator('dt, dd').first().evaluate((dt) => [dt, dt.nextElementSibling].map((element) => parseFloat(getComputedStyle(element).fontSize)));
    expect(sizes[0]).toBeGreaterThan(sizes[1]);
    await expect(page.getByTestId('supplier-attention-none')).toContainText('Det er ikke en godkjenning av leverandøren');
    for (const key of ['info', 'criticality', 'profile']) {
        await expect(page.getByTestId(`details-${key}-toggle`)).toHaveAttribute('aria-expanded', 'false');
    }
    await expect(page.getByTestId('details-criticality-summary')).toHaveText(/^Viktig · Hver 24\. måned · Sist vurdert /);
    await expect(page.getByTestId('criticality-current')).toBeHidden();
    // The other tabs are not repeated on Oversikt.
    for (const gone of ['overview-documents', 'overview-assessment', 'overview-due-diligence', 'supplier-security-privacy']) {
        await expect(page.getByTestId(gone)).toHaveCount(0);
    }
    await expect(page.getByTestId('supplier-other-actions').getByTestId('supplier-status')).toContainText('ikke tatt i bruk');
    await expect(page.getByTestId('supplier-badges')).toContainText('Viktig');
    // A section opens and closes by its heading, from the keyboard too.
    await page.getByTestId('details-criticality-toggle').focus();
    await page.keyboard.press('Enter');
    await expect(page.getByTestId('criticality-current')).toContainText('Hver 24. måned');
    await page.keyboard.press('Space');
    await expect(page.getByTestId('details-criticality-toggle')).toHaveAttribute('aria-expanded', 'false');
    await expect(page.getByTestId('criticality-current')).toBeHidden();
    await openSupplierTab(page, 'Historikk');
    await expect(page.getByTestId('supplier-history')).toContainText(`Registrert som Under vurdering av ${person.name}`);
    await openSupplierTab(page, 'Oversikt');
    // Never used: it could be deleted, and the page says what deleting is for.
    await expect(page.getByTestId('supplier-delete')).toContainText('registrert ved en feil');
    await expectPageHelp(page, 'Om leverandøren', ['Fra registrert til avsluttet', 'Historikk', 'Avslutte eller slette?']);
    await expectReadable(page, '03-supplier');

    // The register lists it; open it from there.
    await page.goto(`/app/supplier-management?${new URLSearchParams({ search: name })}`);
    const row = page.locator('tbody tr', { hasText: name });
    for (const text of ['IT og skytjenester', 'Viktig', 'Drift av lønnssystem', person.colleague_name, 'Under vurdering']) {
        await expect(row).toContainText(text);
    }
    await expectReadable(page, '04-register-with-supplier');
    await row.getByRole('link', { name }).click();
    await page.waitForURL(supplierUrl);

    // Rediger: master data changes, the status does not.
    await page.getByRole('button', { name: 'Rediger' }).click();
    await expect(page.getByTestId('supplier-initial-status')).toHaveCount(0);
    await page.locator('#supplier-deliverable').fill('Drift av lønns- og personalsystem');
    await expectReadable(page, '05-edit');
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Leverandøren er oppdatert.', { exact: true })).toBeVisible();
    await expect(page.getByTestId('supplier-deliverable')).toHaveText('Drift av lønns- og personalsystem');

    // Ta i bruk: Aktiv, and with a history it can no longer be deleted.
    await page.getByRole('button', { name: 'Ta i bruk' }).click();
    await expect(page.getByText('Leverandøren er tatt i bruk.', { exact: true })).toBeVisible();
    await openSupplierTab(page, 'Historikk');
    await expect(page.getByTestId('supplier-history')).toContainText(`Tatt i bruk av ${person.name}`);
    await openSupplierTab(page, 'Oversikt');
    await expect(page.getByRole('button', { name: 'Slett leverandør' })).toHaveCount(0);
    await expect(page.getByTestId('supplier-delete')).toContainText('Avslutt den i stedet');

    // Avslutt leverandør: the reason is required, and the server says so in Norwegian.
    await page.getByRole('button', { name: 'Avslutt leverandør' }).click();
    await expectReadable(page, '06-end-form');
    await page.locator('#supplier-end-reason').evaluate((element) => element.removeAttribute('required'));
    await page.getByRole('button', { name: 'Avslutt leverandør', exact: true }).last().click();
    await expect(page.getByText('Begrunnelse må fylles ut.')).toBeVisible();
    await page.locator('#supplier-end-reason').fill('Avtalen er sagt opp.');
    await page.getByRole('button', { name: 'Avslutt leverandør', exact: true }).last().click();
    await expect(page.getByText('Leverandøren er avsluttet.', { exact: true })).toBeVisible();

    // Ended: read-only — no Rediger, no Ta i bruk; only Gjenåpne.
    await expect(page.getByRole('button', { name: 'Rediger' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Avslutt leverandør' })).toHaveCount(0);
    await expect(page.getByTestId('supplier-ended-notice')).toContainText('Leverandøren er avsluttet.');
    await openSupplierTab(page, 'Historikk');
    await expect(page.getByTestId('supplier-history')).toContainText('Avtalen er sagt opp.');
    await openSupplierTab(page, 'Oversikt');
    await expectReadable(page, '07-ended');

    // It leaves the default register, and comes back under «Avsluttet».
    await page.goto(`/app/supplier-management?${new URLSearchParams({ search: name })}`);
    await expect(page.locator('tbody tr', { hasText: name })).toHaveCount(0);
    await page.locator('#supplier-status-filter').selectOption({ label: 'Avsluttet' });
    await page.getByRole('button', { name: 'Søk', exact: true }).click();
    await expect(page.locator('tbody tr', { hasText: name })).toContainText('Avsluttet');
    await page.goto(supplierUrl);
    // The register remembers no tab: the page opens on Oversikt.

    // Gjenåpne leverandør: Aktiv and editable again, the ending still in the history.
    await page.getByRole('button', { name: 'Gjenåpne leverandør' }).click();
    await page.locator('#supplier-reopen-reason').fill('Ny avtale inngått.');
    await page.getByRole('button', { name: 'Gjenåpne leverandør', exact: true }).last().click();
    await expect(page.getByText('Leverandøren er gjenåpnet.', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Rediger' })).toBeVisible();
    await expect(page.getByTestId('supplier-ended-notice')).toHaveCount(0);
    await openSupplierTab(page, 'Historikk');
    const entries = page.getByTestId('supplier-history-entry');
    await expect(entries).toHaveCount(3);
    await expect(entries.nth(0)).toContainText(`Gjenåpnet av ${person.name}`);
    await expect(entries.nth(1)).toContainText('Avtalen er sagt opp.');
    await expectReadable(page, '08-reopened');
});

/**
 * Sletting is for a supplier registered by mistake. PHP proves the rule; this proves the person
 * meets the confirmation that says so, and that the supplier is gone afterwards.
 */
test('a supplier registered by mistake is deleted after a confirmation that says what deleting is for', async ({ page }) => {
    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const name = `Feilregistrert ${suffix} AS`;

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.goto('/app/supplier-management');
    await page.getByRole('button', { name: 'Registrer leverandør' }).click();
    await page.locator('#supplier-name').fill(name);
    await page.locator('#supplier-category').selectOption({ label: 'Annet' });
    await page.locator('#supplier-deliverable').fill('Ingenting');
    await page.locator('#supplier-owner').selectOption({ label: person.name });
    await answerCriticality(page, 'Standard');
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/supplier-management\/\d+$/);

    let message = '';
    page.once('dialog', (dialog) => { message = dialog.message(); dialog.accept(); });
    await page.getByRole('button', { name: 'Slett leverandør' }).click();
    await page.waitForURL(/\/app\/supplier-management$/);
    expect(message).toContain('registrert ved en feil');
    await expect(page.getByText('Leverandøren er slettet.', { exact: true })).toBeVisible();
    await expect(page.getByText('Ingen leverandører er registrert ennå')).toBeVisible();
});

/**
 * Kritikalitet: how important a supplier is. The rules — the three levels, the required interval,
 * the edit right, the ended lock, the immutable history and the delete rule — are PHP's
 * (SupplierCriticalityTest); this is the journey: a supplier not yet assessed is assessed, the level
 * shows, it is changed with a reason, and the history tells the story — at desktop and 390 px.
 */
test('a supplier is assessed for criticality, reassessed, and the history shows both decisions', async ({ page }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const supplier = await supplierFixture(`unclassifiedSupplier('${suffix}', 'Ordreintegrasjon ${suffix} AS')`);

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/supplier-management/${supplier.id}`);

    await expect(page.getByTestId('details-criticality-summary')).toHaveText('Kritikalitet er ikke vurdert ennå.');
    await openSupplierDetails(page, 'criticality');
    const section = page.getByTestId('supplier-criticality');
    await expect(section.getByTestId('criticality-none')).toHaveText('Kritikalitet er ikke vurdert ennå.');

    // Vurder kritikalitet: the interval is asked for once a level is chosen, and optional for Standard.
    await section.getByRole('button', { name: 'Vurder kritikalitet' }).click();
    await expect(page.locator('#supplier-criticality-interval')).toHaveCount(0);
    await answerCriticality(section, 'Standard', ['processes_personal_data']);
    await expect(page.locator('#supplier-criticality-interval')).toHaveValue('');
    await page.locator('#supplier-criticality-reason').fill('Lett å erstatte; bare et fåtall ordre går gjennom integrasjonen.');
    await expectReadable(page, '10-criticality-form');
    await section.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Kritikaliteten er lagret.', { exact: true })).toBeVisible();

    await expect(page.getByTestId('supplier-badges')).toContainText('Standard');
    const current = section.getByTestId('criticality-current');
    for (const text of ['Standard', 'Ingen fast vurdering', person.name]) {
        await expect(current).toContainText(text);
    }

    // Endre kritikalitet: Kritisk fills in 12 months, and the reason says why.
    await section.getByRole('button', { name: 'Endre kritikalitet' }).click();
    await answerCriticality(section, 'Kritisk', ['processes_personal_data', 'supports_critical_delivery', 'hard_to_replace']);
    await expect(page.locator('#supplier-criticality-interval')).toHaveValue('12');
    await page.locator('#supplier-criticality-reason').fill('Leverandøren drifter en forretningskritisk integrasjon, og bortfall vil stoppe ordrebehandlingen.');
    await section.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Kritikaliteten er lagret.', { exact: true })).toBeVisible();

    await expect(page.getByTestId('supplier-badges')).toContainText('Kritisk');
    await expect(current).toContainText('Hver 12. måned');
    await expect(section.getByTestId('criticality-reason')).toContainText('forretningskritisk integrasjon');
    await expect(section).toContainText('Begrunnelse for kritikalitet');
    // Saving kept the section open; closed, its summary says where it stands now.
    await page.getByTestId('details-criticality-toggle').click();
    await expect(page.getByTestId('details-criticality-summary')).toHaveText(/^Kritisk · Hver 12\. måned · Sist vurdert /);
    // No history on Oversikt — it is under Historikk.
    await expect(page.getByTestId('criticality-history-entry')).toHaveCount(0);

    // The history: newest first, each decision with its reason and the answers it was made on.
    await openSupplierTab(page, 'Historikk');
    const entries = page.getByTestId('supplier-criticality-history').getByTestId('criticality-history-entry');
    await expect(entries).toHaveCount(2);
    await expect(entries.nth(0)).toContainText(`Endret fra Standard til Kritisk av ${person.name}`);
    await expect(entries.nth(0)).toContainText('Vurderingsintervall: Ingen fast vurdering → Hver 12. måned');
    await entries.nth(0).getByText('Beslutningsgrunnlag').click();
    await expect(entries.nth(0)).toContainText('(var nei)');
    await expect(entries.nth(1)).toContainText(`Vurdert som Standard av ${person.name}`);
    await expect(entries.nth(1)).toContainText('Lett å erstatte');
    await expectReadable(page, '11-criticality-history');

    // A real decision was made: the supplier is ended, never deleted.
    await expect(page.getByRole('button', { name: 'Slett leverandør' })).toHaveCount(0);

    // The register shows the level and filters on it.
    await page.goto(`/app/supplier-management?${new URLSearchParams({ criticality: 'critical' })}`);
    await expect(page.locator('tbody tr', { hasText: supplier.name })).toContainText('Kritisk');
});

/**
 * Leverandørvurdering: how a supplier performs now. The rules — supplier.assess, active suppliers
 * only, immutability, the snapshot, the next-review rule and the delete rule — are PHP's
 * (SupplierAssessmentTest); this is the journey: an important supplier is assessed, the result and
 * the next review show, it is assessed again, and the earlier assessment stays in the history — at
 * desktop and 390 px.
 */
test('an active supplier is assessed, reassessed, and the earlier assessment stays in the history', async ({ page }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Lønnsdrift ${suffix} AS', 'important', 24)`);
    const notAssessed = 'Leverandøren er Viktig og mangler leverandørvurdering.';

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // Trenger oppfølging: an important supplier never assessed — a discreet marker on its register
    // row (no panel above the register), and inline on the supplier page with the way to the assessment.
    await page.goto('/app/supplier-management');
    await expect(page.getByTestId('supplier-attention')).toHaveCount(0);
    const registerRow = page.getByTestId('supplier-table').getByRole('row').filter({ hasText: supplier.name });
    await expect(registerRow.getByTestId('supplier-row-attention')).toHaveText('Trenger oppfølging: Ikke vurdert');
    await expectReadable(page, '19-attention-register');
    await registerRow.getByRole('link', { name: supplier.name }).click();
    await page.waitForURL(new RegExp(`/app/supplier-management/${supplier.id}(\\?tab=\\w+)?$`));
    await expect(page.getByTestId('supplier-attention')).toContainText(notAssessed);
    // The link opens Vurderinger.
    await page.getByTestId('supplier-attention').getByRole('link', { name: 'Gå til leverandørvurdering' }).click();
    await expect(page).toHaveURL(/tab=assessments/);

    const section = page.getByTestId('supplier-assessment');
    await expect(section.getByTestId('assessment-none')).toHaveText('Leverandøren er ikke vurdert ennå.');

    // Vurder leverandør: today's criticality is context, never a field.
    await section.getByRole('button', { name: 'Vurder leverandør' }).click();
    const context = section.getByTestId('assessment-criticality-context');
    await expect(context).toContainText('Viktig');
    await expect(context).toContainText('Hver 24. måned');
    await expect(context.locator('input, select')).toHaveCount(0);
    await fillAssessment(section, ['Bra', 'Akseptabelt', 'Bra', 'Ikke relevant'], 'Tilfredsstillende', 'Leveransene har vært stabile og i avtalt kvalitet.');
    await expectReadable(page, '20-assessment-form');
    await section.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Vurderingen er lagret.', { exact: true })).toBeVisible();
    await openSupplierTab(page, 'Oversikt');
    await expect(page.getByTestId('supplier-tab-overview')).toBeVisible();
    await expect(page.getByTestId('supplier-attention')).toHaveCount(0);
    await openSupplierTab(page, 'Vurderinger');

    const current = section.getByTestId('assessment-current');
    for (const text of ['Tilfredsstillende', person.name, 'Leveringspresisjon og respons', 'Akseptabelt', 'Kritikalitet på tidspunktet: Viktig']) {
        await expect(current).toContainText(text);
    }
    // Neste vurdering: today + 24 months.
    await expect(section.getByTestId('assessment-next-review')).toContainText(String(new Date().getFullYear() + 2));

    // Vurder på nytt: the new one is current, the first one moves to the history unchanged.
    await section.getByRole('button', { name: 'Vurder leverandør' }).click();
    await fillAssessment(section, ['Bra', 'Svakt', 'Bra', 'Akseptabelt'], 'Delvis tilfredsstillende',
        'Leveransene har vært stabile, men flere supporthenvendelser har overskredet avtalt responstid de siste månedene.');
    await section.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Vurderingen er lagret.', { exact: true })).toBeVisible();

    await expect(current).toContainText('Delvis tilfredsstillende');
    await expect(section.getByTestId('assessment-rationale')).toContainText('overskredet avtalt responstid');
    const entries = section.getByTestId('assessment-history-entry');
    await expect(entries).toHaveCount(1);
    await expect(entries.first()).toContainText(`av ${person.name}`);
    await expect(entries.first()).toContainText('Samlet vurdering: Tilfredsstillende');
    await expect(entries.first()).toContainText('Leveransene har vært stabile og i avtalt kvalitet.');
    await expectReadable(page, '21-assessment-history');

    // The register shows when the supplier is next to be assessed.
    await page.goto(`/app/supplier-management?${new URLSearchParams({ search: supplier.name })}`);
    await expect(page.locator('tbody tr', { hasText: supplier.name })).toContainText(String(new Date().getFullYear() + 2));
});

/**
 * Dokumentasjon: where a supplier's documentation is kept and how long it is valid — never a file.
 * The rules — supplier.edit, the ended read-only rule, tenant isolation, validation and the delete
 * rule — are PHP's (SupplierDocumentTest); this is the journey: documentation is added, corrected
 * until it shows as expired, renewed, the replaced row deleted, and the section read-only once the
 * supplier is ended — at desktop and 390 px.
 */
test('documentation is added, corrected, renewed and deleted, and is read-only once the supplier is ended', async ({ page }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Skydrift ${suffix} AS', 'critical', 12)`);
    const year = new Date().getFullYear();

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/supplier-management/${supplier.id}?tab=documents`);

    const section = page.getByTestId('supplier-documents');
    await expect(section.getByTestId('documents-none')).toHaveText('Ingen dokumentasjon er registrert.');

    // Legg til dokumentasjon: here a reference to where the document is, without a file (the file is optional).
    await section.getByRole('button', { name: 'Legg til dokumentasjon' }).click();
    await expect(section.locator('input[type="file"]')).toHaveCount(1);
    await section.locator('#supplier-document-type').selectOption({ label: 'Sertifikat' });
    await section.locator('#supplier-document-title').fill('ISO 27001-sertifikat');
    await section.locator('#supplier-document-location').fill('https://contoso.sharepoint.com/sites/innkjop/iso27001.pdf');
    await section.locator('#supplier-document-valid-from').fill(`${year - 1}-01-01`);
    await section.locator('#supplier-document-valid-until').fill(`${year + 1}-01-01`);
    await expectReadable(page, '30-document-form');
    await section.getByRole('button', { name: 'Lagre dokumentasjon' }).click();
    await expect(page.getByText('Dokumentasjonen er lagret.', { exact: true })).toBeVisible();

    const entries = section.getByTestId('document-entry');
    await expect(entries).toHaveCount(1);
    for (const text of ['Sertifikat', 'ISO 27001-sertifikat', 'Gyldig', String(year + 1)]) {
        await expect(entries.first()).toContainText(text);
    }
    await expect(entries.first().getByTestId('document-location').getByRole('link')).toHaveAttribute('href', 'https://contoso.sharepoint.com/sites/innkjop/iso27001.pdf');

    // Rediger: the certificate in fact ran out at the end of last year.
    await entries.first().getByRole('button', { name: 'Rediger' }).click();
    await section.locator('#supplier-document-valid-until').fill(`${year - 1}-12-31`);
    await section.getByRole('button', { name: 'Lagre dokumentasjon' }).click();
    await expect(page.getByText('Dokumentasjonen er oppdatert.', { exact: true })).toBeVisible();
    await expect(entries.first()).toContainText('Utløpt');
    // Trenger oppfølging names the expired certificate until it is renewed, and leads to Dokumentasjon.
    await openSupplierTab(page, 'Oversikt');
    await expect(page.getByTestId('supplier-attention')).toContainText('Sertifikatet «ISO 27001-sertifikat» er utløpt');
    await page.getByTestId('supplier-attention').getByRole('link', { name: 'Gå til dokumentasjon' }).click();
    await expect(page).toHaveURL(/tab=documents/);

    // Registrer fornyet: same type, new validity and location; the old row stays as Erstattet.
    await entries.first().getByRole('button', { name: 'Registrer fornyet' }).click();
    await section.locator('#supplier-document-title').fill(`ISO 27001-sertifikat ${year}`);
    await section.locator('#supplier-document-location').fill('Arkiv sak 2026/114');
    await section.locator('#supplier-document-valid-until').fill(`${year + 2}-12-31`);
    await section.getByRole('button', { name: 'Lagre dokumentasjon' }).click();
    await expect(page.getByText('Den fornyede dokumentasjonen er lagret. Den forrige er markert som erstattet.', { exact: true })).toBeVisible();
    await expect(entries).toHaveCount(2);
    await expect(entries.nth(0)).toContainText(`ISO 27001-sertifikat ${year}`);
    await expect(entries.nth(0)).toContainText('Gyldig');
    await expect(entries.nth(0).getByTestId('document-location')).toHaveText('Arkiv sak 2026/114');
    await expect(entries.nth(1)).toContainText('Erstattet');
    await openSupplierTab(page, 'Oversikt');
    await expect(page.getByTestId('supplier-tab-overview')).toBeVisible();
    await expect(page.getByTestId('supplier-tab-overview')).not.toContainText('Sertifikatet');
    await openSupplierTab(page, 'Dokumentasjon');
    await expectReadable(page, '31-documents');

    // Slett: the replaced row was only kept for reference; deleting it leaves the renewal.
    page.once('dialog', (dialog) => dialog.accept());
    await entries.nth(1).getByRole('button', { name: 'Slett' }).click();
    await expect(page.getByText('Dokumentasjonen er slettet.', { exact: true })).toBeVisible();
    await expect(entries).toHaveCount(1);

    // Ended: the documentation stays visible, and nothing can be changed until it is reopened.
    await openSupplierTab(page, 'Oversikt');
    await page.getByRole('button', { name: 'Avslutt leverandør' }).click();
    await page.locator('#supplier-end-reason').fill('Avtalen er sagt opp.');
    await page.getByRole('button', { name: 'Avslutt leverandør', exact: true }).last().click();
    await expect(page.getByText('Leverandøren er avsluttet.', { exact: true })).toBeVisible();
    await openSupplierTab(page, 'Dokumentasjon');
    await expect(entries).toHaveCount(1);
    await expect(section.getByTestId('documents-read-only')).toContainText('Gjenåpne den for å endre dokumentasjonen.');
    await expect(section.getByRole('button')).toHaveCount(0);
});

/**
 * Avvik og forbedringer hos leverandøren: a supplier problem is handed to Avvik og forbedringer and
 * worked there. The rules — both modules' rights, ended read-only, one case per submit, the hidden
 * case and the hidden supplier — are PHP's (SupplierImprovementHandoffTest); this is the journey:
 * Følg opp, choose the type, fagområde and ansvarlig, create the case, open it in Avvik og
 * forbedringer, and back to the supplier — at desktop and 390 px.
 */
test('a supplier is followed up in Avvik og forbedringer, and each side links to the other', async ({ page }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Skydrift ${suffix} AS', 'important', 24)`);
    const { area_name: areaName } = await supplierFixture(`seedImprovementAccess('${suffix}')`);
    const title = `Leverandør: ${supplier.name} – manglende svar på henvendelser`;

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/supplier-management/${supplier.id}`);

    await expect(page.getByTestId('details-cases-summary')).toHaveText('Ingen tilknyttede saker');
    await openSupplierDetails(page, 'cases');
    const section = page.getByTestId('supplier-cases');
    await expect(section.getByTestId('cases-none')).toHaveText('Ingen saker i Avvik og forbedringer gjelder leverandøren.');

    // Følg opp: a visible, editable suggestion; the type is the person's choice.
    await section.getByRole('button', { name: 'Følg opp i Avvik og forbedringer' }).click();
    const form = section.getByTestId('case-handoff-form');
    await expect(form.locator('#case-handoff-title')).toHaveValue(`Leverandør: ${supplier.name}`);
    await expect(form.locator('#case-handoff-description')).toHaveValue(`Sak opprettet fra Leverandøroppfølging for ${supplier.name}.`);
    await expect(form.getByTestId('case-handoff-type').getByRole('radio', { checked: true })).toHaveCount(0);
    await form.getByTestId('case-handoff-type').getByRole('radio', { name: 'Avvik', exact: true }).check();
    await form.locator('#case-handoff-title').fill(title);
    await form.locator('#case-handoff-area').selectOption({ label: areaName });
    await form.locator('#case-handoff-owner').selectOption({ label: person.name });
    await form.locator('#case-handoff-due').fill(`${new Date().getFullYear() + 1}-03-31`);
    await expectReadable(page, '40-case-handoff-form');
    await form.getByRole('button', { name: 'Opprett sak' }).click();
    await expect(page.getByText('Saken er opprettet i Avvik og forbedringer.', { exact: true })).toBeVisible();

    const entry = section.getByTestId('case-entry');
    await expect(entry).toHaveCount(1);
    for (const text of ['Avvik', title, 'Åpen', String(new Date().getFullYear() + 1), 'Opprettet fra leverandøren']) {
        await expect(entry).toContainText(text);
    }
    await expectReadable(page, '41-supplier-cases');

    // The case lives in Avvik og forbedringer, and says which supplier it concerns.
    await entry.getByRole('link', { name: title }).click();
    await page.waitForURL(/\/app\/improvements\/\d+$/);
    await expect(page.getByRole('heading', { name: title, level: 1 })).toBeVisible();
    const origin = page.getByTestId('improvement-supplier-origin');
    await expect(origin).toContainText(`Gjelder leverandør ${supplier.name}`);
    await expect(origin).toContainText('Saken ble opprettet fra leverandøren.');
    await expectReadable(page, '42-case-from-supplier');

    // And back.
    await origin.getByRole('link', { name: supplier.name }).click();
    await page.waitForURL(new RegExp(`/app/supplier-management/${supplier.id}(\\?tab=\\w+)?$`));
    await expect(page.getByTestId('details-cases-summary')).toHaveText('1 sak');
    await openSupplierDetails(page, 'cases');
    await expect(page.getByTestId('supplier-cases').getByTestId('case-entry')).toContainText(title);
});

/**
 * Risikoer som gjelder leverandøren: a risk is created in Risiko from the supplier and an existing one
 * linked; both are assessed and treated in Risiko. The rules — both modules' rights, the fagområde,
 * ended read-only, the hidden risk and the hidden supplier — are PHP's (SupplierRiskTest); this is the
 * journey: Opprett risiko, choose the fagområde, describe it, create it, open it in Risiko, see
 * «Gjelder leverandør», and back to the supplier — the supplier page at desktop and 390 px.
 */
test('a risk is created from a supplier and an existing one linked, and each side links to the other', async ({ page }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Datasenter ${suffix} AS', 'critical', 12)`);
    const { area_name: areaName, existing_title: existingTitle } = await supplierFixture(`seedRiskAccess('${suffix}')`);
    const title = `Leverandør: ${supplier.name} – brudd i driften`;

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/supplier-management/${supplier.id}`);

    await expect(page.getByTestId('details-risks-summary')).toHaveText('Ingen tilknyttede risikoer');
    await openSupplierDetails(page, 'risks');
    const section = page.getByTestId('supplier-risks');
    await expect(section.getByTestId('risks-none')).toHaveText('Ingen risikoer i Risiko gjelder leverandøren.');

    // Opprett risiko: Risiko's own fields; only the title is suggested.
    await section.getByRole('button', { name: 'Opprett risiko' }).click();
    const form = section.getByTestId('risk-create-form');
    await expect(form.locator('#supplier-risk-title')).toHaveValue(`Leverandør: ${supplier.name}`);
    for (const field of ['cause', 'event', 'consequence']) {
        await expect(form.locator(`#supplier-risk-${field}`)).toHaveValue('');
    }
    await form.locator('#supplier-risk-area').selectOption({ label: areaName });
    await form.locator('#supplier-risk-title').fill(title);
    await form.locator('#supplier-risk-cause').fill('Leverandøren har ett datasenter');
    await form.locator('#supplier-risk-event').fill('Strømbrudd i datasenteret');
    await form.locator('#supplier-risk-consequence').fill('Innbyggertjenestene er nede');
    await form.locator('#supplier-risk-owner').selectOption({ label: person.name });
    await expectReadable(page, '50-risk-create-form');
    await form.getByRole('button', { name: 'Opprett risiko' }).click();
    await expect(page.getByText('Risikoen er opprettet i Risiko.', { exact: true })).toBeVisible();

    // Koble til eksisterende risiko: one the person can edit in Risiko.
    await section.getByRole('button', { name: 'Koble til eksisterende risiko' }).click();
    await section.locator('#supplier-risk-link').selectOption({ label: `${existingTitle} (${areaName})` });
    await section.getByTestId('risk-link-form').getByRole('button', { name: 'Koble til' }).click();
    await expect(page.getByText('Risikoen er koblet til leverandøren.', { exact: true })).toBeVisible();

    const entries = section.getByTestId('risk-entry');
    await expect(entries).toHaveCount(2);
    const created = entries.filter({ hasText: title });
    for (const text of [areaName, 'Identifisert', 'Restrisiko: Ikke vurdert', 'Opprettet fra leverandøren']) {
        await expect(created).toContainText(text);
    }
    await expect(entries.filter({ hasText: existingTitle })).toContainText('Koblet til senere');
    await expectReadable(page, '51-supplier-risks');

    // The risk lives in Risiko, and says which supplier it concerns.
    await created.getByRole('link', { name: title }).click();
    await page.waitForURL(/\/app\/risk\/risks\/\d+$/);
    await expect(page.getByRole('heading', { name: title, level: 1 })).toBeVisible();
    const origin = page.getByTestId('risk-supplier-origin');
    await expect(origin).toContainText(`Gjelder leverandør ${supplier.name}`);
    await expect(origin).toContainText('Risikoen ble opprettet fra leverandøren.');
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await origin.evaluate((element) => parseFloat(getComputedStyle(element).fontSize))).toBeGreaterThanOrEqual(16);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await page.setViewportSize(DESKTOP);

    // And back.
    await origin.getByRole('link', { name: supplier.name }).click();
    await page.waitForURL(new RegExp(`/app/supplier-management/${supplier.id}(\\?tab=\\w+)?$`));
    await expect(page.getByTestId('details-risks-summary')).toHaveText('2 risikoer');
    await openSupplierDetails(page, 'risks');
    await expect(page.getByTestId('supplier-risks').getByTestId('risk-entry')).toHaveCount(2);
});

/**
 * Tidligere koblinger til etterlevelseskrav: a link to a requirement in Etterlevelse og revisjon made
 * before that link was retired from the user flow is kept and read under Historikk — reference, title
 * and kravkilde, a link to the requirement there, and nothing that adds, removes or reads as the
 * supplier's compliance. Krav og kvalifikasjoner no longer has the section. The rules — rights,
 * hidden requirements, no write route — are PHP's (SupplierComplianceRequirementTest).
 */
test('an earlier link to a compliance requirement is read under Historikk, and cannot be added or removed', async ({ page }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Backup ${suffix} AS', 'important', 12)`);
    const requirement = await supplierFixture(`seedEarlierComplianceLink('${suffix}', ${supplier.id})`);

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/supplier-management/${supplier.id}?tab=requirements`);
    await expect(page.getByTestId('supplier-requirements')).toHaveCount(0);
    await expect(page.getByText('Krav som gjelder leverandøren', { exact: true })).toHaveCount(0);

    await page.goto(`/app/supplier-management/${supplier.id}?tab=history`);
    const section = page.getByTestId('supplier-earlier-compliance-links');
    await expect(section.getByRole('heading', { name: 'Tidligere koblinger til etterlevelseskrav' })).toBeVisible();
    await expect(section).toContainText('Koblingen sier ikke at kravet er kontrollert, dokumentert eller oppfylt.');
    const entry = section.getByTestId('earlier-compliance-link');
    await expect(entry).toHaveCount(1);
    for (const text of [requirement.reference, requirement.title, requirement.source_label]) {
        await expect(entry).toContainText(text);
    }
    await expect(section).not.toContainText(requirement.other_title);
    await expect(section).not.toContainText('Oppfylt');
    await expect(section.getByRole('button')).toHaveCount(0);
    await expectReadable(page, '61-earlier-compliance-links');

    // The requirement lives in Etterlevelse og revisjon.
    await entry.getByRole('link', { name: requirement.title }).click();
    await page.waitForURL(/\/app\/compliance\/requirements\/\d+$/);
    await expect(page.getByRole('heading', { name: requirement.title, level: 1 })).toBeVisible();
});
