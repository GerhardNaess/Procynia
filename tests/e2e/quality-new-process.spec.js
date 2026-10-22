import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, USER, loginAs } from './helpers/auth.js';
import { expectFocusIndicator, expectNoAxeViolations } from './helpers/a11y.js';
import { PHONE, sidewaysOverflow } from './helpers/readability.js';
import { tinker } from './helpers/risk.js';

/**
 * Kvalitet → Prosesser: «Ny prosess». A process used to be creatable only from «Registrer i
 * kvalitetssystemet» at the very bottom of Oversikt, which nobody on the process list could find.
 * The tab now opens the same form, fixed to the Prosess type.
 *
 * The journey creates its own process, lands in the flow, finds the process again in the list and
 * deletes it through the row menu. afterAll removes anything a failed run left behind.
 */
const PREFIX = 'E2E Ny prosess';

test.afterAll(async () => {
    await tinker(`\\App\\Models\\QualityItem::where('customer_id', 2)->where('title', 'like', '${PREFIX} %')->get()->each->delete();`);
});

for (const viewport of [{ width: 1366, height: 900 }, PHONE]) {
    test(`a System Owner creates a process from the Prosesser tab (${viewport.width} px)`, async ({ page }) => {
        test.setTimeout(120_000);
        const title = `${PREFIX} ${viewport.width} ${Date.now()}`;

        await page.setViewportSize(viewport);
        await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
        await page.goto('/app/quality?tab=processes');

        // The button is the first thing in the tab, above the list. (On a phone the module rail sits
        // above every page, so there it is reached by scrolling.)
        const newProcess = page.getByRole('button', { name: 'Ny prosess', exact: true });
        const processesHeading = page.getByRole('heading', { name: 'Prosesser', level: 2 });
        expect((await newProcess.boundingBox()).y).toBeLessThan((await processesHeading.boundingBox()).y);
        await newProcess.scrollIntoViewIfNeeded();
        await expect(newProcess).toBeInViewport();
        await expect(newProcess).toHaveAttribute('aria-expanded', 'false');

        // Keyboard: Enter opens the form with focus in Tittel; Avbryt closes it and focus returns.
        await newProcess.focus();
        await expectFocusIndicator(newProcess);
        await page.keyboard.press('Enter');
        await expect(newProcess).toHaveAttribute('aria-expanded', 'true');
        const form = page.locator('#quality-new-process');
        await expect(form.getByRole('heading', { name: 'Ny prosess', level: 2 })).toBeVisible();
        await expect(form.getByLabel('Tittel')).toBeFocused();
        await expect(form.getByLabel('Type')).toHaveCount(0);
        await form.getByRole('button', { name: 'Avbryt' }).click();
        await expect(form).toHaveCount(0);
        await expect(newProcess).toBeFocused();

        // An empty title is refused, with the error on the field.
        await newProcess.click();
        await form.getByRole('button', { name: 'Opprett prosess' }).click();
        await expect(form.getByLabel('Tittel')).toHaveAttribute('aria-invalid', 'true');
        await expect(form.getByLabel('Tittel')).toBeFocused();
        expect(await sidewaysOverflow(page)).toEqual([]);
        if (viewport.width > 500) {
            await expectNoAxeViolations(page, '#quality-new-process');
        }

        // Create: the user lands in the new process's flow and is told it worked.
        await form.getByLabel('Tittel').fill(title);
        await form.getByLabel('Formål').fill('Opprettet av E2E-testen for «Ny prosess».');
        await form.getByRole('button', { name: 'Opprett prosess' }).click();
        await expect(page).toHaveURL(/\/app\/quality\/items\/\d+.*tab=flow/);
        await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible();
        await expect(page.getByText('Prosessen er opprettet.').first()).toBeVisible();

        // Found again in the list, unpublished.
        await page.goto('/app/quality?tab=processes');
        const row = page.getByRole('row').filter({ has: page.getByRole('link', { name: new RegExp(title) }) });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText('Ikke publisert');

        // And removed again through the row menu.
        page.once('dialog', (dialog) => dialog.accept());
        // The menu closes on any scroll (it is fixed, measured off its trigger), so the row is
        // brought into view first and the tap comes after — as a person scrolls, then taps.
        const menuTrigger = row.getByRole('button', { name: /^Handlinger – / });
        await menuTrigger.scrollIntoViewIfNeeded();
        await page.waitForTimeout(300);
        await menuTrigger.click();
        await page.getByRole('button', { name: 'Slett prosess', exact: true }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByRole('button', { name: /^Slett/ }).last().click();
        await expect(page.getByRole('link', { name: new RegExp(title) })).toHaveCount(0);
    });
}

// The spec owns the reader it tests: a role holding quality.view and nothing else, given to the E2E
// contributor for this test and taken away after. It used to lean on whatever roles the development
// database had collected; the isolated E2E database starts without any (docs/testing/e2e-environment.md).
const READER_ROLE = 'E2E Kvalitet leser';
const userQuery = "\\App\\Models\\User::where('email', 'e2e.user@procynia.test')->firstOrFail()";
const removeReaderRole = `\\App\\Models\\CustomerRole::where('name', '${READER_ROLE}')->get()->each(function (\\$role) { \\$role->users()->detach(); \\$role->delete(); });`;

test('a user without quality.create sees the list but no «Ny prosess»', async ({ page }) => {
    await tinker(removeReaderRole);
    await tinker(`\\$user = ${userQuery}; \\$role = \\App\\Models\\CustomerRole::create(['customer_id' => \\$user->customer_id, 'name' => '${READER_ROLE}', 'is_active' => true]); \\$role->syncPermissions(['quality.view']); \\$user->customerRoles()->attach(\\$role->id, ['customer_id' => \\$user->customer_id]);`);

    try {
        await loginAs(page, USER.email, USER.password);
        await page.goto('/app/quality?tab=processes');
        await expect(page.getByRole('heading', { name: 'Prosesser', level: 2 })).toBeVisible();

        // The button follows quality.create exactly as the backend does, whatever else the
        // contributor holds.
        const { stdout } = await tinker(`echo json_encode(app(\\App\\Services\\Permissions\\CustomerPermissionService::class)->has(${userQuery}, 'quality.create'));`);
        const canCreate = stdout.trim().endsWith('true');
        await expect(page.getByRole('button', { name: 'Ny prosess', exact: true })).toHaveCount(canCreate ? 1 : 0);
    } finally {
        await tinker(removeReaderRole);
    }
});
