import { test, expect } from '@playwright/test';
import { loginAs, SYSTEM_OWNER, USER } from './helpers/auth.js';
import { DESKTOP, PHONE, expectPageHelp, expectReadable, sidewaysOverflow } from './helpers/readability.js';
import { tinker } from './helpers/risk.js';

test.beforeEach(async ({ page }) => {
    await loginAs(page, USER.email, USER.password);
});

const labels = async (locator) => (await locator.allTextContents())
    .map((text) => text.trim())
    .filter(Boolean);

test('notices page loads for authenticated customer user', async ({ page }) => {
    const response = await page.goto('/app/notices');

    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/app\/notices/);
    // App navigation header contains the Procynia logo image
    await expect(page.locator('img[alt="Procynia"]')).toBeVisible();
});

/**
 * Three levels, the same three in every module.
 *
 * The rail picks a module, the header names what is inside it, the page carries what is inside
 * that. Wiki worked this way from the start; Anbud used to nest its four work areas in the rail
 * instead, which made it the only module with a second level in the left-hand column. The source
 * guards in resources/js/Layouts/headerNavigation.test.js check how the lists are built — this
 * checks what a person actually sees.
 */
test('Anbud and Wiki have the same navigation shape', async ({ page }) => {
    await page.goto('/app/notices');

    const rail = page.getByTestId('module-sidebar');

    // No module nests its work areas in the rail. The one nested list is Styring's, a level
    // above modules rather than below one.
    await expect(rail.locator('ul ul')).toHaveCount(1);
    await expect(rail.locator('ul ul')).toHaveAttribute('data-testid', 'module-governance-children');
    await expect(rail.locator('[aria-current="page"]')).toHaveText('Anbud');

    await expect(page.getByTestId('module-navigation').locator('a, span'))
        .toHaveText(['Bid Status', 'Kunngjøringer', 'Saksliste', 'Besvarelse', 'Konkurrenter']);
    await expect(page.getByTestId('page-navigation').locator('a, span'))
        .toHaveText(['Live søk', 'Varsler', 'Watch lists']);

    // Wiki has no work-area level, so its tabs stay the module navigation and it gains no third row.
    await page.goto('/app/wiki');
    await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText('Wiki');
    expect(await labels(page.getByTestId('module-navigation').locator('a, span')))
        .toEqual(['Kildedokumenter', 'Kjøringer', 'Wiki-sider', 'Grafvisning']);
    await expect(page.getByTestId('page-navigation')).toHaveCount(0);
});

test('both navigation levels follow the URL', async ({ page }) => {
    await page.goto('/app/notices');
    await expect(page.getByTestId('module-navigation').locator('[aria-current="page"]')).toHaveText('Kunngjøringer');
    await expect(page.getByTestId('page-navigation').locator('[aria-current="page"]')).toHaveText('Live søk');

    // A tab change moves the page level only; the area above it stays Kunngjøringer.
    await page.goto('/app/notices?tab=alerts');
    await expect(page.getByTestId('module-navigation').locator('[aria-current="page"]')).toHaveText('Kunngjøringer');
    await expect(page.getByTestId('page-navigation').locator('[aria-current="page"]')).toHaveText('Varsler');

    // An area change moves both.
    await page.goto('/app/notices?mode=saved');
    await expect(page.getByTestId('module-navigation').locator('[aria-current="page"]')).toHaveText('Saksliste');
});

test('a module without work areas shows neither row — Hjem', async ({ page }) => {
    await page.goto('/app/dashboard');

    await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText('Hjem');
    await expect(page.getByTestId('module-navigation')).toHaveCount(0);
    await expect(page.getByTestId('page-navigation')).toHaveCount(0);
});

/**
 * Hjem and Anbud used to be the same page under two names: /app/dashboard served the bid cockpit,
 * so clicking Hjem put you inside Anbud's numbers. The cockpit is unchanged and still exists, as
 * Bid Status inside Anbud. What these check is that the two stay separate.
 */
