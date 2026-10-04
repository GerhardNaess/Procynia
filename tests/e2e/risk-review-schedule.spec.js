import { exec } from 'node:child_process';
import { promisify } from 'node:util';
import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';

const execAsync = promisify(exec);

/**
 * Periodisk vurdering, end to end: an assessed risk gets a quarterly interval, the page shows the
 * next review (overdue, since the assessment is moved back in time), and a new assessment moves the
 * next review forward. Date rules, permissions and tenancy are owned by RiskReviewScheduleTest.
 */
test('a quarterly interval shows the next review, and a new assessment moves it', async ({ page }) => {
    const suffix = Math.random().toString(36).slice(2, 8).toUpperCase();
    const areaName = `E2E Drift ${suffix}`;
    const roleName = `E2E Risikogjennomgang ${suffix}`;
    const riskTitle = `E2E Svikt i backup ${suffix}`;

    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);

    await page.goto('/app/customer-environment?tab=permissions');
    await page.getByRole('button', { name: 'Nytt fagområde' }).click();
    await page.locator('#business-area-name').fill(areaName);
    await page.getByRole('button', { name: 'Lagre fagområde' }).click();
    await expect(page.locator('tbody tr', { hasText: areaName })).toBeVisible();

    await page.getByRole('button', { name: 'Ny rolle' }).click();
    await page.locator('#customer-role-name').fill(roleName);
    await page.getByRole('checkbox', { name: 'Se risikoer', exact: true }).check();
    await page.getByRole('checkbox', { name: 'Opprette risikoer', exact: true }).check();
    await page.getByRole('checkbox', { name: 'Endre risikoer', exact: true }).check();
    await page.getByRole('checkbox', { name: 'Vurdere risikoer', exact: true }).check();
    await page.getByRole('checkbox', { name: areaName, exact: true }).check();
    await page.getByRole('button', { name: 'Lagre rolle' }).click();
    await expect(page.locator('tr', { hasText: roleName }).first()).toBeVisible();

    await page.goto('/app/customer-environment?tab=users');
    await page.locator('tbody tr', { hasText: USER.email }).first().getByRole('link', { name: 'Rediger' }).click();
    await page.waitForURL(/\/app\/users\/\d+\/edit/);
    await page.getByRole('checkbox', { name: roleName, exact: true }).check();
    await page.getByRole('button', { name: 'Lagre endringer' }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/edit'));

    await page.context().clearCookies();
    await loginAs(page, USER.email, USER.password);

    await page.goto('/app/risk');
    await page.getByRole('button', { name: 'Ny risiko' }).click();
    await page.locator('#risk-title').fill(riskTitle);
    await page.locator('#risk-area').selectOption({ label: areaName });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await page.waitForURL(/\/app\/risk\/risks\/\d+$/);
    const riskId = Number(page.url().match(/\/risks\/(\d+)$/)[1]);

    const assess = async (rationale) => {
        await page.getByRole('button', { name: 'Ny vurdering' }).click();
        await page.locator('#assessment-inherent-likelihood').selectOption('3');
        await page.locator('#assessment-inherent-consequence').selectOption('4');
        await page.locator('#assessment-rationale').fill(rationale);
        await page.getByRole('button', { name: 'Lagre vurdering' }).click();
        await expect(page.getByText('Vurderingen er registrert.')).toBeVisible();
    };

    await assess('Backup tas nattlig, men gjenoppretting er ikke testet.');

    // The first assessment as if it had been made at the end of June.
    await execAsync(
        `docker compose exec -T app php artisan tinker --execute="DB::table('risk_assessments')->where('risk_id', ${riskId})->update(['assessed_at' => '2026-06-30 10:00:00']);"`,
    );
    await page.reload();

    const details = page.locator('section', { has: page.getByRole('heading', { name: 'Detaljer', exact: true }) });
    const field = (label) => details.locator('dt', { hasText: new RegExp(`^${label}$`) }).locator('xpath=following-sibling::dd');
    await expect(field('Vurderingsintervall')).toHaveText('Ingen fast intervall');
    await expect(field('Sist vurdert')).toHaveText('30. juni 2026');
    await expect(field('Neste vurdering')).toHaveText('Ingen fast vurdering');

    // Kvartalsvis, set in the edit form.
    await page.getByRole('button', { name: 'Rediger' }).click();
    await page.locator('#risk-review-interval').selectOption({ label: 'Kvartalsvis' });
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.locator('#risk-review-interval')).toHaveCount(0);

    await expect(field('Vurderingsintervall')).toHaveText('Kvartalsvis');
    await expect(field('Sist vurdert')).toHaveText('30. juni 2026');
    await expect(field('Neste vurdering')).toContainText('30. september 2026');
    await expect(field('Neste vurdering').getByText('Forfalt', { exact: true })).toBeVisible();
    await details.screenshot({ path: 'test-results/risk-review-overdue.png' });

    // A new assessment is the review: the next date moves a quarter on from today.
    await assess('Kvartalsvis gjennomgang: gjenoppretting testet i september.');
    const today = new Date();
    const next = new Date(today.getFullYear(), today.getMonth() + 3, today.getDate());
    const format = (date) => date.toLocaleDateString('nb-NO', { day: 'numeric', month: 'long', year: 'numeric' });
    await expect(field('Sist vurdert')).toHaveText(format(today));
    await expect(field('Neste vurdering')).toHaveText(format(next));
    await expect(details.getByText('Forfalt', { exact: true })).toHaveCount(0);
    await details.screenshot({ path: 'test-results/risk-review-moved.png' });
});
