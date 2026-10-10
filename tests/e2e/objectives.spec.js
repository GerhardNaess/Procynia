import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';
import { cleanUpObjectiveE2eData, objectiveE2eName, objectiveE2eSuffix, seedViewOnlyObjectives } from './helpers/objectives.js';

const suffix = objectiveE2eSuffix();
cleanUpObjectiveE2eData(suffix);

/**
 * Mål, end to end. System Owner names a fagområde and a role in Kundemiljø → Tilganger and hands it
 * to an ordinary user, who then creates, opens, edits, closes, reopens and deletes an objective
 * there, reading the history after each status change.
 */
test('an objective is created, edited, closed, reopened and deleted by someone with a role for its area', async ({ page }) => {
    test.setTimeout(120_000);

    const areaName = objectiveE2eName(suffix, 'Reise');
    const roleName = objectiveE2eName(suffix, 'Målansvarlig');
    const title = objectiveE2eName(suffix, 'Lavere sykefravær');
    const editedTitle = objectiveE2eName(suffix, 'Sykefravær under 4 prosent');

    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);

    // System Owner reaches Mål og KPI but reads nothing there without a role.
    await page.goto('/app/objectives');
    await expect(page.getByRole('heading', { name: 'Mål', exact: true })).toBeVisible();
    await expect(page.getByText(/^(Ingen fagområder er opprettet ennå|Du har ikke tilgang til noen fagområder i Mål og KPI ennå)$/)).toBeVisible();

    await page.goto('/app/customer-environment?tab=permissions');
    await page.getByRole('button', { name: 'Nytt fagområde' }).click();
    await page.locator('#business-area-name').fill(areaName);
    await page.getByRole('button', { name: 'Lagre fagområde' }).click();
    await expect(page.locator('tbody tr', { hasText: areaName })).toBeVisible();

    await page.getByRole('button', { name: 'Ny rolle' }).click();
    await page.locator('#customer-role-name').fill(roleName);
    for (const permission of ['Se mål og KPI-er', 'Opprette og endre mål og KPI-er', 'Slette mål og KPI-er']) {
        await page.getByRole('checkbox', { name: permission, exact: true }).check();
    }
    await page.getByRole('checkbox', { name: areaName, exact: true }).check();
    await page.getByRole('button', { name: 'Lagre rolle' }).click();
    // Each module's matrix sits in a section that starts closed.
    await page.getByRole('button', { name: /^Mål og KPI/ }).click();
    await expect(page.locator('tr', { hasText: roleName }).filter({ hasText: areaName }).first()).toBeVisible();

    await page.goto('/app/customer-environment?tab=users');
    await page.locator('tbody tr', { hasText: USER.email }).first().getByRole('link', { name: 'Rediger' }).click();
    await page.waitForURL(/\/app\/users\/\d+\/edit/);
    await page.getByRole('checkbox', { name: roleName, exact: true }).check();
    await page.getByRole('button', { name: 'Lagre endringer' }).click();
    await page.waitForURL((url) => ! url.pathname.endsWith('/edit'));

    await page.context().clearCookies();
    await loginAs(page, USER.email, USER.password);

    // The rail offers Mål og KPI as a link.
    await page.goto('/app/objectives');
    await expect(page.getByRole('link', { name: 'Mål og KPI' }).first()).toBeVisible();

    // Opprett: no status field, title/area/owner required.
    await page.getByRole('button', { name: 'Nytt mål' }).click();
    await expect(page.locator('#objective-status')).toHaveCount(0);
    await expect(page.locator('label[for="objective-owner"]')).toContainText('*');
    await page.locator('#objective-title').fill(title);
    await page.locator('#objective-description').fill('Sykefraværet skal ned gjennom bedre oppfølging.');
    await page.locator('#objective-area').selectOption({ label: areaName });
    await page.locator('#objective-owner').selectOption({ index: 1 });
    await page.screenshot({ path: 'test-results/objectives-01-create.png', fullPage: true });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();

    await page.waitForURL(/\/app\/objectives\/\d+$/);
    const objectiveUrl = page.url();
    await expect(page.getByRole('heading', { name: title })).toBeVisible();
    await expect(page.getByText('Målet er registrert.')).toBeVisible();
    const header = page.locator('header').filter({ has: page.getByRole('heading', { level: 1 }) });
    await expect(header).toContainText('Aktivt');
    await expect(page.getByText('Løpende', { exact: true })).toBeVisible();

    // Åpne from the overview.
    await page.goto('/app/objectives');
    const row = page.locator('tbody tr', { hasText: title });
    await expect(row).toContainText(areaName);
    await expect(row).toContainText('Aktivt');
    await expect(row).toContainText('Løpende');
    await page.screenshot({ path: 'test-results/objectives-02-overview.png', fullPage: true });
    await row.getByRole('link', { name: title }).click();
    await page.waitForURL(objectiveUrl);

    // Rediger: title and target date; status stays.
    await page.getByRole('button', { name: 'Rediger' }).click();
    await page.locator('#objective-title').fill(editedTitle);
    await page.locator('#objective-target-date').fill('2027-06-30');
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Målet er oppdatert.')).toBeVisible();
    await expect(page.getByRole('heading', { name: editedTitle })).toBeVisible();
    await expect(page.getByText('30.06.2027', { exact: true })).toBeVisible();
    await expect(header).toContainText('Aktivt');
    await page.screenshot({ path: 'test-results/objectives-03-show.png', fullPage: true });

    // Lukk mål: the outcome is the decision; the comment goes into the history.
    const history = page.getByTestId('objective-history');
    await expect(history).toHaveCount(0);
    await page.getByRole('button', { name: 'Lukk mål' }).click();
    await page.getByRole('radio', { name: 'Oppnådd', exact: true }).check();
    await page.locator('#objective-close-note').fill('Målet er nådd.');
    await page.getByRole('button', { name: 'Lukk mål', exact: true }).last().click();
    await expect(page.getByText('Målet er lukket.')).toBeVisible();
    await expect(header).toContainText('Oppnådd');
    await expect(header).toContainText(/Lukket \d{1,2}\. \S+ \d{4} av E2E User\./);

    // Historikk: one entry, the closing.
    await expect(history.locator('li')).toHaveCount(1);
    await expect(history.locator('li').first()).toContainText('Lukket som Oppnådd av E2E User');
    await expect(history.locator('li').first()).toContainText('Målet er nådd.');
    await expect(history.locator('li').first()).toContainText(/\d{1,2}\. \S+ \d{4}/);

    // A closed objective is reopened, not edited.
    await expect(page.getByRole('button', { name: 'Rediger' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Lukk mål' })).toHaveCount(0);
    await page.screenshot({ path: 'test-results/objectives-04-closed.png', fullPage: true });

    // Gjenåpne: the reason is required, and the server says so in Norwegian.
    await page.getByRole('button', { name: 'Gjenåpne' }).click();
    await page.locator('#objective-reopen-reason').evaluate((el) => el.removeAttribute('required'));
    await page.getByRole('button', { name: 'Gjenåpne mål' }).click();
    await expect(page.getByText('Begrunnelse må fylles ut.')).toBeVisible();
    await page.locator('#objective-reopen-reason').fill('Nye krav gjør at målet må videreføres.');
    await page.getByRole('button', { name: 'Gjenåpne mål' }).click();
    await expect(page.getByText('Målet er gjenåpnet.')).toBeVisible();
    await expect(header).toContainText('Aktivt');

    // Historikk: newest first, and the first closing is still there, note and all.
    await expect(history.locator('li')).toHaveCount(2);
    await expect(history.locator('li').nth(0)).toContainText('Gjenåpnet av E2E User');
    await expect(history.locator('li').nth(0)).toContainText('Nye krav gjør at målet må videreføres.');
    await expect(history.locator('li').nth(1)).toContainText('Lukket som Oppnådd av E2E User');
    await expect(history.locator('li').nth(1)).toContainText('Målet er nådd.');
    await expect(page.getByRole('button', { name: 'Rediger' })).toBeVisible();
    await page.screenshot({ path: 'test-results/objectives-05-reopened.png', fullPage: true });

    // Slett. The history goes with the objective.
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Slett mål' }).click();
    await page.waitForURL(/\/app\/objectives$/);
    await expect(page.getByText('Målet er slettet.')).toBeVisible();
    await expect(page.locator('tbody tr', { hasText: editedTitle })).toHaveCount(0);
});

/**
 * Someone who may only read sees the objectives of their area, can change nothing, and cannot
 * find an objective in another area by count, search or URL.
 */
test('a reader sees only their area and is offered no changes', async ({ page }) => {
    const { visible_id: visibleId, hidden_id: hiddenId } = await seedViewOnlyObjectives(suffix);
    const visibleTitle = objectiveE2eName(suffix, 'Synlig mål');
    const hiddenTitle = objectiveE2eName(suffix, 'Skjult mål');

    await loginAs(page, USER.email, USER.password);

    await page.goto(`/app/objectives?search=${encodeURIComponent(`E2E Mål ${suffix}`)}`);
    await expect(page.locator('tbody tr', { hasText: visibleTitle })).toBeVisible();
    await expect(page.getByText(hiddenTitle)).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Nytt mål' })).toHaveCount(0);

    await page.goto(`/app/objectives/${visibleId}`);
    await expect(page.getByRole('heading', { name: visibleTitle })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Rediger' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Slett mål' })).toHaveCount(0);
    await page.screenshot({ path: 'test-results/objectives-06-reader.png', fullPage: true });

    const response = await page.goto(`/app/objectives/${hiddenId}`);
    expect(response.status()).toBe(404);
});
