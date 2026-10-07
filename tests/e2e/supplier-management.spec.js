import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { DESKTOP, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';
import { SUPPLIER_E2E_PASSWORD, answerCriticality, cleanUpSupplierE2eData, fillAssessment, supplierE2eSuffix, supplierFixture } from './helpers/suppliers.js';

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
    await expectPageHelp(page, 'Om leverandøroppfølging', ['Leverandørene', 'Status', 'Tilgang']);
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
    const facts = page.getByTestId('supplier-facts');
    for (const text of ['IT og skytjenester', '987654321', person.colleague_name]) {
        await expect(facts).toContainText(text);
    }
    await expect(page.getByTestId('supplier-contact')).toContainText('kari@driftspartner.example');
    await expect(page.getByTestId('supplier-status')).toContainText('ikke tatt i bruk');
    await expect(page.getByTestId('supplier-badges')).toContainText('Viktig');
    await expect(page.getByTestId('criticality-current')).toContainText('Hver 24. måned');
    await expect(page.getByTestId('supplier-history')).toContainText(`Registrert som Under vurdering av ${person.name}`);
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
    await expect(page.getByTestId('supplier-history')).toContainText(`Tatt i bruk av ${person.name}`);
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
    await expect(page.getByTestId('supplier-history')).toContainText('Avtalen er sagt opp.');
    await expectReadable(page, '07-ended');

    // It leaves the default register, and comes back under «Avsluttet».
    await page.goto(`/app/supplier-management?${new URLSearchParams({ search: name })}`);
    await expect(page.locator('tbody tr', { hasText: name })).toHaveCount(0);
    await page.locator('#supplier-status-filter').selectOption({ label: 'Avsluttet' });
    await page.getByRole('button', { name: 'Søk', exact: true }).click();
    await expect(page.locator('tbody tr', { hasText: name })).toContainText('Avsluttet');
    await page.goto(supplierUrl);

    // Gjenåpne leverandør: Aktiv and editable again, the ending still in the history.
    await page.getByRole('button', { name: 'Gjenåpne leverandør' }).click();
    await page.locator('#supplier-reopen-reason').fill('Ny avtale inngått.');
    await page.getByRole('button', { name: 'Gjenåpne leverandør', exact: true }).last().click();
    await expect(page.getByText('Leverandøren er gjenåpnet.', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Rediger' })).toBeVisible();
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

    // The history: newest first, each decision with its reason and the answers it was made on.
    const entries = section.getByTestId('criticality-history-entry');
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

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/supplier-management/${supplier.id}`);

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
    await page.goto(`/app/supplier-management/${supplier.id}`);

    const section = page.getByTestId('supplier-documents');
    await expect(section.getByTestId('documents-none')).toHaveText('Ingen dokumentasjon er registrert.');

    // Legg til dokumentasjon: a reference to where the document is, never an upload.
    await section.getByRole('button', { name: 'Legg til dokumentasjon' }).click();
    await expect(section.locator('input[type="file"]')).toHaveCount(0);
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
    await expectReadable(page, '31-documents');

    // Slett: the replaced row was only kept for reference; deleting it leaves the renewal.
    page.once('dialog', (dialog) => dialog.accept());
    await entries.nth(1).getByRole('button', { name: 'Slett' }).click();
    await expect(page.getByText('Dokumentasjonen er slettet.', { exact: true })).toBeVisible();
    await expect(entries).toHaveCount(1);

    // Ended: the documentation stays visible, and nothing can be changed until it is reopened.
    await page.getByRole('button', { name: 'Avslutt leverandør' }).click();
    await page.locator('#supplier-end-reason').fill('Avtalen er sagt opp.');
    await page.getByRole('button', { name: 'Avslutt leverandør', exact: true }).last().click();
    await expect(page.getByText('Leverandøren er avsluttet.', { exact: true })).toBeVisible();
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
    await page.waitForURL(new RegExp(`/app/supplier-management/${supplier.id}$`));
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
    await page.waitForURL(new RegExp(`/app/supplier-management/${supplier.id}$`));
    await expect(page.getByTestId('supplier-risks').getByTestId('risk-entry')).toHaveCount(2);
});

/**
 * Krav som gjelder leverandøren: an existing requirement in Etterlevelse og revisjon is added to a
 * supplier and removed again; the requirement is worked there. The rules — both modules' rights,
 * active requirements only, ended read-only, hidden requirements, no compliance status — are PHP's
 * (SupplierComplianceRequirementTest); this is the journey: Legg til krav, search, add, see
 * reference, title and kravkilde, open the requirement in Etterlevelse og revisjon, back, and
 * Fjern krav — the supplier page at desktop and 390 px. Etterlevelse og revisjon shows no supplier
 * back in v1 (plan §7.3).
 */
test('a requirement is added to a supplier, opened in Etterlevelse og revisjon and removed again', async ({ page }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Backup ${suffix} AS', 'important', 12)`);
    const requirement = await supplierFixture(`seedComplianceAccess('${suffix}')`);

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/supplier-management/${supplier.id}`);

    const section = page.getByTestId('supplier-requirements');
    await expect(section.getByTestId('requirements-none')).toHaveText('Ingen krav i Etterlevelse og revisjon er lagt til for leverandøren.');

    // Legg til krav: search among the active requirements, choose one.
    await section.getByRole('button', { name: 'Legg til krav' }).click();
    const form = section.getByTestId('requirement-add-form');
    await form.locator('#supplier-requirement-search').fill('sikkerhetskopi');
    await expect(form.locator('#supplier-requirement-link option')).toHaveCount(2);
    await form.locator('#supplier-requirement-link').selectOption({ label: `${requirement.reference} ${requirement.title} – ${requirement.source_label}` });
    await expectReadable(page, '60-requirement-add-form');
    await form.getByRole('button', { name: 'Legg til', exact: true }).click();
    await expect(page.getByText('Kravet er lagt til for leverandøren.', { exact: true })).toBeVisible();

    // Reference, title and kravkilde — and nothing that reads as the supplier's compliance.
    const entry = section.getByTestId('requirement-entry');
    await expect(entry).toHaveCount(1);
    for (const text of [requirement.reference, requirement.title, requirement.source_label]) {
        await expect(entry).toContainText(text);
    }
    await expect(entry).not.toContainText(requirement.other_title);
    await expect(section).not.toContainText('Oppfylt');
    await expectReadable(page, '61-supplier-requirements');

    // The requirement lives in Etterlevelse og revisjon.
    await entry.getByRole('link', { name: requirement.title }).click();
    await page.waitForURL(/\/app\/compliance\/requirements\/\d+$/);
    await expect(page.getByRole('heading', { name: requirement.title, level: 1 })).toBeVisible();

    // And back: Fjern krav removes only the link.
    await page.goBack();
    await page.waitForURL(new RegExp(`/app/supplier-management/${supplier.id}$`));
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByTestId('supplier-requirements').getByRole('button', { name: 'Fjern krav' }).click();
    await expect(page.getByText('Kravet er fjernet fra leverandøren. Det er fortsatt i Etterlevelse og revisjon.', { exact: true })).toBeVisible();
    await expect(page.getByTestId('supplier-requirements').getByTestId('requirements-none')).toBeVisible();
});
