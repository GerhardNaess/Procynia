import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { cleanUpImprovementE2eData, improvementE2eName, improvementE2eSuffix, improvementFixture } from './helpers/improvements.js';
import { DESKTOP, PHONE, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';

const suffix = improvementE2eSuffix();
const password = 'E2eUser123!';
cleanUpImprovementE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'improvement-verification', name);

const NOT_VERIFIED = 'Ett eller flere fullførte tiltak er ikke effektverifisert. Verifiser effekten før saken lukkes.';
const NOT_EFFECTIVE = 'Ett eller flere tiltak er vurdert som ikke effektive. Følg opp tiltakene før saken lukkes.';

/** «30. september 2026», as the server writes dates in the attention reasons. */
function longDate(isoDay) {
    const [year, month, day] = isoDay.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString('nb-NO', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
}

/** Opens a category on the register's panel and returns its list items. */
async function openCategory(page, key) {
    const category = page.getByTestId(`improvement-attention-category-${key}`);
    await category.getByRole('button', { name: 'Vis' }).click();

    return category.getByTestId('improvement-attention-item');
}

async function tryToClose(page, note) {
    await page.getByTestId('improvement-handling').getByRole('button', { name: 'Lukk sak' }).click();
    await page.locator('#improvement-close-note').fill(note);
    await page.getByRole('button', { name: 'Lukk sak', exact: true }).last().click();
}

async function verify(card, page, result, note) {
    await card.getByRole('button', { name: 'Verifiser effekt' }).click();
    const form = card.getByRole('form', { name: 'Verifiser effekt' });
    await page.locator(`#improvement-action-verify-${result}`).check();
    await page.locator('#improvement-action-verify-note').fill(note);

    return form;
}

/**
 * Effektverifisering end to end: an avvik with one tiltak, started and completed. Closing is refused
 * while the effect is unverified; the tiltak is judged Ikke effektivt, which the register's
 * Trenger oppmerksomhet panel picks up and closing still refuses. The tiltak is reopened, completed
 * again — now awaiting a new judgement — and judged Effekt bekreftet, with the first judgement kept
 * as history. Then the case closes and the panel has nothing left.
 */
test('a tiltak is verified not effective, reopened, verified effective, and then the case closes', async ({ page }) => {
    test.setTimeout(240_000);

    const { area_name: areaName, handler_name: handlerName, handler_email: handlerEmail } = await improvementFixture(`seedJourney('${suffix}', '${password}')`);
    const title = improvementE2eName(suffix, 'Feil merking av prøver');
    const actionTitle = 'Ny etikettmal i laboratoriet';

    await loginAs(page, handlerEmail, password);
    await page.setViewportSize(DESKTOP);

    // A fresh person: the register's panel starts empty.
    await page.goto('/app/improvements');
    await expect(page.getByTestId('improvement-attention-empty')).toHaveText('Ingen saker eller tiltak trenger oppmerksomhet akkurat nå.');

    // Registrer avvik.
    await page.getByRole('button', { name: 'Registrer avvik' }).click();
    await page.locator('#improvement-title').fill(title);
    await page.locator('#improvement-description').fill('Prøver ble merket med feil dato.');
    await page.locator('#improvement-area').selectOption({ label: areaName });
    await page.locator('#improvement-owner').selectOption({ label: handlerName });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/improvements\/\d+$/);
    const caseUrl = page.url();

    // Opprett tiltak → start → fullfør.
    const actions = page.getByTestId('improvement-actions');
    await actions.getByRole('button', { name: 'Nytt tiltak' }).click();
    await page.locator('#improvement-action-title').fill(actionTitle);
    await page.locator('#improvement-action-owner').selectOption({ label: handlerName });
    await page.locator('#improvement-action-due-date').fill('2030-06-30');
    await page.getByRole('form', { name: 'Nytt tiltak' }).getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Tiltaket er lagt inn.')).toBeVisible();

    const card = page.getByTestId('improvement-action').filter({ hasText: actionTitle });
    await card.getByRole('button', { name: 'Start tiltak' }).click();
    await expect(page.getByText('Tiltaket er startet.')).toBeVisible();
    // Under arbeid: no effektverifisering yet.
    await expect(card.getByTestId('improvement-action-verification')).toHaveCount(0);
    await expect(card.getByRole('button', { name: 'Verifiser effekt' })).toHaveCount(0);

    await card.getByRole('button', { name: 'Fullfør tiltak' }).click();
    await page.locator('#improvement-action-completion-note').fill('Innførte ny etikettmal med dato fra systemet.');
    await card.getByRole('form', { name: 'Fullfør tiltak' }).getByRole('button', { name: 'Fullfør tiltak' }).click();
    await expect(page.getByText('Tiltaket er fullført.')).toBeVisible();
    await expect(card.getByTestId('improvement-action-verification-awaiting')).toContainText('Venter på effektverifisering');
    await expect(page.getByTestId('improvement-attention-note')).toContainText('1 tiltak trenger oppmerksomhet');
    await expectPageHelp(page, 'Om saken', ['Tiltak', 'Effektverifisering', 'Lukke, avbryte og gjenåpne']);
    await expectReadable(page, '01-awaiting-verification');

    // Lukk sak: the effect is not verified.
    await tryToClose(page, 'Etikettene er riktige nå.');
    await expect(page.getByText(NOT_VERIFIED)).toBeVisible();
    await page.reload();

    // Verifiser effekt: result and comment are both required, and the server says so.
    let form = await verify(card, page, 'not_effective', '');
    await form.locator('[required]').evaluateAll((elements) => elements.forEach((element) => element.removeAttribute('required')));
    await form.getByRole('button', { name: 'Verifiser effekt' }).click();
    await expect(form.getByText('Kommentar må fylles ut.')).toBeVisible();
    await expectReadable(page, '02-verify-form');
    await page.locator('#improvement-action-verify-note').fill('Samme feil har oppstått på nytt etter at tiltaket ble gjennomført.');
    await form.getByRole('button', { name: 'Verifiser effekt' }).click();
    await expect(page.getByText('Effekten er ikke bekreftet. Gjenåpne tiltaket dersom det må arbeides videre med.').first()).toBeVisible();

    const current = card.getByTestId('improvement-action-verification-current');
    await expect(current).toContainText('Ikke effektivt');
    await expect(current).toContainText('Samme feil har oppstått på nytt');
    await expect(current).toContainText(new RegExp(`Vurdert \\d{1,2}\\. \\S+ \\d{4} av ${handlerName}\\.`));
    // Still completed: nothing reopened behind the person's back.
    await expect(card).toContainText('Fullført');
    await expect(card.getByRole('button', { name: 'Gjenåpne tiltak' })).toBeVisible();
    await expectReadable(page, '03-not-effective');

    // The register's panel picks it up, with the reason, and leads back to the tiltak.
    await page.goto('/app/improvements');
    const panel = page.getByTestId('improvement-attention');
    await expect(panel.getByTestId('improvement-attention-summary')).toHaveText('1 tiltak trenger oppmerksomhet');
    await expect(panel.locator('[data-testid^="improvement-attention-category-"]')).toHaveCount(1);
    const items = await openCategory(page, 'not_effective');
    await expect(items).toHaveCount(1);
    await expect(items.first()).toContainText(actionTitle);
    await expect(items.first()).toContainText(title);
    await expect(items.first()).toContainText('Siste effektverifisering konkluderte med at tiltaket ikke var effektivt.');
    // The light indicator: one tiltak, none open.
    await expect(page.getByTestId('improvement-action-indicator').filter({ visible: true })).toHaveText('1 tiltak');
    await expectReadable(page, '04-register-attention');
    await items.first().getByRole('link', { name: actionTitle }).click();
    await page.waitForURL(/#improvement-action-\d+$/);

    // Lukk sak: judged not effective.
    await tryToClose(page, 'Etikettene er riktige nå.');
    await expect(page.getByText(NOT_EFFECTIVE)).toBeVisible();
    await page.goto(caseUrl);

    // Gjenåpne tiltak → fullfør på nytt: the new completion awaits its own judgement.
    await card.getByRole('button', { name: 'Gjenåpne tiltak' }).click();
    await page.locator('#improvement-action-reopen-reason').fill('Malen må også hente dato fra prøvemottaket.');
    await card.getByRole('form', { name: 'Gjenåpne tiltak' }).getByRole('button', { name: 'Gjenåpne tiltak' }).click();
    await expect(page.getByText('Tiltaket er gjenåpnet.')).toBeVisible();
    await expect(card.getByTestId('improvement-action-verification-current')).toHaveCount(0);
    await card.getByRole('button', { name: 'Fullfør tiltak' }).click();
    await page.locator('#improvement-action-completion-note').fill('Malen henter nå dato fra prøvemottaket.');
    await card.getByRole('form', { name: 'Fullfør tiltak' }).getByRole('button', { name: 'Fullfør tiltak' }).click();
    await expect(page.getByText('Tiltaket er fullført.')).toBeVisible();
    await expect(card.getByTestId('improvement-action-verification-awaiting')).toBeVisible();
    await expect(card.getByTestId('improvement-action-verification-current')).toHaveCount(0);

    // Effekt bekreftet.
    form = await verify(card, page, 'effective', 'Ingen feilmerkede prøver på fire uker.');
    await form.getByRole('button', { name: 'Verifiser effekt' }).click();
    await expect(page.getByText('Effekten er bekreftet.')).toBeVisible();
    await expect(current).toContainText('Effekt bekreftet');
    await expect(current).toContainText('Ingen feilmerkede prøver på fire uker.');
    await expect(card.getByTestId('improvement-action-verification-awaiting')).toHaveCount(0);

    // The first judgement is still there, as history of the earlier completion.
    await card.getByText('Tidligere vurderinger (1)').click();
    const earlier = card.getByTestId('improvement-action-verification-history');
    await expect(earlier.locator('li')).toHaveCount(1);
    await expect(earlier).toContainText('Ikke effektivt');
    await expect(earlier).toContainText('Samme feil har oppstått på nytt etter at tiltaket ble gjennomført.');
    await expect(earlier).toContainText('Gjaldt en tidligere fullføring.');
    await expect(page.getByTestId('improvement-attention-note')).toHaveCount(0);
    await expectReadable(page, '05-effective-with-history');

    // Lukk sak.
    await tryToClose(page, 'Etikettene er riktige, og effekten er bekreftet.');
    await expect(page.getByText('Saken er lukket.')).toBeVisible();
    await expect(page.locator('header').filter({ has: page.getByRole('heading', { level: 1 }) })).toContainText('Lukket');
    await expect(card.getByRole('button', { name: 'Verifiser effekt' })).toHaveCount(0);

    // Nothing left to pay attention to.
    await page.goto('/app/improvements');
    await expect(page.getByTestId('improvement-attention-empty')).toBeVisible();
    await expect(page.getByTestId('improvement-attention-summary')).toHaveCount(0);
});

/**
 * Frist and ansvarlig on the register's panel, for a reader of one fagområde: a case past its frist
 * and without owner, a tiltak past its frist, a tiltak without owner and a completed tiltak awaiting
 * effektverifisering — counted as one case and three tiltak, each category with its reasons. A case
 * full of findings in an area the reader cannot see moves nothing. The reader is offered no
 * Verifiser effekt; someone with improvement.close but not improvement.edit is offered only that,
 * and the finding goes once they use it.
 */
test('the panel lists due-date and owner findings for the reader\'s area only, and close without edit verifies', async ({ page }) => {
    test.setTimeout(180_000);

    const seeded = await improvementFixture(`seedAttention('${suffix}', '${password}')`);

    await loginAs(page, seeded.reader_email, password);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/improvements');

    const panel = page.getByTestId('improvement-attention');
    await expect(panel).toContainText('Saker og tiltak i dine fagområder');
    await expect(panel.getByTestId('improvement-attention-summary')).toHaveText('1 sak og 3 tiltak trenger oppmerksomhet');
    const keys = await panel.locator('[data-testid^="improvement-attention-category-"]').evaluateAll(
        (elements) => elements.map((element) => element.dataset.testid.replace('improvement-attention-category-', '')),
    );
    expect(keys).toEqual(['case_overdue', 'action_overdue', 'case_owner_missing', 'action_owner_missing', 'awaiting_verification']);

    const expectations = {
        case_overdue: [seeded.case_title, `Fristen ${longDate(seeded.case_due)} er passert.`],
        action_overdue: [seeded.overdue_action, `Fristen ${longDate(seeded.overdue_action_due)} er passert.`],
        case_owner_missing: [seeded.case_title, 'Saken har ingen ansvarlig.'],
        action_owner_missing: [seeded.ownerless_action, 'Tiltaket har ingen ansvarlig.'],
        awaiting_verification: [seeded.completed_action, `Tiltaket ble fullført ${longDate(seeded.completed_on)} og venter på effektverifisering.`],
    };

    for (const [key, [itemTitle, detail]] of Object.entries(expectations)) {
        await expect(panel.getByTestId(`improvement-attention-category-${key}`).getByTestId('improvement-attention-count')).toHaveText('1');
        const items = await openCategory(page, key);
        await expect(items).toHaveCount(1);
        await expect(items.first()).toContainText(itemTitle);
        await expect(items.first()).toContainText(detail);
    }

    // Nothing from the hidden area, in the list or the counts.
    await expect(page.getByText(improvementE2eName(suffix, 'Skjult sak'))).toHaveCount(0);
    await expect(page.getByText(improvementE2eName(suffix, 'Skjult tiltak'))).toHaveCount(0);
    await expect(page.getByTestId('improvement-count')).toHaveText('1 sak');
    // Four tiltak, three of them still to be done.
    await expect(page.getByTestId('improvement-action-indicator').filter({ visible: true })).toHaveText('4 tiltak · 3 åpne');
    await expectPageHelp(page, 'Om Avvik og forbedringer', ['Saken', 'Trenger oppmerksomhet']);
    await expectReadable(page, '06-panel-open');
    await page.setViewportSize(PHONE);
    await expect(page.getByTestId('improvement-action-indicator').filter({ visible: true })).toHaveText('4 tiltak · 3 åpne');
    await page.setViewportSize(DESKTOP);

    // The case page: a short note, and the cards say which and why.
    await page.goto(`/app/improvements/${seeded.case_id}`);
    const note = page.getByTestId('improvement-attention-note');
    await expect(note).toContainText(`Fristen ${longDate(seeded.case_due)} er passert.`);
    await expect(note).toContainText('Saken har ingen ansvarlig.');
    await expect(note).toContainText('3 tiltak trenger oppmerksomhet');
    const completedCard = page.getByTestId('improvement-action').filter({ hasText: seeded.completed_action });
    await expect(completedCard.getByTestId('improvement-action-verification-awaiting')).toBeVisible();
    await expect(page.getByTestId('improvement-action').filter({ hasText: seeded.overdue_action })).toContainText('Frist passert');
    // View only: nothing to do here.
    await expect(page.getByRole('button', { name: 'Verifiser effekt' })).toHaveCount(0);
    await expectReadable(page, '07-case-note-reader');

    // improvement.close without improvement.edit: Verifiser effekt, and nothing of the work itself.
    await page.context().clearCookies();
    await loginAs(page, seeded.closer_email, password);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/improvements/${seeded.case_id}`);
    for (const name of ['Nytt tiltak', 'Start tiltak', 'Fullfør tiltak', 'Rediger', 'Avbryt tiltak', 'Gjenåpne tiltak']) {
        await expect(page.getByRole('button', { name, exact: true }), name).toHaveCount(0);
    }
    const form = await verify(completedCard, page, 'effective', 'Rutinen følges i alle mottak.');
    await form.getByRole('button', { name: 'Verifiser effekt' }).click();
    await expect(page.getByText('Effekten er bekreftet.')).toBeVisible();
    await expect(completedCard.getByTestId('improvement-action-verification-current')).toContainText('Effekt bekreftet');
    await expect(page.getByTestId('improvement-attention-note')).toContainText('2 tiltak trenger oppmerksomhet');

    await page.goto('/app/improvements');
    await expect(page.getByTestId('improvement-attention-category-awaiting_verification')).toHaveCount(0);
    await expect(page.getByTestId('improvement-attention-summary')).toHaveText('1 sak og 2 tiltak trenger oppmerksomhet');
});
