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
