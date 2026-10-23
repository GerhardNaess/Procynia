import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';
import { expectNoAxeViolations } from './helpers/a11y.js';
import { DESKTOP, sidewaysOverflow } from './helpers/readability.js';
import { tinker } from './helpers/risk.js';

/**
 * WCAG Fase 4 — tenders, Go/No-go, documents and the AI workspace, in a real browser
 * (docs/wcag-accessibility-plan.md §18).
 *
 * Tests\Support\A11yTenderAiE2EFixture seeds the spec's own two cases for the E2E customer and removes
 * them afterwards. No AI call is made: the case document's status is set directly, and the one request
 * that would generate an answer is intercepted. Forms are submitted empty (rejected by validation),
 * except the Go/No-go assessment and one answer edit, which only touch the fixture's own cases.
 */

const FIXTURE = '\\Tests\\Support\\A11yTenderAiE2EFixture';
const CUSTOMER_ID = 2;
const APPROVED_REQUIREMENT_TEXT = 'E2E a11y: beskriv leveransemodellen.';
const DRAFT_REQUIREMENT_TEXT = 'E2E a11y: oppgi responstid for support.';
const GENERATE_URL = /\/app\/ai\/\d+\/requirements\/\d+\/wiki-answer$/;

let goNoGoCaseId = null;
let aiCaseId = null;

const polite = (page) => page.locator('#procynia-live-polite');
const assertive = (page) => page.locator('#procynia-live-assertive');

async function setDocumentStatus(status) {
    await tinker(`${FIXTURE}::setDocumentStatus(${CUSTOMER_ID}, '${status}');`);
}

/** The approved requirement's card. */
const approvedCard = (page) => page.locator('article').filter({ has: page.getByRole('button', { name: APPROVED_REQUIREMENT_TEXT }) });

/** The undecided requirement's card: the one an extracted requirement may still be deleted from. */
const draftCard = (page) => page.locator('article').filter({ has: page.getByRole('button', { name: DRAFT_REQUIREMENT_TEXT }) });

