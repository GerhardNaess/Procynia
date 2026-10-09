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
const unitsPattern = (value) => new RegExp(grouped(value).replace(/ /g, '\\s'));
const current = async () => {
    const { stdout } = await tinker('echo json_encode(\\Tests\\Support\\AiCapacityE2EFixture::capacity());');

    return JSON.parse(stdout.match(/\{.*\}/)[0]);
};

/**
 * A customer with Basis, several options, several users and Level 1 opens Abonnement and reads
 * three separate blocks: Basis, the options, and the AI capacity — one shared pool. It opens «Endre
 * nivå», sees Nivå 1/2/3 with what each would include for it, chooses Nivå 2, and the card follows.
 * Cancelling an option then shrinks the pool while Nivå 2 stays. Checked at desktop and at 390 px
 * without sideways scrolling. No provider call is made; usage is seeded.
 */
test('the customer chooses its AI capacity level and the pool follows users and options', async ({ page }) => {
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

    // Level 1 over the customer's base (users + Basis + options).
    expect(capacity.source).toBe('tier');
    expect(capacity.tier_key).toBe('level_1');
    expect(capacity.packages).toContain('basis');
    expect(capacity.packages.filter((key) => key !== 'basis').length).toBeGreaterThanOrEqual(2);
    expect(capacity.included).toBe(capacity.level_units.level_1);
    // Tinker resolves the name in the app locale; the page shows the user's Norwegian label.
    await expect(card.getByTestId('ai-capacity-tier')).toHaveText('Nivå 1');
    await expect(card.getByTestId('ai-capacity-headline')).toHaveText(
        new RegExp(`${grouped(capacity.used)} av ${grouped(capacity.included)} AI-enheter brukt`.replace(/ /g, '\\s')),
    );
    expect(capacity.is_provisional).toBe(true);
    await expect(card.getByTestId('ai-capacity-provisional')).toHaveText('AI-kapasiteten er under innfasing, og nivåene kan bli justert.');
    expect(capacity.used).toBeGreaterThanOrEqual(1200);

    // Endre nivå: three levels, each with what it would include for this customer — no formula.
    await card.getByTestId('ai-capacity-change-level').click();
    const levels = page.getByRole('dialog', { name: 'Velg nivå for AI-kapasitet' });
    await expect(levels).toBeVisible();
    for (const [key, name] of [['level_1', 'Nivå 1'], ['level_2', 'Nivå 2'], ['level_3', 'Nivå 3']]) {
        const row = levels.getByTestId(`ai-capacity-level-${key}`);
        await expect(row).toContainText(name);
        await expect(row.getByTestId(`ai-capacity-level-${key}-units`)).toHaveText(unitsPattern(capacity.level_units[key]));
    }
    expect(capacity.level_units.level_2).toBeGreaterThan(capacity.level_units.level_1);
    expect(capacity.level_units.level_3).toBeGreaterThan(capacity.level_units.level_2);
    await expect(levels.getByTestId('ai-capacity-level-level_1')).toContainText('Gjeldende nivå');
    const dialogText = await levels.innerText();
    expect(dialogText).not.toMatch(/multipli|vekt|×|%|token|NOK|kr\b|pris|1 000 AI|2 500 AI|5 000 AI/i);

    await levels.getByRole('button', { name: 'Velg Nivå 2' }).click();
    await expect(levels).toBeHidden();
    await expect(page.getByText('Nivå 2 er valgt. AI-kapasiteten er oppdatert.').first()).toBeVisible();
    await expect(card.getByTestId('ai-capacity-tier')).toHaveText('Nivå 2');
    await expect(card.getByTestId('ai-capacity-included')).toHaveText(unitsPattern(capacity.level_units.level_2));
    await expect(card.getByTestId('ai-capacity-used')).toHaveText(unitsPattern(capacity.used));
    const chosen = await current();
    expect(chosen.stored_tier).toBe('level_2');
    expect(chosen.included).toBe(capacity.level_units.level_2);
    expect(chosen.tier_events).toBe(1);

    const bar = card.getByRole('progressbar');
    await expect(bar).toHaveAttribute('aria-valuenow', String(chosen.percentage_used));
    expect(plain(await card.getByTestId('ai-capacity-used').innerText())).toBe(grouped(chosen.used));
    expect(plain(await card.getByTestId('ai-capacity-remaining').innerText())).toBe(grouped(chosen.remaining));
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

    // Cancelling an option through the page shrinks the pool; the chosen level stays Nivå 2.
    await page.setViewportSize(DESKTOP);
    const cancelled = capacity.packages.find((key) => key !== 'basis' && key !== 'tender') ?? capacity.packages.find((key) => key !== 'basis');
    await tinker(`\\Tests\\Support\\AiCapacityE2EFixture::willCancel('${cancelled}');`);
    await page.getByTestId(`package-row-${cancelled}`).getByRole('button', { name: 'Avbestill' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Avbestill' }).click();
    await expect(page.getByTestId(`package-status-${cancelled}`)).not.toHaveText('Aktiv');
    const afterCancel = await current();
    expect(afterCancel.packages).not.toContain(cancelled);
    expect(afterCancel.stored_tier).toBe('level_2');
    expect(afterCancel.included).toBeLessThan(chosen.included);
    expect(afterCancel.included).toBe(afterCancel.level_units.level_2);
    await expect(card.getByTestId('ai-capacity-tier')).toHaveText('Nivå 2');
    await expect(card.getByTestId('ai-capacity-included')).toHaveText(unitsPattern(afterCancel.included));
    await expect(card.getByTestId('ai-capacity-used')).toHaveText(unitsPattern(capacity.used));

    await page.setViewportSize(PHONE);
    expect(await sidewaysOverflow(page), 'the page scrolls sideways at 390 px after the changes').toEqual([]);
    await card.getByTestId('ai-capacity-change-level').click();
    expect(await sidewaysOverflow(page), 'the level dialog scrolls sideways at 390 px').toEqual([]);
    for (const button of await page.getByRole('dialog').getByRole('button').all()) {
        const box = await button.boundingBox();
        expect(box.x + box.width).toBeLessThanOrEqual(390);
    }
    await page.screenshot({ path: 'test-results/ai-capacity-levels-phone.png', fullPage: true });
    await page.getByRole('dialog').getByRole('button', { name: 'Avbryt' }).click();

    // The help explains the separate, shared capacity without internals.
    await page.setViewportSize(DESKTOP);
    await page.getByRole('button', { name: 'Hjelp', exact: true }).click();
    const help = page.getByRole('dialog');
    await expect(help).toContainText('Abonnementet består av Basis, valgfrie opsjoner og separat AI-kapasitet.');
    await expect(help).toContainText('AI-kapasiteten deles av alle funksjoner som bruker AI.');
    await expect(help).toContainText('AI-kapasiteten beregnes ut fra virksomhetens brukere og aktive moduler. Valgt nivå bestemmer hvor mye ekstra kapasitet virksomheten har. Alle AI-funksjoner bruker den samme kapasiteten.');
    await expect(help).not.toContainText(/token|multipli|vekt/i);
});
