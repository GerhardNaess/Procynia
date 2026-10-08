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
