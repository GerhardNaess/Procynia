import { test, expect } from '@playwright/test';
import { exec } from 'node:child_process';
import { promisify } from 'node:util';

const execAsync = promisify(exec);
const FIXTURE = '\\Tests\\Support\\WikiAnswerMarkdownTableE2EFixture';
const CUSTOMER_ID = 4;
const REQUIREMENT_TEXT = 'E2E: beskriv samhandlingsmodellen med tabell.';

/**
 * The Wiki answer panel used to show `answer_text` in a raw <textarea>, so a Markdown table
 * reached the user as literal "| Element | Beskrivelse |" text. This verifies the display/edit
 * split against the real app: rendered by default, textarea only on demand.
 *
 * The fixture seeds its own case, requirement and stored answer. An earlier version of this spec
 * opened a real generated answer instead, which meant it asserted whatever the answer engine had
 * last produced — once that answer changed, a working renderer started failing a rendering test.
 * Nothing here touches real customer content.
 */
let caseUrl = null;

async function loginAsDevDataUser(page) {
    await page.goto('/login');
    await page.fill('#email', 'alisan@advania.no');
    await page.fill('#password', 'Opaque01');
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 });
}

async function openSeededRequirement(page) {
    await loginAsDevDataUser(page);
    await page.goto(caseUrl);
    await page.getByText(REQUIREMENT_TEXT).first().click();
    await expect(page.getByTestId('wiki-answer-expert-draft')).toBeVisible();
}

test.describe.serial('Wiki answer Markdown rendering', () => {
    test.beforeAll(async () => {
        const { stdout } = await execAsync(
            `docker compose exec -T app php artisan tinker --execute="echo ${FIXTURE}::seed(${CUSTOMER_ID});"`,
            { cwd: new URL('../..', import.meta.url).pathname },
        );

        const savedNoticeId = stdout.trim().match(/(\d+)\s*$/)?.[1];
        expect(savedNoticeId, `fixture did not return a saved notice id: ${stdout}`).toBeTruthy();
        caseUrl = `/app/ai/${savedNoticeId}`;
    });

    test.afterAll(async () => {
        await execAsync(
            `docker compose exec -T app php artisan tinker --execute="${FIXTURE}::cleanup(${CUSTOMER_ID});"`,
            { cwd: new URL('../..', import.meta.url).pathname },
        );
    });

    test('the stored Markdown table is shown as a real table, not as pipe characters', async ({ page }) => {
        await openSeededRequirement(page);

        const rendered = page.getByTestId('wiki-answer-rendered');
        await expect(rendered).toBeVisible();
        await expect(rendered.locator('table')).toHaveCount(1);
        await expect(rendered.locator('th', { hasText: 'Element' })).toBeVisible();
        await expect(rendered.locator('th', { hasText: 'Beskrivelse' })).toBeVisible();
        await expect(rendered.locator('td', { hasText: 'Første rad' })).toBeVisible();
        await expect(rendered).not.toContainText('| Element | Beskrivelse |');
    });

    test('headings, prose and lists around the table render as themselves', async ({ page }) => {
        await openSeededRequirement(page);

        const rendered = page.getByTestId('wiki-answer-rendered');
        await expect(rendered.getByText('Hovedelementer')).toBeVisible();
        await expect(rendered.locator('li', { hasText: 'Roller avklares ved oppstart.' })).toBeVisible();
        await expect(rendered.locator('p').first()).toContainText('tydelig ansvarsdeling');
        await expect(rendered).not.toContainText('## Hovedelementer');
    });

    test('the raw Markdown editor opens on demand and closes again without saving', async ({ page }) => {
        await openSeededRequirement(page);

        const panel = page.getByTestId('wiki-answer-expert-draft');
        await expect(panel.locator('textarea')).toHaveCount(0);

        await panel.getByRole('button', { name: 'Rediger' }).click();

        const editor = panel.locator('textarea');
        await expect(editor).toBeVisible();
        // The editor holds the Markdown source itself — that is what keeps the syntax intact on save.
        await expect(editor).toHaveValue(/\| Element \| Beskrivelse \|/);
        await expect(editor).toHaveValue(/## Hovedelementer/);
        await expect(page.getByTestId('wiki-answer-rendered')).toHaveCount(0);

        await panel.getByRole('button', { name: 'Avbryt' }).click();

        await expect(panel.locator('textarea')).toHaveCount(0);
        await expect(page.getByTestId('wiki-answer-rendered').locator('table')).toHaveCount(1);
    });
});
