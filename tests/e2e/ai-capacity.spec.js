import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';
import { DESKTOP, PHONE, sidewaysOverflow } from './helpers/readability.js';
import { tinker } from './helpers/risk.js';

const suffix = Math.random().toString(36).slice(2, 8).padEnd(6, '0');

test.beforeAll(async () => {
    await tinker('\\Tests\\Support\\AiCapacityE2EFixture::cleanup();');
});

test.afterAll(async () => {
    await tinker(`\\Tests\\Support\\AiCapacityE2EFixture::cleanup('${suffix}');`);
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\AiCapacityE2EFixture::remaining('${suffix}'));`);

    expect(JSON.parse(stdout.match(/\{.*\}/)[0])).toEqual({ attempts: 0, periods: 0 });
});

const grouped = (value) => new Intl.NumberFormat('nb-NO').format(value).replace(/\s/g, ' ');
const plain = (text) => text.replace(/\s/g, ' ');

/**
 * A customer with an active billing period, included AI capacity and some usage opens Abonnement
 * and reads the shared AI capacity: headline, one progress bar, used / remaining / period — at
 * desktop and at 390 px without sideways scrolling. No provider call is made; usage is seeded.
 */
test('Abonnement shows the shared AI capacity in AI units', async ({ page }) => {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\AiCapacityE2EFixture::seed('${suffix}'));`);
    const capacity = JSON.parse(stdout.match(/\{.*\}/)[0]);

    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/billing');

    const card = page.getByTestId('ai-capacity-card');
    await expect(card.getByRole('heading', { name: 'AI-kapasitet' })).toBeVisible();
    await expect(card.getByTestId('ai-capacity-headline')).toHaveText(
        new RegExp(`${grouped(capacity.used)} av ${grouped(capacity.included)} AI-enheter brukt`.replace(/ /g, '\\s')),
    );
    expect(capacity.included).toBe(5000);
    expect(capacity.used).toBeGreaterThanOrEqual(3200);

    const bar = card.getByRole('progressbar');
    await expect(bar).toHaveAttribute('aria-valuenow', String(capacity.percentage_used));
    expect(plain(await card.getByTestId('ai-capacity-used').innerText())).toBe(grouped(capacity.used));
    expect(plain(await card.getByTestId('ai-capacity-remaining').innerText())).toBe(grouped(capacity.remaining));
    await expect(card.getByTestId('ai-capacity-period')).not.toBeEmpty();
    await expect(card.getByTestId('ai-capacity-status')).toHaveText(/God kapasitet|Nærmer seg grensen|Brukt opp/);

    // Customer view: units only — no tokens, models or money, and no AI cases as the AI picture.
    const text = await card.innerText();
    expect(text).not.toMatch(/token|gpt|NOK|kr\b|KI-tilbud|AI-saker/i);
    await expect(page.getByText('Inkluderte KI-tilbud')).toHaveCount(0);

    await page.screenshot({ path: 'test-results/ai-capacity-desktop.png', fullPage: true });

    await page.setViewportSize(PHONE);
    expect(await sidewaysOverflow(page), 'the page scrolls sideways at 390 px').toEqual([]);
    // The rest of Abonnement (InfoHint icons) predates this card; the card itself is held to 16 px.
    const smallText = await card.evaluate((root) => {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const found = [];

        while (walker.nextNode()) {
            const element = walker.currentNode.parentElement;

            if (walker.currentNode.textContent.trim() && parseFloat(getComputedStyle(element).fontSize) < 16) {
                found.push(walker.currentNode.textContent.trim());
            }
        }

        return found;
    });
    expect(smallText, 'card text below 16 px at 390 px').toEqual([]);

    // The bar uses the card's full width beside its percentage; the facts stack vertically.
    const cardBox = await card.boundingBox();
    const barBox = await bar.boundingBox();
    expect(barBox.width).toBeGreaterThan(cardBox.width * 0.6);
    const usedBox = await card.getByTestId('ai-capacity-used').boundingBox();
    const remainingBox = await card.getByTestId('ai-capacity-remaining').boundingBox();
    expect(remainingBox.y).toBeGreaterThan(usedBox.y);

    await page.screenshot({ path: 'test-results/ai-capacity-phone.png', fullPage: true });

    // The help explains the shared capacity without internals.
    await page.setViewportSize(DESKTOP);
    await page.getByRole('button', { name: 'Hjelp', exact: true }).click();
    const help = page.getByRole('dialog');
    await expect(help).toContainText('Alle AI-funksjoner bruker den samme kapasiteten.');
    await expect(help).not.toContainText(/token/i);
});
