import { expect, test } from '@playwright/test';
import { USER, loginAs } from './helpers/auth.js';
import { expectNoAxeViolations } from './helpers/a11y.js';
import { cleanUpImprovementE2eData, improvementE2eName, improvementE2eSuffix, improvementFixture } from './helpers/improvements.js';
import { DESKTOP, sidewaysOverflow } from './helpers/readability.js';

/**
 * WCAG Fase 6 — Avvik og forbedringer, in a real browser (docs/wcag-accessibility-plan.md §8). The
 * rules are pinned in resources/js/Support/a11yPhase6Improvements.test.js; this spec proves the
 * behaviour: refused saves put focus on the field that says why, every inline panel takes focus in
 * and gives it back, a deleted tiltak hands focus to the next one, the register is a named table
 * whose hit count is spoken, and status is words. ImprovementE2EFixture::seedHandler gives the shared
 * E2E user one area with every case permission; everything is named by improvementE2eName and
 * removed after the test.
 */

const suffix = improvementE2eSuffix();
cleanUpImprovementE2eData(suffix);

const polite = (page) => page.locator('#procynia-live-polite');
const focusedId = (page) => page.evaluate(() => document.activeElement?.id ?? '');

/** Removes the browser's own required checks, so the server's answer is what is tested. */
async function dropRequired(scope) {
    await scope.locator('[required]').evaluateAll((elements) => elements.forEach((element) => element.removeAttribute('required')));
}

