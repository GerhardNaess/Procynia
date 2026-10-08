import { expect, test } from '@playwright/test';
import { USER, loginAs } from './helpers/auth.js';
import { DESKTOP, PHONE, expectReadable as expectReadableAt } from './helpers/readability.js';
import { tinker } from './helpers/risk.js';

/**
 * «Lag kunnskapsartikkel», end to end, from Avvik og forbedringer into Enterprise Wiki — the shared
 * handoff every module uses. The person chooses what of the case may be shared (nothing is
 * preselected), writes the lesson, and the case lists the Wiki source it became.
 *
 * Tests\Support\KnowledgeHandoffE2EFixture suspends AI for the E2E customer while this runs, so the
 * Wiki run the handoff starts is stopped by cost control before any provider call. AI usage
 * attribution for such a run is proven in PHP (WikiKnowledgeHandoffTest) against a faked provider.
 */
const suffix = Math.random().toString(36).slice(2, 8).padEnd(6, '0').toUpperCase();

async function fixture(call) {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\KnowledgeHandoffE2EFixture::${call});`);
    const match = stdout.match(/\{.*\}/s);

    if (! match) {
        throw new Error(`Fixture call failed: ${stdout}`);
    }

    return JSON.parse(match[0]);
}

test.beforeAll(async () => {
    await tinker('\\Tests\\Support\\KnowledgeHandoffE2EFixture::cleanup();');
});

test.afterEach(async () => {
    await tinker(`\\Tests\\Support\\KnowledgeHandoffE2EFixture::cleanup('${suffix}');`);
});

test.afterAll(async () => {
    expect(await fixture(`remaining('${suffix}')`)).toEqual({ cases: 0, roles: 0, areas: 0, origins: 0, ai_suspended_by_fixture: false });
});

test('an avvik hands chosen knowledge over to the Wiki as a source with provenance', async ({ page }) => {
    test.setTimeout(120_000);

    const { case_id: caseId, case_title: caseTitle } = await fixture(`seed('${suffix}')`);

    await loginAs(page, USER.email, USER.password);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/improvements/${caseId}`);

    const panel = page.locator('section', { has: page.getByRole('heading', { name: 'Kunnskap delt til Wiki', exact: true }) });
    await expect(panel).toContainText('Ingen kunnskap er delt herfra ennå.');
    await panel.getByRole('button', { name: 'Lag kunnskapsartikkel' }).click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    // The case's own title is suggested; nothing of its content is chosen until the person says so.
    await expect(dialog.getByLabel('Tittel')).toHaveValue(caseTitle);
    await expect(dialog.getByRole('checkbox', { name: /Hva skjedde/ })).not.toBeChecked();
    await expect(dialog.getByRole('checkbox', { name: /Årsaksanalyse/ })).not.toBeChecked();
    await expect(dialog.getByRole('button', { name: 'Send til Wiki' })).toBeDisabled();
    await expectReadableAt(page, 'knowledge-handoff', '01-dialog');

    await dialog.getByLabel('Tittel').fill(`E2E Kunnskap ${suffix} Avstem lønnsfiler`);
    await dialog.getByRole('checkbox', { name: /Årsaksanalyse/ }).check();
    await dialog.getByLabel('Læringspunkter').fill('Avstem alltid lønnsfilen mot forrige kjøring før utbetaling.');
    await dialog.getByRole('button', { name: 'Send til Wiki' }).click();

    await expect(page.getByText('Kunnskapen er lagt inn som kilde i Wiki.')).toBeVisible();
    await expect(dialog).toHaveCount(0);
    await expect(panel).toContainText(`E2E Kunnskap ${suffix} Avstem lønnsfiler`);
    await expect(panel).toContainText('Kilde mottatt – behandles i Wiki');

    // An ordinary Wiki source with the lesson and only the chosen section, traceable to the case.
    const handed = await fixture(`handedOver(${caseId})`);
    expect(handed.origins).toBe(1);
    expect(handed.runs).toBe(1);
    expect(handed.documents).toHaveLength(1);
    expect(handed.documents[0].uploaded_by).toBe(USER.email);
    expect(handed.documents[0].text).toContain('Avstem alltid lønnsfilen mot forrige kjøring før utbetaling.');
    expect(handed.documents[0].text).toContain('Lønnsfilen ble importert manuelt uten avstemming');
    expect(handed.documents[0].text).not.toContain('Dobbel utbetaling til ti ansatte');

    await page.setViewportSize(PHONE);
    await expectReadableAt(page, 'knowledge-handoff', '02-case-after-handoff');
});
