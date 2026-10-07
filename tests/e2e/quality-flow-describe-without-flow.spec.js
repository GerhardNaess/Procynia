import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * A process that has no flow yet can still be described.
 *
 * The seeded process has a purpose but no flow and no stored description, so the field starts
 * empty.
 *
 * This exists because of what the Flyt tab said on exactly that process. The deterministic
 * generator had been removed, but its instruction had not: the empty state told the user to press
 * "Generer struktur" to get a draft, and there was no such button any more. The only control that
 * does build a flow — "Generer prosessflyt" — sat greyed out until the description field was
 * filled, and that field's label was for screen readers only, below a read-only card headed
 * "Prosessbeskrivelse". So the tab read as a dead end on the one process that needed it most.
 *
 * The generate button is pressed only with descriptions the server refuses before any model is
 * called: whether the model answers is a separate question from whether the page lets the user get
 * as far as asking, and this test must not spend a provider call to find out.
 */
test('a process with no flow can be described, and the button is only off while it reads', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    const link = page.getByRole('link', { name: /E2E prosess uten struktur/ });

    if (await link.count() === 0) {
        test.skip(true, 'No seeded flow-less quality process in this environment.');
    }

    await link.first().click();
    await expect(page.getByRole('heading', { name: 'E2E prosess uten struktur' })).toBeVisible();

    await page.getByRole('link', { name: 'Flyt' }).first().click();
    await page.waitForURL('**tab=flow');

    // The empty state points at the description, not at a button that no longer exists.
    const emptyState = page.getByText(/Ingen flyt er laget for denne prosessen ennå/);
    await expect(emptyState).toBeVisible();
    await expect(emptyState).not.toContainText('Generer struktur');

    // The field is named on screen, so the place to write the description is findable.
    await expect(page.getByText('Prosessbeskrivelse', { exact: true })).toBeVisible();

    const description = page.getByRole('textbox', { name: 'Prosessbeskrivelse' });
    await expect(description).toBeEnabled();

    // The button is not withheld until the browser thinks the field has enough in it — that made
    // the one control on the tab depend on client state the user could not see. It is off only
    // while a reading is running; the floor on the description is enforced by the server, before
    // any model is called, and the reason comes back into the field's error line.
    const generate = page.getByRole('button', { name: 'Generer prosessflyt' });
    await expect(description).toHaveValue('');
    await expect(generate).toBeEnabled();

    const paint = () => generate.evaluate((el) => {
        const style = getComputedStyle(el);

        return `${style.backgroundColor}|${style.color}`;
    });

    const idle = await paint();

    // Hold the (refused, model-free) request open, so the running state can be looked at.
    let release;
    const held = new Promise((resolve) => { release = resolve; });
    await page.route('**/blueprint/interpret', async (route) => {
        await held;
        await route.continue();
    });

    await generate.click();

    // While it runs the button is off, says so, and looks it. Procynia's primary is a tint rather
    // than a fill, so the states must differ in colour, not only in the accessibility tree.
    const running = page.getByRole('button', { name: 'Leser beskrivelsen …' });
    await expect(running).toBeDisabled();
    await expect.poll(() => running.evaluate((el) => {
        const style = getComputedStyle(el);

        return `${style.backgroundColor}|${style.color}`;
    })).not.toBe(idle);

    release();

    // Empty: refused with what to do next, and nothing was built.
    await expect(page.getByText('Skriv hvordan prosessen gjennomføres i feltet over, så foreslår Procynia en flyt.')).toBeVisible();
    await expect(generate).toBeEnabled();
    await expect.poll(paint).toBe(idle);
    await expect(emptyState).toBeVisible();

    await page.unroute('**/blueprint/interpret');

    // Too thin to read a process out of: refused the same way, with its own reason.
    await description.fill('Avvik meldes.');
    await generate.click();
    await expect(page.getByText(/Beskrivelsen er for kort til å lese en prosess ut av/)).toBeVisible();
    await expect(emptyState).toBeVisible();

    // A real description is kept in the field and the button is there to press. It is not pressed:
    // that would spend a provider call.
    await description.fill(
        'Når et avvik meldes registrerer saksbehandler det. Deretter vurderer kvalitetsleder avviket og lukker det.',
    );
    await expect(generate).toBeEnabled();
});
