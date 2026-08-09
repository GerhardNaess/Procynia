import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import * as wikiQualityChecks from './wikiQualityChecks.js';

const here = dirname(fileURLToPath(import.meta.url));
const read = (file) => readFileSync(join(here, file), 'utf8');

/**
 * The Kvalitet tab rendered as a blank content area while every other Wiki tab worked: Index.jsx
 * called getQualityCheckCopy(), but wikiQualityChecks only ever exported it as
 * getWikiQualityCheckCopy(). An undefined identifier is not a build error — Vite bundles it
 * happily — it throws a ReferenceError the moment the component using it renders, which unmounts
 * the whole React tree and leaves the tab empty. Only QualityTab called it, so only that tab broke.
 *
 * These are source-level guards rather than render tests: the project has no JSX test renderer,
 * and this bug class is precisely what a renderer would be needed to catch otherwise.
 */
describe('wikiQualityChecks usage in the Wiki pages', () => {
    const pages = ['Index.jsx', 'Show.jsx'];

    test('every helper imported from wikiQualityChecks is actually exported by it', () => {
        for (const page of pages) {
            const source = read(page);
            const importBlock = source.match(/import\s*\{([^}]*)\}\s*from\s*'\.\/wikiQualityChecks'/);

            if (!importBlock) {
                continue;
            }

            const imported = importBlock[1]
                .split(',')
                .map((name) => name.trim())
                .filter(Boolean);

            assert.ok(imported.length > 0, `${page} imports nothing usable`);

            for (const name of imported) {
                assert.equal(
                    typeof wikiQualityChecks[name],
                    'function',
                    `${page} imports ${name}, which wikiQualityChecks does not export`,
                );
            }
        }
    });

    test('the quality-check helper is never called under a name that does not exist', () => {
        for (const page of pages) {
            const source = read(page);

            // The exact regression: a call to getQualityCheckCopy( that is not the exported
            // getWikiQualityCheckCopy( — matched by requiring a non-word char before it.
            assert.equal(
                /(^|[^\w])getQualityCheckCopy\s*\(/.test(source),
                false,
                `${page} calls getQualityCheckCopy(), which is not exported — use getWikiQualityCheckCopy()`,
            );
        }
    });

    test('the best-practice secondary text is dropped when it repeats the title', () => {
        const text = 'Hver endringssak skal inneholde eksplisitt risikovurdering, testgrunnlag med '
            + 'verifiserbare resultater og en tilbakeføringsplan.';

        assert.equal(wikiQualityChecks.resolveWikiFindingSecondaryText(text, text), null);
    });

    test('identical text differing only in whitespace still counts as a repeat', () => {
        const title = 'Hver endringssak skal inneholde eksplisitt risikovurdering.';
        const sectionText = '  Hver endringssak skal   inneholde eksplisitt\nrisikovurdering.  ';

        assert.equal(wikiQualityChecks.resolveWikiFindingSecondaryText(title, sectionText), null);
    });

    test('a section text that adds something beyond the title is kept verbatim', () => {
        const title = 'Begrepsramme: ITIL og Incident management';
        const sectionText = 'Hver endringssak skal inneholde eksplisitt risikovurdering. '
            + 'Tilbakeføringsplanen skal være testet.';

        assert.equal(
            wikiQualityChecks.resolveWikiFindingSecondaryText(title, sectionText),
            sectionText,
            'the original string is returned untouched — normalization is only used for comparison',
        );
    });

    test('an empty, blank or missing section text renders nothing', () => {
        for (const value of ['', '   ', null, undefined]) {
            assert.equal(wikiQualityChecks.resolveWikiFindingSecondaryText('Tittel', value), null);
        }
    });

    test('getWikiQualityCheckCopy resolves a known code and degrades safely for an unknown one', () => {
        const known = wikiQualityChecks.getWikiQualityCheckCopy('page_without_claims', {
            quality_checks: { page_without_claims: { label: 'Side mangler påstander', description: 'Beskrivelse.' } },
        });

        assert.equal(known.label, 'Side mangler påstander');
        assert.equal(known.unknown, false);

        const unknown = wikiQualityChecks.getWikiQualityCheckCopy('not_a_real_code', {});

        assert.equal(unknown.unknown, true);
        assert.ok(unknown.label.includes('not_a_real_code'), 'an unknown code still names itself');
    });
});
