import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';
import { cleanUpImprovementE2eData, improvementE2eName, improvementE2eSuffix, improvementFixture } from './helpers/improvements.js';
import { DESKTOP, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';

const suffix = improvementE2eSuffix();
cleanUpImprovementE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'improvements', name);

/** Yesterday as Y-m-d: a Hendelsesdato that is never in the future. */
function yesterday() {
    const day = new Date(Date.now() - 24 * 60 * 60 * 1000);

    return day.toISOString().slice(0, 10);
}

/**
 * Avvik og forbedringer, end to end: someone with a role for the area registers an avvik, opens
 * and edits it, starts the handling, closes it with a result, reads the history, reopens it with a
 * reason and reads the history again. Every page and form on the way is checked for text under
 * 16 px and sideways scrolling at desktop and phone width, and both pages' help is opened.
 */
test('an avvik is registered, edited, handled, closed and reopened, with its history kept', async ({ page }) => {
    test.setTimeout(180_000);

    const { area_name: areaName } = await improvementFixture(`seedHandler('${suffix}')`);
    const title = improvementE2eName(suffix, 'Feil versjon av rutine brukt');
    const editedTitle = improvementE2eName(suffix, 'Utgått rutine brukt ved mottakskontroll');

    await loginAs(page, USER.email, USER.password);
    await page.setViewportSize(DESKTOP);

    // Åpne Avvik og forbedringer from the rail.
    await page.goto('/app/dashboard');
    await page.getByRole('link', { name: 'Avvik og forbedringer' }).first().click();
    await page.waitForURL(/\/app\/improvements$/);
    await expect(page.getByRole('heading', { name: 'Avvik og forbedringer', level: 1 })).toBeVisible();
    await expectPageHelp(page, 'Om Avvik og forbedringer', ['Avvik eller forbedring', 'Saken']);
    await expectReadable(page, '01-register');

    // Registrer avvik: type chosen, no status field, the avvik hint, and the server's own messages.
    // (With one area to edit in, the area is already chosen.)
    await page.getByRole('button', { name: 'Registrer avvik' }).click();
    await expect(page.locator('#improvement-type-deviation')).toBeChecked();
    await expect(page.locator('#improvement-status')).toHaveCount(0);
    await expect(page.locator('#improvement-description-hint')).toHaveText('Beskriv hva som skjedde, og hva som var forventet.');
    await page.locator('#improvement-type-improvement').check();
    await expect(page.locator('#improvement-description-hint')).toHaveText('Beskriv hva som kan forbedres, og hvorfor det vil være nyttig.');
    await expect(page.locator('#improvement-occurred-at')).toHaveCount(0);
    await page.locator('#improvement-type-deviation').check();

    await page.locator('form [required]').evaluateAll((elements) => elements.forEach((element) => element.removeAttribute('required')));
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Tittel må fylles ut.')).toBeVisible();
    await expect(page.getByText('Beskrivelse må fylles ut.')).toBeVisible();
    await expect(page.getByText('Ansvarlig må fylles ut.')).toBeVisible();

    await page.locator('#improvement-title').fill(title);
    await page.locator('#improvement-description').fill('Mottakskontrollen brukte versjon 2 av rutinen. Versjon 3 skulle vært brukt.');
    await page.locator('#improvement-area').selectOption({ label: areaName });
    await page.locator('#improvement-owner').selectOption({ label: 'E2E User' });
    await page.locator('#improvement-occurred-at').fill(yesterday());
    await page.locator('#improvement-due-date').fill('2030-06-30');
    await expectReadable(page, '02-register-form');
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();

    await page.waitForURL(/\/app\/improvements\/\d+$/);
    const caseUrl = page.url();
    await expect(page.getByText('Saken er registrert.')).toBeVisible();
    await expect(page.getByRole('heading', { name: title, level: 1 })).toBeVisible();
    const header = page.locator('header').filter({ has: page.getByRole('heading', { level: 1 }) });
    await expect(header).toContainText('Avvik');
    await expect(header).toContainText('Åpen');
    await expect(header).toContainText('30.06.2030');
    await expect(page.getByTestId('improvement-history')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Slett sak' })).toBeVisible();

    // The register lists it with type, area, owner, status and frist; open it from there.
    await page.goto(`/app/improvements?search=${encodeURIComponent(`E2E Avvik ${suffix}`)}`);
    const row = page.locator('tbody tr', { hasText: title });
    for (const text of ['Avvik', areaName, 'E2E User', 'Åpen', '30.06.2030']) {
        await expect(row).toContainText(text);
    }
    await expectReadable(page, '03-register-with-case');
    await row.getByRole('link', { name: title }).click();
    await page.waitForURL(caseUrl);

    // Rediger: the title changes, the status does not.
    await page.getByRole('button', { name: 'Rediger' }).click();
    await page.locator('#improvement-title').fill(editedTitle);
    await expectReadable(page, '04-edit');
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Saken er oppdatert.')).toBeVisible();
    await expect(page.getByRole('heading', { name: editedTitle, level: 1 })).toBeVisible();
    await expect(header).toContainText('Åpen');

    // Start behandling: no comment needed.
    await page.getByRole('button', { name: 'Start behandling' }).click();
    await expect(page.getByText('Behandlingen er startet.')).toBeVisible();
    await expect(header).toContainText('Under arbeid');
    const history = page.getByTestId('improvement-history');
    await expect(history.locator('li')).toHaveCount(1);
    await expect(history.locator('li').first()).toContainText('Behandling startet av E2E User');
    // Handled: no longer a mistaken registration that may be deleted.
    await expect(page.getByRole('button', { name: 'Slett sak' })).toHaveCount(0);

    // Lukk sak: the result is required, and the server says so in Norwegian.
    await page.getByRole('button', { name: 'Lukk sak' }).click();
    await expectReadable(page, '05-close-form');
    await page.locator('#improvement-close-note').evaluate((element) => element.removeAttribute('required'));
    await page.getByRole('button', { name: 'Lukk sak', exact: true }).last().click();
    await expect(page.getByText('Resultat må fylles ut.')).toBeVisible();
    await page.locator('#improvement-close-note').fill('Rutinen er erstattet i mottaket, og gammel versjon er fjernet.');
    await page.getByRole('button', { name: 'Lukk sak', exact: true }).last().click();
    await expect(page.getByText('Saken er lukket.')).toBeVisible();
    await expect(header).toContainText('Lukket');
    await expect(page.getByTestId('improvement-closing-note')).toHaveText('Rutinen er erstattet i mottaket, og gammel versjon er fjernet.');
    await expect(page.getByTestId('improvement-handling')).toContainText(/Lukket \d{1,2}\. \S+ \d{4} av E2E User\./);

    // Historikk: newest first.
    await expect(history.locator('li')).toHaveCount(2);
    await expect(history.locator('li').nth(0)).toContainText('Lukket av E2E User');
    await expect(history.locator('li').nth(0)).toContainText('Rutinen er erstattet i mottaket');
    await expect(history.locator('li').nth(1)).toContainText('Behandling startet av E2E User');

    // A closed case is reopened, not edited.
    await expect(page.getByRole('button', { name: 'Rediger' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Start behandling' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Avbryt sak' })).toHaveCount(0);
    await expectPageHelp(page, 'Om saken', ['Status', 'Lukke, avbryte og gjenåpne', 'Historikk']);
    await expectReadable(page, '06-closed');

    // Gjenåpne: the reason is required.
    await page.getByRole('button', { name: 'Gjenåpne' }).click();
    await page.locator('#improvement-reopen-reason').evaluate((element) => element.removeAttribute('required'));
    await page.getByRole('button', { name: 'Gjenåpne sak' }).click();
    await expect(page.getByText('Begrunnelse må fylles ut.')).toBeVisible();
    await page.locator('#improvement-reopen-reason').fill('Samme feil ble funnet igjen ved neste kontroll.');
    await page.getByRole('button', { name: 'Gjenåpne sak' }).click();
    await expect(page.getByText('Saken er gjenåpnet.')).toBeVisible();
    await expect(header).toContainText('Åpen');
    await expect(page.getByTestId('improvement-closing-note')).toHaveCount(0);

    // Historikk: the closing is still there, note and all.
    await expect(history.locator('li')).toHaveCount(3);
    await expect(history.locator('li').nth(0)).toContainText('Gjenåpnet av E2E User');
    await expect(history.locator('li').nth(0)).toContainText('Samme feil ble funnet igjen ved neste kontroll.');
    await expect(history.locator('li').nth(1)).toContainText('Lukket av E2E User');
    await expect(history.locator('li').nth(1)).toContainText('Rutinen er erstattet i mottaket');
    await expect(history.locator('li').nth(2)).toContainText('Behandling startet av E2E User');
    await expect(page.getByRole('button', { name: 'Rediger' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Slett sak' })).toHaveCount(0);
    await expectReadable(page, '07-reopened');
});

/**
 * Someone who may only read sees the cases of their area and can change nothing; a case in another
 * area cannot be found by search, count or URL. System Owner without a role for the data reaches
 * the module and sees no case at all.
 */
test('a reader sees only their area and is offered no changes, and System Owner reads nothing without a role', async ({ page }) => {
    test.setTimeout(90_000);

    const { visible_id: visibleId, in_progress_id: inProgressId, hidden_id: hiddenId } = await improvementFixture(`seedViewOnly('${suffix}')`);
    const visibleTitle = improvementE2eName(suffix, 'Synlig avvik');
    const inProgressTitle = improvementE2eName(suffix, 'Synlig forbedring');
    const hiddenTitle = improvementE2eName(suffix, 'Skjult avvik');

    await loginAs(page, USER.email, USER.password);
    await page.setViewportSize(DESKTOP);

    await page.goto(`/app/improvements?search=${encodeURIComponent(`E2E Avvik ${suffix}`)}`);
    await expect(page.locator('tbody tr', { hasText: visibleTitle })).toBeVisible();
    await expect(page.locator('tbody tr', { hasText: inProgressTitle })).toContainText('Under arbeid');
    await expect(page.getByText(hiddenTitle)).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Registrer avvik' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Registrer forbedring' })).toHaveCount(0);
    await expectReadable(page, '08-reader-register');

    for (const [id, title] of [[visibleId, visibleTitle], [inProgressId, inProgressTitle]]) {
        await page.goto(`/app/improvements/${id}`);
        await expect(page.getByRole('heading', { name: title, level: 1 })).toBeVisible();

        for (const action of ['Rediger', 'Start behandling', 'Lukk sak', 'Avbryt sak', 'Gjenåpne', 'Slett sak']) {
            await expect(page.getByRole('button', { name: action })).toHaveCount(0);
        }
    }

    await expectReadable(page, '09-reader-case');

    const hidden = await page.goto(`/app/improvements/${hiddenId}`);
    expect(hidden.status()).toBe(404);

    // System Owner administers roles and areas, but reads no case without a role of their own.
    await page.context().clearCookies();
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto(`/app/improvements?search=${encodeURIComponent(`E2E Avvik ${suffix}`)}`);
    await expect(page.getByRole('heading', { name: 'Avvik og forbedringer', level: 1 })).toBeVisible();
    await expect(page.getByText(/^(Ingen fagområder er opprettet ennå|Du har ikke tilgang til noen fagområder i Avvik og forbedringer ennå)$/)).toBeVisible();
    await expect(page.getByText(visibleTitle)).toHaveCount(0);

    for (const id of [visibleId, hiddenId]) {
        const response = await page.goto(`/app/improvements/${id}`);
        expect(response.status()).toBe(404);
    }
});
