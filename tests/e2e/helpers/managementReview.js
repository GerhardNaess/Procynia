import { expect, test } from '@playwright/test';
import { tinker } from './risk.js';

/** The password every person the Ledelsens gjennomgåelse fixture seeds logs in with. */
export const MANAGEMENT_REVIEW_E2E_PASSWORD = 'E2eLg123!';

/** The six-character suffix the spec puts on what it creates. */
export function managementReviewE2eSuffix() {
    return Math.random().toString(36).slice(2, 8).padEnd(6, '0').toUpperCase();
}

/** Calls a static method on Tests\Support\ManagementReviewE2EFixture and returns its JSON answer. */
export async function managementReviewFixture(call) {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\ManagementReviewE2EFixture::${call});`);
    const match = stdout.match(/\{.*\}|null/);

    if (! match) {
        throw new Error(`Fixture call failed: ${stdout}`);
    }

    return JSON.parse(match[0]);
}

/**
 * Before the tests, customers left by earlier runs are swept; after each test this run's customer
 * is removed with everything in it; after the file, nothing of the run may be left.
 */
export function cleanUpManagementReviewE2eData(suffix) {
    test.beforeAll(async () => {
        await tinker('\\Tests\\Support\\ManagementReviewE2EFixture::cleanup();');
    });

    test.afterEach(async () => {
        await tinker(`\\Tests\\Support\\ManagementReviewE2EFixture::cleanup('${suffix}');`);
    });

    test.afterAll(async () => {
        expect(await managementReviewFixture(`remaining('${suffix}')`)).toEqual({
            customers: 0, reviews: 0, decisions: 0, snapshots: 0, events: 0, improvement_cases: 0, risks: 0, business_areas: 0, roles: 0, users: 0, wiki_documents: 0,
        });
    });
}

/** The number in one metric tile of the open section. */
export async function metricValue(page, metric) {
    return Number(await page.locator(`[data-metric="${metric}"] dd`).first().innerText());
}
