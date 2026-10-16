import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { expectNoAxeViolations } from './helpers/a11y.js';
import { COMPLIANCE_E2E_PASSWORD, cleanUpComplianceE2eData, complianceE2eName, complianceE2eSuffix, complianceFixture } from './helpers/compliance.js';
import { DESKTOP, sidewaysOverflow } from './helpers/readability.js';

/**
 * WCAG Fase 6 — Etterlevelse og revisjon, in a real browser (docs/wcag-accessibility-plan.md §8).
 *
 * Tests\Support\ComplianceE2EFixture::seedAuditJourney seeds a fresh person who may view and audit
 * (and read Kvalitet), a source with two requirements and a Kvalitet process. The spec creates its
 * own audit through the pages; ComplianceE2EFixture removes everything by the run's suffix.
 *
 * What is checked is what axe cannot see: where focus goes when a panel opens and closes, after a
 * refused submit, after a removal and after an in-page link; that errors are tied to their fields;
 * that tables carry their semantics; that the hit count is spoken; and that 320 px does not scroll
 * sideways. axe runs on each surface as it is reached.
 */

const suffix = complianceE2eSuffix();
cleanUpComplianceE2eData(suffix);

const NARROW = { width: 320, height: 720 };
const polite = (page) => page.locator('#procynia-live-polite');
const focusIsLost = (page) => page.evaluate(() => ! document.activeElement || document.activeElement === document.body);

async function expectNoSidewaysScroll(page, label) {
    await page.setViewportSize(NARROW);
    expect(await sidewaysOverflow(page), `${label} at 320 px`).toEqual([]);
    await page.setViewportSize(DESKTOP);
}

