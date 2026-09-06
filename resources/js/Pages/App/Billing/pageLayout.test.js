import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const pages = join(dirname(fileURLToPath(import.meta.url)), '..');
const billing = readFileSync(join(pages, 'Billing', 'Index.jsx'), 'utf8');
const reference = readFileSync(join(pages, 'CustomerEnvironment', 'Index.jsx'), 'utf8');

/** The classes on the single wrapper the page puts directly inside CustomerAppLayout. */
function pageWrapper(source) {
    const at = source.indexOf('<CustomerAppLayout');
    assert.notEqual(at, -1, 'the page no longer uses the shared layout');

    const match = source.slice(at).match(/<div className="([^"]+)">/);
    assert.notEqual(match, null, 'the page wrapper is no longer recognisable');

    return match[1];
}

/**
 * Kundemiljø is the house page layout: CustomerAppLayout renders the title and owns the container,
 * so a page contributes only its own header section and content. Billing had grown a second
 * container inside <main>, which narrowed it, doubled the top padding and pushed its left edge in.
 *
 * These compare structure against the reference rather than asserting measurements, so a deliberate
 * change to the shared layout updates both sides at once instead of failing on a pixel.
 */
describe('the subscription page uses the shared page layout', () => {
    test('the layout renders the title, so the page does not repeat it', () => {
        assert.match(billing, /<CustomerAppLayout[^>]*showPageTitle=\{false\}/s);
        assert.match(reference, /<CustomerAppLayout[^>]*showPageTitle=\{false\}/s);
    });

    test('there is exactly one page heading', () => {
        assert.equal((billing.match(/<h1\b/g) ?? []).length, 1);
    });

    test('it adds no container of its own — <main> already is one', () => {
        // A nested mx-auto/max-w wrapper is what made the content start further in than Kundemiljø.
        assert.ok(!/mx-auto|max-w-\d/.test(pageWrapper(billing)), 'the page wrapper must not re-center or re-limit the content');
        assert.ok(!/px-\d|py-\d/.test(pageWrapper(billing)), 'the page wrapper must not add its own page padding');
    });

    test('the outer wrapper spaces its sections the same way the reference does', () => {
        assert.equal(pageWrapper(billing), pageWrapper(reference));
    });
});

describe('the header block matches the reference', () => {
    /** The header section: the title row plus the intro paragraphs beneath it. */
    function header(source) {
        const start = source.indexOf('<section className="space-y-1.5">');
        assert.notEqual(start, -1, 'the header section is no longer recognisable');

        return source.slice(start, source.indexOf('</section>', start));
    }

    test('help sits beside the title, not pushed to the far edge', () => {
        for (const [name, source] of [['billing', billing], ['reference', reference]]) {
            const row = header(source);

            assert.match(row, /<div className="flex items-center gap-3">/, `${name}: title row`);
            assert.ok(!row.includes('justify-between'), `${name}: help must not be pushed away from the title`);
        }
    });

    test('the title carries the same weight as every other page title', () => {
        const heading = (source) => header(source).match(/<h1 className="([^"]+)"/)[1];

        assert.equal(heading(billing), heading(reference));
    });

    test('the page help button is part of the title row', () => {
        const row = header(billing);

        assert.ok(row.indexOf('<PageHelpButton') < row.indexOf('</div>'), 'help belongs inside the title row');
    });

    test('the intro text sits directly under the title, in the reference measure', () => {
        const intros = [...header(billing).matchAll(/<p className="([^"]+)">/g)].map(([, classes]) => classes);
        const referenceIntro = header(reference).match(/<p className="([^"]+)">/)[1];

        assert.notEqual(intros.length, 0, 'the intro text is gone');

        for (const intro of intros) {
            assert.equal(intro, referenceIntro);
        }
    });
});

describe('nothing but the framing moved', () => {
    test('the cards and their grids are untouched', () => {
        for (const card of ['SummaryCard', 'AiQuotaCard', 'ConfirmDialog']) {
            assert.ok(billing.includes(`<${card}`), `${card} should still be rendered`);
        }

        assert.match(billing, /<section className="grid gap-4 md:grid-cols-2">/);
    });

    test('the overlays keep their own widths, which the page container never set', () => {
        // The dialog and the plan-change modal are fixed overlays, unaffected by the page wrapper.
        assert.match(billing, /w-full max-w-md/);
        assert.match(billing, /w-full max-w-4xl/);
    });

    test('flash messages still render above the content', () => {
        assert.match(billing, /flash\?\.success/);
        assert.match(billing, /flash\?\.error/);
        assert.ok(billing.indexOf('flash?.success') < billing.indexOf('<h1'), 'flash stays at the top');
    });
});
