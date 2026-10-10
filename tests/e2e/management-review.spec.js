import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { DESKTOP, PHONE, expectPageHelp, expectReadable as expectReadableAt, sidewaysOverflow } from './helpers/readability.js';
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

    // Ny gjennomgåelse: the period is filled in; a participant, ISO 9001 and NIS2 are chosen from
    // searchable pickers — the lists are not on screen until a field is used.
    await page.getByTestId('mr-create').click();
    await page.locator('#mr-title').fill(title);
    await page.locator('#mr-period-start').fill('2026-01-01');
    await page.locator('#mr-meeting-date').fill(new Date().toISOString().slice(0, 10));
    await expect(page.getByRole('listbox')).toHaveCount(0);
    await expect(page.locator('#mr-participants')).toHaveAttribute('placeholder', 'Velg deltakere');
    await page.locator('#mr-participants').fill(people.colleague_name.slice(0, -2));
    await page.getByTestId('mr-participants-list').getByRole('option', { name: people.colleague_name }).click();
    await expect(page.getByRole('button', { name: `Fjern ${people.colleague_name}` })).toBeVisible();
    await page.locator('#mr-frameworks').fill('kvalitet');
    await page.getByTestId('mr-frameworks-option-iso9001').click();
    // Keyboard: search by domain, choose with Enter, close with Escape.
    await page.locator('#mr-frameworks').fill('cyber');
    await expect(page.getByTestId('mr-frameworks-list').getByRole('option')).toHaveCount(1);
    await page.locator('#mr-frameworks').press('Enter');
    await page.locator('#mr-frameworks').press('Escape');
    await expect(page.getByTestId('mr-frameworks-list')).toHaveCount(0);
    await expect(page.getByTestId('mr-frameworks-chosen')).toContainText('ISO 9001 Kvalitetsledelse');
    await expect(page.getByTestId('mr-frameworks-chosen')).toContainText('NIS2 Cybersikkerhet og regulatoriske krav');
    await page.locator('#mr-frameworks').click();
    await expect(page.getByTestId('mr-frameworks-option-dora')).toContainText('Regulatorisk rammeverk');
    await expectReadable(page, '02-create-form');
    await page.locator('#mr-title').click();
    await page.getByRole('button', { name: 'Opprett gjennomgåelse' }).click();
    await page.waitForURL(/\/app\/management-reviews\/\d+$/);
    const reviewUrl = page.url();
    await expect(page.getByTestId('mr-status')).toContainText('Utkast');

    // Oversikt: the checklist and what needs attention — the high risk is flagged.
    await expect(page.getByTestId('mr-readiness-judgements')).toHaveAttribute('data-done', 'false');
    await expect(page.getByTestId('mr-attention')).toContainText('Risiko og risikobilde');
    await expect(page.getByTestId('mr-framework-disclaimer')).toContainText('ikke faglig verifisert');
    // NIS2 is in scope, but nothing claims its requirements are covered.
    await expect(page.getByTestId('mr-framework-nis2')).toHaveAttribute('data-coverage', 'none');
    await expect(page.getByTestId('mr-framework-nis2')).toContainText('Automatisk dekningsanalyse er ikke tilgjengelig');
    await expect(page.getByTestId('mr-framework-iso9001')).toHaveAttribute('data-coverage', 'unverified');

    // Rediger opens with the choices kept.
    await page.getByRole('button', { name: 'Rediger', exact: true }).click();
    await expect(page.getByRole('button', { name: `Fjern ${people.colleague_name}` })).toBeVisible();
    await expect(page.getByTestId('mr-frameworks-chosen')).toContainText('NIS2 Cybersikkerhet og regulatoriske krav');
    await page.getByRole('button', { name: 'Avbryt', exact: true }).click();
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
        // Saved: the section's marker in the navigation turns to the green tick.
        await expect(page.getByTestId(`mr-nav-${key}`)).toHaveAttribute('data-progress', 'judged', { timeout: 15_000 });
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

