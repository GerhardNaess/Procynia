import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { COMPLIANCE_E2E_PASSWORD, cleanUpComplianceE2eData, complianceE2eName, complianceE2eSuffix, complianceFixture } from './helpers/compliance.js';
import { DESKTOP, PHONE, expectPageHelp, expectReadable as expectReadableAt, sidewaysOverflow } from './helpers/readability.js';

const suffix = complianceE2eSuffix();
cleanUpComplianceE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'compliance-workflow', name);

function isoDay(offset) {
    const day = new Date();
    day.setDate(day.getDate() + offset);

    return day.toISOString().slice(0, 10);
}

/**
 * Etterlevelse og revisjon as one workflow, the way a quality manager meets it: Styring →
 * Etterlevelse og revisjon; ISO 14001 chosen and activated in Kravbibliotek; 6.1.2 found in
 * Kravregister with its origin; the same requirement found again in Etterlevelsesvurdering, assessed
 * with a begrunnelse and documentation, and the figures following; ISO 9001 activated beside it and
 * 9.2.1 assessed, each standard counted on its own; an audit planned in Revisjon with 6.1.2 in scope,
 * which never overwrites the assessment; and the way back without losing the context. The four
 * header areas follow the person the whole way. Readability at desktop and 390 px on the way.
 */
