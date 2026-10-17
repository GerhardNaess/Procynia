import { expect, test } from '@playwright/test';
import { loginAs } from './helpers/auth.js';
import { expectNoAxeViolations } from './helpers/a11y.js';
import { DESKTOP, sidewaysOverflow } from './helpers/readability.js';
import { tinker } from './helpers/risk.js';

/**
 * WCAG Fase 5 — Enterprise Wiki, in a real browser (docs/wcag-accessibility-plan.md §19).
 *
 * Tests\Support\A11yWikiE2EFixture seeds a customer of its own: a page owner (System Owner), one
 * eligible reviewer, a draft article with two best-practice suggestions waiting for a decision, and
 * two published pages linked to and from it. Everything the spec changes — claim decisions, the review
 * hand-over, a refused edit — happens to that customer's data and is removed afterwards. No AI call is
 * made anywhere in these flows.
 *
 * The spec is serial: it follows one article from the list, through the graph, into editing and the
 * review, in the order a person would.
 */

const FIXTURE = '\\Tests\\Support\\A11yWikiE2EFixture';
const OWNER = { email: 'e2e.a11ywiki.owner@procynia.test', password: 'e2e-a11y-wiki-password' };
const REVIEWER = { email: 'e2e.a11ywiki.reviewer@procynia.test', password: 'e2e-a11y-wiki-password' };
const ARTICLE = '/app/wiki/e2e-a11y-leverandorsikkerhet';

const polite = (page) => page.locator('#procynia-live-polite');
const toast = (page) => page.locator('#procynia-live-toast');

