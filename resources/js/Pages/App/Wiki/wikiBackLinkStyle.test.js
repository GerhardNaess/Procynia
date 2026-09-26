import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(join(here, 'Show.jsx'), 'utf8');
const actionStyles = readFileSync(join(here, '../../../Support/actionStyles.js'), 'utf8');

/**
 * "Tilbake til funn" is a real navigation action, not breadcrumb text, and used to render as a
 * faint grey link that read as incidental. It now uses the page's own primary-action style.
 *
 * These are source-level guards rather than render tests (the project has no JSX test renderer):
 * what needs protecting is that the style stays SHARED. Originally that meant listing the violet
 * tokens the button was expected to carry — but those tokens have since moved into
 * Support/actionStyles.js, which is now the one place deciding what a primary action looks like,
 * and the palette it settled on is a violet tint rather than a filled violet-600. Freezing the old
 * token list here would only make this file argue with that decision, so the guards now assert what
 * actually matters: the button COMPOSES the shared role instead of hand-copying colours.
 */
describe('the "Tilbake til funn" button reuses Procynia\'s shared primary action style', () => {
    const buttonClass = source.match(/const WIKI_FINDING_BACK_BUTTON_CLASS =\s*\n?\s*`([^`]*)`/)?.[1];

    test('the button style is declared once as a shared constant', () => {
        assert.ok(buttonClass, 'WIKI_FINDING_BACK_BUTTON_CLASS must exist as a single named constant');

        // Both finding back links (top of page, and the one by the finding context panel) use it.
        const usages = source.match(/WIKI_FINDING_BACK_BUTTON_CLASS/g) ?? [];

        assert.ok(usages.length >= 3, 'the constant must be declared once and used by both back links');
    });

    test('its colours come from the shared primary role, not from this file', () => {
        assert.match(buttonClass, /\$\{PRIMARY_COLOURS\}/);
        assert.match(source, /import \{ PRIMARY_COLOURS \} from '\.\.\/\.\.\/\.\.\/Support\/actionStyles'/);
    });

    test('it introduces no colour of its own', () => {
        // Anything colour-like left once the shared role is removed is a hand-copied token.
        const local = buttonClass.replace(/\$\{[^}]*\}/g, '');

        assert.equal(/#[0-9a-fA-F]{3,8}\b/.test(local), false, 'no raw hex colour');
        assert.equal(/rgba?\(/.test(local), false, 'no raw rgb colour');
        assert.equal(/\b(bg|text|border|outline)-[a-z]+-\d{2,3}\b/.test(local), false, 'no local colour tokens');
    });

    test('it keeps a visible focus-visible state', () => {
        // Carried by the shared role now, so the guard belongs on the role.
        const primary = actionStyles.match(/export const PRIMARY_COLOURS = '([^']*)'/)?.[1] ?? '';

        assert.ok(primary, 'the primary role must stay a single named constant');
        assert.ok(primary.includes('focus-visible:outline'), 'focus ring is required for keyboard users');
        assert.match(primary, /focus-visible:outline-violet-\d{3}/, 'and it stays the violet ring');
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
        assert.equal(/\bbg-violet-\d{2,3}\b/.test(secondary), false, 'plain navigation must not become a primary button');
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
