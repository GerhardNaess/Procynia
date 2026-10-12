import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { COMPLIANCE_E2E_PASSWORD, cleanUpComplianceE2eData, complianceE2eName, complianceE2eSuffix, complianceFixture } from './helpers/compliance.js';
import { DESKTOP, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';

const suffix = complianceE2eSuffix();
cleanUpComplianceE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'compliance-audit-planning', name);

/**
 * Revisjon 2.0, end to end — «Internrevisjon av leverandørstyring»: planned in one form with formål,
 * omfang and ISO 9001 8.4 and 8.5 and ISO/IEC 27001 A.5.19; started; one kontrollhandling
 * documenting the examination of 8.4 and A.5.19 together, with observation and documentation; an
 * avvik recorded from that check; the report showing what was examined, what was not (8.5) and what
 * waits for follow-up; completed with a conclusion; and the avvik handed off to Avvik og forbedringer,
 * whose case names the requirement's origin. Every step is checked for text under 16 px and
 * sideways scrolling at desktop and 390 px.
 */
test('an audit is planned, examined, documented, concluded and followed up', async ({ page }) => {
    test.setTimeout(300_000);

    const person = await complianceFixture(`seedAuditPlanningJourney('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);
    const title = complianceE2eName(suffix, 'Internrevisjon av leverandørstyring');
    const findingTitle = complianceE2eName(suffix, 'Leverandørvurdering mangler for ny leverandør');

    await loginAs(page, person.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // 1–3. Opprett revisjon, angi formål og omfang, og velg krav — in one form.
    await page.goto('/app/compliance/audits');
    await page.getByRole('button', { name: 'Ny revisjon' }).click();
    await page.locator('#compliance-audit-title').fill(title);
    await page.locator('#compliance-audit-responsible').selectOption({ label: person.name });
    await page.locator('#compliance-audit-auditor').fill('Kvalitetsavdelingen');
    await page.locator('#compliance-audit-start').fill('2026-11-01');
    await page.locator('#compliance-audit-end').fill('2026-11-15');
    await page.locator('#compliance-audit-purpose').fill('Kontrollere at leverandørstyringen etterlever relevante krav.');
    await page.locator('#compliance-audit-scope').fill('Innkjøpsprosessen og leverandøroppfølging.');
    const selection = page.getByTestId('compliance-audit-create-requirements');
    await expect(selection).toContainText('Legg til fra kravkilde');
    await selection.locator('#compliance-audit-create-requirement-filter').fill(suffix);
    for (const label of [person.requirement_8_4, person.requirement_8_5, person.requirement_a_5_19]) {
        await selection.getByRole('checkbox', { name: label }).check();
    }
    await expect(page.getByTestId('compliance-audit-create-selected')).toHaveText('0 kravkilder og 3 enkeltkrav er valgt.');
    await expectReadable(page, '01-plan');
    await page.getByTestId('compliance-audit-form').getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/compliance\/audits\/\d+$/);
    await expect(page.getByText('Revisjonen er registrert.')).toBeVisible();

    // Planlegging: what, why, who and when — and the requirements with their origin.
    await expect(page.getByRole('tab', { name: /Planlegging/ })).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('tab', { name: /Planlegging/ })).toContainText('Nåværende fase');
    await expect(page.getByTestId('compliance-audit-purpose')).toHaveText('Kontrollere at leverandørstyringen etterlever relevante krav.');
    await expect(page.getByTestId('compliance-audit-scope')).toHaveText('Innkjøpsprosessen og leverandøroppfølging.');
    await expect(page.getByTestId('compliance-audit-requirement')).toHaveCount(3);
    await expect(page.getByTestId('compliance-audit-requirements')).toContainText(person.requirement_8_4);
    await expect(page.getByTestId('compliance-audit-requirements')).toContainText(person.requirement_a_5_19);
    await expectPageHelp(page, 'Om revisjonen', ['Slik gjør du det', 'Faser', 'Omfang', 'Kontrollhandlinger', 'Funn', 'Gjennomføring', 'Statushistorikk']);
    await expectReadable(page, '02-planning');

    // 4. Start revisjonen — the page moves on to Gjennomføring.
    await page.getByTestId('compliance-audit-start-hint').getByRole('button', { name: 'Start revisjon' }).click();
    await page.getByTestId('compliance-audit-start').getByRole('button', { name: 'Start revisjon' }).click();
    await expect(page.getByTestId('compliance-audit-status')).toHaveText('Under arbeid');
    await expect(page.getByRole('tab', { name: /Gjennomføring/ })).toHaveAttribute('aria-selected', 'true');
    const coverage = page.getByTestId('compliance-audit-coverage');
    await expect(coverage.getByTestId('compliance-audit-coverage-row')).toHaveCount(3);
    await expect(coverage).toContainText('Ingen dokumentert undersøkelse');

    // 5. Én kontrollhandling for to standarder.
    const checks = page.getByTestId('compliance-audit-checks');
    await checks.getByRole('button', { name: 'Ny kontrollhandling' }).click();
    const checkForm = page.getByTestId('compliance-check-form');
    await checkForm.getByLabel('Kontrollhandling').fill('Gjennomgang av leverandørvurderinger');
    await checkForm.getByLabel('Observasjon').fill('Tre leverandørvurderinger fra inneværende år ble gjennomgått. Innkjøpsansvarlig ble intervjuet. To av tre vurderinger var dokumentert.');
    await checkForm.getByLabel('Dokumentasjon').fill('Leverandørregister 2026, Wiki: Leverandøroppfølging');
    await checkForm.getByRole('checkbox', { name: person.requirement_8_4 }).check();
    await checkForm.getByRole('checkbox', { name: person.requirement_a_5_19 }).check();
    await expectReadable(page, '03-check-form');
    await checkForm.getByRole('button', { name: 'Lagre kontrollhandling' }).click();
    await expect(page.getByText('Kontrollhandlingen er registrert.')).toBeVisible();

    const check = checks.getByTestId('compliance-check');
    await expect(check).toHaveCount(1);
    await expect(check.getByTestId('compliance-check-requirements')).toContainText(person.requirement_8_4);
    await expect(check.getByTestId('compliance-check-requirements')).toContainText(person.requirement_a_5_19);
    await expect(check.getByTestId('compliance-check-evidence')).toHaveText('Leverandørregister 2026, Wiki: Leverandøroppfølging');
    await expect(check.getByTestId('compliance-check-no-findings')).toBeVisible();
    await expect(coverage.getByTestId('compliance-audit-coverage-row').filter({ hasText: person.requirement_8_4 })).toContainText('1 kontrollhandling');
    await expect(coverage.getByTestId('compliance-audit-coverage-row').filter({ hasText: person.requirement_8_5 })).toContainText('Ingen dokumentert undersøkelse');

    // 6. Registrer funn fra kontrollhandlingen.
    await check.getByRole('button', { name: 'Registrer funn' }).click();
    const findingForm = page.getByTestId('compliance-finding-form');
    await expect(findingForm.locator('select[id$="-check"]')).toHaveValue(/\d+/);
    await findingForm.getByRole('radio', { name: /Avvik/ }).check();
    await findingForm.getByLabel('Tittel').fill(findingTitle);
    await findingForm.getByLabel('Beskrivelse').fill('Den nyeste leverandøren er tatt i bruk uten dokumentert leverandørvurdering.');
    await findingForm.locator('select[id$="-requirement"]').selectOption({ label: person.requirement_8_4 });
    await findingForm.getByRole('button', { name: 'Lagre funn' }).click();
    await expect(page.getByText('Funnet er registrert.')).toBeVisible();
    await expect(check.getByTestId('compliance-check-findings')).toContainText(findingTitle);
    const finding = page.getByTestId('compliance-finding');
    await expect(finding.getByTestId('compliance-finding-check')).toHaveText('Gjennomgang av leverandørvurderinger');
    await expect(finding.getByTestId('compliance-finding-context')).toContainText(person.requirement_8_4);
    // A check with a finding is not deleted.
    await expect(check.getByRole('button', { name: 'Slett', exact: true })).toHaveCount(0);
    await expectReadable(page, '04-execution');

    // 7. Fullfør med konklusjon — the report first.
    await page.getByTestId('compliance-audit-actions').getByRole('button', { name: 'Fullfør revisjon' }).click();
    await expect(page.getByRole('tab', { name: /Rapport og oppfølging/ })).toHaveAttribute('aria-selected', 'true');
    const report = page.getByTestId('compliance-audit-report');
    await expect(report.getByTestId('compliance-audit-report-checks')).toContainText('Gjennomgang av leverandørvurderinger');
    await expect(report.getByTestId('compliance-audit-report-examined')).toContainText(person.requirement_8_4);
    await expect(report.getByTestId('compliance-audit-report-examined')).toContainText(person.requirement_a_5_19);
    await expect(report.getByTestId('compliance-audit-report-unexamined-list')).toHaveText(person.requirement_8_5);
    await expect(report.getByTestId('compliance-audit-report-follow-up')).toContainText(findingTitle);
    await expect(report).toContainText('At et krav er undersøkt, betyr ikke at det er oppfylt.');
    await page.locator('#compliance-audit-complete-conclusion').fill('Leverandørstyringen etterleves i hovedsak. Ett avvik: leverandørvurdering mangler for ny leverandør. 8.5 ble ikke undersøkt i denne revisjonen.');
    await expectReadable(page, '05-report-complete');
    await page.getByTestId('compliance-audit-complete').getByRole('button', { name: 'Fullfør revisjon' }).click();
    await expect(page.getByText('Revisjonen er fullført.')).toBeVisible();
    await expect(page.getByTestId('compliance-audit-status')).toHaveText('Fullført');
    await expect(page.getByTestId('compliance-audit-conclusion')).toContainText('Leverandørstyringen etterleves i hovedsak.');

    // Locked: no more checks.
    await page.getByRole('tab', { name: /Gjennomføring/ }).click();
    await expect(page.getByTestId('compliance-checks-locked')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Ny kontrollhandling' })).toHaveCount(0);

    // 8. Følg opp avvik.
    await finding.getByRole('button', { name: 'Følg opp i Avvik og forbedringer' }).click();
    const handoff = page.getByTestId('compliance-handoff-form');
    await handoff.locator('select[id$="-area"]').selectOption({ label: person.area_name });
    await handoff.locator('select[id$="-owner"]').selectOption({ label: person.name });
    await handoff.getByRole('button', { name: 'Opprett sak' }).click();
    await expect(page.getByText('Funnet er overført til Avvik og forbedringer.')).toBeVisible();
    await expect(finding.getByTestId('compliance-finding-handed-off')).toBeVisible();

    // Historikk: started and completed, by the person.
    const history = page.getByTestId('compliance-audit-history').locator('li');
    await expect(history).toHaveCount(2);
    await expect(history.nth(0)).toContainText(`Fullført av ${person.name}`);
    await expectReadable(page, '06-followed-up');

    // The case names the audit and the requirement's origin.
    await finding.getByTestId('compliance-finding-case-link').click();
    await page.waitForURL(/\/app\/improvements\/\d+$/);
    await expect(page.getByText(title)).toBeVisible();
    await expect(page.getByText(person.requirement_8_4)).toBeVisible();
    await expectReadable(page, '07-case');
});
