import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * "Avklar": what the browser asks for, and what it does before the answer comes back.
 *
 * This defends the half of the behaviour that no server test can reach. The panel used to compose
 * the new description itself — the old text, then the question, then the answer — and send that to
 * be read again, which is how a process description turned into a transcript of its own review.
 * Every assertion about weaving an answer in lives in QualityProcessFlowInterpretationTest, and
 * none of it means anything if the browser never asks for a rewrite in the first place.
 *
 * So: the request carries the question and the answer as separate fields and a description with
 * neither of them in it, and the suggestion leaves the screen on the click rather than when the
 * response lands. That second one matters because the round trip is two provider calls, and a
 * suggestion that sits there through both of them is one the user answers twice.
 *
 * NO PROVIDER CALL IS MADE. The proposal the panel renders from is a prop, so it is injected into
 * the Inertia response rather than generated — whether a model writes a good sentence is a
 * separate question from whether the browser manages to ask for one, and this test must not spend
 * a call to find out. The answer request is intercepted for the same reason.
 */

const QUESTION = 'Hva gjør at en leverandør regnes som kritisk?';
const ANSWER = 'Når verdien av anskaffelsen er over kr. 100000.';
const DESCRIPTION = 'Når en ny leverandør skal opprettes registrerer innkjøper leverandøren i systemet. '
    + 'Dersom leverandøren er kritisk skal sikkerhetsansvarlig kontrollere leverandøren. '
    + 'Deretter godkjenner økonomi leverandøren.';

/** The shape QualityController flashes, as the panel receives it. */
function proposalFor(itemId) {
    return {
        quality_item_id: itemId,
        lanes: [
            { key: 'rolle-1', label: 'Innkjøper' },
            { key: 'rolle-2', label: 'Sikkerhetsansvarlig' },
        ],
        nodes: [
            { key: 'start', lane: 'rolle-1', type: 'start', label: 'Ny leverandør skal opprettes', description: null },
            { key: 'registrer-leverandor', lane: 'rolle-1', type: 'step', label: 'Registrer leverandøren', description: null },
            { key: 'kritisk-leverandor', lane: 'rolle-1', type: 'decision', label: 'Er leverandøren kritisk?', description: null },
            { key: 'kontroller-leverandor', lane: 'rolle-2', type: 'step', label: 'Kontroller leverandøren', description: null },
            { key: 'end', lane: 'rolle-2', type: 'end', label: 'Leverandøren er godkjent', description: null },
        ],
        edges: [
            { from: 'start', to: 'registrer-leverandor', label: null },
            { from: 'registrer-leverandor', to: 'kritisk-leverandor', label: null },
            { from: 'kritisk-leverandor', to: 'kontroller-leverandor', label: 'Ja' },
            { from: 'kritisk-leverandor', to: 'end', label: 'Nei' },
            { from: 'kontroller-leverandor', to: 'end', label: null },
        ],
        blocking_questions: [],
        optional_clarifications: [QUESTION],
        description: DESCRIPTION,
    };
}

test('answering a clarification asks the server to rewrite, and clears the suggestion at once', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(`${error.name}: ${error.message}`));

    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    const link = page.getByRole('link', { name: 'E2E liten prosess' });

    if (await link.count() === 0) {
        test.skip(true, 'No seeded quality process in this environment.');
    }

    await link.first().click();
    await page.waitForURL(/\/app\/quality\/items\/\d+/);

    const itemId = Number(page.url().match(/items\/(\d+)/)[1]);

    // The Inertia visit to the Flyt tab, answered with a proposal on it. A client-side visit rather
    // than a full page load, so the response is the props JSON and not an HTML document.
    await page.route(/\/app\/quality\/items\/\d+\?tab=flow/, async (route) => {
        const response = await route.fetch();
        const body = await response.json();

        body.props.flow_proposal = proposalFor(itemId);
        body.props.flow_ai_available = true;

        await route.fulfill({ response, json: body });
    });

    const answers = [];

    await page.route('**/blueprint/clarifications/answer', async (route) => {
        answers.push(route.request().postData());
        // Held open, so what the screen does while it waits is observable.
        await new Promise((resolve) => setTimeout(resolve, 2000));
        await route.abort();
    });

    await page.getByRole('link', { name: 'Flyt' }).first().click();

    await expect(page.getByText('Verdt å presisere')).toBeVisible();
    await expect(page.getByText(QUESTION)).toBeVisible();

    await page.getByRole('button', { name: 'Avklar' }).first().click();
    await page.locator('#clarification-answer').fill(ANSWER);
    await page.getByRole('button', { name: 'Bruk svaret' }).click();

    await expect.poll(() => answers.length, { timeout: 10000 }).toBe(1);

    const body = JSON.parse(answers[0]);

    // Question and answer travel as themselves, and the description is still the user's own text —
    // a composed one would carry the question into what gets interpreted, which is the bug.
    expect(body.question).toBe(QUESTION);
    expect(body.answer).toBe(ANSWER);
    expect(body.description).toBe(DESCRIPTION);
    expect(body.description).not.toContain(QUESTION);
    expect(body.description).not.toContain(ANSWER);

    // And it is off the screen already, while the request is still open.
    await expect(page.getByText(QUESTION)).toHaveCount(0);
    await expect(page.getByText('Verdt å presisere')).toHaveCount(0);

    // The flow itself is untouched by answering a question about it.
    await expect(page.getByText('Er leverandøren kritisk?').first()).toBeVisible();

    expect(errors).toEqual([]);
});
