import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { COMPLIANCE_E2E_PASSWORD, cleanUpComplianceE2eData, complianceE2eSuffix, complianceFixture } from './helpers/compliance.js';
import { DESKTOP, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';

const suffix = complianceE2eSuffix();
cleanUpComplianceE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'compliance-library', name);

/**
 * Kravbibliotek → ISO 9001, as a quality manager meets it: the library next to Krav in the left
 * menu, what the library is versus the register, the standard's chapters with guidance, «Aktiver
 * for virksomheten», the register laid over the tree with its counts, a conditional requirement
 * set Ikke relevant with a begrunnelse, a mandatory one assessed — and the counts following.
 * Readability at desktop and 390 px on the way.
 */
test('Kravbibliotek: activate ISO 9001, decide relevance and assess', async ({ page }) => {
    test.setTimeout(240_000);

    const person = await complianceFixture(`seedLibraryJourney('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);
    await loginAs(page, person.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // The header: Kravbibliotek | Kravregister | Etterlevelsesvurdering | Revisjon.
    await page.goto('/app/compliance/requirements');
    const areas = page.getByTestId('module-navigation');
    await expect(areas.locator('a')).toHaveText(['Kravbibliotek', 'Kravregister', 'Etterlevelsesvurdering', 'Revisjon']);
    await areas.getByRole('link', { name: 'Kravbibliotek', exact: true }).click();
    await page.waitForURL(/\/app\/compliance\/library$/);
    await expect(areas.locator('[aria-current="page"]')).toHaveText('Kravbibliotek');

    // The library says what it is, and how it differs from the register.
    await expect(page.getByRole('heading', { level: 1, name: 'Kravbibliotek' })).toBeVisible();
    await expect(page.getByTestId('compliance-library-explainer')).toContainText('Hvilke standardiserte krav finnes?');
    await expect(page.getByTestId('compliance-library-explainer')).toContainText('Hvilke krav gjelder for oss');
    await expectPageHelp(page, 'Om kravbiblioteket', ['Hva brukes siden til?', 'Slik gjør du det', 'Arbeidsflyten', 'Kravbibliotek og kravregister', 'Innholdet']);
    await expectReadable(page, '01-library');

    const card = page.getByTestId('compliance-library-framework-iso9001:2015');
    await expect(card).toContainText('ISO 9001 – Kvalitetsstyring');
    await expect(card).toContainText('Ikke aktivert');

    // «Aktiver for virksomheten» on the card asks first; Avbryt changes nothing.
    const dialog = page.getByRole('dialog', { name: 'Aktiver ISO 9001?' });
    await card.getByRole('button', { name: 'Aktiver for virksomheten' }).click();
    await expect(dialog).toContainText('65 krav legges til i virksomhetens kravregister. Eksisterende vurderinger og registreringer blir ikke endret.');
    await dialog.getByRole('button', { name: 'Avbryt' }).click();
    await expect(dialog).toHaveCount(0);
    await expect(card).toContainText('Ikke aktivert');
    await expect(card.getByRole('link', { name: 'Åpne i kravregisteret' })).toHaveCount(0);

    await card.getByRole('link', { name: 'Se innholdet' }).click();
    await page.waitForURL(`**/app/compliance/library/${person.framework_id}`);
    // The header follows into the standard's own page.
    await expect(areas.locator('[aria-current="page"]')).toHaveText('Kravbibliotek');

    // Before activation: the standard's chapters, closed; guidance one click away.
    await expect(page.getByText('Utgave: ISO 9001:2015 + A1:2024')).toBeVisible();
    await expect(page.getByTestId('compliance-library-verification')).toBeVisible();
    await expect(page.getByTestId('compliance-library-tree').locator(':scope > li')).toHaveCount(7);
    // The whole chapter heading is the button; the chevron is big, points right while closed, down while open.
    const drift = page.getByRole('button', { name: /^8\. Drift/ });
    await expect(drift).toHaveAttribute('aria-expanded', 'false');
    const chevron = drift.getByTestId('compliance-library-section-chevron');
    expect((await chevron.boundingBox()).width).toBeGreaterThanOrEqual(20);
    await expect(chevron).not.toHaveClass(/rotate-90/);
    expect((await drift.boundingBox()).height).toBeGreaterThanOrEqual(44);
    await drift.click();
    await expect(drift).toHaveAttribute('aria-expanded', 'true');
    await expect(chevron).toHaveClass(/rotate-90/);
    await page.getByRole('button', { name: /8\.3 Utforming og utvikling/ }).click();
    const design = page.getByTestId('compliance-library-requirement-8.3.1');
    await expect(design).toContainText('Betinget');
    await design.getByRole('button', { name: 'Vis veiledning' }).click();
    await expect(page.getByTestId('compliance-library-8.3.1-guidance')).toContainText('Forslag til dokumentasjon');
    // The framework page is for exploring: no activation here, only where it is done.
    await expect(page.getByRole('button', { name: 'Aktiver for virksomheten' })).toHaveCount(0);
    await expect(page.getByTestId('compliance-library-not-activated')).toContainText('Aktiver den fra Kravbiblioteket');
    await expectReadable(page, '02-framework-before-activation');

    // Aktiver for virksomheten — from the card, confirmed in the dialog; the card turns Aktivert.
    await page.getByRole('link', { name: 'Aktiver den fra Kravbiblioteket' }).click();
    await page.waitForURL(/\/app\/compliance\/library$/);
    await card.getByRole('button', { name: 'Aktiver for virksomheten' }).click();
    await dialog.getByRole('button', { name: 'Aktiver for virksomheten' }).click();
    await expect(page.getByText('ISO 9001 er aktivert. Kravene ligger nå i kravregisteret deres.')).toBeVisible();
    await expect(dialog).toHaveCount(0);
    await expect(card).toContainText('Aktivert');
    await expect(card).not.toContainText('Ikke aktivert');
    await expect(card.getByRole('button', { name: 'Aktiver for virksomheten' })).toHaveCount(0);
    await expect(card.getByRole('link', { name: 'Åpne i kravregisteret' })).toBeVisible();

    // The activated card says what is in the register and where to go next — no figures: where the
    // work stands is Etterlevelsesvurdering's.
    await expect(page.getByTestId('compliance-library-card-iso9001:2015-count')).toHaveText('65 krav i kravregisteret');
    await expect(card.getByRole('link', { name: 'Til etterlevelsesvurdering' })).toBeVisible();
    await expect(card).not.toContainText('Vurdert');
    await expectReadable(page, '02b-library-activated');

    await card.getByRole('link', { name: 'Se innholdet' }).click();
    await page.waitForURL(`**/app/compliance/library/${person.framework_id}`);
    await expect(page.getByTestId('compliance-library-activated')).toBeVisible();
    await expect(page.getByTestId('compliance-library-stats')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Aktiver for virksomheten' })).toHaveCount(0);

    // The filter narrows to what still needs a relevance judgement.
    await page.locator('#compliance-library-filter').selectOption('relevance_open');
    await expect(page.getByTestId('compliance-library-requirement-8.3.1')).toBeVisible();
    await expect(page.getByTestId('compliance-library-requirement-9.2.1')).toHaveCount(0);
    await expectReadable(page, '03-register-filtered');

    // A conditional requirement: Ikke relevant, always with a begrunnelse.
    await page.getByTestId('compliance-library-requirement-8.3.1').getByRole('link', { name: /Åpne krav/ }).click();
    await expect(page.getByTestId('compliance-origin-source')).toHaveText('ISO 9001:2015');
    await expect(page.getByTestId('compliance-reference')).toHaveText('8.3.1');
    await expect(page.getByTestId('compliance-library-context')).toContainText('Kravet kommer fra ISO 9001:2015 + A1:2024.');
    const relevance = page.getByTestId('compliance-applicability');
    await relevance.getByRole('button', { name: 'Endre relevans' }).click();
    await relevance.getByLabel('Ikke relevant').check();
    await relevance.locator('#compliance-applicability-rationale').fill('Vi utformer ikke egne tjenester; vi leverer etter kundens spesifikasjon.');
    await relevance.getByRole('button', { name: 'Lagre relevans' }).click();
    await expect(page.getByText('Relevansen er lagret.')).toBeVisible();
    await expect(page.getByTestId('compliance-applicability-rationale')).toHaveText('Vi utformer ikke egne tjenester; vi leverer etter kundens spesifikasjon.');
    await expect(page.getByRole('button', { name: 'Vurder etterlevelse' })).toHaveCount(0);
    await expectReadable(page, '04-requirement-not-applicable');

    // A mandatory requirement: no Ikke relevant to choose, and assessed as only partly met.
    await page.goto(`/app/compliance/library/${person.framework_id}`);
    await page.locator('#compliance-library-search').fill('9.2.1');
    await page.getByTestId('compliance-library-requirement-9.2.1').getByRole('link', { name: /Åpne krav/ }).click();
    await page.getByTestId('compliance-applicability').getByRole('button', { name: 'Endre relevans' }).click();
    await expect(page.getByTestId('compliance-applicability').getByLabel('Ikke relevant')).toHaveCount(0);
    await page.getByTestId('compliance-applicability').getByRole('button', { name: 'Avbryt' }).click();
    await expect(page.getByTestId('compliance-requirement-library-modules')).toContainText('Revisjoner');
    await page.getByRole('button', { name: 'Vurder etterlevelse' }).click();
    const form = page.getByTestId('compliance-assessment-form');
    await expect(form.getByLabel('Ikke relevant')).toHaveCount(0);
    await form.getByLabel('Delvis oppfylt').check();
    await form.locator('#compliance-assessment-rationale').fill('Revisjonsprogrammet for neste år er ikke lagt.');
    await form.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByTestId('compliance-assessment-form')).toHaveCount(0);

    // The figures follow — in Etterlevelsesvurdering, filtered on the standard from its own page.
    await page.goto(`/app/compliance/library/${person.framework_id}`);
    await expectPageHelp(page, 'Om kravpakken', ['Hva brukes siden til?', 'Slik gjør du det', 'Kravene', 'Relevans', 'Vedlegg A og SoA (ISO/IEC 27001)']);
    await page.getByRole('link', { name: 'Til etterlevelsesvurdering' }).click();
    await page.waitForURL(/\/app\/compliance\/assessments\?source=\d+$/);
    await expect(areas.locator('[aria-current="page"]')).toHaveText('Etterlevelsesvurdering');
    for (const [key, value] of [['assessable', '56'], ['assessed', '1'], ['partially_compliant', '1'], ['not_assessed', '55']]) {
        await expect(page.getByTestId(`compliance-assessments-stat-${key}`)).toContainText(value);
    }
    // 8.3.1 is outside the figures, and the eight undecided conditional requirements are too.
    await expect(page.getByTestId('compliance-assessments-stat-not_applicable')).toContainText('Ikke relevant eller utelatt: 1');
    await expect(page.getByTestId('compliance-assessments-stat-relevance_open')).toContainText('Relevans eller kontrollvalg ikke avklart: 8');
    await expect(page.getByTestId('compliance-assessments-progress')).toHaveText('1 av 56 vurdert');
    await expectReadable(page, '05-assessments-after-work');

    // …and the library card stays a card: activated, with the count in the register.
    await page.goto('/app/compliance/library');
    await expect(page.getByTestId('compliance-library-card-iso9001:2015-count')).toHaveText('65 krav i kravregisteret');
});
