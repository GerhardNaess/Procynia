import { expect, test } from '@playwright/test';
import { USER, loginAs } from './helpers/auth.js';
import { cleanUpObjectiveE2eData, objectiveE2eName, objectiveE2eSuffix, seedObjectiveMeasurer } from './helpers/objectives.js';
import { tinker } from './helpers/risk.js';

const suffix = objectiveE2eSuffix();
cleanUpObjectiveE2eData(suffix);

// After the per-test cleanup: nothing this run created may be left — measurements and every
// status change included.
test.afterAll(async () => {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\ObjectiveE2EFixture::remaining('${suffix}'));`);
    const remaining = JSON.parse(stdout.match(/\{.*\}/)[0]);

    expect(remaining).toEqual({
        areas: 0, roles: 0, users: 0, objectives: 0, kpis: 0, kpi_processes: 0, kpi_activities: 0, kpi_measurements: 0, kpi_status_changes: 0, objective_status_changes: 0,
    });
});

/**
 * One full measurement journey by an ordinary user with a role that reads, edits, measures and
 * deletes in one area: create an objective and a KPI, register a result, correct it, withdraw the
 * correction, tighten the target, read the objective's indicator, fail to delete the KPI, retire
 * it and close the objective.
 */
test('a KPI is measured, corrected, withdrawn and judged against today\'s and the historical target', async ({ page }) => {
    test.setTimeout(180_000);

    const { area_name: areaName } = await seedObjectiveMeasurer(suffix);
    const objectiveTitle = objectiveE2eName(suffix, 'Sikker drift');
    const kpiTitle = objectiveE2eName(suffix, 'Vellykket backup');

    await loginAs(page, USER.email, USER.password);

    // Opprett mål.
    await page.goto('/app/objectives');
    await page.getByRole('button', { name: 'Nytt mål' }).click();
    await page.locator('#objective-title').fill(objectiveTitle);
    await page.locator('#objective-area').selectOption({ label: areaName });
    await page.locator('#objective-owner').selectOption({ label: 'E2E User' });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/objectives\/\d+$/);
    const objectiveUrl = page.url();

    // Opprett KPI: ≥ 98 %, monthly, no tolerance.
    const kpiSection = page.locator('section', { has: page.getByRole('heading', { name: 'KPI-er', exact: true }) });
    await kpiSection.getByRole('button', { name: 'Ny KPI' }).click();
    await page.locator('#kpi-title').fill(kpiTitle);
    await page.locator('#kpi-unit').selectOption({ label: 'Prosent' });
    await page.locator('#kpi-target-min').fill('98');
    await page.locator('#kpi-frequency').selectOption({ label: 'Månedlig' });
    await kpiSection.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/objectives\/\d+\/kpis\/\d+$/);
    const kpiUrl = page.url();

    const latestValue = page.getByTestId('kpi-latest-value');
    const currentResult = page.getByTestId('kpi-current-result');
    const history = page.getByTestId('kpi-measurements');
    await expect(currentResult).toHaveText('Ikke målt');
    await expect(page.getByText('Ingen målinger er registrert ennå.')).toBeVisible();

    // Registrer første måling: the latest ended month is suggested, the running month never offered.
    await page.getByRole('button', { name: 'Registrer måling' }).click();
    const periodSelect = page.locator('#measurement-period');
    const periodLabel = (await periodSelect.locator('option:checked').textContent()).replace(/\s*\(foreslått\).*$/, '').trim();
    expect(periodLabel).toMatch(/^[A-ZÆØÅ][a-zæøå]+ \d{4}$/);
    await expect(page.getByTestId('measurement-correction')).toHaveCount(0);
    await page.locator('#measurement-value').fill('98,7');
    await page.screenshot({ path: 'test-results/kpi-measurements-01-register.png', fullPage: true });
    await page.getByRole('button', { name: 'Registrer måling', exact: true }).last().click();
    await expect(page.getByText('Målingen er registrert.')).toBeVisible();
    await expect(latestValue).toHaveText('98,7 %');
    await expect(currentResult).toHaveText('På mål');
    await expect(history.locator('tbody tr')).toHaveCount(1);
    await expect(history.locator('tbody tr').first()).toContainText(periodLabel);
    await expect(history.locator('tbody tr').first()).toContainText('På mål');
    await expect(history.locator('tbody tr').first()).toContainText('≥ 98 %');

    // Korrigering for samme periode: the form says so, and the comment is required.
    await page.getByRole('button', { name: 'Registrer måling' }).click();
    await periodSelect.selectOption({ label: await periodSelect.locator('option', { hasText: periodLabel }).first().textContent() });
    await expect(page.getByTestId('measurement-correction')).toContainText('Denne perioden har allerede en registrert verdi på 98,7 %. Den nye målingen erstatter den som gjeldende verdi, men historikken beholdes.');
    await page.locator('#measurement-value').fill('97,5');
    await page.locator('#measurement-comment').evaluate((el) => el.removeAttribute('required'));
    await page.getByRole('button', { name: 'Registrer måling', exact: true }).last().click();
    await expect(page.getByText('Perioden har allerede en verdi. Forklar hvorfor den korrigeres.')).toBeVisible();
    await page.locator('#measurement-comment').fill('Rapporten talte feil.');
    await page.screenshot({ path: 'test-results/kpi-measurements-02-correction.png', fullPage: true });
    await page.getByRole('button', { name: 'Registrer måling', exact: true }).last().click();
    await expect(page.getByText('Målingen er registrert.')).toBeVisible();
    await expect(latestValue).toHaveText('97,5 %');
    await expect(currentResult).toHaveText('Utenfor mål');

    // «Erstattet»: the old row stays.
    const rows = history.locator('tbody tr');
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(0)).toHaveAttribute('data-state', 'current');
    await expect(rows.nth(0)).toContainText('Rapporten talte feil.');
    await expect(rows.nth(1)).toHaveAttribute('data-state', 'superseded');
    await expect(rows.nth(1)).toContainText('Erstattet');
    await expect(rows.nth(1)).toContainText('98,7 %');

    // Trekk tilbake korrigeringen: the reason is required; the earlier value counts again.
    await rows.nth(0).getByRole('button', { name: 'Trekk tilbake' }).click();
    const withdrawForm = history.locator('form');
    await withdrawForm.locator('textarea').evaluate((el) => el.removeAttribute('required'));
    await withdrawForm.getByRole('button', { name: 'Trekk tilbake' }).click();
    await expect(page.getByText('Begrunnelse må fylles ut.')).toBeVisible();
    await withdrawForm.locator('textarea').fill('Feil rapport ble brukt.');
    await withdrawForm.getByRole('button', { name: 'Trekk tilbake' }).click();
    await expect(page.getByText('Målingen er trukket tilbake.')).toBeVisible();
    await expect(latestValue).toHaveText('98,7 %');
    await expect(currentResult).toHaveText('På mål');
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(0)).toHaveAttribute('data-state', 'withdrawn');
    await expect(rows.nth(0)).toContainText('Tilbaketrukket');
    await expect(rows.nth(0)).toContainText('Feil rapport ble brukt.');
    await expect(rows.nth(0).getByRole('button', { name: 'Trekk tilbake' })).toHaveCount(0);
    await expect(rows.nth(1)).toHaveAttribute('data-state', 'current');
    await expect(rows.nth(1)).not.toContainText('Erstattet');
    await page.screenshot({ path: 'test-results/kpi-measurements-03-withdrawn.png', fullPage: true });

    // Endre target: the unit is locked, the target is not. Today's status changes, the history's does not.
    await page.getByRole('button', { name: 'Rediger' }).click();
    await expect(page.locator('#kpi-unit')).toBeDisabled();
    await expect(page.getByText('Enheten er låst fordi KPI-en har målinger.')).toBeVisible();
    await page.locator('#kpi-target-min').fill('99,5');
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('KPI-en er oppdatert.')).toBeVisible();
    await expect(page.getByTestId('kpi-result')).toContainText('≥ 99,5 %');
    await expect(latestValue).toHaveText('98,7 %');
    await expect(currentResult).toHaveText('Utenfor mål');
    await expect(rows.nth(1)).toContainText('På mål');
    await expect(rows.nth(1)).toContainText('≥ 98 %');
    await page.screenshot({ path: 'test-results/kpi-measurements-04-target-changed.png', fullPage: true });

    // The objective: «0 av 1 KPI-er på mål», and the row carries the latest value and today's status.
    await page.goto(objectiveUrl);
    await expect(page.getByTestId('objective-kpi-indicator')).toHaveText('0 av 1 KPI-er på mål');
    const kpiRow = page.getByTestId('objective-kpis').locator('tbody tr', { hasText: kpiTitle });
    await expect(kpiRow).toContainText('98,7 %');
    await expect(kpiRow).toContainText(periodLabel);
    await expect(kpiRow).toContainText('Utenfor mål');
    // An objective whose KPI has measurements is closed, not deleted.
    await expect(page.getByRole('button', { name: 'Slett mål' })).toHaveCount(0);
    await page.screenshot({ path: 'test-results/kpi-measurements-05-objective.png', fullPage: true });

    // The register shows the same count.
    await page.goto('/app/objectives');
    const registerRow = page.locator('tbody tr', { hasText: objectiveTitle });
    await expect(registerRow.getByTestId('objective-kpi-indicator')).toHaveText('0 av 1 KPI-er på mål');

    // Forsøk å slette KPI-en: no button, and the server refuses the request itself.
    await page.goto(kpiUrl);
    await expect(page.getByRole('button', { name: 'Slett KPI' })).toHaveCount(0);
    const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    const response = await page.request.delete(kpiUrl, {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Referer: kpiUrl },
        maxRedirects: 0,
    });
    expect([302, 303]).toContain(response.status());
    await page.goto(kpiUrl);
    await expect(page.getByText('KPI-en kan ikke slettes. Avslutt den i stedet.')).toBeVisible();
    await expect(page.getByRole('heading', { name: kpiTitle, level: 1 })).toBeVisible();

    // Avslutt KPI: it no longer takes measurements.
    await page.getByRole('button', { name: 'Avslutt KPI' }).click();
    await page.locator('#kpi-retire-note').fill('Erstattes av ny backupløsning.');
    await page.getByRole('button', { name: 'Avslutt KPI', exact: true }).last().click();
    await expect(page.getByText('KPI-en er avsluttet.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Registrer måling' })).toHaveCount(0);
    await expect(rows.nth(1).getByRole('button', { name: 'Trekk tilbake' })).toHaveCount(0);

    // Lukk mål.
    await page.goto(objectiveUrl);
    await page.getByRole('button', { name: 'Lukk mål' }).click();
    await page.getByRole('radio', { name: 'Ikke oppnådd', exact: true }).check();
    await page.getByRole('button', { name: 'Lukk mål', exact: true }).last().click();
    await expect(page.getByText('Målet er lukket.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Slett mål' })).toHaveCount(0);
    // No active KPI left, so no count to show.
    await expect(page.getByTestId('objective-kpi-indicator')).toHaveCount(0);
    await page.screenshot({ path: 'test-results/kpi-measurements-06-closed.png', fullPage: true });
});
