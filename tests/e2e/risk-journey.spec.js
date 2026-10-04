import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';
import { cleanUpRiskE2eData, fillRiskDescription, riskE2eSuffix } from './helpers/risk.js';

const suffix = riskE2eSuffix();
cleanUpRiskE2eData(suffix);

const SUPERSEDE_NOTE = 'En ny vurdering gjør dagens aksept historisk. Ny restrisiko må eventuelt aksepteres på nytt.';

/**
 * Risiko v1 as one user journey, by an ordinary Risk user: register a risk, assess it, place it in
 * Kvalitet, decide behandling, follow up a tiltak, accept the residual risk, set the review
 * interval, see it in the register and in Trenger oppmerksomhet, reassess, read the history, and
 * open the Wiki dialog. The Wiki dialog is opened and checked but not sent — sending starts a real
 * Wiki run; RiskWikiKnowledgeTest owns the handoff itself.
 *
 * Each step is owned in depth by its own spec or feature test; this one guards the seams between
 * them — that one step leads to the next without a dead end.
 */
test('a risk goes from registered to assessed, treated, accepted and reassessed', async ({ page }) => {
    test.setTimeout(180_000);

    const areaName = `E2E Reise ${suffix}`;
    const roleName = `E2E Risikoreise ${suffix}`;
    const riskTitle = `E2E Reise ${suffix}`;

    // System Owner gives the ordinary user one role: everything in Risiko for one fagområde, plus
    // Kvalitet read and Wiki sources for the links out.
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/customer-environment?tab=permissions');
    await page.getByRole('button', { name: 'Nytt fagområde' }).click();
    await page.locator('#business-area-name').fill(areaName);
    await page.getByRole('button', { name: 'Lagre fagområde' }).click();
    await expect(page.locator('tbody tr', { hasText: areaName })).toBeVisible();

    await page.getByRole('button', { name: 'Ny rolle' }).click();
    await page.locator('#customer-role-name').fill(roleName);
    for (const permission of ['Se kvalitetssystemet', 'Administrere kildedokumenter', 'Se risikoer', 'Opprette risikoer', 'Endre risikoer', 'Vurdere risikoer', 'Akseptere restrisiko']) {
        await page.getByRole('checkbox', { name: permission, exact: true }).check();
    }
    await page.getByRole('checkbox', { name: areaName, exact: true }).check();
    await page.getByRole('button', { name: 'Lagre rolle' }).click();
    await expect(page.locator('tr', { hasText: roleName }).first()).toBeVisible();

    await page.goto('/app/customer-environment?tab=users');
    await page.locator('tbody tr', { hasText: USER.email }).first().getByRole('link', { name: 'Rediger' }).click();
    await page.waitForURL(/\/app\/users\/\d+\/edit/);
    await page.getByRole('checkbox', { name: roleName, exact: true }).check();
    await page.getByRole('button', { name: 'Lagre endringer' }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/edit'));

    await page.context().clearCookies();
    await loginAs(page, USER.email, USER.password);

    // 1–2. Ny risiko: what the risk is, nothing to decide yet.
    await page.goto('/app/risk');
    await page.getByRole('button', { name: 'Ny risiko' }).click();
    await expect(page.locator('#risk-status')).toHaveCount(0);
    await expect(page.locator('#risk-treatment-strategy')).toHaveCount(0);
    await expect(page.locator('#risk-review-interval')).toHaveCount(0);
    await expect(page.locator('label[for="risk-title"]')).toContainText('*');
    await expect(page.getByText('Felt merket med * må fylles ut.')).toBeVisible();
    await page.screenshot({ path: 'test-results/risk-journey-01-create.png', fullPage: true });

    // Saving without the description is refused in Norwegian.
    await page.locator('#risk-title').fill(riskTitle);
    await page.locator('#risk-area').selectOption({ label: areaName });
    await page.locator('#risk-cause').evaluate((el) => el.removeAttribute('required'));
    await page.locator('#risk-event').evaluate((el) => el.removeAttribute('required'));
    await page.locator('#risk-consequence').evaluate((el) => el.removeAttribute('required'));
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Årsak må fylles ut.')).toBeVisible();

    await fillRiskDescription(page, {
        cause: 'én leverandør leverer all lønnsprogramvare',
        event: 'leverandøren går konkurs',
        consequence: 'lønn kan ikke kjøres til riktig tid',
    });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/risk\/risks\/\d+$/);
    const riskUrl = page.url();

    const header = page.locator('header').filter({ has: page.getByRole('heading', { name: riskTitle }) });
    await expect(header).toContainText('Identifisert');
    await expect(header).toContainText('Restrisiko: Ikke vurdert');
    await expect(page.getByText('leverandøren går konkurs', { exact: true })).toBeVisible();

    // 3–5. Første vurdering, iboende risiko and restrisiko.
    const assessments = page.locator('section', { has: page.getByRole('heading', { name: 'Risikovurdering', exact: true }) });
    await expect(assessments).toContainText('Risikoen er ikke vurdert ennå');
    await page.getByRole('button', { name: 'Ny vurdering' }).click();
    await expect(page.getByText(SUPERSEDE_NOTE)).toHaveCount(0);
    await page.locator('#assessment-inherent-likelihood').selectOption('5');
    await page.locator('#assessment-inherent-consequence').selectOption('5');
    await page.getByRole('button', { name: 'Vurder også restrisiko' }).click();
    await page.locator('#assessment-residual-likelihood').selectOption('4');
    await page.locator('#assessment-residual-consequence').selectOption('5');
    await page.locator('#assessment-rationale').fill('Ingen alternativ leverandør er kvalifisert.');
    await page.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Vurderingen er registrert.')).toBeVisible();
    await expect(header).toContainText('Identifisert');
    await expect(header).toContainText(/Restrisiko: (Høy|Svært høy)/);
    await expect(assessments.getByText('Uendret siden vurderingen', { exact: false })).toBeVisible();

    // 6–7. Prosess and aktivitet in Kvalitet.
    const context = page.locator('section', { has: page.getByRole('heading', { name: 'Kontekst', exact: true }) });
    await expect(context).toContainText('Prosessene og aktivitetene i Kvalitet der risikoen kan oppstå.');
    await context.getByRole('button', { name: 'Koble prosess' }).click();
    const processSelect = page.locator('#risk-context-process');
    if (await processSelect.count() === 0) {
        test.skip(true, 'No process in Kvalitet in this environment.');
    }
    const processValues = await processSelect.locator('option').evaluateAll((options) => options.map((o) => o.value).filter(Boolean));
    let processId = null;
    for (const value of processValues) {
        await processSelect.selectOption(value);
        if (await page.locator('#risk-context-activity option:not([disabled])').count() > 1) {
            processId = value;
            break;
        }
    }
    if (processId === null) {
        test.skip(true, 'No process with activities in Kvalitet in this environment.');
    }
    await context.getByRole('button', { name: 'Koble', exact: true }).click();
    await expect(page.getByText('Risikoen er koblet til Kvalitet.')).toBeVisible();

    await context.getByRole('button', { name: 'Koble prosess' }).click();
    await processSelect.selectOption(processId);
    const activityOption = page.locator('#risk-context-activity option:not([disabled])').nth(1);
    await page.locator('#risk-context-activity').selectOption(await activityOption.getAttribute('value'));
    await context.getByRole('button', { name: 'Koble', exact: true }).click();
    await expect(page.getByText('Risikoen er koblet til Kvalitet.')).toBeVisible();
    await expect(context.getByText('Hele prosessen', { exact: true })).toBeVisible();
    // Linking closes the form; no picker is left hanging open.
    await expect(page.locator('#risk-context-process')).toHaveCount(0);

    // 8. Kontroll — with where it sits in Kvalitet.
    const controls = page.locator('section', { has: page.getByRole('heading', { name: 'Kontroller som håndterer risikoen' }) });
    await expect(controls).toContainText('Ta dem med når du vurderer restrisiko.');
    await controls.getByRole('button', { name: 'Koble kontroll' }).click();
    const controlSelect = page.locator('#risk-control');
    if (await controlSelect.count() === 0) {
        test.skip(true, 'No control in Kvalitet in this environment.');
    }
    const controlOption = controlSelect.locator('option').nth(1);
    const controlName = (await controlOption.textContent()).split(' — ')[0].trim();
    await controlSelect.selectOption(await controlOption.getAttribute('value'));
    await controls.getByRole('button', { name: 'Koble', exact: true }).click();
    await expect(page.getByText('Kontrollen er koblet til risikoen.')).toBeVisible();
    await expect(controls.getByRole('link', { name: controlName })).toBeVisible();

    // 9. Behandlingsvalg — and what «Akseptere» does and does not mean.
    const treatment = page.getByRole('region', { name: 'Behandling' });
    await expect(treatment).toContainText('Behandling er ikke besluttet ennå.');
    await treatment.getByRole('button', { name: 'Endre' }).click();
    await expect(page.locator('#risk-treatment-strategy')).toBeFocused();
    await page.locator('#risk-treatment-strategy').selectOption({ label: 'Akseptere' });
    await expect(page.getByText('«Akseptere» er bare retningen.', { exact: false })).toBeVisible();
    await page.locator('#risk-treatment-strategy').selectOption({ label: 'Redusere' });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.locator('#risk-treatment-strategy')).toHaveCount(0);
    await expect(treatment).toContainText('Redusere sannsynlighet eller konsekvens.');

    // 10. Tiltak — Ansvarlig required, no result asked for yet.
    const actions = page.locator('section', { has: page.getByRole('heading', { name: 'Tiltak', exact: true }) });
    await actions.getByRole('button', { name: 'Nytt tiltak' }).click();
    await expect(page.locator('#risk-action-new-note')).toHaveCount(0);
    await page.locator('#risk-action-new-title').fill('Kvalifiser en alternativ lønnsleverandør');
    await page.locator('#risk-action-new-due').fill('2099-03-01');
    await actions.getByRole('button', { name: 'Lagre tiltak' }).click();
    await expect(actions.getByText('Ansvarlig må fylles ut.')).toBeVisible();
    await page.locator('#risk-action-new-title').fill('Kvalifiser en alternativ lønnsleverandør');
    await page.locator('#risk-action-new-owner').selectOption({ index: 1 });
    await page.locator('#risk-action-new-due').fill('2099-03-01');
    await actions.getByRole('button', { name: 'Lagre tiltak' }).click();
    await expect(page.getByText('Tiltaket er registrert.')).toBeVisible();

    // 11. Fullfør tiltak med resultat.
    const action = actions.locator('li', { hasText: 'Kvalifiser en alternativ lønnsleverandør' });
    await action.getByRole('button', { name: 'Marker som fullført' }).click();
    await expect(action.getByText('Hva ble gjort, og hva ble resultatet?')).toBeVisible();
    await action.locator('textarea').fill('Reserveleverandør kvalifisert og avtale signert.');
    await action.getByRole('button', { name: 'Fullfør tiltak' }).click();
    await expect(page.getByText('Tiltaket er markert som fullført.')).toBeVisible();
    await expect(actions.getByText('Reserveleverandør kvalifisert og avtale signert.')).toBeVisible();

    // 12. Aksepter restrisiko — the formal decision, distinct from the direction.
    const decision = page.locator('section', { has: page.getByRole('heading', { name: 'Aksept av restrisiko', exact: true }) });
    await expect(decision).toContainText('Behandlingsvalget «Akseptere» er bare retningen');
    await decision.getByRole('button', { name: 'Aksepter restrisiko' }).click();
    await page.locator('#risk-acceptance-rationale').fill('Restrisikoen er akseptabel med reserveleverandør på plass.');
    await decision.getByRole('button', { name: 'Aksepter', exact: true }).click();
    await expect(page.getByText('Restrisikoen er akseptert.')).toBeVisible();
    await expect(header).toContainText('Identifisert');

    // 13. Vurderingsintervall from Detaljer.
    const details = page.locator('section', { has: page.getByRole('heading', { name: 'Detaljer', exact: true }) });
    await details.getByRole('button', { name: 'Endre vurderingsintervall' }).click();
    await expect(page.locator('#risk-review-interval')).toBeFocused();
    await page.locator('#risk-review-interval').selectOption({ label: 'Årlig' });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.locator('#risk-review-interval')).toHaveCount(0);
    await expect(details).toContainText('Årlig');
    await page.screenshot({ path: 'test-results/risk-journey-13-risk-page.png', fullPage: true });

    // 14–15. Registeret and Trenger oppmerksomhet.
    await page.goto('/app/risk');
    const row = page.locator('tbody tr', { hasText: riskTitle });
    await expect(row).toContainText('leverandøren går konkurs');
    await expect(row).toContainText('Identifisert');
    await expect(row.getByText(/^(Høy|Svært høy)$/)).toBeVisible();
    const high = page.getByTestId('risk-attention-category-high_residual');
    await expect(high).toBeVisible();
    await high.getByRole('button', { name: 'Vis risikoer' }).click();
    await expect(high.getByRole('link', { name: riskTitle })).toBeVisible();
    // Accepted and with a review interval: neither «Ikke vurdert» nor «Restrisiko ikke vurdert».
    for (const key of ['not_assessed', 'residual_not_assessed']) {
        const category = page.getByTestId(`risk-attention-category-${key}`);
        if (await category.count() > 0) {
            await category.getByRole('button', { name: 'Vis risikoer' }).click();
            await expect(category.getByRole('link', { name: riskTitle })).toHaveCount(0);
        }
    }
    await page.screenshot({ path: 'test-results/risk-journey-15-register.png', fullPage: true });

    // 16. Ny vurdering warns that the acceptance goes historical.
    await page.goto(riskUrl);
    await page.getByRole('button', { name: 'Ny vurdering' }).click();
    await expect(page.getByText(SUPERSEDE_NOTE)).toBeVisible();
    await page.locator('#assessment-inherent-likelihood').selectOption('5');
    await page.locator('#assessment-inherent-consequence').selectOption('5');
    await page.getByRole('button', { name: 'Vurder også restrisiko' }).click();
    await page.locator('#assessment-residual-likelihood').selectOption('2');
    await page.locator('#assessment-residual-consequence').selectOption('3');
    await page.locator('#assessment-rationale').fill('Reserveleverandøren er på plass.');
    await page.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Vurderingen er registrert.')).toBeVisible();

    // 17. Historikk: the earlier assessment, and the acceptance that belonged to it.
    await expect(assessments.getByText('Tidligere vurderinger')).toBeVisible();
    await expect(assessments.getByText('Ingen alternativ leverandør er kvalifisert.')).toBeVisible();
    await expect(decision).toContainText('Restrisikoen i siste vurdering er ikke akseptert.');
    await decision.getByText('Tidligere aksepter (1)').click();
    await expect(decision.getByText('Gjaldt en tidligere vurdering')).toBeVisible();

    // 18–19. Kunnskap til Wiki: the dialog starts empty and warns about what not to share.
    const wiki = page.locator('section', { has: page.getByRole('heading', { name: 'Kunnskap delt til Wiki', exact: true }) });
    await wiki.getByRole('button', { name: 'Lag kunnskapsartikkel' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('#risk-wiki-knowledge-title')).toHaveValue('');
    await expect(dialog.locator('#risk-wiki-knowledge-markdown')).toHaveValue('');
    await expect(dialog).toContainText('ikke sensitive detaljer');
    await expect(dialog).toContainText('Ingenting fra risikoen kopieres automatisk.');
    await expect(dialog).not.toContainText('Markdown');
    await expect(dialog.getByRole('button', { name: 'Send til Wiki' })).toBeDisabled();
    await page.screenshot({ path: 'test-results/risk-journey-19-wiki-dialog.png', fullPage: true });
    await dialog.getByRole('button', { name: 'Avbryt' }).click();
    await expect(dialog).toHaveCount(0);
    await expect(wiki).toContainText('Ingen kunnskap er delt fra denne risikoen ennå.');
});
