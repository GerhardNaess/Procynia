import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';
import { expectReadable } from './helpers/readability.js';
import { tinker } from './helpers/risk.js';

/**
 * Kvalitet → Oversikt lists three kinds of object, never one: styrende dokumenter, prosesser and
 * kontroller each in a section of its own. A control is described by what it checks, who carries it
 * out, how often, how, and whether evidence is recorded — not as a document. What the register
 * payload holds is owned by QualityActivityControlTest.
 */
const suffix = Math.random().toString(36).slice(2, 8).padEnd(6, '0').toUpperCase();

test.beforeAll(async () => {
    await tinker('\\Tests\\Support\\QualityOverviewE2EFixture::cleanup();');
});

test.afterAll(async () => {
    await tinker(`\\Tests\\Support\\QualityOverviewE2EFixture::cleanup('${suffix}');`);
});

const section = (page, heading) => page.locator('section', { has: page.getByRole('heading', { name: heading, exact: true }) });

test('Oversikt lists documents, processes and controls in separate sections', async ({ page }) => {
    const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\QualityOverviewE2EFixture::seed('${suffix}'));`);
    const titles = JSON.parse(stdout.match(/\{.*\}/)[0]);

    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/quality');

    const documents = section(page, 'Styrende dokumenter');
    const processes = section(page, 'Prosesser');
    const controls = section(page, 'Kontroller');

    // Each object in its own section, and in no other.
    await expect(documents.getByRole('link', { name: titles.policy })).toBeVisible();
    await expect(documents.getByRole('link', { name: titles.process })).toHaveCount(0);
    await expect(documents.getByRole('link', { name: titles.control })).toHaveCount(0);

    await expect(processes.getByRole('link', { name: titles.process })).toBeVisible();
    await expect(processes.getByRole('link', { name: titles.control })).toHaveCount(0);

    await expect(controls.getByRole('link', { name: titles.control })).toBeVisible();
    await expect(controls.getByRole('link', { name: titles.process })).toHaveCount(0);

    // The control reads as a control: what, who, how often, how, status and evidence.
    const row = controls.locator('tr', { has: page.getByRole('link', { name: titles.control }) });
    for (const text of ['Fire øyne på leverandørens attester', 'Innkjøpsleder', 'Kvartalsvis', 'Stikkprøve', 'Gjeldende', '1 registrert']) {
        await expect(row.getByText(text, { exact: true })).toBeVisible();
    }
    for (const header of ['Hva kontrolleres', 'Ansvarlig', 'Frekvens', 'Metode', 'Evidens']) {
        await expect(controls.getByRole('columnheader', { name: header })).toBeVisible();
    }
    // No document columns on a control.
    await expect(controls.getByRole('columnheader', { name: 'Wiki' })).toHaveCount(0);
    await expect(controls.getByRole('columnheader', { name: 'Type' })).toHaveCount(0);

    // No generic relation editor: a governing document is linked on the process itself.
    await expect(page.getByRole('heading', { name: 'Relasjoner' })).toHaveCount(0);

    await expectReadable(page, 'quality-overview-sections', 'overview');

    // Prosesser: the processes, under their own heading — not «Styrende dokumenter».
    await page.goto('/app/quality?tab=processes');
    await expect(section(page, 'Prosesser').getByRole('link', { name: titles.process })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Styrende dokumenter', exact: true })).toHaveCount(0);
    await expect(page.getByRole('link', { name: titles.control })).toHaveCount(0);
    await expectReadable(page, 'quality-overview-sections', 'processes');

    // Kontroller: the full register, with where each control is used.
    await page.goto('/app/quality?tab=controls');
    const register = section(page, 'Kontrollregister');
    const registerRow = register.locator('tr', { has: page.getByRole('link', { name: titles.control }) });
    await expect(registerRow.getByText('1 registrert', { exact: true })).toBeVisible();
    await expect(registerRow.getByText('Ikke koblet til noen aktivitet')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Styrende dokumenter', exact: true })).toHaveCount(0);
    await expectReadable(page, 'quality-overview-sections', 'controls');
});
