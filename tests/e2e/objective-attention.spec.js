import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { cleanUpObjectiveE2eData, objectiveE2eName, objectiveE2eSuffix } from './helpers/objectives.js';
import { tinker } from './helpers/risk.js';

const suffix = objectiveE2eSuffix();
const password = 'E2eUser123!';
cleanUpObjectiveE2eData(suffix);

// After the per-test cleanup: nothing this run created may be left — the seeded users included.
test.afterAll(async () => {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\ObjectiveE2EFixture::remaining('${suffix}'));`);
    const remaining = JSON.parse(stdout.match(/\{.*\}/)[0]);

    expect(remaining).toEqual({
        areas: 0, roles: 0, users: 0, objectives: 0, kpis: 0, kpi_processes: 0, kpi_activities: 0, kpi_measurements: 0, kpi_status_changes: 0, objective_status_changes: 0,
    });
});

async function fixture(call) {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\ObjectiveE2EFixture::${call});`);
    const match = stdout.match(/\{.*\}|null/);

    if (! match) {
        throw new Error(`Fixture call failed: ${stdout}`);
    }

    return JSON.parse(match[0]);
}

async function createObjective(page, title, areaName, ownerName) {
    await page.goto('/app/objectives');
    await page.getByRole('button', { name: 'Nytt mål' }).click();
    await page.locator('#objective-title').fill(title);
    await page.locator('#objective-area').selectOption({ label: areaName });
    await page.locator('#objective-owner').selectOption({ label: ownerName });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/objectives\/\d+$/);

    return page.url();
}

async function createKpi(page, title, targetMin) {
    const kpiSection = page.locator('section', { has: page.getByRole('heading', { name: 'KPI-er', exact: true }) });
    await kpiSection.getByRole('button', { name: 'Ny KPI' }).click();
    await page.locator('#kpi-title').fill(title);
    await page.locator('#kpi-unit').selectOption({ label: 'Prosent' });
    await page.locator('#kpi-target-min').fill(targetMin);
    await page.locator('#kpi-frequency').selectOption({ label: 'Månedlig' });
    await kpiSection.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/objectives\/\d+\/kpis\/\d+$/);

    return page.url();
}

/** Opens a category on the overview and returns its list items. */
async function openCategory(page, key) {
    const category = page.getByTestId(`objective-attention-category-${key}`);
    await category.getByRole('button', { name: 'Vis' }).click();

    return category.getByTestId('objective-attention-item');
}

