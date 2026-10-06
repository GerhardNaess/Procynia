import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { COMPLIANCE_E2E_PASSWORD, cleanUpComplianceE2eData, complianceE2eName, complianceE2eSuffix, complianceFixture } from './helpers/compliance.js';
import { DESKTOP, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';

const suffix = complianceE2eSuffix();
cleanUpComplianceE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'compliance-audit', name);

async function xsrfHeaders(page) {
    const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');

    return { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'text/html' };
}

/**
 * Revisjoner, end to end: someone who may plan and run audits — and read Kvalitet — opens
 * Etterlevelse og revisjon → Revisjoner, registers an audit, takes requirements into scope (one by
 * hand, the rest through the source shortcut) and a Kvalitet process, starts it, writes the
 * conclusion, is stopped when completing without one, completes it, finds it locked with its
 * history, and reopens it with a reason. Every step is checked for text under 16 px and sideways
 * scrolling at desktop and 390 px.
 */
test('an audit is planned, scoped, run, completed, locked and reopened', async ({ page }) => {
    test.setTimeout(240_000);

    const person = await complianceFixture(`seedAuditJourney('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);
    const title = complianceE2eName(suffix, 'Internrevisjon tilgangsstyring');

    await loginAs(page, person.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // Level 2: Krav | Revisjoner — and nothing else.
    await page.goto('/app/compliance/audits');
    const areas = page.getByTestId('module-navigation');
    await expect(areas.locator('a')).toHaveText(['Krav', 'Revisjoner']);
    await expect(areas.locator('[aria-current="page"]')).toHaveText('Revisjoner');
    await expect(page.getByRole('heading', { name: 'Revisjoner', level: 1 })).toBeVisible();
    await expectPageHelp(page, 'Om revisjoner', ['Hva en revisjon er', 'Status', 'Registeret']);
    await expectReadable(page, '01-register');

    // 1. Opprett revisjon.
    await page.getByRole('button', { name: 'Ny revisjon' }).click();
    await page.locator('#compliance-audit-title').fill(title);
    await expect(page.locator('#compliance-audit-type-internal')).toBeChecked();
    await page.locator('#compliance-audit-responsible').selectOption({ label: person.name });
    await page.locator('#compliance-audit-start').fill('2026-11-01');
    await page.locator('#compliance-audit-end').fill('2026-11-15');
    await page.locator('#compliance-audit-scope').fill('Intern revisjon av tilgangsstyring og brukeradministrasjon for IT-avdelingen.');
    await expectReadable(page, '02-create-form');
    await page.getByTestId('compliance-audit-form').getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/compliance\/audits\/\d+$/);
    await expect(page.getByText('Revisjonen er registrert.')).toBeVisible();

    await expect(page.getByRole('heading', { name: title, level: 1 })).toBeVisible();
    await expect(page.getByTestId('compliance-audit-status')).toHaveText('Planlagt');
    await expect(page.getByTestId('compliance-audit-facts')).toContainText(person.name);
    await expect(page.getByTestId('compliance-audit-facts')).toContainText('01.11.2026');
    await expect(page.getByTestId('compliance-audit-scope')).toHaveText('Intern revisjon av tilgangsstyring og brukeradministrasjon for IT-avdelingen.');
    await expect(page.getByText('Ingen statusendringer ennå.')).toBeVisible();
    await expect(page.getByTestId('module-navigation').locator('[aria-current="page"]')).toHaveText('Revisjoner');
    await expectPageHelp(page, 'Om revisjonen', ['Scope', 'Gjennomføring', 'Statushistorikk']);

    // 2. Legg til krav i scope — one by hand, then the rest of the source through the shortcut.
    const requirements = page.getByTestId('compliance-audit-requirements');
    await requirements.getByRole('button', { name: 'Legg til krav' }).click();
    await page.getByRole('checkbox', { name: person.first_requirement }).check();
    await expectReadable(page, '03-requirement-picker');
    await page.getByTestId('compliance-audit-requirement-form').getByRole('button', { name: 'Legg til valgte krav' }).click();
    await expect(page.getByText('Kravet er lagt til i scope.')).toBeVisible();
    await expect(page.getByTestId('compliance-audit-requirement')).toHaveCount(1);

    await requirements.getByRole('button', { name: 'Legg til krav' }).click();
    await page.locator('#compliance-audit-source').selectOption({ label: `${person.source_label} (1 aktive krav)` });
    await page.getByTestId('compliance-audit-source-form').getByRole('button', { name: 'Legg til fra kilde' }).click();
    await expect(page.getByText('Kravet er lagt til i scope.')).toBeVisible();
    await expect(page.getByTestId('compliance-audit-requirement')).toHaveCount(2);
    await expect(requirements).toContainText(`A.5.15 ${person.first_requirement}`);
    await expect(requirements).toContainText(`A.5.18 ${person.second_requirement}`);

    // 3. Legg til Kvalitet-prosess.
    const processes = page.getByTestId('compliance-audit-processes');
    await processes.getByRole('button', { name: 'Legg til prosess' }).click();
    await page.locator('#compliance-audit-process').selectOption({ label: person.process_title });
    await page.getByTestId('compliance-audit-process-form').getByRole('button', { name: 'Legg til', exact: true }).click();
    await expect(page.getByText('Prosessen er lagt til i scope.')).toBeVisible();
    await expect(page.getByTestId('compliance-audit-process')).toHaveCount(1);
    await expect(page.getByTestId('compliance-audit-process')).toContainText(person.process_title);
    await expectReadable(page, '04-scoped');

    // 4. Start revisjon.
    await page.getByTestId('compliance-audit-actions').getByRole('button', { name: 'Start revisjon' }).click();
    await page.getByTestId('compliance-audit-start').getByRole('button', { name: 'Start revisjon' }).click();
    await expect(page.getByText('Revisjonen er startet.', { exact: true })).toBeVisible();
    await expect(page.getByTestId('compliance-audit-status')).toHaveText('Under arbeid');

    // 5. Skriv konklusjon — the type is no longer offered once the audit has started.
    await page.getByRole('button', { name: 'Rediger' }).click();
    await expect(page.locator('#compliance-audit-type-internal')).toHaveCount(0);
    await page.locator('#compliance-audit-conclusion').fill('Tilgangsstyringen fungerer etter hensikten. Ingen vesentlige avvik.');
    await expectReadable(page, '05-edit-in-progress');
    await page.getByTestId('compliance-audit-form').getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Revisjonen er oppdatert.')).toBeVisible();
    await expect(page.getByTestId('compliance-audit-conclusion')).toHaveText('Tilgangsstyringen fungerer etter hensikten. Ingen vesentlige avvik.');

    // 6. Fullfør revisjon — not without a conclusion, then with it.
    await page.getByTestId('compliance-audit-actions').getByRole('button', { name: 'Fullfør revisjon' }).click();
    const complete = page.getByTestId('compliance-audit-complete');
    await expect(page.locator('#compliance-audit-complete-conclusion')).toHaveValue('Tilgangsstyringen fungerer etter hensikten. Ingen vesentlige avvik.');
    await page.locator('#compliance-audit-complete-conclusion').fill('');
    await complete.getByRole('button', { name: 'Fullfør revisjon' }).click();
    await expect(complete.getByText('Skriv en konklusjon før revisjonen fullføres.')).toBeVisible();
    await expect(page.getByTestId('compliance-audit-status')).toHaveText('Under arbeid');
    await expectReadable(page, '06-complete-needs-conclusion');
    await page.locator('#compliance-audit-complete-conclusion').fill('Tilgangsstyringen fungerer etter hensikten. Revisjonen avsluttes uten funn.');
    await complete.getByRole('button', { name: 'Fullfør revisjon' }).click();
    await expect(page.getByText('Revisjonen er fullført.')).toBeVisible();

    // 7. Locked.
    await expect(page.getByTestId('compliance-audit-status')).toHaveText('Fullført');
    await expect(page.getByTestId('compliance-audit-locked')).toHaveText('Revisjonen er fullført og låst. Gjenåpne den for å gjøre endringer.');
    await expect(page.getByTestId('compliance-audit-conclusion')).toHaveText('Tilgangsstyringen fungerer etter hensikten. Revisjonen avsluttes uten funn.');
    for (const name of ['Rediger', 'Legg til krav', 'Legg til prosess', 'Fjern fra scope', 'Avbryt revisjon', 'Fullfør revisjon', 'Start revisjon', 'Slett revisjon']) {
        await expect(page.getByRole('button', { name, exact: true })).toHaveCount(0);
    }
    await expect(page.getByRole('button', { name: 'Gjenåpne revisjon' })).toBeVisible();
    await expect(page.getByTestId('compliance-audit-requirement')).toHaveCount(2);

    // Sent anyway, with a valid session: refused, nothing changes.
    const auditId = page.url().match(/audits\/(\d+)$/)[1];
    const headers = await xsrfHeaders(page);
    await page.request.patch(`/app/compliance/audits/${auditId}`, { headers, form: { title: 'Endret etter fullføring', scope_description: 'x', planned_end_date: '2026-11-15' } });
    await page.reload();
    await expect(page.getByRole('heading', { name: title, level: 1 })).toBeVisible();

    // 8. Statushistorikk.
    const history = page.getByTestId('compliance-audit-history').locator('li');
    await expect(history).toHaveCount(2);
    await expect(history.nth(0)).toContainText(`Fullført av ${person.name}`);
    await expect(history.nth(1)).toContainText(`Startet av ${person.name}`);
    await expectReadable(page, '07-completed-locked');

    // 9. Gjenåpne med begrunnelse.
    await page.getByRole('button', { name: 'Gjenåpne revisjon' }).click();
    const reopen = page.getByTestId('compliance-audit-reopen');
    await page.locator('#compliance-audit-reopen-reason').fill('Konklusjonen må presiseres etter ledelsens gjennomgang.');
    await reopen.getByRole('button', { name: 'Gjenåpne revisjon' }).click();
    await expect(page.getByText('Revisjonen er gjenåpnet.')).toBeVisible();

    // 10. Ny status og historikk.
    await expect(page.getByTestId('compliance-audit-status')).toHaveText('Under arbeid');
    await expect(page.getByTestId('compliance-audit-locked')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Rediger' })).toBeVisible();
    await expect(history).toHaveCount(3);
    await expect(history.nth(0)).toContainText(`Gjenåpnet av ${person.name}`);
    await expect(history.nth(0)).toContainText('Konklusjonen må presiseres etter ledelsens gjennomgang.');
    await expectReadable(page, '08-reopened');

    // The register lists it, and Krav is one click away in the same row.
    await page.getByRole('link', { name: 'Til revisjoner' }).click();
    await expect(page.getByRole('link', { name: title })).toHaveCount(1);
    await expect(page.getByTestId('compliance-audit-table')).toContainText('Under arbeid');
    await expect(page.getByTestId('compliance-audit-table')).toContainText('01.11.2026–15.11.2026');
    await areas.getByRole('link', { name: 'Krav' }).click();
    await page.waitForURL(/\/app\/compliance\/requirements$/);
    await expect(page.getByTestId('module-navigation').locator('[aria-current="page"]')).toHaveText('Krav');
});

/**
 * Someone with compliance.view only — and no Kvalitet — opens an audit in progress that has a
 * requirement and a Kvalitet process in scope: they read the audit and its requirements, are offered
 * nothing to change, find no trace of the process, and a request sent anyway is refused.
 */
test('a reader reads an audit, cannot change it, and sees no Kvalitet process', async ({ page }) => {
    test.setTimeout(120_000);

    const seeded = await complianceFixture(`seedAuditReader('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);

    await loginAs(page, seeded.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    await page.goto('/app/compliance/audits');
    await expect(page.getByRole('button', { name: 'Ny revisjon' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: seeded.audit_title })).toHaveCount(1);
    await expectReadable(page, '09-reader-register');

    await page.getByRole('link', { name: seeded.audit_title }).click();
    await page.waitForURL(new RegExp(`/app/compliance/audits/${seeded.audit_id}$`));
    await expect(page.getByTestId('compliance-audit-status')).toHaveText('Under arbeid');
    await expect(page.getByTestId('compliance-audit-facts')).toContainText('Sertifiseringsorganet AS');
    await expect(page.getByTestId('compliance-audit-requirement')).toContainText(seeded.requirement_title);
    await expect(page.getByTestId('compliance-audit-actions')).toHaveCount(0);
    for (const name of ['Rediger', 'Legg til krav', 'Fjern fra scope', 'Start revisjon', 'Fullfør revisjon', 'Avbryt revisjon', 'Gjenåpne revisjon', 'Slett revisjon']) {
        await expect(page.getByRole('button', { name, exact: true })).toHaveCount(0);
    }

    // No Kvalitet: no section, no name, no id — not in the page, not in the props. Loaded fresh, so
    // the page data is this page's and not the register's it was reached from.
    await page.reload();
    await expect(page.getByTestId('compliance-audit-processes')).toHaveCount(0);
    await expect(page.getByText('Prosesser i scope')).toHaveCount(0);
    const html = await page.content();
    expect(html).not.toContain(seeded.process_title);
    expect(html).not.toContain(`/app/quality/items/${seeded.process_id}`);
    const props = await page.evaluate(() => JSON.parse(document.querySelector('script[data-page]')?.textContent ?? document.getElementById('app').dataset.page).props);
    expect(props.processes).toBeNull();
    expect(props.process_options).toBeNull();
    expect(props.permissions.can_manage_processes).toBe(false);
    await expectReadable(page, '10-reader-audit');

    // Sent anyway, with a valid session and CSRF token: refused.
    const headers = await xsrfHeaders(page);
    for (const [method, path, form] of [
        ['post', 'complete', { conclusion: 'Ikke min å skrive.' }],
        ['post', 'cancel', { reason: 'Ikke min å avbryte.' }],
        ['post', 'requirements', { 'requirement_ids[]': '1' }],
        ['post', 'processes', { quality_process_id: String(seeded.process_id) }],
        ['delete', `processes/${seeded.process_id}`, undefined],
    ]) {
        const response = await page.request[method](`/app/compliance/audits/${seeded.audit_id}/${path}`, { headers, form });
        expect(response.status(), `${method} ${path}`).toBe(403);
    }
    const edit = await page.request.patch(`/app/compliance/audits/${seeded.audit_id}`, { headers, form: { title: 'Endret av leser' } });
    expect(edit.status()).toBe(403);

    await page.reload();
    await expect(page.getByRole('heading', { name: seeded.audit_title, level: 1 })).toBeVisible();
    await expect(page.getByTestId('compliance-audit-status')).toHaveText('Under arbeid');
});
