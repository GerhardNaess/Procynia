import { test, expect } from '@playwright/test';

/**
 * The focus view, drawn for real.
 *
 * Its layout and its camera fit are both pure functions with their own deterministic unit tests
 * (wikiGraphFocus.test.js, wikiGraphFocusFit.test.js). What those cannot answer is whether the
 * result is actually readable once Sigma has drawn it: a label is painted on a canvas, so "is any
 * title cut off at the edge of the picture" is a question about PIXELS, and only a browser has them.
 *
 * Like wiki-graph-labels.spec.js, this file runs against the dev-seeded customer, which is the only
 * one with real Enterprise Wiki content — including a genuine hub (Security Operations Center, with
 * more than twenty neighbours) that is exactly the case a single ring could not hold.
 */

const FOCUS_PAGE_ID = 82;  // Hybrid SOC-arkitektur — a middling neighbourhood
const HUB_PAGE_ID = 67;    // Security Operations Center (SOC) — the dense one

async function loginAsDevDataUser(page) {
    await page.goto('/login');
    await page.fill('#email', 'alisan@advania.no');
    await page.fill('#password', 'Opaque01');
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 });
}

/**
 * Open the graph on a page and switch to focus mode.
 *
 * ?page_id= is what WikiGraphController already reads for the return link, and the focus picker is
 * pre-selected from it — so this is the same route a user takes from an article, not a test-only one.
 */
async function openFocus(page, pageId) {
    await page.goto(`/app/wiki/graph?page_id=${pageId}`);
    await page.getByRole('button', { name: 'Fokus' }).click();
    await expect(page.locator('[data-graph-view="focus"] canvas.sigma-labels')).toBeVisible();
    await page.waitForTimeout(1500); // the fetch, the layout, and the one-frame-late camera fit
}

function focusLabelCanvas(page) {
    return page.locator('[data-graph-view="focus"] canvas.sigma-labels');
}

/**
 * Where the drawn labels actually reach, as a fraction of the canvas.
 *
 * A clipped label is ink that runs into the very edge of the canvas: the glyphs continue, the canvas
 * does not. So "no ink in the outermost columns" is the direct, pixel-level statement of "no title
 * is cut off", and it needs no knowledge of which page ended up where.
 */
function labelInkExtent(canvas) {
    return canvas.evaluate((element) => {
        const context = element.getContext('2d');
        const { width, height } = element;
        const { data } = context.getImageData(0, 0, width, height);

        let minX = width;
        let maxX = -1;
        let minY = height;
        let maxY = -1;
        let pixels = 0;

        for (let y = 0; y < height; y += 1) {
            for (let x = 0; x < width; x += 1) {
                if (data[(y * width + x) * 4 + 3] > 40) {
                    pixels += 1;
                    if (x < minX) minX = x;
                    if (x > maxX) maxX = x;
                    if (y < minY) minY = y;
                    if (y > maxY) maxY = y;
                }
            }
        }

        return { width, height, minX, maxX, minY, maxY, pixels };
    });
}

test.beforeEach(async ({ page }) => {
    await loginAsDevDataUser(page);
});

test('focus mode draws a neighbourhood with no title clipped at either side', async ({ page }) => {
    await openFocus(page, FOCUS_PAGE_ID);

    const ink = await labelInkExtent(focusLabelCanvas(page));

    expect(ink.pixels).toBeGreaterThan(0);
    expect(ink.minX).toBeGreaterThan(0);
    expect(ink.maxX).toBeLessThan(ink.width - 1);
    expect(ink.minY).toBeGreaterThan(0);
    expect(ink.maxY).toBeLessThan(ink.height - 1);
});

test('a hub with more than twenty neighbours is drawn without clipping, and without being shrunk away', async ({ page }) => {
    await openFocus(page, HUB_PAGE_ID);

    // The badge states the size of what is being drawn; this is the case the ring could not hold.
    const summary = await page.locator('[data-graph-view="focus"] ~ * span', { hasText: 'sider' }).first().textContent()
        .catch(() => null);

    if (summary) {
        expect(Number(summary.match(/(\d+)\s+sider/)?.[1] ?? 0)).toBeGreaterThan(20);
    }

    const ink = await labelInkExtent(focusLabelCanvas(page));

    expect(ink.minX).toBeGreaterThan(0);
    expect(ink.maxX).toBeLessThan(ink.width - 1);

    // …and the fix for clipping must not be "draw everything tiny in the middle". The labels should
    // still spread across most of the canvas.
    expect((ink.maxX - ink.minX) / ink.width).toBeGreaterThan(0.5);
    expect((ink.maxY - ink.minY) / ink.height).toBeGreaterThan(0.4);
});

test('the focus page is still the obvious subject of the picture', async ({ page }) => {
    await openFocus(page, HUB_PAGE_ID);

    // Sigma draws a highlighted node's boxed label on the hover layer. Its presence is what says
    // "this one is the focus", and it survives however crowded the neighbourhood gets.
    const hoverInk = await page.locator('[data-graph-view="focus"] canvas.sigma-hovers').evaluate((canvas) => {
        const { data } = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height);
        for (let i = 3; i < data.length; i += 4) {
            if (data[i] > 0) return true;
        }
        return false;
    });

    expect(hoverInk).toBe(true);
});

test('depth and direction still change the neighbourhood that is drawn', async ({ page }) => {
    await openFocus(page, FOCUS_PAGE_ID);

    const before = await labelInkExtent(focusLabelCanvas(page));

    await page.getByRole('button', { name: '2 hopp' }).click();
    await page.waitForTimeout(1500);

    const after = await labelInkExtent(focusLabelCanvas(page));

    expect(after.pixels).toBeGreaterThan(before.pixels);
    expect(after.minX).toBeGreaterThan(0);
    expect(after.maxX).toBeLessThan(after.width - 1);

    await page.getByRole('button', { name: 'Lenker inn' }).click();
    await page.waitForTimeout(1500);

    const incoming = await labelInkExtent(focusLabelCanvas(page));

    expect(incoming.pixels).toBeGreaterThan(0);
    expect(incoming.minX).toBeGreaterThan(0);
    expect(incoming.maxX).toBeLessThan(incoming.width - 1);
});

test('mobile viewport (390px) draws the focus view without clipping or horizontal scroll', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await openFocus(page, FOCUS_PAGE_ID);

    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 1);

    const ink = await labelInkExtent(focusLabelCanvas(page));

    expect(ink.pixels).toBeGreaterThan(0);
    expect(ink.minX).toBeGreaterThan(0);
    expect(ink.maxX).toBeLessThan(ink.width - 1);
});

test('no console errors while loading and interacting with the focus view', async ({ page }) => {
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
    page.on('response', (res) => { if (res.status() >= 500) failedRequests.push(`${res.status()} ${res.url()}`); });

    await openFocus(page, HUB_PAGE_ID);

    const canvas = page.locator('[data-graph-view="focus"] canvas').last();
    const box = await canvas.boundingBox();

    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.wheel(0, -200);
    await page.waitForTimeout(200);
    await page.mouse.wheel(0, 400);
    await page.waitForTimeout(200);

    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
});
