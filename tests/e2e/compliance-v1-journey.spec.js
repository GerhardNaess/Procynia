import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { COMPLIANCE_E2E_PASSWORD, cleanUpComplianceE2eData, complianceE2eName, complianceE2eSuffix, complianceFixture } from './helpers/compliance.js';
import { DESKTOP, expectReadable as expectReadableAt } from './helpers/readability.js';

const suffix = complianceE2eSuffix();
cleanUpComplianceE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'compliance-v1', name);

function isoDay(offset) {
    const day = new Date();
    day.setDate(day.getDate() + offset);

    return day.toISOString().slice(0, 10);
}

/**
 * Etterlevelse og revisjon v1, in one go: Krav → vurdering → Kvalitet-kontekst → Attention →
 * Revisjon → Funn → Avvik og forbedringer.
 *
 * One person who may do all of it reaches the module from Styring in the left menu, registers a
 * source and a requirement, links a Kvalitet process and control and sees the control's evidence,
 * assesses the requirement, then plans an audit whose planned end has already passed — «Revisjon
 * forfalt» — takes the requirement and the process into scope, starts it, records an avvik,
 * completes it — now «Avvik uten oppfølging» — hands the avvik off, sees the attention disappear,
 * opens the case and follows the provenance back. Readability at desktop and 390 px on the way.
 */
