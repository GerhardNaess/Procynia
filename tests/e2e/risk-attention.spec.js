import { exec } from 'node:child_process';
import { promisify } from 'node:util';
import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';

const execAsync = promisify(exec);

const FIXTURE = '\\Tests\\Support\\RiskAttentionE2EFixture';
const cwd = new URL('../..', import.meta.url).pathname;
const tinker = (php) => execAsync(`docker compose exec -T app php artisan tinker --execute="${php}"`, { cwd });

const suffix = Math.random().toString(36).slice(2, 8).padEnd(6, '0').toUpperCase();
const password = 'E2eUser123!';

// Earlier runs that never reached afterEach (a killed process) are swept first; every run then
// removes its own suffix, whether the test passed, failed or the seed stopped halfway.
test.beforeAll(async () => {
    await tinker(`${FIXTURE}::cleanup();`);
});

test.afterEach(async () => {
    await tinker(`${FIXTURE}::cleanup('${suffix}');`);
});

/**
 * «Trenger oppmerksomhet» on the register, end to end. A fresh user with one fagområde, so the
 * totals are not disturbed by risks earlier specs left behind; a second fagområde the user cannot
 * see holds a risk that would hit every rule. The rules themselves are owned by RiskAttentionTest.
 */
test('the register shows unique risks needing attention, by category, and ignores hidden areas', async ({ page }) => {
    const { stdout } = await tinker(`echo ${FIXTURE}::seed('${suffix}', '${password}');`);
    const email = stdout.trim().split('\n').pop();

    await loginAs(page, email, password);
    await page.goto('/app/risk');

    const section = page.locator('section', { has: page.getByRole('heading', { name: 'Trenger oppmerksomhet' }) });
    await expect(section.getByText('Risikobildet for dine fagområder')).toBeVisible();
    await expect(section.getByTestId('risk-attention-total')).toHaveText('4 risikoer krever oppmerksomhet');

    const category = (key) => section.getByTestId(`risk-attention-category-${key}`);
    const expected = {
        high_residual: ['Høy eller svært høy restrisiko', `E2E Høy restrisiko ${suffix}`, 'Restrisiko i siste vurdering er høy (score 16).'],
        residual_not_assessed: ['Restrisiko ikke vurdert', `E2E Uten restrisiko ${suffix}`, 'har ikke restrisiko.'],
        review_overdue: ['Vurdering forfalt', `E2E Forfalt vurdering ${suffix}`, 'Ny vurdering skulle vært gjort innen'],
        actions_overdue: ['Tiltak forfalt', `E2E Forfalt tiltak ${suffix}`, 'Ett åpent tiltak har passert fristen'],
    };

    for (const [key, [label, title, reason]] of Object.entries(expected)) {
        const box = category(key);
        await expect(box.getByRole('heading', { name: label })).toBeVisible();
        await expect(box.getByTestId('risk-attention-count')).toHaveText('1');
        await box.getByRole('button', { name: 'Vis risikoer' }).click();
        await expect(box.getByRole('link', { name: title })).toBeVisible();
        await expect(box.getByText(reason)).toBeVisible();
    }

    // Only the categories with hits are shown, and nothing from the hidden area appears anywhere.
    await expect(section.locator('[data-testid^="risk-attention-category-"]')).toHaveCount(4);
    await expect(page.getByText('Skjult')).toHaveCount(0);

    await page.screenshot({ path: 'test-results/risk-attention.png', fullPage: true });
});
