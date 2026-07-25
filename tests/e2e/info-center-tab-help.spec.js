import { test, expect } from '@playwright/test';
import { loginAs, USER } from './helpers/auth.js';

test.beforeEach(async ({ page }) => {
    await loginAs(page, USER.email, USER.password);
});

const TABS = [
    { view: 'my_tasks', label: 'Mine oppgaver', help: 'Åpne aksjoner og oppfølginger som er tildelt deg.' },
    { view: 'awaiting_response', label: 'Venter på svar', help: 'Aksjoner du har sendt ut og fortsatt venter svar på fra andre.' },
    { view: 'outbound', label: 'Opprettet av meg', help: 'Aksjoner og oppfølginger du har opprettet, også tidligere og lukkede.' },
    { view: 'inbound', label: 'Innkommende', help: 'Informasjon og oppfølginger som har kommet inn til deg eller saken.' },
];

for (const { view, label, help } of TABS) {
    test(`"${label}" tab help tooltip meets the readable-text standard`, async ({ page }) => {
        const response = await page.goto(`/app/info-center?view=${view}`);
        expect(response?.status()).toBe(200);

        const helpButton = page.getByRole('button', { name: `Vis forklaring for ${label}` });
        await expect(helpButton).toBeVisible();

        // Opens on hover (the wrapping span's onMouseEnter), same as the existing behavior.
        await helpButton.hover();
        const tooltip = page.getByRole('tooltip');
        await expect(tooltip).toBeVisible();
        await expect(tooltip).toHaveText(help);

        const fontSize = await tooltip.evaluate((el) => window.getComputedStyle(el).fontSize);
        const lineHeight = await tooltip.evaluate((el) => window.getComputedStyle(el).lineHeight);
        const color = await tooltip.evaluate((el) => window.getComputedStyle(el).color);

        expect(parseFloat(fontSize)).toBeGreaterThanOrEqual(16);
        expect(parseFloat(lineHeight)).toBeGreaterThanOrEqual(24);
        // Tailwind's slate-700 (oklch(37.2% 0.044 257.287)) or darker/equally readable —
        // never the lighter slate-600.
        expect(color).toBe('oklch(0.372 0.044 257.287)');

        // Closes cleanly by moving the pointer away, without leaving stray state.
        await page.mouse.move(0, 0);
        await expect(page.getByRole('tooltip')).toHaveCount(0);
    });
}

test('tab help tooltip opens and closes via keyboard focus without errors', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') consoleErrors.push(msg.text());
    });

    await page.goto('/app/info-center?view=my_tasks');

    const helpButton = page.getByRole('button', { name: 'Vis forklaring for Mine oppgaver' });
    await helpButton.focus();
    await expect(helpButton).toBeFocused();
    await expect(page.getByRole('tooltip')).toBeVisible();
    await expect(helpButton).toHaveAttribute('aria-expanded', 'true');

    await page.keyboard.press('Tab');
    await expect(page.getByRole('tooltip')).toHaveCount(0);
    await expect(helpButton).toHaveAttribute('aria-expanded', 'false');

    expect(consoleErrors).toEqual([]);
});

test('tab help tooltip is usable on a mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/app/info-center?view=awaiting_response');

    const helpButton = page.getByRole('button', { name: 'Vis forklaring for Venter på svar' });
    await expect(helpButton).toBeVisible();
    await helpButton.hover();

    const tooltip = page.getByRole('tooltip');
    await expect(tooltip).toBeVisible();

    const box = await tooltip.boundingBox();
    const viewportWidth = page.viewportSize().width;
    expect(box.x + box.width).toBeLessThanOrEqual(viewportWidth + 1);
});
