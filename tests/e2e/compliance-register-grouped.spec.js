import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { COMPLIANCE_E2E_PASSWORD, cleanUpComplianceE2eData, complianceE2eSuffix, complianceFixture } from './helpers/compliance.js';
import { DESKTOP, PHONE, expectPageHelp, expectReadable as expectReadableAt, sidewaysOverflow } from './helpers/readability.js';

const suffix = complianceE2eSuffix();
cleanUpComplianceE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'compliance-register-grouped', name);

/**
 * Kravregister, Gruppert and Liste, as someone with three ISO standards and an internal source meets
 * it: overview first — kravkilde, then kapittel, then krav — with counts; Vedlegg A by its themes; a
 * source without chapters listing its requirements directly; the way back from a requirement to the
 * same open chapter; Liste beside it; a search across every source shown as a list, and the chosen
 * view back once it is cleared; a filter narrowing groups and counts. Readable at 1440 and 390 px.
 */
test('Kravregister: grouped by source and chapter, with list, search and filters', async ({ page }) => {
    test.setTimeout(240_000);

    const person = await complianceFixture(`seedRegisterJourney('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);
    await loginAs(page, person.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    const sourceName = (label) => new RegExp(`^${label.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')}\\b`);
    const sourceButton = (label) => page.getByTestId('compliance-register-groups').locator('h3').getByRole('button', { name: sourceName(label) });
    const sourceItem = (label) => page.locator('[data-testid^="compliance-register-source-"]').filter({ has: page.locator('h3').getByRole('button', { name: sourceName(label) }) });
    const viewButton = (name) => page.getByTestId('compliance-view-switch').getByRole('button', { name, exact: true });

    // 1. Gruppert by default: one row per source with its count, nothing open, no requirement shown.
    await page.goto('/app/compliance/requirements');
    await expect(viewButton('Gruppert')).toHaveAttribute('aria-pressed', 'true');
    await expect(viewButton('Liste')).toHaveAttribute('aria-pressed', 'false');
    for (const [label, count] of [['ISO 9001:2015', '65 krav'], ['ISO/IEC 27001:2022', '123 krav'], ['ISO 14001:2026', '35 krav'], [person.internal_label, '2 krav']]) {
        await expect(sourceButton(label)).toHaveAttribute('aria-expanded', 'false');
        await expect(sourceItem(label).getByTestId('compliance-source-group-count')).toHaveText(count);
    }
    await expect(page.getByTestId('compliance-group-requirement')).toHaveCount(0);
    await expect(page.getByTestId('compliance-requirement-table')).toHaveCount(0);
    await expectPageHelp(page, 'Om kravregisteret', ['Hva brukes siden til?', 'Slik gjør du det', 'Registeret']);
    await expectReadable(page, '01-grouped-closed');

    // 2. ISO/IEC 27001: chapters 4–10, then Vedlegg A by its four themes.
    await sourceButton('ISO/IEC 27001:2022').click();
    await expect(sourceButton('ISO/IEC 27001:2022')).toHaveAttribute('aria-expanded', 'true');
    const iso27001 = sourceItem('ISO/IEC 27001:2022');
    await expect(iso27001.locator('h4 button')).toHaveCount(11);
    await expect(iso27001.locator('h4 button').first()).toContainText('4. Organisasjonens kontekst');
    for (const [reference, count] of [['A.5', '37 krav'], ['A.6', '8 krav'], ['A.7', '14 krav'], ['A.8', '34 krav']]) {
        const theme = iso27001.getByTestId(`compliance-group-${reference}`);
        await expect(theme.getByTestId('compliance-group-count')).toHaveText(count);
        await expect(theme.getByTestId('compliance-group-context')).toHaveText('Vedlegg A – Informasjonssikkerhetskontroller');
    }

    // 3. A theme opens to its controls: origin, Kontrollvalg and status, no requirement text.
    const a5 = iso27001.getByTestId('compliance-group-A.5');
    await a5.getByRole('button').click();
    await expect(a5.getByRole('button')).toHaveAttribute('aria-expanded', 'true');
    await expect(a5.getByTestId('compliance-group-requirement')).toHaveCount(37);
    const supplierControl = a5.getByTestId('compliance-group-requirement').filter({ hasText: 'A.5.19' });
    await expect(supplierControl.getByTestId('compliance-origin')).toHaveText('ISO/IEC 27001:2022 · A.5.19 · Informasjonssikkerhet i leverandørforhold');
    await expect(supplierControl).toContainText('Ikke valgt');
    await expect(supplierControl).toContainText('Aktiv');
    await expect(page).toHaveURL(/open=/);
    await expectReadable(page, '02-annex-a-theme-open');

    // 4. The requirement opens; «Tilbake til kravregisteret» returns to the same open theme.
    await supplierControl.getByRole('link', { name: 'Informasjonssikkerhet i leverandørforhold' }).click();
    await page.waitForURL(/\/app\/compliance\/requirements\/\d+$/);
    await expect(page.getByTestId('compliance-origin-source')).toHaveText('ISO/IEC 27001:2022');
    await page.getByTestId('compliance-back').click();
    await page.waitForURL(/\/app\/compliance\/requirements\?.*open=/);
    await expect(iso27001.getByTestId('compliance-group-A.5').getByRole('button')).toHaveAttribute('aria-expanded', 'true');
    await expect(iso27001.getByTestId('compliance-group-requirement').filter({ hasText: 'A.5.19' })).toBeVisible();

    // 5. The internal source has no chapters: its requirements sit directly under it.
    await sourceButton(person.internal_label).click();
    const internal = sourceItem(person.internal_label);
    await expect(internal.locator('h4')).toHaveCount(0);
    await expect(internal.getByTestId('compliance-source-requirements').getByTestId('compliance-group-requirement')).toHaveCount(2);
    await expect(internal).toContainText(person.internal_titles[0]);

    // 6. Liste: the table as before; Gruppert again keeps what was open.
    await viewButton('Liste').click();
    await expect(page).toHaveURL(/view=list/);
    await expect(page.getByTestId('compliance-requirement-table')).toBeVisible();
    await expect(page.getByTestId('compliance-requirement-table').locator('tbody tr', { hasText: 'Informasjonssikkerhet i leverandørforhold' }).getByTestId('compliance-relevance-cell')).toHaveText('Ikke valgt');
    await expect(page.getByTestId('compliance-register-groups')).toHaveCount(0);
    await viewButton('Gruppert').click();
    await expect(page).not.toHaveURL(/view=list/);
    await expect(iso27001.getByTestId('compliance-group-requirement').filter({ hasText: 'A.5.19' })).toBeVisible();

    // 7. A search answers across every source as a list, whatever is open; cleared, Gruppert is back.
    await page.locator('#compliance-search').fill('leverandør');
    await page.getByRole('button', { name: 'Søk', exact: true }).click();
    await expect(page.getByTestId('compliance-search-as-list')).toBeVisible();
    await expect(viewButton('Liste')).toHaveAttribute('aria-pressed', 'true');
    await expect(viewButton('Gruppert')).toBeDisabled();
    const hits = page.getByTestId('compliance-requirement-table').getByTestId('compliance-origin');
    await expect(hits.filter({ hasText: 'ISO/IEC 27001:2022 · A.5.19' })).toHaveCount(1);
    await expect(hits.filter({ hasText: `${person.internal_label} · L-1` })).toHaveCount(1);
    await expect(page.getByTestId('compliance-matching-count')).toBeVisible();
    await expectReadable(page, '03-search-as-list');

    await page.locator('#compliance-search').fill('');
    await page.getByRole('button', { name: 'Søk', exact: true }).click();
    await expect(page.getByTestId('compliance-search-as-list')).toHaveCount(0);
    await expect(viewButton('Gruppert')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('compliance-register-groups')).toBeVisible();

    // 8. A filter narrows the groups and their counts: only the controls wait for a Kontrollvalg.
    await page.locator('#compliance-applicability-filter').selectOption({ label: 'Ikke avklart / ikke valgt' });
    await page.getByRole('button', { name: 'Søk', exact: true }).click();
    await expect(sourceItem('ISO/IEC 27001:2022').getByTestId('compliance-source-group-count')).toHaveText('93 krav');
    await expect(sourceButton('ISO 14001:2026')).toHaveCount(0);
    await expect(sourceItem('ISO/IEC 27001:2022').locator('h4 button')).toHaveCount(4);
    await page.getByRole('button', { name: 'Nullstill' }).click();
    await expect(sourceItem('ISO/IEC 27001:2022').getByTestId('compliance-source-group-count')).toHaveText('123 krav');

    // 9. On a phone the three levels stay apart and nothing scrolls sideways.
    await page.setViewportSize(PHONE);
    await expect(sourceButton('ISO/IEC 27001:2022')).toBeVisible();
    expect(await sidewaysOverflow(page)).toEqual([]);
    await page.setViewportSize(DESKTOP);
    await expectReadable(page, '04-grouped-open-after-reset');
});