test('Hjem is a cross-module dashboard, not the bid cockpit', async ({ page }) => {
    await page.goto('/app/dashboard');

    await expect(page.getByTestId('home-module-cards')).toBeVisible();
    await expect(page.getByTestId('home-module-wiki')).toBeVisible();
    await expect(page.getByTestId('home-module-tenders')).toBeVisible();
    await expect(page.getByTestId('home-module-quality')).toBeVisible();

    // Nothing Anbud-specific: no pipeline, no bid-status sections, and no module navigation.
    const body = (await page.evaluate(() => document.body.innerText)).toLowerCase();
    for (const bidWidget of ['bid status', 'pipeline', 'krever oppfølging', 'resultater']) {
        expect(body, `"${bidWidget}" belongs to Anbud, not Hjem`).not.toContain(bidWidget);
    }
    await expect(page.getByTestId('module-navigation')).toHaveCount(0);
});

test('each module card opens its own module', async ({ page }) => {
    for (const [card, url, railLabel] of [
        ['home-module-wiki', /\/app\/wiki/, 'Wiki'],
        ['home-module-tenders', /\/app\/notices\?mode=saved/, 'Anbud'],
        ['home-module-quality', /\/app\/quality/, 'Kvalitet'],
    ]) {
        await page.goto('/app/dashboard');
        await page.getByTestId(card).click();

        await expect(page).toHaveURL(url);
        await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText(railLabel);
    }
});

test('Kvalitet is shown as being built, with no borrowed numbers', async ({ page }) => {
    await page.goto('/app/dashboard');

    await expect(page.getByTestId('home-module-quality-state')).toHaveText('Under arbeid');
    expect(await page.getByTestId('home-module-quality').locator('dl').count()).toBe(0);
});

test('the bid cockpit lives in Anbud now, as Bid Status', async ({ page }) => {
    await page.goto('/app/bid-status');

    await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText('Anbud');
    await expect(page.getByTestId('module-navigation').locator('[aria-current="page"]')).toHaveText('Bid Status');
    await expect(page.getByRole('heading', { level: 2 }).filter({ hasText: 'Krever oppfølging' })).toBeVisible();
});

test('both rows survive a phone, and nothing is pushed off the edge', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/app/notices');

    await expect(page.getByTestId('module-navigation')).toBeVisible();
    await expect(page.getByTestId('page-navigation')).toBeVisible();

    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );

    expect(overflow).toBeLessThanOrEqual(0);
});

test('dashboard page loads for authenticated customer user', async ({ page }) => {
    const response = await page.goto('/app/dashboard');

    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/app\/dashboard/);
});

test('AI workspace page loads without triggering external AI calls', async ({ page }) => {
    // The /app/ai index page only lists existing cases — no OpenAI calls are made
    const response = await page.goto('/app/ai');

    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/app\/ai/);
    await expect(page.locator('img[alt="Procynia"]')).toBeVisible();
});

test('regular customer user is blocked from the billing page', async ({ page }) => {
    const response = await page.goto('/app/billing');

    // BillingController: abort_unless($user->isSystemOwner(), 403)
    expect(response?.status()).toBe(403);
});

/**
 * The rail folds down to icons, and the page gets the space back.
 *
 * Three things have to survive the fold, and all three are checked below rather than assumed:
 * the selected module stays obviously selected, the planned modules stay obviously inert, and a
 * person who can no longer read a label can still find out what an icon is.
 */
test('the rail lists the workspaces in product order, with Styring holding what this user can open', async ({ page }) => {
    await page.goto('/app/dashboard');

    const rail = page.getByTestId('module-sidebar');

    expect(await labels(rail.locator(':scope > ul').first().locator(':scope > li > a, :scope > li > div > a')))
        .toEqual(['Hjem', 'Wiki', 'Anbud', 'Styring']);
    // This user holds quality.view and none of the others, so Styring holds Kvalitet only —
    // no dimmed or empty rows for the modules the virksomhet has but this person was not given.
    expect(await labels(page.getByTestId('module-governance-children').locator('a'))).toEqual(['Kvalitet']);
    for (const hidden of ['module-risk', 'module-objectives', 'module-improvements', 'module-compliance']) {
        await expect(page.getByTestId(hidden)).toHaveCount(0);
    }
});

