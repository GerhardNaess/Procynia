import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';
import { expectNoAxeViolations } from './helpers/a11y.js';
import { DESKTOP, sidewaysOverflow } from './helpers/readability.js';
import { cleanUpRiskE2eData, fillRiskDescription, riskE2eSuffix } from './helpers/risk.js';

/**
 * WCAG Fase 6 — Risiko, in a real browser (docs/wcag-accessibility-plan.md §8). The rules are pinned
 * in resources/js/Support/a11yPhase6Risk.test.js; this spec proves the behaviour: a refused save puts
 * focus on the field that says why, inline panels take focus in and give it back, the register is a
 * real table whose hit count is spoken, a changed tiltak keeps focus on the tiltak, and levels and
 * statuses are words. Data is created through the UI with the Risk E2E name templates, so
 * Tests\Support\RiskE2EFixture removes it after the test.
 */

const suffix = riskE2eSuffix();
cleanUpRiskE2eData(suffix);

const polite = (page) => page.locator('#procynia-live-polite');
const focusedId = (page) => page.evaluate(() => document.activeElement?.id ?? '');

/** System Owner gives the shared E2E user a role with every Risk permission in one new fagområde. */
async function grantRiskRole(page, areaName, roleName) {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);

    await page.goto('/app/customer-environment?tab=permissions');
    await page.getByRole('button', { name: 'Nytt fagområde' }).click();
    await page.locator('#business-area-name').fill(areaName);
    await page.getByRole('button', { name: 'Lagre fagområde' }).click();
    await expect(page.locator('tbody tr', { hasText: areaName })).toBeVisible();

    await page.getByRole('button', { name: 'Ny rolle' }).click();
    await page.locator('#customer-role-name').fill(roleName);
    for (const permission of ['Se risikoer', 'Opprette risikoer', 'Endre risikoer', 'Vurdere risikoer', 'Akseptere restrisiko']) {
        await page.getByRole('checkbox', { name: permission, exact: true }).check();
    }
    await page.getByRole('checkbox', { name: areaName, exact: true }).check();
    await page.getByRole('button', { name: 'Lagre rolle' }).click();
    await expect(page.locator('tr', { hasText: roleName }).filter({ visible: true }).first()).toBeVisible();

    await page.goto('/app/customer-environment?tab=users');
    await page.locator('tbody tr', { hasText: USER.email }).first().getByRole('link', { name: 'Rediger' }).click();
    await page.waitForURL(/\/app\/users\/\d+\/edit/);
    await page.getByRole('checkbox', { name: roleName, exact: true }).check();
    await page.getByRole('button', { name: 'Lagre endringer' }).click();
    await page.waitForURL((url) => ! url.pathname.endsWith('/edit'));

    await page.context().clearCookies();
}

