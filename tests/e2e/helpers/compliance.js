import { expect, test } from '@playwright/test';
import { tinker } from './risk.js';

/** The password every person the Etterlevelse og revisjon fixture seeds logs in with. */
export const COMPLIANCE_E2E_PASSWORD = 'E2eKrav123!';

/** The six-character suffix every Etterlevelse og revisjon spec puts on what it creates. */
export function complianceE2eSuffix() {
    return Math.random().toString(36).slice(2, 8).padEnd(6, '0').toUpperCase();
}

/**
 * The name of anything an Etterlevelse og revisjon spec creates — source, role or requirement.
 * Always use this: Tests\Support\ComplianceE2EFixture cleans up by this prefix and nothing else.
 */
export function complianceE2eName(suffix, label) {
    return `E2E Krav ${suffix} ${label}`;
}

/** Calls a static method on ComplianceE2EFixture and returns its JSON answer. */
export async function complianceFixture(call) {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\ComplianceE2EFixture::${call});`);
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
export function cleanUpComplianceE2eData(suffix) {
    test.beforeAll(async () => {
        await tinker('\\Tests\\Support\\ComplianceE2EFixture::cleanup();');
    });

    test.afterEach(async () => {
        await tinker(`\\Tests\\Support\\ComplianceE2EFixture::cleanup('${suffix}');`);
    });

    test.afterAll(async () => {
        expect(await complianceFixture(`remaining('${suffix}')`)).toEqual({
            sources: 0, requirements: 0, status_changes: 0, assessments: 0, requirement_processes: 0, requirement_controls: 0, quality_items: 0, roles: 0, users: 0,
        });
    });
}
