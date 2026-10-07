import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';
import { DESKTOP, PHONE, sidewaysOverflow } from './helpers/readability.js';

/**
 * A process shows the styrende dokumenter that govern it, and a quality editor links and unlinks
 * them in place. The link is a `governs` relation, so the policy's own page lists the process too —
 * under «Styrer disse prosessene», never as a generic relation list.
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

    // From the policy, the process it governs — said in the policy's own terms.
    await linked.click();
    await page.waitForURL(/\/app\/quality\/items\/\d+/);
    const governed = page.locator('section', { has: page.getByRole('heading', { name: 'Styrer disse prosessene' }) });
    await expect(governed).toBeVisible();
    await expect(governed.getByRole('link', { name: /E2E liten prosess/ })).toBeVisible();
    await expectNoRelationVocabulary(page);
    expect(await sectionTextBelow16px(governed)).toEqual([]);
    await expectNoSidewaysScrollOnPhone(page, 'policy');

    // Back on the process, remove the link; the policy itself stays.
    await page.goto(processUrl);
    page.once('dialog', (dialog) => dialog.accept());
    await panel.locator('li', { has: page.getByRole('link', { name: policyTitle }) })
        .getByRole('button', { name: 'Fjern kobling' })
        .click();
    await expect(panel.getByRole('link', { name: policyTitle })).toHaveCount(0);
    await expect(panel.getByLabel('Styrende dokument').locator('option', { hasText: policyTitle })).toHaveCount(1);
});

/** A control page speaks of where the control is used and its evidence, never of relations. */
test('a control page shows no generic relations and reads well on a phone', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    const title = `E2E kontroll uten relasjoner ${Date.now()}`;
    const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    const created = await page.request.post('/app/quality/items', {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'text/html' },
        form: { quality_type: 'control', title },
    });
    expect(created.ok()).toBeTruthy();

    await page.goto('/app/quality');
    await page.getByRole('link', { name: title }).first().click();
    await page.waitForURL(/\/app\/quality\/items\/\d+/);
    await expect(page.getByRole('heading', { name: title })).toBeVisible();

    await expectNoRelationVocabulary(page);
    await expectNoSidewaysScrollOnPhone(page, 'control');
});

/**
 * The page fits a 390 px phone. The Item page's form labels and help lines are still 14 px — older
 * than this spec and the whole page's concern — so 16 px is asserted on the section this spec owns.
 */
async function expectNoSidewaysScrollOnPhone(page, name) {
    await page.setViewportSize(PHONE);
    expect(await sidewaysOverflow(page), `${name} scrolls sideways`).toEqual([]);
    await page.screenshot({ path: `test-results/quality-governing-${name}-phone.png`, fullPage: true });
    await page.setViewportSize(DESKTOP);
}

/** Visible text inside one section set below 16 px. */
async function sectionTextBelow16px(section) {
    return section.evaluate((root) => {
        const found = [];
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);

        while (walker.nextNode()) {
            const node = walker.currentNode;
            const element = node.parentElement;

            if (node.textContent.trim() === '' || element.offsetParent === null) {
                continue;
            }

            const size = parseFloat(getComputedStyle(element).fontSize);

            if (size < 16) {
                found.push(`${size}px «${node.textContent.trim().slice(0, 60)}»`);
            }
        }

        return found;
    });
}

/**
 * The words of the relation model — a heading «Relasjoner», a «Fra»/«Til» label, a relation type —
 * appear nowhere in the page's own content.
 */
async function expectNoRelationVocabulary(page) {
    const text = await page.locator('main').innerText();

    expect(text).not.toMatch(/Relasjon/);
    expect(text).not.toMatch(/(^|\n)\s*(Fra|Til)\s*(\n|$)/);
    expect(text).not.toMatch(/styres av|verifiserer|verifiseres av|avhenger av|kreves av/i);
}