test('each of the available modules is reachable from the rail', async ({ page }) => {
    await page.goto('/app/dashboard');

    for (const [key, label, url] of [
        ['module-wiki', 'Wiki', /\/app\/wiki/],
        ['module-tenders', 'Anbud', /\/app\/notices/],
        ['module-governance', 'Styring', /\/app\/governance$/],
        ['module-quality', 'Kvalitet', /\/app\/quality/],
        ['module-home', 'Hjem', /\/app\/dashboard/],
    ]) {
        await page.getByTestId(key).click();
        await expect(page).toHaveURL(url);
        await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText(label);
    }
});

test('collapsing the rail hands its width to the page, and expanding gives it back', async ({ page }) => {
    await page.goto('/app/wiki');

    const rail = page.getByTestId('module-rail');
    const sidebar = page.getByTestId('module-sidebar');
    const toggle = page.getByTestId('module-sidebar-toggle');

    await expect(sidebar).toHaveAttribute('data-collapsed', 'false');
    const expandedRail = (await rail.boundingBox()).width;
    const expandedMain = (await page.locator('main').boundingBox()).width;

    await toggle.click();

    await expect(sidebar).toHaveAttribute('data-collapsed', 'true');
    await expect(async () => {
        const collapsedRail = (await rail.boundingBox()).width;
        const collapsedMain = (await page.locator('main').boundingBox()).width;

        expect(collapsedRail).toBeLessThan(expandedRail - 100);
        expect(collapsedMain).toBeGreaterThan(expandedMain + 100);
    }).toPass();

    await toggle.click();
    await expect(sidebar).toHaveAttribute('data-collapsed', 'false');
});

test('a collapsed rail still shows which module you are in, and what the icons mean', async ({ page }) => {
    await page.goto('/app/wiki');
    await page.getByTestId('module-sidebar-toggle').click();

    const rail = page.getByTestId('module-sidebar');

    // Selected: exactly one module, and still the one the URL is in.
    await expect(rail.locator('[aria-current="page"]')).toHaveCount(1);
    await expect(rail.locator('[aria-current="page"]')).toHaveAttribute('data-testid', 'module-wiki');
    await expect(rail.locator('[aria-current="page"]')).toHaveClass(/bg-violet-50/);

    // The label is gone from the layout, so the icon carries a tooltip instead.
    await expect(page.getByTestId('module-wiki')).toHaveAttribute('title', 'Wiki');
    await expect(page.getByTestId('module-quality')).toHaveAttribute('title', 'Kvalitet');

    // Planned modules stay inert and dimmed, and say so on hover. (Risiko is built now; a built
    // module the person holds no permission in is left off the rail rather than dimmed, so a
    // module that is still planned is the one to check here.)
    await expect(page.getByTestId('module-contracts')).toHaveAttribute('aria-disabled', 'true');
    await expect(page.getByTestId('module-contracts')).toHaveClass(/text-slate-400/);
    await expect(page.getByTestId('module-contracts')).toHaveAttribute('title', /Kontrakter — /);

    // Expanding puts the labels back and drops the now-redundant tooltips.
    await page.getByTestId('module-sidebar-toggle').click();
    await expect(page.getByTestId('module-wiki')).not.toHaveAttribute('title', 'Wiki');
});

test('the rail comes back the way you left it', async ({ page }) => {
    await page.goto('/app/wiki');
    await page.getByTestId('module-sidebar-toggle').click();
    await expect(page.getByTestId('module-sidebar')).toHaveAttribute('data-collapsed', 'true');

    await page.goto('/app/notices');
    await expect(page.getByTestId('module-sidebar')).toHaveAttribute('data-collapsed', 'true');

    await page.reload();
    await expect(page.getByTestId('module-sidebar')).toHaveAttribute('data-collapsed', 'true');
});

