import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { DESKTOP, expectReadable as expectReadableAt } from './helpers/readability.js';
import { SUPPLIER_E2E_PASSWORD, cleanUpSupplierE2eData, supplierE2eSuffix, supplierFixture } from './helpers/suppliers.js';

const suffix = supplierE2eSuffix();
cleanUpSupplierE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'supplier-assurance', name);

/** Picks an answer for one profile question by its visible label. */
async function answer(form, field, label) {
    await form.getByTestId(`profile-question-${field}`).getByLabel(label, { exact: true }).check();
}

/**
 * Leverandørkontroll (docs/supplier-assurance-v2-plan.md), end to end, in a GRC customer of the run's
 * own. Access, tenant isolation, immutability and the delete rule are PHP's (SupplierProfileTest);
 * this is the journey a person takes with the leverandørprofil: fill it in, see the answers —
 * «Ikke avklart» kept apart from «Nei» and from not answered — change it with a begrunnelse, and
 * read both saves in Profilhistorikk; at desktop and 390 px.
 */
test('a supplier profile is filled in, changed with a reason, and both saves read in the history', async ({ page }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Lønnssystem ${suffix} AS', 'important', 24, ['processes_personal_data'])`);

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/supplier-management/${supplier.id}`);

    const section = page.getByTestId('supplier-profile');
    await expect(section.getByRole('heading', { name: 'Leverandørprofil', exact: true })).toBeVisible();
    await expect(section.getByTestId('profile-none')).toHaveText('Profilen er ikke fylt ut ennå.');
    // The four criticality answers are read here and changed under Kritikalitet.
    await expect(section.getByTestId('profile-basis')).toContainText('Endres under Kritikalitet.');

    // Fyll ut profil: personal data, so the role is asked; no system access, so privileged access is not.
    await section.getByRole('button', { name: 'Fyll ut profil' }).click();
    const form = section.getByTestId('profile-form');
    await expect(form.getByTestId('profile-question-privileged_access')).toHaveCount(0);
    await expect(page.locator('#supplier-profile-reason')).toHaveCount(0);
    await answer(form, 'data_role', 'Databehandler');
    await answer(form, 'special_category_data', 'Ikke avklart');
    await answer(form, 'stores_our_data', 'Ja');
    await answer(form, 'data_location', 'Norge');
    await answer(form, 'uses_subcontractors', 'Nei');
    await answer(form, 'sectors', 'IKT og digitale tjenester');
    await expectReadable(page, '01-profile-form');
    await form.getByRole('button', { name: 'Lagre profil' }).click();
    await expect(page.getByText('Leverandørprofilen er lagret.', { exact: true })).toBeVisible();

    // The current state: each answer by its name, and what is not answered says so.
    for (const [field, text] of [
        ['data_role', 'Databehandler'],
        ['special_category_data', 'Ikke avklart'],
        ['uses_subcontractors', 'Nei'],
        ['sectors', 'IKT og digitale tjenester'],
        ['on_site_work', 'Ikke besvart'],
    ]) {
        await expect(section.getByTestId(`profile-answer-${field}`)).toContainText(text);
    }
    await expect(section.getByTestId('profile-incomplete')).toBeVisible();

    const entries = section.getByTestId('profile-history-entry');
    await expect(entries).toHaveCount(1);
    await expect(entries.nth(0)).toContainText(person.name);
    await expect(entries.nth(0)).toContainText('Profilen ble fylt ut');
    await expect(entries.nth(0)).toContainText('Bruker underleverandører: Nei');

    // Rediger profil: a change needs a begrunnelse.
    await section.getByRole('button', { name: 'Rediger profil' }).click();
    await answer(form, 'special_category_data', 'Ja');
    await answer(form, 'uses_subcontractors', 'Ja');
    await page.locator('#supplier-profile-reason').fill('Leverandøren har tatt i bruk en underleverandør for drift av lønnssystemet.');
    await form.getByRole('button', { name: 'Lagre profil' }).click();
    await expect(page.getByText('Leverandørprofilen er lagret.', { exact: true })).toBeVisible();

    await expect(section.getByTestId('profile-answer-uses_subcontractors')).toContainText('Ja');
    await expect(entries).toHaveCount(2);
    await expect(entries.nth(0)).toContainText('Profilen ble endret');
    await expect(entries.nth(0)).toContainText('Særlige kategorier eller sensitive personopplysninger: Ikke avklart → Ja');
    await expect(entries.nth(0)).toContainText('Bruker underleverandører: Nei → Ja');
    await expect(entries.nth(0)).toContainText('underleverandør for drift av lønnssystemet');
    await expect(entries.nth(0)).not.toContainText('Databehandler');
    await expect(entries.nth(1)).toContainText('Profilen ble fylt ut');
    await expectReadable(page, '02-profile-history');

    // A supplier with a profile has a history: it is ended, never deleted.
    await expect(page.getByRole('button', { name: 'Slett leverandør' })).toHaveCount(0);
});

/**
 * Krav og kvalifikasjoner (plan §5.2, §5.5): the profile makes requirements apply, each says why; an
 * important one is excluded with a begrunnelse and read in the history; «Tilbake til automatisk
 * vurdering» returns it to the rule, which then follows the profile again; and one is included by
 * hand. A mandatory requirement is never offered exclusion. Access, immutability and the server
 * refusals are PHP's (SupplierAssuranceRequirementTest).
 */
test('the profile decides which control requirements apply, and a reasoned override is undone back to the profile', async ({ page }) => {
    test.setTimeout(150_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const catalogue = await supplierFixture(`seedAssurance('${suffix}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Drift ${suffix} AS', 'important', 12, ['processes_personal_data'])`);

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/supplier-management/${supplier.id}`);

    const section = page.getByTestId('supplier-control-requirements');
    const rows = section.getByTestId('control-requirement-row');
    const row = (title) => rows.filter({ hasText: title });
    await expect(section.getByRole('heading', { name: 'Krav og kvalifikasjoner', exact: true })).toBeVisible();
    // No profile yet: unanswered profile questions do not trigger anything.
    await expect(section.getByTestId('control-none')).toBeVisible();

    // The profile makes both requirements apply.
    const profile = page.getByTestId('supplier-profile');
    await profile.getByRole('button', { name: 'Fyll ut profil' }).click();
    const form = profile.getByTestId('profile-form');
    await answer(form, 'data_role', 'Databehandler');
    await answer(form, 'uses_subcontractors', 'Ja');
    await form.getByRole('button', { name: 'Lagre profil' }).click();
    await expect(page.getByText('Leverandørprofilen er lagret.', { exact: true })).toBeVisible();

    await expect(rows).toHaveCount(2);
    await expect(row(catalogue.dpa_title).getByTestId('requirement-reason')).toHaveText('Gjelder fordi leverandøren behandler personopplysninger som databehandler');
    await expect(row(catalogue.subcontractors_title).getByTestId('requirement-reason')).toHaveText('Gjelder fordi leverandøren bruker underleverandører');
    await expect(row(catalogue.dpa_title)).toContainText('Ikke vurdert');
    // Mandatory: no exclusion offered.
    await expect(row(catalogue.dpa_title).getByRole('button', { name: 'Gjelder ikke denne leverandøren' })).toHaveCount(0);
    await expectReadable(page, '03-requirement-profile');

    // Exclude the important one, with a begrunnelse.
    await row(catalogue.subcontractors_title).getByRole('button', { name: 'Gjelder ikke denne leverandøren' }).click();
    const exclude = section.getByTestId('override-form-exclude');
    await exclude.getByLabel('Begrunnelse').fill('Underleverandøren dekkes av konsernavtalen.');
    await expectReadable(page, '04-exclude-form');
    await exclude.getByRole('button', { name: 'Utelukk kravet' }).click();
    await expect(page.getByText('Kravet gjelder ikke lenger leverandøren.', { exact: true })).toBeVisible();
    await expect(rows).toHaveCount(1);

    const excluded = section.getByTestId('control-excluded');
    await excluded.locator('summary').click();
    await expect(excluded.getByTestId('control-excluded-row')).toContainText(`${person.name} utelukket kravet`);
    await expect(excluded.getByTestId('control-excluded-row')).toContainText('Begrunnelse: Underleverandøren dekkes av konsernavtalen.');

    const history = section.getByTestId('control-history');
    await history.locator('summary').click();
    await expect(history.getByTestId('control-history-entry')).toHaveCount(1);
    await expect(history.getByTestId('control-history-entry').first()).toContainText(`Kravet ble utelukket manuelt: ${catalogue.subcontractors_title}`);
    await expectReadable(page, '05-excluded-and-history');

    // Back to the rule: it applies again, because the profile says so.
    await excluded.getByRole('button', { name: 'Tilbake til automatisk vurdering' }).click();
    const clear = section.getByTestId('override-form-clear');
    await clear.getByLabel('Begrunnelse').fill('Konsernavtalen dekker ikke denne leveransen likevel.');
    await clear.getByRole('button', { name: 'Tilbakestill' }).click();
    await expect(page.getByText('Kravet følger leverandørprofilen igjen.', { exact: true })).toBeVisible();
    await expect(row(catalogue.subcontractors_title).getByTestId('requirement-reason')).toHaveText('Gjelder fordi leverandøren bruker underleverandører');

    // ... and follows the profile: no subcontractors, no requirement.
    await profile.getByRole('button', { name: 'Rediger profil' }).click();
    await answer(form, 'uses_subcontractors', 'Nei');
    await page.locator('#supplier-profile-reason').fill('Leverandøren har sagt opp underleverandøren.');
    await form.getByRole('button', { name: 'Lagre profil' }).click();
    await expect(page.getByText('Leverandørprofilen er lagret.', { exact: true })).toBeVisible();
    await expect(rows).toHaveCount(1);
    await expect(row(catalogue.subcontractors_title)).toHaveCount(0);

    // Included by hand: the manual reason, with who and when.
    await section.getByRole('button', { name: 'Legg til krav' }).click();
    const include = section.getByTestId('override-form-include');
    await expect(include).toContainText('Gjelder når leverandøren bruker underleverandører');
    await include.getByLabel(new RegExp(catalogue.subcontractors_title)).check();
    await include.getByLabel('Begrunnelse').fill('Vi vil ha oversikten uansett.');
    await include.getByRole('button', { name: 'Legg til krav' }).click();
    await expect(page.getByText('Kravet gjelder nå leverandøren.', { exact: true })).toBeVisible();
    await expect(row(catalogue.subcontractors_title).getByTestId('requirement-reason')).toContainText(`Gjelder fordi ${person.name} inkluderte kravet manuelt`);
    await expect(row(catalogue.subcontractors_title).getByTestId('requirement-reason')).toContainText('Begrunnelse: Vi vil ha oversikten uansett.');
    await expect(history.getByTestId('control-history-entry')).toHaveCount(3);

    // The catalogue says when each requirement applies, in words.
    await page.goto('/app/supplier-management/control-requirements');
    await expect(page.getByRole('heading', { name: 'Kontrollkrav', level: 1 })).toBeVisible();
    await expect(page.getByTestId('control-catalogue-row').filter({ hasText: catalogue.dpa_title }).getByTestId('control-rule-text'))
        .toHaveText('Gjelder når leverandøren behandler personopplysninger som databehandler');
    await expectReadable(page, '06-catalogue');
});