test('Avvik og forbedringer: register, case, cause, tiltak and lifecycle work by keyboard and screen reader', async ({ page }) => {
    test.setTimeout(240_000);

    const { area_name: areaName } = await improvementFixture(`seedHandler('${suffix}')`);
    const title = improvementE2eName(suffix, 'Feil merking av prøver');

    await loginAs(page, USER.email, USER.password);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/improvements');

    // Registrer avvik opens the form in place of the button: focus to the form (named by its heading),
    // the star is explained, and Avbryt gives focus back to the button.
    const register = page.getByRole('button', { name: 'Registrer avvik' });
    await register.click();
    const createSection = page.locator('section[aria-labelledby="improvement-create-heading"]');
    await expect(createSection).toBeFocused();
    await expect(createSection.getByText('Felt merket med * må fylles ut.')).toBeVisible();
    await expectNoAxeViolations(page, 'section[aria-labelledby="improvement-create-heading"]', 'Registrer avvik');
    await createSection.getByRole('button', { name: 'Avbryt' }).click();
    await expect(register).toBeFocused();

    // A refused save: focus to the first invalid field, which is marked and described by its error.
    await register.click();
    await dropRequired(createSection);
    await createSection.getByRole('button', { name: 'Lagre', exact: true }).click();
    const titleField = page.locator('#improvement-title');
    await expect(titleField).toBeFocused();
    await expect(titleField).toHaveAttribute('aria-invalid', 'true');
    await expect(titleField).toHaveAttribute('aria-describedby', /improvement-title-hint improvement-title-error/);
    await expect(page.locator('#improvement-title-error')).toHaveText('Tittel må fylles ut.');
    await expect(page.locator('#improvement-owner')).toHaveAttribute('aria-invalid', 'true');

    await titleField.fill(title);
    await page.locator('#improvement-description').fill('Prøvene fra linje 2 manglet dato.');
    await page.locator('#improvement-area').selectOption({ label: areaName });
    await page.locator('#improvement-owner').selectOption({ label: 'E2E User' });
    await createSection.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/improvements\/\d+$/);
    const caseUrl = page.url();

    // The register: a named table with column and row headers; the hit count is said once a search answers.
    await page.goto('/app/improvements');
    const table = page.getByRole('table', { name: 'Avvik og forbedringer' });
    await expect(table.getByRole('columnheader', { name: 'Frist' })).toBeVisible();
    await expect(table.getByRole('rowheader', { name: new RegExp(title) })).toBeVisible();
    await expectNoAxeViolations(page, 'main', 'Avvik og forbedringer – register');

    await page.locator('#improvement-search').fill(title);
    await page.getByRole('button', { name: 'Søk', exact: true }).click();
    await expect(polite(page)).toHaveText('1 sak');
    await page.locator('#improvement-search').fill(`finnes ikke ${suffix}`);
    await page.getByRole('button', { name: 'Søk', exact: true }).click();
    await expect(polite(page)).toHaveText('Ingen saker passer søket.');
    await page.getByRole('button', { name: 'Nullstill' }).click();
    await expect(page.locator('#improvement-search')).toBeFocused();

    await page.setViewportSize({ width: 320, height: 800 });
    await expect.poll(() => sidewaysOverflow(page)).toEqual([]);
    await page.setViewportSize(DESKTOP);

    // The case page. Type and status are words in the header.
    await page.goto(caseUrl);
    const header = page.locator('header').filter({ has: page.getByRole('heading', { level: 1, name: title }) });
    await expect(header).toContainText('Avvik');

    // Rediger: focus to the form, named «Rediger»; Avbryt gives it back.
    await header.getByRole('button', { name: 'Rediger', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Rediger' })).toBeFocused();
    await page.getByRole('region', { name: 'Rediger' }).getByRole('button', { name: 'Avbryt' }).click();
    await expect(header.getByRole('button', { name: 'Rediger', exact: true })).toBeFocused();

    // Årsak og bakgrunn: focus to the text, and back to the section's button after saving.
    const cause = page.getByTestId('improvement-cause');
    await cause.getByRole('button', { name: 'Beskriv årsak og bakgrunn' }).click();
    await expect(page.locator('#improvement-cause')).toBeFocused();
    await expect(page.locator('#improvement-cause')).toHaveAttribute('aria-describedby', 'improvement-cause-hint');
    await page.locator('#improvement-cause').fill('Etikettskriveren var feil innstilt.');
    await cause.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Årsak og bakgrunn er lagret.')).toBeVisible();
    await expect.poll(() => focusedId(page)).toBe('improvement-cause-edit');

    // Nytt tiltak: focus in; a refused save marks Tittel; saved, focus back to «Nytt tiltak».
    const actions = page.getByTestId('improvement-actions');
    const addAction = async (actionTitle) => {
        await actions.getByRole('button', { name: 'Nytt tiltak' }).click();
        await expect(page.locator('#improvement-action-title')).toBeFocused();
        await page.locator('#improvement-action-title').fill(actionTitle);
        await page.locator('#improvement-action-owner').selectOption({ label: 'E2E User' });
        await page.locator('#improvement-action-due-date').fill('2030-01-15');
        await actions.getByRole('button', { name: 'Lagre', exact: true }).click();
        await expect(actions.getByRole('heading', { name: actionTitle })).toBeVisible();
        await expect.poll(() => focusedId(page)).toBe('improvement-action-create');
    };

    await actions.getByRole('button', { name: 'Nytt tiltak' }).click();
    const newForm = actions.getByRole('form', { name: 'Nytt tiltak' });
    await dropRequired(newForm);
    await newForm.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.locator('#improvement-action-title')).toBeFocused();
    await expect(page.locator('#improvement-action-title')).toHaveAttribute('aria-invalid', 'true');
    await newForm.getByRole('button', { name: 'Avbryt' }).click();
    await expect(actions.getByRole('button', { name: 'Nytt tiltak' })).toBeFocused();

    await addAction('Juster etikettskriveren');
    await addAction('Registrert ved en feil');

    // Every card button is described by its tiltak; the status is a word.
    const card = actions.getByTestId('improvement-action').filter({ hasText: 'Juster etikettskriveren' });
    await expect(card.getByRole('button', { name: 'Start tiltak' })).toHaveAccessibleDescription('Juster etikettskriveren');
    await expect(card).toContainText('Planlagt');
    await expectNoAxeViolations(page, '[data-testid="improvement-actions"]', 'Tiltak');

    // Start tiltak: the button goes; focus to what the tiltak offers now, not the page.
    await card.getByRole('button', { name: 'Start tiltak' }).click();
    await expect(page.getByText('Tiltaket er startet.')).toBeVisible();
    await expect.poll(() => focusedId(page)).toMatch(/^improvement-action-\d+-(complete|verify|edit|cancel)$/);

    // Lukk sak while a tiltak is under arbeid: refused on the field, which takes focus; Avbryt returns.
    const handling = page.getByTestId('improvement-handling');
    await handling.getByRole('button', { name: 'Lukk sak', exact: true }).click();
    const closeNote = page.locator('#improvement-close-note');
    await expect(closeNote).toBeFocused();
    await closeNote.fill('Rettet.');
    await page.locator('section[aria-labelledby="improvement-close-note-heading"]').getByRole('button', { name: 'Lukk sak', exact: true }).click();
    await expect(closeNote).toBeFocused();
    await expect(closeNote).toHaveAttribute('aria-invalid', 'true');
    await expect(closeNote).toHaveAttribute('aria-describedby', 'improvement-close-note-error');
    await expect(page.locator('#improvement-close-note-error')).toContainText('tiltak');
    await page.locator('section[aria-labelledby="improvement-close-note-heading"]').getByRole('button', { name: 'Avbryt' }).click();
    await expect(handling.getByRole('button', { name: 'Lukk sak', exact: true })).toBeFocused();

    // Fullfør tiltak: focus to «Hva ble gjort?»; done, focus to the tiltak's next step.
    await card.getByRole('button', { name: 'Fullfør tiltak' }).click();
    await expect(page.locator('#improvement-action-completion-note')).toBeFocused();
    await page.locator('#improvement-action-completion-note').fill('Skriveren er justert og kontrollert.');
    await card.getByRole('form', { name: 'Fullfør tiltak' }).getByRole('button', { name: 'Fullfør tiltak' }).click();
    await expect(page.getByText('Tiltaket er fullført.')).toBeVisible();
    await expect.poll(() => focusedId(page)).toMatch(/^improvement-action-\d+-(verify|reopen)$/);
    await expect(card.getByTestId('improvement-action-verification-awaiting')).toContainText('Venter på effektverifisering');

    // Slett the tiltak registered by mistake: window.confirm stays, and focus goes to the other tiltak.
    const mistaken = actions.getByTestId('improvement-action').filter({ hasText: 'Registrert ved en feil' });
    page.once('dialog', (dialog) => dialog.accept());
    await mistaken.getByRole('button', { name: 'Slett' }).click();
    await expect(actions.getByRole('heading', { name: 'Registrert ved en feil' })).toHaveCount(0);
    await expect.poll(() => focusedId(page)).toMatch(/^improvement-action-\d+-title$/);

    await expectNoAxeViolations(page, '[data-testid="improvement-handling"]', 'Behandling');
    await expectNoAxeViolations(page, '[data-testid="improvement-actions"]', 'Tiltak');

    await page.setViewportSize({ width: 320, height: 800 });
    await expect.poll(() => sidewaysOverflow(page)).toEqual([]);
    await page.setViewportSize(DESKTOP);
});
