import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(join(here, 'WatchProfileForm.jsx'), 'utf8');
const createSource = readFileSync(join(here, 'Create.jsx'), 'utf8');
const editSource = readFileSync(join(here, 'Edit.jsx'), 'utf8');

/**
 * Picking CPV codes used to be code-first: add an empty rule row, type a code into it, and read the
 * catalog description back from a separate "Forklaring" field. Users think description-first, so
 * the form now searches the catalog in plain language and adds whole codes from the results.
 *
 * The backend contract is unchanged — cpv_codes is still [{ cpv_code, weight }] — so these are
 * source-level guards for the interaction, which the project has no JSX renderer to assert.
 */
describe('the CPV field is search-first', () => {
    test('there is a search input, described in plain language', () => {
        assert.match(source, /Søk etter CPV/);
        assert.match(source, /placeholder="Søk etter f\.eks\. nettverk, IT-drift eller datasenter"/);
    });

    test('the lookup is driven by the query, not by a row being focused', () => {
        assert.match(source, /url\.searchParams\.set\('query', trimmedCpvQuery\)/);
        assert.ok(!source.includes('activeCpvIndex'), 'no row-focus state should remain');
    });

    test('already-selected codes are excluded from the search server-side', () => {
        assert.match(source, /url\.searchParams\.set\('selected'/);
    });

    test('a result renders its name, its code and an add action', () => {
        const results = source.slice(source.indexOf('cpvSuggestions.map'));

        assert.match(results, /\{suggestion\.label\}/, 'the catalog name is shown');
        assert.match(results, /\{suggestion\.code\}/, 'the code is shown');
        assert.match(results, /onClick=\{\(\) => addCpvCode\(suggestion\)\}/);
    });
});

describe('duplicates', () => {
    test('adding a code twice is refused in the handler, not just in the view', () => {
        const handler = source.slice(source.indexOf('const addCpvCode'), source.indexOf('const setCpvWeight'));

        assert.match(handler, /if \(selectedCodes\.has\(suggestion\.code\)\) \{\s*return;/);
    });

    test('the add button says so and is disabled', () => {
        assert.match(source, /const alreadySelected = selectedCodes\.has\(suggestion\.code\);/);
        assert.match(source, /disabled=\{alreadySelected\}/);
        assert.match(source, /alreadySelected \? 'Allerede valgt' : 'Legg til'/);
    });
});

describe('the selected codes', () => {
    test('they get their own labelled section', () => {
        assert.match(source, /Valgte CPV-koder/);
    });

    test('an empty selection says so instead of showing a blank rule row', () => {
        assert.match(source, /Ingen CPV-koder er lagt til ennå\./);
    });

    test('each selected code shows its name and code', () => {
        const selected = source.slice(source.indexOf('form.data.cpv_codes.map'));

        assert.match(selected, /\{row\.description \|\| row\.cpv_code\}/);
        assert.match(selected, /\{row\.cpv_code\}/);
    });

    test('removal is a full button, not a bare x, and is keyed by code', () => {
        assert.match(source, /onClick=\{\(\) => removeCpvCode\(row\.cpv_code\)\}/);
        assert.match(source, /aria-label=\{`Fjern \$\{row\.description \|\| row\.cpv_code\}`\}/);
        assert.match(source, />\s*Fjern\s*</);
    });

    test('removing only touches the form, never the catalog', () => {
        const handler = source.slice(source.indexOf('const removeCpvCode'));
        const body = handler.slice(0, handler.indexOf('};'));

        assert.match(body, /form\.setData\(/);
        assert.ok(!/fetch|router\.(delete|post)/.test(body), 'removal must not call the server');
    });

    test('they lay out in two columns on wide screens and one when narrow', () => {
        assert.match(source, /className="grid gap-2 lg:grid-cols-2"/);
    });
});

describe('weight', () => {
    // Weight is used by DoffinRelevanceService, DoffinWatchProfileMatchService and the inbox
    // discovery scorer, so it stays — but on the selected code, out of the search experience.
    test('it is editable on a selected code', () => {
        assert.match(source, /onChange=\{\(event\) => setCpvWeight\(row\.cpv_code, event\.target\.value\)\}/);
        assert.match(source, /aria-label=\{`Vekt for \$\{row\.description \|\| row\.cpv_code\}`\}/);
    });

    test('a newly added code carries the default weight the backend requires', () => {
        assert.match(source, /\{ cpv_code: suggestion\.code, description: suggestion\.label, weight: 1 \}/);
    });

    test('weight is not part of the search results', () => {
        const results = source.slice(
            source.indexOf('cpvSuggestions.map'),
            source.indexOf('Valgte CPV-koder'),
        );

        assert.ok(!results.includes('weight'), 'the search results must not ask for a weight');
    });
});

describe('the retired code-first affordances', () => {
    test('the separate "Forklaring" field is gone', () => {
        assert.ok(!source.includes('Forklaring'));
        assert.ok(!source.includes('Velg en CPV-kode for å se beskrivelsen.'));
    });

    test('"Legg til CPV-rad" is gone', () => {
        assert.ok(!source.includes('Legg til CPV-rad'));
        assert.ok(!source.includes('addCpvRule'));
    });

    test('the old per-row edit handlers are gone', () => {
        for (const gone of ['updateCpvRule', 'selectCpvSuggestion', 'removeCpvRule', 'closeCpvSuggestions']) {
            assert.ok(!source.includes(gone), `${gone} should no longer exist`);
        }
    });
});

describe('the backend contract is unchanged', () => {
    test('the form still builds cpv_codes rows of { cpv_code, weight }', () => {
        assert.match(source, /form\.setData\('cpv_codes'/);
        assert.match(source, /cpv_code: suggestion\.code/);
        assert.match(source, /weight: 1/);
    });

    test('both suggestion shapes are tolerated', () => {
        // Watch profiles return {code, description}; the notices endpoint returns {code, label}.
        assert.match(source, /item\.description \|\| item\.label \|\| item\.code/);
    });
});

describe('accessibility', () => {
    test('add and remove are real buttons', () => {
        const addButton = source.slice(source.indexOf('onClick={() => addCpvCode(suggestion)}') - 200);
        assert.match(addButton.slice(0, 400), /type="button"/);

        const removeButton = source.slice(source.indexOf('onClick={() => removeCpvCode(row.cpv_code)}') - 200);
        assert.match(removeButton.slice(0, 400), /type="button"/);
    });

    test('the search input is labelled and points at its results', () => {
        assert.match(source, /htmlFor=\{cpvSearchId\}/);
        assert.match(source, /id=\{cpvSearchId\}/);
        assert.match(source, /aria-describedby=\{cpvResultsId\}/);
    });

    test('results announce themselves as they change', () => {
        assert.match(source, /id=\{cpvResultsId\} aria-live="polite"/);
    });
});

describe('the help layer', () => {
    test('both create and edit render the same PageHelp', () => {
        for (const [name, page] of [['Create', createSource], ['Edit', editSource]]) {
            assert.match(page, /<PageHelpButton/, `${name} renders PageHelp`);
            assert.match(page, /sections=\{getWatchProfileHelpSections\(wp\)\}/, `${name} uses the shared sections`);
            assert.match(page, /wp\.form_page_help_title/, `${name} uses the form help title`);
        }
    });

    test('the sections live in one shared module', () => {
        for (const page of [createSource, editSource]) {
            assert.match(page, /from '\.\/watchProfileHelp'/);
        }

        assert.ok(!source.includes('getWatchProfileHelpSections'), 'the form no longer owns the sections');
    });

    test('the fields that need explaining have one', () => {
        for (const [label, key] of [
            ['Eierskap', 'hint_owner_scope'],
            ['Status', 'hint_status'],
            ['Beskrivelse', 'hint_description'],
            ['Nøkkelord', 'hint_keywords'],
            ['CPV-koder', 'hint_cpv'],
            ['Vekt', 'hint_weight'],
        ]) {
            assert.ok(
                source.includes(`label="Vis forklaring for ${label}"`),
                `${label} has an info button`,
            );
            assert.ok(source.includes(`wp.${key}`), `${label} reads ${key} from translations`);
        }
    });

    test('the hints use the shared InfoHint component rather than a new one', () => {
        assert.match(source, /import InfoHint from '\.\.\/\.\.\/\.\.\/Components\/App\/InfoHint'/);
        assert.equal((source.match(/<InfoHint size="sm"/g) ?? []).length, 6);
    });

    test('the internal note under Nøkkelord is gone', () => {
        assert.ok(!source.includes('Lagres i samme format'));
        assert.ok(!source.includes('modell forventer'));
        assert.match(source, /wp\.keywords_help/);
    });
});
