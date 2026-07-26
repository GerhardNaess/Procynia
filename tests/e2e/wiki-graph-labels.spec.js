import { test, expect } from '@playwright/test';

// This graph view is only populated with real Wiki data for the dev-seeded customer
// (alisan@advania.no / customer_id=4) — the plain E2E-seeded USER has no Enterprise Wiki
// content. The real dataset (16 pages, ~110 edges) already gives a genuinely dense graph
// with at least one long page title ("Styringsnivåer: strategisk, taktisk og operativt"),
// so no synthetic fixtures are needed for this test file.
async function loginAsDevDataUser(page) {
    await page.goto('/login');
    await page.fill('#email', 'alisan@advania.no');
    await page.fill('#password', 'Opaque01');
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 });
}

// Node labels render on Sigma's own canvas layer (class "sigma-labels"), not as DOM text —
// there is no element to run getComputedStyle() against. The label canvas's 2D context
// persists the `font` string set by the last draw call, so reading it back is a legitimate,
// DOM-inspectable way to verify the actual on-screen font size used for node labels.
function labelCanvasFont(page) {
    return page.locator('canvas.sigma-labels').evaluate((canvas) => canvas.getContext('2d').font);
}

test.beforeEach(async ({ page }) => {
    await loginAsDevDataUser(page);
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
    await page.goto('/app/wiki/graph');
    await page.waitForTimeout(1200);

    // Sigma renders nodes on canvas only (no per-node DOM elements, and node positions come
    // from a Math.random()-seeded force-directed layout), so a blind grid of clicks over the
    // canvas is unreliable — it can spend its whole budget landing between nodes. Instead,
    // read the label canvas's own pixel data to find where a label was ACTUALLY drawn this
    // run, then click just to its left: node labels always start at `data.x + data.size + 3`
    // (see drawTruncatedNodeLabel in Graph.jsx), so the node's circle is reliably a short,
    // known distance to the left of any drawn label pixel, at roughly the same height.
    const labelsCanvas = page.locator('canvas.sigma-labels');
    const canvasBox = await labelsCanvas.boundingBox();
    expect(canvasBox).not.toBeNull();

    const candidates = await labelsCanvas.evaluate((canvas) => {
        const ctx = canvas.getContext('2d');
        const ratio = window.devicePixelRatio || 1;
        const { data, width, height } = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const points = [];
        for (let y = 0; y < height && points.length < 30; y += 4) {
            for (let x = 0; x < width && points.length < 30; x += 4) {
                if (data[(y * width + x) * 4 + 3] > 50) {
                    points.push({ x: x / ratio, y: y / ratio });
                    break; // leftmost drawn pixel on this row is enough — move to the next row
                }
            }
        }
        return points;
    });
    expect(candidates.length).toBeGreaterThan(0);

    const panelTitle = page.locator('p[title]').first();
    let opened = false;
    for (const point of candidates) {
        await page.mouse.click(canvasBox.x + point.x - 25, canvasBox.y + point.y - 5);
        await page.waitForTimeout(30);
        if (await panelTitle.isVisible().catch(() => false)) {
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
