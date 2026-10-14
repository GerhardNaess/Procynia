import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { expectPageHelp, sidewaysOverflow, textBelow16px } from './helpers/readability.js';
import { cleanUpRiskE2eData, riskE2eSuffix, tinker } from './helpers/risk.js';

const suffix = riskE2eSuffix();
const password = `E2e-${suffix}-Lesbar1`;
const FIXTURE = '\\Tests\\Support\\RiskAttentionE2EFixture';
cleanUpRiskE2eData(suffix);

// A phone, a tablet, a laptop, and the laptop at 200 % zoom (half the CSS width).
const WIDTHS = [['phone', 390], ['tablet', 768], ['laptop', 1280], ['laptop-zoom-200', 640]];

/** Visible text under 16 px in the shared header and module menu — the parts textBelow16px leaves out. */
async function shellTextBelow16px(page) {
    return page.evaluate(() => {
        const found = [];
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);

        while (walker.nextNode()) {
            const text = walker.currentNode.textContent.trim();
            const element = walker.currentNode.parentElement;

            if (! text || ! element || element.closest('main, [role="dialog"], [aria-hidden="true"], .sr-only, option')) {
                continue;
            }

            const rect = element.getBoundingClientRect();

            if (rect.width > 0 && rect.height > 0 && parseFloat(getComputedStyle(element).fontSize) < 16) {
                found.push(`${getComputedStyle(element).fontSize} «${text.slice(0, 40)}»`);
            }
        }

        return found;
    });
}

/** No text under 16 px anywhere and no sideways scrolling, at every width; screenshots per width. */
async function expectReadable(page, name) {
    for (const [label, width] of WIDTHS) {
        await page.setViewportSize({ width, height: 900 });
        expect(await textBelow16px(page), `${name} (${label})`).toEqual([]);
        expect(await shellTextBelow16px(page), `${name} (${label}) header and menu`).toEqual([]);
        // Polled: after the previous width's full-page screenshot the browser takes a moment to lay
        // the page out again; a real overflow stays and still fails.
        await expect.poll(() => sidewaysOverflow(page), { message: `${name} (${label}) scrolls sideways` }).toEqual([]);
        await page.screenshot({ path: `test-results/risk-readability-${name}-${label}.png`, fullPage: true });
    }

    await page.setViewportSize({ width: 1280, height: 900 });
}

/**
 * Risiko as a user reads it, after the move to 16 px: the register with Trenger oppmerksomhet and
 * its help, Ny risiko with and without its error messages, and a risk with every panel's form
 * open — at phone, tablet, laptop and laptop-at-200-% width, the shared menu included. The
 * behaviour itself is owned by the other Risk specs; this one only reads.
 */
test('every page, panel and form in Risiko is readable at 16 px', async ({ page }) => {
    test.setTimeout(240_000);

    const { stdout } = await tinker(`echo ${FIXTURE}::seed('${suffix}', '${password}');`);
    const email = stdout.trim().split('\n').pop();
    // The fixture's user only reads risks; give the same role everything the forms need.
    await tinker(`\\App\\Models\\CustomerRole::where('name', 'E2E Oppmerksomhet ${suffix}')->firstOrFail()->syncPermissions(['risk.view', 'risk.create', 'risk.edit', 'risk.assess', 'risk.accept', 'quality.view']);`);

    await loginAs(page, email, password);
    await page.setViewportSize({ width: 1280, height: 900 });

    // The register, Trenger oppmerksomhet with all four categories, and the help.
    await page.goto('/app/risk');
    await expect(page.locator('[data-testid^="risk-attention-category-"]')).toHaveCount(4);
    await expectReadable(page, '01-register');
    await expectPageHelp(page, 'Om risikoregisteret', ['Registrere en risiko', 'Vurdering og oppfølging', 'Trenger oppmerksomhet']);

    // Ny risiko, then the same form with every error message showing.
    await page.getByRole('button', { name: 'Ny risiko' }).click();
    await expect(page.locator('#risk-title')).toBeVisible();
    await expectReadable(page, '02-new-risk');
    for (const field of ['title', 'cause', 'event', 'consequence', 'area']) {
        await page.locator(`#risk-${field}`).evaluate((element) => element.removeAttribute('required'));
    }
    await page.getByRole('button', { name: 'Lagre', exact: true }).click();
    await expect(page.getByText('Årsak må fylles ut.')).toBeVisible();
    await expectReadable(page, '03-new-risk-errors');

    // Keyboard: Tab reaches the first field and the actions, and focus is never on something hidden.
    await page.locator('#risk-title').focus();
    await page.keyboard.press('Tab');
    await expect(page.locator('#risk-cause')).toBeFocused();

    // A risk with a high restrisiko, every panel with a form opened in turn.
    await page.goto('/app/risk');
    await page.getByRole('link', { name: `E2E Høy restrisiko ${suffix}` }).first().click();
    await page.waitForURL(/\/app\/risk\/risks\/\d+$/);
    await expectReadable(page, '04-risk');

    await page.getByRole('button', { name: 'Ny vurdering' }).click();
    await page.getByRole('button', { name: 'Vurder også restrisiko' }).click();
    await expectReadable(page, '05-assessment-form');
    await page.getByRole('button', { name: 'Avbryt' }).first().click();

    const actions = page.locator('section', { has: page.getByRole('heading', { name: 'Tiltak', exact: true }) });
    await actions.getByRole('button', { name: 'Nytt tiltak' }).click();
    await expectReadable(page, '06-action-form');
    await actions.getByRole('button', { name: 'Avbryt' }).click();

    const decision = page.locator('section', { has: page.getByRole('heading', { name: 'Aksept av restrisiko', exact: true }) });
    await decision.getByRole('button', { name: 'Aksepter restrisiko' }).click();
    await expectReadable(page, '07-acceptance-form');
    await decision.getByRole('button', { name: 'Avbryt' }).click();

    const details = page.locator('section', { has: page.getByRole('heading', { name: 'Detaljer', exact: true }) });
    await details.getByRole('button', { name: 'Endre vurderingsintervall' }).click();
    await expectReadable(page, '08-review-interval');
});
