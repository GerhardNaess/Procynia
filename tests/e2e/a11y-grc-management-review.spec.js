import { expect, test } from '@playwright/test';
import { expectNoAxeViolations } from './helpers/a11y.js';
import { sidewaysOverflow } from './helpers/readability.js';
import { loginAs } from './helpers/auth.js';
import {
    MANAGEMENT_REVIEW_E2E_PASSWORD,
    cleanUpManagementReviewE2eData,
    managementReviewE2eSuffix,
    managementReviewFixture,
} from './helpers/managementReview.js';
import { DESKTOP, PHONE } from './helpers/readability.js';

const suffix = managementReviewE2eSuffix();
cleanUpManagementReviewE2eData(suffix);

const focusedId = (page) => page.evaluate(() => document.activeElement?.id ?? '');
const focusIsOnBody = (page) => page.evaluate(() => document.activeElement === document.body || document.activeElement === null);
const switchOffBrowserCheck = (form) => form.evaluate((element) => { element.noValidate = true; });

/**
 * WCAG Fase 6, Ledelsens gjennomgang (docs/wcag-accessibility-plan.md §8), in a GRC customer of the
 * run's own: a refused save focuses the field that is wrong; Avgrensning is a named group; the
 * readiness list says «fullført»/«gjenstår» in words; moving between sections puts focus on the new
 * section's heading; panels take and give back focus; removing a participant or a decision never
 * drops focus; the report's key figures have row headers and the PDF link names the accessible
 * version; nothing scrolls sideways at 320 px.
 */
