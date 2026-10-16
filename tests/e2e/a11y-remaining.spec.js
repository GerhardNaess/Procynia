import { expect, test } from '@playwright/test';
import { expectFocusIndicator, expectNoAxeViolations } from './helpers/a11y.js';
import { loginAs } from './helpers/auth.js';
import { DESKTOP, sidewaysOverflow } from './helpers/readability.js';
import {
    MANAGEMENT_REVIEW_E2E_PASSWORD,
    cleanUpManagementReviewE2eData,
    managementReviewE2eSuffix,
    managementReviewFixture,
} from './helpers/managementReview.js';

const suffix = managementReviewE2eSuffix();
cleanUpManagementReviewE2eData(suffix);

/**
 * WCAG Fase 6 — the surfaces outside the modules, in a real browser (docs/wcag-accessibility-plan.md):
 * the public registration form, the FAQ disclosures, the public pages' structure, and the shared
 * SearchableMultiSelect (seen through Ledelsens gjennomgang's create form, in that spec's own fixture
 * customer). Source rules are pinned in resources/js/Support/a11yPhase6Remaining.test.js.
 */

const PHONE_320 = { width: 320, height: 640 };

test('registration: a rejected submit lands on the first wrong field, tied to its error; nothing is created', async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await page.goto('/registrer');

    await expect(page.getByText('Felt merket med * må fylles ut.')).toBeVisible();
    await expect(page.locator('#company_name')).toHaveAttribute('aria-required', 'true');
    await expect(page.locator('#privacy_accepted')).toHaveAttribute('aria-required', 'true');
    await expect(page.getByRole('group', { name: /./ }).filter({ has: page.locator('#privacy_accepted') })).toHaveCount(1);
    await expectNoAxeViolations(page, 'main', 'registrer');

    // Everything the browser checks is filled, so the server answers: a private e-mail domain and a
    // short password are refused (PublicRegistrationRequest). No account can come of this.
    await page.locator('#company_name').fill(`E2E A11y ${suffix}`);
    await page.locator('#nationality_id').selectOption({ index: 1 });
    await page.locator('#language_id').selectOption({ index: 1 });
    await page.locator('#owner_name').fill('Test Testesen');
    await page.locator('#owner_email').fill(`a11y.${suffix.toLowerCase()}@gmail.com`);
    await page.locator('#password').fill('kort');
    await page.locator('#privacy_accepted').check();
    await page.locator('#terms_accepted').check();
    await page.locator('#represents_business').check();
    await page.getByRole('button', { name: /Opprett|Create/ }).click();

    const email = page.locator('#owner_email');
    await expect(email).toBeFocused();
    await expect(email).toHaveAttribute('aria-invalid', 'true');
    await expect(email).toHaveAttribute('aria-describedby', /owner_email-error/);
    await expect(page.locator('#owner_email-error')).toBeVisible();
    await expect(page.locator('#password')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#company_name')).not.toHaveAttribute('aria-invalid', 'true');
    await expect(page).toHaveURL(/\/registrer$/);
    await expectNoAxeViolations(page, 'main', 'registrer with errors');

    await page.setViewportSize(PHONE_320);
    expect(await sidewaysOverflow(page)).toEqual([]);
});

test('FAQ: each question is a heading with a disclosure button that controls its answer', async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await page.goto('/faq');

    const question = page.getByRole('heading', { level: 3, name: 'Hva er Procynia?' }).getByRole('button');
    await expect(question).toHaveAttribute('aria-expanded', 'false');
    const answerId = await question.getAttribute('aria-controls');
    const answer = page.locator(`[id="${answerId}"]`);
    await expect(answer).toBeHidden();

    await question.focus();
    await page.keyboard.press('Enter');
    await expect(question).toHaveAttribute('aria-expanded', 'true');
    await expect(answer).toBeVisible();
    await expect(answer).toContainText('Procynia er en norsk plattform');
    await expectFocusIndicator(question);
    await expectNoAxeViolations(page, 'main', 'faq open');

    await page.keyboard.press('Space');
    await expect(question).toHaveAttribute('aria-expanded', 'false');
    await expect(answer).toBeHidden();
});

