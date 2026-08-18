import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import * as wikiQualityChecks from './wikiQualityChecks.js';

const here = dirname(fileURLToPath(import.meta.url));
const read = (file) => readFileSync(join(here, file), 'utf8');

/**
 * Reads the wiki.* block straight out of the PHP language file, so the presentation tests fail when
 * a finding type exists without its human copy — not just when the helper's own logic breaks.
 */
function readTranslations(locale) {
    const php = readFileSync(join(here, `../../../../../lang/${locale}/procynia.php`), 'utf8');
    const quality_checks = {};

    const checksBlock = php.slice(php.indexOf("'quality_checks' => ["));
    const checkPattern = /'([a-z0-9_]+)' => \[\s*'label' => '((?:[^'\\]|\\.)*)',\s*'description' => '((?:[^'\\]|\\.)*)',/g;
    let match = checkPattern.exec(checksBlock);

    while (match) {
        quality_checks[match[1]] = { label: match[2], description: match[3] };
        match = checkPattern.exec(checksBlock);
    }

    const actionsBlock = php.slice(php.indexOf("'quality_check_actions' => ["));
    const quality_check_actions = {};
    const actionPattern = /'([a-z_]+)' => '((?:[^'\\]|\\.)*)',/g;
    let actionMatch = actionPattern.exec(actionsBlock.slice(0, actionsBlock.indexOf('],')));

    while (actionMatch) {
        quality_check_actions[actionMatch[1]] = actionMatch[2];
        actionMatch = actionPattern.exec(actionsBlock.slice(0, actionsBlock.indexOf('],')));
    }

    return { quality_checks, quality_check_actions };
}

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
            quality_check_actions: { source_document: 'Kontroller kildedokumentet.' },
        });

        assert.equal(known.label, 'Side mangler påstander');
        assert.equal(known.unknown, false);
        assert.equal(known.action, 'Kontroller kildedokumentet.');

        const unknown = wikiQualityChecks.getWikiQualityCheckCopy('not_a_real_code', {
            quality_check_unknown_label: 'Kvalitetsproblem på Wiki-siden',
            quality_check_unknown_description: 'Ingen egen forklaring ennå.',
            quality_check_actions: { unknown: 'Kontakt systemansvarlig.' },
        });

        assert.equal(unknown.unknown, true);
        assert.equal(unknown.label, 'Kvalitetsproblem på Wiki-siden');
        assert.equal(
            unknown.label.includes('not_a_real_code'),
            false,
            'the raw lint code must never be the user-facing headline',
        );
        assert.equal(unknown.description.includes('not_a_real_code'), false);
        assert.equal(unknown.action, 'Kontakt systemansvarlig.');
        assert.equal(unknown.code, 'not_a_real_code', 'the code stays available as a discreet technical reference');
    });

    test('every known finding type resolves a title, an explanation and a concrete action', () => {
        const no = readTranslations('no');

        for (const code of Object.keys(wikiQualityChecks.WIKI_QUALITY_CHECK_REMEDIES)) {
            const copy = wikiQualityChecks.getWikiQualityCheckCopy(code, no);

            assert.equal(copy.unknown, false, `${code} has no human presentation in lang/no`);
            assert.ok(copy.label.trim().length > 0, `${code} has no title`);
            assert.ok(copy.description.trim().length > 0, `${code} has no explanation`);
            assert.ok(copy.action.trim().length > 0, `${code} has no recommended action`);
            assert.equal(copy.label.includes(code), false, `${code} leaks its technical code into the title`);
        }
    });

    test('the two planned-figure findings from the reported page read as plain language', () => {
        for (const locale of ['no', 'en']) {
            const tw = readTranslations(locale);

            for (const code of ['planned_figure_missing', 'planned_figure_wrong_section']) {
                const copy = wikiQualityChecks.getWikiQualityCheckCopy(code, tw);

                assert.equal(copy.unknown, false, `${code} is unknown in ${locale}`);
                assert.equal(copy.remedy, 'source_document');
                assert.ok(copy.action.trim().length > 0, `${code} has no action in ${locale}`);
            }
        }
    });

    test('claim findings and page-quality findings are split apart', () => {
        const { claimFindings, structuralFindings } = wikiQualityChecks.splitWikiVerificationFindings([
            { id: 1, code: 'claim_missing_source' },
            { id: 2, code: 'source_reference_without_document' },
            { id: 3, code: 'planned_figure_missing' },
        ]);

        assert.deepEqual(claimFindings.map((f) => f.id), [1, 2]);
        assert.deepEqual(structuralFindings.map((f) => f.id), [3]);
    });
});
