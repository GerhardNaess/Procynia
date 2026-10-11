import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { COMPLIANCE_E2E_PASSWORD, cleanUpComplianceE2eData, complianceE2eSuffix, complianceFixture } from './helpers/compliance.js';
import { DESKTOP, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';

const suffix = complianceE2eSuffix();
cleanUpComplianceE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'compliance-library-frameworks', name);

/**
 * Kravbibliotek with three standards, as an information security manager meets it: ISO 9001, ISO/IEC
 * 27001 and ISO 14001 side by side; ISO/IEC 27001's chapters apart from Vedlegg A; activation with
 * the overview split in two; an Annex A control included with a begrunnelse and assessed as partly
 * met, another left out; the register filtered on the standard; and ISO 14001 activated as well.
 * Readability at desktop and 390 px on the way.
 */
test('Kravbibliotek: ISO/IEC 27001 with Annex A control selection, and ISO 14001 beside it', async ({ page }) => {
    test.setTimeout(300_000);

    const person = await complianceFixture(`seedFrameworksJourney('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);
    const iso27001 = person.frameworks['iso27001:2022'];
    const iso14001 = person.frameworks['iso14001:2026'];
    await loginAs(page, person.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // Three standards in the library.
    await page.goto('/app/compliance/library');
    await expect(page.getByTestId('compliance-library-framework-iso9001:2015')).toContainText('ISO 9001 – Kvalitetsstyring');
    await expect(page.getByTestId('compliance-library-framework-iso27001:2022')).toContainText('ISO/IEC 27001 – Informasjonssikkerhet');
    await expect(page.getByTestId('compliance-library-framework-iso14001:2026')).toContainText('ISO 14001 – Miljøstyring');
    await expectReadable(page, '01-library-three-standards');

    // ISO/IEC 27001 before activation: chapters 4–10 and Vedlegg A as its own part.
    await page.getByTestId('compliance-library-framework-iso27001:2022').getByRole('link', { name: 'Se innholdet' }).click();
    await page.waitForURL(`**/app/compliance/library/${iso27001}`);
    await expect(page.getByText('Utgave: ISO/IEC 27001:2022 + A1:2024')).toBeVisible();
    await expect(page.getByTestId('compliance-library-tree').locator(':scope > li')).toHaveCount(8);
    // Vedlegg A opens from the keyboard like any chapter; its themes have the same chevron.
    const annex = page.getByRole('button', { name: /Vedlegg A – Informasjonssikkerhetskontroller/ });
    await annex.focus();
    await page.keyboard.press('Enter');
    await expect(annex).toHaveAttribute('aria-expanded', 'true');
    const theme = page.getByRole('button', { name: /A\.8 Teknologiske kontroller/ });
    expect((await theme.getByTestId('compliance-library-section-chevron').boundingBox()).width).toBeGreaterThanOrEqual(20);
    await theme.focus();
    await page.keyboard.press('Space');
    await expect(theme).toHaveAttribute('aria-expanded', 'true');
    const backupRow = page.getByTestId('compliance-library-requirement-A.8.13');
    await backupRow.getByRole('button', { name: 'Vis veiledning' }).click();
    await expect(page.getByTestId('compliance-library-A.8.13-guidance')).toContainText('Sikkerhetskontroll (vedlegg A)');
    await expectReadable(page, '02-iso27001-before-activation');

    await expect(page.getByRole('button', { name: 'Aktiver for virksomheten' })).toHaveCount(0);

    // Activate from the card: the dialog counts requirements and controls from the data.
    await page.goto('/app/compliance/library');
    const card27001 = page.getByTestId('compliance-library-framework-iso27001:2022');
    await card27001.getByRole('button', { name: 'Aktiver for virksomheten' }).click();
    const dialog27001 = page.getByRole('dialog', { name: 'Aktiver ISO/IEC 27001?' });
    await expect(dialog27001).toContainText('123 krav og kontroller legges til i virksomhetens kravregister. Eksisterende vurderinger og registreringer blir ikke endret.');
    await dialog27001.getByRole('button', { name: 'Aktiver for virksomheten' }).click();
    await expect(page.getByText('ISO/IEC 27001 er aktivert. Kravene ligger nå i kravregisteret deres.')).toBeVisible();
    await expect(card27001.getByRole('link', { name: 'Åpne i kravregisteret' })).toBeVisible();
    await expect(page.getByTestId('compliance-library-framework-iso9001:2015').getByRole('button', { name: 'Aktiver for virksomheten' })).toBeVisible();

    // The card says what is in the register — no figures; the standard's page shows each control's choice.
    await expect(page.getByTestId('compliance-library-card-iso27001:2022-count')).toHaveText('123 krav i kravregisteret');
    await card27001.getByRole('link', { name: 'Se innholdet' }).click();
    await page.waitForURL(`**/app/compliance/library/${iso27001}`);
    await expect(page.getByTestId('compliance-library-activated')).toBeVisible();
    await expect(page.getByTestId('compliance-library-stats-controls')).toHaveCount(0);
    await page.locator('#compliance-library-filter').selectOption('relevance_open');
    await expect(page.getByTestId('compliance-library-requirement-A.8.13')).toContainText('Ikke valgt');
    await page.locator('#compliance-library-filter').selectOption('all');
    await expectPageHelp(page, 'Om kravpakken', ['Slik gjør du det', 'Vedlegg A og SoA (ISO/IEC 27001)']);
    await expectReadable(page, '03-iso27001-activated');

    // A.8.13 Sikkerhetskopiering: Kontrollvalg Inkludert, with a begrunnelse; then assessed.
    await page.locator('#compliance-library-search').fill('A.8.13');
    await page.getByTestId('compliance-library-requirement-A.8.13').getByRole('link', { name: /Åpne krav/ }).click();
    await expect(page.getByTestId('compliance-origin-source')).toHaveText('ISO/IEC 27001:2022');
    await expect(page.getByTestId('compliance-reference')).toHaveText('A.8.13');
    const choice = page.getByTestId('compliance-applicability');
    await expect(choice.getByRole('heading', { name: 'Kontrollvalg' })).toBeVisible();
    await expect(page.getByTestId('compliance-applicability-assess-blocked')).toContainText('Inkluder kontrollen under Kontrollvalg');
    await choice.getByRole('button', { name: 'Endre kontrollvalg' }).click();
    await choice.getByLabel('Inkludert').check();
    await choice.locator('#compliance-applicability-rationale').fill('Inkludert fordi tap av kundedata er en høy risiko i risikovurderingen.');
    await choice.getByRole('button', { name: 'Lagre kontrollvalg' }).click();
    await expect(page.getByText('Kontrollvalget er lagret.')).toBeVisible();
    await page.getByRole('button', { name: 'Vurder etterlevelse' }).click();
    const form = page.getByTestId('compliance-assessment-form');
    await form.getByLabel('Delvis oppfylt').check();
    await form.locator('#compliance-assessment-rationale').fill('Rutinen finnes, men gjenoppretting er aldri testet.');
    await form.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByTestId('compliance-assessment-form')).toHaveCount(0);
    await expectPageHelp(page, 'Om kravet', ['Slik gjør du det', 'Eksempel: ISO/IEC 27001 A.8.13 Sikkerhetskopiering', 'Eksempel: ISO 14001 6.1.2 Miljøaspekter']);
    await expectReadable(page, '04-control-included-and-assessed');

    // A.8.28 Sikker koding: Utelatt, with a begrunnelse — and then not assessed.
    await page.goto(`/app/compliance/library/${iso27001}`);
    await page.locator('#compliance-library-search').fill('A.8.28');
    await page.getByTestId('compliance-library-requirement-A.8.28').getByRole('link', { name: /Åpne krav/ }).click();
    await page.getByTestId('compliance-applicability').getByRole('button', { name: 'Endre kontrollvalg' }).click();
    await page.getByTestId('compliance-applicability').getByLabel('Utelatt').check();
    await page.locator('#compliance-applicability-rationale').fill('Utelatt fordi vi ikke utvikler programvare selv.');
    await page.getByTestId('compliance-applicability').getByRole('button', { name: 'Lagre kontrollvalg' }).click();
    await expect(page.getByTestId('compliance-applicability-rationale')).toHaveText('Utelatt fordi vi ikke utvikler programvare selv.');
    await expect(page.getByText('Kontrollen er utelatt under Kontrollvalg og vurderes derfor ikke for etterlevelse.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Vurder etterlevelse' })).toHaveCount(0);

    // The figures follow in Etterlevelsesvurdering: the included control is assessed and has a gap,
    // the excluded one is outside the figures, and the 91 not selected are no gaps either.
    await page.goto('/app/compliance/library');
    await card27001.getByRole('link', { name: 'Til etterlevelsesvurdering' }).click();
    await page.waitForURL(/\/app\/compliance\/assessments\?source=\d+$/);
    for (const [key, value] of [['assessable', '31'], ['assessed', '1'], ['partially_compliant', '1'], ['non_compliant', '0'], ['not_assessed', '30']]) {
        await expect(page.getByTestId(`compliance-assessments-stat-${key}`)).toContainText(value);
    }
    await expect(page.getByTestId('compliance-assessments-stat-not_applicable')).toContainText('Ikke relevant eller utelatt: 1');
    await expect(page.getByTestId('compliance-assessments-stat-relevance_open')).toContainText('Relevans eller kontrollvalg ikke avklart: 91');
    const gaps = page.getByTestId('compliance-assessments-row');
    await page.locator('#compliance-assessments-status').selectOption('gaps');
    await page.getByRole('button', { name: 'Filtrer' }).click();
    await expect(gaps).toHaveCount(1);
    await expect(gaps.getByTestId('compliance-assessments-origin')).toHaveText('ISO/IEC 27001:2022 · A.8.13 · Sikkerhetskopiering');
    await page.locator('#compliance-assessments-status').selectOption('not_applicable');
    await page.getByRole('button', { name: 'Filtrer' }).click();
    await expect(gaps).toHaveCount(1);
    await expect(gaps).toContainText('A.8.28');
    await expect(gaps).toContainText('Utelatt');
    await expect(gaps.getByRole('link', { name: /^Vurder/ })).toHaveCount(0);
    await expectReadable(page, '04b-annex-a-in-assessments');

    await page.goto('/app/compliance/library');
    await card27001.getByRole('link', { name: 'Åpne i kravregisteret' }).click();
    await page.waitForURL(/\/app\/compliance\/requirements\?source=\d+/);
    await expect(page.locator('#compliance-source-filter option:checked')).toHaveText(/^ISO\/IEC 27001/);
    await expect(page.locator('tbody tr')).toHaveCount(123);

    // ISO 14001 beside it, activated from its card — a double click activates it once.
    await page.goto('/app/compliance/library');
    const card14001 = page.getByTestId('compliance-library-framework-iso14001:2026');
    await card14001.getByRole('button', { name: 'Aktiver for virksomheten' }).click();
    const dialog14001 = page.getByRole('dialog', { name: 'Aktiver ISO 14001?' });
    await expect(dialog14001).toContainText('35 krav legges til i virksomhetens kravregister.');
    await dialog14001.getByRole('button', { name: 'Aktiver for virksomheten' }).dblclick();
    await expect(card14001.getByRole('link', { name: 'Åpne i kravregisteret' })).toBeVisible();
    await card14001.getByRole('link', { name: 'Åpne i kravregisteret' }).click();
    await page.waitForURL(/\/app\/compliance\/requirements\?source=\d+/);
    await expect(page.locator('#compliance-source-filter option:checked')).toHaveText(/^ISO 14001/);
    await expect(page.locator('tbody tr')).toHaveCount(35);

    // Seven chapters, no Annex A, one overview.
    await page.goto(`/app/compliance/library/${iso14001}`);
    await expect(page.getByText('Utgave: ISO 14001:2026')).toBeVisible();
    await expect(page.getByTestId('compliance-library-tree').locator(':scope > li')).toHaveCount(7);
    await expect(page.getByTestId('compliance-library-stats-controls')).toHaveCount(0);
    await page.locator('#compliance-library-search').fill('6.1.2');
    await page.getByTestId('compliance-library-requirement-6.1.2').getByRole('button', { name: 'Vis veiledning' }).click();
    await expect(page.getByTestId('compliance-library-6.1.2-guidance')).toContainText('Forslag til dokumentasjon');
    await expectReadable(page, '05-iso14001-activated');

    // Both are activated in the library, with no activation left to offer; the help describes the cards.
    await page.goto('/app/compliance/library');
    for (const activated of [card27001, card14001]) {
        await expect(activated).toContainText('Aktivert');
        await expect(activated.getByRole('button', { name: 'Aktiver for virksomheten' })).toHaveCount(0);
    }
    await expectPageHelp(page, 'Om kravbiblioteket', ['Hva brukes siden til?', 'Slik gjør du det', 'Arbeidsflyten']);
    await expect(page.getByTestId('compliance-library-card-iso27001:2022-count')).toHaveText('123 krav i kravregisteret');
    await expect(page.getByTestId('compliance-library-card-iso14001:2026-count')).toHaveText('35 krav i kravregisteret');
    await expectReadable(page, '06-library-two-activated');

    // «Se innholdet» opens the right standard.
    await card14001.getByRole('link', { name: 'Se innholdet' }).click();
    await page.waitForURL(`**/app/compliance/library/${iso14001}`);
    await expect(page.getByText('Utgave: ISO 14001:2026')).toBeVisible();
});
