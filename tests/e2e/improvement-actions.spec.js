import { expect, test } from '@playwright/test';
import { USER, loginAs } from './helpers/auth.js';
import { cleanUpImprovementE2eData, improvementE2eName, improvementE2eSuffix, improvementFixture } from './helpers/improvements.js';
import { DESKTOP, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';

const suffix = improvementE2eSuffix();
cleanUpImprovementE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'improvement-actions', name);

/** Removes the browser's own required checks, so the server's messages are what the person sees. */
async function dropRequired(scope) {
    await scope.locator('[required]').evaluateAll((elements) => elements.forEach((element) => element.removeAttribute('required')));
}

/**
 * Årsak og bakgrunn → Tiltak → Ansvarlig → Frist → Gjennomføring, end to end: an avvik is registered,
 * its cause written, a tiltak added and started; closing the case is refused while the tiltak is
 * under arbeid; the tiltak is completed with «Hva ble gjort?» and the case closes. The case and the
 * tiltak are then reopened and the tiltak completed again — and the first completion is still in the
 * history. Every step is checked for text under 16 px and sideways scrolling on desktop and phone.
 */
test('a tiltak is added, started, completed, reopened and completed again, and holds the case open until done', async ({ page }) => {
    test.setTimeout(240_000);

    const { area_name: areaName } = await improvementFixture(`seedHandler('${suffix}')`);
    const title = improvementE2eName(suffix, 'Feil versjon av rutine brukt');
    const actionTitle = 'Erstatt rutinen i mottaket';

    await loginAs(page, USER.email, USER.password);
    await page.setViewportSize(DESKTOP);

    // Registrer avvik.
    await page.goto('/app/improvements');
    await page.getByRole('button', { name: 'Registrer avvik' }).click();
    await page.locator('#improvement-title').fill(title);
    await page.locator('#improvement-description').fill('Mottakskontrollen brukte versjon 2 av rutinen.');
    await page.locator('#improvement-area').selectOption({ label: areaName });
    await page.locator('#improvement-owner').selectOption({ label: 'E2E User' });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/improvements\/\d+$/);
    await expect(page.getByText('Saken er registrert.')).toBeVisible();

    const header = page.locator('header').filter({ has: page.getByRole('heading', { level: 1 }) });
    const cause = page.getByTestId('improvement-cause');
    const actions = page.getByTestId('improvement-actions');
    await expect(cause.getByRole('heading', { name: 'Årsak og bakgrunn' })).toBeVisible();
    await expect(cause).toContainText('Ikke beskrevet ennå.');
    await expect(actions).toContainText('Ingen tiltak er lagt inn ennå.');
    await expectPageHelp(page, 'Om saken', ['Status', 'Årsak og bakgrunn', 'Tiltak', 'Lukke, avbryte og gjenåpne', 'Historikk']);
    await expectReadable(page, '01-new-case');

    // Årsak og bakgrunn: the avvik hint, then saved.
    await cause.getByRole('button', { name: 'Beskriv årsak og bakgrunn' }).click();
    await expect(page.locator('#improvement-cause-hint')).toHaveText('Beskriv hvorfor avviket oppstod, dersom årsaken er kjent.');
    await page.locator('#improvement-cause').fill('Ny versjon av rutinen ble publisert, men ikke distribuert til mottaket.');
    await expectReadable(page, '02-cause-form');
    await cause.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Årsak og bakgrunn er lagret.')).toBeVisible();
    await expect(page.getByTestId('improvement-cause-text')).toHaveText('Ny versjon av rutinen ble publisert, men ikke distribuert til mottaket.');

    // Nytt tiltak: title, owner and frist are required, and the server says so in Norwegian.
    await actions.getByRole('button', { name: 'Nytt tiltak' }).click();
    const newForm = page.getByRole('form', { name: 'Nytt tiltak' });
    await expect(newForm.locator('#improvement-action-status')).toHaveCount(0);
    await dropRequired(newForm);
    await newForm.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(newForm.getByText('Tittel må fylles ut.')).toBeVisible();
    await expect(newForm.getByText('Ansvarlig må fylles ut.')).toBeVisible();
    await expect(newForm.getByText('Frist må fylles ut.')).toBeVisible();
    await page.locator('#improvement-action-title').fill(actionTitle);
    await page.locator('#improvement-action-description').fill('Bytt ut papirversjonen og oppdater lenken i håndboken.');
    await page.locator('#improvement-action-owner').selectOption({ label: 'E2E User' });
    await page.locator('#improvement-action-due-date').fill('2030-06-30');
    await expectReadable(page, '03-new-action-form');
    await newForm.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Tiltaket er lagt inn.')).toBeVisible();

    // Ansvarlig and frist on the card; a new tiltak is planned.
    const card = page.getByTestId('improvement-action').filter({ hasText: actionTitle });
    await expect(card).toContainText('Planlagt');
    await expect(card.getByTestId('improvement-action-owner')).toHaveText('E2E User');
    await expect(card.getByTestId('improvement-action-due-date')).toHaveText('30.06.2030');
    await expect(card.getByRole('button', { name: 'Slett' })).toBeVisible();
    // A case with tiltak is no longer a mistaken registration that may be deleted.
    await expect(page.getByRole('button', { name: 'Slett sak' })).toHaveCount(0);
    await expectReadable(page, '04-action-planned');

    // Start tiltak: no comment, and the case's own status does not follow.
    await card.getByRole('button', { name: 'Start tiltak' }).click();
    await expect(page.getByText('Tiltaket er startet.')).toBeVisible();
    await expect(card).toContainText('Under arbeid');
    await expect(header).toContainText('Åpen');
    await expect(card.getByRole('button', { name: 'Slett' })).toHaveCount(0);

    // Lukk sak is refused while the tiltak is under arbeid.
    await page.getByTestId('improvement-handling').getByRole('button', { name: 'Lukk sak' }).click();
    await page.locator('#improvement-close-note').fill('Rutinen er erstattet.');
    await page.getByRole('button', { name: 'Lukk sak', exact: true }).last().click();
    await expect(page.getByText('Saken har tiltak som ikke er ferdig behandlet. Fullfør eller avbryt tiltakene før saken lukkes.')).toBeVisible();
    await expectReadable(page, '05-close-refused');
    await page.reload();
    await expect(header).toContainText('Åpen');

    // Fullfør tiltak: «Hva ble gjort?» is required.
    await card.getByRole('button', { name: 'Fullfør tiltak' }).click();
    const completeForm = card.getByRole('form', { name: 'Fullfør tiltak' });
    await dropRequired(completeForm);
    await completeForm.getByRole('button', { name: 'Fullfør tiltak' }).click();
    await expect(completeForm.getByText('Skriv hva som ble gjort.')).toBeVisible();
    await page.locator('#improvement-action-completion-note').fill('Erstattet papirversjonen og oppdaterte lenken i håndboken.');
    await expectReadable(page, '06-complete-form');
    await completeForm.getByRole('button', { name: 'Fullfør tiltak' }).click();
    await expect(page.getByText('Tiltaket er fullført.')).toBeVisible();
    await expect(card).toContainText('Fullført');
    await expect(card.getByTestId('improvement-action-completion')).toHaveText('Erstattet papirversjonen og oppdaterte lenken i håndboken.');
    await expect(card).toContainText(/Fullført \d{1,2}\. \S+ \d{4} av E2E User\./);

    // Historikk, newest first.
    await card.getByText('Vis historikk (2)').click();
    const history = card.getByTestId('improvement-action-history');
    await expect(history.locator('li')).toHaveCount(2);
    await expect(history.locator('li').nth(0)).toContainText('Fullført av E2E User');
    await expect(history.locator('li').nth(0)).toContainText('Erstattet papirversjonen');
    await expect(history.locator('li').nth(1)).toContainText('Startet av E2E User');
    await expectReadable(page, '07-action-completed');

    // Now the case closes.
    await page.getByTestId('improvement-handling').getByRole('button', { name: 'Lukk sak' }).click();
    await page.locator('#improvement-close-note').fill('Rutinen er erstattet i mottaket.');
    await page.getByRole('button', { name: 'Lukk sak', exact: true }).last().click();
    await expect(page.getByText('Saken er lukket.')).toBeVisible();
    await expect(header).toContainText('Lukket');
    // An ended case leaves its tiltak and cause as they stood.
    for (const name of ['Nytt tiltak', 'Gjenåpne tiltak', 'Endre']) {
        await expect(page.getByRole('button', { name, exact: true })).toHaveCount(0);
    }
    await expectReadable(page, '08-case-closed');

    // Gjenåpne the case, then the tiltak.
    await page.getByRole('button', { name: 'Gjenåpne', exact: true }).click();
    await page.locator('#improvement-reopen-reason').fill('Kontrollen fant papirversjonen i et annet mottak.');
    await page.getByRole('button', { name: 'Gjenåpne sak' }).click();
    await expect(page.getByText('Saken er gjenåpnet.')).toBeVisible();
    await expect(header).toContainText('Åpen');

    await card.getByRole('button', { name: 'Gjenåpne tiltak' }).click();
    const reopenForm = card.getByRole('form', { name: 'Gjenåpne tiltak' });
    await dropRequired(reopenForm);
    await reopenForm.getByRole('button', { name: 'Gjenåpne tiltak' }).click();
    await expect(reopenForm.getByText('Begrunnelse må fylles ut.')).toBeVisible();
    await page.locator('#improvement-action-reopen-reason').fill('Mottak 2 hadde fortsatt papirversjonen.');
    await reopenForm.getByRole('button', { name: 'Gjenåpne tiltak' }).click();
    await expect(page.getByText('Tiltaket er gjenåpnet.')).toBeVisible();
    await expect(card).toContainText('Planlagt');
    await expect(card.getByTestId('improvement-action-completion')).toHaveCount(0);
    // It has history, so it is cancelled rather than deleted.
    await expect(card.getByRole('button', { name: 'Slett' })).toHaveCount(0);

    // Fullfør again.
    await card.getByRole('button', { name: 'Fullfør tiltak' }).click();
    await page.locator('#improvement-action-completion-note').fill('Fjernet papirversjonen også i mottak 2.');
    await card.getByRole('form', { name: 'Fullfør tiltak' }).getByRole('button', { name: 'Fullfør tiltak' }).click();
    await expect(page.getByText('Tiltaket er fullført.')).toBeVisible();
    await expect(card.getByTestId('improvement-action-completion')).toHaveText('Fjernet papirversjonen også i mottak 2.');

    // The first completion is still in the history.
    await card.getByText('Vis historikk (4)').click();
    await expect(history.locator('li')).toHaveCount(4);
    await expect(history.locator('li').nth(0)).toContainText('Fullført av E2E User');
    await expect(history.locator('li').nth(0)).toContainText('Fjernet papirversjonen også i mottak 2.');
    await expect(history.locator('li').nth(1)).toContainText('Gjenåpnet av E2E User');
    await expect(history.locator('li').nth(1)).toContainText('Mottak 2 hadde fortsatt papirversjonen.');
    await expect(history.locator('li').nth(2)).toContainText('Fullført av E2E User');
    await expect(history.locator('li').nth(2)).toContainText('Erstattet papirversjonen og oppdaterte lenken i håndboken.');
    await expect(history.locator('li').nth(3)).toContainText('Startet av E2E User');
    await expectReadable(page, '09-completed-again');
});