test('Risiko: register, Ny risiko, vurdering, tiltak and aksept work by keyboard and screen reader', async ({ page }) => {
    test.setTimeout(240_000);

    const areaName = `E2E Beredskap ${suffix}`;
    const roleName = `E2E Risikoansvarlig ${suffix}`;
    const riskTitle = `E2E Datasenter ${suffix}`;

    await grantRiskRole(page, areaName, roleName);
    await loginAs(page, USER.email, USER.password);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/risk');

    // Ny risiko opens in place of its button: focus goes to the first field, and Avbryt gives it back.
    const createButton = page.getByRole('button', { name: 'Ny risiko' });
    await createButton.click();
    await expect(page.locator('#risk-title')).toBeFocused();
    await expect(page.getByText('Felt merket med * må fylles ut.')).toBeVisible();
    await expect(page.locator('#risk-title')).toHaveAttribute('aria-required', 'true');
    await expectNoAxeViolations(page, 'section[aria-labelledby="risk-create-heading"]', 'Ny risiko');
    await page.getByRole('button', { name: 'Avbryt' }).click();
    await expect(createButton).toBeFocused();

    // A refused save: Tittel is left empty (the browser only checks the three description fields).
    await createButton.click();
    await fillRiskDescription(page);
    await page.locator('#risk-area').selectOption({ label: areaName });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    const title = page.locator('#risk-title');
    await expect(title).toBeFocused();
    await expect(title).toHaveAttribute('aria-invalid', 'true');
    await expect(title).toHaveAttribute('aria-describedby', /risk-title-error/);
    await expect(page.locator('#risk-title-error')).toHaveClass(/text-rose-700/);
    await expect(page.locator('#risk-title-error')).not.toBeEmpty();
    // Every hint is tied to its field.
    await expect(page.locator('#risk-area')).toHaveAttribute('aria-describedby', /risk-area-hint/);
    await expect(page.locator('#risk-owner')).toHaveAttribute('aria-describedby', /risk-owner-hint/);

    await title.fill(riskTitle);
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/risk\/risks\/\d+$/);
    const riskUrl = page.url();

    // The register: a named table with column and row headers, and the hit count said once a search answers.
    await page.goto('/app/risk');
    const table = page.getByRole('table', { name: 'Risikoregister' });
    await expect(table).toBeVisible();
    await expect(table.getByRole('columnheader', { name: 'Restrisiko' })).toBeVisible();
    await expect(table.getByRole('rowheader', { name: new RegExp(riskTitle) })).toBeVisible();
    await expectNoAxeViolations(page, 'main', 'Risikoregister');

    await page.locator('#risk-search').fill(riskTitle);
    await page.getByRole('button', { name: 'Søk', exact: true }).click();
    await expect(polite(page)).toHaveText('1 risikoer');

    await page.locator('#risk-search').fill(`finnes ikke ${suffix}`);
    await page.getByRole('button', { name: 'Søk', exact: true }).click();
    await expect(polite(page)).toHaveText('Ingen risikoer passer søket.');

    // Nullstill goes away with the filter; focus goes back to the search field, not the page.
    await page.getByRole('button', { name: 'Nullstill' }).click();
    await expect(page.locator('#risk-search')).toBeFocused();

    // 320 px: the register does not scroll sideways (the table scrolls in its own region).
    await page.setViewportSize({ width: 320, height: 800 });
    await expect.poll(() => sidewaysOverflow(page)).toEqual([]);
    await page.setViewportSize(DESKTOP);

    // The risk page. Status and level are words.
    await page.goto(riskUrl);
    const header = page.locator('header').filter({ has: page.getByRole('heading', { level: 1, name: riskTitle }) });
    await expect(header).toContainText('Status:');
    await expect(header).toContainText('Restrisiko: Ikke vurdert');

    // Rediger: focus into the form, and back to Rediger on Avbryt.
    await page.getByRole('button', { name: 'Rediger', exact: true }).click();
    await expect(page.locator('#risk-title')).toBeFocused();
    await page.getByRole('button', { name: 'Avbryt' }).click();
    await expect(page.getByRole('button', { name: 'Rediger', exact: true })).toBeFocused();

    // Behandling → Endre: focus to Behandlingsvalg, and back to that Endre after Avbryt.
    const treatment = page.getByRole('region', { name: 'Behandling' });
    await treatment.getByRole('button', { name: 'Endre' }).click();
    await expect(page.locator('#risk-treatment-strategy')).toBeFocused();
    await page.getByRole('button', { name: 'Avbryt' }).click();
    await expect.poll(() => focusedId(page)).toBe('risk-treatment-strategy-edit');

    // Ny vurdering: focus in; a refused save marks the first scale; the calculated level is spoken.
    const assessment = page.locator('section', { has: page.getByRole('heading', { name: 'Risikovurdering', exact: true }) });
    await assessment.getByRole('button', { name: 'Ny vurdering' }).click();
    await expect(page.locator('#assessment-inherent-likelihood')).toBeFocused();
    await assessment.getByRole('button', { name: 'Lagre vurdering' }).click();
    const likelihood = page.locator('#assessment-inherent-likelihood');
    await expect(likelihood).toBeFocused();
    await expect(likelihood).toHaveAttribute('aria-invalid', 'true');
    await expect(likelihood).toHaveAttribute('aria-describedby', /assessment-inherent-likelihood-error/);
    await expect(page.locator('#assessment-rationale')).toHaveAttribute('aria-invalid', 'true');

    await likelihood.selectOption('4');
    await page.locator('#assessment-inherent-consequence').selectOption('4');
    await expect(polite(page)).toHaveText(/Beregnet nivå: .+\. Score 16/);
    await expect(assessment.locator('[aria-live]')).toHaveCount(0);
    await page.getByRole('button', { name: 'Vurder også restrisiko' }).click();
    await page.locator('#assessment-residual-likelihood').selectOption('4');
    await page.locator('#assessment-residual-consequence').selectOption('4');
    await page.locator('#assessment-rationale').fill('Ingen reservestrøm for kjølingen.');
    await expectNoAxeViolations(page, 'section:has(#risk-assessment-heading)', 'Ny risikovurdering');
    await assessment.getByRole('button', { name: 'Lagre vurdering' }).click();
    await expect(page.getByText('Vurderingen er registrert.')).toBeVisible();
    await expect.poll(() => focusedId(page)).toBe('risk-assessment-create');
    // The level is text, in the header and in the assessment — never colour alone.
    await expect(header).toContainText(/Restrisiko: (Høy|Svært høy)/);

    // Tiltak: Nytt tiltak, then Marker som fullført; focus stays with the tiltak as it moves.
    const actions = page.locator('section', { has: page.getByRole('heading', { name: 'Tiltak', exact: true }) });
    await actions.getByRole('button', { name: 'Nytt tiltak' }).click();
    await expect(page.locator('#risk-action-new-title')).toBeFocused();
    await page.locator('#risk-action-new-title').fill('Installer reservekjøling');
    await page.locator('#risk-action-new-owner').selectOption({ index: 1 });
    await page.locator('#risk-action-new-due').fill('2020-01-15');
    await actions.getByRole('button', { name: 'Lagre tiltak' }).click();
    await expect(page.getByText('Tiltaket er registrert.')).toBeVisible();
    await expect.poll(() => focusedId(page)).toBe('risk-action-create');

    const row = actions.locator('li', { hasText: 'Installer reservekjøling' });
    // Overdue is a word, not only the red date.
    await expect(row.getByText('Forfalt', { exact: true })).toBeVisible();
    const complete = row.getByRole('button', { name: 'Marker som fullført' });
    await expect(complete).toHaveAccessibleDescription('Installer reservekjøling');
    await complete.click();
    await expect(row.locator('textarea')).toBeFocused();
    await row.getByRole('button', { name: 'Avbryt' }).click();
    await expect(row.getByRole('button', { name: 'Marker som fullført' })).toBeFocused();

    await row.getByRole('button', { name: 'Marker som fullført' }).click();
    await row.locator('textarea').fill('Montert og testet.');
    await row.getByRole('button', { name: 'Fullfør tiltak' }).click();
    await expect(page.getByText('Tiltaket er markert som fullført.')).toBeVisible();
    // The tiltak moved to Fullførte tiltak; focus is on one of its own buttons, not the page.
    await expect.poll(() => focusedId(page)).toMatch(/^risk-action-\d+-(edit|reopen)$/);

    await actions.getByRole('button', { name: 'Gjenåpne' }).click();
    await expect.poll(() => focusedId(page)).toMatch(/^risk-action-\d+-complete$/);

    // Aksept av restrisiko: the form takes focus, a refused save marks Begrunnelse, Avbryt gives focus back.
    const acceptance = page.locator('section', { has: page.getByRole('heading', { name: 'Aksept av restrisiko', exact: true }) });
    await acceptance.getByRole('button', { name: 'Aksepter restrisiko' }).click();
    await expect(page.locator('#risk-acceptance-rationale')).toBeFocused();
    await expect(page.locator('#risk-acceptance-rationale')).toHaveAttribute('aria-describedby', /risk-acceptance-rationale-hint/);
    await expect(acceptance.getByText('Felt merket med * må fylles ut.')).toBeVisible();
    await expectNoAxeViolations(page, 'section:has(#risk-acceptance-heading)', 'Aksept av restrisiko');
    await acceptance.getByRole('button', { name: 'Avbryt' }).click();
    await expect(acceptance.getByRole('button', { name: 'Aksepter restrisiko' })).toBeFocused();

    await expectNoAxeViolations(page, 'section:has(#risk-treatment-heading)', 'Tiltak');
    await expectNoAxeViolations(page, 'section:has(#risk-assessment-heading)', 'Risikovurdering');

    // 320 px on the risk page.
    await page.setViewportSize({ width: 320, height: 800 });
    await expect.poll(() => sidewaysOverflow(page)).toEqual([]);
    await page.setViewportSize(DESKTOP);
});
