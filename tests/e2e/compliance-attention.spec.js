import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { COMPLIANCE_E2E_PASSWORD, cleanUpComplianceE2eData, complianceE2eName, complianceE2eSuffix, complianceFixture } from './helpers/compliance.js';
import { DESKTOP, PHONE, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';

const suffix = complianceE2eSuffix();
cleanUpComplianceE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'compliance-attention', name);
const registerUrl = (extra = {}) => `/app/compliance/requirements?${new URLSearchParams({ search: `E2E Krav ${suffix}`, ...extra })}`;

/** The reasons one requirement's row shows, on the table or the phone card. */
const rowReasons = (page, title) => page.locator('tbody tr', { hasText: title }).getByTestId('compliance-attention-reasons').locator('li');

/**
 * Trenger oppmerksomhet, end to end: a requirement is registered and is «Ikke vurdert»; its owner
 * leaves and it is «Mangler ansvarlig» until a new one is set; it is assessed «Delvis oppfylt»;
 * the assessment ages past the monthly review and it is also «Revurdering forfalt»; the filter shows
 * only what needs attention; the requirement is opened from the worklist and assessed «Oppfylt»,
 * and every signal is gone. Every step is checked for text under 16 px and sideways scrolling at
 * desktop and 390 px.
 */
test('a requirement collects and loses its attention reasons as it is followed up', async ({ page }) => {
    test.setTimeout(180_000);

    const seeded = await complianceFixture(`seedAttention('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);
    const title = complianceE2eName(suffix, 'Tilgangsstyring');

    await loginAs(page, seeded.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // 1. A requirement, through the pages, owned by someone who is about to leave.
    await page.goto('/app/compliance/requirements');
    await expectPageHelp(page, 'Om krav', ['Trenger oppmerksomhet']);
    await page.getByRole('button', { name: 'Nytt krav' }).click();
    await page.locator('#compliance-requirement-source').selectOption({ label: seeded.source_label });
    await page.locator('#compliance-requirement-reference').fill('A.5.15');
    await page.locator('#compliance-requirement-title').fill(title);
    await page.locator('#compliance-requirement-text').fill('Regler for fysisk og logisk tilgang skal etableres.');
    await page.locator('#compliance-requirement-owner').selectOption({ label: seeded.former_owner_name });
    await page.locator('#compliance-requirement-review').selectOption({ label: 'Månedlig' });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/compliance\/requirements\/\d+$/);
    const requirementUrl = page.url();

    // 2. Ikke vurdert — on the page and in the register; the settled requirement raises nothing.
    await expect(page.getByTestId('compliance-show-attention').locator('li')).toHaveText(['Ikke vurdert']);
    await page.goto(registerUrl());
    await expect(rowReasons(page, title)).toHaveText(['Ikke vurdert']);
    await expect(page.locator('tbody tr', { hasText: seeded.settled_title }).getByTestId('compliance-attention-reasons')).toHaveCount(0);
    await expect(page.getByTestId('compliance-attention')).toBeVisible();
    await expectReadable(page, '01-not-assessed');

    // 3. The owner leaves: Mangler ansvarlig, until someone takes it over.
    await complianceFixture(`removeFormerOwner('${suffix}')`);
    await page.reload();
    await expect(rowReasons(page, title)).toHaveText(['Ikke vurdert', 'Mangler ansvarlig']);
    await page.setViewportSize(PHONE);
    const card = page.getByTestId('compliance-requirement-list').locator(':scope > li', { hasText: title });
    await expect(card.getByTestId('compliance-attention-reasons')).toContainText('Trenger oppmerksomhet');
    await expect(card.getByTestId('compliance-attention-reasons').locator('li')).toHaveText(['Ikke vurdert', 'Mangler ansvarlig']);
    await page.setViewportSize(DESKTOP);
    await expectReadable(page, '02-missing-owner');

    await page.goto(requirementUrl);
    await page.getByRole('button', { name: 'Rediger' }).click();
    await page.locator('#compliance-requirement-owner').selectOption({ label: seeded.name });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Kravet er oppdatert.')).toBeVisible();
    await expect(page.getByTestId('compliance-show-attention').locator('li')).toHaveText(['Ikke vurdert']);

    // 4–5. Delvis oppfylt replaces Ikke vurdert.
    const section = page.getByTestId('compliance-assessment');
    await section.getByRole('button', { name: 'Vurder etterlevelse' }).click();
    await page.locator('#compliance-assessment-result-partially_compliant').check();
    await page.locator('#compliance-assessment-rationale').fill('Rutinen finnes, men tilgangsgjennomgang er ikke innført.');
    await page.getByTestId('compliance-assessment-form').getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Etterlevelsesvurderingen er registrert.')).toBeVisible();
    await expect(page.getByTestId('compliance-show-attention').locator('li')).toHaveText(['Delvis oppfylt']);
    await page.goto(registerUrl());
    await expect(rowReasons(page, title)).toHaveText(['Delvis oppfylt']);

    // 6. The assessments age two months past a monthly review: Revurdering forfalt as well — the
    //    settled requirement's yearly review is still ahead of it.
    await complianceFixture(`ageAssessments('${suffix}', 2)`);
    await page.reload();
    await expect(rowReasons(page, title)).toHaveText(['Delvis oppfylt', 'Revurdering forfalt']);
    await expect(page.locator('tbody tr', { hasText: seeded.settled_title }).getByTestId('compliance-attention-reasons')).toHaveCount(0);
    await expectReadable(page, '03-partial-and-overdue');

    // 7. The filter: only what needs attention.
    await page.getByTestId('compliance-attention-filter').check();
    await page.getByRole('button', { name: 'Søk', exact: true }).click();
    await expect(page).toHaveURL(/attention=1/);
    await expect(page.locator('tbody tr', { hasText: title })).toHaveCount(1);
    await expect(page.locator('tbody tr', { hasText: seeded.settled_title })).toHaveCount(0);
    await expectReadable(page, '04-filtered');

    // 8. Open it from the worklist — the whole register's, so it may sit behind «Vis alle».
    const worklist = page.getByTestId('compliance-attention');
    const showAll = worklist.getByRole('button', { name: /^Vis alle/ });
    if (await showAll.count()) {
        await showAll.click();
    }
    const entry = worklist.getByTestId('compliance-attention-item').filter({ hasText: title });
    await expect(entry.locator('li')).toHaveText(['Delvis oppfylt', 'Revurdering forfalt']);
    await expect(worklist.getByTestId('compliance-attention-item').filter({ hasText: seeded.settled_title })).toHaveCount(0);
    await entry.getByRole('link').click();
    await page.waitForURL(requirementUrl);

    // 9–10. Oppfylt today: the status signal and the overdue review are both gone.
    await section.getByRole('button', { name: 'Vurder etterlevelse' }).click();
    await page.locator('#compliance-assessment-result-compliant').check();
    await page.locator('#compliance-assessment-rationale').fill('Kvartalsvis tilgangsgjennomgang er innført og dokumentert.');
    await page.getByTestId('compliance-assessment-form').getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Etterlevelsesvurderingen er registrert.')).toBeVisible();
    await expect(page.getByTestId('compliance-current')).toHaveText('Oppfylt');
    await expect(page.getByTestId('compliance-show-attention')).toHaveCount(0);
    await expectReadable(page, '05-settled');

    await page.goto(registerUrl());
    await expect(page.locator('tbody tr', { hasText: title }).getByTestId('compliance-attention-reasons')).toHaveCount(0);
    await page.goto(registerUrl({ attention: 1 }));
    await expect(page.locator('tbody tr', { hasText: title })).toHaveCount(0);
});
