import { test, expect } from '@playwright/test';
import { loginAs, USER } from './helpers/auth.js';

/**
 * The dashboard answers three questions in order. These check the information architecture
 * itself — the section order, and that the old duplicated KPI cards are gone — which needs no
 * seeded cases. The numbers behind each section are covered by dashboardLogic.test.js and
 * DashboardControllerTest.
 */

test.beforeEach(async ({ page }) => {
    await loginAs(page, USER.email, USER.password);
    await page.goto('/app/dashboard');
});

test('the three sections render in priority order', async ({ page }) => {
    const headings = page.getByRole('heading', { level: 2 });

    await expect(headings.filter({ hasText: 'Krever oppfølging' })).toBeVisible();

    // Card titles render uppercase, and innerText reflects text-transform — compare lowercased.
    const order = await page.evaluate(() => {
        const text = document.body.innerText.toLowerCase();

        return ['krever oppfølging', 'pipeline', 'resultater'].map((t) => text.indexOf(t));
    });

    expect(order.every((index) => index > -1), 'all three sections present').toBe(true);
    expect(order[0]).toBeLessThan(order[1]);
    expect(order[1]).toBeLessThan(order[2]);
});

test('the four follow-up signals are keyboard-reachable buttons', async ({ page }) => {
    for (const name of ['Go / No-Go', 'Mangler Bid Manager', 'Nær frist', 'Ingen aktivitet > 7 dager']) {
        const signal = page.getByRole('button', { name: new RegExp(name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')) }).first();
        await expect(signal, `${name} renders as a button`).toBeVisible();
        await expect(signal).toHaveAttribute('aria-expanded', /true|false/);
    }
});

test('the six pipeline stages render', async ({ page }) => {
    const pipeline = page.locator('section', { hasText: 'Pipeline' }).first();

    for (const stage of ['Registrert', 'Kvalifiseres', 'Go / No-Go', 'Under arbeid', 'Sendt', 'Forhandling']) {
        await expect(pipeline.getByText(stage, { exact: true }).first()).toBeVisible();
    }
});

test('the retired KPI cards are gone', async ({ page }) => {
    // The page subtitle legitimately uses the word "porteføljeoversikten", so match card titles
    // and metric labels rather than loose substrings.
    const body = await page.evaluate(() => document.body.innerText.toLowerCase());

    for (const removed of [
        'saker totalt',
        'saker med registrert utfall',
        'bid-kvalitet og styring',
        'ansvar & aktivitet',
        'lagrede watch lists',
        'saker med bidragsyter',
        'siste aktivitet',
        'pipeline-stadier',
        'gjennomsnittlig alder i fase',
        'aktive saker med bid-manager',
        'aktive saker med kommersiell eier',
    ]) {
        expect(body, `"${removed}" must no longer be on the dashboard`).not.toContain(removed);
    }
});
