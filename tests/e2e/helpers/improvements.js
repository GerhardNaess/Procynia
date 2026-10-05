import { expect, test } from '@playwright/test';
import { tinker } from './risk.js';

/**
 * The six-character suffix every Avvik og forbedringer spec puts on what it creates.
 */
export function improvementE2eSuffix() {
    return Math.random().toString(36).slice(2, 8).padEnd(6, '0').toUpperCase();
}

/**
 * The name of anything an Avvik og forbedringer spec creates — fagområde, role or case. Always use
 * this: Tests\Support\ImprovementE2EFixture cleans up by this prefix and nothing else.
 */
export function improvementE2eName(suffix, label) {
    return `E2E Avvik ${suffix} ${label}`;
}

/** Calls a static method on ImprovementE2EFixture and returns its JSON answer. */
export async function improvementFixture(call) {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\ImprovementE2EFixture::${call});`);
    const match = stdout.match(/\{.*\}|null/);

    if (! match) {
        throw new Error(`Fixture call failed: ${stdout}`);
    }

    return JSON.parse(match[0]);
}

/**
 * Registers the cleanup for a spec file: before the tests, leftovers from earlier runs that never
 * reached teardown are swept; after each test this run's own data is removed, whether the test
 * passed, failed or stopped halfway; and after the file, what is left of the run must be nothing.
 */
export function cleanUpImprovementE2eData(suffix) {
    test.beforeAll(async () => {
        await tinker('\\Tests\\Support\\ImprovementE2EFixture::cleanup();');
    });

    test.afterEach(async () => {
        await tinker(`\\Tests\\Support\\ImprovementE2EFixture::cleanup('${suffix}');`);
    });

    test.afterAll(async () => {
        expect(await improvementFixture(`remaining('${suffix}')`)).toEqual({
            areas: 0, roles: 0, users: 0, cases: 0, status_changes: 0, actions: 0, action_status_changes: 0, action_verifications: 0, processes: 0, activities: 0,
        });
    });
}
