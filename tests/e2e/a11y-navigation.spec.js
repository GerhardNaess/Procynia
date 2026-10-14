import { expect, test } from '@playwright/test';
import { expectFocusIndicator, expectNoAxeViolations } from './helpers/a11y.js';
import { loginAs, SYSTEM_OWNER } from './helpers/auth.js';
import { COMPLIANCE_E2E_PASSWORD, cleanUpComplianceE2eData, complianceE2eSuffix, complianceFixture } from './helpers/compliance.js';
import { DESKTOP, sidewaysOverflow } from './helpers/readability.js';

const complianceSuffix = complianceE2eSuffix();
cleanUpComplianceE2eData(complianceSuffix);

/**
 * WCAG Fase 2 — layout, navigation, page changes and flash messages, in a real browser
 * (docs/wcag-accessibility-plan.md §16). Fase 2's acceptance pages are checked with axe whole, not
 * scoped: the layouts and standard views are what this phase made accessible.
 */

const PUBLIC_PAGES = ['/', '/funksjoner', '/priser', '/sikkerhet', '/kontakt', '/faq', '/betingelser', '/personvern', '/registrer'];
const PHONE_320 = { width: 320, height: 640 };

/** The element that currently has focus, described for an assertion message. */
const focusedDescription = (page) => page.evaluate(() => {
    const element = document.activeElement;

    return element ? `${element.tagName.toLowerCase()}#${element.id}` : 'none';
});

test('public pages: no axe violations, a real title, a skip link first and a named menu', async ({ page }) => {
    test.setTimeout(120_000);
    await page.setViewportSize(DESKTOP);

    for (const url of PUBLIC_PAGES) {
        await page.goto(url);
        await expectNoAxeViolations(page, undefined, url);
    }

    await page.goto('/login');
    await expectNoAxeViolations(page, undefined, '/login');
    await expect(page.getByRole('main')).toHaveCount(1);

    // The home page is called the product's name once, not «Procynia · Procynia» (A11Y-122).
    await page.goto('/');
    await expect(page).toHaveTitle('Procynia');
    await page.goto('/faq');
    await expect(page).toHaveTitle('Ofte stilte spørsmål · Procynia');

    // Out of sight until Tab reaches it, then the first stop, visible and focused.
    const skip = page.getByRole('link', { name: 'Hopp til hovedinnhold' });
    expect((await skip.boundingBox()).width).toBeLessThanOrEqual(1);
    await page.keyboard.press('Tab');
    await expectFocusIndicator(skip);
    expect((await skip.boundingBox()).width).toBeGreaterThan(50);

    await page.keyboard.press('Enter');
    await expect(page.locator('main#main-content')).toBeFocused();
    await expect(page).toHaveURL(/\/faq$/);

    await expect(page.getByRole('navigation', { name: 'Hovedmeny' })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Hovedmeny' }).getByRole('link', { name: 'Produkt' })).not.toHaveAttribute('aria-current', 'page');
});

test('public pages on a 320 px phone: the menu opens and closes by keyboard, hands focus back, and nothing scrolls sideways', async ({ page }) => {
    await page.setViewportSize(PHONE_320);
    await page.goto('/funksjoner');
    expect(await sidewaysOverflow(page)).toEqual([]);

    const button = page.getByRole('button', { name: 'Meny' });
    const menu = page.getByRole('navigation', { name: 'Hovedmeny' });

    // Closed: the button says so, and no hidden link is reachable.
    await expect(button).toHaveAttribute('aria-expanded', 'false');
    await expect(menu).toHaveCount(0);

    await button.focus();
    await page.keyboard.press('Enter');
    await expect(button).toHaveAttribute('aria-expanded', 'true');
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('link', { name: 'Funksjoner' })).toHaveAttribute('aria-current', 'page');
    expect(await sidewaysOverflow(page)).toEqual([]);

    // Tab goes from the button straight into the menu.
    await page.keyboard.press('Tab');
    await expect(menu.getByRole('link', { name: 'Produkt' })).toBeFocused();

    // Escape closes it and puts the user back on the button.
    await page.keyboard.press('Escape');
    await expect(menu).toHaveCount(0);
    await expect(button).toBeFocused();
    await expect(button).toHaveAttribute('aria-expanded', 'false');

    // Following a link from the menu: the new page's heading gets focus, the menu is closed.
    await page.keyboard.press('Enter');
    await menu.getByRole('link', { name: 'Priser' }).focus();
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/priser$/);
    await expect(page.locator('main h1').first()).toBeFocused();
    await expect(menu).toHaveCount(0);
    expect(await sidewaysOverflow(page)).toEqual([]);
});

