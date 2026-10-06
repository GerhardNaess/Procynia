import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * The Flyt tab's zoom controls, against the two shapes of graph that matter: one that already fits
 * its surface, and one several times wider than it.
 *
 * What is checked is what the user is promised — the whole of a large flow is inside the surface
 * after fit, a small flow is not shrunk for no reason, and zooming moves the diagram and nothing
 * else on the page. Exact scales are not asserted; diagramViewport.test.js owns the arithmetic.
 *
 * Both processes come from E2ETestSeeder, which also activates the ISO package (which carries Kvalitet) — without the
 * entitlement the routes redirect to Hjem, and a skip would hide that.
 */

const SMALL = 'E2E liten prosess';
const LARGE = 'E2E stor prosess';

async function openFlow(page, title) {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    const link = page.getByRole('link', { name: title });

    if (await link.count() === 0) {
        test.skip(true, `No seeded quality process named "${title}" in this environment.`);
    }

    await link.first().click();
    await page.getByRole('tab', { name: 'Flyt' }).or(page.getByRole('link', { name: 'Flyt' })).first().click();
    await expect(page.getByRole('img', { name: /Prosessflyt/ })).toBeVisible();
}

/** The surface is the scroll box; the drawing is the SVG inside it. */
async function boxes(page) {
    return page.evaluate(() => {
        const svg = document.querySelector('svg[role="img"]');
        const surface = svg.closest('div.overflow-auto');

        return {
            surface: { width: surface.clientWidth, height: surface.clientHeight },
            drawing: { width: svg.clientWidth, height: svg.clientHeight },
        };
    });
}

test.describe('process diagram zoom', () => {
    test('a large flow opens fitted, with all of it inside the surface', async ({ page }) => {
        await openFlow(page, LARGE);

        const { surface, drawing } = await boxes(page);

        expect(drawing.width).toBeLessThanOrEqual(surface.width + 1);
        expect(drawing.height).toBeLessThanOrEqual(surface.height + 1);
        await expect(page.getByLabel('Tilpass til vindu')).toBeVisible();
    });

    test('a small flow is not shrunk to open', async ({ page }) => {
        await openFlow(page, SMALL);

        await expect(page.locator('[role="group"][aria-label="Visning av diagrammet"]')).toContainText('100 %');
    });

    test('zoom in enlarges the drawing and leaves the page alone', async ({ page }) => {
        await openFlow(page, LARGE);

        const before = await boxes(page);
        const headingBefore = await page.getByRole('heading', { name: 'Diagram' }).boundingBox();

        await page.getByLabel('Zoom inn').click();

        const after = await boxes(page);
        const headingAfter = await page.getByRole('heading', { name: 'Diagram' }).boundingBox();

        expect(after.drawing.width).toBeGreaterThan(before.drawing.width);
        // The surface, and the heading above it, must not have moved: zoom is the diagram's, not
        // the page's.
        expect(after.surface.width).toBe(before.surface.width);
        expect(after.surface.height).toBe(before.surface.height);
        expect(headingAfter.width).toBe(headingBefore.width);
    });

    test('fit after zooming in brings the whole flow back inside the surface', async ({ page }) => {
        await openFlow(page, LARGE);

        await page.getByLabel('Zoom inn').click();
        await page.getByLabel('Zoom inn').click();
        await page.getByLabel('Tilpass til vindu').click();

        const { surface, drawing } = await boxes(page);

        expect(drawing.width).toBeLessThanOrEqual(surface.width + 1);
        expect(drawing.height).toBeLessThanOrEqual(surface.height + 1);
    });

    test('the controls stop at the limits instead of zooming without end', async ({ page }) => {
        await openFlow(page, SMALL);

        for (let press = 0; press < 12; press += 1) {
            const button = page.getByLabel('Zoom inn');

            if (await button.isDisabled()) {
                break;
            }

            await button.click();
        }

        await expect(page.getByLabel('Zoom inn')).toBeDisabled();

        for (let press = 0; press < 24; press += 1) {
            const button = page.getByLabel('Zoom ut');

            if (await button.isDisabled()) {
                break;
            }

            await button.click();
        }

        await expect(page.getByLabel('Zoom ut')).toBeDisabled();
    });
});
