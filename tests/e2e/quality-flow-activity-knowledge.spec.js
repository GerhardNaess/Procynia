import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * The knowledge behind one activity, as a reader meets it.
 *
 * What is checked is the journey the feature exists for: a reader looking at a flow can see that
 * one of its steps rests on something written down, open it, read which Wiki pages those are, and
 * follow one of them into Wiki. The rules about tenancy, deleted pages and what reaches the graph
 * are owned by QualityProcessBlueprintTest and QualityGraphProjectionTest — none of that is
 * re-asserted here.
 *
 * The process comes from E2ETestSeeder, which also activates the Kvalitet package and creates the
 * two Wiki pages the activity points at. Two rather than one, because the indicator is a count.
 */

const PROCESS = 'E2E prosess med kunnskap';
const ACTIVITY = 'Vurder anskaffelsen';
const PAGES = ['E2E Anskaffelsesrutine', 'E2E Terskelverdier'];

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

test.describe('the knowledge behind an activity', () => {
    test('an activity says how much it rests on, and opens it', async ({ page }) => {
        await openFlow(page, PROCESS);

        // The indicator on the diagram: a count, discreetly, on the node itself.
        await expect(page.locator('svg[role="img"]').getByText('2 kunnskapskilder')).toBeVisible();

        // Clicking the activity opens the panel. The node is the control, named after what it does.
        await page.getByRole('button', { name: `Vis kunnskapskildene for ${ACTIVITY}` }).first().click();

        const panel = page.getByRole('dialog');

        // Activity, role, and the pages behind it — the three things the panel exists to say.
        await expect(panel.getByRole('heading', { name: ACTIVITY })).toBeVisible();
        await expect(panel.getByText('Innkjøper')).toBeVisible();

        for (const title of PAGES) {
            await expect(panel.getByRole('link', { name: title })).toBeVisible();
        }

        // And the page can be opened where it lives. Nothing of what it says is repeated here.
        await panel.getByRole('link', { name: PAGES[0] }).click();

        await expect(page).toHaveURL(/\/app\/wiki\//);
        await expect(page.getByRole('heading', { name: PAGES[0] }).first()).toBeVisible();
    });

    /** The step list is the flow read aloud, so it has to carry the same way in. */
    test('the step list offers the same way in', async ({ page }) => {
        await openFlow(page, PROCESS);

        await page
            .getByRole('button', { name: `Vis kunnskapskildene for ${ACTIVITY}` })
            .last()
            .click();

        await expect(page.getByRole('dialog').getByRole('link', { name: PAGES[1] })).toBeVisible();
    });

    /** Connecting and removing, in the editor where the flow is corrected. */
    test('a connection can be added and removed in the structure editor', async ({ page }) => {
        await openFlow(page, PROCESS);

        await page.getByRole('button', { name: 'Rediger struktur manuelt' }).click();

        const picker = page.getByRole('combobox', { name: `Legg til kunnskapskilde for ${ACTIVITY}` });

        await expect(picker).toBeVisible();
        await expect(page.getByRole('button', { name: `Fjern ${PAGES[0]}` })).toBeVisible();

        // Removing one leaves the other, and the indicator follows immediately — the diagram is a
        // function of what the editor holds, not of what was last saved.
        await page.getByRole('button', { name: `Fjern ${PAGES[0]}` }).click();

        await expect(page.getByRole('button', { name: `Fjern ${PAGES[0]}` })).toHaveCount(0);
        await expect(page.locator('svg[role="img"]').getByText('1 kunnskapskilde')).toBeVisible();

        // And putting it back is one choice in the picker.
        await picker.selectOption({ label: PAGES[0] });

        await expect(page.locator('svg[role="img"]').getByText('2 kunnskapskilder')).toBeVisible();
    });

    /** A flow whose activities rest on nothing written down is untouched by any of this. */
    test('a process without knowledge connections has nothing extra on it', async ({ page }) => {
        await openFlow(page, 'E2E liten prosess');

        await expect(page.locator('svg[role="img"]').getByText(/kunnskapskilder/)).toHaveCount(0);
        await expect(page.getByRole('button', { name: /Vis kunnskapskildene/ })).toHaveCount(0);
    });
});