test('the app layout: skip link, named landmarks, current page, user menu and bell by keyboard', async ({ page }) => {
    test.setTimeout(90_000);
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/dashboard');
    await expect(page).toHaveTitle('Hjem · Procynia');
    await expectNoAxeViolations(page, undefined, '/app/dashboard');

    // One banner, one main, one contentinfo, and every navigation landmark named.
    await expect(page.getByRole('banner')).toHaveCount(1);
    await expect(page.getByRole('main')).toHaveCount(1);
    await expect(page.getByRole('contentinfo')).toHaveCount(1);
    for (const nav of await page.locator('nav').all()) {
        await expect(nav).toHaveAccessibleName(/\S/);
    }
    await expect(page.getByTestId('module-home')).toHaveAttribute('aria-current', 'page');

    // The skip link is the first Tab stop and lands in <main>; the next Tab is inside the page.
    await page.keyboard.press('Tab');
    const skip = page.getByRole('link', { name: 'Hopp til hovedinnhold' });
    await expectFocusIndicator(skip);
    await page.keyboard.press('Enter');
    await expect(page.locator('main#main-content')).toBeFocused();
    await page.keyboard.press('Tab');
    expect(await page.evaluate(() => Boolean(document.activeElement.closest('main'))), await focusedDescription(page)).toBe(true);

    // The user menu is a disclosure: named, expanded state, Tab into it, Escape back to the button.
    const userButton = page.getByRole('button', { name: /brukermeny$/ });
    await expect(userButton).not.toHaveAttribute('aria-haspopup');
    await userButton.focus();
    await page.keyboard.press('Enter');
    await expect(userButton).toHaveAttribute('aria-expanded', 'true');
    await page.keyboard.press('Tab');
    expect(await page.evaluate(() => Boolean(document.activeElement.closest('#app-user-menu')))).toBe(true);
    await page.keyboard.press('Escape');
    await expect(userButton).toBeFocused();
    await expect(userButton).toHaveAttribute('aria-expanded', 'false');

    // Leaving it with the keyboard closes it, so it can never cover what focus moves to.
    await page.keyboard.press('Enter');
    await expect(page.locator('#app-user-menu')).toBeVisible();
    await page.keyboard.press('Shift+Tab');
    await expect(page.locator('#app-user-menu')).toHaveCount(0);

    // The bell: its name carries the unread count, it is not announced as a dialog, and its panel is
    // a named region that Escape closes back onto the bell.
    const bell = page.getByRole('button', { name: /^Varsler/ });
    await expect(bell).not.toHaveAttribute('aria-haspopup');
    await bell.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('region', { name: 'Varsler' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('region', { name: 'Varsler' })).toHaveCount(0);
    await expect(bell).toBeFocused();
});

test('a real page change moves focus to the new heading; a filter on the same page does not move it', async ({ page }) => {
    test.setTimeout(90_000);
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/dashboard');

    // Keyboard on the rail: Enter on «Styring» → the Styring page, its h1 focused, its title set.
    await page.getByTestId('module-governance').focus();
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/app\/governance$/);
    const heading = page.getByRole('heading', { level: 1, name: 'Styring' });
    await expect(heading).toBeFocused();
    await expect(page).toHaveTitle('Styring · Procynia');
    await expect(page.getByTestId('module-governance')).toHaveAttribute('aria-current', 'page');
    // A heading is not a control; the page-change focus draws no frame round it.
    expect(await heading.evaluate((element) => getComputedStyle(element).outlineStyle)).toBe('none');

    // Tab continues from the heading into the page.
    await page.keyboard.press('Tab');
    expect(await page.evaluate(() => Boolean(document.activeElement.closest('main'))), await focusedDescription(page)).toBe(true);

    // The skip link still works after an Inertia visit.
    const skip = page.getByRole('link', { name: 'Hopp til hovedinnhold' });
    await skip.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('main#main-content')).toBeFocused();

    // One component, two areas told apart by the query string: Kunngjøringer → Saksliste is a new
    // page to the user (its own title), so focus moves to its heading.
    await page.goto('/app/notices');
    await page.getByTestId('module-navigation').getByRole('link', { name: 'Saksliste' }).focus();
    await page.keyboard.press('Enter');
    await page.waitForURL(/mode=saved/);
    await expect(page.locator('main h1').first()).toBeFocused();

    // A filter on the Wiki's source list is a pushed visit to the same page (preserveState, own
    // history entry): the list updates in place and focus stays on the filter the user changed.
    await page.goto('/app/wiki?tab=sources');
    const statusFilter = page.locator('select:has(option[value="failed"])');
    await statusFilter.focus();
    await statusFilter.selectOption('failed');
    await page.waitForURL(/src_status=failed/);
    await page.waitForTimeout(300);
    await expect(statusFilter).toBeFocused();

    // A11Y-134: a header pill that only changes the query string, under the same title, re-mounts the
    // page without preserveState. Focus used to fall to <body>; it goes back to the same pill.
    await page.goto('/app/wiki?tab=pages');
    const titleBefore = await page.title();
    const runsPill = page.getByTestId('module-navigation').getByRole('link', { name: 'Kjøringer' });
    await runsPill.focus();
    await page.keyboard.press('Enter');
    await page.waitForURL(/tab=runs/);
    await page.waitForTimeout(300);
    await expect(page).toHaveTitle(titleBefore);
    await expect(page.getByTestId('module-navigation').getByRole('link', { name: 'Kjøringer' })).toBeFocused();

    // Browser back is a page change too: focus goes to the restored page's heading, not <body>.
    await page.goto('/app/governance');
    await page.getByTestId('governance-module-risk').click();
    await page.waitForURL(/\/app\/risk$/);
    await page.goBack();
    await page.waitForURL(/\/app\/governance$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Styring' })).toBeFocused();
});

