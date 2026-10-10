import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';
import { DESKTOP, expectReadable } from './helpers/readability.js';

/**
 * A process shows the styrende dokumenter that govern it — policies, procedures, work instructions
 * and checklists in one list — and a quality editor searches for, links and unlinks them in place.
 * The link is a `governs` relation, so the document's own page lists the process too — under
 * «Styrer disse prosessene», never as a generic relation list.
 *
 * The documents are registered through the app itself (the same POST the create form sends), with
 * unique titles so repeated runs never pick up a document an earlier run left linked.
 */
test('a process links several governing document types, each sees the process, and a link is removed', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    const processLink = page.getByRole('link', { name: /E2E liten prosess/ });

    if (await processLink.count() === 0) {
        test.skip(true, 'No seeded quality process in this environment.');
    }

    const stamp = Date.now();
    const procedureTitle = `E2E prosedyre ${stamp}`;
    const checklistTitle = `E2E sjekkliste ${stamp}`;
    const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');

    for (const [type, title] of [['procedure', procedureTitle], ['checklist', checklistTitle]]) {
        const created = await page.request.post('/app/quality/items', {
            headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'text/html' },
            form: { quality_type: type, title },
        });
        expect(created.ok()).toBeTruthy();
    }

    await page.goto('/app/quality');
    await processLink.first().click();
    await page.waitForURL(/\/app\/quality\/items\/\d+/);
    const processUrl = page.url();

    const panel = page.getByTestId('governing-documents');
    await expect(panel).toBeVisible();

    // Search narrows the list; each option says its type and status.
    const picker = panel.getByLabel('Legg til styrende dokumenter');
    await picker.fill(`E2E prosedyre ${stamp}`);
    const procedureOption = panel.getByRole('option', { name: new RegExp(procedureTitle) });
    await expect(procedureOption).toContainText('Prosedyre');
    await expect(procedureOption).toContainText('Utkast');
    await expect(panel.getByRole('option', { name: new RegExp(checklistTitle) })).toHaveCount(0);
    await procedureOption.click();
    await picker.fill(checklistTitle);
    await panel.getByRole('option', { name: new RegExp(checklistTitle) }).click();
    await panel.getByRole('button', { name: 'Koble til' }).click();

    const linkedProcedure = panel.getByRole('link', { name: procedureTitle });
    await expect(linkedProcedure).toBeVisible();
    await expect(panel.getByRole('link', { name: checklistTitle })).toBeVisible();
    await expect(panel.locator('li', { has: page.getByRole('link', { name: procedureTitle }) })).toContainText('Prosedyre');
    await expect(panel.locator('li', { has: page.getByRole('link', { name: procedureTitle }) })).toContainText('Utkast');
    await expectReadable(page, 'quality-governing', 'process');
    await page.setViewportSize(DESKTOP);

    // From the procedure, the process it governs — said in the document's own terms.
    await linkedProcedure.click();
    await page.waitForURL(/\/app\/quality\/items\/\d+/);
    const governed = page.locator('section', { has: page.getByRole('heading', { name: 'Styrer disse prosessene' }) });
    await expect(governed).toBeVisible();
    await expect(governed.getByRole('link', { name: /E2E liten prosess/ })).toBeVisible();
    await expectNoRelationVocabulary(page);
    await expectReadable(page, 'quality-governing', 'procedure');
    await page.setViewportSize(DESKTOP);

    // Back on the process, remove one link; the document itself stays and is offered again.
    await page.goto(processUrl);
    page.once('dialog', (dialog) => dialog.accept());
    await panel.getByRole('button', { name: `Fjern koblingen til ${checklistTitle}` }).click();
    await expect(panel.getByRole('link', { name: checklistTitle })).toHaveCount(0);
    await expect(panel.getByRole('link', { name: procedureTitle })).toBeVisible();
    await picker.fill(checklistTitle);
    await expect(panel.getByRole('option', { name: new RegExp(checklistTitle) })).toHaveCount(1);

    // Clean up the procedure link so later runs see the seeded process as it was.
    await page.keyboard.press('Escape');
    page.once('dialog', (dialog) => dialog.accept());
    await panel.getByRole('button', { name: `Fjern koblingen til ${procedureTitle}` }).click();
    await expect(panel.getByRole('link', { name: procedureTitle })).toHaveCount(0);
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
    await expectReadable(page, 'quality-governing', 'control');
});

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
