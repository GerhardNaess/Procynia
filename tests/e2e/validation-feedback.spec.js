import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';
import { DESKTOP } from './helpers/readability.js';
import { tinker } from './helpers/risk.js';

/**
 * Validation work package 2 — what a refused save looks like in a real browser: the person's work
 * is kept, the message stands at the field it is about, and it is Norwegian and plain.
 *
 * Tests\Support\A11yTenderAiE2EFixture seeds the Go/No-go and AI cases and removes them afterwards.
 * Every save here is refused by the server or intercepted, so nothing outlives the test: the user
 * form is sent with passwords that do not match, the Go/No-go reasoning is too long, the upload is
 * a .doc, and the answer save is answered by a fake server error.
 */

const FIXTURE = '\\Tests\\Support\\A11yTenderAiE2EFixture';
const CUSTOMER_ID = 2;
const APPROVED_REQUIREMENT_TEXT = 'E2E a11y: beskriv leveransemodellen.';
const ANSWER_URL = /\/app\/ai\/\d+\/requirements\/\d+\/wiki-answer$/;

let goNoGoCaseId = null;
let aiCaseId = null;

test.describe.serial('Validation feedback keeps the work and says what to correct', () => {
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

    test('Users: a refused create keeps what was entered, asks only for the password again', async ({ page }) => {
        await page.goto('/app/users/create');

        await page.getByRole('textbox', { name: 'Navn' }).fill('E2E Validering Bruker');
        await page.getByRole('textbox', { name: 'E-post' }).fill('e2e-validering@example.test');
        await page.getByRole('checkbox', { name: 'QA (kvalitetssikring)' }).check();
        await page.getByRole('textbox', { name: 'Passord', exact: true }).fill('Langt-passord-1');
        await page.getByRole('textbox', { name: 'Bekreft passord' }).fill('Et-annet-passord-2');
        await page.getByRole('button', { name: 'Opprett bruker' }).click();
        await page.waitForURL(/\/app\/users\/create/);

        // Refused either by the passwords or by the E2E customer's user limit; which rule refuses does
        // not matter here, only that what was entered survives it.
        await expect(page.locator('#user-name-error, #user-password-error').first()).toBeVisible();
        await expect(page.getByRole('textbox', { name: 'Navn' })).toHaveValue('E2E Validering Bruker');
        await expect(page.getByRole('textbox', { name: 'E-post' })).toHaveValue('e2e-validering@example.test');
        await expect(page.getByRole('checkbox', { name: 'QA (kvalitetssikring)' })).toBeChecked();
        await expect(page.getByRole('textbox', { name: 'Passord', exact: true })).toHaveValue('');
        await expect(page.getByRole('textbox', { name: 'Bekreft passord' })).toHaveValue('');
        await expect(page.getByText('Skriv inn passordet på nytt.', { exact: false })).toBeVisible();
    });

    test('Go/No-go: an overlong reasoning is marked on its criterion, and every answer stays', async ({ page }) => {
        await page.goto(`/app/notices/saved/${goNoGoCaseId}`);

        const assessment = page.getByRole('region', { name: 'Beslutningsvurdering' });
        const titles = await assessment.locator('[id^="go-no-go-criterion-"][id$="-title"]').allTextContents();
        const [first, second] = titles;
        const firstComment = assessment.getByRole('textbox', { name: `Begrunnelse for ${first} (valgfritt)` });
        const secondComment = assessment.getByRole('textbox', { name: `Begrunnelse for ${second} (valgfritt)` });

        await secondComment.fill('E2E: kort begrunnelse.');
        await firstComment.fill('a'.repeat(2001));
        // The length shows near the limit, without stopping the typing or cutting the text.
        await expect(assessment.getByText('2 001 av maks 2 000 tegn')).toBeVisible();

        await assessment.getByRole('button', { name: 'Lagre vurdering' }).click();

        await expect(firstComment).toHaveAttribute('aria-invalid', 'true');
        await expect(firstComment).toHaveAccessibleDescription(/Begrunnelsen kan ha maks 2000 tegn/);
        await expect(assessment.getByRole('alert')).toContainText(`Rett opp det som er markert ved: «${first}»`);
        await expect(firstComment).toHaveValue('a'.repeat(2001));
        await expect(secondComment).toHaveValue('E2E: kort begrunnelse.');
        await expect(secondComment).not.toHaveAttribute('aria-invalid', 'true');

        // Correcting the reasoning takes the mark away at once.
        await firstComment.fill('E2E: rettet.');
        await expect(firstComment).not.toHaveAttribute('aria-invalid', 'true');
    });

    test('AI: the picker offers only what can be read, and a .doc is named with what to do', async ({ page }) => {
        await page.goto(`/app/ai/${aiCaseId}`);

        const picker = page.locator('#ai-documents');
        await expect(picker).toHaveAttribute('accept', '.pdf,.docx,.xlsx');
        await expect(picker).toHaveAccessibleDescription(/PDF, Word \(\.docx\) og Excel \(\.xlsx\)/);

        await picker.setInputFiles({ name: 'e2e-gammel.doc', mimeType: 'application/msword', buffer: Buffer.from('E2E') });
        await page.getByRole('button', { name: /Last opp/ }).click();

        await expect(page.locator('#ai-documents-error')).toHaveText(/«e2e-gammel\.doc» kan ikke lastes opp\. Tillatte filtyper er PDF, Word \(\.docx\) og Excel \(\.xlsx\)/);
    });

    test('AI: a failed answer save says so in plain words and keeps the edit', async ({ page }) => {
        await page.route(ANSWER_URL, (route) => (route.request().method() === 'PATCH'
            ? route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'Server Error' }) })
            : route.fallback()));

        await page.goto(`/app/ai/${aiCaseId}`);
        await page.getByRole('button', { name: APPROVED_REQUIREMENT_TEXT }).click();

        const panel = page.getByTestId('wiki-answer-expert-draft');
        await panel.getByRole('button', { name: 'Rediger' }).click();

        const editor = page.locator('#wiki-answer-editor');
        await editor.press('End');
        await editor.type(' (E2E ulagret)');
        await panel.getByRole('button', { name: 'Lagre endring' }).click();

        const alert = page.locator('#ai-answer-panel').getByRole('alert');
        await expect(alert).toHaveText('Svaret ble ikke lagret. Teksten din er beholdt i feltet. Prøv igjen.');
        await expect(alert).not.toContainText('Request failed');
        await expect(alert).not.toContainText('Server Error');
        await expect(editor).toHaveValue(/\(E2E ulagret\)/);
    });

    test('AI: an answer the server refuses is marked at the editor', async ({ page }) => {
        await page.route(ANSWER_URL, (route) => (route.request().method() === 'PATCH'
            ? route.fulfill({
                status: 422,
                contentType: 'application/json',
                body: JSON.stringify({ message: 'The given data was invalid.', errors: { answer_text: ['Svaret kan ha maks 20000 tegn.'] } }),
            })
            : route.fallback()));

        await page.goto(`/app/ai/${aiCaseId}`);
        await page.getByRole('button', { name: APPROVED_REQUIREMENT_TEXT }).click();

        const panel = page.getByTestId('wiki-answer-expert-draft');
        await panel.getByRole('button', { name: 'Rediger' }).click();

        const editor = page.locator('#wiki-answer-editor');
        await editor.press('End');
        await editor.type(' (E2E)');
        await panel.getByRole('button', { name: 'Lagre endring' }).click();

        await expect(editor).toHaveAttribute('aria-invalid', 'true');
        await expect(editor).toHaveAccessibleDescription(/Svaret kan ha maks 20000 tegn\./);
        await expect(page.locator('#wiki-answer-editor-error')).toHaveText('Svaret kan ha maks 20000 tegn.');
    });
});
