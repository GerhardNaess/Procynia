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
 * Returns every element with a computed font-size under 16px inside `root`, keyed by its own
 * text content — used to prove the Kjøringer readability fix (product rule: all ordinary
 * reading text in this view must be >=16px).
 */
async function smallTextIn(locator) {
    return locator.evaluate((root) => {
        const results = [];
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode(node) {
                return node.textContent.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
            },
        });
        let node;
        while ((node = walker.nextNode())) {
            const el = node.parentElement;
            if (!el) continue;
            const size = parseFloat(getComputedStyle(el).fontSize);
            if (size < 16) {
                results.push({ text: node.textContent.trim().slice(0, 60), size, cls: el.className });
            }
        }
        return results;
    });
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

    test('no text in the Kjøringer table/panels renders under 16px on desktop', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const runsRoot = page.locator('table').locator('xpath=ancestor::div[contains(@class,"space-y-4")][1]');
        await expect(runsRoot.locator(`tr:has-text("${runId}")`).first()).toBeVisible();

        expect(await smallTextIn(runsRoot)).toEqual([]);
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
        expect(await stalledPill.evaluate((el) => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(16);
    });

    test('step timeline chips (Kø/Beslutning/Anvendelse/Sider/Verifisering/QA/Dokumenteier) are readable', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`tr:has-text("${runId}")`).first();
        for (const label of ['Kø', 'Beslutning', 'Anvendelse', 'Sider', 'Verifisering', 'QA', 'Dokumenteier']) {
            const chip = row.getByText(label, { exact: true });
            await expect(chip).toBeVisible();
            const size = await chip.evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
            expect(size).toBeGreaterThanOrEqual(16);
        }
    });

    test('no text-xs or text-sm utility classes remain on Kjøringer-tab elements', async ({ page }) => {
        await loginAsDevDataUser(page);
        await page.goto('/app/wiki?tab=runs');

        const runsRoot = page.locator('table').locator('xpath=ancestor::div[contains(@class,"space-y-4")][1]');
        const offendingCount = await runsRoot.evaluate((root) => root.querySelectorAll(
            '[class*="text-xs"], [class~="text-sm"]',
        ).length);

        expect(offendingCount).toBe(0);
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
