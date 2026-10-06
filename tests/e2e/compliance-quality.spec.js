import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { COMPLIANCE_E2E_PASSWORD, cleanUpComplianceE2eData, complianceE2eName, complianceE2eSuffix, complianceFixture } from './helpers/compliance.js';
import { DESKTOP, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';

const suffix = complianceE2eSuffix();
cleanUpComplianceE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'compliance-quality', name);

async function xsrfHeaders(page) {
    const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');

    return { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'text/html' };
}

/**
 * Hvordan kravet oppfylles, end to end: a person who may edit and assess requirements and read
 * Kvalitet registers a requirement, links a Kvalitet process and a Kvalitet control, finds the
 * control's evidence read-only, assesses the requirement, sees how it is met and whether it is met
 * side by side, removes a link — and Kvalitet's own items are exactly as they were. Every step is
 * checked for text under 16 px and sideways scrolling at desktop and 390 px.
 */
test('a requirement is linked to a Kvalitet process and control, shows the evidence, and is assessed', async ({ page }) => {
    test.setTimeout(180_000);

    const person = await complianceFixture(`seedQualityJourney('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);
    const qualityBefore = await complianceFixture(`qualityState('${suffix}')`);
    const sourceName = complianceE2eName(suffix, 'ISO 27001');
    const title = complianceE2eName(suffix, 'Tilgangsstyring');

    await loginAs(page, person.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // 1. A source and a requirement, through the pages.
    await page.goto('/app/compliance/requirements');
    await page.getByRole('button', { name: 'Kravkilder', exact: true }).click();
    const sources = page.getByTestId('compliance-sources');
    await sources.getByRole('button', { name: 'Ny kravkilde' }).click();
    await page.locator('#compliance-source-name').fill(sourceName);
    await page.locator('#compliance-source-kind').selectOption({ label: 'Standard' });
    await sources.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Kravkilden er opprettet.')).toBeVisible();

    await page.getByRole('button', { name: 'Nytt krav' }).click();
    await page.locator('#compliance-requirement-source').selectOption({ label: sourceName });
    await page.locator('#compliance-requirement-title').fill(title);
    await page.locator('#compliance-requirement-text').fill('Tilganger skal tildeles, gjennomgås og fjernes etter fastsatte regler.');
    await page.locator('#compliance-requirement-owner').selectOption({ label: person.name });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/compliance\/requirements\/\d+$/);

    const section = page.getByTestId('compliance-quality');
    await expect(section.getByRole('heading', { name: 'Hvordan kravet oppfylles' })).toBeVisible();
    await expect(section).toContainText('Ingen prosesser er koblet til kravet.');
    await expect(section).toContainText('Ingen kontroller er koblet til kravet.');
    await expectPageHelp(page, 'Om kravet', ['Hvordan kravet oppfylles', 'Etterlevelse']);
    await expectReadable(page, '01-empty');

    // 2. Koble prosess.
    await section.getByRole('button', { name: 'Legg til prosess' }).click();
    await page.locator('#compliance-process-link').selectOption({ label: person.process_title });
    await expectReadable(page, '02-process-picker');
    await page.getByTestId('compliance-process-link-form').getByRole('button', { name: 'Koble' }).click();
    await expect(page.getByText('Prosessen er koblet til kravet.')).toBeVisible();
    await expect(page.getByTestId('compliance-process')).toHaveCount(1);
    await expect(page.getByTestId('compliance-process')).toContainText(person.process_title);

    // 3. Koble kontroll.
    await section.getByRole('button', { name: 'Legg til kontroll' }).click();
    await page.locator('#compliance-control-link').selectOption({ label: person.control_title });
    await page.getByTestId('compliance-control-link-form').getByRole('button', { name: 'Koble' }).click();
    await expect(page.getByText('Kontrollen er koblet til kravet.')).toBeVisible();

    // 4. The control as Kvalitet has it, and its evidence — read-only, and no verdict.
    const control = page.getByTestId('compliance-control');
    await expect(control).toHaveCount(1);
    await expect(control).toContainText(person.control_title);
    await expect(control).toContainText('Alle tilganger er godkjent av leder.');
    await expect(control).toContainText('Stikkprøve av ti brukere i hvert fagsystem.');
    await expect(control).toContainText('Kvartalsvis');
    await expect(control).toContainText('IT-sjef');
    const evidence = control.getByTestId('compliance-evidence');
    await expect(evidence).toHaveCount(1);
    await expect(evidence).toContainText(person.evidence_title);
    await expect(evidence).toContainText('Signert gjennomgangsrapport for første kvartal.');
    await expect(evidence).toContainText(`av ${person.name}`);
    await expect(section).toContainText('Evidensen vises fra Kvalitet og bestemmer ikke etterlevelsen.');
    await expect(section.locator('input[type="file"]')).toHaveCount(0);
    await expect(page.getByTestId('compliance-current')).toHaveText('Ikke vurdert');
    await expectReadable(page, '03-linked');

    // 5. Vurder etterlevelse.
    await page.getByTestId('compliance-assessment').getByRole('button', { name: 'Vurder etterlevelse' }).click();
    await page.locator('#compliance-assessment-result-compliant').check();
    await page.locator('#compliance-assessment-rationale').fill('Kontrollen gjennomføres kvartalsvis og er dokumentert.');
    await page.getByTestId('compliance-assessment-form').getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Etterlevelsesvurderingen er registrert.')).toBeVisible();

    // 6. How it is met and whether it is met, together — in that order.
    await expect(page.getByTestId('compliance-current')).toHaveText('Oppfylt');
    await expect(control).toContainText(person.control_title);
    const qualityBox = await section.boundingBox();
    const assessmentBox = await page.getByTestId('compliance-assessment').boundingBox();
    expect(qualityBox.y).toBeLessThan(assessmentBox.y);
    await expectReadable(page, '04-linked-and-assessed');

    // 7. Fjern kobling (the requirement is active).
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByTestId('compliance-process').getByRole('button', { name: 'Fjern kobling' }).click();
    await expect(page.getByText('Koblingen til prosessen er fjernet.')).toBeVisible();
    await expect(page.getByTestId('compliance-process')).toHaveCount(0);
    await expect(control).toHaveCount(1);
    await expect(page.getByTestId('compliance-current')).toHaveText('Oppfylt');

    // 8. Kvalitet's own items are untouched.
    expect(await complianceFixture(`qualityState('${suffix}')`)).toEqual(qualityBefore);
});

/**
 * Someone with compliance.view and compliance.edit but no Kvalitet opens a requirement that is
 * linked to a process and a control with evidence: there is no trace of them — no section, no
 * names, no ids, no count — and a link request sent anyway is refused.
 */
test('without Kvalitet the links are invisible and cannot be changed', async ({ page }) => {
    test.setTimeout(90_000);

    const seeded = await complianceFixture(`seedQualityHidden('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);

    await loginAs(page, seeded.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    await page.goto(`/app/compliance/requirements/${seeded.requirement_id}`);
    await expect(page.getByRole('heading', { name: seeded.requirement_title })).toBeVisible();
    await expect(page.getByTestId('compliance-quality')).toHaveCount(0);
    await expect(page.getByText('Hvordan kravet oppfylles')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Legg til kontroll' })).toHaveCount(0);

    // Not in the page, not in the Inertia props it was drawn from.
    const html = await page.content();
    for (const needle of [seeded.process_title, seeded.control_title, seeded.evidence_title, `/app/quality/items/${seeded.process_id}`, `/app/quality/items/${seeded.control_id}`]) {
        expect(html).not.toContain(needle);
    }
    const props = await page.evaluate(() => JSON.parse(document.querySelector('script[data-page]')?.textContent ?? document.getElementById('app').dataset.page).props);
    expect(props.quality_context).toBeNull();
    expect(props.quality_options).toBeNull();
    expect(props.permissions.can_manage_quality_links).toBe(false);
    await expectReadable(page, '05-no-kvalitet');

    // Sent anyway, with a valid session and CSRF token: refused, and nothing changes.
    const headers = await xsrfHeaders(page);
    const link = await page.request.post(`/app/compliance/requirements/${seeded.requirement_id}/controls`, { headers, form: { control_item_id: String(seeded.control_id) } });
    expect(link.status()).toBe(403);
    const unlink = await page.request.delete(`/app/compliance/requirements/${seeded.requirement_id}/processes/${seeded.process_id}`, { headers });
    expect(unlink.status()).toBe(403);
    const probe = await page.request.post(`/app/compliance/requirements/${seeded.requirement_id}/processes`, { headers, form: { quality_process_id: '999999999' } });
    expect(probe.status()).toBe(403);
});