/**
 * Someone with improvement.view and nothing more sees the tiltak — even one they own — and is offered
 * no way to add, change or move it, nor to write the cause.
 */
test('a reader sees the tiltak but cannot add, edit or move them', async ({ page }) => {
    test.setTimeout(90_000);

    const { visible_id: visibleId, action_title: actionTitle } = await improvementFixture(`seedViewOnly('${suffix}')`);

    await loginAs(page, USER.email, USER.password);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/improvements/${visibleId}`);
    await expect(page.getByRole('heading', { name: improvementE2eName(suffix, 'Synlig avvik'), level: 1 })).toBeVisible();

    const card = page.getByTestId('improvement-action').filter({ hasText: actionTitle });
    await expect(card).toContainText('Under arbeid');
    await expect(card.getByTestId('improvement-action-owner')).toHaveText('E2E User');
    await card.getByText('Vis historikk (1)').click();
    await expect(card.getByTestId('improvement-action-history')).toContainText('Startet av E2E User');

    for (const name of ['Nytt tiltak', 'Start tiltak', 'Fullfør tiltak', 'Rediger', 'Avbryt tiltak', 'Gjenåpne tiltak', 'Slett', 'Beskriv årsak og bakgrunn', 'Endre']) {
        await expect(page.getByRole('button', { name, exact: true }), name).toHaveCount(0);
    }

    await expectReadable(page, '10-reader');
});
