import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';
import { DESKTOP, PHONE, expectPageHelp, sidewaysOverflow } from './helpers/readability.js';

/**
 * Every view in Kvalitet opens its own help — the four tabs and one page of each kind — and the help
 * is readable and closes at both widths. The texts themselves are owned by
 * QualityPageHelpTranslationsTest; which page gets which text by qualityHelp.test.js.
 */
const TABS = [
    { tab: 'overview', title: 'Om Kvalitet', sections: ['Styrende dokumenter', 'Prosesser og kontroller', 'Trenger oppmerksomhet', 'Kom i gang'] },
    { tab: 'processes', title: 'Om prosesser', sections: ['Prosesser'] },
    { tab: 'controls', title: 'Om kontroller', sections: ['Kontrollregisteret'] },
    { tab: 'tools', title: 'Om verktøy', sections: ['Verktøy'] },
];

test.beforeEach(async ({ page }) => {
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
});

for (const { tab, title, sections } of TABS) {
    test(`the ${tab} tab has its own help at desktop and phone width`, async ({ page }) => {
        for (const size of [DESKTOP, PHONE]) {
            await page.setViewportSize(size);
            await page.goto(`/app/quality?tab=${tab}`);
            await expectPageHelp(page, title, sections);
            expect(await sidewaysOverflow(page)).toEqual([]);
        }

        await page.setViewportSize(PHONE);
        await page.getByRole('button', { name: 'Hjelp', exact: true }).click();
        await page.screenshot({ path: `test-results/quality-page-help-${tab}-phone.png` });
        await page.getByRole('button', { name: 'Lukk hjelpepanel' }).click();
        await expect(page.getByRole('dialog', { name: title })).toHaveCount(0);
    });
}

const ITEMS = [
    { kind: 'process', from: '/app/quality?tab=processes', title: 'Om prosessen', sections: ['Dokument', 'Flyt'] },
    { kind: 'control', from: '/app/quality?tab=controls', title: 'Om kontrollen', sections: ['Kontrollen', 'Plassering og evidens'] },
    { kind: 'document', from: '/app/quality', section: 'Styrende dokumenter', title: 'Om dokumentet', sections: ['Dokumenttyper', 'Styring og vedlikehold'] },
];

for (const { kind, from, section, title, sections } of ITEMS) {
    test(`a ${kind} page has its own help at desktop and phone width`, async ({ page }) => {
        await page.goto(from);
        const scope = section
            ? page.locator('section', { has: page.getByRole('heading', { name: section, exact: true }) })
            : page.locator('main');
        const link = scope.locator('a[href*="/app/quality/items/"]').first();

        if (await link.count() === 0) {
            test.skip(true, `No ${kind} in this environment.`);
        }

        await page.goto(await link.getAttribute('href'));

        for (const size of [DESKTOP, PHONE]) {
            await page.setViewportSize(size);
            await expectPageHelp(page, title, sections);
            expect(await sidewaysOverflow(page)).toEqual([]);
        }

        await page.getByRole('button', { name: 'Hjelp', exact: true }).click();
        await page.screenshot({ path: `test-results/quality-page-help-${kind}-phone.png` });
    });
}
