import { expect, test } from '@playwright/test';
import os from 'node:os';
import path from 'node:path';
import { loginAsAdmin, SUPER_ADMIN } from './helpers/auth.js';
import { expectNoAxeViolations } from './helpers/a11y.js';
import { DESKTOP, sidewaysOverflow } from './helpers/readability.js';
import { tinker } from './helpers/risk.js';

/**
 * WCAG Fase 6 — the Filament admin panel (/admin), in a real browser (docs/wcag-accessibility-plan.md §8).
 *
 * The panel runs in dark mode by default (it follows the system setting), so every surface is checked in
 * both schemes. axe runs over the whole page — Filament's own chrome included — and over the custom
 * Blade views; nothing is excluded.
 *
 * Tests\Support\A11yAdminE2EFixture adds rows of its own (a runbook with two attachments, two unread
 * admin notifications, and a help text for Varsler when the environment has none), so marking a
 * notification as read never touches a real one. A rejected form submit writes nothing.
 */

const FIXTURE = '\\Tests\\Support\\A11yAdminE2EFixture';

const CUSTOM_PAGES = [
    '/admin/admin-notifications',
    '/admin/ai-erfaring',
    '/admin/ai-forbruk',
    '/admin/ai-profitability',
    '/admin/ai-usage-capacity',
    '/admin/backup-recovery',
    '/admin/billing-overview',
    '/admin/csv-import',
    '/admin/doffin-automatic-import',
    '/admin/doffin-harvest',
    '/admin/doffin-supplier-harvest',
    '/admin/incidents',
    '/admin/monitoring',
    '/admin/queue-scheduler',
    '/admin/system-status',
];

// Filament throttles its login form (5 attempts a minute): log in once and reuse the session.
const STATE = path.join(os.tmpdir(), 'procynia-a11y-admin-state.json');

let runbookId = 0;
let customerId = 0;

/** Switches Filament's theme (stored in localStorage «theme») and the media query together, then reloads. */
async function useScheme(page, scheme) {
    await page.emulateMedia({ colorScheme: scheme });
    await page.evaluate((value) => localStorage.setItem('theme', value), scheme);
    await page.reload();
    await expect(page.locator('html')).toHaveClass(scheme === 'dark' ? /\bdark\b/ : /^(?!.*\bdark\b)/);
}

async function visit(page, url) {
    // A Vite full reload (another file saved in the dev server) can abort a navigation; try once more.
    await page.goto(url).catch(async (error) => {
        if (! String(error).includes('ERR_ABORTED')) {
            throw error;
        }

        await page.goto(url);
    });
    await page.waitForLoadState('networkidle').catch(() => {});
}