/** What one nav item draws as its marker: how many markers, and the one's look and place. */
async function markerOf(item) {
    return item.evaluate((button) => {
        const icons = button.querySelectorAll('[data-icon]');
        const icon = icons[0];
        const box = icon?.getBoundingClientRect();
        const style = icon ? getComputedStyle(icon) : null;
        // Tailwind 4 colours are oklch; paint one pixel to read them as RGB, then name the tint.
        const tint = (color) => {
            const context = document.createElement('canvas').getContext('2d');
            context.fillStyle = color;
            context.fillRect(0, 0, 1, 1);
            const [r, g, b, a] = context.getImageData(0, 0, 1, 1).data;
            if (a === 0) return 'none';
            if (g > r && g >= b && g - r >= 10) return 'green';
            if (r >= g && g > b && r - b >= 15) return 'orange';
            if (Math.max(r, g, b) - Math.min(r, g, b) < 45) return 'grey';
            return `rgb(${r}, ${g}, ${b})`;
        };

        return {
            count: icons.length,
            marker: icon?.dataset.icon,
            width: Math.round(box?.width ?? 0),
            height: Math.round(box?.height ?? 0),
            background: style ? tint(style.backgroundColor) : null,
            border: style ? tint(style.borderTopColor) : null,
            symbol: icon?.querySelector('svg') ? 'tick' : (icon?.textContent.trim() || ''),
            rightGap: Math.round(button.getBoundingClientRect().right - (box?.right ?? 0)),
        };
    });
}

