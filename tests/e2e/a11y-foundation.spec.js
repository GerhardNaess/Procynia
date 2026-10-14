import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { expectFocusIndicator, expectNoAxeViolations, focusIndicator } from './helpers/a11y.js';
import { COMPLIANCE_E2E_PASSWORD, cleanUpComplianceE2eData, complianceE2eSuffix, complianceFixture } from './helpers/compliance.js';
import { DESKTOP } from './helpers/readability.js';
import { cleanUpSupplierE2eData, SUPPLIER_E2E_PASSWORD, supplierE2eSuffix, supplierFixture } from './helpers/suppliers.js';

const complianceSuffix = complianceE2eSuffix();
const supplierSuffix = supplierE2eSuffix();
cleanUpComplianceE2eData(complianceSuffix);
cleanUpSupplierE2eData(supplierSuffix);

/**
 * WCAG Fase 1 — the shared foundation, checked in a real browser on real pages
 * (docs/wcag-accessibility-plan.md). Each test drives one shared piece with the keyboard and scopes
 * axe to that piece: the pages around them belong to later phases.
 */

test('keyboard focus is visible on every control of the login form, including fields that suppress the outline', async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await page.goto('/login');

    // The e-mail field sets `outline-none` and only darkens its border on focus (under 3:1); the
    // global rule restores the outline, drawn inside the field's edge.
    const email = page.locator('#email');
    await email.focus();
    await expectFocusIndicator(email, { offset: '-2px' });

    // Every further Tab stop of the form gets the same indicator, whatever its own classes say:
    // password (outline-none), «Husk meg» (browser default before), «Logg inn».
    const stops = [];

    for (let step = 0; step < 3; step += 1) {
        await page.keyboard.press('Tab');
        const focused = page.locator(':focus');
        const indicator = await focusIndicator(focused);
        const description = await focused.evaluate((element) => `${element.tagName.toLowerCase()}[type=${element.getAttribute('type')}]`);
        stops.push(description);

        expect(indicator.style, description).toBe('solid');
        expect(indicator.width, description).toBe('2px');
        expect(indicator.isViolet600, description).toBe(true);
    }

    expect(stops).toEqual(['input[type=password]', 'input[type=checkbox]', 'button[type=submit]']);
});

