import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { DESKTOP, PHONE, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';
import {
    MANAGEMENT_REVIEW_E2E_PASSWORD,
    cleanUpManagementReviewE2eData,
    managementReviewE2eSuffix,
    managementReviewFixture,
    metricValue,
} from './helpers/managementReview.js';

const suffix = managementReviewE2eSuffix();
cleanUpManagementReviewE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'management-review', name);

/**
 * Ledelsens gjennomgåelse, end to end, in a GRC customer of the run's own. Access, tenant isolation,
 * the locks and the snapshot rules are PHP's (ManagementReview*Test); this is the journey a quality
 * manager takes: open the module from Styring, create a review, read the basis, judge every section,
 * record a tiltak, write the conclusion, finalize — and then see that the basis stays as it was while
 * the module changes, that the tiltak is in the owner's Mine oppgaver, and that the report prints and
 * downloads. Every page is checked for text under 16 px and sideways scrolling at desktop and 390 px.
 */
test('a management review is created, assessed, decided, finalized and reported', async ({ page, browser }) => {
    test.setTimeout(240_000);

    const people = await managementReviewFixture(`seedJourney('${suffix}', '${MANAGEMENT_REVIEW_E2E_PASSWORD}')`);
    const title = `LG ${suffix}`;
    const action = `Oppdatere risikovurdering ${suffix}`;

    await loginAs(page, people.email, MANAGEMENT_REVIEW_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // Styring → Ledelsens gjennomgåelse: an empty register that says how to begin.
    await page.goto('/app/governance');
    await page.getByTestId('governance-module-management_review').click();
    await page.waitForURL(/\/app\/management-reviews$/);
    await expect(page.getByRole('heading', { name: 'Ledelsens gjennomgåelse', level: 1 })).toBeVisible();
    await expect(page.getByText('Ingen gjennomgåelser ennå')).toBeVisible();
    await expectPageHelp(page, 'Om ledelsens gjennomgåelse', ['Slik fungerer det', 'Oppfølging']);
    await expectReadable(page, '01-empty');

    // Ny gjennomgåelse: the period is filled in; a participant and ISO 9001 are chosen.
    await page.getByTestId('mr-create').click();
    await page.locator('#mr-title').fill(title);
    await page.locator('#mr-period-start').fill('2026-01-01');
    await page.locator('#mr-meeting-date').fill(new Date().toISOString().slice(0, 10));
    await page.getByLabel(people.colleague_name).check();
    await page.getByTestId('mr-framework-iso9001').check();
    await expectReadable(page, '02-create-form');
    await page.getByRole('button', { name: 'Opprett gjennomgåelse' }).click();
    await page.waitForURL(/\/app\/management-reviews\/\d+$/);
    const reviewUrl = page.url();
    await expect(page.getByTestId('mr-status')).toContainText('Utkast');

    // Oversikt: the checklist and what needs attention — the high risk is flagged.
    await expect(page.getByTestId('mr-readiness-judgements')).toHaveAttribute('data-done', 'false');
    await expect(page.getByTestId('mr-attention')).toContainText('Risiko og risikobilde');
    await expect(page.getByTestId('mr-framework-disclaimer')).toContainText('ikke faglig verifisert');
    await expectPageHelp(page, 'Om denne gjennomgåelsen', ['Grunnlaget', 'Etter ferdigstilling']);
    await expectReadable(page, '03-overview');

    // Risiko: the basis is there, read with the manager's own fagområde.
    await page.getByTestId('mr-nav-risks').click();
    await expect(page.getByTestId('mr-section-risks')).toHaveAttribute('data-state', 'available');
    expect(await metricValue(page, 'risks_open')).toBe(1);
    expect(await metricValue(page, 'level_high')).toBe(1);
    await page.getByTestId('mr-judgement-needs_improvement').check();
    await page.locator('#mr-comment-risks').fill('Risikoen for datatap må reduseres.');
    await page.getByTestId('mr-save-assessment').click();
    await expect(page.getByText('Lagret.', { exact: true }).first()).toBeVisible();

    // A tiltak with a responsible person and a due date, recorded in the section.
    await page.getByTestId('mr-add-decision').click();
    await page.locator('#mr-decision-text').fill(action);
    await page.locator('#mr-decision-owner').selectOption({ label: people.colleague_name });
    await page.locator('#mr-decision-due').fill('2030-01-31');
    await page.getByRole('button', { name: 'Lagre', exact: true }).last().click();
    await expect(page.getByTestId('mr-decision').filter({ hasText: action })).toBeVisible();
    await expectReadable(page, '04-risk-section');

    // Avvik: the deviation registered now counts in the period and as open.
    await page.getByTestId('mr-nav-improvements').click();
    expect(await metricValue(page, 'open_deviations')).toBe(1);

    // Every section the manager can see is judged, straight from the checklist.
    await page.getByTestId('mr-nav-overview').click();
    for (let guard = 0; guard < 15; guard += 1) {
        const missing = page.getByTestId('mr-readiness-judgements').getByRole('button');

        if (await missing.count() === 0) {
            break;
        }

        await missing.first().click();
        const key = new URL(page.url()).searchParams.get('section');
        await page.getByTestId('mr-judgement-satisfactory').check();
        await page.getByTestId('mr-save-assessment').click();
        // Saved: the section's marker in the navigation turns to «Vurdert».
        await expect(page.getByTestId(`mr-nav-${key}`).getByLabel('Vurdert', { exact: true })).toBeVisible({ timeout: 15_000 });
        await page.getByTestId('mr-nav-overview').click();
    }

    await page.locator('#mr-conclusion-text').fill('Styringssystemet er egnet og virker, med forbedringsbehov innen risikostyring.');
    await page.getByTestId('mr-save-conclusion').click();
    await expect(page.getByTestId('mr-readiness-conclusion')).toHaveAttribute('data-done', 'true', { timeout: 15_000 });
    await expect(page.getByTestId('mr-status')).toContainText('Klar for ferdigstilling');

    // Ferdigstill: the basis is frozen and the page is locked.
    await page.getByTestId('mr-finalize').click();
    await expect(page.getByTestId('mr-finalize-panel')).toBeVisible();
    await page.getByTestId('mr-finalize-confirm').click();
    await expect(page.getByTestId('mr-locked')).toBeVisible();
    await expect(page.getByTestId('mr-status')).toContainText('Ferdigstilt');
    await expect(page.getByRole('button', { name: 'Rediger', exact: true })).toHaveCount(0);

    // The module changes; the finalized basis does not.
    await managementReviewFixture(`addDeviation('${suffix}', 'Nytt avvik etter møtet')`);
    await page.goto(`${reviewUrl}?section=improvements`);
    expect(await metricValue(page, 'open_deviations')).toBe(1);
    await expect(page.getByTestId('mr-section-improvements')).toContainText('Status ved ferdigstilling');
    await expectReadable(page, '05-finalized-section');

    // The tiltak is in the colleague's Mine oppgaver, and they complete it after the meeting.
    const colleagueContext = await browser.newContext();
    const colleague = await colleagueContext.newPage();
    await loginAs(colleague, people.colleague_email, MANAGEMENT_REVIEW_E2E_PASSWORD);
    await colleague.goto('/app/info-center');
    const task = colleague.getByTestId('info-center-governance-task').filter({ hasText: action });
    await expect(task).toBeVisible();
    await task.getByRole('link', { name: 'Åpne' }).click();
    await colleague.waitForURL(/\/app\/management-reviews\/\d+/);
    const card = colleague.getByTestId('mr-decision').filter({ hasText: action });
    await card.getByTestId('mr-action-complete').click();
    await card.locator('textarea').fill('Vurderingen er oppdatert.');
    await card.getByTestId('mr-status-confirm-completed').click();
    await expect(colleague.getByText('Tiltaket er fullført.', { exact: true })).toBeVisible();
    await expect(card.getByTestId('mr-decision-at-finalization')).toContainText('Åpent');
    await colleagueContext.close();

    // Rapport: one document, printable, and the same as a PDF.
    await page.goto(reviewUrl);
    await page.getByTestId('mr-report-link').click();
    await page.waitForURL(/\/report$/);
    await expect(page.getByTestId('mr-report')).toContainText(title);
    await expect(page.getByTestId('mr-report')).toContainText('Risikoen for datatap må reduseres.');
    await expect(page.getByTestId('mr-report-draft')).toHaveCount(0);
    const pdf = await page.request.get(await page.getByTestId('mr-download-pdf').getAttribute('href'));
    expect(pdf.status()).toBe(200);
    expect(pdf.headers()['content-type']).toContain('application/pdf');
    expect((await pdf.body()).subarray(0, 4).toString()).toBe('%PDF');
    await expectReadable(page, '06-report');

    // The register lists it, finalized, with the open tiltak gone.
    await page.goto('/app/management-reviews');
    await expect(page.getByTestId('mr-review-table')).toContainText(title);
    await expect(page.getByTestId('mr-open-actions')).not.toContainText(action);
    await expectReadable(page, '07-register');
});

test('on a phone the sections are one select and nothing scrolls sideways', async ({ page }) => {
    test.setTimeout(120_000);

    const people = await managementReviewFixture(`seedJourney('${suffix}', '${MANAGEMENT_REVIEW_E2E_PASSWORD}')`);
    await loginAs(page, people.email, MANAGEMENT_REVIEW_E2E_PASSWORD);
    await page.setViewportSize(PHONE);

    await page.goto('/app/management-reviews');
    await page.getByTestId('mr-create').click();
    await page.locator('#mr-title').fill(`LG mobil ${suffix}`);
    await page.getByRole('button', { name: 'Opprett gjennomgåelse' }).click();
    await page.waitForURL(/\/app\/management-reviews\/\d+$/);

    await page.getByTestId('mr-nav-select').selectOption('risks');
    await expect(page.getByTestId('mr-section-risks')).toBeVisible();
    await expect(page).toHaveURL(/section=risks/);
    await page.getByTestId('mr-nav-select').selectOption('decisions');
    await expect(page.getByTestId('mr-decisions-pane')).toBeVisible();
    // Let the layout settle at desktop width before measuring (the rail animates when it reflows).
    await page.setViewportSize(DESKTOP);
    await page.waitForTimeout(500);
    await expectReadable(page, '08-phone');
});