/**
 * Kontroller krav (plan §8, §10.2.1): a requirement applies, its documentation is registered, the
 * person controls the requirement with that document and reads the result, when and by whom; the
 * history keeps the document as it was even after the row is corrected, and says — apart from it —
 * what is different now. The used document can no longer be deleted. Access, applicability,
 * immutability and the server refusals are PHP's (SupplierRequirementEvaluationTest).
 */
test('a requirement is controlled with a document, and the history keeps the document as it was', async ({ page }) => {
    test.setTimeout(150_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const catalogue = await supplierFixture(`seedAssurance('${suffix}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Lønn ${suffix} AS', 'important', 12, ['processes_personal_data'])`);
    const dpaTitle = `DBA ${suffix} 2026`;

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/supplier-management/${supplier.id}`);

    // The requirement applies: a data processor.
    const profile = page.getByTestId('supplier-profile');
    await profile.getByRole('button', { name: 'Fyll ut profil' }).click();
    await answer(profile.getByTestId('profile-form'), 'data_role', 'Databehandler');
    await profile.getByRole('button', { name: 'Lagre profil' }).click();
    await expect(page.getByText('Leverandørprofilen er lagret.', { exact: true })).toBeVisible();

    const section = page.getByTestId('supplier-control-requirements');
    const row = section.getByTestId('control-requirement-row').filter({ hasText: catalogue.dpa_title });
    await expect(row.getByTestId('requirement-display-status')).toHaveText('Ikke vurdert');
    await expect(row.getByTestId('requirement-last-control')).toHaveCount(0);

    // Dokumentasjon: the agreement is registered there, not in the control.
    const documents = page.getByTestId('supplier-documents');
    await documents.getByRole('button', { name: 'Legg til dokumentasjon' }).click();
    await documents.locator('#supplier-document-type').selectOption({ label: 'Databehandleravtale' });
    await documents.locator('#supplier-document-title').fill(dpaTitle);
    await documents.locator('#supplier-document-location').fill('P360 2026/12');
    await documents.locator('#supplier-document-valid-until').fill('2028-12-31');
    await documents.getByRole('button', { name: 'Lagre dokumentasjon' }).click();
    await expect(page.getByText('Dokumentasjonen er lagret.', { exact: true })).toBeVisible();

    // Kontroller krav: choose the document, the result and the begrunnelse.
    await row.getByRole('button', { name: 'Kontroller krav' }).click();
    const form = row.getByTestId('evaluation-form');
    await expect(form.getByTestId('evaluation-requirement')).toContainText('Personvernforordningen art. 28');
    await form.getByRole('radio', { name: /^Dokumentert/ }).check();
    await expect(form.getByTestId('evaluation-requires-document')).toBeVisible();
    await expect(form.getByRole('button', { name: 'Registrer kontroll' })).toBeDisabled();
    await form.getByTestId('evaluation-document-option').filter({ hasText: dpaTitle }).getByRole('checkbox').check();
    await form.getByLabel('Begrunnelse').fill('Signert databehandleravtale dekker behandlingen av lønnsdata.');
    await expectReadable(page, '07-evaluation-form');
    await form.getByRole('button', { name: 'Registrer kontroll' }).click();
    await expect(page.getByText('Kontrollen er registrert.', { exact: true })).toBeVisible();

    // The latest result, when and by whom.
    await expect(row.getByTestId('requirement-display-status')).toHaveText('Dokumentert');
    await expect(row.getByTestId('requirement-last-control')).toContainText(`av ${person.name}`);

    // The history: self-explanatory, with the document as it was.
    const history = row.getByTestId('evaluation-history');
    await history.locator('summary').click();
    const entry = history.getByTestId('evaluation-entry');
    await expect(entry).toHaveCount(1);
    for (const text of ['Dokumentert', person.name, 'Signert databehandleravtale dekker behandlingen av lønnsdata.', 'Gjelder fordi leverandøren behandler personopplysninger som databehandler']) {
        await expect(entry).toContainText(text);
    }
    await expect(entry.getByTestId('snapshot-title')).toHaveText(dpaTitle);
    await expect(entry.getByTestId('snapshot-now')).toHaveCount(0);

    // The used document is kept: no Slett, and it says why.
    const document = documents.getByTestId('document-entry').filter({ hasText: dpaTitle });
    await expect(document.getByTestId('document-used-in-control')).toBeVisible();
    await expect(document.getByRole('button', { name: 'Slett' })).toHaveCount(0);

    // Correct the row: the control still shows what was seen, and what is different now apart from it.
    await document.getByRole('button', { name: 'Rediger' }).click();
    await expect(documents.getByTestId('document-edit-used-hint')).toBeVisible();
    await documents.locator('#supplier-document-title').fill(`${dpaTitle} revidert`);
    await documents.locator('#supplier-document-location').fill('P360 2026/99');
    await documents.getByRole('button', { name: 'Lagre dokumentasjon' }).click();
    await expect(page.getByText('Dokumentasjonen er oppdatert.', { exact: true })).toBeVisible();

    // The history may still be open from before the save; open it if not.
    if (! await history.evaluate((element) => element.open)) {
        await history.locator('summary').click();
    }
    await expect(entry.getByTestId('snapshot-title')).toHaveText(dpaTitle);
    await expect(entry).toContainText('P360 2026/12');
    await expect(entry.getByTestId('snapshot-now')).toContainText('Dokumentet er endret etter kontrollen.');
    await expect(entry.getByTestId('snapshot-now')).toContainText(`Nå: ${dpaTitle} revidert`);
    await expect(row.getByTestId('requirement-display-status')).toHaveText('Dokumentert');
    await expectReadable(page, '08-evaluation-history');
});

/**
 * Kontrollstatus (plan §7.1, §9.5): a mandatory requirement applies and is controlled as Mangler, so
 * Tilstand nå says Krever beslutning; a person registers a decision, and Beslutning and Tilstand nå
 * are read as two lines. The requirement is then documented: the state changes, the decision does
 * not — it stands in the history with the state it was taken on — and a new decision is a new entry.
 * The state rules, access and immutability are PHP's (SupplierAssuranceResolverTest,
 * SupplierAssuranceDecisionTest).
 */
test('a decision is registered on the control state, and stays as it was when the state changes', async ({ page }) => {
    test.setTimeout(150_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const catalogue = await supplierFixture(`seedAssurance('${suffix}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Regnskap ${suffix} AS', 'important', 12, ['processes_personal_data'])`);
    const dpaTitle = `DBA ${suffix} regnskap`;

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/supplier-management/${supplier.id}`);

    // Only the mandatory data processing agreement applies: a processor without subcontractors.
    const profile = page.getByTestId('supplier-profile');
    await profile.getByRole('button', { name: 'Fyll ut profil' }).click();
    const profileForm = profile.getByTestId('profile-form');
    await answer(profileForm, 'data_role', 'Databehandler');
    await answer(profileForm, 'uses_subcontractors', 'Nei');
    await profile.getByRole('button', { name: 'Lagre profil' }).click();
    await expect(page.getByText('Leverandørprofilen er lagret.', { exact: true })).toBeVisible();

    const status = page.getByTestId('supplier-assurance-status');
    const decisionLine = status.getByTestId('assurance-decision');
    const stateLine = status.getByTestId('assurance-state');
    await expect(status.getByRole('heading', { name: 'Kontrollstatus', exact: true })).toBeVisible();
    await expect(decisionLine).toContainText('Ingen beslutning registrert');
    await expect(stateLine.getByTestId('assurance-state-label')).toHaveText('Krever beslutning');
    await expect(stateLine.getByTestId('assurance-applicable')).toHaveText('1 krav gjelder');

    // Kontroller krav: Mangler.
    const row = page.getByTestId('control-requirement-row').filter({ hasText: catalogue.dpa_title });
    await row.getByRole('button', { name: 'Kontroller krav' }).click();
    const evaluation = row.getByTestId('evaluation-form');
    await evaluation.getByRole('radio', { name: /^Mangler/ }).check();
    await evaluation.getByLabel('Begrunnelse').fill('Databehandleravtalen er ikke mottatt.');
    await evaluation.getByRole('button', { name: 'Registrer kontroll' }).click();
    await expect(page.getByText('Kontrollen er registrert.', { exact: true })).toBeVisible();

    await expect(stateLine.getByTestId('assurance-state-label')).toHaveText('Krever beslutning');
    await expect(stateLine).toContainText('Det finnes et forhold som krever at noen tar stilling.');
    await expect(stateLine.getByTestId('assurance-summary')).toHaveText('1 mangler');
    await expect(stateLine.getByTestId('assurance-open-mandatory')).toContainText(`${catalogue.dpa_title} – Mangler`);
    await expect(stateLine).not.toContainText('%');

    // Registrer beslutning: with a mandatory requirement open only «Ikke godkjent for nye kjøp» can be chosen.
    await status.getByRole('button', { name: 'Registrer beslutning' }).click();
    const form = status.getByTestId('decision-form');
    await expect(form.getByRole('radio', { name: /^Godkjent med oppfølging/ })).toBeDisabled();
    await expect(form.getByRole('radio', { name: /^Godkjent(?! med)/ })).toBeDisabled();
    await expect(form.getByTestId('decision-not-allowed')).toHaveCount(2);
    await form.getByRole('radio', { name: /^Ikke godkjent for nye kjøp/ }).check();
    await form.getByLabel('Begrunnelse').fill('Ingen nye kjøp før databehandleravtalen er signert.');
    await expectReadable(page, '09-decision-form');
    await form.getByRole('button', { name: 'Registrer beslutning' }).click();
    await expect(page.getByText('Beslutningen er registrert.', { exact: true })).toBeVisible();

    // Two lines, labelled apart: the person's decision, and the state now.
    await expect(decisionLine).toContainText('Beslutning');
    await expect(decisionLine).toContainText('Ikke godkjent for nye kjøp');
    await expect(decisionLine.getByTestId('assurance-decision-byline')).toContainText(`av ${person.name}`);
    await expect(stateLine).toContainText('Tilstand nå');
    await expect(stateLine.getByTestId('assurance-state-label')).toHaveText('Obligatorisk krav åpent');
    await expectReadable(page, '10-decision-and-state');

    // Dokumentasjon, then Kontroller krav: Dokumentert. The state changes; the decision does not.
    const documents = page.getByTestId('supplier-documents');
    await documents.getByRole('button', { name: 'Legg til dokumentasjon' }).click();
    await documents.locator('#supplier-document-type').selectOption({ label: 'Databehandleravtale' });
    await documents.locator('#supplier-document-title').fill(dpaTitle);
    await documents.getByRole('button', { name: 'Lagre dokumentasjon' }).click();
    await expect(page.getByText('Dokumentasjonen er lagret.', { exact: true })).toBeVisible();
    await row.getByRole('button', { name: 'Kontroller krav' }).click();
    await evaluation.getByRole('radio', { name: /^Dokumentert/ }).check();
    await evaluation.getByTestId('evaluation-document-option').filter({ hasText: dpaTitle }).getByRole('checkbox').check();
    await evaluation.getByLabel('Begrunnelse').fill('Signert databehandleravtale mottatt.');
    await evaluation.getByRole('button', { name: 'Registrer kontroll' }).click();
    await expect(page.getByText('Kontrollen er registrert.', { exact: true })).toBeVisible();

    await expect(stateLine.getByTestId('assurance-state-label')).toHaveText('I orden');
    await expect(stateLine.getByTestId('assurance-summary')).toHaveText('1 dokumentert');
    await expect(stateLine.getByTestId('assurance-open-mandatory')).toHaveCount(0);
    await expect(decisionLine).toContainText('Ikke godkjent for nye kjøp');

    // A new decision: a new entry, newest first; the first still shows the state it was taken on.
    await status.getByRole('button', { name: 'Registrer beslutning' }).click();
    await form.getByRole('radio', { name: /^Godkjent(?! med)/ }).check();
    await form.getByLabel('Begrunnelse').fill('Databehandleravtalen er signert og kontrollert.');
    await form.getByRole('button', { name: 'Registrer beslutning' }).click();
    await expect(page.getByText('Beslutningen er registrert.', { exact: true })).toBeVisible();
    await expect(decisionLine).toContainText('Godkjent');
    await expect(decisionLine).not.toContainText('Ikke godkjent');

    const history = status.getByTestId('decision-history');
    await history.locator('summary').click();
    const entries = history.getByTestId('decision-history-entry');
    await expect(entries).toHaveCount(2);
    await expect(entries.nth(0)).toContainText('Godkjent');
    await expect(entries.nth(0).getByTestId('decision-snapshot')).toContainText('I orden');
    await expect(entries.nth(0).getByTestId('decision-snapshot')).toContainText('Alle obligatoriske og viktige krav var dokumentert.');
    await expect(entries.nth(1)).toContainText('Ikke godkjent for nye kjøp');
    await expect(entries.nth(1)).toContainText('Ingen nye kjøp før databehandleravtalen er signert.');
    await expect(entries.nth(1).getByTestId('decision-snapshot')).toContainText('Obligatorisk krav åpent');
    await expect(entries.nth(1).getByTestId('decision-snapshot')).toContainText('1 krav gjelder · 1 mangler');
    await expect(entries.nth(1).getByTestId('decision-snapshot')).toContainText(`${catalogue.dpa_title} – Obligatorisk – Mangler`);
    await expectReadable(page, '11-decision-history');

    // The register: the decision in its own column, and no «Krever beslutning» marker now.
    await page.goto('/app/supplier-management');
    const registerRow = page.getByTestId('supplier-table').getByRole('row').filter({ hasText: `Regnskap ${suffix} AS` });
    await expect(registerRow.getByTestId('register-control-status')).toHaveText('Godkjent');
    await expect(registerRow.getByTestId('register-decision-required')).toHaveCount(0);
    await expectReadable(page, '12-register');
});

