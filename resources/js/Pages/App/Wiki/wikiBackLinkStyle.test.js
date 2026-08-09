import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(join(here, 'Show.jsx'), 'utf8');

/**
 * "Tilbake til funn" is a real navigation action, not breadcrumb text, and used to render as a
 * faint grey link that read as incidental. It now uses the page's own primary-action button style.
 *
 * These are source-level guards rather than render tests (the project has no JSX test renderer):
 * what actually needs protecting is that the style stays SHARED — a future edit that hand-copies
 * a slightly different violet, or introduces a new shade, is the regression, not a visual diff.
 */
describe('the "Tilbake til funn" button reuses Procynia\'s existing violet action style', () => {
    const buttonClass = source.match(/const WIKI_FINDING_BACK_BUTTON_CLASS =\s*\n?\s*'([^']*)'/)?.[1];

    test('the button style is declared once as a shared constant', () => {
        assert.ok(buttonClass, 'WIKI_FINDING_BACK_BUTTON_CLASS must exist as a single named constant');

        // Both finding back links (top of page, and the one by the finding context panel) use it.
        const usages = source.match(/WIKI_FINDING_BACK_BUTTON_CLASS/g) ?? [];

        assert.ok(usages.length >= 3, 'the constant must be declared once and used by both back links');
    });

    test('it carries the same violet tokens as the page\'s other primary buttons', () => {
        // Exactly the tokens on "Send til gjennomgang" / "Lagre endring" in this same file.
        for (const token of ['bg-violet-600', 'hover:bg-violet-700', 'text-white', 'font-semibold', 'rounded-full']) {
            assert.ok(buttonClass.includes(token), `missing shared token: ${token}`);
        }
    });

    test('it introduces no new colour value or violet shade', () => {
        assert.equal(/#[0-9a-fA-F]{3,8}\b/.test(buttonClass), false, 'no raw hex colour');
        assert.equal(/rgba?\(/.test(buttonClass), false, 'no raw rgb colour');

        const shades = [...buttonClass.matchAll(/violet-(\d+)/g)].map((m) => m[1]);
        const allowed = new Set(['600', '700']);

        for (const shade of shades) {
            assert.ok(allowed.has(shade), `violet-${shade} is not one of the shades this page already uses`);
        }
    });

    test('it keeps a visible focus-visible state', () => {
        assert.ok(buttonClass.includes('focus-visible:outline'), 'focus ring is required for keyboard users');
        assert.ok(buttonClass.includes('focus-visible:outline-violet-700'), 'uses the Wiki module\'s existing focus ring');
    });

    test('icon and text stay vertically centred, and the icon never squashes', () => {
        assert.ok(buttonClass.includes('inline-flex'));
        assert.ok(buttonClass.includes('items-center'));
        assert.ok(buttonClass.includes('gap-'), 'icon and label need spacing between them');
        assert.ok(source.includes('h-4 w-4 shrink-0'), 'the arrow icon must not shrink on narrow screens');
    });

    test('it is no larger than the page\'s other action buttons', () => {
        // "Send til gjennomgang" is min-h-9 / px-4 py-2 / text-sm — the button must not exceed it.
        assert.ok(buttonClass.includes('min-h-9'));
        assert.ok(buttonClass.includes('px-4'));
        assert.ok(buttonClass.includes('py-2 '), 'py-2, not a taller variant');
        assert.ok(buttonClass.includes('text-sm'));
    });

    test('"Tilbake til Wiki" is untouched and stays visually subordinate', () => {
        const secondary = source.match(/const WIKI_SECONDARY_BACK_LINK_CLASS =\s*\n?\s*'([^']*)'/)?.[1];

        assert.ok(secondary, 'ordinary Wiki navigation keeps its own style');
        assert.equal(secondary.includes('bg-violet-600'), false, 'plain navigation must not become a primary button');
        assert.ok(secondary.includes('text-slate-500'), 'unchanged from the original discreet link');
    });

    test('the destination is still driven by the deep-link context, not by the styling', () => {
        // The regression to prevent: someone "simplifying" the conditional class by also
        // hardcoding the href, which would silently kill the finding deep link.
        assert.ok(
            /href=\{topBackLink\.href\}/.test(source),
            'the top link must still resolve its href from resolveWikiBackLink()',
        );
        assert.ok(/href=\{backHref\}/.test(source), 'the finding-context link must still use backHref');
    });
});
