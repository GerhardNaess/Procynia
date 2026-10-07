import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { COMPLIANCE_E2E_PASSWORD, cleanUpComplianceE2eData, complianceE2eName, complianceE2eSuffix, complianceFixture } from './helpers/compliance.js';
import { DESKTOP, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';

const suffix = complianceE2eSuffix();
cleanUpComplianceE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'compliance-audit-findings', name);

async function xsrfHeaders(page) {
    const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');

    return { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'text/html' };
}

async function pageProps(page) {
    return page.evaluate(() => JSON.parse(document.querySelector('script[data-page]')?.textContent ?? document.getElementById('app').dataset.page).props);
}

/**
 * Revisjonsfunn → Avvik og forbedringer, end to end: someone who runs audits, reads Kvalitet and may
 * register cases in one fagområde creates and starts an audit, records an avvik linked to a
 * requirement, a process and a control, completes the audit — the finding is locked but can still
 * be followed up — hands it off with area, owner and frist, finds it marked Overført with a link,
 * opens the case with the finding's text and the process proposed, and follows the provenance back
 * to the audit. Every step is checked for text under 16 px and sideways scrolling at desktop and 390 px.
 */
test('a finding is recorded, the audit completed, and the finding followed up in Avvik og forbedringer', async ({ page }) => {
    test.setTimeout(240_000);

    const person = await complianceFixture(`seedFindingJourney('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);
    const auditTitle = complianceE2eName(suffix, 'Internrevisjon tilganger');
    const findingTitle = complianceE2eName(suffix, 'Tilganger fjernes ikke ved fratredelse');
    const findingText = 'Tre av ti sluttede brukere hadde fortsatt aktiv tilgang til økonomisystemet.';

    await loginAs(page, person.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // 1. Opprett revisjon.
    await page.goto('/app/compliance/audits');
    await page.getByRole('button', { name: 'Ny revisjon' }).click();
    await page.locator('#compliance-audit-title').fill(auditTitle);
    await page.locator('#compliance-audit-responsible').selectOption({ label: person.name });
    await page.locator('#compliance-audit-end').fill('2026-11-15');
    await page.locator('#compliance-audit-scope').fill('Tilgangsstyring for økonomisystemet.');
    await page.getByTestId('compliance-audit-form').getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/compliance\/audits\/\d+$/);
    const auditUrl = page.url();

    // A planned audit has no findings yet, and offers no way to record one.
    const findings = page.getByTestId('compliance-audit-findings');
    await expect(findings).toContainText('Funn registreres når revisjonen er startet.');
    await expect(findings.getByRole('button', { name: 'Nytt funn' })).toHaveCount(0);

    // 2. Start revisjon.
    await page.getByTestId('compliance-audit-actions').getByRole('button', { name: 'Start revisjon' }).click();
    await page.getByTestId('compliance-audit-start').getByRole('button', { name: 'Start revisjon' }).click();
    await expect(page.getByTestId('compliance-audit-status')).toHaveText('Under arbeid');
    await expect(findings).toContainText('Ingen funn er registrert.');
    await expectPageHelp(page, 'Om revisjonen', ['Scope', 'Funn', 'Gjennomføring', 'Statushistorikk']);

    // 3–4. Nytt funn: Avvik, linked to requirement, process and control.
    await findings.getByRole('button', { name: 'Nytt funn' }).click();
    const form = page.getByTestId('compliance-finding-form');
    await expect(form.getByRole('radio')).toHaveCount(3);
    for (const field of ['Alvorlighet', 'Frist', 'Ansvarlig', 'Tiltak']) {
        await expect(form.getByLabel(field, { exact: false })).toHaveCount(0);
    }
    await form.getByRole('radio', { name: /^Avvik/ }).check();
    await page.locator('#compliance-finding-new-title').fill(findingTitle);
    await page.locator('#compliance-finding-new-description').fill(findingText);
    await page.locator('#compliance-finding-new-requirement').selectOption({ label: person.requirement_label });
    await page.locator('#compliance-finding-new-process').selectOption({ label: person.process_title });
    await page.locator('#compliance-finding-new-control').selectOption({ label: person.control_title });
    await expectReadable(page, '01-finding-form');
    await form.getByRole('button', { name: 'Lagre funn' }).click();
    await expect(page.getByText('Funnet er registrert.')).toBeVisible();

    // 5. Verifiser funnet.
    const card = page.getByTestId('compliance-finding');
    await expect(card).toHaveCount(1);
    await expect(card).toContainText('Avvik');
    await expect(card).toContainText(findingTitle);
    await expect(card).toContainText(findingText);
    const context = card.getByTestId('compliance-finding-context');
    await expect(context.getByRole('link', { name: person.requirement_label })).toBeVisible();
    await expect(context.getByRole('link', { name: person.process_title })).toBeVisible();
    await expect(context.getByRole('link', { name: person.control_title })).toBeVisible();
    await expect(card.getByRole('button', { name: 'Rediger' })).toBeVisible();
    await expect(card.getByRole('button', { name: 'Slett' })).toBeVisible();
    await expectReadable(page, '02-finding-recorded');

    // 6. Fullfør revisjonen — the finding is locked, but can still be followed up.
    await page.getByTestId('compliance-audit-actions').getByRole('button', { name: 'Fullfør revisjon' }).click();
    await page.locator('#compliance-audit-complete-conclusion').fill('Ett avvik i tilgangsstyringen.');
    await page.getByTestId('compliance-audit-complete').getByRole('button', { name: 'Fullfør revisjon' }).click();
    await expect(page.getByTestId('compliance-audit-status')).toHaveText('Fullført');
    await expect(page.getByTestId('compliance-findings-locked')).toContainText('funnene kan ikke endres');
    await expect(findings.getByRole('button', { name: 'Nytt funn' })).toHaveCount(0);
    await expect(card.getByRole('button', { name: 'Rediger' })).toHaveCount(0);
    await expect(card.getByRole('button', { name: 'Slett' })).toHaveCount(0);
    await expectReadable(page, '03-completed');

    // 7. Følg opp i Avvik og forbedringer.
    await card.getByRole('button', { name: 'Følg opp i Avvik og forbedringer' }).click();
    const handoff = page.getByTestId('compliance-handoff-form');
    await expect(handoff.getByTestId('compliance-handoff-type')).toHaveText('Avvik');
    const handoffId = (await handoff.getAttribute('aria-labelledby')).replace('-heading', '');
    await expect(page.locator(`#${handoffId}-title`)).toHaveValue(findingTitle);
    await expect(page.locator(`#${handoffId}-description`)).toHaveValue(findingText);
    // The process is proposed in plain sight, and could be left out.
    await expect(handoff.getByTestId('compliance-handoff-process')).toContainText(person.process_title);
    await expect(handoff.getByTestId('compliance-handoff-process').getByRole('checkbox')).toBeChecked();
    // The owner is chosen after the area, never before.
    await expect(page.locator(`#${handoffId}-owner`)).toBeDisabled();

    // 8–9. Fagområde, ansvarlig, frist.
    await page.locator(`#${handoffId}-area`).selectOption({ label: person.area_name });
    await page.locator(`#${handoffId}-owner`).selectOption({ label: person.name });
    await page.locator(`#${handoffId}-due`).fill('2026-12-31');
    await expectReadable(page, '04-handoff-form');

    // 10. Opprett sak.
    await handoff.getByRole('button', { name: 'Opprett sak' }).click();
    await expect(page.getByText('Funnet er overført til Avvik og forbedringer.')).toBeVisible();

    // 11. Overført — with a link, never the case's status.
    await expect(card.getByTestId('compliance-finding-handed-off')).toHaveText('Overført');
    const caseBox = card.getByTestId('compliance-finding-case');
    await expect(caseBox).toContainText('Overført til Avvik og forbedringer');
    await expect(caseBox).toContainText(person.name);
    await expect(card.getByRole('button', { name: 'Følg opp i Avvik og forbedringer' })).toHaveCount(0);
    for (const word of ['Åpen', 'Under behandling', 'Frist passert']) {
        await expect(caseBox.getByText(word, { exact: true })).toHaveCount(0);
    }
    expect(await complianceFixture(`findingState('${suffix}')`)).toEqual({ handed_off: 1, cases: 1 });
    await expectReadable(page, '05-handed-off');

    // 12–13. Open the case: the finding's text, the chosen area, owner and frist, and the process.
    await card.getByTestId('compliance-finding-case-link').click();
    await page.waitForURL(/\/app\/improvements\/\d+$/);
    await expect(page.getByRole('heading', { name: findingTitle, level: 1 })).toBeVisible();
    await expect(page.getByText(findingText)).toBeVisible();
    await expect(page.getByText('Avvik', { exact: true }).first()).toBeVisible();
    await expect(page.locator('main')).toContainText(person.area_name);
    await expect(page.locator('main')).toContainText('31.12.2026');
    await expect(page.locator('main')).toContainText(person.process_title);

    // 14. Provenance back to the audit.
    const origin = page.getByTestId('improvement-audit-origin');
    await expect(origin).toContainText('Fra revisjonsfunn i');
    await expect(origin).toContainText(`Funn: ${findingTitle}`);
    await expect(page.getByRole('button', { name: 'Slett sak' })).toHaveCount(0);
    await expectReadable(page, '06-case-with-origin');
    await origin.getByRole('link', { name: auditTitle }).click();
    await page.waitForURL((url) => url.href.startsWith(auditUrl));
    await expect(page.getByRole('heading', { name: auditTitle, level: 1 })).toBeVisible();
    await expect(page.getByTestId('compliance-finding-handed-off')).toHaveText('Overført');
});

/**
 * Someone who runs audits but may only *read* cases in Avvik og forbedringer: the hand-off form says
 * they cannot register a case and offers nothing to submit; a request sent anyway is refused and
 * creates nothing. A finding handed off to a case in a fagområde they cannot reach reads Overført —
 * with no link, no case title and no status, in the page or its data.
 */
test('without improvement.edit the hand-off is refused, and a hidden case leaks nothing', async ({ page }) => {
    test.setTimeout(120_000);

    const seeded = await complianceFixture(`seedFindingAccess('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);

    await loginAs(page, seeded.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/compliance/audits/${seeded.audit_id}`);
    await expect(page.getByRole('heading', { name: seeded.audit_title, level: 1 })).toBeVisible();

    const cards = page.getByTestId('compliance-finding');
    await expect(cards).toHaveCount(2);
    const open = cards.filter({ hasText: seeded.finding_title });
    const hidden = cards.filter({ hasText: seeded.handed_off_title });

    // The hidden case: Overført, and nothing about it.
    await expect(hidden.getByTestId('compliance-finding-handed-off')).toHaveText('Overført');
    await expect(hidden.getByTestId('compliance-finding-case')).toContainText('Overført til Avvik og forbedringer');
    await expect(hidden.getByTestId('compliance-finding-case-link')).toHaveCount(0);
    await page.reload();
    const html = await page.content();
    expect(html).not.toContain(seeded.hidden_case_title);
    expect(html).not.toContain(`/app/improvements/${seeded.hidden_case_id}`);
    const props = await pageProps(page);
    const hiddenRow = props.findings.find((finding) => finding.title === seeded.handed_off_title);
    expect(hiddenRow.handed_off).toBe(true);
    expect(hiddenRow.case_link).toBeNull();
    expect(Object.keys(hiddenRow)).not.toContain('status');
    expect(props.handoff.area_options).toEqual([]);

    // The hand-off form says why, and offers nothing to submit.
    await open.getByRole('button', { name: 'Følg opp i Avvik og forbedringer' }).click();
    const handoff = page.getByTestId('compliance-handoff-form');
    await expect(handoff.getByTestId('compliance-handoff-no-areas')).toContainText('Du har ikke tilgang til å registrere saker');
    await expect(handoff.getByRole('button', { name: 'Opprett sak' })).toHaveCount(0);
    await expectReadable(page, '07-handoff-not-allowed');

    // Sent anyway, into the area they can only read: refused, nothing created, nothing marked.
    const headers = await xsrfHeaders(page);
    await page.request.post(`/app/compliance/audits/${seeded.audit_id}/findings/${seeded.finding_id}/handoff`, {
        headers,
        form: { title: seeded.finding_title, description: 'Forsøk.', business_area_id: String(seeded.area_id), owner_user_id: '1' },
    });
    expect(await complianceFixture(`findingState('${suffix}')`)).toEqual({ handed_off: 1, cases: 1 });
    await page.reload();
    await expect(cards.filter({ hasText: seeded.finding_title }).getByTestId('compliance-finding-handed-off')).toHaveCount(0);
    expect(await page.content()).not.toContain(seeded.hidden_case_title);
});