function isoDay(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/**
 * One attention journey by a fresh planner who works in one fagområde: an off-target KPI, a KPI
 * missing old measurements, a passed target date and an objective without owner, each read off the
 * «Trenger oppmerksomhet» panel and its lists. Then a reader of another fagområde — who holds edit,
 * but not view, in the planner's area through a second role — sees none of it.
 */
test('the attention panel shows what needs action, and only in the user\'s own fagområder', async ({ page }) => {
    test.setTimeout(180_000);

    const seeded = await fixture(`seedAttention('${suffix}', '${password}')`);
    const objectiveTitle = objectiveE2eName(suffix, 'Sikker drift');
    const backupTitle = objectiveE2eName(suffix, 'Vellykket backup');
    const experienceTitle = objectiveE2eName(suffix, 'Kundeopplevelse');
    const satisfactionTitle = objectiveE2eName(suffix, 'Kundetilfredshet');

    await loginAs(page, seeded.planner_email, password);

    // Nothing yet.
    await page.goto('/app/objectives');
    await expect(page.getByTestId('objective-attention')).toContainText('Mål og KPI-er i dine fagområder');
    await expect(page.getByTestId('objective-attention-empty')).toBeVisible();

    // Opprett mål og KPI, registrer en verdi på mål.
    const objectiveUrl = await createObjective(page, objectiveTitle, seeded.area_name, seeded.planner_name);
    const backupUrl = await createKpi(page, backupTitle, '98');
    await page.getByRole('button', { name: 'Registrer måling' }).click();
    await page.locator('#measurement-value').fill('98,7');
    await page.getByRole('button', { name: 'Registrer måling', exact: true }).last().click();
    await expect(page.getByText('Målingen er registrert.')).toBeVisible();
    await expect(page.getByTestId('kpi-current-result')).toHaveText('På mål');

    await page.goto('/app/objectives');
    await expect(page.getByTestId('objective-attention-empty')).toBeVisible();

    // Stram inn målet: the same value is now below it.
    await page.goto(backupUrl);
    await page.getByRole('button', { name: 'Rediger' }).click();
    await page.locator('#kpi-target-min').fill('99,5');
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('KPI-en er oppdatert.')).toBeVisible();
    await expect(page.getByTestId('kpi-current-result')).toHaveText('Utenfor mål');

    await page.goto('/app/objectives');
    await expect(page.getByTestId('objective-attention-summary')).toHaveText('1 KPI trenger oppmerksomhet');
    const offTarget = page.getByTestId('objective-attention-category-kpi_off_target');
    await expect(offTarget).toContainText('KPI ikke på mål');
    await expect(offTarget.getByTestId('objective-attention-count')).toHaveText('1');
    const offTargetItems = await openCategory(page, 'kpi_off_target');
    await expect(offTargetItems).toHaveCount(1);
    await expect(offTargetItems.first()).toContainText(backupTitle);
    await expect(offTargetItems.first()).toContainText(objectiveTitle);
    await expect(offTargetItems.first()).toContainText('Siste verdi 98,7 % er under målet ≥ 99,5 %.');
    await page.screenshot({ path: 'test-results/objective-attention-01-off-target.png', fullPage: true });

    // A KPI on its own objective whose old periods are expected and unmeasured. Creation is moved
    // back four months and the grace days to zero — the fixture's only shortcut in this journey.
    await createObjective(page, experienceTitle, seeded.area_name, seeded.planner_name);
    await createKpi(page, satisfactionTitle, '80');
    await fixture(`backdate('${suffix}', '${experienceTitle}', 4) ?? null`);

    await page.goto('/app/objectives');
    await expect(page.getByTestId('objective-attention-summary')).toHaveText('2 KPI-er trenger oppmerksomhet');
    await expect(page.getByTestId('objective-attention-category-measurement_missing').getByTestId('objective-attention-count')).toHaveText('1');
    const missingItems = await openCategory(page, 'measurement_missing');
    await expect(missingItems).toHaveCount(1);
    await expect(missingItems.first()).toContainText(satisfactionTitle);
    await expect(missingItems.first()).toContainText(/4 måleperioder mangler\. Eldste er [A-ZÆØÅ][a-zæøå]+ \d{4}\./);

    // Måldato i går.
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    await page.goto(objectiveUrl);
    await page.getByRole('button', { name: 'Rediger' }).click();
    await page.locator('#objective-target-date').fill(isoDay(yesterday));
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Målet er oppdatert.')).toBeVisible();

    await page.goto('/app/objectives');
    await expect(page.getByTestId('objective-attention-summary')).toHaveText('1 mål og 2 KPI-er trenger oppmerksomhet');
    const dateItems = await openCategory(page, 'target_date_passed');
    await expect(dateItems).toHaveCount(1);
    await expect(dateItems.first()).toContainText(objectiveTitle);
    await expect(dateItems.first()).toContainText(/Måldato \d{1,2}\. [a-zæøå]+ \d{4} er passert\./);

    // Målansvarlig fjernet, as when the owner's user is deleted.
    await fixture(`removeOwner('${suffix}', '${objectiveTitle}') ?? null`);

    await page.goto('/app/objectives');
    // Still one objective: two findings on it count once.
    await expect(page.getByTestId('objective-attention-summary')).toHaveText('1 mål og 2 KPI-er trenger oppmerksomhet');
    await expect(page.getByTestId('objective-attention-count')).toHaveText(['1', '1', '1', '1']);
    const ownerItems = await openCategory(page, 'owner_missing');
    await expect(ownerItems).toHaveCount(1);
    await expect(ownerItems.first()).toContainText(objectiveTitle);
    await expect(ownerItems.first()).toContainText('Målet har ingen ansvarlig.');
    await page.screenshot({ path: 'test-results/objective-attention-02-all-categories.png', fullPage: true });

    // The objective page: a short note, not the panel again.
    await page.goto(objectiveUrl);
    const note = page.getByTestId('objective-attention-note');
    await expect(note).toContainText('er passert.');
    await expect(note).toContainText('Målet har ingen ansvarlig.');
    await expect(note).toContainText('1 KPI trenger oppmerksomhet');
    await page.screenshot({ path: 'test-results/objective-attention-03-objective-note.png', fullPage: true });

    // A reader of another fagområde: nothing of the planner's area moves a count or appears.
    await page.context().clearCookies();
    await loginAs(page, seeded.reader_email, password);
    await page.goto('/app/objectives');
    await expect(page.locator('tbody tr', { hasText: seeded.reader_objective })).toHaveCount(1);
    await expect(page.locator('tbody tr', { hasText: objectiveTitle })).toHaveCount(0);
    await expect(page.getByTestId('objective-attention-empty')).toBeVisible();
    await expect(page.getByTestId('objective-attention')).not.toContainText(objectiveE2eName(suffix, ''));
    await expect(page.getByTestId('objective-attention-category-kpi_off_target')).toHaveCount(0);
    await page.screenshot({ path: 'test-results/objective-attention-04-other-area.png', fullPage: true });
});
