import { expect, test } from '@playwright/test';
import { USER, loginAs } from './helpers/auth.js';
import {
    cleanUpObjectiveE2eData,
    objectiveE2eName,
    objectiveE2eSuffix,
    seedObjectiveEnglishEditor,
    seedObjectiveMeasurer,
} from './helpers/objectives.js';
import { tinker } from './helpers/risk.js';

const suffix = objectiveE2eSuffix();
const password = `E2e-${suffix}-English1`;
cleanUpObjectiveE2eData(suffix);

test.afterAll(async () => {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\ObjectiveE2EFixture::remaining('${suffix}'));`);
    const remaining = JSON.parse(stdout.match(/\{.*\}/)[0]);

    expect(remaining).toEqual({
        areas: 0, roles: 0, users: 0, objectives: 0, kpis: 0, kpi_processes: 0, kpi_activities: 0, kpi_measurements: 0, kpi_status_changes: 0, objective_status_changes: 0,
    });
});

/** What the database holds for a KPI's målverdi and startverdi. */
async function stored(title) {
    const { stdout } = await tinker(`echo json_encode(\\App\\Models\\Kpi::where('title', '${title}')->firstOrFail()->only(['target_min', 'target_max', 'baseline_value']));`);

    return JSON.parse(stdout.match(/\{.*\}/)[0]);
}

/** The page never scrolls sideways at phone width, and its ordinary text is at least 16 px. */
async function expectPhoneReadable(page, testIds) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);

    for (const id of testIds) {
        const size = await page.getByTestId(id).evaluate((element) => parseFloat(getComputedStyle(element).fontSize));
        expect(size, id).toBeGreaterThanOrEqual(16);
    }
}

/**
 * A vekst-KPI from start to status, without anyone seeing a min or a max: «30 % økning av FCV fra
 * dagens nivå» under an objective with a måldato. Registered, corrected through «Korriger», given
 * an older month, followed in «Utvikling over tid» and edited to a higher change.
 */
test('a growth KPI is defined from a startverdi, measured, corrected and judged', async ({ page }) => {
    test.setTimeout(180_000);

    const { area_name: areaName } = await seedObjectiveMeasurer(suffix);
    const objectiveTitle = objectiveE2eName(suffix, 'Lønnsom vekst');
    const kpiTitle = objectiveE2eName(suffix, 'FCV');

    await loginAs(page, USER.email, USER.password);

    // Opprett et virksomhetsmål med måldato.
    await page.goto('/app/objectives');
    await page.getByRole('button', { name: 'Nytt mål' }).click();
    await page.locator('#objective-title').fill(objectiveTitle);
    await page.locator('#objective-area').selectOption({ label: areaName });
    await page.locator('#objective-owner').selectOption({ label: 'E2E User' });
    await page.locator('#objective-target-date').fill('2027-09-30');
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/objectives\/\d+$/);

    // Opprett KPI: valuta, endring fra startverdi.
    const kpiSection = page.locator('section', { has: page.getByRole('heading', { name: 'KPI-er', exact: true }) });
    await kpiSection.getByRole('button', { name: 'Ny KPI' }).click();
    await page.locator('#kpi-title').fill(kpiTitle);
    await page.locator('#kpi-unit').selectOption({ label: 'Valuta' });
    await page.getByRole('radio', { name: 'Endring fra startverdi' }).check();
    // Only the growth fields: no «Minst», «Høyst», «Fra» or «Til».
    await expect(page.locator('#kpi-target-min')).toHaveCount(0);
    await expect(page.locator('#kpi-target-max')).toHaveCount(0);
    await page.locator('#kpi-baseline').fill('1 000 000');
    await page.locator('#kpi-baseline-date').fill('2026-10-01');
    await page.locator('#kpi-change').fill('30');
    await expect(page.getByTestId('kpi-computed-target')).toHaveText('NOK 1 300 000');
    await expect(page.getByTestId('kpi-rule-preview')).toContainText(
        'Målet er nådd når verdien er minst NOK 1 300 000. Det er en økning på 30 % fra startverdien NOK 1 000 000 (1. oktober 2026).',
    );
    // The KPI follows the objective's måldato; there is no deadline field of its own.
    await expect(page.getByTestId('kpi-form-deadline')).toHaveText('30. september 2027 (målets måldato)');
    await page.locator('#kpi-frequency').selectOption({ label: 'Månedlig' });
    await page.screenshot({ path: 'test-results/kpi-target-types-01-growth-form.png', fullPage: true });
    await kpiSection.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/objectives\/\d+\/kpis\/\d+$/);

    expect(await stored(kpiTitle)).toEqual({ target_min: '1300000.0000', target_max: null, baseline_value: '1000000.0000' });
    await expect(page.getByTestId('kpi-target-rule')).toHaveText(
        'Målet er nådd når verdien er minst NOK 1 300 000. Det er en økning på 30 % fra startverdien NOK 1 000 000 (1. oktober 2026).',
    );
    await expect(page.getByTestId('kpi-deadline')).toHaveText('30. september 2027 (målets måldato)');
    await expect(page.getByTestId('kpi-current-result')).toHaveText('Ikke målt');
    await expect(page.getByTestId('kpi-details')).toContainText('Økning på 30 %');
    await expect(page.getByTestId('kpi-trend')).toHaveCount(0);

    // Registrer første måling, under målet.
    await page.getByRole('button', { name: 'Registrer måling' }).click();
    const periodSelect = page.locator('#measurement-period');
    const suggested = await periodSelect.inputValue();
    await page.locator('#measurement-value').fill('1 250 000');
    await page.getByRole('button', { name: 'Registrer måling', exact: true }).last().click();
    await expect(page.getByText('Målingen er registrert.')).toBeVisible();
    await expect(page.getByTestId('kpi-current-result')).toHaveText('Utenfor mål');
    await expect(page.getByTestId('kpi-status-explanation')).toHaveText('Siste verdi NOK 1 250 000 er under målet ≥ NOK 1 300 000.');

    // Korriger: the form opens on the same period, says it is a correction and needs a comment.
    const rows = page.getByTestId('kpi-measurements').locator('tbody tr');
    await rows.first().getByRole('button', { name: 'Korriger' }).click();
    await expect(page.getByRole('heading', { name: 'Korriger', level: 2 })).toBeVisible();
    await expect(periodSelect).toHaveValue(suggested);
    await expect(page.getByTestId('measurement-correction')).toBeVisible();
    await page.locator('#measurement-value').fill('1 350 000');
    await page.locator('#measurement-comment').fill('Revidert regnskap');
    await page.getByRole('button', { name: 'Registrer måling', exact: true }).last().click();
    await expect(page.getByText('Målingen er registrert.')).toBeVisible();
    await expect(page.getByTestId('kpi-current-result')).toHaveText('På mål');
    await expect(page.getByTestId('kpi-status-explanation')).toHaveText('Siste verdi NOK 1 350 000 oppfyller målet ≥ NOK 1 300 000.');
    await expect(rows.nth(1)).toContainText('Erstattet');

    // A month before, registered afterwards: the latest value stays the newest period.
    await page.getByRole('button', { name: 'Registrer måling' }).click();
    const earlier = await periodSelect.locator('option').nth(1).getAttribute('value');
    await periodSelect.selectOption(earlier);
    await page.locator('#measurement-value').fill('1 100 000');
    await page.getByRole('button', { name: 'Registrer måling', exact: true }).last().click();
    await expect(page.getByText('Målingen er registrert.')).toBeVisible();
    await expect(page.getByTestId('kpi-latest-value')).toHaveText('NOK 1 350 000');

    // Utvikling over tid: one point per period, the corrected value only.
    const trend = page.getByTestId('kpi-trend');
    await expect(trend).toBeVisible();
    // One point per period. The chart is a picture named by its summary (A11Y-052): no tab stop per
    // point; hovering one still shows its value, and every value is in Målehistorikk.
    await expect(trend.getByTestId('kpi-trend-point')).toHaveCount(2);
    await expect(trend.locator('[tabindex]')).toHaveCount(0);
    await expect(trend).toHaveAttribute('aria-label', /Siste verdi er NOK\s1\s350\s000 for /);
    await trend.getByTestId('kpi-trend-point').last().hover();
    await expect(page.getByTestId('kpi-trend-tooltip')).toContainText('NOK 1 350 000');
    await page.screenshot({ path: 'test-results/kpi-target-types-02-growth-measured.png', fullPage: true });

    // Rediger: the growth fields come back as they were; a higher change moves the målverdi.
    await page.getByRole('button', { name: 'Rediger' }).click();
    await expect(page.getByRole('radio', { name: 'Endring fra startverdi' })).toBeChecked();
    await expect(page.locator('#kpi-change')).toHaveValue('30');
    await page.locator('#kpi-change').fill('40');
    await expect(page.getByTestId('kpi-computed-target')).toHaveText('NOK 1 400 000');
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('KPI-en er oppdatert.')).toBeVisible();
    await expect(page.getByTestId('kpi-current-result')).toHaveText('Utenfor mål');
    expect(await stored(kpiTitle)).toEqual({ target_min: '1400000.0000', target_max: null, baseline_value: '1000000.0000' });
    // The history keeps the målverdi each measurement was registered with.
    await expect(rows.first()).toContainText('≥ NOK 1 300 000');
});

/**
 * In English, on a phone: an «At most» KPI in days, measured above it, then changed to an exact
 * value and judged again. The form and the page fit 390 px, and ordinary text stays 16 px.
 */
test('an English user defines at-most and exact targets on a phone', async ({ page }) => {
    test.setTimeout(180_000);

    const { area_name: areaName, user_name: userName, email } = await seedObjectiveEnglishEditor(suffix, password);
    const objectiveTitle = objectiveE2eName(suffix, 'Fast case handling');
    const kpiTitle = objectiveE2eName(suffix, 'Processing time');

    await page.setViewportSize({ width: 390, height: 844 });
    await loginAs(page, email, password);

    await page.goto('/app/objectives');
    await page.getByRole('button', { name: 'New objective' }).click();
    await page.locator('#objective-title').fill(objectiveTitle);
    await page.locator('#objective-area').selectOption({ label: areaName });
    await page.locator('#objective-owner').selectOption({ label: userName });
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.waitForURL(/\/app\/objectives\/\d+$/);

    const kpiSection = page.locator('section', { has: page.getByRole('heading', { name: 'KPIs', exact: true }) });
    await kpiSection.getByRole('button', { name: 'New KPI' }).click();
    await page.locator('#kpi-title').fill(kpiTitle);
    await page.locator('#kpi-unit').selectOption({ label: 'Days' });
    await expect(page.locator('#kpi-unit-hint')).toHaveText('Duration in days, at most two decimals.');
    await page.getByRole('radio', { name: 'At most' }).check();
    await page.locator('#kpi-target-max').fill('5');
    await expect(page.getByTestId('kpi-rule-preview')).toContainText('The target is reached when the value is at most 5 days.');
    await expect(page.getByTestId('kpi-form-deadline')).toHaveText('Ongoing – the objective has no target date');
    await page.locator('#kpi-frequency').selectOption({ label: 'Monthly' });
    await expectPhoneReadable(page, ['kpi-rule-preview']);
    await page.screenshot({ path: 'test-results/kpi-target-types-03-english-form-phone.png', fullPage: true });
    await kpiSection.getByRole('button', { name: 'Save', exact: true }).click();
    await page.waitForURL(/\/app\/objectives\/\d+\/kpis\/\d+$/);

    await page.getByRole('button', { name: 'Record measurement' }).click();
    // Days are never negative: refused before it is sent.
    await page.locator('#measurement-value').fill('-2');
    await page.locator('#measurement-value').blur();
    await expect(page.locator('#measurement-value-error')).toHaveText('The value cannot be negative for this unit.');
    await page.locator('#measurement-value').fill('8');
    await page.getByRole('button', { name: 'Record measurement', exact: true }).last().click();
    await expect(page.getByText('The measurement has been recorded.')).toBeVisible();
    await expect(page.getByTestId('kpi-current-result')).toHaveText('Off target');
    await expect(page.getByTestId('kpi-status-explanation')).toHaveText('The latest value 8 days is above the target ≤ 5 days.');
    await expectPhoneReadable(page, ['kpi-status-explanation', 'kpi-target-rule', 'kpi-deadline']);

    // Exact value: 8 days is now the target, so the same measurement is on target.
    await page.getByRole('button', { name: 'Edit' }).click();
    await page.getByRole('radio', { name: 'Exact value' }).check();
    await page.locator('#kpi-target-min').fill('8');
    await expect(page.getByTestId('kpi-rule-preview')).toContainText('The target is reached when the value is exactly 8 days.');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('The KPI has been updated.')).toBeVisible();
    await expect(page.getByTestId('kpi-current-result')).toHaveText('On target');
    await expect(page.getByTestId('kpi-target-rule')).toHaveText('The target is reached when the value is exactly 8 days.');
    await expectPhoneReadable(page, ['kpi-status-explanation']);
    await page.screenshot({ path: 'test-results/kpi-target-types-04-english-phone.png', fullPage: true });
});
