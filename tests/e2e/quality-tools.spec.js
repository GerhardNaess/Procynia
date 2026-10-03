import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * Verktøy: a document registered in the library, linked to a control, opened from there, and the
 * link removed again with the tool still in the library. Permissions, tenancy and the file cascade
 * are owned by QualityToolTest.
 *
 * The registered tool and its file stay in the document archive afterwards — that is the point of
 * removing a link — so each run uploads unique bytes under a unique name.
 */
function minimalPdf(text) {
    return Buffer.from(`%PDF-1.4
1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj
3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj
4 0 obj<</Length ${text.length + 30}>>stream
BT /F1 12 Tf 20 100 Td (${text}) Tj ET
endstream endobj
5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj
trailer<</Root 1 0 R>>
%%EOF
`);
}

test('a tool is registered, linked to a control, opened and unlinked', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality?tab=controls');

    const controlLink = page.locator('tr a').first();

    if (await controlLink.count() === 0) {
        test.skip(true, 'No control in the register in this environment.');
    }

    const controlTitle = (await controlLink.innerText()).trim();
    const controlHref = await controlLink.getAttribute('href');

    // Register.
    await page.goto('/app/quality?tab=tools');
    await expect(page.getByRole('link', { name: 'Verktøy', exact: true }).first()).toBeVisible();

    const stamp = Date.now();
    const title = `E2E veiledning ${stamp}`;
    const register = page.locator('section', { has: page.getByRole('heading', { name: 'Registrer verktøy' }) });
    await register.getByLabel('Navn').fill(title);
    await register.getByLabel('Type verktøy').selectOption('guide');
    await register.getByLabel('Kort beskrivelse').fill('Slik utføres kontrollen steg for steg.');
    await register.locator('input[type=file]').setInputFiles({
        name: `e2e-veiledning-${stamp}.pdf`,
        mimeType: 'application/pdf',
        buffer: minimalPdf(`Veiledning ${stamp}`),
    });
    await register.getByRole('button', { name: 'Registrer verktøy' }).click();

    const card = page.locator('li', { has: page.getByRole('heading', { name: title }) });
    await expect(card).toBeVisible();
    await expect(card.getByText('Veiledning', { exact: true })).toBeVisible();
    await expect(card.getByText('Ikke koblet til noen kontroll ennå', { exact: false })).toBeVisible();

    // Link from the control.
    await page.goto(controlHref);
    const tools = page.locator('section', { has: page.getByRole('heading', { name: 'Verktøy', exact: true }) });
    await expect(tools).toBeVisible();
    const optionValue = await tools.locator('option', { hasText: title }).getAttribute('value');
    await tools.getByLabel('Verktøy fra biblioteket').selectOption(optionValue);
    await tools.getByRole('button', { name: 'Koble til verktøy' }).click();

    const row = tools.locator('li', { hasText: title });
    await expect(row).toBeVisible();
    await page.screenshot({ path: 'test-results/quality-control-tools.png', fullPage: true });

    // Open and download go through Kvalitet's own route.
    const openHref = await row.getByRole('link', { name: 'Åpne' }).getAttribute('href');
    const opened = await page.request.get(openHref);
    expect(opened.status()).toBe(200);
    expect(opened.headers()['content-type']).toContain('application/pdf');
    const downloaded = await page.request.get(await row.getByRole('link', { name: 'Last ned' }).getAttribute('href'));
    expect(downloaded.headers()['content-disposition']).toContain('attachment');

    // The library shows where it is used.
    await page.goto('/app/quality?tab=tools');
    await expect(card.getByRole('link', { name: controlTitle })).toBeVisible();
    await page.screenshot({ path: 'test-results/quality-tools-library.png', fullPage: true });

    // Unlink: the tool stays in the library.
    await page.goto(controlHref);
    page.once('dialog', (dialog) => dialog.accept());
    await row.getByRole('button', { name: 'Fjern' }).click();
    await expect(tools.locator('li', { hasText: title })).toHaveCount(0);

    await page.goto('/app/quality?tab=tools');
    await expect(card).toBeVisible();
    await expect(card.getByText('Ikke koblet til noen kontroll ennå', { exact: false })).toBeVisible();
});
