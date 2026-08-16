import { test, expect } from '@playwright/test';

/**
 * The Wiki answer panel showed `answer_text` in a raw <textarea>, so a Markdown table the answer
 * engine produced reached the user as literal "| Element | Beskrivelse |" text. This verifies the
 * display/edit split against the real app: rendered by default, textarea only on demand.
 *
 * Uses the existing dev data (saved notice 1, requirement 1 — "Beskriv Leverandørens
 * samhandlingsmodell.", whose stored answer contains a Markdown table). It reads only; it never
 * saves, so the stored answer is not modified.
 */
const CASE_URL = '/app/ai/1';

async function loginAsDevDataUser(page) {
    await page.goto('/login');
    await page.fill('#email', 'alisan@advania.no');
    await page.fill('#password', 'Opaque01');
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 });
}

async function openRequirementWithTableAnswer(page) {
    await loginAsDevDataUser(page);
    await page.goto(CASE_URL);
    await page.getByText('Beskriv Leverandørens samhandlingsmodell.').first().click();
    await expect(page.getByTestId('wiki-answer-expert-draft')).toBeVisible();
}

test.describe.serial('Wiki answer Markdown rendering', () => {
    test('the stored Markdown table is shown as a real table, not as pipe characters', async ({ page }) => {
        await openRequirementWithTableAnswer(page);

        const rendered = page.getByTestId('wiki-answer-rendered');
        await expect(rendered).toBeVisible();
        // The stored answer holds three Markdown tables; each must have become a real one.
        await expect(rendered.locator('table')).toHaveCount(3);
        await expect(rendered.locator('th', { hasText: 'Element' })).toBeVisible();
        await expect(rendered.locator('th', { hasText: 'Beskrivelse' })).toBeVisible();
        await expect(rendered.locator('td', { hasText: 'Organisering' }).first()).toBeVisible();
        await expect(rendered).not.toContainText('| Element | Beskrivelse |');
    });

    test('the prose around the table is still there and readable', async ({ page }) => {
        await openRequirementWithTableAnswer(page);

        const paragraphs = page.getByTestId('wiki-answer-rendered').locator('p');
        expect(await paragraphs.count()).toBeGreaterThan(0);
    });

    test('the raw Markdown editor opens on demand and closes again without saving', async ({ page }) => {
        await openRequirementWithTableAnswer(page);

        const panel = page.getByTestId('wiki-answer-expert-draft');
        await expect(panel.locator('textarea')).toHaveCount(0);

        await panel.getByRole('button', { name: 'Rediger' }).click();

        const editor = panel.locator('textarea');
        await expect(editor).toBeVisible();
        // The editor holds the Markdown source itself — that is what keeps the syntax intact on save.
        await expect(editor).toHaveValue(/\| Element\s*\| Beskrivelse\s*\|/);
        await expect(page.getByTestId('wiki-answer-rendered')).toHaveCount(0);

        await panel.getByRole('button', { name: 'Avbryt' }).click();

        await expect(panel.locator('textarea')).toHaveCount(0);
        await expect(page.getByTestId('wiki-answer-rendered').locator('table')).toHaveCount(3);
    });
});
