import { expect, test } from '@playwright/test';
import { tinker } from './risk.js';

/** The password every person the Leverandøroppfølging fixture seeds logs in with. */
export const SUPPLIER_E2E_PASSWORD = 'E2eLev123!';

/** The six-character suffix a Leverandøroppfølging spec puts on what it creates. */
export function supplierE2eSuffix() {
    return Math.random().toString(36).slice(2, 8).padEnd(6, '0').toUpperCase();
}

/** Calls a static method on Tests\Support\SupplierE2EFixture and returns its JSON answer. */
export async function supplierFixture(call) {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\SupplierE2EFixture::${call});`);
    const match = stdout.match(/\{.*\}|null/);

    if (! match) {
        throw new Error(`Fixture call failed: ${stdout}`);
    }

    return JSON.parse(match[0]);
}

/**
 * Registers the cleanup for a spec file: before the tests, customers left by earlier runs that never
 * reached teardown are swept; after each test this run's customer is removed with everything in it;
 * and after the file, what is left of the run must be nothing.
 */
export function cleanUpSupplierE2eData(suffix) {
    test.beforeAll(async () => {
        await tinker('\\Tests\\Support\\SupplierE2EFixture::cleanup();');
    });

    test.afterEach(async () => {
        await tinker(`\\Tests\\Support\\SupplierE2EFixture::cleanup('${suffix}');`);
    });

    test.afterAll(async () => {
        expect(await supplierFixture(`remaining('${suffix}')`)).toEqual({
            customers: 0, suppliers: 0, status_changes: 0, roles: 0, users: 0,
        });
    });
}
