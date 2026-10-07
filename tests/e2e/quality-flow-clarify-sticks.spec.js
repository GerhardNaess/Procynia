import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * The reported bug, in the running app: answer a clarification and watch it stay answered.
 *
 * WHAT IS REAL HERE AND WHAT IS NOT.
 *
 * Every server call this test makes is real, including both provider calls behind "Bruk svaret"
 * and the readings after it. What is injected is one thing: the proposal the panel starts from,
 * so the suggestion is reliably on screen to be answered. Whether a given reading of a given
 * paragraph raises a given clarification is the model's judgement on a sentence — asking for it
 * and hoping would make this test green or red for reasons that have nothing to do with the code.
 * The stub is removed before the answer is sent, so nothing from there on is anything but the
 * application.
 *
 * Its sibling, quality-flow-clarify.spec.js, is the opposite split: everything stubbed, because
 * what it defends is the shape of the request the browser builds. This one is about what the
 * server does with it.
 *
 * WHAT IS ASSERTED.
 *
 * That the answer reaches the description, that the suggestion is gone from the proposal that
 * comes back with it, and that it is still gone after regenerating and after adopting, reloading
 * and regenerating again — the loop a kvalitetsleder actually walks, and the one where the
 * question used to come back. Not what the model wrote: phrasing is its business, and asserting on
 * it would make this a test of a sentence.
 */

const QUESTION = 'Hva gjør at en leverandør regnes som kritisk?';
const ANSWER = 'Når verdien av anskaffelsen er over kr. 100000.';
const BUSY = 'Leser beskrivelsen …';
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

/**
 * One row of the "Verdt å presisere" list.
 *
 * Identified by carrying an "Avklar" button rather than by a test hook: that button is what makes
 * a row an optional clarification and not a blocking question, which is also an <li> a few
 * centimetres further up. Nothing in the panel had to change to be findable.
 */
function clarifications(page) {
    return page.locator('li').filter({ has: page.getByRole('button', { name: 'Avklar' }) });
}

/** A real reading is one provider call, and the clarify path is two. The wait is generous. */
async function settle(page) {
    await expect(page.getByRole('button', { name: BUSY })).toHaveCount(0, { timeout: 180000 });
    await expect(page.getByRole('button', { name: 'Generer prosessflyt' })).toBeEnabled({ timeout: 180000 });
}

async function generate(page) {
    await page.getByRole('button', { name: 'Generer prosessflyt' }).click();
    await expect(page.getByRole('button', { name: BUSY })).toBeVisible({ timeout: 20000 });
    await settle(page);
}

test('an answered clarification does not come back', async ({ page }) => {
    test.setTimeout(300000);

    const errors = [];
    page.on('pageerror', (error) => errors.push(`${error.name}: ${error.message}`));

    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    // A process of its own. Step 5 adopts a new flow, and adopting onto a seeded process would hand
    // every later spec in the run a different flow from the one the seeder wrote (zoom and
    // subprocess read «E2E liten prosess» by its size and its steps).
    const xsrf = async () => decodeURIComponent((await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN').value);
    const created = await page.request.post('/app/quality/items', {
        headers: { 'X-XSRF-TOKEN': await xsrf(), Accept: 'text/html' },
        form: { quality_type: 'process', title: `E2E avklaring ${Date.now()}` },
    });
    expect(created.ok()).toBeTruthy();

    const itemId = Number(created.url().match(/items\/(\d+)/)[1]);

    try {
        await answerAndAdopt(page, itemId, errors);
    } finally {
        await page.request.delete(`/app/quality/items/${itemId}`, { headers: { 'X-XSRF-TOKEN': await xsrf() } });
    }
});

async function answerAndAdopt(page, itemId, errors) {
    await page.goto(`/app/quality/items/${itemId}?tab=document`);
    const flowTab = /\/app\/quality\/items\/\d+\?tab=flow/;

    // The Inertia visit to the Flyt tab, answered with a proposal on it. A client-side visit rather
    // than a full page load, so the response is the props JSON and not an HTML document.
    await page.route(flowTab, async (route) => {
        const response = await route.fetch();
        const body = await response.json();

        body.props.flow_proposal = proposalFor(itemId);
        body.props.flow_ai_available = true;

        await route.fulfill({ response, json: body });
    });

    await page.getByRole('link', { name: 'Flyt' }).first().click();

    const asked = clarifications(page).filter({ hasText: QUESTION });

    await expect(asked).toHaveCount(1);

    // From here the application answers for itself.
    await page.unroute(flowTab);

    const description = page.getByRole('textbox', { name: 'Prosessbeskrivelse' });

    await expect(description).toHaveValue(DESCRIPTION);

    // 1. Answer it. Two real provider calls: the rewrite, then the reading of what it produced.
    await asked.getByRole('button', { name: 'Avklar' }).click();
    await page.locator('#clarification-answer').fill(ANSWER);
    await page.getByRole('button', { name: 'Bruk svaret' }).click();
    await expect(page.getByRole('button', { name: BUSY })).toBeVisible({ timeout: 20000 });
    await settle(page);

    // 2. The criterion is in the description, and the question is not.
    const revised = await description.inputValue();

    expect(revised).not.toBe(DESCRIPTION);
    expect(revised).toMatch(/100\s?000/);
    expect(revised).not.toContain('?');

    // 3. Gone from the proposal that came back with it. The reading happened before the answer was
    //    recorded, so this is the one the endpoint has to filter itself.
    await expect(clarifications(page).filter({ hasText: /kritisk/i })).toHaveCount(0);

    // 4. Gone from a fresh reading of the revised text. This is the path that failed: the model
    //    noticing the word again and the user being asked what they had just answered.
    await generate(page);
    await expect(clarifications(page).filter({ hasText: /kritisk/i })).toHaveCount(0);

    // 5. Adopt, reload, generate again. Adopting is what stores the revised description, so this is
    //    the state a kvalitetsleder comes back to the next morning — and a reload drops the
    //    proposal, so nothing but the stored row is carrying the answer by this point.
    await page.getByRole('button', { name: 'Bruk denne prosessflyten' }).click();
    await expect(page.getByText('Prosessflyten er tatt i bruk.')).toBeVisible({ timeout: 30000 });

    await page.reload();
    await page.getByRole('link', { name: 'Flyt' }).first().click();

    await expect(description).toHaveValue(revised);

    await generate(page);
    await expect(clarifications(page).filter({ hasText: /kritisk/i })).toHaveCount(0);

    expect(errors).toEqual([]);
}
