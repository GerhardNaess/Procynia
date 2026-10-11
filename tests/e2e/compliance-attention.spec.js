import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { COMPLIANCE_E2E_PASSWORD, cleanUpComplianceE2eData, complianceE2eName, complianceE2eSuffix, complianceFixture } from './helpers/compliance.js';
import { DESKTOP, PHONE, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';

const suffix = complianceE2eSuffix();
cleanUpComplianceE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'compliance-attention', name);
const worklistUrl = (extra = {}) => `/app/compliance/assessments?${new URLSearchParams({ search: `E2E Krav ${suffix}`, ...extra })}`;
const row = (page, title) => page.getByTestId('compliance-assessments-row').filter({ hasText: title });

/**
 * A requirement followed up through Etterlevelsesvurdering, end to end: it is registered and is
 * «Ikke vurdert» in the worklist; its owner leaves, which raises nothing — the ansvarlig is optional;
 * «Vurder» opens it with the form ready, it is assessed «Delvis oppfylt», and «Tilbake til
 * etterlevelsesvurdering» returns to the same filtered list; the assessment ages past the monthly
 * review and the row says «Revurdering forfalt»; it is assessed «Oppfylt» and leaves the gaps.
 * Every step is checked for text under 16 px and sideways scrolling at desktop and 390 px.
 */
test('a requirement is followed up from the worklist until it is met', async ({ page }) => {
    test.setTimeout(180_000);

    const seeded = await complianceFixture(`seedAttention('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);
    const title = complianceE2eName(suffix, 'Tilgangsstyring');

    await loginAs(page, seeded.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // 1. A requirement, through the register, owned by someone who is about to leave.
    await page.goto('/app/compliance/requirements');
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
    await expect(page.getByTestId('compliance-show-attention').locator('li')).toHaveText(['Ikke vurdert']);

    // 2. Ikke vurdert in the worklist, with its origin; the settled requirement is Oppfylt.
    await page.goto(worklistUrl());
    await expectPageHelp(page, 'Om etterlevelsesvurdering', ['Slik gjør du det', 'Nøkkeltallene', 'Utenfor tallene', 'Per standard', 'Vurdering og revisjon']);
    await expect(row(page, title).getByTestId('compliance-assessments-origin')).toHaveText(`${seeded.source_label} · A.5.15 · ${title}`);
    await expect(row(page, title)).toContainText('Ikke vurdert');
    await expect(row(page, seeded.settled_title)).toContainText('Oppfylt');
    await expect(page.getByTestId('module-navigation').locator('[aria-current="page"]')).toHaveText('Etterlevelsesvurdering');
    await expectReadable(page, '01-not-assessed');

    // 3. The owner leaves. Nothing is raised for it: the ansvarlig is optional.
    await complianceFixture(`removeFormerOwner('${suffix}')`);
    await page.goto(requirementUrl);
    await expect(page.getByTestId('compliance-facts')).toContainText('Ingen valgt');
    await expect(page.getByTestId('compliance-show-attention').locator('li')).toHaveText(['Ikke vurdert']);

    // 4. From the list of what is not assessed: «Vurder» opens the requirement with the form ready.
    await page.goto(worklistUrl({ status: 'not_assessed' }));
    await expect(row(page, seeded.settled_title)).toHaveCount(0);
    await row(page, title).getByRole('link', { name: /^Vurder/ }).click();
    await page.waitForURL((url) => /\/app\/compliance\/requirements\/\d+$/.test(url.pathname) && url.searchParams.get('from') === 'assessments' && url.searchParams.get('assess') === '1');
    await expect(page.getByTestId('module-navigation').locator('[aria-current="page"]')).toHaveText('Etterlevelsesvurdering');
    const form = page.getByTestId('compliance-assessment-form');
    await expect(form).toBeVisible();
    await page.locator('#compliance-assessment-result-partially_compliant').check();
    await page.locator('#compliance-assessment-rationale').fill('Rutinen finnes, men tilgangsgjennomgang er ikke innført.');
    await expectReadable(page, '02-assessment-from-worklist');
    await form.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Etterlevelsesvurderingen er registrert.')).toBeVisible();
    await expect(page.getByTestId('compliance-show-attention').locator('li')).toHaveText(['Delvis oppfylt']);

    // 5. Back to the same filtered list, without losing the context.
    await page.getByTestId('compliance-back').click();
    await expect(page).toHaveURL(/\/app\/compliance\/assessments\?.*status=not_assessed/);
    await expect(page.locator('#compliance-assessments-status')).toHaveValue('not_assessed');
    await expect(row(page, title)).toHaveCount(0);
    await page.goto(worklistUrl({ status: 'gaps' }));
    await expect(row(page, title)).toContainText('Delvis oppfylt');

    // 6. The assessments age two months past a monthly review: Revurdering forfalt as well — the
    //    settled requirement's yearly review is still ahead of it.
    await complianceFixture(`ageAssessments('${suffix}', 2)`);
    await page.goto(worklistUrl({ status: 'overdue' }));
    await expect(row(page, title)).toContainText('Revurdering forfalt');
    await expect(row(page, seeded.settled_title)).toHaveCount(0);
    await page.setViewportSize(PHONE);
    await expect(row(page, title).getByRole('link', { name: /^Vurder/ })).toBeVisible();
    await page.setViewportSize(DESKTOP);
    await expectReadable(page, '03-partial-and-overdue');

    // 7. Oppfylt today: the gap and the overdue review are both gone.
    await row(page, title).getByRole('link', { name: /^Vurder/ }).click();
    await page.locator('#compliance-assessment-result-compliant').check();
    await page.locator('#compliance-assessment-rationale').fill('Kvartalsvis tilgangsgjennomgang er innført og dokumentert.');
    await form.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Etterlevelsesvurderingen er registrert.')).toBeVisible();
    await expect(page.getByTestId('compliance-current')).toHaveText('Oppfylt');
    await expect(page.getByTestId('compliance-show-attention')).toHaveCount(0);
    await expectReadable(page, '04-settled');

    await page.getByTestId('compliance-back').click();
    await expect(page).toHaveURL(/status=overdue/);
    await expect(row(page, title)).toHaveCount(0);
    await page.goto(worklistUrl({ status: 'gaps' }));
    await expect(row(page, title)).toHaveCount(0);
    await page.goto(worklistUrl());
    await expect(row(page, title)).toContainText('Oppfylt');
});