test.describe.serial('WCAG Fase 4 — tenders and the AI workspace', () => {
    test.beforeAll(async () => {
        const { stdout } = await tinker(`echo ${FIXTURE}::seed(${CUSTOMER_ID});`);
        const ids = stdout.trim().match(/(\d+),(\d+)\s*$/);

        expect(ids, `the fixture did not return two case ids: ${stdout}`).toBeTruthy();
        goNoGoCaseId = ids[1];
        aiCaseId = ids[2];
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup(${CUSTOMER_ID});`);
    });

    test.beforeEach(async ({ page }) => {
        await page.setViewportSize(DESKTOP);
        await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    });

    test('Go/No-go: every rating is a named, pressed-or-not button, and the whole assessment works from the keyboard (A11Y-003)', async ({ page }) => {
        await page.goto(`/app/notices/saved/${goNoGoCaseId}`);

        // One main landmark: the layout's. The case page used to nest a second one inside it.
        await expect(page.locator('main')).toHaveCount(1);

        // The case progress says which step is current in text, not only in colour (A11Y-024).
        const pipeline = page.getByRole('list', { name: 'Saksforløp' });
        await expect(pipeline.locator('[aria-current="step"]')).toContainText('Go / No-Go');
        await expect(pipeline.locator('[aria-current="step"] .sr-only')).toHaveText(', nåværende steg');
        await expect(pipeline.locator('li').first().locator('.sr-only')).toHaveText(', fullført');

        const assessment = page.getByRole('region', { name: 'Beslutningsvurdering' });
        await expect(assessment).toBeVisible();
        await expectNoAxeViolations(page, '[aria-labelledby="go-no-go-assessment-title"]', 'Go/No-go assessment');

        const titles = await assessment.locator('[id^="go-no-go-criterion-"][id$="-title"]').allTextContents();
        expect(titles.length).toBeGreaterThan(1);
        const [first] = titles;

        const ratings = assessment.getByRole('group', { name: first, exact: true });
        const middels = ratings.getByRole('button', { name: 'Middels' });
        await expect(middels).toHaveAttribute('aria-pressed', 'false');

        // Space sets it, Space again clears it — the toggle the component has always been.
        await middels.focus();
        await page.keyboard.press('Space');
        await expect(middels).toHaveAttribute('aria-pressed', 'true');
        await expect(middels.locator('svg')).toHaveCount(1);
        await page.keyboard.press('Space');
        await expect(middels).toHaveAttribute('aria-pressed', 'false');
        await page.keyboard.press('Space');
        await expect(middels).toBeFocused();

        await expect(assessment.getByRole('textbox', { name: `Begrunnelse for ${first} (valgfritt)` })).toBeVisible();

        // The help for one criterion opens on Enter and gives focus back on Escape.
        const help = assessment.getByRole('button', { name: `Vurderingshjelp for ${first}` });
        await help.focus();
        await page.keyboard.press('Enter');
        await expect(page.getByRole('dialog', { name: first })).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(help).toBeFocused();

        // Rating everything brings the verdict, which is spoken once.
        for (const title of titles) {
            const high = assessment.getByRole('group', { name: title, exact: true }).getByRole('button', { name: 'Høy' });
            if ((await high.getAttribute('aria-pressed')) !== 'true') {
                await high.click();
            }
        }
        await expect(polite(page)).toContainText('Foreløpig beslutningsgrunnlag:', { timeout: 5000 });
        await expectNoAxeViolations(page, '[aria-labelledby="go-no-go-assessment-title"]', 'Go/No-go assessment, rated');

        // Saving from the keyboard keeps focus on the button and is confirmed in the toast.
        const save = assessment.getByRole('button', { name: 'Lagre vurdering' });
        await save.focus();
        await page.keyboard.press('Enter');
        await expect(page.locator('#procynia-live-toast')).toContainText('Vurderingen ble lagret.');
        await expect(save).toBeFocused();
        await expect(save).toHaveAttribute('aria-disabled', 'true');
    });

    test('No-Go: a rejected closing puts focus on the reason, and Escape returns to the trigger (A11Y-013)', async ({ page }) => {
        await page.goto(`/app/notices/saved/${goNoGoCaseId}`);

        const trigger = page.getByRole('button', { name: 'Sett som No-go' });
        await trigger.focus();
        await page.keyboard.press('Enter');

        const dialog = page.getByRole('dialog', { name: 'Sett saken som No-Go' });
        await expect(dialog).toBeVisible();
        // Named and described, and the page behind it is inert (ActionDialog, Fase 3).
        await expect(dialog).toHaveAccessibleDescription(/.+/);
        expect(await page.locator('header').first().evaluate((element) => element.closest('[inert]') !== null)).toBe(true);
        const reason = dialog.getByRole('combobox', { name: 'Årsak til lukking' });
        await expect(reason).toBeFocused();
        await expect(reason).toHaveAttribute('aria-required', 'true');

        // Submitting without a reason is turned down by the server; focus lands on the reason.
        await dialog.getByRole('button', { name: 'Sett som No-go' }).focus();
        await page.keyboard.press('Enter');
        await expect(reason).toHaveAttribute('aria-invalid', 'true');
        await expect(reason).toBeFocused();
        expect(await reason.getAttribute('aria-describedby')).toContain('no_go_closure_reason-error');
        await expectNoAxeViolations(page, '[role="dialog"]', 'No-Go dialog with an error');

        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
        await expect(trigger).toBeFocused();
        await expect(page.locator('[inert]')).toHaveCount(0);
    });

    test('Saksliste: a rejected private request names and focuses the first wrong field; a filter change says how many hits', async ({ page }) => {
        await page.goto('/app/notices?mode=saved');

        await page.getByRole('button', { name: 'Registrer privat forespørsel' }).click();
        const form = page.locator('#private-request-form');
        await expect(form.getByText('Felt merket med * må fylles ut.')).toBeVisible();

        const title = form.getByRole('textbox', { name: 'Tittel' });
        await expect(title).toHaveAttribute('aria-required', 'true');

        await form.getByRole('button', { name: 'Registrer forespørsel' }).focus();
        await page.keyboard.press('Enter');
        await expect(title).toHaveAttribute('aria-invalid', 'true');
        await expect(title).toBeFocused();
        await expect(page.locator('#private_request_title-error')).toBeVisible();
        expect(await title.getAttribute('aria-describedby')).toBe('private_request_title-error');
        await expectNoAxeViolations(page, '#private-request-form', 'private request form with errors');

        // The phase filter reloads the list in place; its size is spoken once.
        await page.getByRole('combobox', { name: 'Filtrer på fase' }).selectOption('go_no_go');
        await expect(polite(page)).toContainText('treff', { timeout: 5000 });
    });

    test('Saksliste: deleting a business-review date keeps focus in the deadline editor (A11Y-146, -147)', async ({ page }) => {
        await page.goto('/app/notices?mode=saved');

        const card = page.locator('article').filter({ has: page.getByRole('heading', { name: 'E2E a11y — Go/No-go-vurdering' }) });
        await card.getByRole('button', { name: 'Rediger frister' }).click();

        // «Legg til BR» has a name now (it rendered an undefined key, an empty button).
        const add = card.getByRole('button', { name: 'Legg til BR' });
        await add.click();
        await add.click();

        const dates = card.locator(`[id^="business_review_"]`);
        await expect(dates).toHaveCount(2);
        await dates.nth(1).fill('2026-11-02');

        // Deleting the first date: focus goes to the date that followed it (now the first).
        await card.getByRole('button', { name: /^Slett .* 1$/ }).focus();
        await page.keyboard.press('Enter');
        await expect(dates).toHaveCount(1);
        await expect(dates.first()).toBeFocused();
        await expect(dates.first()).toHaveValue('2026-11-02');

        // Deleting the last one: focus goes to «Legg til BR». Nothing is saved — the editor is cancelled.
        await card.getByRole('button', { name: /^Slett .* 1$/ }).focus();
        await page.keyboard.press('Enter');
        await expect(dates).toHaveCount(0);
        await expect(add).toBeFocused();
        await card.getByRole('button', { name: 'Avbryt' }).click();
    });

    test('AI workspace: documents, requirement forms and the requirement card (A11Y-011, -044, -135)', async ({ page }) => {
        await page.goto(`/app/ai/${aiCaseId}`);

        // The upload field: one name, its state and rules as its description.
        const picker = page.locator('#ai-documents');
        await expect(picker).toHaveAccessibleName('Velg filer');
        await expect(picker).toHaveAccessibleDescription(/Ingen filer valgt[\s\S]*Tillatte filtyper/);
        await expectNoAxeViolations(page, 'form:has(#ai-documents)', 'case document upload');

        // A file of the wrong type is turned down by validation (nothing is uploaded or processed);
        // the field is marked, described by the error, and has focus.
        await picker.setInputFiles({ name: 'e2e-a11y.txt', mimeType: 'text/plain', buffer: Buffer.from('E2E') });
        await expect(picker).toHaveAccessibleDescription(/e2e-a11y\.txt/);
        await page.getByRole('button', { name: /Last opp/ }).focus();
        await page.keyboard.press('Enter');
        await expect(picker).toHaveAttribute('aria-invalid', 'true');
        await expect(picker).toBeFocused();
        // Heard once: as the focused field's description, not also as an alert.
        await expect(picker).toHaveAccessibleDescription(/e2e-a11y\.txt[\s\S]*Tillatte filtyper[\s\S]+/);
        await expect(page.locator('#ai-documents-error')).toBeVisible();
        await expect(page.locator('#ai-documents-error')).not.toHaveAttribute('role', 'alert');

        // The requirement card is not a button around buttons; its text is the control that opens it.
        await expect(page.locator('article[role="button"]')).toHaveCount(0);
        const open = page.getByRole('button', { name: APPROVED_REQUIREMENT_TEXT });
        await open.focus();
        await page.keyboard.press('Enter');
        await expect(open).toHaveAttribute('aria-current', 'true');
        await expect(page.getByTestId('wiki-answer-expert-draft')).toBeVisible();
        await expectNoAxeViolations(page, '#ai-answer-panel', 'answer panel');
        await expectNoAxeViolations(page, `article:has(#${await open.getAttribute('id')})`, 'requirement card');

        // The manual requirement form: focus to its first field on open; a rejected submit lands on
        // the empty requirement text. «Kravtype» keeps its own name, its «i» is a separate button.
        await page.getByRole('button', { name: 'Legg til krav', exact: true }).first().click();
        const manual = page.locator('form:has(#manual-requirement-text)');
        await expect(page.locator('#manual-requirement-identifier')).toBeFocused();
        await expect(manual.getByRole('combobox', { name: 'Kravtype', exact: true })).toBeVisible();
        await expect(manual.getByRole('button', { name: 'Vis forklaring for Kravtype' })).toBeVisible();
        await manual.getByRole('button', { name: 'Legg til krav' }).focus();
        await page.keyboard.press('Enter');
        const text = page.locator('#manual-requirement-text');
        await expect(text).toHaveAttribute('aria-invalid', 'true');
        await expect(text).toBeFocused();
        await expect(text).toHaveAccessibleDescription(/.+/);
        await expectNoAxeViolations(page, 'form:has(#manual-requirement-text)', 'manual requirement form with errors');

        // Closing the form gives focus back to its toggle.
        await page.getByRole('button', { name: 'Skjul skjema' }).click();
        await expect(page.locator('#manual-requirement-text')).toHaveCount(0);

        // An approved requirement is kept (A42): it offers no «Slett», only the undecided one does.
        await expect(approvedCard(page).getByTestId('requirement-delete-button')).toHaveCount(0);

        // Delete asks first: focus goes to the question, and Avbryt brings it back to «Slett».
        const card = draftCard(page);
        const remove = card.getByTestId('requirement-delete-button');
        await remove.focus();
        await page.keyboard.press('Enter');
        const confirm = card.getByRole('group', { name: 'Slette dette kravet permanent?' });
        await expect(confirm).toBeFocused();
        await confirm.getByRole('button', { name: 'Avbryt' }).focus();
        await page.keyboard.press('Enter');
        await expect(card.getByTestId('requirement-delete-button')).toBeFocused();
    });

    test('AI workspace: generating an answer is announced at start and finish, and focus stays put (A11Y-045, -115, -116)', async ({ page }) => {
        let release;
        const answered = new Promise((resolve) => { release = resolve; });

        await page.route(GENERATE_URL, async (route) => {
            if (route.request().method() !== 'POST') {
                return route.fallback();
            }

            await answered;

            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    wiki_answer: {
                        coverage_status: 'full',
                        text: 'E2E a11y: generert svarutkast.',
                        sources: [],
                        sections: [],
                        generated_at: new Date().toISOString(),
                    },
                }),
            });
        });

        await page.goto(`/app/ai/${aiCaseId}`);
        const card = approvedCard(page);
        const generate = card.getByRole('button', { name: 'Lag svar' });
        await expect(generate).not.toHaveAttribute('aria-pressed', /.*/);

        await generate.focus();
        await page.keyboard.press('Enter');
        await expect(polite(page)).toHaveText('Svarutkastet genereres. Det kan ta opptil to minutter.');
        // Still focused while the list is locked: aria-disabled, not disabled.
        await expect(generate).toBeFocused();
        await expect(generate).toHaveAttribute('aria-disabled', 'true');

        release();
        await expect(polite(page)).toHaveText('Svarutkastet er klart.');
        await expect(page.getByTestId('wiki-answer-rendered')).toContainText('E2E a11y: generert svarutkast.');
        await expect(generate).toBeFocused();
    });

    test('AI workspace: a failed generation is an alert in the answer panel', async ({ page }) => {
        await page.route(GENERATE_URL, (route) => (route.request().method() === 'POST'
            ? route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'E2E a11y: generering feilet.' }) })
            : route.fallback()));

        await page.goto(`/app/ai/${aiCaseId}`);
        await approvedCard(page).getByRole('button', { name: 'Lag svar' }).click();

        // A server error's own text is technical and never shown (F12, validation work package 2):
        // the panel says in plain words that the answer was not generated.
        await expect(page.locator('#ai-answer-panel').getByRole('alert')).toHaveText('Kunne ikke generere Wiki-svar.');
    });

    test('AI workspace: editing, saving and copying the answer keep focus and say what happened (A11Y-014, -116)', async ({ page, context }) => {
        await context.grantPermissions(['clipboard-read', 'clipboard-write']);
        await page.goto(`/app/ai/${aiCaseId}`);
        await page.getByRole('button', { name: APPROVED_REQUIREMENT_TEXT }).click();

        const panel = page.getByTestId('wiki-answer-expert-draft');
        const edit = panel.getByRole('button', { name: 'Rediger' });
        await edit.focus();
        await page.keyboard.press('Enter');

        const editor = page.locator('#wiki-answer-editor');
        await expect(editor).toBeFocused();
        await expect(editor).toHaveAccessibleDescription(/Markdown/);
        await page.keyboard.press('End');
        await page.keyboard.type(' (E2E)');

        await panel.getByRole('button', { name: 'Lagre endring' }).click();
        await expect(polite(page)).toHaveText('Svaret er lagret.');
        await expect(panel.getByRole('button', { name: 'Rediger' })).toBeFocused();

        await panel.getByRole('button', { name: 'Kopier svar' }).click();
        await expect(polite(page)).toHaveText('Svaret er kopiert.');
        await expect(panel.getByRole('button', { name: /Kopier/ })).toBeFocused();
    });

    test('AI workspace: requirement extraction is spoken when it finishes, not on every reload (A11Y-112)', async ({ page }) => {
        await setDocumentStatus('processing');

        try {
            await page.goto(`/app/ai/${aiCaseId}`);

            const banner = page.getByRole('region', { name: 'Krav ekstraheres i bakgrunnen' });
            await expect(banner).toBeVisible();
            await expect(banner).not.toHaveAttribute('aria-live', /.*/);
            await expect(banner).not.toHaveAttribute('role', 'status');

            // Several polling reloads go by: the state the page opened in is not announced.
            await page.waitForTimeout(6500);
            await expect(polite(page)).toHaveText('');
            await expect(assertive(page)).toHaveText('');

            await setDocumentStatus('completed');
            await expect(banner).toHaveCount(0, { timeout: 10000 });
            await expect(polite(page)).toHaveText('Kravuttrekket er ferdig. 2 krav er hentet ut.');
        } finally {
            await setDocumentStatus('completed');
        }
    });

    test('the case page and the AI workspace reflow at 320 and 640 px without sideways scrolling (1.4.10)', async ({ page }) => {
        for (const width of [320, 640]) {
            await page.setViewportSize({ width, height: 720 });

            await page.goto(`/app/notices/saved/${goNoGoCaseId}`);
            await expect(page.getByRole('region', { name: 'Beslutningsvurdering' })).toBeVisible();
            expect(await sidewaysOverflow(page), `case page at ${width} px`).toEqual([]);

            // The AI workspace with everything open that can widen it: the document list, an answer
            // and the manual requirement form.
            await page.goto(`/app/ai/${aiCaseId}`);
            await page.locator('[aria-controls="case-documents-list"]').click();
            await page.getByRole('button', { name: APPROVED_REQUIREMENT_TEXT }).click();
            await expect(page.getByTestId('wiki-answer-expert-draft')).toBeVisible();
            await page.getByRole('button', { name: 'Legg til krav', exact: true }).first().click();
            await expect(page.locator('#manual-requirement-text')).toBeVisible();
            expect(await sidewaysOverflow(page), `AI workspace at ${width} px`).toEqual([]);
        }
    });
});
