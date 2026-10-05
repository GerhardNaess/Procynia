import { expect, test } from '@playwright/test';
import { USER, loginAs } from './helpers/auth.js';
import { cleanUpObjectiveE2eData, objectiveE2eName, objectiveE2eSuffix } from './helpers/objectives.js';
import { DESKTOP, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';
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

/** Checks the page at desktop and phone width; screenshots named objective-readability-<name>-<width>. */
const expectReadable = (page, name) => expectReadableAt(page, 'objective-readability', name);

async function fixture(call) {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\ObjectiveE2EFixture::${call});`);
    const match = stdout.match(/\{.*\}|null/);

    if (! match) {
        throw new Error(`Fixture call failed: ${stdout}`);
    }

    return JSON.parse(match[0]);
}

/**
 * Mål og KPI as a user reads it: one objective with a KPI that is measured, corrected, withdrawn,
 * linked to Kvalitet and behind on its measurements, and the objective closed and reopened. Every
 * page and panel on the way — the overview with Trenger oppmerksomhet, the objective, the KPI, each
 * form and the history — is checked for text under 16 px and sideways scrolling at desktop and
 * phone width, and each page's help is opened. The behaviour itself is owned by the other specs.
 */
test('every page and panel in Mål og KPI is readable at 16 px and has help', async ({ page }) => {
    test.setTimeout(240_000);

    const { area_name: areaName } = await fixture(`seedJourney('${suffix}')`);
    const objectiveTitle = objectiveE2eName(suffix, 'Stabil drift av kundesystemene');
    const kpiTitle = objectiveE2eName(suffix, 'Oppetid');

    await loginAs(page, USER.email, USER.password);
    await page.setViewportSize(DESKTOP);

    // Overview and Nytt mål.
    await page.goto('/app/objectives');
    await expectPageHelp(page, 'Om Mål og KPI', ['Mål', 'Trenger oppmerksomhet']);
    await page.getByRole('button', { name: 'Nytt mål' }).click();
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.locator('#objective-title')).toBeVisible();
    await page.locator('#objective-title').fill(objectiveTitle);
    await page.locator('#objective-area').selectOption({ label: areaName });
    await page.locator('#objective-owner').selectOption({ label: 'E2E User' });
    await page.locator('#objective-target-date').fill('2026-01-31');
    await expectReadable(page, '01-new-objective');
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/objectives\/\d+$/);
    const objectiveUrl = page.url();

    // Ny KPI: an interval-free minimum with a tolerance, monthly, five days to report.
    const kpiSection = page.locator('section', { has: page.getByRole('heading', { name: 'KPI-er', exact: true }) });
    await kpiSection.getByRole('button', { name: 'Ny KPI' }).click();
    await page.locator('#kpi-title').fill(kpiTitle);
    await page.locator('#kpi-unit').selectOption({ label: 'Prosent' });
    await page.locator('#kpi-target-min').fill('98');
    await page.locator('#kpi-tolerance').fill('1');
    await page.locator('#kpi-frequency').selectOption({ label: 'Månedlig' });
    await page.locator('#kpi-grace-days').fill('5');
    await expectReadable(page, '02-new-kpi');
    await kpiSection.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/objectives\/\d+\/kpis\/\d+$/);
    const kpiUrl = page.url();

    await expect(page.getByTestId('kpi-result')).toContainText('Målefrekvens');
    await expect(page.getByTestId('kpi-result')).toContainText('Dagens status');

    // Prosess og aktivitet, when Kvalitet has a process to link to.
    const context = page.getByTestId('kpi-context');
    await context.getByRole('button', { name: 'Endre kobling' }).click();
    const processSelect = page.locator('#kpi-context-process');

    if (await processSelect.count() > 0) {
        const firstProcess = await processSelect.locator('option').evaluateAll((options) => options.map((o) => o.value).find(Boolean));
        await processSelect.selectOption(firstProcess);
        await expectReadable(page, '03-link-process');
        await context.getByRole('checkbox', { name: 'Hele prosessen', exact: true }).check();
        await context.getByRole('button', { name: 'Lagre kobling' }).click();
        await expect(page.getByText('Koblingen er oppdatert.')).toBeVisible();
    } else {
        await context.getByRole('button', { name: 'Avbryt' }).click();
    }

    // Registrer måling: inside the tolerance, so it needs attention.
    await page.getByRole('button', { name: 'Registrer måling' }).click();
    await page.locator('#measurement-value').fill('97,5');
    await expectReadable(page, '04-register');
    await page.getByRole('button', { name: 'Registrer måling', exact: true }).last().click();
    await expect(page.getByTestId('kpi-current-result')).toHaveText('Trenger oppmerksomhet');

    // Korrigering for the same period: the notice and the required comment.
    await page.getByRole('button', { name: 'Registrer måling' }).click();
    await page.locator('#measurement-value').fill('96');
    await expect(page.getByTestId('measurement-correction')).toBeVisible();
    await page.locator('#measurement-comment').fill('Feil kilde i første rapport.');
    await expectReadable(page, '05-correction');
    await page.getByRole('button', { name: 'Registrer måling', exact: true }).last().click();
    await expect(page.getByTestId('kpi-current-result')).toHaveText('Utenfor mål');

    // Erstattet, then Trekk tilbake the correction: the first value counts again.
    const history = page.getByTestId('kpi-measurements');
    const rows = history.locator('tbody tr');
    await expect(rows.nth(1)).toContainText('Erstattet');
    await rows.nth(0).getByRole('button', { name: 'Trekk tilbake' }).click();
    const withdrawForm = history.locator('form');
    await withdrawForm.locator('textarea').fill('Første rapport var riktig likevel.');
    await expectReadable(page, '06-withdraw');
    await withdrawForm.getByRole('button', { name: 'Trekk tilbake' }).click();
    await expect(page.getByTestId('kpi-current-result')).toHaveText('Trenger oppmerksomhet');
    await expect(rows.nth(0)).toContainText('Tilbaketrukket');
    await expect(history.locator('thead')).toContainText('Målverdi da');

    // Avslutt and Gjenåpne KPI, so the KPI has a history of its own.
    await page.getByRole('button', { name: 'Avslutt KPI' }).click();
    await expectReadable(page, '07-retire');
    await page.getByRole('button', { name: 'Avslutt KPI' }).last().click();
    await expect(page.getByText('KPI-en er avsluttet.')).toBeVisible();
    await page.getByRole('button', { name: 'Gjenåpne' }).click();
    await page.locator('#kpi-reopen-reason').fill('Skal måles videre.');
    await page.getByRole('button', { name: 'Gjenåpne KPI' }).click();
    await expect(page.getByTestId('kpi-history')).toContainText('Gjenåpnet av E2E User');

    // Older periods behind their deadline: Måling mangler on the KPI.
    await fixture(`backdate('${suffix}', '${objectiveTitle}', 3)`);
    await page.goto(kpiUrl);
    await expect(page.getByTestId('kpi-measurement-missing')).toBeVisible();
    await expectReadable(page, '08-kpi');
    await expectPageHelp(page, 'Om KPI-en', ['KPI-en', 'Målinger', 'Dagens status og Status da', 'Prosess og aktivitet']);

    // The objective: its note, the KPI table, Lukk mål, the history and Gjenåpne.
    await page.goto(objectiveUrl);
    await expect(page.getByTestId('objective-attention-note')).toBeVisible();
    await expect(page.getByTestId('objective-kpis').locator('thead')).toContainText('Dagens status');
    await expectPageHelp(page, 'Om målet', ['Målet', 'KPI-er', 'Lukke og gjenåpne']);
    await page.getByRole('button', { name: 'Lukk mål' }).click();
    await page.getByRole('radio', { name: 'Oppnådd', exact: true }).check();
    await expectReadable(page, '09-close');
    await page.getByRole('button', { name: 'Lukk mål' }).last().click();
    await expect(page.getByTestId('objective-history')).toContainText('Lukket som Oppnådd av E2E User');
    await page.getByRole('button', { name: 'Gjenåpne' }).click();
    await page.locator('#objective-reopen-reason').fill('Målet gjelder ett år til.');
    await page.getByRole('button', { name: 'Gjenåpne mål' }).click();
    await expect(page.getByTestId('objective-history')).toContainText('Gjenåpnet av E2E User');
    await expectReadable(page, '10-objective');

    // Trenger oppmerksomhet with every category this objective has open.
    await page.goto('/app/objectives');
    const attention = page.getByTestId('objective-attention');

    for (const key of ['kpi_off_target', 'measurement_missing', 'target_date_passed']) {
        const category = page.getByTestId(`objective-attention-category-${key}`);
        await category.getByRole('button', { name: 'Vis' }).click();
        await expect(category.getByTestId('objective-attention-item').filter({ hasText: key === 'target_date_passed' ? objectiveTitle : kpiTitle })).toBeVisible();
    }

    await expect(attention).toContainText('Måldato 31. januar 2026 er passert.');
    await expect(attention).toContainText(/Eldste er [a-zæøå]+ \d{4}\./);
    await expectReadable(page, '11-attention');
});
