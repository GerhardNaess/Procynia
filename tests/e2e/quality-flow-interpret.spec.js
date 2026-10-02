import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * Pressing "Generer prosessflyt" sends the description, and nothing else.
 *
 * This exists because of a bug that no unit test could have caught and that looked nothing like
 * its cause: the button's onClick was wired straight to a handler whose first argument is the text
 * to interpret, so the click event was handed to it as the description. Inertia then walked that
 * object looking for files, reached `event.view` — the window, which refers to itself — and the
 * stack ran out. The request was never sent, the finish callback never ran, and the button sat on
 * "Leser beskrivelsen …" for good.
 *
 * So what is asserted is the whole of that: a request leaves, no uncaught error reaches the page,
 * and the button comes back. The response is aborted deliberately — whether the model answers is a
 * separate question from whether the browser managed to ask, and this test must not spend a
 * provider call to find out.
 */
test('the generate button sends a request instead of serialising the click event', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(`${error.name}: ${error.message}`));

    const payloads = [];

    await page.route('**/blueprint/interpret', async (route) => {
        payloads.push(route.request().postData());
        await route.abort();
    });

    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    const link = page.getByRole('link', { name: 'E2E liten prosess' });

    if (await link.count() === 0) {
        test.skip(true, 'No seeded quality process in this environment.');
    }

    await link.first().click();
    await page.getByRole('tab', { name: 'Flyt' }).or(page.getByRole('link', { name: 'Flyt' })).first().click();

    const description = 'Når en ny leverandør skal opprettes registrerer innkjøper leverandøren i systemet. Deretter godkjenner økonomi leverandøren.';

    await page.getByLabel('Prosessbeskrivelse').fill(description);
    await page.getByRole('button', { name: 'Generer prosessflyt' }).click();

    await expect.poll(() => payloads.length, { timeout: 10000 }).toBe(1);

    // What was sent is the text the user typed — not a click event dressed as one.
    expect(payloads[0]).toContain('Når en ny leverandør');
    expect(payloads[0]).not.toContain('isTrusted');

    // The working state clears, so a failed attempt can be retried.
    await expect(page.getByRole('button', { name: 'Generer prosessflyt' })).toBeEnabled({ timeout: 10000 });

    expect(errors.filter((error) => error.includes('RangeError'))).toEqual([]);
});