/** The rendered contrast of an element's text against the first opaque background behind it. */
async function renderedContrast(locator) {
    return locator.evaluate((element) => {
        const canvas = document.createElement('canvas');
        canvas.width = 1;
        canvas.height = 1;
        const context = canvas.getContext('2d', { willReadFrequently: true });
        const rgba = (color) => {
            context.clearRect(0, 0, 1, 1);
            context.fillStyle = color;
            context.fillRect(0, 0, 1, 1);

            return [...context.getImageData(0, 0, 1, 1).data];
        };
        const luminance = ([r, g, b]) => {
            const channel = (value) => {
                const c = value / 255;

                return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
        };

        let node = element;
        let background = [255, 255, 255, 255];

        while (node) {
            const color = rgba(getComputedStyle(node).backgroundColor);

            if (color[3] === 255) {
                background = color;
                break;
            }

            node = node.parentElement;
        }

        if (! node) {
            background = rgba(getComputedStyle(document.body).backgroundColor);
        }

        const foreground = luminance(rgba(getComputedStyle(element).color));
        const back = luminance(background);
        const [light, dark] = foreground > back ? [foreground, back] : [back, foreground];

        return Math.round(((light + 0.05) / (dark + 0.05)) * 100) / 100;
    });
}

test.describe.serial('WCAG Fase 6 — Filament admin', () => {
    test.use({ storageState: STATE });

    test.beforeAll(async ({ browser }) => {
        const context = await browser.newContext({ storageState: undefined });
        const loginPage = await context.newPage();
        await loginAsAdmin(loginPage, SUPER_ADMIN.email, SUPER_ADMIN.password);
        await context.storageState({ path: STATE });
        await context.close();

        const { stdout } = await tinker(`echo ${FIXTURE}::seed();`);
        runbookId = Number(stdout.trim().split('\n').pop());
        const customer = await tinker("echo \\App\\Models\\Customer::where('slug', 'e2e-test-customer')->value('id');");
        customerId = Number(customer.stdout.trim());
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup();`);
    });

    test.beforeEach(async ({ page }) => {
        await page.setViewportSize(DESKTOP);
    });

    for (const scheme of ['light', 'dark']) {
        test(`1. no axe violations on the custom admin pages (${scheme}) — A11Y-054, -055, -056, -125`, async ({ page }) => {
            test.setTimeout(240_000);
            await visit(page, '/admin');
            await useScheme(page, scheme);

            for (const url of ['/admin', ...CUSTOM_PAGES, `/admin/operational-runbooks/${runbookId}`]) {
                await visit(page, url);
                await expectNoAxeViolations(page, undefined, `${url} (${scheme})`);

                // Livewire's <!--[if BLOCK]> markers must never land inside a tag (they break its attributes).
                expect(await page.evaluate(() => [...document.querySelectorAll('*')]
                    .flatMap((element) => [...element.attributes].map((attribute) => attribute.name))
                    .filter((name) => name.includes('<!--') || name.includes('[if'))), `${url}: broken attributes`).toEqual([]);
            }
        });

        test(`2. no axe violations on resource lists, forms and a customer's own pages (${scheme})`, async ({ page }) => {
            test.setTimeout(180_000);
            await visit(page, '/admin');
            await useScheme(page, scheme);

            for (const url of [
                '/admin/customers',
                `/admin/customers/${customerId}/edit`,
                `/admin/customers/${customerId}/ai-control`,
                `/admin/customers/${customerId}/billing`,
                '/admin/users',
                '/admin/users/create',
                '/admin/operational-runbooks',
                '/admin/operational-deviations/create',
            ]) {
                await visit(page, url);
                await expectNoAxeViolations(page, undefined, `${url} (${scheme})`);
            }
        });
    }

    test('3. dark mode is real on the custom views: dark cards, light text (A11Y-056)', async ({ page }) => {
        await visit(page, '/admin/system-status');
        await useScheme(page, 'dark');

        const card = page.locator('.fi-main section').first();
        const background = await card.evaluate((element) => getComputedStyle(element).backgroundColor);
        expect(background, 'the first status card is not white in dark mode').not.toMatch(/^rgb\(255, 255, 255\)$/);

        const heading = page.getByRole('heading', { level: 2 }).first();
        expect(await renderedContrast(heading)).toBeGreaterThanOrEqual(4.5);
    });

    test('4. primary-coloured link text reaches 4.5:1 in both schemes (A11Y-055)', async ({ page }) => {
        await visit(page, `/admin/operational-runbooks/${runbookId}`);

        for (const scheme of ['light', 'dark']) {
            await useScheme(page, scheme);
            const link = page.getByRole('link', { name: 'Last ned e2e-uu-gjenoppretting.pdf' });
            await expect(link).toBeVisible();
            expect(await renderedContrast(link), `download link (${scheme})`).toBeGreaterThanOrEqual(4.5);
        }
    });

    test('5. runbook attachments: a real disclosure button opens the description (A11Y-053)', async ({ page }) => {
        await visit(page, `/admin/operational-runbooks/${runbookId}`);

        const table = page.getByRole('table', { name: 'Vedlegg' });
        await expect(table).toBeVisible();
        await expect(table.getByRole('rowheader', { name: 'e2e-uu-gjenoppretting.pdf' })).toBeVisible();

        // Only the attachment with a description has the button; its name says which attachment it is.
        const toggles = page.locator('[data-testid="runbook-attachment-description-toggle"]');
        await expect(toggles).toHaveCount(1);
        const toggle = toggles.first();
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(toggle).toHaveAccessibleName(/e2e-uu-gjenoppretting\.pdf/);

        const panelId = await toggle.getAttribute('aria-controls');
        const panel = page.locator(`[id="${panelId}"]`);
        await expect(panel).toBeHidden();

        // Keyboard: focus the button and press Enter / Space.
        await toggle.focus();
        await page.keyboard.press('Enter');
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expect(panel).toBeVisible();
        await expect(panel).toContainText('Trinnvis gjenoppretting av databasen');
        await expect(toggle).toBeFocused();

        await page.keyboard.press('Space');
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(panel).toBeHidden();

        // The row click is kept as a mouse shortcut, and stays in step with the button.
        await page.getByRole('rowheader', { name: 'e2e-uu-gjenoppretting.pdf' }).click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expectNoAxeViolations(page, '.fi-main', 'runbook with the description open');
    });

    test('6. date filters on AI-forbruk and AI-lønnsomhet are labelled (A11Y-054)', async ({ page }) => {
        for (const url of ['/admin/ai-forbruk', '/admin/ai-profitability']) {
            await visit(page, url);
            await expect(page.getByLabel('Fra', { exact: true })).toHaveAttribute('type', 'date');
            await expect(page.getByLabel('Til', { exact: true })).toHaveAttribute('type', 'date');
        }
    });

    test('7. notifications: severity in words, named buttons, focus kept after «Merk lest» (A11Y-125)', async ({ page }) => {
        await visit(page, '/admin/admin-notifications');

        const critical = page.getByRole('listitem').filter({ hasText: 'E2E UU kritisk varsel' });
        const warning = page.getByRole('listitem').filter({ hasText: 'E2E UU advarsel' });
        await expect(critical.locator('[data-notification-severity="critical"]')).toHaveText('Kritisk');
        await expect(warning.locator('[data-notification-severity="warning"]')).toHaveText('Advarsel');

        const markCritical = critical.getByRole('button', { name: 'Merk lest' });
        await expect(markCritical).toHaveAccessibleDescription('E2E UU kritisk varsel');

        await markCritical.focus();
        await page.keyboard.press('Enter');
        await expect(page.getByRole('listitem').filter({ hasText: 'E2E UU kritisk varsel' }).getByRole('button')).toHaveCount(0);

        // Focus moved to the next notification's button (here: the fixture's warning), never to <body>.
        await expect(warning.getByRole('button', { name: 'Merk lest' })).toBeFocused();
        expect(await page.evaluate(() => document.activeElement === document.body)).toBe(false);
    });

    test('8. a Filament modal: focus moves in, Escape closes it and focus returns to «Hjelp»', async ({ page }) => {
        await visit(page, '/admin/admin-notifications');

        const help = page.getByRole('button', { name: 'Hjelp' });
        await help.focus();
        await page.keyboard.press('Enter');

        // Filament's role="dialog" wrapper has no box of its own; its window is what is shown.
        const dialog = page.locator('.fi-modal-open[role="dialog"]');
        await expect(dialog.locator('.fi-modal-window')).toBeVisible();
        await expect(dialog).toHaveAttribute('aria-modal', 'true');
        await expect(dialog).toHaveAccessibleName(/.+/);
        await expect.poll(() => dialog.evaluate((element) => element.contains(document.activeElement))).toBe(true);
        await expectNoAxeViolations(page, '.fi-modal-window', 'help modal');

        await page.keyboard.press('Escape');
        await expect(dialog.locator('.fi-modal-window')).toBeHidden();
        await expect(help).toBeFocused();
    });

    test('9. a rejected submit moves focus to the first invalid field (A11Y-212)', async ({ page }) => {
        await visit(page, '/admin/operational-deviations/create');

        const form = page.locator('.fi-main form').first();
        const submit = form.locator('button[type="submit"]').first();

        // Required fields carry `required`, so the browser's own check stops the submit first and
        // focuses the first empty field itself.
        await submit.focus();
        await page.keyboard.press('Enter');
        await expect.poll(() => page.evaluate(() => document.activeElement?.matches(':invalid') ?? false)).toBe(true);

        // The server-side rejection (the path every rule the browser cannot check takes): without the
        // browser check, Filament scrolls to the first error and the render hook moves focus there.
        await form.evaluate((element) => { element.noValidate = true; });
        await submit.focus();
        await page.keyboard.press('Enter');

        const invalid = page.locator('[data-validation-error]').first();
        await expect(invalid).toBeVisible();

        await expect.poll(() => page.evaluate(() => {
            const active = document.activeElement;

            return Boolean(active && active !== document.body && active.closest('[data-field-wrapper]')?.querySelector('[data-validation-error]'));
        }), { message: 'focus is in the first field with an error' }).toBe(true);

        await expectNoAxeViolations(page, '.fi-main', 'deviation form with errors');
    });

    test('10. system status: progress bars, severity words and a pause control (A11Y-125)', async ({ page }) => {
        await visit(page, '/admin/system-status');

        const bars = page.getByRole('progressbar');
        if (await bars.count() > 0) {
            const first = bars.first();
            await expect(first).toHaveAttribute('aria-valuemin', '0');
            await expect(first).toHaveAttribute('aria-valuemax', '100');
            await expect(first).toHaveAccessibleName(/.+/);
        }

        const toggle = page.locator('[data-scheduler-live-toggle]');
        await expect(toggle).toBeVisible();
        const before = await page.locator('[data-scheduler-countdown]').allTextContents();
        await toggle.click();
        await page.waitForTimeout(2_500);
        expect(await page.locator('[data-scheduler-countdown]').allTextContents(), 'paused countdown does not move').toEqual(before);
        await toggle.click();

        // Section headings are real headings.
        await expect(page.getByRole('heading', { level: 2 }).first()).toBeVisible();
    });

    test('11. 320 px: the custom pages do not scroll sideways', async ({ page }) => {
        test.setTimeout(180_000);
        await page.setViewportSize({ width: 320, height: 720 });

        const overflowing = {};

        for (const url of [...CUSTOM_PAGES, `/admin/operational-runbooks/${runbookId}`]) {
            await visit(page, url);
            const found = await sidewaysOverflow(page);

            if (found.length > 0) {
                overflowing[url] = found;
            }
        }

        expect(overflowing, 'pages that scroll sideways at 320 px').toEqual({});
    });
});
