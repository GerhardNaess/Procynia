import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

/**
 * A process that has no flow yet can still be described.
 *
 * This exists because of what the Flyt tab said on exactly that process. The deterministic
 * generator had been removed, but its instruction had not: the empty state told the user to press
 * "Generer struktur" to get a draft, and there was no such button any more. The only control that
 * does build a flow — "Generer prosessflyt" — sat greyed out until the description field was
 * filled, and that field's label was for screen readers only, below a read-only card headed
 * "Prosessbeskrivelse". So the tab read as a dead end on the one process that needed it most.
 *
 * Nothing here presses the generate button: whether the model answers is a separate question from
 * whether the page lets the user get as far as asking, and this test must not spend a provider
 * call to find out.
 */
test('a process with no flow can be described, and the button wakes up with the text', async ({ page }) => {
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

    const generate = page.getByRole('button', { name: 'Generer prosessflyt' });
    await expect(generate).toBeDisabled();

    const paint = () => generate.evaluate((el) => {
        const style = getComputedStyle(el);

        return `${style.backgroundColor}|${style.color}`;
    });

    const off = await paint();

    await description.fill(
        'Når et avvik meldes registrerer saksbehandler det. Deretter vurderer kvalitetsleder avviket og lukker det.',
    );

    await expect(generate).toBeEnabled();

    // And it has to look it. The button was disabled by fading the role's own colours, and
    // Procynia's primary is a tint rather than a fill — six tenths of a tint on a white card is
    // still a tint, so waking up changed almost nothing on screen and the user kept waiting for a
    // button that was already theirs to press. The states must differ in colour, not only in the
    // accessibility tree.
    await expect.poll(paint).not.toBe(off);
});
