import { expect, test } from '@playwright/test';
import { SUPER_ADMIN, loginAsAdmin } from './helpers/auth.js';
import { DESKTOP, PHONE, sidewaysOverflow } from './helpers/readability.js';
import { tinker } from './helpers/risk.js';

const suffix = Math.random().toString(36).slice(2, 8).padEnd(6, '0');
const json = (stdout) => JSON.parse(stdout.match(/\{.*\}/)[0]);

test.beforeAll(async () => {
    await tinker('\\Tests\\Support\\AiExperienceE2EFixture::cleanup();');
});

test.afterAll(async () => {
    await tinker(`\\Tests\\Support\\AiExperienceE2EFixture::cleanup('${suffix}');`);
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\AiExperienceE2EFixture::remaining('${suffix}'));`);

    expect(json(stdout)).toEqual({ customers: 0, attempts: 0, snapshots: 0 });
});

/** Visible text inside the page's own content that is set below 16 px. */
const smallText = (page) => page.getByTestId('experience-page').evaluate((root) => {
    const found = [];
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);

    while (walker.nextNode()) {
        const element = walker.currentNode.parentElement;
        const text = walker.currentNode.textContent.trim();

        if (text && element && ! element.closest('option') && element.getBoundingClientRect().width > 0 && parseFloat(getComputedStyle(element).fontSize) < 16) {
            found.push(text.slice(0, 40));
        }
    }

    return found;
});

/**
 * A Procynia admin opens AI-erfaring, reads the overview and the current model against observed
 * data, filters on one customer and opens its billing period: users, modules, level, capacity,
 * cost and the split per feature and operation. Usage is seeded into the ledger; no provider call.
 * Checked at desktop and at 390 px without sideways scrolling.
 */
test('an admin reads AI experience and opens one customer period', async ({ page }) => {
    const seeded = json((await tinker(`echo json_encode(\\Tests\\Support\\AiExperienceE2EFixture::seed('${suffix}'));`)).stdout);

    await loginAsAdmin(page, SUPER_ADMIN.email, SUPER_ADMIN.password);
    await page.setViewportSize(DESKTOP);
    await page.goto('/admin/ai-erfaring');

    await expect(page.getByTestId('experience-read-only')).toBeVisible();
    await expect(page.getByTestId('experience-overview')).toBeVisible();
    await expect(page.getByTestId('experience-basis')).toBeVisible();
    await expect(page.getByTestId('experience-formula')).toBeVisible();
    await expect(page.getByTestId('experience-factor-tender')).toBeVisible();
    await expect(page.getByTestId('experience-factor-level_2')).toContainText('×1,50');
    await expect(page.getByTestId('experience-customers')).toContainText(seeded.beta.name);

    // Only the customer chosen.
    await page.getByTestId('experience-filter-customer').selectOption(String(seeded.beta.id));
    const rows = page.getByTestId('experience-row');
    await expect(rows.first()).toContainText(seeded.beta.name);
    await expect(page.getByTestId('experience-customers')).not.toContainText(seeded.alfa.name);

    // The period with usage sorts first (highest cost). Open it.
    await rows.first().getByRole('button', { name: seeded.beta.name }).click();
    const detail = page.getByTestId('experience-detail');
    await expect(detail).toBeVisible();

    const setup = detail.getByTestId('experience-detail-setup');
    await expect(setup).toContainText('4');
    await expect(setup).toContainText(/Basis, (Anbud|Tender)/);
    await expect(setup).toContainText('×1,50');
    await expect(setup).toContainText(String(seeded.beta_period).replace(/\B(?=(\d{3})+(?!\d))/g, ' '));
    await expect(detail.getByTestId('experience-detail-usage')).toContainText('50,00 NOK');
    await expect(detail.getByTestId('experience-detail-features')).toContainText('75,0 %');
    await expect(detail.getByTestId('experience-detail-operations')).toContainText('tender.requirement_answer');
    await expect(detail.getByTestId('experience-detail-capacity')).toBeVisible();

    expect(await smallText(page)).toEqual([]);
    expect(await sidewaysOverflow(page)).toEqual([]);
    await page.screenshot({ path: 'test-results/ai-experience-detail-desktop.png', fullPage: true });

    await page.setViewportSize(PHONE);
    await expect(detail).toBeVisible();
    expect(await smallText(page)).toEqual([]);
    expect(await sidewaysOverflow(page)).toEqual([]);
    await page.screenshot({ path: 'test-results/ai-experience-detail-phone.png', fullPage: true });
    await page.setViewportSize(DESKTOP);

    await detail.getByTestId('experience-detail-back').click();
    await expect(page.getByTestId('experience-detail')).toHaveCount(0);
});
