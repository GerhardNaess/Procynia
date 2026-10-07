import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { COMPLIANCE_E2E_PASSWORD, cleanUpComplianceE2eData, complianceE2eName, complianceE2eSuffix, complianceFixture } from './helpers/compliance.js';
import { DESKTOP, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';

const suffix = complianceE2eSuffix();
cleanUpComplianceE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'compliance-assessment', name);
const registerUrl = () => `/app/compliance/requirements?${new URLSearchParams({ search: `E2E Krav ${suffix}` })}`;

/**
 * Etterlevelsesvurderinger, end to end: a person who may edit and assess registers a requirement,
 * assesses it «Oppfylt», reads the current status and the history, edits the requirement text, is
 * told the requirement has changed since it was assessed, assesses it again «Delvis oppfylt», and
 * finds both assessments — the newest as today's status, each with the requirement as it was.
 * Every step is checked for text under 16 px and sideways scrolling at desktop and 390 px.
 */
test('a requirement is assessed, changed and reassessed, with every assessment and its snapshot kept', async ({ page }) => {
    test.setTimeout(180_000);

    const person = await complianceFixture(`seedAssessor('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);
    const sourceName = complianceE2eName(suffix, 'ISO 27001');
    const sourceLabel = `${sourceName} (2022)`;
    const title = complianceE2eName(suffix, 'Tilgangsstyring');
    const originalText = 'Regler for fysisk og logisk tilgang skal etableres.';
    const changedText = 'Regler for fysisk og logisk tilgang skal etableres, dokumenteres og gjennomgås årlig.';

    await loginAs(page, person.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // 1. A source and a requirement, through the pages.
    await page.goto('/app/compliance/requirements');
    await expectPageHelp(page, 'Om krav', ['Etterlevelse']);
    await page.getByRole('button', { name: 'Kravkilder', exact: true }).click();
    const sources = page.getByTestId('compliance-sources');
    await sources.getByRole('button', { name: 'Ny kravkilde' }).click();
    await page.locator('#compliance-source-name').fill(sourceName);
    await page.locator('#compliance-source-version').fill('2022');
    await page.locator('#compliance-source-kind').selectOption({ label: 'Standard' });
    await sources.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Kravkilden er opprettet.')).toBeVisible();

    await page.getByRole('button', { name: 'Nytt krav' }).click();
    await page.locator('#compliance-requirement-source').selectOption({ label: sourceLabel });
    await page.locator('#compliance-requirement-reference').fill('A.5.15');
    await page.locator('#compliance-requirement-title').fill(title);
    await page.locator('#compliance-requirement-text').fill(originalText);
    await page.locator('#compliance-requirement-owner').selectOption({ label: person.name });
    await page.locator('#compliance-requirement-review').selectOption({ label: 'Årlig' });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();

    // 2. Open it: not assessed yet, which is no stored status.
    await page.waitForURL(/\/app\/compliance\/requirements\/\d+$/);
    const section = page.getByTestId('compliance-assessment');
    await expect(page.getByTestId('compliance-current')).toHaveText('Ikke vurdert');
    await expect(section).toContainText('Kravet er ikke vurdert ennå.');
    await expect(page.getByTestId('compliance-assessment-history')).toHaveCount(0);
    await expectPageHelp(page, 'Om kravet', ['Etterlevelse', 'Status', 'Statushistorikk']);
    await expectReadable(page, '01-not-assessed');

    // 3. Vurder etterlevelse: the server's messages first, then «Oppfylt».
    await section.getByRole('button', { name: 'Vurder etterlevelse' }).click();
    const form = page.getByTestId('compliance-assessment-form');
    await expect(form.locator('input[type="date"], input[type="datetime-local"]')).toHaveCount(0);
    await form.locator('[required]').evaluateAll((elements) => elements.forEach((element) => element.removeAttribute('required')));
    await form.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Resultat må fylles ut.')).toBeVisible();
    await expect(page.getByText('Begrunnelse må fylles ut.')).toBeVisible();
    await page.locator('#compliance-assessment-result-compliant').check();
    await page.locator('#compliance-assessment-rationale').fill('Tilgangsrutinen er etablert og fulgt opp i alle systemer.');
    await expectReadable(page, '02-assessment-form');
    await form.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Etterlevelsesvurderingen er registrert.')).toBeVisible();

    // 4. Today's status, when and by whom, why, and the next review a year on.
    await expect(page.getByTestId('compliance-current')).toHaveText('Oppfylt');
    await expect(section).toContainText(person.name);
    await expect(page.getByTestId('compliance-current-rationale')).toHaveText('Tilgangsrutinen er etablert og fulgt opp i alle systemer.');
    await expect(page.getByTestId('compliance-next-review')).toHaveText(/^\d{2}\.\d{2}\.\d{4}$/);
    await expect(page.getByTestId('compliance-changed-since')).toHaveCount(0);

    // 5. The history: one assessment, with the requirement as it was.
    const history = page.getByTestId('compliance-assessment-history').locator(':scope > li');
    await expect(history).toHaveCount(1);
    await expect(history.first()).toContainText('Oppfylt');
    await expect(history.first()).toContainText(`Vurdert av ${person.name}`);
    await history.first().getByText('Vis krav slik det var').click();
    await expect(history.first().getByTestId('compliance-assessment-snapshot')).toContainText(originalText);
    await expect(history.first().getByTestId('compliance-assessment-snapshot')).toContainText(sourceLabel);
    await expectReadable(page, '03-assessed');
    const requirementUrl = page.url();

    // The register shows the result.
    await page.goto(registerUrl());
    await expect(page.locator('tbody tr', { hasText: title }).getByTestId('compliance-cell')).toHaveText('Oppfylt');
    await expectReadable(page, '04-register-assessed');

    // 6. Edit the requirement text.
    await page.goto(requirementUrl);
    await page.getByRole('button', { name: 'Rediger' }).click();
    await page.locator('#compliance-requirement-text').fill(changedText);
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Kravet er oppdatert.')).toBeVisible();

    // 7. The page says so — and the result stands.
    await expect(page.getByTestId('compliance-changed-since')).toContainText('Kravet er endret siden siste etterlevelsesvurdering.');
    await expect(page.getByTestId('compliance-current')).toHaveText('Oppfylt');
    await expectReadable(page, '05-changed-since');

    // 8. Reassess: «Delvis oppfylt».
    await section.getByRole('button', { name: 'Vurder etterlevelse' }).click();
    await page.locator('#compliance-assessment-result-partially_compliant').check();
    await page.locator('#compliance-assessment-rationale').fill('Årlig gjennomgang er ikke innført ennå.');
    await form.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Etterlevelsesvurderingen er registrert.')).toBeVisible();

    // 9–10. Both assessments, newest first; the newest is today's status, and the change notice is gone.
    await expect(history).toHaveCount(2);
    await expect(history.nth(0)).toContainText('Delvis oppfylt');
    await expect(history.nth(0)).toContainText('Årlig gjennomgang er ikke innført ennå.');
    await expect(history.nth(1)).toContainText('Oppfylt');
    await expect(history.nth(1)).toContainText('Tilgangsrutinen er etablert');
    await expect(page.getByTestId('compliance-current')).toHaveText('Delvis oppfylt');
    await expect(page.getByTestId('compliance-changed-since')).toHaveCount(0);
    await history.nth(1).getByText('Vis krav slik det var').click();
    await expect(history.nth(1).getByTestId('compliance-assessment-snapshot')).toContainText(originalText);
    await history.nth(0).getByText('Vis krav slik det var').click();
    await expect(history.nth(0).getByTestId('compliance-assessment-snapshot')).toContainText(changedText);
    await expectReadable(page, '06-reassessed');

    await page.goto(registerUrl());
    await expect(page.locator('tbody tr', { hasText: title }).getByTestId('compliance-cell')).toHaveText('Delvis oppfylt');
});

/**
 * Someone with compliance.view only reads the current status and the assessment history, is offered
 * no «Vurder etterlevelse», and is refused by the server when the request is sent anyway. A retired
 * requirement offers no assessment to anyone.
 */
test('a reader sees the assessment but cannot assess', async ({ page }) => {
    test.setTimeout(90_000);

    const seeded = await complianceFixture(`seedReader('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);

    await loginAs(page, seeded.reader_email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    await page.goto(registerUrl());
    await expect(page.locator('tbody tr', { hasText: seeded.active_title }).getByTestId('compliance-cell')).toHaveText('Delvis oppfylt');

    await page.goto(`/app/compliance/requirements/${seeded.active_id}`);
    await expect(page.getByTestId('compliance-current')).toHaveText('Delvis oppfylt');
    await expect(page.getByTestId('compliance-assessment-history').locator(':scope > li')).toHaveCount(1);
    await expect(page.getByTestId('compliance-assessment-history')).toContainText('Protokollen finnes, men er ikke oppdatert.');
    await expect(page.getByRole('button', { name: 'Vurder etterlevelse' })).toHaveCount(0);
    await expectReadable(page, '07-reader');

    // Sent anyway, with a valid session and CSRF token: the server refuses it.
    const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    const response = await page.request.post(`/app/compliance/requirements/${seeded.active_id}/assessments`, {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'text/html' },
        form: { result: 'compliant', rationale: 'Forsøk uten tilgang' },
    });
    expect(response.status()).toBe(403);

    await page.reload();
    await expect(page.getByTestId('compliance-assessment-history').locator(':scope > li')).toHaveCount(1);

    await page.goto(`/app/compliance/requirements/${seeded.retired_id}`);
    await expect(page.getByTestId('compliance-current')).toHaveText('Ikke vurdert');
    await expect(page.getByRole('button', { name: 'Vurder etterlevelse' })).toHaveCount(0);
});
