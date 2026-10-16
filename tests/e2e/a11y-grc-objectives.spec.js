import { expect, test } from '@playwright/test';
import { expectNoAxeViolations } from './helpers/a11y.js';
import { sidewaysOverflow } from './helpers/readability.js';
import { USER, loginAs } from './helpers/auth.js';
import { cleanUpObjectiveE2eData, objectiveE2eName, objectiveE2eSuffix, seedObjectiveMeasurer } from './helpers/objectives.js';
import { PHONE } from './helpers/readability.js';
import { tinker } from './helpers/risk.js';

const suffix = objectiveE2eSuffix();
cleanUpObjectiveE2eData(suffix);

test.afterAll(async () => {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\ObjectiveE2EFixture::remaining('${suffix}'));`);
    const remaining = JSON.parse(stdout.match(/\{.*\}/)[0]);

    expect(remaining).toEqual({
        areas: 0, roles: 0, users: 0, objectives: 0, kpis: 0, kpi_processes: 0, kpi_activities: 0, kpi_measurements: 0, kpi_status_changes: 0, objective_status_changes: 0,
    });
});

// Focus after a panel closes is restored a frame later (refocusIfLost), so it is polled for.
const focusedId = (page) => page.evaluate(() => document.activeElement?.id ?? '');
const focusIsOnBody = (page) => page.evaluate(() => document.activeElement === document.body || document.activeElement === null);

/**
 * WCAG Fase 6, Mål og KPI (docs/wcag-accessibility-plan.md §8): a refused save puts focus on the
 * field that is wrong, with the error tied to it; inline panels take focus and give it back; the
 * tables name their rows; the KPI chart is a picture with its summary and no tab stop per point;
 * status is text; nothing scrolls sideways at 320 px. One user with a role that reads, edits,
 * measures and deletes in one fagområde of the run's own.
 */
test('objectives and KPIs: errors, panels, tables, the chart and status are accessible', async ({ page }) => {
    test.setTimeout(240_000);

    const { area_name: areaName } = await seedObjectiveMeasurer(suffix);
    const objectiveTitle = objectiveE2eName(suffix, 'Tilgjengelig drift');
    const kpiTitle = objectiveE2eName(suffix, 'Oppetid');

    await loginAs(page, USER.email, USER.password);
    await page.goto('/app/objectives');

    // Nytt mål opens in place: focus goes to the first field.
    await page.getByRole('button', { name: 'Nytt mål' }).click();
    await expect(page.locator('#objective-title')).toBeFocused();
    await expect(page.locator('#objective-title')).toHaveAttribute('aria-required', 'true');
    await expect(page.getByText('Felt merket med * må fylles ut.').first()).toBeVisible();

    // A refused save (the browser's own check switched off, so the server answers): focus to the
    // first field that is wrong, marked invalid and described by its error.
    await page.locator('form', { has: page.locator('#objective-title') }).evaluate((form) => { form.noValidate = true; });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.locator('#objective-title')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#objective-title')).toBeFocused();
    await expect(page.locator('#objective-title')).toHaveAttribute('aria-describedby', /objective-title-error/);
    await expect(page.locator('#objective-title-error')).not.toBeEmpty();
    await expectNoAxeViolations(page, 'form:has(#objective-title)', 'Nytt mål with errors');

    // Avbryt gives focus back to «Nytt mål».
    await page.getByRole('button', { name: 'Avbryt', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Nytt mål' })).toBeFocused();

    await page.getByRole('button', { name: 'Nytt mål' }).click();
    await page.locator('#objective-title').fill(objectiveTitle);
    await page.locator('#objective-area').selectOption({ label: areaName });
    await page.locator('#objective-owner').selectOption({ label: 'E2E User' });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/objectives\/\d+$/);
    const objectiveUrl = page.url();

    // Lukk mål: focus into the panel (the outcome group), back to the button on Avbryt.
    await page.getByRole('button', { name: 'Lukk mål' }).click();
    await expect(page.getByRole('group', { name: 'Utfall' })).toBeVisible();
    expect(await page.evaluate(() => document.activeElement?.getAttribute('name'))).toBe('objective-close-outcome');
    await page.getByRole('button', { name: 'Avbryt', exact: true }).click();
    await expect.poll(() => focusedId(page)).toBe('objective-action-close');

    // Ny KPI: focus to the title; a refused number is caught before sending and focused.
    const kpiSection = page.locator('section', { has: page.getByRole('heading', { name: 'KPI-er', exact: true }) });
    await kpiSection.getByRole('button', { name: 'Ny KPI' }).click();
    await expect(page.locator('#kpi-title')).toBeFocused();
    await page.locator('#kpi-title').fill(kpiTitle);
    await page.locator('#kpi-unit').selectOption({ label: 'Prosentandel' });
    await page.locator('#kpi-target-min').fill('abc');
    // From the keyboard: leaving the field shows its error (on blur, as designed), then Lagre is
    // pressed and the refused submit puts focus back on the field. A mouse click is not used here:
    // the error appearing on blur pushes Lagre down between mousedown and click (A11Y-186).
    const kpiSave = kpiSection.getByRole('button', { name: 'Lagre', exact: true });
    await kpiSave.focus();
    await expect(page.locator('#kpi-target-min-error')).toBeVisible();
    await expect(kpiSave).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.locator('#kpi-target-min')).toBeFocused();
    await expect(page.locator('#kpi-target-min')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#kpi-target-min')).toHaveAttribute('aria-describedby', /kpi-target-min-error/);
    await expect(page.getByRole('radiogroup', { name: /Måltype/ })).toBeVisible();
    await expectNoAxeViolations(page, 'form:has(#kpi-title)', 'Ny KPI with a number error');
    await page.locator('#kpi-target-min').fill('98');
    await page.locator('#kpi-frequency').selectOption({ label: 'Månedlig' });
    await kpiSection.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/objectives\/\d+\/kpis\/\d+$/);

    // Registrer måling: focus to the first field; Avbryt back to the button.
    await page.getByRole('button', { name: 'Registrer måling' }).click();
    await expect(page.locator('#measurement-period')).toBeFocused();
    await page.getByRole('button', { name: 'Avbryt', exact: true }).click();
    await expect.poll(() => focusedId(page)).toBe('kpi-action-measure');

    await page.getByRole('button', { name: 'Registrer måling' }).click();
    const firstPeriod = await page.locator('#measurement-period').inputValue();
    await page.locator('#measurement-value').fill('99,1');
    await page.getByRole('button', { name: 'Registrer måling', exact: true }).last().click();
    await expect(page.getByText('Målingen er registrert.')).toBeVisible();
    await page.getByRole('button', { name: 'Registrer måling' }).click();
    const secondPeriod = await page.locator('#measurement-period option').evaluateAll((options, used) => options.map((option) => option.value).find((value) => value !== used), firstPeriod);
    await page.locator('#measurement-period').selectOption(secondPeriod);
    await page.locator('#measurement-value').fill('97,5');
    await page.getByRole('button', { name: 'Registrer måling', exact: true }).last().click();
    await expect(page.getByText('Målingen er registrert.')).toBeVisible();

    // Status is text, not colour alone.
    await expect(page.getByTestId('kpi-current-result')).not.toBeEmpty();

    // The chart: a picture named by its summary, no tab stop inside, and a link to the table.
    const trend = page.getByTestId('kpi-trend');
    await expect(trend).toHaveAttribute('role', 'img');
    await expect(trend).toHaveAttribute('aria-label', /2 målinger fra .+ Målverdi: .+ Dagens status: .+Målehistorikk/);
    await expect(trend.locator('[tabindex]')).toHaveCount(0);
    await trend.getByTestId('kpi-trend-point').first().hover();
    await expect(page.getByTestId('kpi-trend-tooltip')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('kpi-trend-tooltip')).toHaveCount(0);
    await page.getByRole('link', { name: 'Se alle verdiene i Målehistorikk' }).click();
    await expect.poll(() => focusedId(page)).toBe('kpi-measurements-heading');

    // Målehistorikk: a captioned table, column headers with scope, the period as row header, and
    // row actions described by their period.
    const table = page.getByTestId('kpi-measurements');
    await expect(table.locator('caption')).toHaveText('Målehistorikk');
    expect(await table.locator('thead th:not([scope="col"])').count()).toBe(0);
    await expect(table.locator('tbody th[scope="row"]')).toHaveCount(2);
    const withdraw = table.locator('tbody tr').first().getByRole('button', { name: 'Trekk tilbake' });
    await expect(withdraw).toHaveAttribute('aria-describedby', /kpi-measurement-\d+-period/);

    // Trekk tilbake opens in the row: focus to the reason; Avbryt gives it back to the button.
    await withdraw.click();
    await expect.poll(() => focusedId(page)).toMatch(/^withdraw-reason-\d+$/);
    await page.getByRole('button', { name: 'Avbryt', exact: true }).click();
    await expect.poll(() => focusedId(page)).toMatch(/^kpi-withdraw-\d+$/);

    // Withdrawn: the button is gone with the state, focus is not dropped to <body>.
    await table.locator('tbody tr').first().getByRole('button', { name: 'Trekk tilbake' }).click();
    await page.locator('[id^="withdraw-reason-"]').fill('Feilregistrert');
    await page.locator('form', { has: page.locator('[id^="withdraw-reason-"]') }).getByRole('button', { name: 'Trekk tilbake' }).click();
    await expect(table.locator('tbody tr[data-state="withdrawn"]')).toHaveCount(1);
    await expect.poll(() => focusIsOnBody(page)).toBe(false);

    await expectNoAxeViolations(page, '[data-testid="kpi-result"]', 'KPI result');
    await expectNoAxeViolations(page, 'section[aria-labelledby="kpi-trend-heading"]', 'KPI chart');
    await expectNoAxeViolations(page, 'section[aria-labelledby="kpi-measurements-heading"]', 'Målehistorikk');

    // The objective's KPI table and the register: captions, scopes and a row header.
    await page.goto(objectiveUrl);
    const kpis = page.getByTestId('objective-kpis');
    await expect(kpis.locator('caption')).toHaveText('KPI-er');
    await expect(kpis.locator('tbody th[scope="row"]')).toContainText(kpiTitle);
    await expectNoAxeViolations(page, 'section[aria-labelledby="objective-kpis-heading"]', 'KPI list');

    await page.goto('/app/objectives');
    const register = page.locator('table', { has: page.getByRole('link', { name: objectiveTitle }) });
    await expect(register.locator('caption')).toHaveCount(1);
    await expect(register.locator('tbody th[scope="row"]').filter({ hasText: objectiveTitle })).toHaveCount(1);
    expect(await register.locator('thead th:not([scope="col"])').count()).toBe(0);

    // 320 px: nothing scrolls sideways — the register, the objective and the KPI page.
    await page.setViewportSize({ width: 320, height: PHONE.height });
    for (const url of ['/app/objectives', objectiveUrl]) {
        await page.goto(url);
        expect(await sidewaysOverflow(page), url).toEqual([]);
    }
    await page.goto(objectiveUrl);
    await page.getByRole('link', { name: kpiTitle }).first().click();
    await page.waitForURL(/\/kpis\/\d+$/);
    expect(await sidewaysOverflow(page), 'KPI page').toEqual([]);
});
