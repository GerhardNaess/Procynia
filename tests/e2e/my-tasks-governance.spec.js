import { exec } from 'node:child_process';
import { promisify } from 'node:util';
import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { DESKTOP } from './helpers/readability.js';
import { REPO_ROOT, artisanCommand } from './helpers/environment.js';

const execAsync = promisify(exec);
const PASSWORD = 'E2eOppg123!';
const suffix = Math.random().toString(36).slice(2, 8).padEnd(6, '0').toUpperCase();

/** Runs Tests\Support\MyTasksE2EFixture in the isolated E2E app container (helpers/environment.js). */
async function fixture(call) {
    const { stdout } = await execAsync(artisanCommand(`tinker --execute="echo json_encode(\\Tests\\Support\\MyTasksE2EFixture::${call});"`), { cwd: REPO_ROOT });
    const match = stdout.match(/\{.*\}|null/);

    return match ? JSON.parse(match[0]) : null;
}

test.beforeAll(async () => {
    await fixture('cleanup()');
});

test.afterAll(async () => {
    await fixture(`cleanup('${suffix}')`);
    expect(await fixture(`remaining('${suffix}')`)).toEqual({ customers: 0 });
});

/**
 * Punkt 6D, end to end: a person who owns work in every styringsmodul, at a customer without Anbud,
 * finds it all under «Mine oppgaver» — grouped by deadline, each from its module, each opening the
 * module's own page — narrows it with the module filter, and has been told about each handover in
 * the bell. Rules, access and isolation are PHP's (MyTasksGovernanceTest, GovernanceAssignmentNotificationTest).
 */
test('work from every styringsmodul is under Mine oppgaver, filterable, and announced in the bell', async ({ page }) => {
    test.setTimeout(120_000);

    const seeded = await fixture(`seed('${suffix}', '${PASSWORD}')`);
    await loginAs(page, seeded.email, PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/info-center');

    // Every module's work, one card each, in the module's own words.
    const cards = page.getByTestId('info-center-governance-task');
    await expect(cards).toHaveCount(5);
    for (const title of Object.values(seeded.titles)) {
        await expect(cards.filter({ hasText: title })).toHaveCount(1);
    }
    await expect(cards.filter({ hasText: seeded.titles.improvements })).toContainText('Sak med passert frist');
    await expect(cards.filter({ hasText: seeded.titles.quality })).toContainText('Kontroll uten evidens');
    await expect(page.getByTestId('info-center-my-tasks-overdue')).toContainText(seeded.titles.improvements);
    await expect(page.getByTestId('info-center-my-tasks-no_due')).toContainText(seeded.titles.risk);

    // The filter narrows to one module and back.
    const filter = page.getByTestId('info-center-my-tasks-filter');
    await filter.getByRole('button', { name: /^Risiko/ }).click();
    await expect(cards).toHaveCount(1);
    await expect(cards).toContainText(seeded.titles.risk);
    await filter.getByRole('button', { name: /^Alle moduler/ }).click();
    await expect(cards).toHaveCount(5);

    // The bell named each handover, from its module.
    // WCAG Fase 2 (A11Y-021): the bell is named «Varsler, N uleste», and a card's name is its own
    // content (title first) rather than an «Åpne varsel: …» label that hid the rest.
    await page.getByRole('button', { name: /^Varsler/ }).click();
    const panel = page.getByRole('region', { name: 'Varsler' });
    await expect(panel.getByRole('button', { name: /^Ansvar for risiko/ })).toContainText('Risiko');
    await expect(panel.getByRole('button', { name: /^Ansvar for sak/ })).toContainText('Avvik og forbedringer');
    await page.keyboard.press('Escape');

    // A card opens the module's own page.
    await cards.filter({ hasText: seeded.titles.objectives }).getByRole('link', { name: 'Åpne' }).click();
    await page.waitForURL(/\/app\/objectives\/\d+$/);
    await expect(page.getByRole('heading', { name: seeded.titles.objectives, level: 1 })).toBeVisible();

    // At phone width the list does not scroll sideways.
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/app/info-center');
    await expect(cards).toHaveCount(5);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