test('a phone never gets a collapsed rail, and never gets a sideways scrollbar', async ({ page }) => {
    // Collapse on a wide screen first, then shrink: the stored preference must not follow the
    // person onto a phone, where the rail is the only module navigation there is.
    await page.goto('/app/notices');
    await page.getByTestId('module-sidebar-toggle').click();

    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload();

    await expect(page.getByTestId('module-sidebar-toggle')).toBeHidden();
    await expect(page.getByTestId('module-wiki')).toContainText('Wiki');
    // A module every E2E user reaches, rather than Risiko, which this user holds no permission in.
    await expect(page.getByTestId('module-tenders')).toContainText('Anbud');
    await expect(page.getByTestId('module-contracts')).toContainText('Kontrakter');

    const railWidth = (await page.getByTestId('module-rail').boundingBox()).width;
    expect(railWidth).toBeGreaterThan(200);

    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );

    expect(overflow).toBeLessThanOrEqual(0);
});

/**
 * Styring: an arbeidsområde over Kvalitet, Risiko, Mål og KPI and Avvik og forbedringer.
 *
 * It is derived from the four modules' own entitlement and permission, so the checks below change
 * the E2E user's roles (Tests\Support\NavigationE2EFixture) and look at what the rail and the
 * landing page then offer — never more than the modules themselves would.
 */
