import { expect, test } from '@playwright/test';
import { USER, loginAs } from './helpers/auth.js';
import { cleanUpObjectiveE2eData, objectiveE2eName, objectiveE2eSuffix, seedObjectiveEditor } from './helpers/objectives.js';
import { tinker } from './helpers/risk.js';

const suffix = objectiveE2eSuffix();
cleanUpObjectiveE2eData(suffix);

// After the per-test cleanup: nothing this run created may be left, KPI history included.
test.afterAll(async () => {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\ObjectiveE2EFixture::remaining('${suffix}'));`);
    const remaining = JSON.parse(stdout.match(/\{.*\}/)[0]);

    expect(remaining).toEqual({
        areas: 0, roles: 0, users: 0, objectives: 0, kpis: 0, kpi_processes: 0, kpi_activities: 0, kpi_measurements: 0, kpi_status_changes: 0, objective_status_changes: 0,
    });
});

/**
 * A KPI, end to end, by an ordinary user with a role for one area: create an objective, give it a
 * KPI, open it, edit it, retire it and reopen it (reading the history), delete it, delete the
 * objective. The role has no objective.measure, so no measurement is offered.
 */
test('a KPI is created under an objective, edited, retired, reopened and deleted', async ({ page }) => {
    test.setTimeout(120_000);

    const { area_name: areaName } = await seedObjectiveEditor(suffix);
    const objectiveTitle = objectiveE2eName(suffix, 'Stabil kundeportal');
    const kpiTitle = objectiveE2eName(suffix, 'Oppetid');
    const editedKpiTitle = objectiveE2eName(suffix, 'Alvorlige avvik');

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

    // The objective's KPI section starts empty, with Ny KPI offered.
    const kpiSection = page.locator('section', { has: page.getByRole('heading', { name: 'KPI-er', exact: true }) });
    await expect(kpiSection).toContainText('Ingen KPI-er er registrert for dette målet ennå.');

    // Opprett KPI: Oppetid ≥ 99,5 %, tolerance 1, monthly, no own owner.
    await kpiSection.getByRole('button', { name: 'Ny KPI' }).click();
    await expect(page.locator('#kpi-status')).toHaveCount(0);
    await page.locator('#kpi-title').fill(kpiTitle);
    await page.locator('#kpi-description').fill('Andel av måneden kundeportalen er tilgjengelig.');
    await page.locator('#kpi-unit').selectOption({ label: 'Prosent' });
    await expect(page.locator('#kpi-currency-code')).toHaveCount(0);
    await expect(page.locator('#kpi-unit-label')).toHaveCount(0);
    await page.locator('#kpi-target-min').fill('99,5');
    await page.locator('#kpi-tolerance').fill('1');
    // The unit sits inside each number field, outside the value.
    await expect(page.getByTestId('kpi-target-min-suffix')).toHaveText('%');
    await expect(page.getByTestId('kpi-tolerance-suffix')).toHaveText('%');
    await expect(page.locator('#kpi-target-min')).toHaveValue('99,5');
    await page.locator('#kpi-frequency').selectOption({ label: 'Månedlig' });
    await expect(page.locator('#kpi-grace-days')).toHaveValue('7');
    await expect(page.locator('#kpi-owner')).toHaveValue('');
    await expect(page.locator('#kpi-owner option').first()).toHaveText('Målets ansvarlig (E2E User)');
    await page.screenshot({ path: 'test-results/kpis-01-create.png', fullPage: true });
    await kpiSection.getByRole('button', { name: 'Lagre', exact: true }).click();

    // Åpne KPI: lands on the KPI page.
    await page.waitForURL(/\/app\/objectives\/\d+\/kpis\/\d+$/);
    const kpiUrl = page.url();
    await expect(page.getByText('KPI-en er registrert.')).toBeVisible();
    await expect(page.getByRole('heading', { name: kpiTitle, level: 1 })).toBeVisible();
    const details = page.getByTestId('kpi-details');
    const result = page.getByTestId('kpi-result');
    await expect(result).toContainText('≥ 99,5 %');
    await expect(result).toContainText('Månedlig');
    await expect(result).toContainText('E2E User (målets ansvarlig)');
    await expect(result).toContainText('Ikke målt');
    await expect(details).toContainText('1 %');
    await expect(details).toContainText('Prosent');
    await expect(details).toContainText('7 dager etter at perioden er slutt');
    await expect(details).toContainText(objectiveTitle);
    // This role has no objective.measure.
    await expect(page.getByRole('button', { name: 'Registrer måling' })).toHaveCount(0);
    await expect(page.getByTestId('kpi-history')).toHaveCount(0);
    await page.screenshot({ path: 'test-results/kpis-02-show.png', fullPage: true });

    // The objective lists it.
    await page.goto(objectiveUrl);
    const row = page.getByTestId('objective-kpis').locator('tbody tr', { hasText: kpiTitle });
    await expect(row).toContainText('≥ 99,5 %');
    await expect(row).toContainText('Månedlig');
    await expect(row).toContainText('E2E User (målets ansvarlig)');
    await expect(row).toContainText('Ikke målt');
    await page.screenshot({ path: 'test-results/kpis-03-objective.png', fullPage: true });
    await row.getByRole('link', { name: kpiTitle }).click();
    await page.waitForURL(kpiUrl);

    // Rediger: a count with a label and an upper bound, own owner.
    await page.getByRole('button', { name: 'Rediger' }).click();
    await page.locator('#kpi-title').fill(editedKpiTitle);
    // Editing starts with the stored unit inside the fields, and follows a change of unit at once.
    await expect(page.getByTestId('kpi-target-min-suffix')).toHaveText('%');
    await expect(page.locator('#kpi-target-min')).toHaveValue(/^99[.,]5$/);
    await page.locator('#kpi-unit').selectOption({ label: 'Antall' });
    await expect(page.getByTestId('kpi-target-max-suffix')).toHaveText('stk.');
    await page.locator('#kpi-unit-label').fill('hendelser');
    await expect(page.getByTestId('kpi-target-max-suffix')).toHaveText('hendelser');
    await page.locator('#kpi-target-min').fill('');
    await page.locator('#kpi-target-max').fill('3');
    await page.locator('#kpi-tolerance').fill('');
    await page.locator('#kpi-owner').selectOption({ label: 'E2E User' });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('KPI-en er oppdatert.')).toBeVisible();
    await expect(page.getByRole('heading', { name: editedKpiTitle, level: 1 })).toBeVisible();
    await expect(result).toContainText('≤ 3 hendelser');
    await expect(details).toContainText('Antall (hendelser)');
    await expect(result).not.toContainText('målets ansvarlig');

    // Avslutt: the decision goes into the history; a retired KPI is reopened, not edited.
    const header = page.locator('header').filter({ has: page.getByRole('heading', { level: 1 }) });
    await page.getByRole('button', { name: 'Avslutt KPI' }).click();
    await page.locator('#kpi-retire-note').fill('Erstattes av en ny måling.');
    await page.getByRole('button', { name: 'Avslutt KPI', exact: true }).last().click();
    await expect(page.getByText('KPI-en er avsluttet.')).toBeVisible();
    await expect(header).toContainText('Avsluttet');
    const history = page.getByTestId('kpi-history');
    await expect(history.locator('li')).toHaveCount(1);
    await expect(history.locator('li').first()).toContainText('Avsluttet av E2E User');
    await expect(history.locator('li').first()).toContainText('Erstattes av en ny måling.');
    await expect(page.getByRole('button', { name: 'Rediger' })).toHaveCount(0);
    await page.screenshot({ path: 'test-results/kpis-04-retired.png', fullPage: true });

    // Gjenåpne: the reason is required.
    await page.getByRole('button', { name: 'Gjenåpne' }).click();
    await page.locator('#kpi-reopen-reason').evaluate((el) => el.removeAttribute('required'));
    await page.getByRole('button', { name: 'Gjenåpne KPI' }).click();
    await expect(page.getByText('Begrunnelse må fylles ut.')).toBeVisible();
    await page.locator('#kpi-reopen-reason').fill('Målingen trengs likevel.');
    await page.getByRole('button', { name: 'Gjenåpne KPI' }).click();
    await expect(page.getByText('KPI-en er gjenåpnet.')).toBeVisible();
    await expect(header).toContainText('Aktiv');
    await expect(history.locator('li')).toHaveCount(2);
    await expect(history.locator('li').nth(0)).toContainText('Gjenåpnet av E2E User');
    await expect(history.locator('li').nth(1)).toContainText('Avsluttet av E2E User');
    await page.screenshot({ path: 'test-results/kpis-05-reopened.png', fullPage: true });

    // Slett KPI: back on the objective, with no KPI left.
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Slett KPI' }).click();
    await page.waitForURL(objectiveUrl);
    await expect(page.getByText('KPI-en er slettet.')).toBeVisible();
    await expect(kpiSection).toContainText('Ingen KPI-er er registrert for dette målet ennå.');
    expect((await page.goto(kpiUrl)).status()).toBe(404);

    // Slett mål.
    await page.goto(objectiveUrl);
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Slett mål' }).click();
    await page.waitForURL(/\/app\/objectives$/);
    await expect(page.getByText('Målet er slettet.')).toBeVisible();
});

/**
 * The unit inside the number fields: it follows the unit as it is chosen, is never part of the
 * value (what is typed is what is saved), is part of the field's accessible description, takes a
 * keyboard user straight through and never overlaps the number on a phone.
 */
test('the number fields show the chosen unit inside them, outside the value', async ({ page }) => {
    test.setTimeout(120_000);

    const { area_name: areaName } = await seedObjectiveEditor(suffix);
    const objectiveTitle = objectiveE2eName(suffix, 'Lønnsom vekst');
    const kpiTitle = objectiveE2eName(suffix, 'Fri kontantstrøm');

    await page.setViewportSize({ width: 390, height: 844 });
    await loginAs(page, USER.email, USER.password);

    await page.goto('/app/objectives');
    await page.getByRole('button', { name: 'Nytt mål' }).click();
    await page.locator('#objective-title').fill(objectiveTitle);
    await page.locator('#objective-area').selectOption({ label: areaName });
    await page.locator('#objective-owner').selectOption({ label: 'E2E User' });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/objectives\/\d+$/);

    const kpiSection = page.locator('section', { has: page.getByRole('heading', { name: 'KPI-er', exact: true }) });
    await kpiSection.getByRole('button', { name: 'Ny KPI' }).click();
    await page.locator('#kpi-title').fill(kpiTitle);

    const min = page.locator('#kpi-target-min');
    const minSuffix = page.getByTestId('kpi-target-min-suffix');

    // No unit chosen: no suffix.
    await expect(minSuffix).toHaveCount(0);

    // Each unit, at once.
    const unit = page.locator('#kpi-unit');
    await unit.selectOption({ label: 'Prosent' });
    await expect(minSuffix).toHaveText('%');
    await unit.selectOption({ label: 'Timer' });
    await expect(minSuffix).toHaveText('timer');
    await min.fill('1');
    await expect(minSuffix).toHaveText('time');
    await unit.selectOption({ label: 'Dager' });
    await expect(minSuffix).toHaveText('dag');
    await unit.selectOption({ label: 'Antall' });
    await expect(minSuffix).toHaveText('stk.');
    await unit.selectOption({ label: 'Tall' });
    await expect(minSuffix).toHaveCount(0);
    await unit.selectOption({ label: 'Valuta' });
    await expect(page.locator('#kpi-currency-code')).toHaveValue('NOK');
    await expect(minSuffix).toHaveText('kr');
    await page.locator('#kpi-currency-code').fill('EUR');
    await expect(minSuffix).toHaveText('EUR');
    await page.locator('#kpi-currency-code').fill('NOK');
    await expect(minSuffix).toHaveText('kr');

    // A keyboard user tabs into the field, types, selects and replaces; the unit is never in the value.
    await page.locator('#kpi-currency-code').focus();
    await page.keyboard.press('Tab');
    await expect(min).toBeFocused();
    await page.keyboard.press('ControlOrMeta+a');
    await page.keyboard.type('1 300 000');
    await expect(min).toHaveValue('1 300 000');
    await expect(min).toHaveAccessibleDescription(/kr/);
    await expect(page.getByTestId('kpi-tolerance-suffix')).toHaveText('kr');

    // A click on the unit puts the cursor in the field.
    await page.getByTestId('kpi-tolerance-suffix').click();
    await expect(page.locator('#kpi-tolerance')).toBeFocused();

    // On a phone the number and the unit sit side by side, never on top of each other, and the
    // page does not scroll sideways.
    const inputBox = await min.boundingBox();
    const suffixBox = await minSuffix.boundingBox();
    expect(inputBox.x + inputBox.width).toBeLessThanOrEqual(suffixBox.x);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
    await page.screenshot({ path: 'test-results/kpis-06-unit-suffix-phone.png', fullPage: true });

    // What was typed is what is saved: the number alone, read back in the KPI's unit.
    await kpiSection.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/objectives\/\d+\/kpis\/\d+$/);
    await expect(page.getByTestId('kpi-result')).toContainText('≥ NOK 1 300 000');

    // Editing shows the stored number with the unit beside it.
    await page.getByRole('button', { name: 'Rediger' }).click();
    await expect(page.getByTestId('kpi-target-min-suffix')).toHaveText('kr');
    await expect(page.locator('#kpi-target-min')).toHaveValue('1300000');
});
