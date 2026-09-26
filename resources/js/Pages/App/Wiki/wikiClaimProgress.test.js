import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(join(here, 'Index.jsx'), 'utf8');
const lang = (locale) => readFileSync(join(here, '..', '..', '..', '..', '..', 'lang', locale, 'procynia.php'), 'utf8');

/**
 * The Pages tab used to show a bare claim total — how much there was to check, and nothing about
 * how much had been checked. It now reads "2 av 10".
 *
 * Source-level guards, in the same style as the other Wiki tests: the project has no JSX test
 * renderer, and what needs protecting is that the cell keeps showing both numbers.
 */
describe('the claims column shows quality assurance progress', () => {
    /**
     * "Godkjent" is what a page gets when it is published, and what a claim gets from quality
     * assurance — two different decisions sharing one word. The claim number says how far the
     * quality work has come, so it says that.
     */
    test('the header says what the number means', () => {
        assert.match(source, /tw\.claims_approved_column \?\? 'Påstander kvalitetssikret'/);
        assert.match(lang('no'), /'claims_approved_column' => 'Påstander kvalitetssikret',/);
        assert.match(lang('en'), /'claims_approved_column' => 'Claims quality assured',/);
    });

    test('the cell renders approved out of total', () => {
        assert.match(source, /tw\.claims_approved_of_total \?\? ':approved av :total'/);
        assert.match(source, /\.replace\(':approved', page\.claims_approved_count \?\? 0\)/);
        assert.match(source, /\.replace\(':total', page\.claims_count \?\? 0\)/);
    });

    test('both languages phrase the progress in their own words', () => {
        assert.match(lang('no'), /'claims_approved_of_total' => ':approved av :total',/);
        assert.match(lang('en'), /'claims_approved_of_total' => ':approved of :total',/);
    });

    /**
     * A page with nothing to check reads "0 av 0" rather than an empty cell: the nullish defaults
     * make a missing count render as a number, not as "undefined av undefined".
     */
    test('a missing count still renders as a number', () => {
        const render = (approved, total) => ':approved av :total'
            .replace(':approved', approved ?? 0)
            .replace(':total', total ?? 0);

        assert.equal(render(undefined, undefined), '0 av 0');
        assert.equal(render(0, 10), '0 av 10');
        assert.equal(render(2, 10), '2 av 10');
        assert.equal(render(10, 10), '10 av 10');
    });

    /** The lowercase noun is a different string, used as "10 påstander" elsewhere on the page. */
    test('the column heading did not take over the plain noun', () => {
        assert.match(lang('no'), /'claims' => 'påstander',/);
        assert.match(source, /\{page\.claims_count\} \{tw\.claims \?\? 'påstander'\}/);
    });
});
