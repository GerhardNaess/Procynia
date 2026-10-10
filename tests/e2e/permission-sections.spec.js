import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';
import { DESKTOP, PHONE, sidewaysOverflow } from './helpers/readability.js';

/**
 * Kundemiljø → Tilganger lists one collapsible section per module. Every section starts closed,
 * several may be open at once, and a save in a matrix leaves the open sections open — the
 * sections are reading aids, never part of what is saved.
 */
const MODULES = [
    'Anbudsroller og kundemiljø',
    'Kvalitet',
    'Enterprise Wiki',
    'Risiko',
    'Mål og KPI',
    'Avvik og forbedringer',
    'Etterlevelse og revisjon',
    'Leverandøroppfølging',
    'Ledelsens gjennomgåelse',
];

const sectionButton = (page, name) => page.locator('section[data-testid^="permission-section-"] h3 button', { hasText: name }).first();
const allSectionButtons = (page) => page.locator('section[data-testid^="permission-section-"] h3 button');

test('modules are closed sections that open alone, together and all at once, and keep a save open', async ({ page }) => {
    test.setTimeout(90_000);
    page.on('dialog', (dialog) => dialog.accept());

    const roleName = `E2E Seksjonsrolle ${Date.now()}`;

    await page.setViewportSize(DESKTOP);
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/customer-environment?tab=permissions');

    await expect(page.getByRole('heading', { name: 'Tilganger', level: 2 })).toBeVisible();

    // 1–2. Every module is listed, and every section starts closed.
    for (const name of MODULES) {
        await expect(sectionButton(page, name)).toBeVisible();
    }
    await expect(allSectionButtons(page)).toHaveCount(MODULES.length);
    for (const button of await allSectionButtons(page).all()) {
        await expect(button).toHaveAttribute('aria-expanded', 'false');
    }
    await expect(page.getByRole('button', { name: 'Lukk alle' })).toBeDisabled();

    // A role to work with, holding one Kvalitet permission.
    await page.getByRole('button', { name: 'Ny rolle' }).click();
    await page.locator('#customer-role-name').fill(roleName);
    await page.getByRole('checkbox', { name: 'Se kvalitetssystemet', exact: true }).check();
    await page.getByRole('button', { name: 'Lagre rolle' }).click();
    await expect(page.locator('#customer-role-name')).toHaveCount(0);

    // Saving the role did not open anything.
    await expect(sectionButton(page, 'Kvalitet')).toHaveAttribute('aria-expanded', 'false');

    // 3. One section opens with a click; its matrix appears.
    const quality = sectionButton(page, 'Kvalitet');
    await quality.click();
    await expect(quality).toHaveAttribute('aria-expanded', 'true');
    const qualityPanel = page.locator('#permission-section-quality');
    await expect(qualityPanel).toBeVisible();
    await expect(qualityPanel.getByRole('columnheader', { name: 'Godkjenne kvalitetsobjekter' })).toBeVisible();

    // 5. Another opens from the keyboard while the first stays open; Space closes it again.
    const wiki = sectionButton(page, 'Enterprise Wiki');
    await wiki.focus();
    await page.keyboard.press('Enter');
    await expect(wiki).toHaveAttribute('aria-expanded', 'true');
    await expect(quality).toHaveAttribute('aria-expanded', 'true');
    await page.keyboard.press('Space');
    await expect(wiki).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('#permission-section-wiki')).toBeHidden();

    // 6–8. A matrix checkbox names its row and column, saves, and the section stays open.
    const create = qualityPanel.getByRole('checkbox', { name: `Opprette kvalitetsobjekter – ${roleName}`, exact: true });
    await expect(qualityPanel.getByRole('checkbox', { name: `Se kvalitetssystemet – ${roleName}`, exact: true })).toBeChecked();
    await expect(create).not.toBeChecked();
    await create.click();
    await expect(create).toBeChecked();
    await expect(create).toBeEnabled();
    await expect(quality).toHaveAttribute('aria-expanded', 'true');

    // 9. The save reached the server.
    await page.reload();
    await sectionButton(page, 'Kvalitet').click();
    await expect(page.locator('#permission-section-quality').getByRole('checkbox', { name: `Opprette kvalitetsobjekter – ${roleName}`, exact: true })).toBeChecked();

    // The bid-role matrix saves the same way and stays open too. Ticked twice, so the shared
    // E2E customer ends as it started.
    const base = sectionButton(page, 'Anbudsroller og kundemiljø');
    await base.click();
    const bidCheckbox = page.locator('#permission-section-base').getByRole('checkbox', { name: 'Opprette avdelinger – Bid Manager', exact: true });
    const before = await bidCheckbox.isChecked();
    await bidCheckbox.click();
    await expect(bidCheckbox).toBeChecked({ checked: ! before });
    await expect(bidCheckbox).toBeEnabled();
    await expect(base).toHaveAttribute('aria-expanded', 'true');
    await expect(sectionButton(page, 'Kvalitet')).toHaveAttribute('aria-expanded', 'true');
    await bidCheckbox.click();
    await expect(bidCheckbox).toBeChecked({ checked: before });
    await expect(bidCheckbox).toBeEnabled();

    // 4. Utvid alle and Lukk alle.
    await page.getByRole('button', { name: 'Utvid alle' }).click();
    for (const button of await allSectionButtons(page).all()) {
        await expect(button).toHaveAttribute('aria-expanded', 'true');
    }
    await expect(page.getByRole('button', { name: 'Utvid alle' })).toBeDisabled();

    // 11. On a phone the page itself never scrolls sideways; wide matrices scroll in their own box.
    await page.setViewportSize(PHONE);
    await expect(sectionButton(page, 'Leverandøroppfølging')).toBeVisible();
    expect(await sidewaysOverflow(page)).toEqual([]);
    await page.screenshot({ path: 'test-results/permission-sections-phone.png', fullPage: true });
    await page.setViewportSize(DESKTOP);
    await page.screenshot({ path: 'test-results/permission-sections-desktop.png', fullPage: true });

    await page.getByRole('button', { name: 'Lukk alle' }).click();
    for (const button of await allSectionButtons(page).all()) {
        await expect(button).toHaveAttribute('aria-expanded', 'false');
    }

    // Clean up the role.
    await page.locator('tr', { hasText: roleName }).getByRole('button', { name: 'Slett' }).click();
    await expect(page.locator('tr', { hasText: roleName })).toHaveCount(0);
});