/**
 * Kravmaler (plan §16): the IT/SaaS template is previewed and applied on Kontrollkrav — what is added,
 * nothing matched on title — and its requirements land in the catalogue next to the customer's own,
 * which are untouched. On a supplier the profile decides which of them apply, in Krav og
 * kvalifikasjoner like any other requirement; one the profile does not trigger is not there.
 * Access, duplicates, provenance and the control chain are PHP's (SupplierRequirementTemplateTest).
 */
test('a requirement template fills Kontrollkrav, and the profile decides which of its requirements apply', async ({ page }) => {
    test.setTimeout(150_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const catalogue = await supplierFixture(`seedAssurance('${suffix}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Skytjeneste ${suffix} AS', 'important', 12, ['processes_personal_data', 'has_system_access'])`);

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/supplier-management/control-requirements');

    const templates = page.getByTestId('requirement-templates');
    await expect(templates.getByRole('heading', { name: 'Kravmaler', exact: true })).toBeVisible();
    await expect(templates.getByTestId('requirement-template')).toHaveCount(9);
    const itSaas = templates.getByTestId('requirement-template').filter({ has: page.getByRole('heading', { name: 'IT/SaaS-leverandør', exact: true }) });
    await expect(itSaas).toContainText('11 krav · 5 obligatoriske');
    await expectReadable(page, '13-templates');

    // The preview: eleven to add, none «already there» — the customer's own Databehandleravtale is not matched on title.
    await itSaas.getByRole('button', { name: 'Ta i bruk kravmal' }).click();
    const dialog = page.getByRole('dialog', { name: 'Ta i bruk kravmal: IT/SaaS-leverandør' });
    await expect(dialog.getByRole('heading', { name: 'Legges til i Kontrollkrav (11)' })).toBeVisible();
    await expect(dialog.getByTestId('template-item-new')).toHaveCount(11);
    await expect(dialog.getByTestId('template-item-existing')).toHaveCount(0);
    await expect(dialog).toContainText('Eksisterende kontrollkrav endres ikke, og ingen krav slettes.');
    await expectReadable(page, '14-template-preview');
    await dialog.getByRole('button', { name: 'Legg til 11 kontrollkrav' }).click();
    await expect(page.getByText('11 kontrollkrav ble lagt til.', { exact: true })).toBeVisible();
    await expect(itSaas.getByTestId('requirement-template-complete')).toHaveText('Alle kravene er lagt til');

    // In the catalogue, next to the customer's own requirements, which are still there.
    const rows = page.getByTestId('control-catalogue-row');
    await expect(rows).toHaveCount(13);
    await expect(rows.filter({ hasText: catalogue.dpa_title })).toHaveCount(1);
    await expect(rows.filter({ hasText: catalogue.subcontractors_title })).toHaveCount(1);
    await expect(rows.filter({ hasText: 'Tilgangsstyring og MFA' }).getByTestId('control-template-origin')).toHaveText('Fra kravmal: IT/SaaS-leverandør');
    await expectReadable(page, '15-catalogue-with-template');

    // On the supplier: the profile decides.
    await page.goto(`/app/supplier-management/${supplier.id}`);
    const profile = page.getByTestId('supplier-profile');
    await profile.getByRole('button', { name: 'Fyll ut profil' }).click();
    const form = profile.getByTestId('profile-form');
    await answer(form, 'data_role', 'Databehandler');
    await answer(form, 'stores_our_data', 'Ja');
    await answer(form, 'data_location', 'Annet land i EØS');
    await form.getByRole('button', { name: 'Lagre profil' }).click();
    await expect(page.getByText('Leverandørprofilen er lagret.', { exact: true })).toBeVisible();

    const section = page.getByTestId('supplier-control-requirements');
    const applicable = section.getByTestId('control-requirement-row');
    await expect(applicable.filter({ hasText: 'Tilgangsstyring og MFA' })).toHaveCount(1);
    await expect(applicable.filter({ hasText: 'Tilgangsstyring og MFA' }).getByTestId('requirement-reason')).toContainText('Gjelder fordi');
    await expect(applicable.filter({ hasText: 'Underdatabehandlere oversikt og godkjenning' })).toContainText('Ikke vurdert');
    // Data in EØS: the transfer requirement does not apply; the customer's own requirement still does.
    await expect(applicable.filter({ hasText: 'Overføringsgrunnlag utenfor EØS' })).toHaveCount(0);
    await expect(applicable.filter({ hasText: catalogue.dpa_title })).toHaveCount(1);
    await expectReadable(page, '16-template-requirements-on-supplier');
});

/**
 * Oppfølging (plan §14, §15.1): a requirement controlled every 6 months was last controlled 7 months
 * ago — a fixture with explicit historical dates rather than a moved clock. The register's existing
 * «trenger oppmerksomhet» filter finds the supplier and says which requirement is overdue and why;
 * the supplier page names it, points to its row and lists it first in Neste kontroller. A new control
 * clears that finding; the earlier control stays in the history. Every other signal, edge and
 * tenant rule is PHP's (SupplierAssuranceAttentionTest, SupplierFollowUpPlanTest).
 */
test('an overdue control is found from the register, controlled again, and leaves Trenger oppmerksomhet', async ({ page }) => {
    test.setTimeout(150_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    await supplierFixture(`seedAssurance('${suffix}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Forfall ${suffix} AS')`);
    const overdue = await supplierFixture(`seedOverdueControl('${suffix}', ${supplier.id})`);

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/supplier-management');

    // The register: the existing filter, now also for Leverandørkontroll.
    await page.getByTestId('supplier-attention-filter').check();
    await page.getByRole('button', { name: 'Søk', exact: true }).click();
    await expect(page).toHaveURL(/attention=1/);
    const item = page.getByTestId('supplier-attention-item').filter({ hasText: supplier.name });
    await expect(item).toContainText('1 kontrollkrav er forfalt:');
    await expect(item.getByTestId('supplier-attention-requirements')).toContainText(`${overdue.requirement_title} – kontrollfristen var`);
    await expect(page.getByTestId('supplier-attention-categories')).toContainText('Kontroll forfalt');
    await expectReadable(page, '17-register-control-overdue');

    // The supplier: which requirement, why, and where it is followed up.
    await item.getByRole('link', { name: supplier.name }).click();
    const attention = page.getByTestId('supplier-attention');
    await expect(attention.locator('[data-finding="control_overdue"]')).toContainText('1 kontrollkrav er forfalt:');
    await expect(attention.getByRole('link', { name: 'Gå til Krav og kvalifikasjoner' })).toHaveAttribute('href', '#supplier-control-heading');
    const requirementLink = attention.getByRole('link', { name: new RegExp(`^${overdue.requirement_title} – kontrollfristen var`) });
    const row = page.getByTestId('control-requirement-row').filter({ hasText: overdue.requirement_title });
    await expect(requirementLink).toHaveAttribute('href', `#${await row.getAttribute('id')}`);
    await expect(row.getByTestId('requirement-display-status')).toHaveText('Må fornyes');
    await expect(row.getByTestId('requirement-next-control')).toContainText('Kontrollfristen var');

    const plan = page.getByTestId('supplier-follow-up');
    const first = plan.getByTestId('follow-up-entry').first();
    await expect(first).toHaveAttribute('data-overdue', '1');
    await expect(first).toContainText(`Ny kontroll: ${overdue.requirement_title}`);
    await expect(first).toContainText('Forfalt');
    await expectReadable(page, '18-supplier-control-overdue');

    // A new control, on the same report.
    await row.getByRole('button', { name: 'Kontroller krav' }).click();
    const form = row.getByTestId('evaluation-form');
    await form.getByRole('radio', { name: /^Dokumentert/ }).check();
    await form.getByTestId('evaluation-document-option').filter({ hasText: overdue.document_title }).getByRole('checkbox').check();
    await form.getByLabel('Begrunnelse').fill('Ny rapport for perioden gjennomgått.');
    await form.getByRole('button', { name: 'Registrer kontroll' }).click();
    await expect(page.getByText('Kontrollen er registrert.', { exact: true })).toBeVisible();

    // The finding is gone; the next control is six months on; both controls are in the history.
    await expect(page.locator('[data-finding="control_overdue"]')).toHaveCount(0);
    await expect(row.getByTestId('requirement-display-status')).toHaveText('Dokumentert');
    await expect(row.getByTestId('requirement-next-control')).toContainText('Neste kontroll');
    await expect(plan.getByTestId('follow-up-entry').filter({ hasText: overdue.requirement_title })).toHaveAttribute('data-overdue', '0');
    await row.getByTestId('evaluation-history').locator('summary').click();
    await expect(row.getByTestId('evaluation-entry')).toHaveCount(2);
    await expectReadable(page, '19-supplier-control-renewed');
});

/**
 * Aktsomhet (plan §11, §15.1, §16.2 mal 4): a product supplier whose profile names textiles made
 * outside the EEA. Once template 4 is applied, the register's existing filter says an
 * aktsomhetsvurdering is missing; on the supplier the card says why it is relevant, maps the profile
 * — «Ikke avklart» shown as such — and takes an assessment area by area, with a conclusion the person
 * chooses. The finding is gone and the assessment reads in the history. Access, immutability, signal
 * 11 and the hand-offs to Avvik and Risiko are PHP's (SupplierDueDiligenceTest).
 */
test('a product supplier with human rights risk gets an aktsomhetsvurdering, and Trenger oppmerksomhet follows', async ({ page }) => {
    test.setTimeout(150_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    await supplierFixture(`seedAssurance('${suffix}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Arbeidstøy ${suffix} AS')`);
    await supplierFixture(`seedProductProfile('${suffix}', ${supplier.id})`);

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/supplier-management/control-requirements');

    // Template 4, next to the others, through the same «Ta i bruk kravmal».
    const template = page.getByTestId('requirement-template').filter({ hasText: 'Produktleverandør med menneskerettighetsrisiko' });
    await expect(template).toContainText('5 krav · 1 obligatoriske');
    await template.getByRole('button', { name: 'Ta i bruk kravmal' }).click();
    const dialog = page.getByRole('dialog', { name: 'Ta i bruk kravmal: Produktleverandør med menneskerettighetsrisiko' });
    await dialog.getByRole('button', { name: 'Legg til 5 kontrollkrav' }).click();
    await expect(page.getByText('5 kontrollkrav ble lagt til.', { exact: true })).toBeVisible();

    // The register: the existing filter carries the new signal.
    await page.goto('/app/supplier-management');
    await page.getByTestId('supplier-attention-filter').check();
    await page.getByRole('button', { name: 'Søk', exact: true }).click();
    const item = page.getByTestId('supplier-attention-item').filter({ hasText: supplier.name });
    await expect(item).toContainText('Leverandørprofilen gjør en aktsomhetsvurdering relevant, og ingen er registrert.');
    await expect(page.getByTestId('supplier-attention-categories')).toContainText('Aktsomhetsvurdering mangler');
    await expectReadable(page, '20-register-due-diligence-missing');

    // The supplier: why it is relevant, what the profile maps, and the requirements that investigate it.
    await item.getByRole('link', { name: supplier.name }).click();
    await expect(page.getByTestId('supplier-attention').getByRole('link', { name: 'Gå til Aktsomhet og bærekraft' })).toHaveAttribute('href', '#supplier-due-diligence-heading');
    const card = page.getByTestId('supplier-due-diligence');
    await expect(card.getByTestId('due-diligence-relevance')).toContainText('Aktsomhetsvurdering er relevant for leverandøren');
    await expect(card.getByTestId('due-diligence-relevance')).toContainText('Gjelder fordi');
    await expect(card.getByTestId('due-diligence-mapping')).toContainText('Ikke avklart');
    await expect(card.getByTestId('due-diligence-none')).toBeVisible();
    await expect(card.getByTestId('due-diligence-requirements')).toContainText('Egenerklæring om menneskerettigheter og arbeidsforhold i leverandørkjeden');
    await expectReadable(page, '21-supplier-due-diligence-missing');

    // The assessment: each area chosen, the conclusion chosen; the interval follows the conclusion.
    await card.getByRole('button', { name: 'Registrer aktsomhetsvurdering' }).click();
    const form = card.getByTestId('due-diligence-form');
    const levels = {
        child_labour_risk: 'Lav',
        forced_labour_risk: 'Lav',
        working_conditions_risk: 'Forhøyet',
        discrimination_risk: 'Lav',
        freedom_of_association_risk: 'Ukjent',
        environment_risk: 'Høy',
    };
    for (const [area, level] of Object.entries(levels)) {
        await form.getByTestId(`due-diligence-area-${area}`).getByLabel(level, { exact: true }).check();
    }
    await form.getByTestId('due-diligence-conclusion-measures_required').getByRole('radio').check();
    await expect(form.getByLabel('Ny vurdering om')).toHaveValue('12');
    await form.getByLabel('Begrunnelse').fill('Overtid og kjemikaliebruk hos syfabrikken må følges opp.');
    await expectReadable(page, '22-due-diligence-form');
    await form.getByRole('button', { name: 'Registrer aktsomhetsvurdering' }).click();
    await expect(page.getByText('Aktsomhetsvurderingen er registrert.', { exact: true })).toBeVisible();

    // The latest assessment, the six areas in words, the next date; the finding is gone.
    await expect(card.getByTestId('due-diligence-conclusion')).toHaveText('Tiltak kreves');
    const areas = card.getByTestId('due-diligence-current-areas');
    await expect(areas.locator('[data-area="working_conditions_risk"]')).toContainText('Forhøyet');
    await expect(areas.locator('[data-area="freedom_of_association_risk"]')).toContainText('Ukjent');
    await expect(card.getByTestId('due-diligence-next')).toContainText('Neste aktsomhetsvurdering');
    await expect(page.locator('[data-finding="due_diligence_missing"]')).toHaveCount(0);
    await card.getByTestId('due-diligence-history').locator('summary').click();
    await expect(card.getByTestId('due-diligence-history-entry')).toHaveCount(1);
    await expectReadable(page, '23-supplier-due-diligence-assessed');
});

/**
 * Kravmaler 5–9 (plan §16.2, phase 8): the customer already uses IT/SaaS-leverandør and has edited one
 * of its requirements. Kritisk IKT-leverandør is IT/SaaS and six more: the preview says which eleven
 * are already there — the edited one under its own name — and only the six are added. The edited
 * requirement is untouched; a critical ICT supplier gets the new ones through its profile. Content,
 * levels, access and tenant rules are PHP's (SupplierRequirementTemplatesTest,
 * SupplierRequirementTemplateTest).
 */
test('a template that overlaps one in use adds only what is missing, and never changes an edited requirement', async ({ page }) => {
    test.setTimeout(150_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    await supplierFixture(`seedAssurance('${suffix}')`);
    const inUse = await supplierFixture(`seedItSaasInUse('${suffix}')`);
    const supplier = await supplierFixture(`activeSupplier('${suffix}', 'Driftspartner ${suffix} AS', 'critical', 12, ['processes_personal_data', 'has_system_access', 'supports_critical_delivery'])`);
    await supplierFixture(`seedIctProfile('${suffix}', ${supplier.id})`);

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/supplier-management/control-requirements');

    const templates = page.getByTestId('requirement-templates');
    await expect(templates.getByTestId('requirement-template')).toHaveCount(9);
    for (const name of ['Kritisk IKT-leverandør', 'Bygg og anlegg', 'Renhold', 'Bemanning', 'Helse']) {
        await expect(templates.getByRole('heading', { name, exact: true })).toBeVisible();
    }
    const criticalIct = templates.getByTestId('requirement-template').filter({ has: page.getByRole('heading', { name: 'Kritisk IKT-leverandør', exact: true }) });
    await expect(criticalIct).toContainText('17 krav · 6 obligatoriske');
    await expectReadable(page, '24-nine-templates');

    // The preview: six to add, eleven already there — the edited one under the name the customer gave it.
    await criticalIct.getByRole('button', { name: 'Ta i bruk kravmal' }).click();
    const dialog = page.getByRole('dialog', { name: 'Ta i bruk kravmal: Kritisk IKT-leverandør' });
    await expect(dialog.getByRole('heading', { name: 'Legges til i Kontrollkrav (6)' })).toBeVisible();
    await expect(dialog.getByTestId('template-item-new')).toHaveCount(6);
    await expect(dialog.getByTestId('template-item-new').filter({ hasText: 'Uavhengig sikkerhetsrapport' })).toHaveCount(1);
    await expect(dialog.getByRole('heading', { name: 'Finnes allerede og legges ikke til på nytt (11)' })).toBeVisible();
    await expect(dialog.getByTestId('template-item-existing').filter({ hasText: 'Tilgangsstyring og MFA' })).toContainText(`heter nå «${inUse.edited_title}»`);
    await expectReadable(page, '25-template-overlap-preview');
    await dialog.getByRole('button', { name: 'Legg til 6 kontrollkrav' }).click();
    await expect(page.getByText('6 kontrollkrav ble lagt til. 11 krav fantes allerede og ble ikke lagt til på nytt.', { exact: true })).toBeVisible();
    await expect(criticalIct.getByTestId('requirement-template-complete')).toHaveText('Alle kravene er lagt til');

    // The catalogue: two of the customer's own, eleven from IT/SaaS, six new — the edited one as it was.
    const rows = page.getByTestId('control-catalogue-row');
    await expect(rows).toHaveCount(19);
    const edited = rows.filter({ hasText: inUse.edited_title });
    await expect(edited).toHaveCount(1);
    await expect(edited.getByTestId('control-template-origin')).toHaveText('Fra kravmal: IT/SaaS-leverandør');
    await expect(rows.filter({ hasText: 'Tilgangsstyring og MFA' })).toHaveCount(0);
    await expect(rows.filter({ hasText: 'Uavhengig sikkerhetsrapport' }).getByTestId('control-template-origin')).toHaveText('Fra kravmal: Kritisk IKT-leverandør');

    // The critical ICT supplier: the new requirements apply through the profile, as any requirement does.
    await page.goto(`/app/supplier-management/${supplier.id}`);
    const applicable = page.getByTestId('supplier-control-requirements').getByTestId('control-requirement-row');
    await expect(applicable.filter({ hasText: 'Uavhengig sikkerhetsrapport' }).getByTestId('requirement-reason')).toContainText('Gjelder fordi');
    await expect(applicable.filter({ hasText: 'Test av gjenoppretting dokumentert' })).toHaveCount(1);
    await expect(applicable.filter({ hasText: inUse.edited_title })).toHaveCount(1);
    // No privileged access, no subcontractors: those two do not apply.
    await expect(applicable.filter({ hasText: 'Logging og sporbarhet av tilgang' })).toHaveCount(0);
    await expect(applicable.filter({ hasText: 'Kritiske avhengigheter og underleverandører kartlagt' })).toHaveCount(0);
    await expectReadable(page, '26-critical-ict-on-supplier');
});
