import { expect, test } from '@playwright/test';
import { USER, loginAs } from './helpers/auth.js';
import { cleanUpObjectiveE2eData, objectiveE2eName, objectiveE2eSuffix, seedObjectiveContext } from './helpers/objectives.js';
import { tinker } from './helpers/risk.js';

const suffix = objectiveE2eSuffix();
const READER_PASSWORD = 'E2eUser123!';
cleanUpObjectiveE2eData(suffix);

// After the per-test cleanup: nothing this run created may be left — links, KPIs, objectives, the
// seeded reader, areas and roles. Kvalitet's processes are only read by this spec.
test.afterAll(async () => {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\ObjectiveE2EFixture::remaining('${suffix}'));`);
    const remaining = JSON.parse(stdout.match(/\{.*\}/)[0]);

    expect(remaining).toEqual({
        areas: 0, roles: 0, users: 0, objectives: 0, kpis: 0, kpi_processes: 0, kpi_activities: 0, kpi_measurements: 0, kpi_status_changes: 0, objective_status_changes: 0,
    });
});

/**
 * KPI → Kvalitet-prosess og -aktivitet, end to end: create an objective and a KPI, link a whole
 * process, then two of its activities, read the context on the KPI and as «Berørte prosesser» on the
 * objective, remove an activity and link it again. Then a person who can read the KPI but not
 * Kvalitet opens the same pages and learns nothing about the context. Permissions, tenancy and
 * cleanup on flow changes are owned by KpiQualityContextLinkTest.
 */
test('a KPI is linked to a process and activities, shown, unlinked and relinked; hidden without Kvalitet', async ({ page }) => {
    test.setTimeout(120_000);

    const { area_name: areaName, reader_email: readerEmail } = await seedObjectiveContext(suffix, READER_PASSWORD);
    const objectiveTitle = objectiveE2eName(suffix, 'Stabil drift');
    const kpiTitle = objectiveE2eName(suffix, 'Løsningstid');

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
    // No KPI measures anything yet: nothing about processes on the objective.
    await expect(page.getByTestId('objective-affected-processes')).toHaveCount(0);

    // Opprett KPI.
    const kpiSection = page.locator('section', { has: page.getByRole('heading', { name: 'KPI-er', exact: true }) });
    await kpiSection.getByRole('button', { name: 'Ny KPI' }).click();
    await page.locator('#kpi-title').fill(kpiTitle);
    await page.locator('#kpi-unit').selectOption({ label: 'Prosent' });
    await page.locator('#kpi-target-min').fill('95');
    await page.locator('#kpi-frequency').selectOption({ label: 'Månedlig' });
    await kpiSection.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/objectives\/\d+\/kpis\/\d+$/);
    const kpiUrl = page.url();

    const panel = page.getByTestId('kpi-context');
    await expect(panel.getByRole('heading', { name: 'Prosess og aktivitet' })).toBeVisible();
    await expect(panel).toContainText('KPI-en er ikke koblet til noen prosess eller aktivitet.');

    // Koble prosess: a process whose flow has at least two activities, chosen from what Kvalitet has.
    await panel.getByRole('button', { name: 'Endre kobling' }).click();
    const processSelect = page.locator('#kpi-context-process');

    if (await processSelect.count() === 0) {
        test.skip(true, 'No process in Kvalitet in this environment.');
    }

    const activityBoxes = panel.locator('fieldset input[type="checkbox"]');
    const processValues = await processSelect.locator('option').evaluateAll((options) => options.map((o) => o.value).filter(Boolean));
    let processId = null;

    for (const value of processValues) {
        await processSelect.selectOption(value);
        // «Hele prosessen» plus at least two activities.
        if (await activityBoxes.count() >= 3) {
            processId = value;
            break;
        }
    }

    if (processId === null) {
        test.skip(true, 'No process with two activities in Kvalitet in this environment.');
    }

    const processLabel = (await processSelect.locator(`option[value="${processId}"]`).textContent()).trim();
    const activityLabels = (await panel.locator('fieldset label').allTextContents()).map((text) => text.trim()).slice(1, 3);
    const [firstActivity, secondActivity] = activityLabels;

    await panel.getByRole('checkbox', { name: 'Hele prosessen', exact: true }).check();
    await panel.getByRole('button', { name: 'Lagre kobling' }).click();
    await expect(page.getByText('Koblingen er oppdatert.')).toBeVisible();
    const processLink = panel.getByRole('link', { name: processLabel, exact: true });
    await expect(processLink).toHaveAttribute('href', new RegExp(`/app/quality/items/${processId}$`));
    await expect(panel.getByText('Hele prosessen', { exact: true })).toBeVisible();

    // Koble aktivitet: two activities in the same process, which opens preselected.
    await panel.getByRole('button', { name: 'Endre kobling' }).click();
    await expect(processSelect).toHaveValue(processId);
    await expect(panel.getByRole('checkbox', { name: 'Hele prosessen', exact: true })).toBeChecked();
    await panel.getByRole('checkbox', { name: firstActivity, exact: true }).check();
    await panel.getByRole('checkbox', { name: secondActivity, exact: true }).check();
    await panel.getByRole('button', { name: 'Lagre kobling' }).click();
    await expect(page.getByText('Koblingen er oppdatert.')).toBeVisible();

    // Kontroller visning: the process, and the activities under it, linking into the flow.
    const activities = panel.getByRole('list', { name: 'Aktiviteter' });
    await expect(activities.getByRole('link')).toHaveText([firstActivity, secondActivity]);
    await expect(activities.getByRole('link', { name: firstActivity, exact: true })).toHaveAttribute('href', new RegExp(`/app/quality/items/${processId}\\?tab=flow&activity=`));
    await page.screenshot({ path: 'test-results/kpi-context-01-linked.png', fullPage: true });

    // Berørte prosesser on the objective, derived from the KPI.
    await page.goto(objectiveUrl);
    const affected = page.getByTestId('objective-affected-processes');
    await expect(affected.getByRole('link')).toHaveText([processLabel]);
    await page.screenshot({ path: 'test-results/kpi-context-02-objective.png', fullPage: true });

    // Fjern aktivitet.
    await page.goto(kpiUrl);
    await panel.getByRole('button', { name: 'Endre kobling' }).click();
    await panel.getByRole('checkbox', { name: firstActivity, exact: true }).uncheck();
    await panel.getByRole('button', { name: 'Lagre kobling' }).click();
    await expect(page.getByText('Koblingen er oppdatert.')).toBeVisible();
    await expect(activities.getByRole('link')).toHaveText([secondActivity]);

    // Koble på nytt.
    await panel.getByRole('button', { name: 'Endre kobling' }).click();
    await panel.getByRole('checkbox', { name: firstActivity, exact: true }).check();
    await panel.getByRole('button', { name: 'Lagre kobling' }).click();
    await expect(page.getByText('Koblingen er oppdatert.')).toBeVisible();
    await expect(activities.getByRole('link')).toHaveCount(2);
    await expect(activities.getByRole('link', { name: firstActivity, exact: true })).toBeVisible();

    // Kvalitet says nothing about the KPI.
    await page.goto(`/app/quality/items/${processId}`);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByText(kpiTitle)).toHaveCount(0);
    await expect(page.getByText(objectiveTitle)).toHaveCount(0);

    // A reader of the KPI without Kvalitet: no panel, no names, no hint that links exist.
    await page.context().clearCookies();
    await loginAs(page, readerEmail, READER_PASSWORD);

    await page.goto(kpiUrl);
    await expect(page.getByRole('heading', { name: kpiTitle, level: 1 })).toBeVisible();
    await expect(page.getByTestId('kpi-context')).toHaveCount(0);
    await expect(page.getByText('Prosess og aktivitet')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Endre kobling' })).toHaveCount(0);
    for (const text of [processLabel, firstActivity, secondActivity]) {
        await expect(page.getByText(text, { exact: false })).toHaveCount(0);
    }
    expect(await page.content()).not.toContain(`/app/quality/items/${processId}`);
    await page.screenshot({ path: 'test-results/kpi-context-03-reader.png', fullPage: true });

    await page.goto(objectiveUrl);
    await expect(page.getByRole('heading', { name: objectiveTitle, level: 1 })).toBeVisible();
    await expect(page.getByTestId('objective-affected-processes')).toHaveCount(0);
    await expect(page.getByText(processLabel, { exact: false })).toHaveCount(0);
    expect(await page.content()).not.toContain(`/app/quality/items/${processId}`);
});
