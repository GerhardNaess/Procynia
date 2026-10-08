import { test, expect } from '@playwright/test';
import { loginAs, SYSTEM_OWNER } from './helpers/auth.js';
import { DESKTOP, PHONE, sidewaysOverflow } from './helpers/readability.js';

/**
 * Text under 16 px in the Abonnement card and any open dialog. Scoped on purpose: the InfoHint «i»
 * glyphs elsewhere on the page predate the card, and the glyph is named by its aria-label.
 */
const smallTextInCard = (page) => page.evaluate(() => {
    const roots = [document.querySelector('[data-testid="subscription-card"]'), ...document.querySelectorAll('[role="dialog"]')].filter(Boolean);
    const found = [];

    for (const root of roots) {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);

        while (walker.nextNode()) {
            const text = walker.currentNode.textContent.trim();
            const element = walker.currentNode.parentElement;

            if (! text || ! element || element.closest('[aria-hidden="true"], .sr-only, button[aria-label][aria-expanded]')) {
                continue;
            }

            const size = parseFloat(getComputedStyle(element).fontSize);

            if (size < 16 && element.getBoundingClientRect().width > 0) {
                found.push(`${size}px «${text.slice(0, 60)}»`);
            }
        }
    }

    return found;
});

test('system owner can access the billing page', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    const response = await page.goto('/app/billing');

    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/app\/billing/);
    // Page rendered — Stripe data is absent in the test env but rescue() handles it safely
    await expect(page.locator('nav').first()).toBeVisible();
});

/**
 * The seeded customer still holds the legacy Pro plan under the hood. The card names Basis, its
 * billing facts and the cancel action — never Pro/Max/Ultra, and no plan switch. Nothing is
 * cancelled here: the confirmation is opened and closed again, so the shared seed is untouched.
 */
test('the subscription card shows Basis, not the legacy plan', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);

    for (const viewport of [DESKTOP, PHONE]) {
        await page.setViewportSize(viewport);
        await page.goto('/app/billing');

        const card = page.getByTestId('subscription-card');
        await expect(card.getByRole('heading', { name: 'Abonnement' })).toBeVisible();
        await expect(card.getByText('Basis', { exact: true })).toBeVisible();
        await expect(card.getByText('Aktiv', { exact: true })).toBeVisible();
        await expect(card.getByText('Fakturering')).toBeVisible();
        await expect(card.getByText(/^(Månedlig|Årlig)$/)).toBeVisible();
        await expect(card.getByText('Inkluderte brukere')).toBeVisible();

        await expect(page.getByRole('button', { name: 'Endre abonnement' })).toHaveCount(0);
        expect(await page.locator('main').innerText()).not.toMatch(/\b(Pro|Max|Ultra|Enterprise)\b/);

        // Basis/Opsjoner and AI-kapasitet still have their own sections.
        await expect(page.getByTestId('module-packages')).toBeVisible();
        await expect(page.getByTestId('ai-capacity-card')).toHaveCount(1);

        expect(await sidewaysOverflow(page)).toEqual([]);
        expect(await smallTextInCard(page)).toEqual([]);
    }

    await page.getByTestId('subscription-card').getByRole('button', { name: 'Si opp abonnement' }).click();
    const dialog = page.getByRole('dialog', { name: 'Si opp abonnement' });
    await expect(dialog).toContainText('Du beholder tilgang til da.');
    expect(await smallTextInCard(page)).toEqual([]);
    await dialog.getByRole('button', { name: 'Avbryt' }).click();
    await expect(dialog).toHaveCount(0);

    await page.screenshot({ path: 'test-results/subscription-card-phone.png', fullPage: true });
});