test('the file picker is named, described, visibly focused and reports a rejected file; the help panel takes and returns focus', async ({ page }) => {
    test.setTimeout(120_000);

    const person = await supplierFixture(`seedJourney('${supplierSuffix}', '${SUPPLIER_E2E_PASSWORD}')`);
    await loginAs(page, person.email, SUPPLIER_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/supplier-management/import');

    const section = page.locator('section[aria-labelledby="import-file-heading"]');
    const input = page.locator('#supplier-import-file');
    const trigger = page.locator('#supplier-import-file-trigger');

    // Name: caption + button, not only «Velg fil». Description: chosen file, then the hint.
    await expect(input).toHaveAccessibleName('Excel-fil (.xlsx) Velg fil');
    await expect(input).toHaveAccessibleDescription(/^Ingen fil valgt Bare \.xlsx, maks \d+ MB og \d+ leverandører\./);
    await expect(input).not.toHaveAttribute('aria-invalid', 'true');

    // The real input is visually hidden; reaching it with Tab must show the ring on the visible trigger.
    await page.getByTestId('import-template-download').focus();
    await page.keyboard.press('Tab');
    await expect(input).toBeFocused();
    expect(await focusIndicator(trigger)).toMatchObject({ style: 'solid', width: '2px', offset: '2px', isViolet600: true });

    await expectNoAxeViolations(page, 'section[aria-labelledby="import-file-heading"]', 'file picker section');

    // A rejected file: the field is marked invalid, the message is an alert and part of its description.
    await input.setInputFiles({ name: 'leverandorer.txt', mimeType: 'text/plain', buffer: Buffer.from('ikke excel') });
    await section.getByRole('button', { name: 'Last opp og kontroller' }).click();
    const error = section.getByRole('alert');
    await expect(error).toBeVisible();
    await expect(input).toHaveAttribute('aria-invalid', 'true');
    await expect(input).toHaveAccessibleDescription(new RegExp(`${(await error.innerText()).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`));
    await expectNoAxeViolations(page, 'section[aria-labelledby="import-file-heading"]', 'file picker with error');

    // Hjelp: opening moves focus into the panel, Escape gives it back to the button.
    const helpButton = page.getByRole('button', { name: 'Hjelp', exact: true });
    await helpButton.focus();
    await page.keyboard.press('Enter');
    const panel = page.getByRole('dialog', { name: 'Om import av leverandører' });
    await expect(panel).toBeFocused();
    await expect(panel.getByRole('button', { name: 'Lukk hjelpepanel' })).toBeVisible();
    await expectNoAxeViolations(page, '[role="dialog"]', 'help panel');
    await page.keyboard.press('Escape');
    await expect(panel).toHaveCount(0);
    await expect(helpButton).toBeFocused();
});

test('ActionDialog traps focus above the header, closes on Escape back to its trigger, and the outcome is announced', async ({ page }) => {
    test.setTimeout(180_000);

    const person = await complianceFixture(`seedLibraryJourney('${complianceSuffix}', '${COMPLIANCE_E2E_PASSWORD}')`);
    await loginAs(page, person.email, COMPLIANCE_E2E_PASSWORD);
    await page.setViewportSize(DESKTOP);
    await page.goto('/app/compliance/library');

    const card = page.getByTestId('compliance-library-framework-iso9001:2015');
    const trigger = card.getByRole('button', { name: 'Aktiver for virksomheten' });
    const dialog = page.getByRole('dialog', { name: 'Aktiver ISO 9001?' });

    // Open with the keyboard: focus lands inside the dialog.
    await trigger.focus();
    await page.keyboard.press('Enter');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator(':focus')).toHaveCount(1);
    await expectNoAxeViolations(page, '[role="dialog"]', 'ActionDialog');

    // The dialog covers the sticky header: what is at the top of the window is the backdrop, not the header.
    const topElementIsHeader = await page.evaluate(() => Boolean(document.elementFromPoint(window.innerWidth / 2, 10)?.closest('header')));
    expect(topElementIsHeader).toBe(false);

    // Tab and Shift+Tab never leave it.
    for (const key of ['Tab', 'Tab', 'Tab', 'Shift+Tab', 'Shift+Tab', 'Shift+Tab', 'Shift+Tab']) {
        await page.keyboard.press(key);
        await expect(dialog.locator(':focus'), `after ${key}`).toHaveCount(1);
        await expect(dialog.locator(':focus')).toBeFocused();
        expect((await focusIndicator(dialog.locator(':focus'))).style).toBe('solid');
    }

    // Focus moved out by the browser itself — Safari's Tab skips buttons and can jump from a field
    // to one outside — is pulled back into the dialog.
    await page.evaluate(() => document.querySelector('header a[href], header button')?.focus());
    await expect(dialog.locator(':focus')).toHaveCount(1);

    // Escape closes it and puts the user back on the button they pressed.
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expect(trigger).toBeFocused();

    // Confirming redirects with a flash message. The toast is drawn inside the app-wide polite live
    // region, which exists from page load, so the text a sighted user sees is the text that is spoken.
    await trigger.click();
    await dialog.getByRole('button', { name: 'Aktiver for virksomheten' }).click();
    const toastRegion = page.locator('#procynia-live-toast');
    await expect(toastRegion).toHaveAttribute('aria-live', 'polite');
    await expect(toastRegion).toContainText('ISO 9001 er aktivert. Kravene ligger nå i kravregisteret deres.');
    await expect(page.getByText('ISO 9001 er aktivert. Kravene ligger nå i kravregisteret deres.')).toHaveCount(1);
});
