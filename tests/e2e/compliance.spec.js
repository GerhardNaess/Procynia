import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';
import { COMPLIANCE_E2E_PASSWORD, cleanUpComplianceE2eData, complianceE2eName, complianceE2eSuffix, complianceFixture } from './helpers/compliance.js';
import { DESKTOP, expectPageHelp, expectReadable as expectReadableAt } from './helpers/readability.js';

const suffix = complianceE2eSuffix();
cleanUpComplianceE2eData(suffix);

const expectReadable = (page, name) => expectReadableAt(page, 'compliance', name);
const registerUrl = (query = {}) => `/app/compliance/requirements?${new URLSearchParams({ search: `E2E Krav ${suffix}`, ...query })}`;

/**
 * Etterlevelse og revisjon, end to end: a person whose role grants compliance opens Styring and
 * the module from there, creates a kravkilde, registers a requirement under it, opens and edits
 * it, retires it with a reason, reads the status history, reopens it, and finds it again through
 * the register's search and filters. Every page and form on the way is checked for text under
 * 16 px and sideways scrolling at desktop and 390 px, and both pages' help is opened.
 */
test('a requirement is registered under a new source, edited, retired and reopened, with its history kept', async ({ page }) => {
    test.setTimeout(180_000);

    const person = await complianceFixture(`seedJourney('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);
    const sourceName = complianceE2eName(suffix, 'ISO 27001');
    const sourceLabel = `${sourceName} (2022)`;
    const title = complianceE2eName(suffix, 'Tilgangsstyring');
    const editedTitle = complianceE2eName(suffix, 'Tilgangsstyring for alle systemer');

    await loginAs(page, person.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    // Styring → Etterlevelse og revisjon.
    await page.goto('/app/dashboard');
    await page.getByTestId('module-governance').click();
    await page.waitForURL(/\/app\/governance$/);
    await page.getByTestId('governance-module-compliance').click();
    await page.waitForURL(/\/app\/compliance\/requirements$/);
    await expect(page.getByRole('heading', { name: 'Krav', level: 1 })).toBeVisible();
    // One current page: the work area, under the module that stays marked as the place you are in.
    await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText('Krav');
    await expect(page.getByTestId('module-compliance')).toHaveAttribute('data-active', 'true');
    await expectPageHelp(page, 'Om krav', ['Krav og kravkilder', 'Etterlevelse og revisjon', 'Registeret']);
    await expectReadable(page, '01-register');

    // Kravkilder: a new source, with the server's own messages first.
    await page.getByRole('button', { name: 'Kravkilder', exact: true }).click();
    const sources = page.getByTestId('compliance-sources');
    await sources.getByRole('button', { name: 'Ny kravkilde' }).click();
    await sources.locator('form [required]').evaluateAll((elements) => elements.forEach((element) => element.removeAttribute('required')));
    await sources.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Navn må fylles ut.')).toBeVisible();
    await page.locator('#compliance-source-name').fill(sourceName);
    await page.locator('#compliance-source-version').fill('2022');
    await page.locator('#compliance-source-kind').selectOption({ label: 'Standard' });
    await page.locator('#compliance-source-description').fill('Informasjonssikkerhet.');
    await expectReadable(page, '02-source-form');
    await sources.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Kravkilden er opprettet.')).toBeVisible();

    // Nytt krav: no status field, the server's messages, then the real thing.
    await page.getByRole('button', { name: 'Nytt krav' }).click();
    await expect(page.locator('#compliance-requirement-status')).toHaveCount(0);
    await page.locator('#compliance-requirement-source').selectOption({ label: sourceLabel });
    await page.locator('form [required]').evaluateAll((elements) => elements.forEach((element) => element.removeAttribute('required')));
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Tittel må fylles ut.')).toBeVisible();
    await expect(page.getByText('Kravtekst må fylles ut.')).toBeVisible();
    await expect(page.getByText('Ansvarlig må fylles ut.')).toBeVisible();

    await page.locator('#compliance-requirement-source').selectOption({ label: sourceLabel });
    await page.locator('#compliance-requirement-reference').fill('A.5.15');
    await page.locator('#compliance-requirement-title').fill(title);
    await page.locator('#compliance-requirement-text').fill('Regler for fysisk og logisk tilgang til informasjon skal etableres og implementeres.');
    await page.locator('#compliance-requirement-owner').selectOption({ label: person.name });
    await page.locator('#compliance-requirement-review').selectOption({ label: 'Årlig' });
    await expectReadable(page, '03-requirement-form');
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();

    await page.waitForURL(/\/app\/compliance\/requirements\/\d+$/);
    const requirementUrl = page.url();
    await expect(page.getByText('Kravet er registrert.')).toBeVisible();
    await expect(page.getByRole('heading', { name: title, level: 1 })).toBeVisible();
    await expect(page.getByTestId('compliance-reference')).toHaveText('A.5.15');
    const facts = page.getByTestId('compliance-facts');
    for (const text of [sourceLabel, person.name, 'Årlig']) {
        await expect(facts).toContainText(text);
    }
    await expect(page.getByTestId('compliance-status')).toContainText('Kravet gjelder for virksomheten.');
    await expect(page.getByTestId('compliance-history')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Slett krav' })).toBeVisible();
    await expectPageHelp(page, 'Om kravet', ['Ansvar og oppfølging', 'Status', 'Statushistorikk']);
    await expectReadable(page, '04-requirement');

    // The register lists it with every column; open it from there.
    await page.goto(registerUrl());
    const row = page.locator('tbody tr', { hasText: title });
    for (const text of ['A.5.15', sourceLabel, person.name, 'Årlig', 'Aktiv']) {
        await expect(row).toContainText(text);
    }
    await expect(page.getByTestId('compliance-count')).toBeVisible();
    await expectReadable(page, '05-register-with-requirement');
    await row.getByRole('link', { name: title }).click();
    await page.waitForURL(requirementUrl);

    // Rediger: title and interval change, the status does not.
    await page.getByRole('button', { name: 'Rediger' }).click();
    await page.locator('#compliance-requirement-title').fill(editedTitle);
    await page.locator('#compliance-requirement-review').selectOption({ label: 'Kvartalsvis' });
    await expectReadable(page, '06-edit');
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Kravet er oppdatert.')).toBeVisible();
    await expect(page.getByRole('heading', { name: editedTitle, level: 1 })).toBeVisible();
    await expect(facts).toContainText('Kvartalsvis');
    const header = page.locator('header').filter({ has: page.getByRole('heading', { level: 1 }) });
    await expect(header).toContainText('Aktiv');

    // Sett som utgått: the reason is required, and the server says so in Norwegian.
    await page.getByRole('button', { name: 'Sett som utgått' }).click();
    await expectReadable(page, '07-retire-form');
    await page.locator('#compliance-retire-reason').evaluate((element) => element.removeAttribute('required'));
    await page.getByRole('button', { name: 'Sett som utgått', exact: true }).last().click();
    await expect(page.getByText('Begrunnelse må fylles ut.')).toBeVisible();
    await page.locator('#compliance-retire-reason').fill('Erstattet av nytt krav etter revidert standard.');
    await page.getByRole('button', { name: 'Sett som utgått', exact: true }).last().click();
    await expect(page.getByText('Kravet er satt som utgått.')).toBeVisible();
    await expect(header).toContainText('Utgått');
    await expect(page.getByRole('button', { name: 'Rediger' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Slett krav' })).toHaveCount(0);

    // Statushistorikk.
    const history = page.getByTestId('compliance-history');
    await expect(history.locator('li')).toHaveCount(1);
    await expect(history.locator('li').first()).toContainText(`Satt som utgått av ${person.name}`);
    await expect(history.locator('li').first()).toContainText('Erstattet av nytt krav etter revidert standard.');
    await expectReadable(page, '08-retired');

    // Gjenåpne: the reason is required; the retirement stays in the history.
    await page.getByRole('button', { name: 'Gjenåpne' }).click();
    await page.locator('#compliance-reopen-reason').evaluate((element) => element.removeAttribute('required'));
    await page.getByRole('button', { name: 'Gjenåpne krav' }).click();
    await expect(page.getByText('Begrunnelse må fylles ut.')).toBeVisible();
    await page.locator('#compliance-reopen-reason').fill('Den nye standarden er utsatt; kravet gjelder fortsatt.');
    await page.getByRole('button', { name: 'Gjenåpne krav' }).click();
    await expect(page.getByText('Kravet er gjenåpnet.')).toBeVisible();
    await expect(header).toContainText('Aktiv');
    await expect(history.locator('li')).toHaveCount(2);
    await expect(history.locator('li').nth(0)).toContainText(`Gjenåpnet av ${person.name}`);
    await expect(history.locator('li').nth(0)).toContainText('Den nye standarden er utsatt');
    await expect(history.locator('li').nth(1)).toContainText('Satt som utgått');
    await expect(page.getByRole('button', { name: 'Rediger' })).toBeVisible();
    // It has a history now: handled through its lifecycle, never deleted.
    await expect(page.getByRole('button', { name: 'Slett krav' })).toHaveCount(0);
    await expectReadable(page, '09-reopened');

    // The register's filters: status and source.
    await page.goto(registerUrl());
    await page.locator('#compliance-status-filter').selectOption({ label: 'Utgått' });
    await page.getByRole('button', { name: 'Søk', exact: true }).click();
    await expect(page).toHaveURL(/status=retired/);
    await expect(page.getByText('Ingen krav passer søket.')).toBeVisible();
    await page.locator('#compliance-status-filter').selectOption({ label: 'Aktiv' });
    await page.locator('#compliance-source-filter').selectOption({ label: sourceLabel });
    await page.getByRole('button', { name: 'Søk', exact: true }).click();
    await expect(page).toHaveURL(/source=\d+/);
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(page.locator('tbody tr').first()).toContainText(editedTitle);
    await expectReadable(page, '10-register-filtered');

    // The source is in use: it offers Rediger but no Slett.
    await page.getByRole('button', { name: 'Kravkilder', exact: true }).click();
    await expect(sources.getByRole('button', { name: `Rediger ${sourceLabel}` })).toBeVisible();
    await expect(sources.getByRole('button', { name: `Slett ${sourceLabel}` })).toHaveCount(0);
    await expect(sources).toContainText('1 krav');
});

/**
 * Someone who may only read sees the register, the requirements and their history, and is offered
 * no change at all. System Owner without a compliance role of their own is not offered the module,
 * is refused it by URL, and is told in Tilganger that this access is not automatic.
 */
test('a reader changes nothing, and System Owner reaches nothing without a role of their own', async ({ page }) => {
    test.setTimeout(90_000);

    const seeded = await complianceFixture(`seedReader('${suffix}', '${COMPLIANCE_E2E_PASSWORD}')`);

    await loginAs(page, seeded.reader_email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);

    await page.goto(registerUrl());
    await expect(page.locator('tbody tr', { hasText: seeded.active_title })).toContainText('Aktiv');
    await expect(page.locator('tbody tr', { hasText: seeded.retired_title })).toContainText('Utgått');
    // Active first.
    await expect(page.locator('tbody tr').first()).toContainText(seeded.active_title);
    await expect(page.getByRole('button', { name: 'Nytt krav' })).toHaveCount(0);

    await page.getByRole('button', { name: 'Kravkilder', exact: true }).click();
    const sources = page.getByTestId('compliance-sources');
    await expect(sources).toContainText(seeded.source_label);
    await expect(sources.getByRole('button', { name: 'Ny kravkilde' })).toHaveCount(0);
    await expect(sources.getByRole('button', { name: /^(Rediger|Slett) / })).toHaveCount(0);
    await expectReadable(page, '11-reader-register');

    for (const id of [seeded.active_id, seeded.retired_id]) {
        await page.goto(`/app/compliance/requirements/${id}`);

        for (const action of ['Rediger', 'Sett som utgått', 'Gjenåpne', 'Slett krav']) {
            await expect(page.getByRole('button', { name: action })).toHaveCount(0);
        }
    }

    await expect(page.getByTestId('compliance-history').locator('li')).toHaveCount(1);
    await expect(page.getByTestId('compliance-history')).toContainText('Meldeplikten er opphevet.');
    await expectReadable(page, '12-reader-requirement');

    // System Owner administers roles, but holds no compliance key without one of their own.
    await page.context().clearCookies();
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/dashboard');
    await expect(page.getByTestId('module-compliance')).toHaveCount(0);
    await page.goto('/app/governance');
    await expect(page.getByTestId('governance-module-compliance')).toHaveCount(0);

    for (const url of ['/app/compliance/requirements', `/app/compliance/requirements/${seeded.active_id}`]) {
        const response = await page.goto(url);
        expect(response.status()).toBe(403);
    }

    await page.goto('/app/customer-environment?tab=permissions');
    await expect(page.getByTestId('explicit-grant-note-compliance')).toHaveText(
        'System Owner får ikke disse rettighetene automatisk. Skal du arbeide her, gi deg selv en rolle med rettighetene.',
    );
});
