import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { DESKTOP, expectReadable as expectReadableAt } from './helpers/readability.js';
import { cleanUpSupplierE2eData, SUPPLIER_E2E_PASSWORD, supplierE2eSuffix, supplierFixture } from './helpers/suppliers.js';

const suffix = supplierE2eSuffix();
cleanUpSupplierE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'suppliers', name);

/**
 * Veiledning fra Enterprise Wiki on a control requirement (supplier-assurance-v2-plan §28). Access,
 * tenant isolation, archived and deleted pages are PHP's (SupplierRequirementWikiGuidanceTest); this
 * is the journey: under Kontrollkrav, find an existing Wiki article for a requirement, link it, open
 * it in Enterprise Wiki, and remove the link again — readable at desktop and 390 px.
 */
test('a Wiki article is linked to a control requirement, opened in Enterprise Wiki and removed again', async ({ page, context }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedJourney('${suffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    const catalogue = await supplierFixture(`seedAssurance('${suffix}')`);
    const wiki = await supplierFixture(`seedWikiGuidance('${suffix}')`);

    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/supplier-management/control-requirements');

    const row = page.getByTestId('control-catalogue-row').filter({ hasText: catalogue.dpa_title });
    const guidance = row.getByTestId('wiki-guidance');
    await expect(guidance.getByRole('heading', { name: 'Veiledning fra Enterprise Wiki' })).toBeVisible();
    await expect(guidance.getByTestId('wiki-guidance-none')).toHaveText('Ingen veiledning er knyttet til kravet.');

    // Find: the article is offered, the archived page is not.
    await guidance.getByRole('button', { name: 'Knytt til artikkel' }).click();
    await guidance.getByLabel('Søk i Enterprise Wiki').fill('databehandler');
    const results = guidance.getByTestId('wiki-guidance-result');
    await expect(results.filter({ hasText: wiki.page_title })).toHaveCount(1);
    await expect(results.filter({ hasText: wiki.archived_title })).toHaveCount(0);
    await expectReadable(page, '70-wiki-guidance-search');

    // Link.
    await results.filter({ hasText: wiki.page_title }).getByRole('button', { name: 'Knytt til' }).click();
    await expect(page.getByText('Artikkelen er knyttet til kravet.', { exact: true })).toBeVisible();
    const linked = guidance.getByTestId('wiki-guidance-page');
    await expect(linked).toHaveCount(1);
    await expect(linked).toContainText(wiki.page_title);
    await expect(linked).toContainText('Ikke publisert ennå');
    await expectReadable(page, '71-wiki-guidance-linked');

    // Open: the article opens in Enterprise Wiki, in its own tab.
    const [article] = await Promise.all([context.waitForEvent('page'), linked.getByRole('link').click()]);
    await article.waitForLoadState();
    expect(new URL(article.url()).pathname).toBe(`/app/wiki/${wiki.page_slug}`);
    await expect(article.getByText(wiki.page_title).first()).toBeVisible();
    await article.close();

    // Remove: the link goes; the article stays in the Wiki.
    page.once('dialog', (dialog) => dialog.accept());
    await linked.getByRole('button', { name: 'Fjern' }).click();
    await expect(page.getByText('Artikkelen er fjernet fra kravet.', { exact: true })).toBeVisible();
    await expect(guidance.getByTestId('wiki-guidance-none')).toBeVisible();
});
