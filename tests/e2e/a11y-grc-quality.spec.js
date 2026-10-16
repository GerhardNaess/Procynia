import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';
import { expectNoAxeViolations } from './helpers/a11y.js';
import { DESKTOP, sidewaysOverflow } from './helpers/readability.js';
import { tinker } from './helpers/risk.js';

/**
 * WCAG Fase 6 — Kvalitet and the process diagram, in a real browser (docs/wcag-accessibility-plan.md).
 *
 * Tests\Support\A11yGrcQualityE2EFixture seeds, in the E2E System Owner's customer and marked with
 * this run's suffix, a process whose flow has two roles, two activities and a decision, and a control
 * with two pieces of evidence. Nothing here calls an AI: the one AI form is refused by validation
 * before any model is asked, and the structure edits are never saved.
 */

const FIXTURE = '\\Tests\\Support\\A11yGrcQualityE2EFixture';
const suffix = Math.random().toString(36).slice(2, 8).padEnd(6, '0').toUpperCase();
let seeded = null;

const polite = (page) => page.locator('#procynia-live-polite');
const section = (page, heading) => page.locator('section', { has: page.getByRole('heading', { name: heading, exact: true }) });

async function openFlow(page) {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto(`/app/quality/items/${seeded.process_id}?tab=flow`);
    await expect(page.locator(`svg[aria-label="Prosessflyt — ${seeded.process}"]`)).toBeVisible();
}

