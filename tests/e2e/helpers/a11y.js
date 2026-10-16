import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';

/**
 * Accessibility checks for the E2E suite (docs/wcag-accessibility-plan.md §9).
 *
 * axe is scoped to what a phase has made accessible — a dialog, a field, a panel — rather than run
 * over whole pages whose later phases have not happened yet. Scoping is how coverage grows; no rule is
 * ever disabled to make a check pass. axe finds only part of what WCAG asks, so keyboard and focus
 * behaviour is asserted separately, below and in the specs.
 */

export const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

/** axe violations (WCAG 2.2 A/AA rules) inside `include`, one readable line each. */
export async function axeViolations(page, include) {
    let builder = new AxeBuilder({ page }).withTags(WCAG_TAGS);

    if (include) {
        builder = builder.include(include);
    }

    const { violations } = await builder.analyze();

    return violations.map((violation) => `${violation.id} (${violation.impact}): ${violation.help} — ${violation.nodes
        .slice(0, 3)
        .map((node) => node.target.join(' '))
        .join(' | ')}`);
}

export async function expectNoAxeViolations(page, include, label = include) {
    expect(await axeViolations(page, include), `axe: ${label}`).toEqual([]);
}

/**
 * The focus indicator the global rule in resources/css/app.css draws, as the browser computed it for
 * `locator` (which must have focus): style, width, offset, and whether its colour is violet-600.
 */
export async function focusIndicator(locator) {
    return locator.evaluate(async (element) => {
        // Controls with `transition` animate outline-color too; read the colour once it has settled.
        await Promise.all(element.getAnimations().map((animation) => animation.finished));
        const style = getComputedStyle(element);
        const probe = document.createElement('span');
        probe.style.color = 'var(--color-violet-600, #7f22fe)';
        document.body.appendChild(probe);
        const violet600 = getComputedStyle(probe).color;
        probe.remove();

        return {
            focusVisible: element.matches(':focus-visible'),
            style: style.outlineStyle,
            width: style.outlineWidth,
            offset: style.outlineOffset,
            isViolet600: style.outlineColor === violet600,
        };
    });
}

/** Asserts the app-wide keyboard focus indicator: a 2 px solid violet-600 outline. */
export async function expectFocusIndicator(locator, { offset = '2px' } = {}) {
    await expect(locator).toBeFocused();
    expect(await focusIndicator(locator)).toEqual({ focusVisible: true, style: 'solid', width: '2px', offset, isViolet600: true });
}