test('each section shows exactly one marker — grey, orange or green — explained, and following save and removal', async ({ page }) => {
    test.setTimeout(150_000);

    const people = await managementReviewFixture(`seedJourney('${suffix}', '${MANAGEMENT_REVIEW_E2E_PASSWORD}')`);
    await loginAs(page, people.email, MANAGEMENT_REVIEW_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    await page.goto('/app/management-reviews');
    await page.getByTestId('mr-create').click();
    await page.locator('#mr-title').fill(`LG status ${suffix}`);
    await page.getByRole('button', { name: 'Opprett gjennomgåelse' }).click();
    await page.waitForURL(/\/app\/management-reviews\/\d+$/);
    const reviewUrl = page.url();

    const quality = page.getByTestId('mr-nav-quality');
    const resources = page.getByTestId('mr-nav-resources');
    const missing = page.getByTestId('mr-readiness-judgements');

    // Every section: one marker, 20 × 20, at the same place on the right.
    const navItems = page.locator('nav [data-marker]');
    const gaps = new Set();
    for (const item of await navItems.all()) {
        const marker = await markerOf(item);
        expect(marker.count, 'one marker per section').toBe(1);
        expect([marker.width, marker.height]).toEqual([20, 20]);
        gaps.add(marker.rightGap);
    }
    expect(gaps.size, 'every marker at the same right-hand position').toBe(1);

    // Kvalitetsarbeid before: one orange circle with «!», nothing else. Ressurser: one plain grey circle.
    expect(await markerOf(quality)).toMatchObject({ count: 1, marker: 'attention', background: 'orange', border: 'orange', symbol: '!' });
    expect(await markerOf(resources)).toMatchObject({ count: 1, marker: 'open', background: 'grey', border: 'grey', symbol: '' });

    // The explanation: on hover, on keyboard focus, and in the accessible name.
    await quality.hover();
    await expect(page.getByRole('tooltip')).toContainText('Ikke vurdert – forhold krever oppmerksomhet:');
    await page.mouse.move(900, 10);
    await expect(page.getByRole('tooltip')).toHaveCount(0);
    await resources.focus();
    await expect(page.getByRole('tooltip')).toHaveText('Ikke vurdert.');
    await page.getByTestId('mr-nav-previous_decisions').focus();
    await expect(page.getByRole('tooltip')).toHaveText('Ikke vurdert. Ikke påkrevd for ferdigstilling.');
    await expect(quality).toHaveAccessibleName(/^Kvalitetsarbeid \(Ikke vurdert – forhold krever oppmerksomhet: .+\)$/);
    const height = await quality.evaluate((element) => element.getBoundingClientRect().height);

    // Opening the section marks nothing.
    await quality.click();
    expect((await markerOf(quality)).marker).toBe('attention');
    await expect(page.getByTestId('mr-section-status')).toHaveAttribute('data-marker', 'attention');
    await page.screenshot({ path: `test-results/mr-markers-1-before-${suffix}.png`, clip: await page.getByRole('navigation', { name: 'Seksjoner i gjennomgåelsen' }).locator('ul').boundingBox() });

    // Saved: one green circle with a tick — and the attention is still told in the section.
    await page.getByTestId('mr-judgement-satisfactory').check();
    await page.getByTestId('mr-save-assessment').click();
    await expect(quality).toHaveAttribute('data-marker', 'judged', { timeout: 15_000 });
    expect(await markerOf(quality)).toMatchObject({ count: 1, marker: 'judged', background: 'green', border: 'green', symbol: 'tick' });
    await expect(page.getByTestId('mr-section-status')).toContainText('Vurdering gjennomført – forhold krever fortsatt oppmerksomhet:');
    await expect(page.getByTestId('mr-section-status').locator('[data-icon]')).toHaveCount(1);
    expect(await quality.evaluate((element) => element.getBoundingClientRect().height)).toBe(height);
    await quality.hover();
    await expect(page.getByRole('tooltip')).toContainText('Vurdering gjennomført – forhold krever fortsatt oppmerksomhet:');
    await page.screenshot({ path: `test-results/mr-markers-2-judged-${suffix}.png`, clip: await page.getByRole('navigation', { name: 'Seksjoner i gjennomgåelsen' }).locator('ul').boundingBox() });
    await page.getByTestId('mr-nav-overview').click();
    await expect(missing.getByRole('button', { name: 'Kvalitetsarbeid' })).toHaveCount(0);

    // After a reload: still green.
    await page.reload();
    expect(await markerOf(quality)).toMatchObject({ count: 1, marker: 'judged', background: 'green', border: 'green' });

    // Removed: back to one orange circle — the attention is still there — in menu, section and checklist.
    await quality.click();
    await page.getByTestId('mr-remove-assessment').click();
    await expect(quality).toHaveAttribute('data-marker', 'attention', { timeout: 15_000 });
    expect(await markerOf(quality)).toMatchObject({ count: 1, marker: 'attention', background: 'orange', border: 'orange', symbol: '!' });
    await page.goto(reviewUrl);
    expect((await markerOf(quality)).marker).toBe('attention');
    await expect(missing.getByRole('button', { name: 'Kvalitetsarbeid' })).toHaveCount(1);
    await expectReadable(page, '09-status-markers');

    // A phone has no hover and no menu icons: the select and the section say the same in words.
    await quality.click();
    await page.getByTestId('mr-judgement-needs_improvement').check();
    await page.getByTestId('mr-save-assessment').click();
    await expect(quality).toHaveAttribute('data-marker', 'judged', { timeout: 15_000 });
    await page.setViewportSize(PHONE);
    const select = page.getByTestId('mr-nav-select');
    await expect(select.locator('option[value="quality"]')).toHaveText('Kvalitetsarbeid (vurdert, krever fortsatt oppmerksomhet)');
    await expect(select.locator('option[value="risks"]')).toHaveText('Risiko og risikobilde (ikke vurdert, krever oppmerksomhet)');
    await expect(select.locator('option[value="resources"]')).toHaveText('Ressurser, kompetanse og forbedringsbehov (ikke vurdert)');
    await expect(page.getByTestId('mr-section-status')).toBeVisible();
    await expect(page.getByTestId('mr-section-status').locator('[data-icon="judged"]')).toBeVisible();
    await expect(page.getByTestId('mr-section-status')).toContainText('forhold krever fortsatt oppmerksomhet');
    expect(await sidewaysOverflow(page), 'section status scrolls sideways').toEqual([]);
    await page.screenshot({ path: `test-results/mr-markers-3-phone-${suffix}.png` });
});

test('on a phone the sections are one select and nothing scrolls sideways', async ({ page }) => {
    test.setTimeout(120_000);

    const people = await managementReviewFixture(`seedJourney('${suffix}', '${MANAGEMENT_REVIEW_E2E_PASSWORD}')`);
    await loginAs(page, people.email, MANAGEMENT_REVIEW_E2E_PASSWORD);
    await page.setViewportSize(PHONE);

    await page.goto('/app/management-reviews');
    await page.getByTestId('mr-create').click();
    await page.locator('#mr-title').fill(`LG mobil ${suffix}`);
    // The pickers fit the phone, open or with long chips chosen.
    await page.locator('#mr-frameworks').click();
    await page.getByTestId('mr-frameworks-option-dora').click();
    await page.getByTestId('mr-frameworks-option-nis2').click();
    expect(await sidewaysOverflow(page), 'framework picker scrolls sideways').toEqual([]);
    await page.locator('#mr-title').click();
    await expect(page.getByTestId('mr-frameworks-list')).toHaveCount(0);
    expect(await sidewaysOverflow(page), 'chosen frameworks scroll sideways').toEqual([]);
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

test('a finalized review hands the management’s own, dated word over to the Wiki', async ({ page }) => {
    test.setTimeout(120_000);

    const seeded = await managementReviewFixture(`seedFinalizedForWiki('${suffix}', '${MANAGEMENT_REVIEW_E2E_PASSWORD}')`);
    await loginAs(page, seeded.email, MANAGEMENT_REVIEW_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto(`/app/management-reviews/${seeded.review_id}`);

    const panel = page.locator('section', { has: page.getByRole('heading', { name: 'Kunnskap delt til Wiki', exact: true }) });
    await expect(panel).toContainText('Ingen kunnskap er delt herfra ennå.');
    await panel.getByRole('button', { name: 'Lag kunnskapsartikkel' }).click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    // Always dated, and the person is told the Wiki will not follow a later review by itself.
    await expect(dialog.getByTestId('knowledge-handoff-notice')).toContainText('det skjer ikke automatisk');
    await expect(dialog.getByTestId('knowledge-handoff-context')).toContainText('Ledelsens gjennomgåelse for perioden');
    // Nothing chosen in advance; the management's own word is offered, the people are not.
    const conclusion = dialog.getByRole('checkbox', { name: /Ledelsens samlede konklusjon/ });
    await expect(conclusion).not.toBeChecked();
    await expect(dialog.getByRole('checkbox', { name: /Ledelsens vurdering: Risiko og risikobilde/ })).not.toBeChecked();
    await expect(dialog).not.toContainText(seeded.participant);
    await expect(dialog).not.toContainText(seeded.risk);
    await expectReadable(page, '20-wiki-dialog');

    await dialog.getByLabel('Læringspunkter').fill('Ledelsen går gjennom styringssystemet hvert halvår og følger opp beslutningene.');
    await conclusion.check();
    await dialog.getByRole('checkbox', { name: /Ledelsens vurdering: Risiko og risikobilde/ }).check();
    await dialog.getByRole('button', { name: 'Send til Wiki' }).click();

    await expect(page.getByText('Kunnskapen er lagt inn som kilde i Wiki.')).toBeVisible();
    await expect(dialog).toHaveCount(0);
    await expect(panel).toContainText('Kilde mottatt – behandles i Wiki');

    const handed = await managementReviewFixture(`handedOver(${seeded.review_id})`);
    expect(handed.origins).toBe(1);
    expect(handed.texts).toHaveLength(1);
    expect(handed.texts[0]).toContain('Ledelsens gjennomgåelse for perioden');
    expect(handed.texts[0]).toContain(seeded.conclusion);
    expect(handed.texts[0]).toContain('Risikobildet følges opp kvartalsvis.');
    expect(handed.texts[0]).not.toContain('Avvik skal lukkes innen 30 dager.');
    expect(handed.texts[0]).not.toContain(seeded.participant);
    expect(handed.texts[0]).not.toContain(seeded.risk);

    await page.setViewportSize(PHONE);
    await expectReadable(page, '21-review-after-wiki-handoff');
});