test.describe.serial('WCAG Fase 5 — Enterprise Wiki', () => {
    test.beforeAll(async () => {
        await tinker(`echo ${FIXTURE}::seed();`);
    });

    test.afterAll(async () => {
        await tinker(`${FIXTURE}::cleanup();`);
    });

    test.beforeEach(async ({ page }) => {
        await page.setViewportSize(DESKTOP);
    });

    test('1–2. the Wiki list: named filters, a labelled search, and the result count said once (A11Y-048, -111)', async ({ page }) => {
        await loginAs(page, OWNER.email, OWNER.password);
        await page.goto('/app/wiki');

        await expect(page.getByRole('heading', { level: 1, name: 'Wiki' })).toBeVisible();

        // Every filter is named by what it filters, not by its first option.
        for (const name of ['Sidetype', 'Status', 'Kvalitetsfunn', 'Sortering']) {
            await expect(page.getByRole('combobox', { name, exact: true })).toBeVisible();
        }

        await expectNoAxeViolations(page, 'main', 'Wiki-sider');

        const search = page.getByRole('searchbox', { name: 'Søk i Wiki-sider' });
        await search.fill('Leverandør');
        await search.press('Enter');

        // The table names the page in its «Åpne» link, and the count is said once the list has changed.
        await expect(page.getByRole('link', { name: 'Åpne Leverandørsikkerhet' })).toBeVisible();
        await expect(polite(page)).toHaveText('1 sider.');
        await expect(search).toBeFocused();

        // A filter change keeps focus where it was made.
        const status = page.getByRole('combobox', { name: 'Status', exact: true });
        await status.focus();
        await status.selectOption('draft');
        await expect(page).toHaveURL(/status=draft/);
        await expect(status).toBeFocused();
    });

    test('3–5. the graph has a list that does what the canvas does: find, open details, follow a relation (A11Y-050)', async ({ page }) => {
        await loginAs(page, OWNER.email, OWNER.password);
        await page.goto('/app/wiki/graph');

        const canvas = page.locator('[data-graph-view="full"]');
        await expect(canvas).toHaveAttribute('role', 'img');
        await expect(canvas).toHaveAttribute('aria-label', /3 sider og 2 koblinger.*Liste/);
        await expectNoAxeViolations(page, 'main', 'graf');

        // The switch is a named group of toggle buttons; pressing one leaves focus on it.
        const listButton = page.getByRole('group', { name: 'Visning' }).getByRole('button', { name: 'Liste', exact: true });
        await listButton.focus();
        await page.keyboard.press('Enter');
        await expect(listButton).toHaveAttribute('aria-pressed', 'true');
        await expect(listButton).toBeFocused();
        await expect(canvas).toBeHidden();

        const list = page.getByRole('region', { name: 'Sider i grafen' });
        await expect(list).toBeVisible();
        await expect(list.getByRole('heading', { level: 3 })).toHaveText(['Kontinuitetsplan', 'Leverandørsikkerhet', 'Tilgangsstyring']);

        // The same search narrows both presentations, and the change is said once.
        await page.getByLabel('Søk i Wiki-sider').fill('Leverandør');
        await expect(polite(page)).toHaveText('Viser 1 av 3 sider.');
        await expect(list.getByRole('heading', { level: 3 })).toHaveText(['Leverandørsikkerhet']);
        await expect(page.getByText('1 av 3 sider', { exact: true })).toBeVisible();

        // «Vis detaljer» opens the node panel the canvas opens, and focus moves into it.
        const details = list.getByRole('button', { name: 'Vis detaljer' });
        await details.focus();
        await page.keyboard.press('Enter');
        const nodePanel = page.locator('[data-graph-panel="node"]');
        await expect(nodePanel).toBeFocused();
        await expect(nodePanel).toContainText('Status: OK');
        await expect(details).toHaveAttribute('aria-pressed', 'true');
        await expectNoAxeViolations(page, 'main', 'liste med sidepanel');

        // Closing returns focus to the row's button.
        await nodePanel.getByRole('button', { name: 'Lukk sidedetaljer' }).click();
        await expect(details).toBeFocused();

        // A relation: «Hvorfor er de koblet?» opens the relation panel the line opens. The search is
        // cleared first — with only one page shown there is no line to explain.
        await page.getByLabel('Søk i Wiki-sider').fill('');
        await expect(list.getByRole('heading', { level: 3 })).toHaveCount(3);
        const row = list.getByRole('listitem').filter({ has: page.getByRole('heading', { name: 'Leverandørsikkerhet' }) });
        await expect(row.getByText('Lenker til')).toBeVisible();
        await expect(row.getByText('Lenkes fra')).toBeVisible();
        const why = row.getByRole('button', { name: 'Hvorfor er de koblet?' }).first();
        await why.press('Enter');
        const relationPanel = page.locator('[data-graph-panel="relation"]');
        await expect(relationPanel).toBeFocused();
        await relationPanel.getByRole('button', { name: 'Lukk relasjonen' }).press('Enter');
        await expect(why).toBeFocused();

        // The related page opens from the list, with the graph as its way back.
        await row.getByRole('link', { name: 'Kontinuitetsplan' }).click();
        await expect(page).toHaveURL(/e2e-a11y-kontinuitetsplan\?back_url=/);
        await expect(page.getByRole('link', { name: 'Tilbake til Grafvisning' })).toBeVisible();
    });

    test('the graph list is the same data as the canvas, under every filter (A11Y-050)', async ({ page }) => {
        await loginAs(page, OWNER.email, OWNER.password);
        await page.goto('/app/wiki/graph');
        await page.getByRole('button', { name: 'Liste', exact: true }).click();

        const list = page.getByRole('region', { name: 'Sider i grafen' });
        const rows = list.getByRole('heading', { level: 3 });
        const summaryCount = async () => Number((await page.getByRole('heading', { name: 'Grafoversikt' }).locator('..').locator('dd').first().textContent()).trim());

        await expect(rows).toHaveCount(3);
        expect(await summaryCount()).toBe(3);

        // Status filter: no page has errors, so unticking OK leaves nothing in either presentation.
        await page.getByRole('group', { name: 'Status' }).getByLabel('OK').uncheck();
        await expect(list).toContainText('Ingen Wiki-sider matcher filtrene.');
        await expect(rows).toHaveCount(0);
        expect(await summaryCount()).toBe(0);
        await expect(polite(page)).toHaveText('Ingen Wiki-sider matcher filtrene.');
        await page.getByRole('group', { name: 'Status' }).getByLabel('OK').check();

        // Page type: only entities.
        for (const type of ['Artikkel', 'Sammendrag', 'Konsept']) {
            await page.getByRole('group', { name: 'Sidetyper' }).getByLabel(type).uncheck();
        }
        await expect(rows).toHaveText(['Tilgangsstyring']);
        expect(await summaryCount()).toBe(1);
        await expect(page.locator('[data-graph-view="full"]')).toHaveAttribute('aria-label', /1 sider og 0 koblinger/);
    });

    test('focus mode has a list too, with the same two actions as the card on the canvas (A11Y-050)', async ({ page }) => {
        await loginAs(page, OWNER.email, OWNER.password);
        await page.goto('/app/wiki/graph');

        await page.getByRole('group', { name: 'Grafmodus' }).getByRole('button', { name: 'Fokus', exact: true }).click();
        await page.getByRole('button', { name: 'Liste', exact: true }).click();
        await page.locator('#graph-focus-page').selectOption({ label: 'Leverandørsikkerhet' });

        const focusList = page.getByRole('region', { name: 'Sider i fokusvisningen' });

        // The fixture projects its pages into the graph store; where the projection is off in this
        // environment the message is shown instead — and said, which is what is checked then.
        const unavailable = page.getByText('Fokusvisningen er ikke tilgjengelig.');
        await expect(focusList.or(unavailable).first()).toBeVisible({ timeout: 15000 });

        if (await focusList.isVisible()) {
            await expect(focusList.getByRole('heading', { name: 'Fokusside' })).toBeVisible();
            await expect(focusList.getByRole('heading', { name: '1 hopp unna' })).toBeVisible();
            await expect(focusList.getByRole('link', { name: 'Kontinuitetsplan' })).toBeVisible();
            await expect(polite(page)).toHaveText(/3 sider · 2 koblinger/);
            await expectNoAxeViolations(page, 'main', 'fokusvisning som liste');

            // «Fokuser på denne siden» makes a neighbour the next focus, like the card on the canvas.
            await focusList.getByRole('button', { name: 'Fokuser på denne siden' }).first().click();
            await expect(page.locator('#graph-focus-page')).not.toHaveValue('');
            await expect(focusList.getByRole('heading', { name: 'Fokusside' })).toBeVisible();
        } else {
            await expect(page.locator('#procynia-live-assertive')).toHaveText('Fokusvisningen er ikke tilgjengelig.');
        }
    });

    test('the article: one h1, the Markdown headings under it, and a table of contents a screen reader can use (A11Y-046)', async ({ page }) => {
        await loginAs(page, OWNER.email, OWNER.password);
        await page.goto(ARTICLE);

        // The stored Markdown starts with «# Leverandørsikkerhet»; it is the h2 under the page's h1 now.
        await expect(page.locator('main h1')).toHaveCount(1);
        await expect(page.locator('main h1')).toHaveText('Leverandørsikkerhet');
        const article = page.locator('.wiki-article');
        await expect(article.locator('h2[data-md-level="1"]')).toHaveText('Leverandørsikkerhet');
        await expect(article.locator('h3[data-md-level="2"]')).toHaveText(['Formål', 'Oppfølging']);
        await expect(article.locator('h1')).toHaveCount(0);

        // The collapsible verification sections are headings with a disclosure inside.
        const verification = page.getByRole('heading', { level: 2, name: /Verifikasjonsgrunnlag/ }).getByRole('button');
        await expect(verification).toHaveAttribute('aria-expanded', 'true');

        await expectNoAxeViolations(page, 'main', 'artikkel');
    });

    test('9. both suggestions can be decided with the keyboard alone, without losing focus or the scroll position (A11Y-014, -047)', async ({ page }) => {
        await loginAs(page, OWNER.email, OWNER.password);
        await page.goto(ARTICLE);

        // The comment field has a label of its own, not just a placeholder.
        await expect(page.getByLabel('Kommentar (valgfritt)').first()).toBeVisible();

        const keep = page.getByRole('button', { name: 'Behold teksten' });
        await expect(keep).toHaveCount(2);

        const cards = page.locator('article[id^="claim-"]').filter({ has: page.getByRole('button', { name: 'Behold teksten' }) });
        await expect(cards).toHaveCount(2);
        const firstCard = cards.nth(0);
        const secondCardId = await cards.nth(1).getAttribute('id');
        await expect(firstCard).toHaveAccessibleName(/Påstand 1 av 2/);

        await keep.first().focus();
        const scrollBefore = await page.evaluate(() => window.scrollY);
        await page.keyboard.press('Enter');

        // The decided card left the work list; focus is on the next claim, which is named by its place.
        await expect(toast(page)).toContainText('Påstanden er godkjent.');
        await expect(page.locator(`#${secondCardId}`)).toBeFocused();
        await expect(page.locator(`#${secondCardId}`)).toHaveAccessibleName(/Påstand 1 av 1/);
        expect(Math.abs(await page.evaluate(() => window.scrollY) - scrollBefore)).toBeLessThan(400);

        // Tab from the card reaches its own decision.
        await page.getByRole('button', { name: 'Behold teksten' }).focus();
        await page.keyboard.press('Enter');
        await expect(page.getByText('Alle påstander som krever behandling, er ferdigbehandlet.')).toBeVisible();
        // No open claim is left and the verified list is folded, so focus lands on the section's heading.
        await expect(page.locator('#wiki-verification-toggle')).toBeFocused();
    });

    test('6–8. editing: named fields, the Markdown hint, a refused save on the field, and focus back on «Rediger artikkel» (A11Y-049)', async ({ page }) => {
        await loginAs(page, OWNER.email, OWNER.password);
        await page.goto(ARTICLE);

        const edit = page.getByRole('button', { name: 'Rediger artikkel' });
        await edit.click();

        const first = page.getByRole('textbox', { name: /^Rediger avsnitt 1 av \d+$/ });
        await expect(first).toBeFocused();
        await expect(first).toHaveAccessibleDescription(/Markdown/);
        // Each field is named by its place, never all «Rediger avsnitt».
        const names = await page.getByRole('textbox', { name: /^Rediger avsnitt/ }).evaluateAll((fields) => fields.map((field) => field.labels[0].textContent));
        expect(new Set(names).size).toBe(names.length);
        expect(names.length).toBeGreaterThan(1);
        await expectNoAxeViolations(page, 'main', 'redigering');

        // An emptied paragraph is refused by validation: the field says so, and focus is on it.
        await first.fill('');
        await page.getByRole('button', { name: 'Lagre', exact: true }).click();
        await expect(first).toBeFocused();
        await expect(first).toHaveAttribute('aria-invalid', 'true');
        await expect(first).toHaveAccessibleDescription(/Et avsnitt kan ikke være tomt\./);
        await expectNoAxeViolations(page, 'main', 'redigering med feil');

        await page.getByRole('button', { name: 'Avbryt', exact: true }).click();
        await expect(edit).toBeFocused();
        await expect(page.getByRole('textbox', { name: /Rediger avsnitt/ })).toHaveCount(0);
    });

    test('9–10. the page is sent for review from the keyboard, and the status is said and shown (A11Y-002)', async ({ page }) => {
        await loginAs(page, OWNER.email, OWNER.password);
        await page.goto(ARTICLE);

        const panel = page.locator('#wiki-review-panel');
        await expect(panel).toHaveAccessibleName('Gjennomgang og publisering');

        await page.getByRole('button', { name: 'Send til gjennomgang', exact: true }).press('Enter');
        const dialog = page.getByRole('dialog', { name: 'Send til gjennomgang' });
        await expect(dialog).toBeVisible();
        await expect(dialog).toHaveAccessibleDescription(/kontrollere denne versjonen/);
        const reviewer = dialog.getByRole('combobox', { name: 'Kontrollør' });
        await expect(reviewer).toBeFocused();
        await expect(reviewer).toHaveValue(/\d+/);
        await expectNoAxeViolations(page, '[role="dialog"]', 'send til gjennomgang');

        await dialog.getByRole('button', { name: 'Send til gjennomgang', exact: true }).press('Enter');

        // The trigger is gone (the page is in review now); focus is on the panel that says so.
        await expect(dialog).toBeHidden();
        await expect(toast(page)).toContainText('Siden er sendt til gjennomgang hos E2E Kontrollør.');
        await expect(panel).toBeFocused();
        await expect(page.getByText('Til gjennomgang', { exact: true }).first()).toBeVisible();
    });

    test('9–10. the reviewer sends it back: the minimum length is stated, and the result is said and shown (A11Y-118)', async ({ page }) => {
        await loginAs(page, REVIEWER.email, REVIEWER.password);
        await page.goto(ARTICLE);

        await expect(page.getByTestId('wiki-review-turn')).toContainText('Denne siden venter på din gjennomgang.');
        await page.getByRole('button', { name: 'Send tilbake', exact: true }).click();

        const dialog = page.getByRole('dialog', { name: 'Send tilbake' });
        const reason = dialog.getByRole('textbox', { name: 'Kommentar' });
        await expect(reason).toBeFocused();
        await expect(reason).toHaveAccessibleDescription(/Minst 10 tegn\./);

        await reason.fill('For kort');
        await expect(dialog.getByRole('button', { name: 'Send tilbake', exact: true })).toBeDisabled();
        await reason.fill('Formålet må beskrive hvem som gjør vurderingen.');
        await expectNoAxeViolations(page, '[role="dialog"]', 'send tilbake');
        await dialog.getByRole('button', { name: 'Send tilbake', exact: true }).click();

        await expect(dialog).toBeHidden();
        await expect(toast(page)).toContainText('Wiki-siden er avvist.');
        await expect(page.locator('#wiki-review-panel')).toBeFocused();
        await expect(page.getByText('Endringer kreves', { exact: true }).first()).toBeVisible();
    });

    test('Kildedokumenter and Kjøringer: named controls, row actions named by their document, timeline steps in words (A11Y-011, -024)', async ({ page }) => {
        await loginAs(page, OWNER.email, OWNER.password);
        await page.goto('/app/wiki?tab=sources');

        await expect(page.getByLabel('Velg fil')).toHaveAccessibleDescription(/PDF eller DOCX/);
        await expect(page.getByRole('button', { name: 'Søk', exact: true })).toBeVisible();
        await expect(page.getByRole('row', { name: /Leverandørrutiner/ }).getByRole('link', { name: 'Last ned' }))
            .toHaveAccessibleDescription('Leverandørrutiner (E2E a11y).docx');
        await expectNoAxeViolations(page, 'main', 'kildedokumenter');

        await page.goto('/app/wiki?tab=runs');
        const progress = page.getByRole('list', { name: /Fremdrift for kjøring/ }).first();
        await expect(progress.getByRole('listitem').first()).toContainText('fullført');
        await expectNoAxeViolations(page, 'main', 'kjøringer');
    });

    test('Spør Wiki has one h1, and the field is wired (A11Y-042)', async ({ page }) => {
        await loginAs(page, OWNER.email, OWNER.password);
        await page.goto('/app/wiki/ask');

        await expect(page.locator('main h1')).toHaveCount(1);
        await expect(page.getByRole('textbox', { name: 'Spørsmål' })).toBeVisible();
        await expectNoAxeViolations(page, 'main', 'Spør Wiki');
    });

    test('reflow: 320 px and 640 px without sideways scrolling (1.4.10)', async ({ page }) => {
        await loginAs(page, OWNER.email, OWNER.password);

        for (const width of [320, 640]) {
            await page.setViewportSize({ width, height: 800 });

            for (const url of ['/app/wiki', '/app/wiki?tab=sources', '/app/wiki?tab=runs', ARTICLE, '/app/wiki/ask']) {
                await page.goto(url);
                expect(await sidewaysOverflow(page), `${url} at ${width}px`).toEqual([]);
            }

            await page.goto('/app/wiki/graph');
            await page.getByRole('button', { name: 'Liste', exact: true }).click();
            await expect(page.getByRole('region', { name: 'Sider i grafen' })).toBeVisible();
            expect(await sidewaysOverflow(page), `graph list at ${width}px`).toEqual([]);
        }
    });
});