// cleanUpComplianceE2eData removes the run's data after each test, so each test seeds its own person.
const seed = () => complianceFixture(`seedAuditJourney('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);

test.describe.serial('WCAG Fase 6 — Etterlevelse og revisjon', () => {
    const title = complianceE2eName(suffix, 'Internrevisjon tilgjengelighet');

    // One audit over several steps, so it is one test.
    test('an audit, from the register to the report, keeps focus, names and structure', async ({ page }) => {
        test.setTimeout(300_000);

        const person = await seed();
        await loginAs(page, person.email, COMPLIANCE_E2E_PASSWORD);
        await page.setViewportSize(DESKTOP);

        // 1. Revisjoner: table semantics and Ny revisjon opening with focus in its first field.
        await page.goto('/app/compliance/audits');
        await expectNoAxeViolations(page, 'main', 'Revisjoner');
        await expectNoSidewaysScroll(page, 'Revisjoner');

        const create = page.getByRole('button', { name: 'Ny revisjon' });
        await create.click();
        await expect(page.locator('#compliance-audit-title')).toBeFocused();
        await expect(page.locator('#compliance-audit-title')).toHaveAttribute('aria-required', 'true');
        await expect(page.getByTestId('compliance-audit-form').getByRole('radiogroup')).toHaveAttribute('aria-required', 'true');
        await expect(page.getByTestId('compliance-audit-form')).toContainText('Felt merket med * må fylles ut.');
        await expectNoAxeViolations(page, '[data-testid="compliance-audit-form"]', 'Ny revisjon');

        // Avbryt hands focus back to Ny revisjon.
        await page.getByTestId('compliance-audit-form').getByRole('button', { name: 'Avbryt' }).click();
        await expect(create).toBeFocused();

        await create.click();
        await page.locator('#compliance-audit-title').fill(title);
        await page.locator('#compliance-audit-responsible').selectOption({ label: person.name });
        await page.locator('#compliance-audit-end').fill('2026-11-15');
        await page.getByTestId('compliance-audit-form').getByRole('button', { name: 'Lagre', exact: true }).click();
        await page.waitForURL(/\/app\/compliance\/audits\/\d+$/);
        const auditUrl = page.url();

        // The register table, now with a row: caption, column headers and a row header.
        await page.goto('/app/compliance/audits');
        const table = page.getByTestId('compliance-audit-table');
        await expect(table.locator('caption')).toHaveText('Revisjoner');
        await expect(table.locator('thead th[scope="col"]')).toHaveCount(5);
        await expect(table.locator('tbody th[scope="row"]').filter({ hasText: title })).toHaveCount(1);
        // Status is text, not only a colour.
        await expect(table.locator('tbody tr').filter({ hasText: title })).toContainText('Planlagt');

        // A search speaks its hit count once.
        await page.locator('#compliance-audit-search').fill(title);
        await page.getByRole('button', { name: 'Søk', exact: true }).click();
        await expect(polite(page)).toHaveText(/1 revisjon/);

        // 2. The audit: Legg til krav opens with focus in the picker and returns to its button.
        await page.goto(auditUrl);
        await expect(page.getByRole('tab', { name: /Planlegging/ })).toHaveAttribute('aria-selected', 'true');
        const requirements = page.getByTestId('compliance-audit-requirements');
        const addRequirement = requirements.getByRole('button', { name: 'Legg til krav' });

        await addRequirement.click();
        await expect(page.locator('#compliance-audit-requirement-filter')).toBeFocused();
        await expect(page.getByRole('group', { name: 'Velg krav' })).toBeVisible();
        await page.getByTestId('compliance-audit-requirement-picker').getByRole('button', { name: 'Avbryt' }).click();
        await expect(addRequirement).toBeFocused();

        await addRequirement.click();
        await page.getByRole('checkbox', { name: person.first_requirement }).check();
        await page.getByRole('checkbox', { name: person.second_requirement }).check();
        await page.getByTestId('compliance-audit-requirement-form').getByRole('button', { name: 'Legg til valgte krav' }).click();
        await expect(page.getByTestId('compliance-audit-requirement')).toHaveCount(2);
        await expectNoAxeViolations(page, '[data-testid="compliance-audit-requirements"]', 'Krav i omfanget');

        // «Fjern fra omfanget» is described by its requirement, and removing a row hands focus on.
        const firstRemove = page.getByTestId('compliance-audit-requirement').first().getByRole('button', { name: 'Fjern fra omfanget' });
        const describedBy = await firstRemove.getAttribute('aria-describedby');
        await expect(page.locator(`#${describedBy}`)).toContainText(/Tilgang/);

        page.once('dialog', (dialog) => dialog.accept());
        await firstRemove.click();
        await expect(page.getByTestId('compliance-audit-requirement')).toHaveCount(1);
        await expect(page.getByTestId('compliance-audit-requirement').getByRole('button', { name: 'Fjern fra omfanget' })).toBeFocused();
        expect(await focusIsLost(page)).toBe(false);

        // 3. Start revisjon: focus into the step's form, and to the new status once it has gone through.
        await page.getByTestId('compliance-audit-actions').getByRole('button', { name: 'Start revisjon' }).click();
        await expect(page.locator('#compliance-audit-start-reason')).toBeFocused();
        await page.getByTestId('compliance-audit-start').getByRole('button', { name: 'Start revisjon' }).click();
        await expect(page.getByTestId('compliance-audit-status')).toHaveText('Under arbeid');
        await expect(page.locator('#compliance-audit-status')).toBeFocused();

        // 4. Gjennomføring: Ny kontrollhandling opens with focus in it and returns to its button.
        await page.getByRole('tab', { name: /Gjennomføring/ }).click();
        const checks = page.getByTestId('compliance-audit-checks');
        const newCheck = checks.getByRole('button', { name: 'Ny kontrollhandling' });
        await newCheck.click();
        await expect(page.locator('#compliance-check-new-title')).toBeFocused();
        await page.locator('#compliance-check-new-title').fill('Stikkprøve av tilganger');
        await page.locator('#compliance-check-new-observation').fill('Ti brukere kontrollert mot HR-listen.');
        await checks.getByRole('button', { name: 'Lagre kontrollhandling' }).click();
        await expect(page.getByTestId('compliance-check')).toHaveCount(1);
        await expect(newCheck).toBeFocused();
        await expectNoAxeViolations(page, '[data-testid="compliance-audit-checks"]', 'Kontrollhandlinger');
        await expectNoAxeViolations(page, '[data-testid="compliance-audit-findings"]', 'Funn');

        // An address pointing at the check puts focus on it (A11Y-015).
        const checkId = await page.getByTestId('compliance-check').getAttribute('id');
        await page.goto('about:blank');
        await page.goto(`${auditUrl}#${checkId}`);
        await expect(page.locator(`#${checkId}`)).toBeFocused();

        // 5. Rapport: Fullfør without a conclusion is refused — focus on the field, which says why.
        await page.getByRole('tab', { name: /Rapport og oppfølging/ }).click();
        await page.getByTestId('compliance-audit-actions').getByRole('button', { name: 'Fullfør revisjon' }).click();
        const conclusion = page.locator('#compliance-audit-complete-conclusion');
        await expect(conclusion).toBeFocused();
        await conclusion.fill('');
        await page.getByTestId('compliance-audit-complete').getByRole('button', { name: 'Fullfør revisjon' }).click();
        await expect(conclusion).toHaveAttribute('aria-invalid', 'true');
        await expect(conclusion).toBeFocused();
        const description = await conclusion.getAttribute('aria-describedby');
        expect(description.split(' ')).toContain('compliance-audit-complete-conclusion-error');
        await expect(page.locator('#compliance-audit-complete-conclusion-error')).toHaveText('Skriv en konklusjon før revisjonen fullføres.');
        await expectNoAxeViolations(page, '[data-testid="compliance-audit-complete"]', 'Fullfør revisjon');

        // Avbryt returns focus to Fullfør revisjon.
        await page.getByTestId('compliance-audit-complete').getByRole('button', { name: 'Avbryt' }).click();
        await expect(page.getByTestId('compliance-audit-actions').getByRole('button', { name: 'Fullfør revisjon' })).toBeFocused();

        // The phases are real tabs, moved between with the arrow keys.
        await page.getByRole('tab', { name: /Rapport og oppfølging/ }).focus();
        await page.keyboard.press('ArrowLeft');
        await expect(page.getByRole('tab', { name: /Gjennomføring/ })).toBeFocused();
        await expect(page.getByRole('tab', { name: /Gjennomføring/ })).toHaveAttribute('aria-selected', 'true');

        await expectNoSidewaysScroll(page, 'Revisjonen');
    });

    test('Kravregister and Etterlevelsesvurdering: headings, table semantics and spoken hit counts', async ({ page }) => {
        const person = await seed();
        await loginAs(page, person.email, COMPLIANCE_E2E_PASSWORD);
        await page.setViewportSize(DESKTOP);

        // Gruppert: h1 → h2 → h3 (source) → h4 (chapter), no level skipped.
        await page.goto('/app/compliance/requirements');
        await expect(page.getByRole('heading', { level: 2, name: 'Kravregister' })).toHaveCount(1);
        const levels = await page.locator('main').evaluate((main) => [...main.querySelectorAll('h1, h2, h3, h4, h5, h6')].map((heading) => Number(heading.tagName[1])));
        levels.forEach((level, index) => {
            if (index > 0) {
                expect(level - levels[index - 1], `heading ${index}`).toBeLessThanOrEqual(1);
            }
        });
        await expectNoAxeViolations(page, 'main', 'Kravregister');
        await expectNoSidewaysScroll(page, 'Kravregister');

        // Liste: the table names itself and its columns, and the search speaks its hits once.
        await page.goto(`/app/compliance/requirements?view=list&search=${encodeURIComponent(person.first_requirement)}`);
        const table = page.getByTestId('compliance-requirement-table');
        await expect(table.locator('caption')).toHaveText('Kravregister');
        await expect(table.locator('thead th[scope="col"]')).toHaveCount(3);
        await expect(table.locator('tbody th[scope="row"]')).toHaveCount(1);

        await page.locator('#compliance-search').fill(person.second_requirement);
        await page.getByRole('button', { name: 'Søk', exact: true }).click();
        await expect(polite(page)).toHaveText(/1 passer søket og filtrene/);

        // Kravkilder opens with focus on its heading and closes back to its button.
        await page.goto('/app/compliance/requirements');
        const sourcesButton = page.getByRole('button', { name: 'Kravkilder', exact: true });
        await sourcesButton.click();
        await expect(page.locator('#compliance-sources-heading')).toBeFocused();
        await expectNoAxeViolations(page, '[data-testid="compliance-sources"]', 'Kravkilder');
        await page.getByTestId('compliance-sources').getByRole('button', { name: 'Lukk' }).click();
        await expect(sourcesButton).toBeFocused();

        // Etterlevelsesvurdering: the figures are a valid definition list, and the worklist passes axe.
        await page.goto('/app/compliance/assessments');
        await expectNoAxeViolations(page, 'main', 'Etterlevelsesvurdering');
        await expectNoSidewaysScroll(page, 'Etterlevelsesvurdering');
    });

    test('Kravbibliotek: headings follow the tree, and the tree speaks its hits', async ({ page }) => {
        const person = await seed();
        await loginAs(page, person.email, COMPLIANCE_E2E_PASSWORD);
        await page.setViewportSize(DESKTOP);

        await page.goto('/app/compliance/library');
        await expectNoAxeViolations(page, 'main', 'Kravbibliotek');
        await expectNoSidewaysScroll(page, 'Kravbibliotek');

        const firstFramework = page.getByTestId('compliance-library-frameworks').getByRole('link', { name: 'Se innholdet' }).first();
        test.skip(await firstFramework.count() === 0, 'No framework in the library catalogue.');
        await firstFramework.click();
        await page.waitForURL(/\/app\/compliance\/library\/\d+$/);

        const levels = await page.locator('main').evaluate((main) => [...main.querySelectorAll('h1, h2, h3, h4, h5, h6')].map((heading) => Number(heading.tagName[1])));
        levels.forEach((level, index) => {
            if (index > 0) {
                expect(level - levels[index - 1], `heading ${index}`).toBeLessThanOrEqual(1);
            }
        });

        await page.locator('#compliance-library-search').fill('ledelse');
        await expect(polite(page)).toHaveText(/krav passer søket|Ingen krav passer søket/, { timeout: 5_000 });
        await expectNoAxeViolations(page, 'main', 'Standard');
        await expectNoSidewaysScroll(page, 'Standard');
    });
});
