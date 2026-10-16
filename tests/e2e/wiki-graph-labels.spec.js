import { test, expect } from '@playwright/test';
import { tinker } from './helpers/risk.js';
import { e2eWikiCustomerId, loginAsWikiReader } from './helpers/wiki.js';

// The graph is the spec's own: WikiGraphFocusE2EFixture seeds a dense neighbourhood (a hub with
// more than twenty neighbours) and one long page title into the E2E customer, so the labels are
// drawn crowded and at least one of them has to be truncated on the canvas.
const FIXTURE = '\\Tests\\Support\\WikiGraphFocusE2EFixture';
let CUSTOMER_ID;

test.beforeAll(async () => {
    CUSTOMER_ID = await e2eWikiCustomerId();
    await tinker(`echo ${FIXTURE}::seed(${CUSTOMER_ID});`);
});

test.afterAll(async () => {
    await tinker(`${FIXTURE}::cleanup(${CUSTOMER_ID});`);
});

// Node labels render on Sigma's own canvas layer (class "sigma-labels"), not as DOM text —
// there is no element to run getComputedStyle() against. The label canvas's 2D context
// persists the `font` string set by the last draw call, so reading it back is a legitimate,
// DOM-inspectable way to verify the actual on-screen font size used for node labels.
function labelCanvasFont(page) {
    return page.locator('canvas.sigma-labels').evaluate((canvas) => canvas.getContext('2d').font);
}

test.beforeEach(async ({ page }) => {
    await loginAsWikiReader(page);
});

test('node label font size is at least 16px in the normal view', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1200);

    const font = await labelCanvasFont(page);
    const match = font.match(/(\d+(?:\.\d+)?)px/);
    expect(match).not.toBeNull();
    expect(parseFloat(match[1])).toBeGreaterThanOrEqual(16);
});

test('node label font does not use a text-xs/text-sm-equivalent size', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1200);

    const font = await labelCanvasFont(page);
    // text-xs = 12px, text-sm = 14px in this app's Tailwind scale — neither may appear.
    expect(font).not.toMatch(/\b12px\b/);
    expect(font).not.toMatch(/\b14px\b/);
});

test('the labels canvas renders with the expected font family and weight', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1200);

    const font = await labelCanvasFont(page);
    expect(font).toContain('Inter');
    expect(font).toMatch(/^500\s/); // labelWeight
});

test('node labels are visible in the normal graph view', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1200);

    const labelsCanvas = page.locator('canvas.sigma-labels');
    await expect(labelsCanvas).toBeVisible();

    // Sanity check that something was actually drawn onto the label canvas (not just an
    // empty transparent layer) — sample the canvas pixel data for any non-transparent pixel.
    const hasDrawnContent = await labelsCanvas.evaluate((canvas) => {
        const ctx = canvas.getContext('2d');
        const { width, height } = canvas;
        const { data } = ctx.getImageData(0, 0, width, height);
        for (let i = 3; i < data.length; i += 4) {
            if (data[i] > 0) return true; // any non-zero alpha channel byte
        }
        return false;
    });
    expect(hasDrawnContent).toBe(true);
});