test('the success toast stays until closed, sits above the header, is read once and gives focus back', async ({ page }) => {
    test.setTimeout(180_000);

    const person = await complianceFixture(`seedLibraryJourney('${complianceSuffix}', '${COMPLIANCE_E2E_PASSWORD}')`);
    await loginAs(page, person.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/compliance/library');

    const card = page.getByTestId('compliance-library-framework-iso9001:2015');
    await card.getByRole('button', { name: 'Aktiver for virksomheten' }).click();
    await page.getByRole('dialog', { name: 'Aktiver ISO 9001?' }).getByRole('button', { name: 'Aktiver for virksomheten' }).click();

    const message = 'ISO 9001 er aktivert. Kravene ligger nå i kravregisteret deres.';
    const toast = page.getByTestId('flash-success');
    await expect(toast).toHaveText(message);

    // Read from the polite region, once; the close button sits beside the region, not in it.
    await expect(page.locator('#procynia-live-toast')).toHaveText(message);
    await expect(page.getByText(message)).toHaveCount(1);
    const close = page.getByRole('button', { name: 'Lukk melding' });
    expect(await close.evaluate((element) => Boolean(element.closest('[aria-live]')))).toBe(false);

    // No timer: still there well after the old three seconds.
    await page.waitForTimeout(4_000);
    await expect(toast).toBeVisible();

    // Above the sticky header (z 80 over 60), and only the button takes the pointer.
    const host = await page.locator('#procynia-toast').evaluate((element) => ({ z: getComputedStyle(element).zIndex, pointer: getComputedStyle(element).pointerEvents }));
    expect(host).toEqual({ z: '80', pointer: 'none' });
    const box = await close.boundingBox();
    expect(await page.evaluate(({ x, y }) => document.elementFromPoint(x, y)?.closest('button')?.getAttribute('data-testid'), { x: box.x + box.width / 2, y: box.y + box.height / 2 })).toBe('flash-success-close');

    // Escape closes it when no dialog or menu is open; focus does not move.
    await page.getByRole('button', { name: 'Hjelp', exact: true }).focus();
    await page.keyboard.press('Escape');
    await expect(toast).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Hjelp', exact: true })).toBeFocused();

    // A second outcome: closed with the button by keyboard, focus goes back to where the user was.
    const second = page.getByTestId('compliance-library-framework-iso27001:2022');
    await second.getByRole('button', { name: 'Aktiver for virksomheten' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Aktiver for virksomheten' }).click();
    await expect(toast).toBeVisible();
    const help = page.getByRole('button', { name: 'Hjelp', exact: true });
    await help.focus();
    await close.focus();
    await page.keyboard.press('Enter');
    await expect(toast).toHaveCount(0);
    await expect(help).toBeFocused();
});

test('the dashboard calendar names each day and lists the chosen day\'s deadlines outside the grid', async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/bid-status');
    await expect(page).toHaveTitle(/^Bid Status · Procynia$/);
    await expectNoAxeViolations(page, undefined, '/app/bid-status');

    const days = page.locator('button[aria-controls="deadline-day-list"]');
    await expect(days).toHaveCount(42);
    await expect(days.first()).toHaveAccessibleName(/^\p{L}+ \d{1,2}\. \p{L}+ \d{4}/u);
    await expect(page.locator('button[aria-current="date"]')).toHaveCount(1);
    await expect(page.locator('button a, button button')).toHaveCount(0);

    const list = page.getByTestId('deadline-day-list');
    await expect(list).toContainText('Velg en dag i kalenderen');

    const today = page.locator('button[aria-current="date"]');
    await today.focus();
    await page.keyboard.press('Enter');
    await expect(today).toHaveAttribute('aria-pressed', 'true');
    await expect(list.getByRole('heading', { level: 3 })).toHaveText(/^Frister /);
});

test('the app at 320 and 640 px: no sideways scroll, the header scrolls away, menus and help stay in the window', async ({ page }) => {
    test.setTimeout(90_000);
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);

    for (const size of [PHONE_320, { width: 640, height: 800 }]) {
        for (const url of ['/app/dashboard', '/app/bid-status', '/app/governance']) {
            await page.setViewportSize(size);
            await page.goto(url);
            expect(await sidewaysOverflow(page), `${url} at ${size.width}px`).toEqual([]);
            // Below lg the header is not sticky: at 400 % zoom it would otherwise fill the window.
            expect(await page.locator('header').first().evaluate((element) => getComputedStyle(element).position)).toBe('static');
        }
    }

    await page.setViewportSize(PHONE_320);
    await page.goto('/app/bid-status');

    // The skip link is in the window when focused.
    await page.keyboard.press('Tab');
    const skipBox = await page.getByRole('link', { name: 'Hopp til hovedinnhold' }).boundingBox();
    expect(skipBox.x).toBeGreaterThanOrEqual(0);
    expect(skipBox.x + skipBox.width).toBeLessThanOrEqual(PHONE_320.width);

    // The user menu opens inside the window.
    await page.getByRole('button', { name: /brukermeny$/ }).click();
    const menuBox = await page.locator('#app-user-menu').boundingBox();
    expect(menuBox.x).toBeGreaterThanOrEqual(0);
    expect(menuBox.x + menuBox.width).toBeLessThanOrEqual(PHONE_320.width);
    await page.keyboard.press('Escape');

    // So does the bell's panel.
    await page.getByRole('button', { name: /^Varsler/ }).click();
    const bellBox = await page.locator('#app-notifications-panel').boundingBox();
    expect(bellBox.x).toBeGreaterThanOrEqual(0);
    expect(bellBox.x + bellBox.width).toBeLessThanOrEqual(PHONE_320.width);
    await page.keyboard.press('Escape');

    // The help panel fills the narrow window and still hands focus back.
    const help = page.getByRole('button', { name: 'Hjelp', exact: true });
    await help.focus();
    await page.keyboard.press('Enter');
    const panel = page.getByRole('dialog');
    await expect(panel).toBeFocused();
    const panelBox = await panel.boundingBox();
    expect(panelBox.x + panelBox.width).toBeLessThanOrEqual(PHONE_320.width + 1);
    await page.keyboard.press('Escape');
    await expect(help).toBeFocused();

    // Opened after scrolling, when the non-sticky header has gone off the top, the panel still starts
    // inside the window and its close button can be reached.
    await page.mouse.wheel(0, 600);
    await page.waitForFunction(() => window.scrollY > 200);
    await help.focus();
    await page.keyboard.press('Enter');
    const scrolledBox = await page.getByRole('dialog').boundingBox();
    expect(scrolledBox.y).toBeGreaterThanOrEqual(0);
    await expect(page.getByRole('button', { name: 'Lukk hjelpepanel' })).toBeInViewport();
    await page.keyboard.press('Escape');

    // From lg up the header is sticky, and focus scrolled into view stops below it.
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/bid-status');
    const header = await page.locator('header').first().evaluate((element) => ({ position: getComputedStyle(element).position, height: element.offsetHeight }));
    expect(header.position).toBe('sticky');
    const padding = await page.evaluate(() => parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop));
    expect(padding).toBeGreaterThanOrEqual(header.height);
});

test('a rejected sign-in puts focus on the field, and the field carries its error', async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await page.goto('/login');
    await page.fill('#email', SYSTEM_OWNER.email);
    await page.fill('#password', 'feil-passord');
    await page.click('button[type="submit"]');

    const email = page.locator('#email');
    await expect(email).toHaveAttribute('aria-invalid', 'true');
    await expect(email).toBeFocused();
    const error = await page.locator('#email-error').innerText();
    await expect(email).toHaveAccessibleDescription(error);
    await expectNoAxeViolations(page, 'main', 'login with error');
});