test.describe.serial('WCAG Fase 6 — Kvalitet', () => {
    test.beforeAll(async () => {
        await tinker(`${FIXTURE}::cleanup();`);
        const { stdout } = await tinker(`echo json_encode(${FIXTURE}::seed('${suffix}'));`);
        seeded = JSON.parse(stdout.match(/\{.*\}/s)[0]);
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup('${suffix}');`);
    });

    test.beforeEach(async ({ page }) => {
        await page.setViewportSize(DESKTOP);
    });

    test('the diagram with buttons is a named group, not an image, and draws its own focus ring (A11Y-004, -128)', async ({ page }) => {
        await openFlow(page);

        const svg = page.locator(`svg[aria-label="Prosessflyt — ${seeded.process}"]`);
        await expect(svg).toHaveAttribute('role', 'group');
        await expect(page.getByRole('group', { name: `Prosessflyt — ${seeded.process}` })).toBeVisible();

        // The node is a button the group exposes, and keyboard focus shows a ring drawn in the SVG.
        const node = svg.getByRole('button', { name: 'Rediger steget Registrer behovet' });
        await expect(node).toHaveCount(1);
        await page.keyboard.press('Shift');
        await node.focus();
        await expect(node).toBeFocused();
        const ringOpacity = await node.evaluate((element) => getComputedStyle(element.querySelector('rect.stroke-violet-600')).opacity);
        expect(ringOpacity).toBe('1');

        await expectNoAxeViolations(page, `svg[aria-label="Prosessflyt — ${seeded.process}"]`, 'diagram');
    });

    test('the step list edits and inserts through the same dialogs, and focus comes back (A11Y-004, -014)', async ({ page }) => {
        await openFlow(page);

        const steps = section(page, 'Steg');
        await expectNoAxeViolations(page, '#process-flow-steps', 'steg');

        // «Rediger steget» opens the step's dialog — the same one a click on the box opens.
        const edit = steps.getByRole('button', { name: 'Rediger steget Registrer behovet' });
        await edit.click();
        const dialog = page.getByRole('dialog');
        await expect(dialog.getByRole('heading', { name: 'Rediger steg' })).toBeVisible();
        const activity = dialog.getByRole('textbox', { name: 'Aktivitet' });
        await expect(activity).toHaveAttribute('aria-required', 'true');
        await expect(dialog.getByText('Felt merket med * må fylles ut.')).toBeVisible();
        await expectNoAxeViolations(page, '[role="dialog"]', 'Rediger steg');
        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
        await expect(edit).toBeFocused();

        // «Legg til aktivitet etter» puts the new activity on the same arrow as the diagram's «+».
        const insert = steps.getByRole('button', { name: 'Legg til aktivitet etter «Registrer behovet»' });
        await insert.click();
        await expect(dialog.getByRole('heading', { name: 'Legg til aktivitet' })).toBeVisible();
        await expect(dialog.getByText('Mellom «Registrer behovet» og «Over terskelverdi?».')).toBeVisible();
        await dialog.getByRole('button', { name: 'Avbryt' }).click();
        await expect(insert).toBeFocused();

        // A decision is edited from the list too; its branches are named per row.
        await steps.getByRole('button', { name: 'Rediger steget Over terskelverdi?' }).click();
        await expect(dialog.getByRole('textbox', { name: 'Utfall 1' })).toHaveValue('Ja');
        await expect(dialog.getByRole('combobox', { name: 'Går til (Utfall 2)' })).toBeVisible();
        await page.keyboard.press('Escape');
    });

    test('the structure editor names every field and keeps focus when a role goes (A11Y-004, -108, -127)', async ({ page }) => {
        await openFlow(page);
        await page.getByRole('button', { name: 'Rediger struktur manuelt' }).click();

        const nodes = page.getByRole('table', { name: 'Noder' });
        await expect(nodes.getByRole('columnheader', { name: 'Handlinger' })).toHaveCount(1);
        await expect(nodes.getByRole('textbox', { name: 'Tekst, node 2' })).toHaveValue('Registrer behovet');
        await expect(nodes.getByRole('combobox', { name: 'Rolle, node 2' })).toBeVisible();
        await expect(page.getByRole('table', { name: 'Forbindelser' }).getByRole('combobox', { name: 'Fra, forbindelse 1' })).toBeVisible();
        await expectNoAxeViolations(page, '#process-flow-structure-editor', 'struktur');

        // Removing a role says before and after that its steps go too; focus stays in the list.
        const removeLead = page.getByRole('button', { name: 'Fjern rollen «Leder» og 3 steg i den' });
        await removeLead.click();
        await expect(polite(page)).toHaveText('Rollen «Leder» og 3 steg i den er fjernet. Endringen er ikke lagret.');
        await expect(page.getByRole('button', { name: /^Fjern rollen «Innkjøper»/ })).toBeFocused();

        // Not saved: reloading brings the role back.
        await page.reload();
        await expect(page.getByRole('button', { name: 'Rediger steget Godkjenn innkjøpet' }).first()).toBeVisible();
    });

    test('a refused process description is on the field, described and focused (A11Y-013)', async ({ page }) => {
        await openFlow(page);
        const generate = page.getByRole('button', { name: 'Generer prosessflyt' });

        test.skip(await generate.count() === 0, 'AI-tolkning er ikke tilgjengelig i dette miljøet.');

        const field = page.getByRole('textbox', { name: 'Prosessbeskrivelse' });
        await field.fill('For kort.');
        await generate.click();

        // Refused by validation before any model is called.
        await expect(field).toBeFocused({ timeout: 15000 });
        await expect(field).toHaveAttribute('aria-invalid', 'true');
        const describedBy = await field.getAttribute('aria-describedby');
        await expect(page.locator(`[id="${describedBy}"]`)).not.toBeEmpty();
    });

    test('Dokument | Flyt says which one is open, and a refused save puts focus on the field (A11Y-027, -013, -121)', async ({ page }) => {
        await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
        await page.goto(`/app/quality/items/${seeded.process_id}?tab=document`);

        const views = page.getByRole('navigation', { name: 'Visninger av prosessen' });
        await expect(views.getByRole('link', { name: 'Dokument' })).toHaveAttribute('aria-current', 'page');
        await expect(views.getByRole('link', { name: 'Flyt' })).not.toHaveAttribute('aria-current', 'page');

        const metadata = section(page, 'Styringsinformasjon');
        const title = metadata.getByRole('textbox', { name: 'Tittel' });
        await expect(title).toHaveAttribute('aria-required', 'true');
        await title.fill('');
        await metadata.getByRole('button', { name: 'Lagre' }).click();

        await expect(title).toBeFocused();
        await expect(title).toHaveAttribute('aria-invalid', 'true');
        const describedBy = await title.getAttribute('aria-describedby');
        await expect(page.locator(`[id="${describedBy}"]`)).not.toBeEmpty();
        await expectNoAxeViolations(page, '#quality-item-metadata', 'Styringsinformasjon');
    });

    test('removing evidence keeps focus in the list (A11Y-014)', async ({ page }) => {
        await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
        await page.goto(`/app/quality/items/${seeded.control_id}`);

        const evidence = section(page, 'Evidens');
        page.once('dialog', (dialog) => dialog.accept());
        await evidence.getByRole('button', { name: `Fjern – ${seeded.evidence[0]}` }).click();

        await expect(evidence.getByText(seeded.evidence[0])).toHaveCount(0);
        await expect(evidence.getByRole('button', { name: `Fjern – ${seeded.evidence[1]}` })).toBeFocused();
    });

    test('the row actions are a disclosure; tables have captions and row headers (A11Y-103, -108)', async ({ page }) => {
        await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
        await page.goto('/app/quality?tab=processes');

        const table = page.getByRole('table', { name: 'Prosesser' });
        await expect(table.getByRole('rowheader', { name: seeded.process })).toBeVisible();

        const trigger = table.getByRole('button', { name: `Handlinger – ${seeded.process}` });
        expect(await trigger.getAttribute('aria-haspopup')).toBeNull();
        await trigger.click();
        await expect(trigger).toHaveAttribute('aria-expanded', 'true');
        await expect(page.getByRole('button', { name: 'Slett prosess', exact: true })).toBeVisible();
        await expect(page.getByRole('menu')).toHaveCount(0);

        await page.keyboard.press('Escape');
        await expect(trigger).toBeFocused();
        await expect(trigger).toHaveAttribute('aria-expanded', 'false');

        await expectNoAxeViolations(page, 'section[aria-labelledby="quality-processes-heading"]', 'Kvalitet → Prosesser');
    });

    test('320 px: the flow tab and the list do not scroll sideways', async ({ page }) => {
        await page.setViewportSize({ width: 320, height: 800 });
        await openFlow(page);
        expect(await sidewaysOverflow(page)).toEqual([]);

        await page.goto('/app/quality?tab=processes');
        expect(await sidewaysOverflow(page)).toEqual([]);
    });
});
