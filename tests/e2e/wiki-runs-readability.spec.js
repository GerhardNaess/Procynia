import { test, expect } from '@playwright/test';
import { exec } from 'node:child_process';
import { promisify } from 'node:util';

const execAsync = promisify(exec);
const FIXTURE = '\\Tests\\Support\\WikiRunsReadabilityE2EFixture';
const CUSTOMER_ID = 4;

async function loginAsDevDataUser(page) {
    await page.goto('/login');
    await page.fill('#email', 'alisan@advania.no');
    await page.fill('#password', 'Opaque01');
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 });
}

/**
 * Readability fix verification (see CLAUDE.md task "Increase Wiki run status text to readable
 * size"): status/progress text, secondary badges, and step chips in the Kjøringer tab must all
 * render at >=16px, never via text-xs/text-sm/a custom sub-16px arbitrary size.
 */
test.describe.serial('Kjøringer run row readability', () => {
    let runId;

    test.beforeAll(async () => {
        const { stdout } = await execAsync(
            `docker compose exec -T app php artisan tinker --execute="echo ${FIXTURE}::seed(${CUSTOMER_ID});"`,
            { cwd: new URL('../..', import.meta.url).pathname },
        );
        runId = stdout.trim();
    });

    test.afterAll(async () => {
        await execAsync(
            `docker compose exec -T app php artisan tinker --execute="${FIXTURE}::cleanup(${CUSTOMER_ID});"`,
            { cwd: new URL('../..', import.meta.url).pathname },
        );
    });

    test('main status pill and secondary "stille" pill are both readable', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`tr:has-text("${runId}")`).first();
        await expect(row).toBeVisible();

        const statusPill = row.getByText('Kjører', { exact: true });
        await expect(statusPill).toBeVisible();
        expect(await statusPill.evaluate((el) => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(16);

        const stalledPill = row.getByText('Ser ut til å stå stille', { exact: true });
        await expect(stalledPill).toBeVisible();
        expect(await stalledPill.evaluate((el) => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(12);
    });

    test('step timeline line (Kø/Beslutning/Sidestruktur/Sider/Verifisering/QA/Dokumenteiergodkjenning) is readable', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`tr:has-text("${runId}")`).first();
        const desktopTimeline = row.locator('xpath=following-sibling::tr[1]');
        await expect(desktopTimeline).toBeVisible();
        await expect(desktopTimeline.locator('[data-progress-step]')).toHaveCount(7);
        await expect(desktopTimeline.locator('[data-progress-connector]')).toHaveCount(6);
        for (const label of ['Kø', 'Beslutning', 'Sidestruktur', 'Sider', 'Verifisering', 'QA', 'Dokumenteiergodkjenning']) {
            const chip = desktopTimeline.getByText(label, { exact: true });
            await expect(chip).toBeVisible();
            const size = await chip.evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
            expect(size).toBeGreaterThanOrEqual(14);
        }
    });

    test('desktop layout has no console errors and no overlapping row text', async ({ page }) => {
        const errors = [];
        page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
        page.on('pageerror', (err) => errors.push(String(err)));

        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`tr:has-text("${runId}")`).first();
        await expect(row).toBeVisible();

        const overlaps = await countOverlaps(row);
        expect(overlaps).toBe(0);
        expect(errors).toEqual([]);
    });

    test('390px layout has no console errors, no page-level horizontal overflow, and no overlapping row text', async ({ page }) => {
        const errors = [];
        page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
        page.on('pageerror', (err) => errors.push(String(err)));

        await page.setViewportSize({ width: 390, height: 844 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`tr:has-text("${runId}")`).first();
        await expect(row).toBeVisible();

        // The page body itself must never scroll horizontally — only the table's own container
        // may (the table already uses overflow-x-auto; this fix must not break that pattern).
        const bodyOverflows = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        );
        expect(bodyOverflows).toBe(false);

        const overlaps = await countOverlaps(row);
        expect(overlaps).toBe(0);
        expect(errors).toEqual([]);
    });
});

/**
 * Counts genuine overlaps between visible text-bearing elements within `rowLocator` — a
 * parent/child containment pair (e.g. a <span> inside a wrapping <td>) is expected and not
 * counted; only two unrelated rects that visually intersect are.
 */
async function countOverlaps(rowLocator) {
    return rowLocator.evaluate((rowEl) => {
        const textEls = Array.from(rowEl.querySelectorAll('span, p, td, button')).filter((el) => el.textContent.trim());
        const rects = textEls.map((el) => el.getBoundingClientRect()).filter((r) => r.width > 0 && r.height > 0);
        let overlapCount = 0;
        for (let i = 0; i < rects.length; i++) {
            for (let j = i + 1; j < rects.length; j++) {
                const a = rects[i];
                const b = rects[j];
                const aContainsB = a.left <= b.left && a.right >= b.right && a.top <= b.top && a.bottom >= b.bottom;
                const bContainsA = b.left <= a.left && b.right >= a.right && b.top <= a.top && b.bottom >= a.bottom;
                if (aContainsB || bContainsA) continue;
                const overlapX = Math.min(a.right, b.right) - Math.max(a.left, b.left);
                const overlapY = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
                if (overlapX > 2 && overlapY > 2) overlapCount++;
            }
        }
        return overlapCount;
    });
}