test('long node titles are handled: truncated on canvas (unit-tested), never truncated in the node panel', async ({ page }) => {
    // The truncation logic itself is covered deterministically for realistic long Norwegian
    // titles in graphLabelLogic.test.js. Here we confirm the complementary E2E guarantee: the
    // click-to-open node panel (NodePanel in Graph.jsx) always renders the FULL, untruncated
    // title via a `title` attribute — regardless of the node clicked, and never with an
    // ellipsis — which is the "full title tilgjengelig hvis visuell tekst avkortes" behavior
    // the canvas-truncated label relies on.
    // Sigma renders nodes on canvas only (no per-node DOM elements, and node positions come
    // from a Math.random()-seeded force-directed layout), so neither a grid of clicks nor a guess
    // from label pixels finds a node every run (OPPF-002). Sigma does tell where each node is,
    // though: drawTruncatedNodeLabel (Graph.jsx) draws every visible label with
    // fillText(label, data.x + data.size + 3, data.y + labelSize / 3), in CSS pixels of the label
    // canvas. Recording those calls gives each drawn node's right edge and centre line exactly, so
    // the click lands a few pixels inside a node that is on screen this run. No app code changes.
    await page.addInitScript(() => {
        const { clearRect, fillText } = CanvasRenderingContext2D.prototype;
        window.__wikiGraphLabels = new Map();
        // Sigma clears the label canvas before each frame's labels, so the map holds one frame.
        CanvasRenderingContext2D.prototype.clearRect = function (...args) {
            if (this.canvas?.classList?.contains('sigma-labels')) {
                window.__wikiGraphLabels = new Map();
            }

            return clearRect.apply(this, args);
        };
        CanvasRenderingContext2D.prototype.fillText = function (text, x, y, ...rest) {
            if (this.canvas?.classList?.contains('sigma-labels')) {
                window.__wikiGraphLabels.set(String(text), { x, y });
            }

            return fillText.call(this, text, x, y, ...rest);
        };
    });
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1200);

    const labelsCanvas = page.locator('canvas.sigma-labels');
    const canvasBox = await labelsCanvas.boundingBox();
    expect(canvasBox).not.toBeNull();

    // The labels of the frame on screen: read twice until the camera has stopped moving.
    const drawnLabels = () => page.evaluate(() => Array.from(window.__wikiGraphLabels, ([text, at]) => ({ text, ...at })));
    let candidates = [];
    await expect.poll(async () => {
        const before = JSON.stringify(await drawnLabels());
        await page.waitForTimeout(200);
        candidates = await drawnLabels();

        return candidates.length > 0 && JSON.stringify(candidates) === before;
    }, { timeout: 10_000 }).toBe(true);

    // Label baseline is data.y + 16 / 3 (NODE_LABEL_SIZE); the label starts 3 px right of the node.
    const LABEL_SIZE = 16;
    const INSIDE_NODE_PX = 3;
    const panelTitle = page.locator('p[title]').first();
    let opened = false;
    for (const label of candidates) {
        const x = label.x - 3 - INSIDE_NODE_PX;
        const y = label.y - LABEL_SIZE / 3;
        if (x < 0 || y < 0 || x > canvasBox.width || y > canvasBox.height) {
            continue;
        }
        await page.mouse.click(canvasBox.x + x, canvasBox.y + y);
        if (await panelTitle.waitFor({ state: 'visible', timeout: 1000 }).then(() => true, () => false)) {
            opened = true;
            break;
        }
    }
    expect(opened).toBe(true);

    const fullTitle = await panelTitle.getAttribute('title');
    expect(fullTitle.length).toBeGreaterThan(0);
    expect(fullTitle).not.toMatch(/…$/); // the panel itself never truncates, unlike the canvas label
});

test('zooming in keeps the label font size stable (not unnaturally large)', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1200);

    const fontBefore = await labelCanvasFont(page);

    await page.mouse.move(700, 400);
    for (let i = 0; i < 6; i++) {
        await page.mouse.wheel(0, -200);
        await page.waitForTimeout(60);
    }
    await page.waitForTimeout(200);

    const fontAfterZoomIn = await labelCanvasFont(page);
    expect(fontAfterZoomIn).toBe(fontBefore);
});

test('zooming out keeps the label font size stable (not illegibly small)', async ({ page }) => {
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1200);

    const fontBefore = await labelCanvasFont(page);

    await page.mouse.move(700, 400);
    for (let i = 0; i < 10; i++) {
        await page.mouse.wheel(0, 200);
        await page.waitForTimeout(60);
    }
    await page.waitForTimeout(200);

    const fontAfterZoomOut = await labelCanvasFont(page);
    expect(fontAfterZoomOut).toBe(fontBefore);
    const match = fontAfterZoomOut.match(/(\d+(?:\.\d+)?)px/);
    expect(parseFloat(match[1])).toBeGreaterThanOrEqual(16);
});

test('mobile viewport (390px) has no horizontal scroll with the larger labels', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1200);

    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 1);

    await expect(page.locator('canvas.sigma-labels')).toBeVisible();
});

test('no console errors or failed requests while loading and interacting with the graph', async ({ page }) => {
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
    page.on('requestfailed', (req) => failedRequests.push(`${req.method()} ${req.url()}`));
    page.on('response', (res) => { if (res.status() >= 500) failedRequests.push(`${res.status()} ${res.url()}`); });

    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1200);

    await page.mouse.move(700, 400);
    await page.mouse.wheel(0, -300);
    await page.waitForTimeout(200);
    await page.mouse.wheel(0, 600);
    await page.waitForTimeout(200);

    const canvas = page.locator('canvas').last();
    const box = await canvas.boundingBox();
    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
    await page.waitForTimeout(200);

    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
});
