import { SYSTEM_OWNER, loginAs } from './auth.js';
import { tinker } from './risk.js';

/**
 * Wiki E2E fixtures seed into the E2E customer E2ETestSeeder creates (e2e-test-customer), read by
 * its System Owner — never into a development customer, and never as a development user.
 */
export async function e2eWikiCustomerId() {
    const { stdout } = await tinker("echo \\App\\Models\\Customer::where('slug', 'e2e-test-customer')->value('id');");

    return Number(stdout.trim());
}

export function loginAsWikiReader(page) {
    return loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
}
