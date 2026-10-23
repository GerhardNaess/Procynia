import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';
import { PHONE, sidewaysOverflow } from './helpers/readability.js';
import { expectFocusIndicator, expectNoAxeViolations } from './helpers/a11y.js';
import { tinker } from './helpers/risk.js';

/**
 * A wide table scrolls sideways inside its box, and its last column holds the row's actions. At a
 * common laptop width (1366 px, with the module rail open) the Brukere and Wiki-sider tables were
 * wider than the box, so «Rediger», «Deaktiver» and «Åpne» sat off-screen behind a scrollbar macOS
 * does not even draw. The action column is now pinned to the box's right edge: the actions are in
 * view at every width where the table is shown, and the other columns scroll under them.
 *
 * Nothing is changed by this spec: «Deaktiver» is clicked only to reach its confirmation, which is
 * dismissed.
 */
const LAPTOP = { width: 1366, height: 900 };

/*
 * The contributor test reads Wiki as the E2E user, who has no roles in the isolated environment and
 * so no wiki.view. The spec gives it the reading baseline for the run and takes it away again
 * (NavigationE2EFixture, as app-navigation and wiki-navigation do).
 */
const BASELINE_SUFFIX = Math.random().toString(36).slice(2, 8).padEnd(6, '0').toUpperCase();

test.beforeAll(async () => {
    await tinker(`\\Tests\\Support\\NavigationE2EFixture::baseline('${BASELINE_SUFFIX}');`);
});

test.afterAll(async () => {
    await tinker(`\\Tests\\Support\\NavigationE2EFixture::cleanup('${BASELINE_SUFFIX}');`);
});
const NARROW_DESKTOP = { width: 1024, height: 800 };

/**
 * The control is drawn inside the viewport and nothing lies on top of its centre — with the table's
 * box still scrolled fully left, so the action is in view without the user scrolling sideways. Only
 * the page is scrolled, vertically, to bring the row up.
 */
async function expectInViewAndUncovered(locator) {
    await locator.evaluate((element) => window.scrollBy(0, element.getBoundingClientRect().top - window.innerHeight / 2));
    const boxScrollLeft = await locator.evaluate((element) => {
        let node = element.parentElement;
        while (node && ! ['auto', 'scroll'].includes(getComputedStyle(node).overflowX)) node = node.parentElement;

        return node ? node.scrollLeft : 0;
    });
    expect(boxScrollLeft).toBe(0);
    await expect(locator).toBeInViewport({ ratio: 1 });
    const uncovered = await locator.evaluate((element) => {
        const box = element.getBoundingClientRect();
        const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);

        return hit !== null && (hit === element || element.contains(hit));
    });
    expect(uncovered).toBe(true);
}

/** Tab from the page start until `locator` has focus — it must be reachable by keyboard alone. */
async function tabTo(page, locator, limit = 200) {
    await page.locator('main').evaluate((main) => main.focus());
    for (let i = 0; i < limit; i += 1) {
        await page.keyboard.press('Tab');
        if (await locator.evaluate((element) => element === document.activeElement)) {
            return;
        }
    }
    throw new Error('The control was never reached with Tab.');
}