test.describe('Styring', () => {
    const suffix = Math.random().toString(36).slice(2, 8).padEnd(6, '0').toUpperCase();

    test.afterEach(async () => {
        await tinker(`\\Tests\\Support\\NavigationE2EFixture::cleanup('${suffix}');`);
    });

    const children = async (page) => labels(page.getByTestId('module-governance-children').locator('a'));

    test('a user given Risiko as well sees both, and the landing page offers the same two', async ({ page }) => {
        await tinker(`\\Tests\\Support\\NavigationE2EFixture::grant('${suffix}', ['risk.view']);`);
        await page.goto('/app/governance');

        expect(await children(page)).toEqual(['Kvalitet', 'Risiko']);
        expect(await labels(page.getByTestId('governance-module-cards').locator('h2'))).toEqual(['Kvalitet', 'Risiko']);

        // Navigation, not a dashboard: no numbers on the cards.
        const cards = await page.getByTestId('governance-module-cards').innerText();
        expect(cards).not.toMatch(/\d/);

        await page.getByTestId('governance-module-risk').click();
        await expect(page).toHaveURL(/\/app\/risk$/);
        await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText('Risiko');
    });

    test('Styring is lit as the workspace, and the module as the page, on index and detail pages alike', async ({ page }) => {
        const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\NavigationE2EFixture::improvementCase('${suffix}'));`);
        const { case_id: caseId } = JSON.parse(stdout.match(/\{.*\}/)[0]);
        const rail = page.getByTestId('module-sidebar');

        for (const [url, current] of [
            ['/app/quality', 'Kvalitet'],
            ['/app/quality?tab=processes', 'Kvalitet'],
            ['/app/improvements', 'Avvik og forbedringer'],
            [`/app/improvements/${caseId}`, 'Avvik og forbedringer'],
        ]) {
            await page.goto(url);
            await expect(rail.locator('[aria-current="page"]'), url).toHaveCount(1);
            await expect(rail.locator('[aria-current="page"]'), url).toHaveText(current);
            await expect(page.getByTestId('module-governance'), url).toHaveAttribute('data-active', 'true');
            await expect(page.getByTestId('module-governance'), url).toHaveClass(/text-violet-700/);
        }

        // On its own page Styring is the current page; elsewhere it is not lit at all.
        await page.goto('/app/governance');
        await expect(rail.locator('[aria-current="page"]')).toHaveAttribute('data-testid', 'module-governance');
        await page.goto('/app/wiki');
        await expect(page.getByTestId('module-governance')).toHaveAttribute('data-active', 'false');
    });

    test('on a phone Styring and its modules are all there, reachable, and nothing scrolls sideways', async ({ page }) => {
        await tinker(`\\Tests\\Support\\NavigationE2EFixture::grant('${suffix}', ['objective.view', 'improvement.view']);`);
        await page.setViewportSize(PHONE);
        await page.goto('/app/objectives');

        await expect(page.getByTestId('module-governance')).toBeVisible();
        await expect(page.getByTestId('module-governance')).toHaveAttribute('data-active', 'true');
        expect(await children(page)).toEqual(['Kvalitet', 'Mål og KPI', 'Avvik og forbedringer']);
        await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText('Mål og KPI');
        expect(await sidewaysOverflow(page)).toEqual([]);

        await page.getByTestId('module-improvements').click();
        await expect(page).toHaveURL(/\/app\/improvements$/);
        await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText('Avvik og forbedringer');

        await page.getByTestId('module-governance').click();
        await expect(page).toHaveURL(/\/app\/governance$/);
        expect(await sidewaysOverflow(page)).toEqual([]);
        await page.screenshot({ path: 'test-results/navigation-governance-phone.png', fullPage: true });
    });
});

test('System Owner sees all four under Styring, and they keep their own URLs', async ({ page }) => {
    await page.context().clearCookies();
    await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);
    await page.goto('/app/governance');

    expect(await labels(page.getByTestId('module-governance-children').locator('a')))
        .toEqual(['Kvalitet', 'Risiko', 'Mål og KPI', 'Avvik og forbedringer']);

    for (const [key, url] of [
        ['quality', /\/app\/quality$/],
        ['risk', /\/app\/risk$/],
        ['objectives', /\/app\/objectives$/],
        ['improvements', /\/app\/improvements$/],
    ]) {
        await page.goto('/app/governance');
        await page.getByTestId(`governance-module-${key}`).click();
        await expect(page).toHaveURL(url);
    }

    await page.goto('/app/governance');
    await page.screenshot({ path: 'test-results/navigation-governance-desktop.png', fullPage: true });
});

test('no visible text in the rail or the app shell is below 16 px', async ({ page }) => {
    for (const size of [DESKTOP, PHONE]) {
        await page.setViewportSize(size);
        await page.goto('/app/quality');

        const small = await page.evaluate(() => {
            const roots = [document.querySelector('[data-testid="module-rail"]'), document.querySelector('header'), document.querySelector('footer')].filter(Boolean);
            const found = [];

            for (const root of roots) {
                const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);

                while (walker.nextNode()) {
                    const text = walker.currentNode.textContent.trim();
                    const element = walker.currentNode.parentElement;

                    if (! text || ! element || element.closest('[aria-hidden="true"], .sr-only')) {
                        continue;
                    }

                    const rect = element.getBoundingClientRect();

                    if (rect.width === 0 && rect.height === 0) {
                        continue;
                    }

                    const fontSize = parseFloat(getComputedStyle(element).fontSize);

                    if (fontSize < 16) {
                        found.push(`${fontSize}px «${text.slice(0, 40)}»`);
                    }
                }
            }

            return found;
        });

        expect(small, `${size.width}px`).toEqual([]);
    }
});

/**
 * The navigation contract, in a browser: the rail picks workspace and module, the header names the
 * module's main areas, and a row on the page exists only for a real level below the active area.
 * Anbud → Kunngjøringer → Live søk / Varsler / Watch lists is the reference; Kvalitet used to draw
 * its header areas a second time on the page, and these hold that it no longer does.
 */
test.describe('one choice, one place', () => {
    const QUALITY_AREAS = ['Oversikt', 'Prosesser', 'Kontroller', 'Verktøy'];

    const expectQualityHierarchy = async (page, area) => {
        const rail = page.getByTestId('module-sidebar');

        await expect(page.getByTestId('module-governance')).toHaveAttribute('data-active', 'true');
        await expect(rail.locator('[aria-current="page"]')).toHaveText('Kvalitet');
        expect(await labels(page.getByTestId('module-navigation').locator('a, span'))).toEqual(QUALITY_AREAS);
        await expect(page.getByTestId('module-navigation').locator('[aria-current="page"]')).toHaveText(area);
    };

    const expectNoCopyOnThePage = async (page) => {
        // Each main area is offered once — in the header — and nowhere in the page content.
        for (const label of QUALITY_AREAS) {
            await expect(page.getByRole('link', { name: label, exact: true }), label).toHaveCount(1);
        }
        await expect(page.locator('main nav')).toHaveCount(0);
        await expect(page.locator('main a[href*="/app/quality?tab="]')).toHaveCount(0);
        await expect(page.getByTestId('page-navigation')).toHaveCount(0);
    };

    test('Styring → Kvalitet → each main area: lit at every level, offered once', async ({ page }) => {
        for (const [tab, area] of [['overview', 'Oversikt'], ['processes', 'Prosesser'], ['controls', 'Kontroller'], ['tools', 'Verktøy']]) {
            await page.goto(`/app/quality?tab=${tab}`);
            await expectQualityHierarchy(page, area);
            await expectNoCopyOnThePage(page);
        }

        // The page starts with its own title, not with another row of the same choices.
        await page.goto('/app/quality?tab=processes');
        await expect(page.locator('main > :not([data-testid="page-navigation"])').first().locator('h1')).toHaveText('Kvalitet');
    });

    test('a process page stays under Prosesser, and keeps its own Dokument / Flyt level', async ({ page }) => {
        const { stdout } = await tinker('echo \\App\\Models\\QualityItem::where(\'title\', \'E2E liten prosess\')->value(\'id\');');
        const id = stdout.trim().split('\n').pop();

        for (const url of [`/app/quality/items/${id}`, `/app/quality/items/${id}?tab=flow`]) {
            await page.goto(url);
            await expectQualityHierarchy(page, 'Prosesser');
        }

        await page.goto(`/app/quality/items/${id}`);
        await expect(page.locator('main nav').getByRole('link')).toHaveText(['Dokument', 'Flyt']);
    });

    test('Anbud → Kunngjøringer → Varsler: three levels, each with its own meaning', async ({ page }) => {
        await page.goto('/app/notices?tab=alerts');

        await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText('Anbud');
        await expect(page.getByTestId('module-governance')).toHaveAttribute('data-active', 'false');
        await expect(page.getByTestId('module-navigation').locator('[aria-current="page"]')).toHaveText('Kunngjøringer');
        await expect(page.getByTestId('page-navigation').locator('a, span')).toHaveText(['Live søk', 'Varsler', 'Watch lists']);
        await expect(page.getByTestId('page-navigation').locator('[aria-current="page"]')).toHaveText('Varsler');

        // None of the local choices is in the header, and none of the header's is local.
        const header = await labels(page.getByTestId('module-navigation').locator('a, span'));
        for (const local of ['Live søk', 'Varsler', 'Watch lists']) {
            expect(header).not.toContain(local);
        }
    });

    test('Risiko, Mål og KPI and Avvik og forbedringer get no made-up header row', async ({ page }) => {
        await page.context().clearCookies();
        await loginAs(page, SYSTEM_OWNER.email, SYSTEM_OWNER.password);

        for (const [url, current] of [['/app/risk', 'Risiko'], ['/app/objectives', 'Mål og KPI'], ['/app/improvements', 'Avvik og forbedringer']]) {
            await page.goto(url);
            await expect(page.getByTestId('module-governance'), url).toHaveAttribute('data-active', 'true');
            await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]'), url).toHaveText(current);
            await expect(page.getByTestId('module-navigation'), url).toHaveCount(0);
            await expect(page.getByTestId('page-navigation'), url).toHaveCount(0);
        }
    });

    test('on a phone the same levels show once each, and nothing scrolls sideways', async ({ page }) => {
        await page.setViewportSize(PHONE);

        await page.goto('/app/quality?tab=processes');
        await expectQualityHierarchy(page, 'Prosesser');
        await expectNoCopyOnThePage(page);
        expect(await sidewaysOverflow(page)).toEqual([]);
        await page.screenshot({ path: 'test-results/navigation-quality-processes-phone.png', fullPage: true });

        await page.goto('/app/notices?tab=alerts');
        await expect(page.getByTestId('module-navigation').locator('[aria-current="page"]')).toHaveText('Kunngjøringer');
        await expect(page.getByTestId('page-navigation').locator('[aria-current="page"]')).toHaveText('Varsler');
        expect(await sidewaysOverflow(page)).toEqual([]);
    });
});

/**
 * Package → modules → rail, and folding.
 *
 * Each case is a customer of its own (Tests\Support\NavigationE2EFixture::packageCustomer) whose one
 * person may view every module, so the only thing that differs between them is what the customer
 * bought. The packages are the real ones from config/procynia_modules.php — Basis, ISO, GRC, and
 * Anbud as an add-on; the full matrix (Styring included) is covered against the server in
 * tests/Feature/App/NavigationEntitlementMatrixTest.php.
 */
test.describe('the rail follows what the customer bought, and folds', () => {
    const suffix = Math.random().toString(36).slice(2, 8).padEnd(6, '0').toUpperCase();
    const password = 'E2eNav123!';

    test.afterEach(async () => {
        await tinker(`\\Tests\\Support\\NavigationE2EFixture::cleanup('${suffix}');`);
    });

    const seed = async (label, packages) => {
        const list = packages.map((key) => `'${key}'`).join(', ');
        const { stdout } = await tinker(`echo json_encode(\\Tests\\Support\\NavigationE2EFixture::packageCustomer('${suffix}', '${label}', [${list}], '${password}'));`);

        return JSON.parse(stdout.match(/\{.*\}/)[0]).email;
    };

    const loginAsSeeded = async (page, email) => {
        await page.context().clearCookies();
        await loginAs(page, email, password);
    };

    const topLevel = async (page) => labels(page.getByTestId('module-sidebar').locator(':scope > ul').first().locator(':scope > li > a, :scope > li > div > a'));
    const governanceChildren = async (page) => labels(page.getByTestId('module-governance-children').locator(':scope > li > div > a'));

    test('Basis, ISO, ISO + Anbud and GRC: each its own rail', async ({ page }) => {
        const iso = ['Kvalitet', 'Risiko', 'Mål og KPI', 'Avvik og forbedringer', 'Etterlevelse og revisjon'];
        const cases = [
            ['Basis', ['basis'], ['Hjem', 'Wiki', 'Styring'], ['Kvalitet', 'Avvik og forbedringer'], false],
            ['ISO', ['iso'], ['Hjem', 'Wiki', 'Styring'], iso, true],
            ['ISO Anbud', ['iso', 'tender'], ['Hjem', 'Wiki', 'Anbud', 'Styring'], iso, true],
            // GRC is ISO plus Leverandører, last under Styring.
            ['GRC', ['grc'], ['Hjem', 'Wiki', 'Styring'], [...iso, 'Leverandører'], true],
        ];

        for (const [label, packages, top, children, compliance] of cases) {
            await loginAsSeeded(page, await seed(label, packages));
            await page.goto('/app/dashboard');

            expect(await topLevel(page), label).toEqual(top);
            expect(await governanceChildren(page), label).toEqual(children);

            if (compliance) {
                await expect(page.getByTestId('module-compliance-areas').locator('a'), label).toHaveText(['Krav', 'Revisjoner']);
            } else {
                await expect(page.getByTestId('module-compliance'), label).toHaveCount(0);
            }

            // Not ordered is not shown — no dimmed Anbud, no «Ikke bestilt».
            await expect(page.getByTestId('module-sidebar'), label).not.toContainText('Ikke bestilt');
            if (! top.includes('Anbud')) {
                await expect(page.getByTestId('module-tenders'), label).toHaveCount(0);
            }

            // Only entries with something under them have a chevron.
            await expect(page.getByTestId('module-governance-toggle'), label).toBeVisible();
            await expect(page.getByTestId('module-compliance-toggle'), label).toHaveCount(compliance ? 1 : 0);
            await expect(page.getByTestId('module-sidebar').locator('button[aria-controls]'), label).toHaveCount(compliance ? 2 : 1);
        }
    });

    test('GRC: Leverandører opens from Styring, is the current page, and is readable with its help', async ({ page }) => {
        await loginAsSeeded(page, await seed('GRC Leverandorer', ['grc', 'tender']));
        await page.setViewportSize(DESKTOP);

        await page.goto('/app/governance');
        await page.getByTestId('governance-module-suppliers').click();
        await expect(page).toHaveURL(/\/app\/supplier-management$/);

        const rail = page.getByTestId('module-sidebar');
        await expect(page.getByRole('heading', { name: 'Leverandører', level: 1 })).toBeVisible();
        await expect(page.getByText('Leverandøroppfølging', { exact: true })).toBeVisible();
        await expect(page.getByText('Ingen leverandører er registrert ennå')).toBeVisible();
        await expect(rail.locator('[aria-current="page"]')).toHaveCount(1);
        await expect(rail.locator('[aria-current="page"]')).toHaveText('Leverandører');
        await expect(page.getByTestId('module-governance')).toHaveAttribute('data-active', 'true');
        // Anbud's own `suppliers` area (Konkurrenter) is a different place, and is not lit.
        await expect(page.getByTestId('module-tenders')).not.toHaveAttribute('aria-current', 'page');

        await expectPageHelp(page, 'Om leverandøroppfølging', ['Leverandørene', 'Status', 'Tilgang']);
        await expectReadable(page, 'supplier-management', '01-register');

        // A folded Styring opens itself when the page is reached directly.
        await page.getByTestId('module-governance-toggle').click();
        await expect(page.getByTestId('module-governance-children')).toBeHidden();
        await page.goto('/app/wiki');
        await page.goto('/app/supplier-management');
        await expect(page.getByTestId('module-governance-children')).toBeVisible();
    });

    test('Styring folds, stays folded after a reload, and opens itself for a page inside it', async ({ page }) => {
        await loginAsSeeded(page, await seed('ISO Anbud', ['iso', 'tender']));
        await page.goto('/app/wiki');

        const toggle = page.getByTestId('module-governance-toggle');
        const children = page.getByTestId('module-governance-children');

        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expect(children).toBeVisible();

        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(children).toBeHidden();
        await expect(page.getByTestId('module-governance')).toBeVisible();

        await page.reload();
        await expect(page.getByTestId('module-governance-toggle')).toHaveAttribute('aria-expanded', 'false');
        await expect(page.getByTestId('module-governance-children')).toBeHidden();

        // Straight to Revisjoner: both parents open, and the page is the current one.
        await page.goto('/app/compliance/audits');
        await expect(page.getByTestId('module-governance-children')).toBeVisible();
        await expect(page.getByTestId('module-compliance-areas')).toBeVisible();
        await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText('Revisjoner');
        await expect(page.getByTestId('module-compliance')).toHaveAttribute('data-active', 'true');
        await page.screenshot({ path: 'test-results/navigation-folding-audits.png', fullPage: true });
    });

    test('Etterlevelse og revisjon folds on its own; the name still navigates', async ({ page }) => {
        await loginAsSeeded(page, await seed('ISO', ['iso']));
        await page.goto('/app/quality');

        const toggle = page.getByTestId('module-compliance-toggle');

        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(page.getByTestId('module-compliance-areas')).toBeHidden();
        await expect(page.getByTestId('module-governance-children')).toBeVisible();
        await expect(page).toHaveURL(/\/app\/quality$/);

        await toggle.click();
        await expect(page.getByTestId('module-compliance-areas')).toBeVisible();

        await page.getByTestId('module-compliance').click();
        await expect(page).toHaveURL(/\/app\/compliance\/requirements$/);
        await expect(page.getByTestId('module-sidebar').locator('[aria-current="page"]')).toHaveText('Krav');
    });

    test('on a phone the folding rail fits without a sideways scrollbar', async ({ page }) => {
        await loginAsSeeded(page, await seed('ISO Anbud', ['iso', 'tender']));
        await page.setViewportSize(PHONE);
        await page.goto('/app/compliance/requirements');

        await expect(page.getByTestId('module-compliance-areas')).toBeVisible();
        await expect(page.getByTestId('module-governance-toggle')).toBeVisible();
        expect(await sidewaysOverflow(page)).toEqual([]);

        await page.getByTestId('module-governance-toggle').click();
        await expect(page.getByTestId('module-governance-children')).toBeHidden();
        expect(await sidewaysOverflow(page)).toEqual([]);
        await page.screenshot({ path: 'test-results/navigation-folding-phone.png', fullPage: true });
    });
});
