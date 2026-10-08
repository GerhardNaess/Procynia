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
 * A customer with Basis, several options and its own AI capacity tier opens Abonnement and reads
 * three separate blocks: Basis, the options, and the AI capacity — one shared pool, sized by the
 * tier alone. Cancelling an option leaves the pool untouched. Checked at desktop and at 390 px
 * without sideways scrolling. No provider call is made; usage is seeded.
 */
test('Basis, options and a separate AI capacity tier share one pool', async ({ page }) => {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\AiCapacityE2EFixture::seed('${suffix}'));`);
    const capacity = JSON.parse(stdout.match(/\{.*\}/)[0]);

    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/billing');

    // Three separate blocks, in order: Basis → Opsjoner → AI-kapasitet.
    const basis = page.getByTestId('subscription-card');
    const options = page.getByTestId('module-packages');
    const card = page.getByTestId('ai-capacity-card');
    await expect(basis.getByRole('heading', { name: 'Basis', exact: true })).toBeVisible();
    await expect(basis).toContainText('Inneholder Wiki, Kvalitet og Avvik og forbedringer.');
    await expect(options.getByRole('heading', { name: 'Opsjoner', level: 2 })).toBeVisible();
    // Basis is said once: not again as a row among the options, and only one status badge for it.
    await expect(options.getByText('Basis', { exact: true })).toHaveCount(0);
    await expect(page.getByTestId('package-status-basis')).toHaveText('Aktiv');
    await expect(card.getByRole('heading', { name: 'AI-kapasitet' })).toBeVisible();
    await expect(page.getByTestId('ai-capacity-card')).toHaveCount(1);
    const [basisBox, optionsBox, cardBox0] = [await basis.boundingBox(), await options.boundingBox(), await card.boundingBox()];
    expect(basisBox.y).toBeLessThan(optionsBox.y);
    expect(optionsBox.y + optionsBox.height).toBeLessThanOrEqual(cardBox0.y);
    const invoicesBox = await page.getByRole('heading', { name: 'Fakturaer og betalinger' }).boundingBox();
    expect(cardBox0.y + cardBox0.height).toBeLessThanOrEqual(invoicesBox.y);

    // No old plan names, no summary cards repeating Basis, no «Tilleggstjenester».
    const main = await page.locator('main').innerText();
    expect(main).not.toMatch(/\b(Pro|Max|Ultra|Enterprise)\b/);
    expect(main).not.toMatch(/Tilleggstjenester|Moduler og pakker|Basis · (Månedlig|Årlig)/i);
    expect(main.match(/Fakturering/g) ?? []).toHaveLength(1);
    await expect(page.getByText('Oversikt over Basis, opsjoner, AI-kapasitet og fakturaer.')).toBeVisible();
    await expect(basis).not.toContainText(/AI-enheter/);
    await expect(options).not.toContainText(/AI-enheter/);

    // The capacity comes from the tier, not from Basis or the options.
    expect(capacity.source).toBe('tier');
    expect(capacity.packages).toContain('basis');
    expect(capacity.packages.filter((key) => key !== 'basis').length).toBeGreaterThanOrEqual(2);
    // Tinker resolves the name in the app locale; the page shows the user's Norwegian label.
    expect(capacity.tier_name).toBeTruthy();
    await expect(card.getByTestId('ai-capacity-tier')).toHaveText('Nivå 2');
    await expect(card.getByTestId('ai-capacity-headline')).toHaveText(
        new RegExp(`${grouped(capacity.used)} av ${grouped(capacity.included)} AI-enheter brukt`.replace(/ /g, '\\s')),
    );
    expect(capacity.is_provisional).toBe(true);
    await expect(card.getByTestId('ai-capacity-provisional')).toContainText('under innfasing');
    expect(capacity.used).toBeGreaterThanOrEqual(1200);

    const bar = card.getByRole('progressbar');
    await expect(bar).toHaveAttribute('aria-valuenow', String(capacity.percentage_used));
    expect(plain(await card.getByTestId('ai-capacity-used').innerText())).toBe(grouped(capacity.used));
    expect(plain(await card.getByTestId('ai-capacity-remaining').innerText())).toBe(grouped(capacity.remaining));
    await expect(card.getByTestId('ai-capacity-period')).not.toBeEmpty();
    await expect(card.getByTestId('ai-capacity-status')).toHaveText(/God kapasitet|Nærmer seg grensen|Brukt opp/);

    // Customer view: units only — no tokens, models or money, and no AI cases as the AI picture.
    const text = await card.innerText();
    expect(text).not.toMatch(/token|gpt|NOK|kr\b|kr\/mnd|pris|KI-tilbud|AI-saker|Basis inkluderer/i);
    await expect(page.getByText('Inkluderte KI-tilbud')).toHaveCount(0);

    await page.screenshot({ path: 'test-results/ai-capacity-desktop.png', fullPage: true });

    await page.setViewportSize(PHONE);
    expect(await sidewaysOverflow(page), 'the page scrolls sideways at 390 px').toEqual([]);
    // The whole page content — Basis, Opsjoner, AI-kapasitet, fakturaer — is held to 16 px.
    const smallText = await page.locator('main').evaluate((root) => {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const found = [];

        while (walker.nextNode()) {
            const element = walker.currentNode.parentElement;

            if (walker.currentNode.textContent.trim() && !element.closest('[aria-hidden="true"], .sr-only')
                && parseFloat(getComputedStyle(element).fontSize) < 16) {
                found.push(walker.currentNode.textContent.trim());
            }
        }

        return found;
    });
    expect(smallText, 'page text below 16 px at 390 px').toEqual([]);

    // Option buttons wrap inside their rows rather than pushing the page wider.
    for (const button of await page.getByTestId('module-packages').getByRole('button').all()) {
        const box = await button.boundingBox();
        expect(box.x + box.width).toBeLessThanOrEqual(390);
    }

    // The bar uses the card's full width beside its percentage; the facts stack vertically.
    const cardBox = await card.boundingBox();
    const barBox = await bar.boundingBox();
    expect(barBox.width).toBeGreaterThan(cardBox.width * 0.6);
    const usedBox = await card.getByTestId('ai-capacity-used').boundingBox();
    const remainingBox = await card.getByTestId('ai-capacity-remaining').boundingBox();
    expect(remainingBox.y).toBeGreaterThan(usedBox.y);

    await page.screenshot({ path: 'test-results/ai-capacity-phone.png', fullPage: true });

    // Cancelling an option changes the options block, never the pool.
    const cancelled = capacity.packages.find((key) => key !== 'basis');
    const { stdout: after } = await tinker(`echo json_encode(\\Tests\\Support\\AiCapacityE2EFixture::cancelOption('${cancelled}'));`);
    const afterCancel = JSON.parse(after.match(/\{.*\}/)[0]);
    expect(afterCancel.packages).not.toContain(cancelled);
    expect(afterCancel.included).toBe(capacity.included);
    await page.reload();
    await expect(page.getByTestId('ai-capacity-included')).toHaveText(new RegExp(grouped(capacity.included).replace(/ /g, '\\s')));
    await expect(page.getByTestId('ai-capacity-used')).toHaveText(new RegExp(grouped(capacity.used).replace(/ /g, '\\s')));

    // The help explains the separate, shared capacity without internals.
    await page.setViewportSize(DESKTOP);
    await page.getByRole('button', { name: 'Hjelp', exact: true }).click();
    const help = page.getByRole('dialog');
    await expect(help).toContainText('Abonnementet består av Basis, valgfrie opsjoner og separat AI-kapasitet.');
    await expect(help).toContainText('AI-kapasiteten deles av alle funksjoner som bruker AI.');
    await expect(help).not.toContainText(/token/i);
});
