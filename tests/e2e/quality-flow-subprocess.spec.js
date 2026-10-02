import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * Drilling from a process into a step that is itself a process, and back out again.
 *
 * What is checked is the journey the feature exists for: a reader looking at "E2E hovedprosess"
 * sees that one of its steps opens into something, opens it, is told where they are, and gets back
 * in one click. The arithmetic of the layout and the rules about tenancy and cycles are owned by
 * processBlueprintLayout.test.js and QualityProcessBlueprintTest — none of that is re-asserted here.
 *
 * Both processes come from E2ETestSeeder, which also activates the Kvalitet package.
 */

const PARENT = 'E2E hovedprosess';
const CHILD = 'E2E liten prosess';

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

test.describe('drilling into a subprocess', () => {
    test('a step that stands for another process says so, opens it, and comes back', async ({ page }) => {
        await openFlow(page, PARENT);

        // The parent's own flow, before anything is opened.
        await expect(page.getByRole('img', { name: `Prosessflyt — ${PARENT}` })).toBeVisible();

        // The indicator: the node is a control, named after what it opens.
        const node = page.getByRole('button', { name: `Åpne underprosessen ${CHILD}` });

        await expect(node).toBeVisible();
        await expect(page.locator('svg[role="img"]').getByText('2 steg')).toBeVisible();

        await node.click();

        // The subprocess, drawn from its own stored blueprint, in the same place on the page.
        await expect(page.getByRole('img', { name: `Prosessflyt — ${CHILD}` })).toBeVisible();
        await expect(
            page.getByRole('img', { name: `Prosessflyt — ${CHILD}` }).getByText('Avviket er lukket'),
        ).toBeVisible();
        await expect(page).toHaveURL(/subprocess=\d+/);

        // ← Hovedprosess / Underprosess, directly above the diagram.
        const trail = page.getByRole('navigation', { name: 'Hvor du er i prosessen' });

        await expect(trail).toBeVisible();
        await expect(trail.getByRole('button', { name: `Tilbake til ${PARENT}` })).toBeVisible();
        await expect(trail.getByText(CHILD, { exact: true })).toBeVisible();

        // Nothing that edits the parent is on screen while a child is.
        await expect(page.getByRole('button', { name: 'Lagre struktur' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Godkjenn struktur' })).toHaveCount(0);

        // And back, in one click, to the process we came from.
        await trail.getByRole('button', { name: `Tilbake til ${PARENT}` }).click();

        await expect(page.getByRole('img', { name: `Prosessflyt — ${PARENT}` })).toBeVisible();
        await expect(page.getByRole('navigation', { name: 'Hvor du er i prosessen' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Lagre struktur' })).toBeVisible();
    });

    /** The breadcrumb is in the URL, so the browser's back button is the back button. */
    test('the browser back button leaves the subprocess', async ({ page }) => {
        await openFlow(page, PARENT);

        await page.getByRole('button', { name: `Åpne underprosessen ${CHILD}` }).click();
        await expect(page.getByRole('img', { name: `Prosessflyt — ${CHILD}` })).toBeVisible();

        await page.goBack();

        await expect(page.getByRole('img', { name: `Prosessflyt — ${PARENT}` })).toBeVisible();
    });

    /** The step list is the flow read aloud, so it has to carry the same way in. */
    test('the step list offers the same way in', async ({ page }) => {
        await openFlow(page, PARENT);

        await page.getByRole('button', { name: new RegExp(`Underprosess: ${CHILD}`) }).click();

        await expect(page.getByRole('img', { name: `Prosessflyt — ${CHILD}` })).toBeVisible();
    });

    /** A flow with no references is untouched by any of this. */
    test('a process without a subprocess has nothing extra on it', async ({ page }) => {
        await openFlow(page, CHILD);

        await expect(page.getByRole('navigation', { name: 'Hvor du er i prosessen' })).toHaveCount(0);
        await expect(page.locator('svg[role="img"]').getByText(/steg$/)).toHaveCount(0);
        await expect(page.getByRole('button', { name: /Åpne underprosessen/ })).toHaveCount(0);
    });
});
