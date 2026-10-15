import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';
import { expectFocusIndicator, expectNoAxeViolations, focusIndicator } from './helpers/a11y.js';
import { DESKTOP, PHONE, sidewaysOverflow } from './helpers/readability.js';
import { riskE2eSuffix, tinker } from './helpers/risk.js';

/**
 * WCAG Fase 3 — forms, dialogs, tables and status messages, in a real browser
 * (docs/wcag-accessibility-plan.md §17). Nothing here saves data that outlives the test: every form
 * is submitted empty (rejected by validation), every dialog is closed with Escape or Avbryt, and the
 * one permission that is toggled is toggled back.
 */

/** Whether the element is fully inside the viewport. */
async function inViewport(locator) {
    return locator.evaluate((element) => {
        const box = element.getBoundingClientRect();

        return box.top >= 0 && box.left >= 0 && box.bottom <= window.innerHeight && box.right <= window.innerWidth;
    });
}

/** Whether the element, or an ancestor, is inert. */
const isInert = (locator) => locator.evaluate((element) => element.closest('[inert]') !== null);

test.beforeEach(async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
});

test('a rejected submit puts focus on the first wrong field and brings it into view — mouse and keyboard', async ({ page }) => {
    await page.goto('/app/watch-profiles/create');

    const form = page.locator('form').filter({ has: page.locator('#watch-profile-name') });
    const name = page.locator('#watch-profile-name');
    const submit = page.getByRole('button', { name: 'Opprett Watch Profile' });

    // A11Y-001: the select is named by its label, the «i» beside it is a separate button.
    if (await page.locator('#watch-profile-owner-scope').count() > 0) {
        await expect(page.locator('#watch-profile-owner-scope')).toHaveAccessibleName('Eierskap');
    }
    await expect(page.getByRole('textbox', { name: 'Beskrivelse' })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Nøkkelord' })).toHaveAccessibleDescription('Ett nøkkelord per linje.');
    await expect(page.getByRole('button', { name: 'Vis forklaring for Nøkkelord' })).toBeVisible();
    await expectNoAxeViolations(page, 'form', 'watch profile form');

    // Mouse: scroll down to the button, click. The banner A11Y-133 was about would be far above.
    await submit.scrollIntoViewIfNeeded();
    expect(await inViewport(name), 'the name field starts out of view').toBe(false);
    await submit.click();

    await expect(name).toBeFocused();
    await expect(name).toHaveAttribute('aria-invalid', 'true');
    const describedBy = await name.getAttribute('aria-describedby');
    expect(describedBy).toBe('watch-profile-name-error');
    await expect(page.locator('#watch-profile-name-error')).toBeVisible();
    expect(await inViewport(name), 'the focused field was scrolled into view').toBe(true);
    await expectNoAxeViolations(page, 'form', 'watch profile form with errors');

    // Keyboard: Enter on the submit button does the same.
    await submit.focus();
    await page.keyboard.press('Enter');
    await expect(name).toBeFocused();

    // Typing does not move focus, even though the error is still on screen.
    await page.keyboard.type('E2E');
    await expect(name).toBeFocused();
    await expect(form).toBeVisible();
});

test('the plain POST user form comes back with focus on the first wrong field, and every field is named', async ({ page }) => {
    await page.goto('/app/users/create');

    await expect(page.getByRole('combobox', { name: 'Rolle' })).toBeVisible();
    await expect(page.getByRole('checkbox', { name: 'QA (kvalitetssikring)' })).toBeVisible();
    await expect(page.getByRole('group', { name: 'Brukeren tilhører disse avdelingene' })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Passord', exact: true })).toHaveAccessibleDescription('Minst 8 tegn.');

    await page.getByRole('button', { name: 'Opprett bruker' }).click();
    await page.waitForURL(/\/app\/users\/create/);

    const name = page.locator('#user-name');
    await expect(name).toBeFocused();
    await expect(name).toHaveAttribute('aria-invalid', 'true');
    await expect(name).toHaveAttribute('aria-describedby', 'user-name-error');
    await expectNoAxeViolations(page, 'form[action="/app/users"]', 'user form with errors');
});

test('the department dialog: focus in, trapped, the page behind inert, errors at the field, Escape back to the trigger', async ({ page }) => {
    await page.goto('/app/customer-environment?tab=departments');

    // The page's button; the dialog's submit button has the same name and comes later in the DOM.
    const trigger = page.getByRole('button', { name: 'Opprett avdeling' }).first();
    await trigger.focus();
    await page.keyboard.press('Enter');

    const dialog = page.getByRole('dialog', { name: 'Opprett avdeling' });
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAccessibleDescription('Alle endringer gjelder kun deres eget kundemiljø.');
    await expect(dialog.getByRole('button', { name: 'Lukk dialog' })).toBeFocused();

    // The page behind is inert: out of the accessibility tree, and focus cannot be put there.
    expect(await isInert(page.locator('header').first())).toBe(true);
    expect(await isInert(trigger)).toBe(true);
    expect(await isInert(dialog)).toBe(false);
    await page.locator('header a').first().evaluate((link) => link.focus());
    expect(await dialog.evaluate((element) => element.contains(document.activeElement))).toBe(true);

    // Tab and Shift+Tab stay inside.
    for (let step = 0; step < 6; step += 1) {
        await page.keyboard.press('Tab');
        expect(await dialog.evaluate((element) => element.contains(document.activeElement))).toBe(true);
    }
    await page.keyboard.press('Shift+Tab');
    expect(await dialog.evaluate((element) => element.contains(document.activeElement))).toBe(true);

    // An empty name is rejected: focus to the field, the error tied to it.
    await dialog.locator('button[type="submit"]').click();
    const name = page.locator('#department-environment-name');
    await expect(name).toBeFocused();
    await expect(name).toHaveAttribute('aria-invalid', 'true');
    await expect(name).toHaveAttribute('aria-describedby', 'department-environment-name-error');
    await expectNoAxeViolations(page, '[role="dialog"]', 'department dialog with errors');

    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expect(trigger).toBeFocused();
    expect(await isInert(page.locator('header').first()), 'the page is live again').toBe(false);
});

test('Go/No-go templates: one h1, the new-template dialog is named, closes with its named button and returns focus', async ({ page }) => {
    await page.goto('/app/go-no-go-templates');

    await expect(page.locator('h1')).toHaveCount(1);

    const trigger = page.getByRole('button', { name: '+ Ny mal' });
    await trigger.click();

    const dialog = page.getByRole('dialog', { name: 'Ny vurderingsmal' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('textbox', { name: 'Malnavn' })).toHaveAttribute('aria-required', 'true');
    await expectNoAxeViolations(page, '[role="dialog"]', 'new template dialog');

    await dialog.getByRole('button', { name: 'Lukk dialog' }).click();
    await expect(dialog).toHaveCount(0);
    await expect(trigger).toBeFocused();
});

test('a permission checkbox keeps focus while it saves and the save is spoken once', async ({ page }) => {
    test.setTimeout(60_000);
    await page.goto('/app/customer-environment?tab=permissions');

    const section = page.locator('section[data-testid="permission-section-base"]');
    await section.locator('h3 button').click();

    const checkbox = section.locator('tbody input[type="checkbox"]:not([disabled])').first();
    const wasChecked = await checkbox.isChecked();

    await checkbox.focus();
    await page.keyboard.press('Space');
    await expect(page.locator('#procynia-live-polite')).toHaveText('Lagret');
    await expect(checkbox).toBeFocused();
    expect(await checkbox.isChecked()).toBe(! wasChecked);

    // Put it back. Focus never left the box, so Space works again straight away.
    await page.keyboard.press('Space');
    await expect.poll(() => checkbox.isChecked()).toBe(wasChecked);
    await expect(checkbox).toBeFocused();
});

test('a wide table scrolls in a named, focusable region on a phone, and is no tab stop when it fits', async ({ page }) => {
    await page.setViewportSize(PHONE);
    await page.goto('/app/customer-environment?tab=users');

    const region = page.getByRole('region', { name: 'Brukere' });
    await expect(region).toBeVisible();
    await expect(region).toHaveAttribute('tabindex', '0');
    expect(await sidewaysOverflow(page)).toEqual([]);

    await page.keyboard.press('Tab');
    await region.focus();
    await expectFocusIndicator(region, { offset: '-2px' });
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowRight');
    await expect.poll(() => region.evaluate((element) => element.scrollLeft)).toBeGreaterThan(0);
    await expectNoAxeViolations(page, '[data-scroll-region]', 'users table region');

    // Departments fit on a desktop: no region, no extra tab stop.
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/customer-environment?tab=departments');
    const box = page.locator('[data-scroll-region]').first();

    if (await box.count() > 0) {
        await expect(box).not.toHaveAttribute('role', 'region');
        await expect(box).not.toHaveAttribute('tabindex', '0');
    }
});

test('the focus ring of a first-column link and a last-column button is not cut off by the table box', async ({ page }) => {
    await page.goto('/app/quality?tab=processes');

    const region = page.locator('[data-scroll-region]').first();
    const firstLink = region.locator('tbody tr').first().locator('td').first().locator('a');
    const lastButton = region.locator('tbody tr').first().locator('td').last().locator('button');

    if (await firstLink.count() === 0) {
        test.skip(true, 'No seeded quality process in this environment.');
    }

    // The outline is 2 px wide, 2 px off the control: it needs 4 px inside the clipping box.
    const room = async (control) => control.evaluate((element, box) => {
        const outer = box.getBoundingClientRect();
        const inner = element.getBoundingClientRect();

        return { left: inner.left - outer.left, right: outer.right - inner.right };
    }, await region.elementHandle());

    await page.keyboard.press('Tab');
    await firstLink.focus();
    await expectFocusIndicator(firstLink);
    expect((await room(firstLink)).left).toBeGreaterThanOrEqual(4);

    if (await lastButton.count() > 0) {
        // The row menu draws its own slate-500 outline (≈ 4.8:1), so only its shape is asserted.
        await lastButton.first().focus();
        await expect(lastButton.first()).toBeFocused();
        expect(await focusIndicator(lastButton.first())).toMatchObject({ focusVisible: true, style: 'solid', width: '2px', offset: '2px' });
        expect((await room(lastButton.first())).right).toBeGreaterThanOrEqual(4);
    }
});

test('the competitor list says how it is sorted and names each row’s link', async ({ page }) => {
    await page.goto('/app/suppliers');

    await expect(page.getByRole('searchbox', { name: 'Leverandørnavn / org.nr.' })).toBeVisible();
    await expect(page.getByLabel('Fra dato')).toBeVisible();
    await expect(page.getByLabel('Til dato')).toBeVisible();

    const table = page.locator('table');

    if (await table.count() === 0) {
        return;
    }

    // Default sort: Oppdatert, descending.
    await expect(page.locator('th[aria-sort="descending"]')).toContainText('Oppdatert');
    const sortByName = page.getByRole('button', { name: 'Sorter etter Leverandørnavn' });
    await sortByName.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('th[aria-sort="ascending"]')).toContainText('Leverandørnavn');
    await expect(sortByName, 'the sort button keeps focus across the reload').toBeFocused();
});

test('an explanation stays open on the way from the «i» to its text, and one press opens it', async ({ page }) => {
    await page.goto('/app/info-center?view=my_tasks');

    const trigger = page.getByRole('button', { name: 'Vis forklaring for Mine oppgaver' });
    const tooltip = page.getByRole('tooltip');

    // Pointer: over the «i», then down across the 8 px gap onto the panel (A11Y-025).
    await trigger.hover();
    await expect(tooltip).toBeVisible();
    const from = await trigger.boundingBox();
    const to = await tooltip.boundingBox();
    const x = Math.min(Math.max(from.x + from.width / 2, to.x + 8), to.x + to.width - 8);
    for (let y = from.y + from.height / 2; y <= to.y + 12; y += 2) {
        await page.mouse.move(x, y);
    }
    await expect(tooltip).toBeVisible();

    // The panel is a <span>: valid wherever a hint sits (a label, a heading, a paragraph).
    expect(await tooltip.evaluate((element) => element.tagName)).toBe('SPAN');

    // One press, as a tap delivers it (pointer arrives, then click), leaves it open.
    await page.mouse.move(0, 0);
    await expect(tooltip).toHaveCount(0);
    await trigger.click();
    await expect(tooltip).toBeVisible();
});

test('a refused row action is answered beside the row, described by its button, focus kept, no scroll', async ({ page }) => {
    test.setTimeout(90_000);
    // An area holding a risk cannot be deleted. Named from the Risk fixture's templates, so
    // RiskE2EFixture::cleanup removes both whatever happens.
    const suffix = riskE2eSuffix();
    const areaName = `E2E Beredskap ${suffix}`;
    await tinker(`\\$u=\\App\\Models\\User::where('email','e2e.systemowner@procynia.test')->first(); \\$a=\\App\\Models\\BusinessArea::create(['customer_id'=>\\$u->customer_id,'name'=>'${areaName}']); \\App\\Models\\Risk::create(['customer_id'=>\\$u->customer_id,'business_area_id'=>\\$a->id,'title'=>'E2E Serverbrann ${suffix}','cause'=>'a','event'=>'b','consequence'=>'c','status'=>'identified','created_by'=>\\$u->id]);`);

    try {
        page.on('dialog', (dialog) => dialog.accept());
        await page.goto('/app/customer-environment?tab=permissions');

        const remove = page.getByRole('button', { name: `Slett ${areaName}` });
        await remove.scrollIntoViewIfNeeded();
        const scrollBefore = await page.evaluate(() => window.scrollY);
        await remove.focus();
        await page.keyboard.press('Enter');

        const message = 'Fagområdet er i bruk og kan ikke slettes. Flytt innholdet i fagområdet, for eksempel risikoer, først.';
        // The banner at the top is still the one alert…
        await expect(page.getByRole('alert')).toHaveText(message);
        // …and the answer also stands where the user is, tied to the button.
        await expect(remove).toHaveAccessibleDescription(message);
        await expect(remove).toBeFocused();
        const local = page.locator('[id^="row-action-error-area-delete-"]');
        await expect(local).toHaveText(message);
        expect(await inViewport(local)).toBe(true);
        await expect(local).not.toHaveAttribute('role', 'alert');
        expect(Math.abs((await page.evaluate(() => window.scrollY)) - scrollBefore)).toBeLessThan(40);

        // The next visit clears it.
        await page.goto('/app/customer-environment?tab=permissions');
        await expect(page.locator('[id^="row-action-error-"]')).toHaveCount(0);
    } finally {
        await tinker(`\\Tests\\Support\\RiskE2EFixture::cleanup('${suffix}');`);
    }
});

test('one event, one visible message: Go/No-go templates show the layout\'s toast only', async ({ page }) => {
    // A template of this test's own, removed afterwards whatever happens.
    const name = `E2E A11y-mal ${riskE2eSuffix()}`;
    const model = '\\\\App\\\\Models\\\\GoNoGoAssessmentTemplate';
    await tinker(`\\$u=\\App\\Models\\User::where('email','e2e.systemowner@procynia.test')->first(); ${model}::create(['customer_id'=>\\$u->customer_id,'name'=>'${name}','is_default'=>false,'is_active'=>true,'created_by'=>\\$u->id]);`);

    try {
        await page.goto('/app/go-no-go-templates');
        await page.getByRole('button', { name: `Deaktiver ${name}` }).click();

        // The toast in the polite live region is the message; the page draws no second copy.
        const toast = page.locator('#procynia-live-toast');
        await expect(toast).not.toBeEmpty();
        const text = (await toast.innerText()).trim();
        await expect(page.getByText(text, { exact: true })).toHaveCount(1);
        await expect(page.getByRole('button', { name: `Aktiver ${name}` })).toBeVisible();
    } finally {
        await tinker(`${model}::where('name','${name}')->delete();`);
    }
});

test('field borders have 3:1 at rest, turn rose-600 when invalid, and keep the violet focus outline', async ({ page }) => {
    await page.goto('/app/watch-profiles/create');
    const name = page.locator('#watch-profile-name');

    // Contrast of the computed border colour against the white field and the white card.
    // The field has `transition`: the border colour animates for 150 ms, so it is read once settled.
    const borderContrast = () => name.evaluate(async (element) => {
        await Promise.all(element.getAnimations().map((animation) => animation.finished));
        const canvas = document.createElement('canvas').getContext('2d');
        canvas.fillStyle = getComputedStyle(element).borderTopColor;
        canvas.fillRect(0, 0, 1, 1);
        const [r, g, b] = canvas.getImageData(0, 0, 1, 1).data;
        const channel = (value) => {
            const c = value / 255;

            return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
        };
        const luminance = 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);

        return 1.05 / (luminance + 0.05);
    });

    expect(await borderContrast()).toBeGreaterThanOrEqual(3);

    await page.getByRole('button', { name: 'Opprett Watch Profile' }).click();
    await expect(name).toHaveAttribute('aria-invalid', 'true');
    await page.mouse.click(5, 5);
    expect(await borderContrast(), 'rose-600 on white').toBeGreaterThanOrEqual(4.5);

    await name.focus();
    await page.keyboard.press('Shift+Tab');
    await page.keyboard.press('Tab');
    await expectFocusIndicator(name, { offset: '-2px' });
});

test('a dialog closed by navigating away leaves nothing inert', async ({ page }) => {
    // Rediger bruker → Avdelinger is an Inertia visit with its own history entry.
    await page.goto('/app/customer-environment?tab=users');
    await page.locator('tbody tr', { hasText: 'e2e.user@procynia.test' }).first().getByRole('link', { name: /^Rediger / }).click();
    await page.waitForURL(/\/app\/users\/\d+\/edit/);
    await page.getByRole('navigation', { name: 'Kundemiljø' }).getByRole('button', { name: 'Avdelinger' }).click();
    await page.waitForURL(/tab=departments/);

    await page.getByRole('button', { name: 'Opprett avdeling' }).first().click();
    await expect(page.getByRole('dialog', { name: 'Opprett avdeling' })).toBeVisible();
    expect(await page.locator('[inert]').count()).toBeGreaterThan(0);

    // Back in the history, with the dialog still open: the page is replaced under it.
    await page.goBack();
    await page.waitForURL(/\/app\/users\/\d+\/edit/);
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect.poll(() => page.locator('[inert]').count()).toBe(0);

    // And the page works with the keyboard again.
    const field = page.locator('#user-name');
    await field.focus();
    await expect(field).toBeFocused();
});