test('Kravbibliotek → Kravregister → Etterlevelsesvurdering → Revisjon, and back', async ({ page }) => {
    test.setTimeout(300_000);

    const person = await complianceFixture(`seedWorkflowJourney('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);
    const auditTitle = complianceE2eName(suffix, 'Internrevisjon miljøstyring');
    await loginAs(page, person.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    const areas = page.getByTestId('module-navigation');
    const current = areas.locator('[aria-current="page"]');
    const row = (text) => page.getByTestId('compliance-assessments-row').filter({ hasText: text });
    const stat = (key) => page.getByTestId(`compliance-assessments-stat-${key}`);

    // 1. Styring → Etterlevelse og revisjon: one entry; nothing activated yet, so it opens Kravbibliotek.
    await page.goto('/app/governance');
    await page.getByTestId('governance-module-compliance').click();
    await page.waitForURL(/\/app\/compliance\/library$/);
    await expect(page.getByTestId('module-compliance-areas')).toHaveCount(0);
    await expect(areas.locator('a')).toHaveText(['Kravbibliotek', 'Kravregister', 'Etterlevelsesvurdering', 'Revisjon']);
    await expect(current).toHaveText('Kravbibliotek');

    // The module name is the page heading in every work area, as in Kvalitet; the tab marks the area.
    for (const [area, path] of [['Kravregister', 'requirements'], ['Etterlevelsesvurdering', 'assessments'], ['Revisjon', 'audits'], ['Kravbibliotek', 'library']]) {
        await areas.getByRole('link', { name: area, exact: true }).click();
        await page.waitForURL(new RegExp(`/app/compliance/${path}$`));
        await expect(current).toHaveText(area);
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Etterlevelse og revisjon');
        await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText('Etterlevelse og revisjon');
    }

    // 2. ISO 14001 in Kravbibliotek: explore it, then activate it from its card.
    const card14001 = page.getByTestId('compliance-library-framework-iso14001:2026');
    await card14001.getByRole('link', { name: 'Se innholdet' }).click();
    await expect(page.getByText('Utgave: ISO 14001:2026')).toBeVisible();
    await expect(current).toHaveText('Kravbibliotek');
    await page.goto('/app/compliance/library');
    await card14001.getByRole('button', { name: 'Aktiver for virksomheten' }).click();
    await page.getByRole('dialog', { name: 'Aktiver ISO 14001?' }).getByRole('button', { name: 'Aktiver for virksomheten' }).click();
    await expect(page.getByText('ISO 14001 er aktivert. Kravene ligger nå i kravregisteret deres.')).toBeVisible();
    await expect(page.getByTestId('compliance-library-card-iso14001:2026-count')).toHaveText('35 krav i kravregisteret');
    await expectReadable(page, '01-library-activated');

    // 3. Kravregister: find ISO 14001 6.1.2 — the standard, the edition, the clause and the title.
    await areas.getByRole('link', { name: 'Kravregister', exact: true }).click();
    await page.waitForURL(/\/app\/compliance\/requirements$/);
    await expect(current).toHaveText('Kravregister');
    await page.locator('#compliance-source-filter').selectOption({ label: 'ISO 14001:2026' });
    await page.locator('#compliance-search').fill('6.1.2');
    await page.getByRole('button', { name: 'Søk', exact: true }).click();
    const registerRow = page.locator('tbody tr', { hasText: 'Miljøaspekter' });
    await expect(registerRow.getByTestId('compliance-origin')).toHaveText('ISO 14001:2026 · 6.1.2 · Miljøaspekter');
    await expect(registerRow.getByTestId('compliance-relevance-cell')).toHaveText('Gjelder');
    // The register says what applies; it shows no results.
    await expect(registerRow).not.toContainText('Ikke vurdert');
    await expectReadable(page, '02-register-6.1.2');

    // 4–5. Etterlevelsesvurdering: the same requirement, with its origin, among 35 to assess.
    await areas.getByRole('link', { name: 'Etterlevelsesvurdering', exact: true }).click();
    await page.waitForURL(/\/app\/compliance\/assessments$/);
    await expect(current).toHaveText('Etterlevelsesvurdering');
    await expectPageHelp(page, 'Om etterlevelsesvurdering', ['Slik gjør du det', 'Nøkkeltallene', 'Utenfor tallene']);
    await expect(stat('assessable')).toContainText('35');
    await expect(stat('not_assessed')).toContainText('35');
    await page.locator('#compliance-assessments-source').selectOption({ label: 'ISO 14001:2026' });
    await page.locator('#compliance-assessments-status').selectOption('not_assessed');
    await page.locator('#compliance-assessments-search').fill('Miljøaspekter');
    await page.getByRole('button', { name: 'Filtrer' }).click();
    await expect(page).toHaveURL(/status=not_assessed/);
    await expect(page).toHaveURL(/source=\d+/);
    await expect(page).toHaveURL(/search=Milj/);
    await expect(row('Miljøaspekter').getByTestId('compliance-assessments-origin')).toHaveText('ISO 14001:2026 · 6.1.2 · Miljøaspekter');
    await expect(row('Miljøaspekter')).toContainText('Ikke vurdert');
    await expectReadable(page, '03-worklist-6.1.2');
    const filteredWorklist = page.url();

    // 6. Vurder: the requirement opens with the form ready; the header keeps Etterlevelsesvurdering.
    await row('Miljøaspekter').getByRole('link', { name: /^Vurder/ }).click();
    await page.waitForURL((url) => /\/app\/compliance\/requirements\/\d+$/.test(url.pathname) && url.searchParams.get('from') === 'assessments' && url.searchParams.get('assess') === '1');
    await expect(current).toHaveText('Etterlevelsesvurdering');
    await expect(page.getByTestId('compliance-origin-source')).toHaveText('ISO 14001:2026');
    await expect(page.getByTestId('compliance-reference')).toHaveText('6.1.2');
    await expect(page.getByTestId('compliance-library-context')).toContainText('Fra kravbiblioteket');
    const form = page.getByTestId('compliance-assessment-form');
    await expect(form).toBeVisible();
    await form.getByLabel('Delvis oppfylt').check();
    await form.locator('#compliance-assessment-rationale').fill('Miljøaspektene for kontordriften er kartlagt, men transport og avfall fra leveransene mangler.');
    await form.locator('#compliance-assessment-documentation').fill('Miljøaspektregister 2026 (Wiki)');
    await expectReadable(page, '04-assessment-form');
    await form.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Etterlevelsesvurderingen er registrert.')).toBeVisible();
    await expect(page.getByTestId('compliance-current')).toHaveText('Delvis oppfylt');
    await expect(page.getByTestId('compliance-assessment')).toContainText(person.name);
    await expect(page.getByTestId('compliance-current-documentation')).toHaveText('Miljøaspektregister 2026 (Wiki)');
    // No ansvarlig was ever asked for.
    await expect(page.getByTestId('compliance-facts')).toContainText('Ingen valgt');

    // 7. Back to the same filtered worklist: it is no longer «Ikke vurdert», and the figures follow.
    await page.getByTestId('compliance-back').click();
    await expect(page).toHaveURL(filteredWorklist);
    await expect(row('Miljøaspekter')).toHaveCount(0);
    for (const [key, value] of [['assessable', '35'], ['assessed', '1'], ['partially_compliant', '1'], ['not_assessed', '34']]) {
        await expect(stat(key)).toContainText(value);
    }
    await stat('partially_compliant').getByRole('link').click();
    await expect(row('Miljøaspekter')).toContainText('Delvis oppfylt');
    await expect(row('Miljøaspekter')).toContainText(`av ${person.name}`);
    await expectReadable(page, '05-worklist-after-assessment');

    // ISO 9001 beside it: activated from the library, 9.2.1 assessed — each standard counted once.
    await areas.getByRole('link', { name: 'Kravbibliotek', exact: true }).click();
    const card9001 = page.getByTestId('compliance-library-framework-iso9001:2015');
    await card9001.getByRole('button', { name: 'Aktiver for virksomheten' }).click();
    await page.getByRole('dialog', { name: 'Aktiver ISO 9001?' }).getByRole('button', { name: 'Aktiver for virksomheten' }).click();
    await expect(card9001.getByRole('link', { name: 'Til etterlevelsesvurdering' })).toBeVisible();
    await card9001.getByRole('link', { name: 'Til etterlevelsesvurdering' }).click();
    await page.waitForURL(/\/app\/compliance\/assessments\?source=\d+$/);
    // Nine conditional clauses wait for relevance and are kept outside the 56 to assess.
    await expect(stat('assessable')).toContainText('56');
    await expect(stat('relevance_open')).toContainText('Relevans eller kontrollvalg ikke avklart: 9');
    await page.locator('#compliance-assessments-search').fill('9.2.1');
    await page.getByRole('button', { name: 'Filtrer' }).click();
    await expect(row('Gjennomføre internrevisjoner').getByTestId('compliance-assessments-origin')).toHaveText('ISO 9001:2015 · 9.2.1 · Gjennomføre internrevisjoner');
    await row('Gjennomføre internrevisjoner').getByRole('link', { name: /^Vurder/ }).click();
    await form.getByLabel('Oppfylt', { exact: true }).check();
    await form.locator('#compliance-assessment-rationale').fill('Revisjonsprogrammet for 2026 er gjennomført som planlagt.');
    await form.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByTestId('compliance-current')).toHaveText('Oppfylt');
    await page.getByTestId('compliance-back').click();
    await page.getByRole('button', { name: 'Nullstill' }).click();
    await page.waitForURL(/\/app\/compliance\/assessments$/);
    const perSource = page.getByTestId('compliance-assessments-per-source');
    await expect(perSource).toContainText('ISO 9001:2015');
    await expect(perSource).toContainText('ISO 14001:2026');
    // Both standards together: 35 + 56 to assess, one result each.
    for (const [key, value] of [['assessable', '91'], ['assessed', '2'], ['compliant', '1'], ['partially_compliant', '1'], ['not_assessed', '89']]) {
        await expect(stat(key)).toContainText(value);
    }
    await expectReadable(page, '06-worklist-two-standards');

    // 8. Revisjon: plan an audit with ISO 14001 in scope — 6.1.2 named by its origin there too.
    await areas.getByRole('link', { name: 'Revisjon', exact: true }).click();
    await page.waitForURL(/\/app\/compliance\/audits$/);
    await expect(current).toHaveText('Revisjon');
    await page.getByRole('button', { name: 'Ny revisjon' }).click();
    await page.locator('#compliance-audit-title').fill(auditTitle);
    await page.locator('#compliance-audit-responsible').selectOption({ label: person.name });
    await page.locator('#compliance-audit-end').fill(isoDay(30));
    await page.locator('#compliance-audit-scope').fill('Miljøstyringssystemet, kapittel 6.');
    await page.getByTestId('compliance-audit-form').getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/compliance\/audits\/\d+$/);
    await expect(current).toHaveText('Revisjon');
    const scope = page.getByTestId('compliance-audit-requirements');
    await scope.getByRole('button', { name: 'Legg til krav' }).click();
    await page.locator('#compliance-audit-source').selectOption({ label: 'ISO 14001:2026 (35 krav som gjelder)' });
    await page.getByTestId('compliance-audit-source-form').getByRole('button', { name: 'Legg til fra kilde' }).click();
    await expect(page.getByTestId('compliance-audit-requirement')).toHaveCount(35);
    await expect(scope).toContainText('ISO 14001:2026 · 6.1.2 · Miljøaspekter');
    await expectReadable(page, '07-audit-scope');

    // The audit is a check of compliance, not an assessment: 6.1.2 is still «Delvis oppfylt».
    await scope.getByRole('link', { name: 'ISO 14001:2026 · 6.1.2 · Miljøaspekter' }).click();
    await page.waitForURL(/\/app\/compliance\/requirements\/\d+$/);
    await expect(current).toHaveText('Kravregister');
    await expect(page.getByTestId('compliance-current')).toHaveText('Delvis oppfylt');

    // 9. Back without losing the context: the browser's own back, and the header.
    await page.goBack();
    await expect(page).toHaveURL(/\/app\/compliance\/audits\/\d+$/);
    await expect(current).toHaveText('Revisjon');
    await page.goto(filteredWorklist);
    await expect(page.locator('#compliance-assessments-status')).toHaveValue('not_assessed');
    await expect(page.locator('#compliance-assessments-search')).toHaveValue('Miljøaspekter');
    await areas.getByRole('link', { name: 'Kravbibliotek', exact: true }).click();
    await expect(current).toHaveText('Kravbibliotek');

    // On a phone the four areas and the worklist read without sideways scrolling.
    await page.setViewportSize(PHONE);
    await page.goto('/app/compliance/assessments');
    await expect(areas.locator('a')).toHaveCount(4);
    expect(await sidewaysOverflow(page)).toEqual([]);
});
