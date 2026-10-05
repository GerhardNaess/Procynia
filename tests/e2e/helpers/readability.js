import { expect } from '@playwright/test';

export const DESKTOP = { width: 1440, height: 900 };
export const PHONE = { width: 390, height: 844 };

/**
 * Every piece of visible text in the page's own content (and an open help panel) set below 16 px.
 * The shared header and module menu are not the module's and are left out.
 */
export async function textBelow16px(page) {
    return page.evaluate(() => {
        const roots = [document.querySelector('main'), ...document.querySelectorAll('[role="dialog"]')].filter(Boolean);
        const found = [];

        for (const root of roots) {
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);

            while (walker.nextNode()) {
                const text = walker.currentNode.textContent.trim();
                const element = walker.currentNode.parentElement;

                if (! text || ! element || element.closest('[aria-hidden="true"], .sr-only, option')) {
                    continue;
                }

                const rect = element.getBoundingClientRect();

                if (rect.width === 0 && rect.height === 0) {
                    continue;
                }

                const size = parseFloat(getComputedStyle(element).fontSize);

                if (size < 16) {
                    found.push(`${size}px «${text.slice(0, 60)}»`);
                }
            }
        }

        return found;
    });
}

/**
 * Whether the whole page scrolls sideways, and if so what reaches past the window. Wide tables may
 * scroll inside their own box, but nothing — not even a screen-reader label — may widen the page.
 */
export async function sidewaysOverflow(page) {
    return page.evaluate(() => {
        if (document.documentElement.scrollWidth <= window.innerWidth + 1) {
            return [];
        }

        return [`page is ${document.documentElement.scrollWidth}px wide`, ...[...document.querySelectorAll('main *')]
            .filter((element) => element.getBoundingClientRect().right > window.innerWidth + 1)
            .slice(-5)
            .map((element) => `${element.tagName.toLowerCase()}.${String(element.className).split(' ').slice(0, 4).join('.')}`)];
    });
}

/**
 * Checks the page at desktop and phone width — no text under 16 px, no sideways scrolling — and
 * leaves it at desktop width. Screenshots go to test-results/<prefix>-<name>-<width>.png.
 */
export async function expectReadable(page, prefix, name) {
    for (const [label, size] of [['desktop', DESKTOP], ['phone', PHONE]]) {
        await page.setViewportSize(size);
        expect(await textBelow16px(page), `${name} (${label})`).toEqual([]);
        expect(await sidewaysOverflow(page), `${name} (${label}) scrolls sideways`).toEqual([]);
        await page.screenshot({ path: `test-results/${prefix}-${name}-${label}.png`, fullPage: true });
    }

    await page.setViewportSize(DESKTOP);
}

/** Opens the page's help, checks it has the expected sections and is readable, and closes it. */
export async function expectPageHelp(page, title, sectionTitles) {
    await page.getByRole('button', { name: 'Hjelp', exact: true }).click();
    const panel = page.getByRole('dialog', { name: title });
    await expect(panel).toBeVisible();

    for (const sectionTitle of sectionTitles) {
        await expect(panel.getByRole('heading', { name: sectionTitle, exact: true })).toBeVisible();
    }

    expect(await textBelow16px(page), `help «${title}»`).toEqual([]);
    await page.keyboard.press('Escape');
    await expect(panel).toHaveCount(0);
}
