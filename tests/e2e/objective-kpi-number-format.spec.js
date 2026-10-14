import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import {
    cleanUpObjectiveE2eData,
    clickOnCharacter,
    objectiveE2eName,
    objectiveE2eSuffix,
    seedObjectiveEnglishEditor,
} from './helpers/objectives.js';
import { tinker } from './helpers/risk.js';

const suffix = objectiveE2eSuffix();
const password = `E2e-${suffix}-English1`;
cleanUpObjectiveE2eData(suffix);

// A phone with a touch screen: the fields are tapped, not clicked.
test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

test.afterAll(async () => {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\ObjectiveE2EFixture::remaining('${suffix}'));`);
    const remaining = JSON.parse(stdout.match(/\{.*\}/)[0]);

    expect(remaining).toEqual({
        areas: 0, roles: 0, users: 0, objectives: 0, kpis: 0, kpi_processes: 0, kpi_activities: 0, kpi_measurements: 0, kpi_status_changes: 0, objective_status_changes: 0,
    });
});

/** A KPI's bounds, tolerance and measured values as the database holds them. */
async function stored(title) {
    // No PHP variables: the command runs through a shell, which would expand them.
    const kpi = `\\App\\Models\\Kpi::where('title', '${title}')`;
    const { stdout } = await tinker(`echo json_encode(${kpi}->firstOrFail()->only(['target_min', 'target_max', 'tolerance']) + ['values' => \\App\\Models\\KpiMeasurement::whereIn('kpi_id', ${kpi}->select('id'))->pluck('value')->all()]);`);

    return JSON.parse(stdout.match(/\{.*\}/)[0]);
}

/**
 * The KPI number fields for a user whose language is English, on a phone: English thousands
 * («25,000» is 25000, never a question), the decimal point, malformed groups refused, and a tap in
 * the middle of a number editing right there — in Ny KPI and in Registrer måling. A fresh user of
 * the run holds the English language; the shared E2E users are never touched.
 */
test('an English user writes, edits and measures KPI numbers the English way', async ({ page }) => {
    test.setTimeout(120_000);

    const { area_name: areaName, user_name: userName, email } = await seedObjectiveEnglishEditor(suffix, password);
    const objectiveTitle = objectiveE2eName(suffix, 'Revenue growth');
    const kpiTitle = objectiveE2eName(suffix, 'Order intake');

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
    await page.locator('#kpi-unit').selectOption({ label: 'Currency' });
    await expect(page.locator('#kpi-currency-code')).toHaveValue('NOK');

    // Both bounds: a range. The tolerance is under the advanced settings.
    await page.getByRole('radio', { name: 'Within a range' }).check();
    await page.getByRole('button', { name: /Advanced settings/ }).click();
    const min = page.locator('#kpi-target-min');
    const max = page.locator('#kpi-target-max');
    const tolerance = page.locator('#kpi-tolerance');

    // English thousands and decimals, each read as English writes them.
    await min.tap();
    await page.keyboard.type('25,000');
    await min.blur();
    await expect(min).toHaveValue('25,000');
    await expect(page.getByTestId('kpi-target-min-suffix')).toHaveText('NOK');
    await expect(page.locator('#kpi-target-min-error')).toHaveCount(0);

    await max.tap();
    await page.keyboard.type('1,300,000');
    await max.blur();
    await expect(max).toHaveValue('1,300,000');

    await tolerance.tap();
    await page.keyboard.type('1,300.50');
    await tolerance.blur();
    await expect(tolerance).toHaveValue('1,300.5');

    // Thousands in the wrong places are refused, never read as another number.
    for (const malformed of ['1,30,000', '1.300,50']) {
        await tolerance.fill(malformed);
        await tolerance.blur();
        await expect(tolerance).toHaveValue(malformed);
        await expect(page.locator('#kpi-tolerance-error')).toHaveText('Enter a number, for example 1,300,000 or 12.5.');
        await expect(tolerance).toHaveAttribute('aria-invalid', 'true');
    }
    await kpiSection.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page).not.toHaveURL(/\/kpis\/\d+$/);
    await tolerance.fill('1,300.50');
    await expect(page.locator('#kpi-tolerance-error')).toHaveCount(0);
    await tolerance.blur();
    await expect(tolerance).toHaveValue('1,300.5');

    // A tap on the comma between «1,300» and «000» edits right there.
    await clickOnCharacter(max, 5, { tap: true });
    await expect(max).toBeFocused();
    await expect(max).toHaveValue('1300000');
    await expect.poll(() => max.evaluate((input) => [input.selectionStart, input.selectionEnd])).toEqual([4, 4]);
    await page.keyboard.type('5');
    await expect(max).toHaveValue('13005000');
    // Leaving by a tap elsewhere — two taps on one spot would be a double-tap, selecting the word.
    await page.locator('#kpi-title').tap();
    await expect(max).toHaveValue('13,005,000');

    // And a digit deleted there: the cursor after «13,005», Backspace takes the «5».
    await clickOnCharacter(max, 6, { tap: true });
    await expect.poll(() => max.evaluate((input) => [input.selectionStart, input.selectionEnd])).toEqual([5, 5]);
    await page.keyboard.press('Backspace');
    await expect(max).toHaveValue('1300000');
    await max.blur();
    await expect(max).toHaveValue('1,300,000');

    // On a phone the page never scrolls sideways.
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);

    await kpiSection.getByRole('button', { name: 'Save', exact: true }).click();
    await page.waitForURL(/\/app\/objectives\/\d+\/kpis\/\d+$/);
    await expect(page.getByText('The KPI has been registered.')).toBeVisible();
    expect(await stored(kpiTitle)).toEqual({ target_min: '25000.0000', target_max: '1300000.0000', tolerance: '1300.5000', values: [] });

    // Record measurement: a value written the English way, then corrected in the middle.
    await page.getByRole('button', { name: 'Record measurement' }).click();
    const value = page.locator('#measurement-value');
    await value.tap();
    await page.keyboard.type('1,250,000');
    await value.blur();
    await expect(value).toHaveValue('1,250,000');
    await expect(page.getByTestId('measurement-value-suffix')).toHaveText('NOK');

    // A double-tap selects the whole number, as anywhere else, and typing replaces it.
    await clickOnCharacter(value, 2, { tap: true });
    await clickOnCharacter(value, 2, { tap: true });
    await expect.poll(() => value.evaluate((input) => [input.value, input.selectionStart, input.selectionEnd])).toEqual(['1250000', 0, 7]);
    await page.keyboard.type('1250000');
    await page.locator('#measurement-comment').tap();
    await expect(value).toHaveValue('1,250,000');

    // On the comma before «000»: Backspace takes the last «0» of «250», a «9» goes in its place.
    await clickOnCharacter(value, 5, { tap: true });
    await page.keyboard.press('Backspace');
    await page.keyboard.type('9');
    await expect(value).toHaveValue('1259000');
    await value.blur();
    await expect(value).toHaveValue('1,259,000');

    await page.getByRole('button', { name: 'Record measurement', exact: true }).last().click();
    await expect(page.getByText('The measurement has been recorded.')).toBeVisible();
    expect((await stored(kpiTitle)).values).toEqual(['1259000.0000']);
});