test('management review: errors, groups, status words, section focus, panels, removal and the report', async ({ page }) => {
    test.setTimeout(240_000);

    const people = await managementReviewFixture(`seedJourney('${suffix}', '${MANAGEMENT_REVIEW_E2E_PASSWORD}')`);
    const title = `LG a11y ${suffix}`;

    await loginAs(page, people.email, MANAGEMENT_REVIEW_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/management-reviews');

    // Ny gjennomgang: focus to the title; the «*» is explained; Avgrensning is a named group.
    await page.getByTestId('mr-create').click();
    await expect(page.locator('#mr-title')).toBeFocused();
    await expect(page.locator('#mr-title')).toHaveAttribute('aria-required', 'true');
    const form = page.getByTestId('management-review-form');
    await expect(form.getByText('Felt merket med * må fylles ut.')).toBeVisible();
    await expect(form.getByRole('group', { name: 'Avgrensning' })).toBeVisible();

    // A refused save: focus to the first field that is wrong, marked and described by its error.
    await page.locator('#mr-title').fill('');
    await switchOffBrowserCheck(form);
    await page.getByRole('button', { name: 'Opprett gjennomgang' }).click();
    await expect(page.locator('#mr-title')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#mr-title')).toBeFocused();
    await expect(page.locator('#mr-title')).toHaveAttribute('aria-describedby', /mr-title-error/);
    await expectNoAxeViolations(page, '[data-testid="management-review-form"]', 'Ny gjennomgang with errors');

    // Avbryt gives focus back to «Ny gjennomgang».
    await page.getByRole('button', { name: 'Avbryt', exact: true }).click();
    await expect(page.getByTestId('mr-create')).toBeFocused();

    await page.getByTestId('mr-create').click();
    await page.locator('#mr-title').fill(title);
    await page.locator('#mr-period-start').fill('2026-01-01');
    await page.getByRole('button', { name: 'Opprett gjennomgang' }).click();
    await page.waitForURL(/\/app\/management-reviews\/\d+$/);
    const reviewUrl = page.url();

    // Readiness: the state of each item in words, not only ✓/○ and colour.
    await expect(page.getByTestId('mr-readiness-conclusion')).toContainText('(gjenstår)');
    await expect(page.getByTestId('mr-readiness-judgements')).toContainText('(gjenstår)');
    await expectNoAxeViolations(page, '[data-testid="mr-readiness"]', 'readiness');

    // A section link on Oversikt opens the section with focus on its heading.
    await page.getByTestId('mr-readiness-judgements').getByRole('button').first().click();
    const key = new URL(page.url()).searchParams.get('section');
    expect(await focusedId(page)).toBe(`mr-section-${key}`);

    // «Neste seksjon» does the same for the next one.
    await page.getByTestId('mr-next-section').click();
    const next = new URL(page.url()).searchParams.get('section');
    expect(next).not.toBe(key);
    expect(await focusedId(page)).toBe(`mr-section-${next}`);

    // The navigation keeps focus where it is and marks the open pane.
    await page.getByTestId('mr-nav-risks').click();
    await expect(page.getByTestId('mr-nav-risks')).toBeFocused();
    await expect(page.getByTestId('mr-nav-risks')).toHaveAttribute('aria-current', 'page');
    await expect(page.getByRole('group', { name: 'Vurdering' })).toBeVisible();
    await expectNoAxeViolations(page, '[data-testid="mr-section-risks"]', 'Risiko section');

    // Registrer beslutning eller tiltak: focus into the form; a refused save focuses the text.
    await page.getByTestId('mr-add-decision').click();
    const decisionForm = page.getByTestId('mr-decision-form');
    expect(await page.evaluate(() => document.activeElement?.closest('[data-testid="mr-decision-form"]') !== null)).toBe(true);
    await expect(decisionForm.getByRole('group', { name: 'Type' })).toHaveAttribute('aria-describedby', /mr-decision-kind-hint/);
    await switchOffBrowserCheck(decisionForm);
    await decisionForm.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.locator('#mr-decision-text')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#mr-decision-text')).toBeFocused();
    await expectNoAxeViolations(page, '[data-testid="mr-decision-form"]', 'decision form with errors');

    const action = `Tiltak a11y ${suffix}`;
    await page.locator('#mr-decision-text').fill(action);
    await page.locator('#mr-decision-owner').selectOption({ label: people.colleague_name });
    await page.locator('#mr-decision-due').fill('2030-01-31');
    await decisionForm.getByRole('button', { name: 'Lagre', exact: true }).click();
    const card = page.getByTestId('mr-decision').filter({ hasText: action });
    await expect(card).toBeVisible();
    // Back on «Registrer …» once the form has closed.
    await expect(page.getByTestId('mr-add-decision')).toBeFocused();

    // Rediger opens in the card and gives focus back on Avbryt; the buttons name their decision.
    const edit = card.getByRole('button', { name: 'Rediger', exact: true });
    await expect(edit).toHaveAttribute('aria-describedby', /decision-\d+-text/);
    await edit.click();
    expect(await page.evaluate(() => document.activeElement?.closest('[data-testid="mr-decision-form"]') !== null)).toBe(true);
    await card.getByRole('button', { name: 'Avbryt', exact: true }).click();
    await expect(card.getByRole('button', { name: 'Rediger', exact: true })).toBeFocused();

    // Slett (window.confirm stays): focus moves on, never to <body>.
    page.once('dialog', (dialog) => dialog.accept());
    await card.getByRole('button', { name: 'Slett', exact: true }).click();
    await expect(page.getByTestId('mr-decision').filter({ hasText: action })).toHaveCount(0);
    await expect.poll(() => focusIsOnBody(page)).toBe(false);
    await expect(page.getByTestId('mr-add-decision')).toBeFocused();

    // Participants on Oversikt: the add form takes focus; «Fjern» is described by the person; removing
    // one gives focus to the next «Fjern», or to «Legg til deltaker» when none is left.
    await page.getByTestId('mr-nav-overview').click();
    await page.getByTestId('mr-add-participant').click();
    await expect(page.locator('#mr-participant-user')).toBeFocused();
    await page.locator('#mr-participant-user').selectOption({ label: people.colleague_name });
    await page.getByTestId('mr-participant-save').click();
    const row = page.getByTestId('mr-participants').locator('li', { hasText: people.colleague_name });
    const remove = row.getByRole('button', { name: 'Fjern', exact: true });
    await expect(remove).toBeVisible();
    await expect(remove).toHaveAccessibleDescription(new RegExp(people.colleague_name));
    // Not the same name as the picker's chip «Fjern <name>».
    await expect(page.getByRole('button', { name: `Fjern ${people.colleague_name}`, exact: true })).toHaveCount(0);
    await remove.click();
    await expect(remove).toHaveCount(0);
    await expect.poll(() => focusedId(page)).toMatch(/^(mr-participant-remove-\d+|mr-add-participant)$/);
    await expectNoAxeViolations(page, '[data-testid="mr-participants"]', 'participants');

    // The report: key figures as rows with headers; the PDF link points to this accessible version.
    await page.goto(`${reviewUrl}/report`);
    const report = page.getByTestId('mr-report');
    await expect(page.getByTestId('mr-download-pdf')).toHaveAttribute('aria-describedby', 'mr-pdf-note');
    await expect(page.getByTestId('mr-pdf-note')).toBeVisible();
    expect(await report.locator('table tbody tr').count()).toBeGreaterThan(0);
    expect(await report.locator('table tbody tr:not(:has(th[scope="row"]))').count()).toBe(0);
    await page.getByTestId('mr-pdf-note').getByRole('link').click();
    await expect(report).toBeFocused();
    await expectNoAxeViolations(page, '[data-testid="mr-report"]', 'report');

    // 320 px: nothing scrolls sideways.
    await page.setViewportSize({ width: 320, height: PHONE.height });
    for (const url of ['/app/management-reviews', reviewUrl, `${reviewUrl}/report`]) {
        await page.goto(url);
        expect(await sidewaysOverflow(page), url).toEqual([]);
    }
});