for (const viewport of [LAPTOP, NARROW_DESKTOP]) {
    test(`Brukere (${viewport.width} px): Rediger and Deaktiver are in view, keyboard-reachable and work`, async ({ page }) => {
        test.setTimeout(90_000);
        await page.setViewportSize(viewport);
        await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
        await page.goto('/app/users');

        const table = page.getByRole('table', { name: 'Brukere' });
        const edit = table.getByRole('link', { name: 'Rediger E2E User' });
        const deactivate = table.getByRole('button', { name: 'Deaktiver E2E User' });

        await expectInViewAndUncovered(edit);
        await expectInViewAndUncovered(deactivate);
        await expect(deactivate).toBeEnabled();

        // Deaktiver reaches its confirmation — dismissed, so nothing changes.
        let confirmation = null;
        page.once('dialog', (dialog) => { confirmation = dialog.message(); dialog.dismiss(); });
        await deactivate.click();
        await expect.poll(() => confirmation).toContain('deaktivere E2E User');
        await expect(deactivate).toHaveText('Deaktiver');

        // Rediger by keyboard: reachable with Tab, a visible focus ring, Enter opens the user.
        await tabTo(page, edit);
        await expectFocusIndicator(edit);
        await expectInViewAndUncovered(edit);
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/\/app\/users\/\d+\/edit/);
        await expect(page.locator('#user-email-readonly')).toHaveValue('e2e.user@procynia.test');
    });

    test(`Kundemiljø → Brukere (${viewport.width} px): Rediger is in view and opens the user`, async ({ page }) => {
        test.setTimeout(90_000);
        await page.setViewportSize(viewport);
        await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
        await page.goto('/app/customer-environment?tab=users');

        const table = page.getByRole('table', { name: 'Brukere' });
        const edit = table.getByRole('link', { name: 'Rediger E2E User' });

        await expectInViewAndUncovered(edit);
        await expectInViewAndUncovered(table.getByRole('button', { name: 'Deaktiver E2E User' }));
        await edit.click();
        await expect(page).toHaveURL(/\/app\/users\/\d+\/edit/);
    });

    test(`Wiki-sider (${viewport.width} px): Åpne is in view and opens the page`, async ({ page }) => {
        test.setTimeout(90_000);
        await page.setViewportSize(viewport);
        await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
        await page.goto('/app/wiki?tab=pages');

        const table = page.getByRole('table', { name: 'Wiki-sider' });
        const open = table.getByRole('link', { name: /^Åpne / }).first();
        const title = (await open.textContent()).replace(/^Åpne\s*/, '').trim();

        await expectInViewAndUncovered(open);
        await tabTo(page, open);
        await expectFocusIndicator(open);
        await page.keyboard.press('Enter');
        await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible();
    });
}

test('a contributor sees the same pinned Wiki action, and the Brukere actions follow the existing permission', async ({ page }) => {
    test.setTimeout(90_000);
    await page.setViewportSize(LAPTOP);
    await loginAs(page, USER.email, USER.password);

    await page.goto('/app/wiki?tab=pages');
    await expectInViewAndUncovered(page.getByRole('table', { name: 'Wiki-sider' }).getByRole('link', { name: /^Åpne / }).first());

    // The contributor holds create_users by default and sees the users they manage — the System
    // Owner is not one of them, and their own row cannot be deactivated.
    await page.goto('/app/users');
    const table = page.getByRole('table', { name: 'Brukere' });
    await expectInViewAndUncovered(table.getByRole('link', { name: 'Rediger E2E User' }));
    await expect(table.getByRole('link', { name: 'Rediger E2E System Owner' })).toHaveCount(0);
    await expect(table.getByRole('button', { name: 'Egen konto' })).toBeDisabled();
});

test('the pinned columns keep the tables free of axe violations, and phones keep their card lists', async ({ page }) => {
    test.setTimeout(90_000);
    await page.setViewportSize(LAPTOP);
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);

    await page.goto('/app/users');
    await expectNoAxeViolations(page, 'main table');
    await page.goto('/app/customer-environment?tab=users');
    await expectNoAxeViolations(page, 'main table');
    await page.goto('/app/wiki?tab=pages');
    await expectNoAxeViolations(page, 'main table');

    await page.setViewportSize(PHONE);
    await page.goto('/app/users');
    await expect(page.getByRole('table', { name: 'Brukere' })).toBeHidden();
    await expect(page.getByRole('link', { name: 'Rediger E2E User' })).toBeVisible();
    expect(await sidewaysOverflow(page)).toEqual([]);
    await page.goto('/app/wiki?tab=pages');
    await expect(page.getByRole('link', { name: /^Åpne / }).first()).toBeVisible();
});
