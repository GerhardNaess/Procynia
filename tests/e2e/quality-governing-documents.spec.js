import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * A process shows the styrende dokumenter that govern it, and a quality editor links and unlinks
 * them in place. The link is a `governs` relation, so the policy's own page lists the process too.
 *
 * The policy is registered through the app itself (the same POST the create form sends), with a
 * unique title so repeated runs never pick up a policy an earlier run left linked.
 */
test('a process links a governing document, the policy sees the process, and the link is removed', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    const processLink = page.getByRole('link', { name: /E2E liten prosess/ });

    if (await processLink.count() === 0) {
        test.skip(true, 'No seeded quality process in this environment.');
    }

    const policyTitle = `E2E policy ${Date.now()}`;
    const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    const created = await page.request.post('/app/quality/items', {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'text/html' },
        form: { quality_type: 'policy', title: policyTitle },
    });
    expect(created.ok()).toBeTruthy();

    await page.goto('/app/quality');
    await processLink.first().click();
    await page.waitForURL(/\/app\/quality\/items\/\d+/);
    const processUrl = page.url();

    const panel = page.locator('section', { has: page.getByRole('heading', { name: 'Styrende dokumenter' }) });
    await expect(panel).toBeVisible();

    await panel.getByLabel('Styrende dokument').selectOption({ label: policyTitle });
    await panel.getByRole('button', { name: 'Koble til' }).click();

    const linked = panel.getByRole('link', { name: policyTitle });
    await expect(linked).toBeVisible();
    await page.screenshot({ path: 'test-results/quality-governing-documents.png', fullPage: true });

    // From the policy, the process it governs.
    await linked.click();
    await page.waitForURL(/\/app\/quality\/items\/\d+/);
    await expect(page.getByRole('heading', { name: 'Relasjoner' })).toBeVisible();
    await expect(page.getByRole('link', { name: /E2E liten prosess/ })).toBeVisible();

    // Back on the process, remove the link; the policy itself stays.
    await page.goto(processUrl);
    page.once('dialog', (dialog) => dialog.accept());
    await panel.locator('li', { has: page.getByRole('link', { name: policyTitle }) })
        .getByRole('button', { name: 'Fjern kobling' })
        .click();
    await expect(panel.getByRole('link', { name: policyTitle })).toHaveCount(0);
    await expect(panel.getByLabel('Styrende dokument').locator('option', { hasText: policyTitle })).toHaveCount(1);
});