test('public pages: no skipped heading levels, named tables and tables of contents, nothing sideways at 320 px', async ({ page }) => {
    test.setTimeout(90_000);
    await page.setViewportSize(DESKTOP);

    // Security: the cards follow the h1 as h2 (A11Y-119).
    await page.goto('/sikkerhet');
    await expect(page.locator('main h3')).toHaveCount(0);
    await expect(page.getByRole('heading', { level: 2, name: /Kryptering|Tilgangskontroll|Organisatoriske tiltak/ }).first()).toBeVisible();

    const levels = async () => page.locator('main').evaluate((main) => [...main.querySelectorAll('h1, h2, h3, h4, h5, h6')].map((h) => Number(h.tagName[1])));
    for (const url of ['/', '/sikkerhet', '/faq', '/personvern', '/betingelser', '/registrer']) {
        await page.goto(url);
        const found = await levels();
        expect(found[0], `${url}: the first heading is the h1`).toBe(1);
        for (let index = 1; index < found.length; index += 1) {
            expect(found[index] - found[index - 1], `${url}: no jump after h${found[index - 1]}`).toBeLessThanOrEqual(1);
        }
    }

    // Privacy: both tables are named by the sentence before them, with column and row headers.
    await page.goto('/personvern');
    await expect(page.getByRole('table', { name: /kategorier av personopplysninger/ })).toBeVisible();
    await expect(page.getByRole('table', { name: /rettslige grunnlag/ })).toBeVisible();
    await expect(page.getByRole('rowheader', { name: 'Identifikasjonsdata' })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Innhold' })).toBeVisible();
    await expectNoAxeViolations(page, 'main', 'personvern');

    await page.goto('/betingelser');
    await expect(page.getByRole('navigation', { name: 'Innhold' })).toBeVisible();
    await expectNoAxeViolations(page, 'main', 'betingelser');

    await page.setViewportSize(PHONE_320);
    for (const url of ['/', '/sikkerhet', '/faq', '/personvern', '/betingelser', '/registrer']) {
        await page.goto(url);
        expect(await sidewaysOverflow(page), url).toEqual([]);
    }
});

test('SearchableMultiSelect: the hit count is spoken, the active option is outlined, removing a chip keeps focus', async ({ page }) => {
    test.setTimeout(120_000);

    const people = await managementReviewFixture(`seedJourney('${suffix}', '${MANAGEMENT_REVIEW_E2E_PASSWORD}')`);
    await loginAs(page, people.email, MANAGEMENT_REVIEW_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/management-reviews');
    await page.getByTestId('mr-create').click();

    const field = page.locator('#mr-frameworks');
    const polite = page.locator('#procynia-live-polite');

    // Opening the list says nothing; a search says how many are left, once.
    await field.click();
    await expect(page.getByTestId('mr-frameworks-list')).toBeVisible();
    await field.fill('cyber');
    await expect(page.getByTestId('mr-frameworks-list').getByRole('option')).toHaveCount(1);
    await expect(polite).toHaveText('1 treff');
    await field.fill('zzzz-ingen');
    await expect(polite).toHaveText('Ingen treff');

    // The option under the arrow keys is outlined in violet-600, and named by aria-activedescendant.
    await field.fill('');
    await field.press('ArrowDown');
    const activeId = await field.getAttribute('aria-activedescendant');
    expect(activeId).toBeTruthy();
    const outline = await page.locator(`[id="${activeId}"]`).evaluate((element) => {
        const style = getComputedStyle(element);
        return { style: style.outlineStyle, width: style.outlineWidth };
    });
    expect(outline).toEqual({ style: 'solid', width: '2px' });

    // Choose two, then remove the first by keyboard: focus moves to the chip now in its place.
    await field.fill('kvalitet');
    await page.getByTestId('mr-frameworks-option-iso9001').click();
    await field.fill('cyber');
    await field.press('Enter');
    await field.press('Escape');
    const chips = page.locator('[data-testid^="mr-frameworks-chip-"]');
    await expect(chips).toHaveCount(2);
    const secondChipTestId = await chips.nth(1).getAttribute('data-testid');

    await chips.first().focus();
    await page.keyboard.press('Enter');
    await expect(chips).toHaveCount(1);
    await expect(page.getByTestId(secondChipTestId)).toBeFocused();
    await expectFocusIndicator(page.getByTestId(secondChipTestId));

    // The last one: focus goes to the search field, and the list does not pop open.
    await page.keyboard.press('Enter');
    await expect(chips).toHaveCount(0);
    await expect(field).toBeFocused();
    await expect(page.getByTestId('mr-frameworks-list')).toHaveCount(0);
});