test('Krav to vurdering to Kvalitet to revisjon to funn to Avvik og forbedringer', async ({ page }) => {
    test.setTimeout(300_000);

    const person = await complianceFixture(`seedV1Journey('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);
    const sourceName = complianceE2eName(suffix, 'ISO 27001');
    const requirementTitle = complianceE2eName(suffix, 'Tilgangsstyring');
    const auditTitle = complianceE2eName(suffix, 'Internrevisjon tilgangsstyring');
    const findingTitle = complianceE2eName(suffix, 'Sluttede brukere har tilgang');

    await loginAs(page, person.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // The left menu: Styring → Etterlevelse og revisjon → Krav | Revisjoner.
    await page.goto('/app/dashboard');
    const rail = page.getByTestId('module-sidebar');
    await expect(rail.getByTestId('module-compliance')).toHaveText('Etterlevelse og revisjon');
    await expect(rail.getByTestId('module-compliance-areas').locator('a')).toHaveText(['Krav', 'Revisjoner']);
    await rail.getByTestId('module-area-compliance-requirements').click();
    await page.waitForURL(/\/app\/compliance\/requirements$/);
    await expect(rail.getByTestId('module-area-compliance-requirements')).toHaveAttribute('aria-current', 'page');
    await expect(page.getByTestId('module-navigation').locator('a')).toHaveText(['Krav', 'Revisjoner']);

    // 1. Kravkilde.
    await page.getByRole('button', { name: 'Kravkilder', exact: true }).click();
    const sources = page.getByTestId('compliance-sources');
    await sources.getByRole('button', { name: 'Ny kravkilde' }).click();
    await page.locator('#compliance-source-name').fill(sourceName);
    await page.locator('#compliance-source-kind').selectOption({ label: 'Standard' });
    await sources.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Kravkilden er opprettet.')).toBeVisible();

    // 2. Krav.
    await page.getByRole('button', { name: 'Nytt krav' }).click();
    await page.locator('#compliance-requirement-source').selectOption({ label: sourceName });
    await page.locator('#compliance-requirement-reference').fill('A.5.15');
    await page.locator('#compliance-requirement-title').fill(requirementTitle);
    await page.locator('#compliance-requirement-text').fill('Tilganger skal tildeles, gjennomgås og fjernes etter fastsatte regler.');
    await page.locator('#compliance-requirement-owner').selectOption({ label: person.name });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/compliance\/requirements\/\d+$/);
    await expect(page.getByTestId('compliance-show-attention')).toContainText('Ikke vurdert');

    // 3–5. Kvalitet: process, control, and the control's evidence.
    const section = page.getByTestId('compliance-quality');
    await section.getByRole('button', { name: 'Legg til prosess' }).click();
    await page.locator('#compliance-process-link').selectOption({ label: person.process_title });
    await page.getByTestId('compliance-process-link-form').getByRole('button', { name: 'Koble' }).click();
    await expect(page.getByTestId('compliance-process')).toContainText(person.process_title);
    await section.getByRole('button', { name: 'Legg til kontroll' }).click();
    await page.locator('#compliance-control-link').selectOption({ label: person.control_title });
    await page.getByTestId('compliance-control-link-form').getByRole('button', { name: 'Koble' }).click();
    await expect(page.getByTestId('compliance-control').getByTestId('compliance-evidence')).toContainText(person.evidence_title);

    // 6. Vurder etterlevelse — the requirement no longer needs attention.
    await page.getByTestId('compliance-assessment').getByRole('button', { name: 'Vurder etterlevelse' }).click();
    await page.locator('#compliance-assessment-result-compliant').check();
    await page.locator('#compliance-assessment-rationale').fill('Kontrollen gjennomføres kvartalsvis og er dokumentert.');
    await page.getByTestId('compliance-assessment-form').getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByTestId('compliance-current')).toHaveText('Oppfylt');
    await expect(page.getByTestId('compliance-show-attention')).toHaveCount(0);
    await expectReadable(page, '01-requirement-assessed');

    // 7. Revisjon — planned to end yesterday, so it is already overdue.
    await rail.getByTestId('module-area-compliance-audits').click();
    await page.waitForURL(/\/app\/compliance\/audits$/);
    await expect(rail.getByTestId('module-area-compliance-audits')).toHaveAttribute('aria-current', 'page');
    await page.getByRole('button', { name: 'Ny revisjon' }).click();
    await page.locator('#compliance-audit-title').fill(auditTitle);
    await page.locator('#compliance-audit-responsible').selectOption({ label: person.name });
    await page.locator('#compliance-audit-end').fill(isoDay(-1));
    await page.locator('#compliance-audit-scope').fill('Tilgangsstyring for økonomisystemet.');
    await page.getByTestId('compliance-audit-form').getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/compliance\/audits\/\d+$/);
    const auditUrl = page.url();
    await expect(page.getByTestId('compliance-audit-show-attention')).toContainText('Revisjon forfalt');

    await page.goto('/app/compliance/audits');
    const panel = page.getByTestId('compliance-audit-attention');
    await expect(panel.getByTestId('compliance-audit-attention-item').filter({ hasText: auditTitle })).toContainText('Revisjon forfalt');
    await page.getByTestId('compliance-audit-attention-filter').check();
    await page.getByRole('button', { name: 'Søk', exact: true }).click();
    await expect(page.getByTestId('compliance-audit-table').getByRole('link', { name: auditTitle })).toBeVisible();
    await expectReadable(page, '02-audit-register-attention');
    await page.getByRole('button', { name: 'Nullstill' }).click();
    await expect(page.getByTestId('compliance-audit-attention-filter')).not.toBeChecked();
    await page.goto(auditUrl);

    // 8. Scope: the requirement and the process.
    await page.getByTestId('compliance-audit-requirements').getByRole('button', { name: 'Legg til krav' }).click();
    await page.getByRole('checkbox', { name: requirementTitle }).check();
    await page.getByTestId('compliance-audit-requirement-form').getByRole('button', { name: 'Legg til valgte krav' }).click();
    await expect(page.getByTestId('compliance-audit-requirement')).toHaveCount(1);
    await page.getByTestId('compliance-audit-processes').getByRole('button', { name: 'Legg til prosess' }).click();
    await page.locator('#compliance-audit-process').selectOption({ label: person.process_title });
    await page.getByTestId('compliance-audit-process-form').getByRole('button', { name: 'Legg til', exact: true }).click();
    await expect(page.getByTestId('compliance-audit-process')).toHaveCount(1);

    // 9. Start.
    await page.getByTestId('compliance-audit-actions').getByRole('button', { name: 'Start revisjon' }).click();
    await page.getByTestId('compliance-audit-start').getByRole('button', { name: 'Start revisjon' }).click();
    await expect(page.getByTestId('compliance-audit-status')).toHaveText('Under arbeid');
    await expect(page.getByTestId('compliance-audit-show-attention')).toContainText('Revisjon forfalt');

    // 10. Avvik, on the requirement and the process in scope.
    const findings = page.getByTestId('compliance-audit-findings');
    await findings.getByRole('button', { name: 'Nytt funn' }).click();
    await page.getByTestId('compliance-finding-form').getByRole('radio', { name: /^Avvik/ }).check();
    await page.locator('#compliance-finding-new-title').fill(findingTitle);
    await page.locator('#compliance-finding-new-description').fill('To av ti sluttede brukere hadde fortsatt tilgang.');
    await page.locator('#compliance-finding-new-requirement').selectOption({ label: `A.5.15 ${requirementTitle}` });
    await page.locator('#compliance-finding-new-process').selectOption({ label: person.process_title });
    await page.getByTestId('compliance-finding-form').getByRole('button', { name: 'Lagre funn' }).click();
    await expect(page.getByTestId('compliance-finding')).toContainText(findingTitle);

    // 11–12. Fullfør — no longer overdue, now «Avvik uten oppfølging».
    await page.getByTestId('compliance-audit-actions').getByRole('button', { name: 'Fullfør revisjon' }).click();
    await page.locator('#compliance-audit-complete-conclusion').fill('Ett avvik i tilgangsstyringen.');
    await page.getByTestId('compliance-audit-complete').getByRole('button', { name: 'Fullfør revisjon' }).click();
    await expect(page.getByTestId('compliance-audit-status')).toHaveText('Fullført');
    const attention = page.getByTestId('compliance-audit-show-attention');
    await expect(attention).toContainText('Avvik uten oppfølging');
    await expect(attention).not.toContainText('Revisjon forfalt');
    await page.goto('/app/compliance/audits');
    await expect(page.getByTestId('compliance-audit-attention-item').filter({ hasText: auditTitle })).toContainText('Avvik uten oppfølging');
    await page.goto(auditUrl);

    // 13. Følg opp i Avvik og forbedringer.
    const card = page.getByTestId('compliance-finding');
    await card.getByRole('button', { name: 'Følg opp i Avvik og forbedringer' }).click();
    const handoff = page.getByTestId('compliance-handoff-form');
    const handoffId = (await handoff.getAttribute('aria-labelledby')).replace('-heading', '');
    await page.locator(`#${handoffId}-area`).selectOption({ label: person.area_name });
    await page.locator(`#${handoffId}-owner`).selectOption({ label: person.name });
    await page.locator(`#${handoffId}-due`).fill(isoDay(30));
    await expectReadable(page, '03-handoff');
    await handoff.getByRole('button', { name: 'Opprett sak' }).click();
    await expect(page.getByText('Funnet er overført til Avvik og forbedringer.')).toBeVisible();

    // 14. The attention goes by itself.
    await expect(card.getByTestId('compliance-finding-handed-off')).toHaveText('Overført');
    await expect(page.getByTestId('compliance-audit-show-attention')).toHaveCount(0);
    await page.goto('/app/compliance/audits');
    await expect(page.getByTestId('compliance-audit-attention-item').filter({ hasText: auditTitle })).toHaveCount(0);
    await expectReadable(page, '04-register-after-handoff');
    await page.goto(auditUrl);

    // 15–16. The case, and where it came from.
    await page.getByTestId('compliance-finding-case-link').click();
    await page.waitForURL(/\/app\/improvements\/\d+$/);
    await expect(page.getByRole('heading', { name: findingTitle, level: 1 })).toBeVisible();
    await expect(page.locator('main')).toContainText(person.process_title);
    const origin = page.getByTestId('improvement-audit-origin');
    await expect(origin).toContainText(`Funn: ${findingTitle}`);
    await expect(origin.getByRole('link', { name: auditTitle })).toBeVisible();
    await expectReadable(page, '05-case-provenance');
});
