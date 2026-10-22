import { exec } from 'node:child_process';
import { promisify } from 'node:util';
import { test } from '@playwright/test';
import { artisanCommand } from './environment.js';

const execAsync = promisify(exec);
const cwd = new URL('../../..', import.meta.url).pathname;

/**
 * Runs PHP in the isolated E2E app container through tinker, as the E2E fixtures in tests/Support
 * are invoked (helpers/environment.js). E2E_TINKER_WORKDIR (e.g. /var/www/html/.wt-feature) runs it
 * in a git worktree's checkout instead of the main one.
 */
export function tinker(php) {
    return execAsync(artisanCommand(`tinker --execute="${php}"`), { cwd });
}

/**
 * The six-character suffix every Risk spec puts on the fagområder, roles and risks it creates.
 */
export function riskE2eSuffix() {
    return Math.random().toString(36).slice(2, 8).padEnd(6, '0').toUpperCase();
}

/**
 * Registers the Risk E2E cleanup (Tests\Support\RiskE2EFixture) for a spec file: before the
 * tests, leftovers from earlier runs that never reached teardown are swept; after each test this
 * run's own data is removed, whether the test passed, failed or stopped halfway.
 */
export function cleanUpRiskE2eData(suffix) {
    test.beforeAll(async () => {
        await tinker('\\Tests\\Support\\RiskE2EFixture::cleanup();');
    });

    test.afterEach(async () => {
        await tinker(`\\Tests\\Support\\RiskE2EFixture::cleanup('${suffix}');`);
    });
}

/**
 * Fills the required risk description (årsak → hendelse → konsekvens) in an open risk form.
 */
export async function fillRiskDescription(page, {
    cause = 'manglende rutiner',
    event = 'en hendelse inntreffer',
    consequence = 'virksomheten rammes',
} = {}) {
    await page.locator('#risk-cause').fill(cause);
    await page.locator('#risk-event').fill(event);
    await page.locator('#risk-consequence').fill(consequence);
}
