import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * An activity as a source of knowledge articles, as a user meets it.
 *
 * What is checked is the journey the feature exists for: standing on a step of a process, seeing
 * what it has already produced, and writing the next article, which is handed to Enterprise Wiki as
 * a source that Wiki's own pipeline turns into pages. The rules about tenancy, provenance surviving a rewrite
 * and what reaches the graph are owned by QualityProcessBlueprintTest and QualityGraphProjectionTest
 * — none of that is re-asserted here.
 *
 * The AI draft is deliberately not exercised: it is a provider call, and the one thing it changes
 * is what the two fields are prefilled with. Writing the article by hand goes through the same
 * create.
 *
 * The process comes from E2ETestSeeder, which also activates the ISO package (which carries Kvalitet) and records the
 * two articles the seeded activity has produced. Two rather than one, because the indicator is a
 * count.
 */

const PROCESS = 'E2E prosess med kunnskap';
const ACTIVITY = 'Vurder anskaffelsen';
const ARTICLES = ['E2E Anskaffelsesrutine', 'E2E Terskelverdier'];

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

test.describe('an activity as a source of knowledge articles', () => {
    test('an activity says what it has produced, and opens it', async ({ page }) => {
        await openFlow(page, PROCESS);

        // The indicator on the diagram: a count, discreetly, on the node itself.
        await expect(page.locator('svg[role="img"]').getByText('2 kunnskapssider')).toBeVisible();

        await page.getByRole('button', { name: `Åpne kunnskapen bak ${ACTIVITY}` }).first().click();

        const panel = page.getByRole('dialog');

        // Activity, role, and the articles it is the source of.
        await expect(panel.getByRole('heading', { name: ACTIVITY })).toBeVisible();
        await expect(panel.getByText('Innkjøper')).toBeVisible();

        for (const title of ARTICLES) {
            await expect(panel.getByRole('link', { name: title })).toBeVisible();
        }

        // And the article can be opened where it lives. Nothing of what it says is repeated here.
        await panel.getByRole('link', { name: ARTICLES[0] }).click();

        await expect(page).toHaveURL(/\/app\/wiki\//);
        await expect(page.getByRole('heading', { name: ARTICLES[0] }).first()).toBeVisible();
    });

    /**
     * The journey the whole feature is for: aktivitet → artikkel → Wiki-utkast.
     *
     * Written by hand rather than drafted, so the test makes no provider call — what is created is
     * the text in the two fields either way.
     */
    test('an activity hands a knowledge article to Wiki as a source', async ({ page }) => {
        await openFlow(page, 'E2E liten prosess');

        await page.getByRole('button', { name: /Åpne kunnskapen bak/ }).first().click();

        const panel = page.getByRole('dialog');

        await expect(panel.getByText('Denne aktiviteten har ikke gitt noen kunnskap ennå.')).toBeVisible();

        await panel.getByRole('button', { name: 'Skriv artikkelen selv' }).click();

        // Written by hand or drafted, the article starts in the same structure — so the two are the
        // same kind of document and a reader finds what they are looking for in the same place.
        await expect(panel.getByLabel('Artikkel')).toHaveValue(/## Formål[\s\S]*## Relatert prosesskontekst/);

        const title = `E2E Sikkerhetskrav ${Date.now()}`;

        await panel.getByLabel('Tittel').fill(title);
        const markdown = 'Dette dekker hva som kontrolleres.\n\n## Hva du ser etter\n\nDokumentasjon.';
        await panel.getByLabel('Artikkel').fill(markdown);

        // The article is handed to Wiki as a source, and Wiki's own ingest run turns it into pages.
        // That run calls the model on the Wiki queues and each activity takes only so many
        // articles, so the hand-over is caught here and answered the way the server answers it — a
        // redirect back to the flow. What is checked is the browser's half: the text sent is the
        // user's, for this activity, and the user stays on the flow (there is no page to go to
        // yet). The server's half is covered in QualityProcessBlueprintTest.
        const flowUrl = page.url();
        let sent = null;
        await page.route('**/activities/articles', async (route) => {
            sent = route.request().postDataJSON();
            await route.fulfill({ status: 303, headers: { Location: flowUrl } });
        });

        await panel.getByRole('button', { name: 'Legg artikkelen inn i Wiki' }).click();

        await expect.poll(() => sent).not.toBeNull();
        expect(sent.title).toBe(title);
        expect(sent.markdown).toBe(markdown);
        expect(typeof sent.activity_key).toBe('string');
        expect(sent.activity_key).not.toBe('');

        await expect(page).toHaveURL(/\/app\/quality\/items\/\d+/);
        await expect(page).not.toHaveURL(/\/app\/wiki\//);
    });

    /** A step that has produced nothing still has a way in — that is the point of the direction. */
    test('an activity that has produced nothing still offers the way in', async ({ page }) => {
        await openFlow(page, 'E2E liten prosess');

        await expect(page.locator('svg[role="img"]').getByText(/kunnskapssid/)).toHaveCount(0);
        await expect(page.getByRole('button', { name: /Åpne kunnskapen bak/ }).first()).toBeVisible();
    });
});
