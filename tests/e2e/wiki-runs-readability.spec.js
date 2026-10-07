import { test, expect } from '@playwright/test';
import { tinker } from './helpers/risk.js';
import { e2eWikiCustomerId, loginAsWikiReader } from './helpers/wiki.js';

const FIXTURE = '\\Tests\\Support\\WikiRunsReadabilityE2EFixture';
let CUSTOMER_ID;

/**
 * Readability fix verification (see CLAUDE.md task "Increase Wiki run status text to readable
 * size"): status/progress text, secondary badges, and step chips in the Kjøringer tab must all
 * render at >=16px, never via text-xs/text-sm/a custom sub-16px arbitrary size.
 */
test.describe.serial('Kjøringer run row readability', () => {
    let runId;

    test.beforeAll(async () => {
        CUSTOMER_ID = await e2eWikiCustomerId();
        const { stdout } = await tinker(`echo ${FIXTURE}::seed(${CUSTOMER_ID});`);
        runId = stdout.trim();
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup(${CUSTOMER_ID});`);
    });

    test('main status pill and secondary "stille" pill are both readable', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`[data-run-item][data-run-id="${runId}"]`).first();
        const mainRow = row.locator('[data-run-main-row]');
        await expect(row).toBeVisible();

        const statusPill = mainRow.getByText('Kjører', { exact: true });
        await expect(statusPill).toBeVisible();
        expect(await statusPill.evaluate((el) => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(16);

        const stalledPill = mainRow.getByText('Ser ut til å stå stille', { exact: true });
        await expect(stalledPill).toBeVisible();
        expect(await stalledPill.evaluate((el) => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(12);
    });

    test('step timeline line (Kø/Sideplanlegging/Sidestruktur/Sider/Verifisering/QA) is readable', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`[data-run-item][data-run-id="${runId}"]`).first();
        await expect(row).toBeVisible();

        const header = page.locator('[data-run-header]');
        await expect(header).toBeVisible();
        const rowTemplate = await row.locator('[data-run-main-row]').evaluate((el) => el.style.gridTemplateColumns);
        const headerTemplate = await header.evaluate((el) => el.style.gridTemplateColumns);
        expect(rowTemplate).toBe(headerTemplate);

        const desktopTimeline = row.locator('[data-run-progress-row]');
        await expect(desktopTimeline).toBeVisible();
        // Six steps since 5d26caf2 retired the document-owner step, which nothing could reach.
        await expect(desktopTimeline.locator('[data-progress-step]')).toHaveCount(6);
        await expect(desktopTimeline.locator('[data-progress-connector]')).toHaveCount(5);
        for (const label of ['Kø', 'Sideplanlegging', 'Sidestruktur', 'Sider', 'Verifisering', 'QA']) {
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
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`[data-run-item][data-run-id="${runId}"]`).first();
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
        await loginAsWikiReader(page);
        await page.goto('/app/wiki?tab=runs');

        const row = page.locator(`[data-run-item][data-run-id="${runId}"]`).first();
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
        const textEls = Array.from(rowEl.querySelectorAll('span, p, div, button, a, h4'))
            .filter((el) => el.textContent.trim() && el.children.length === 0);
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
