import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { FILTERS, activationText, applicabilityLabel, buildTree, chapterHeading, filterTree, hasControls, matchesFilter, requirementsUnder, sectionIds } from './complianceLibrary.js';

const here = fileURLToPath(new URL('.', import.meta.url));
const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

const row = (overrides = {}) => ({ status: 'active', applicability: 'applies', ...overrides });

// 7 Støtte → 7.1 Ressurser → 7.1.1, 7.1.5 → 7.1.5.2; 7.2 directly under 7.
const NODES = [
    { id: 1, parent_id: null, clause: '7', kind: 'section', depth: 1, title: 'Støtte' },
    { id: 2, parent_id: 1, clause: '7.1', kind: 'section', depth: 2, title: 'Ressurser' },
    { id: 3, parent_id: 2, clause: '7.1.1', kind: 'requirement', depth: 3, title: 'Ressurser – generelt', description: 'Ressurser skal fastsettes.', requirement: row() },
    { id: 4, parent_id: 2, clause: '7.1.5', kind: 'section', depth: 3, title: 'Overvåking og måling' },
    { id: 5, parent_id: 4, clause: '7.1.5.2', kind: 'requirement', depth: 4, title: 'Sporbarhet for målinger', description: 'Kalibrering.', requirement: row({ applicability: 'not_applicable' }) },
    { id: 6, parent_id: 1, clause: '7.2', kind: 'requirement', depth: 2, title: 'Kompetanse', description: 'Kompetanse skal sikres.', requirement: row({ applicability: 'not_assessed' }) },
];

describe('the tree', () => {
    test('nests the flat list by parent and keeps the standard\'s order', () => {
        const [chapter] = buildTree(NODES);

        assert.equal(chapter.clause, '7');
        assert.deepEqual(chapter.children.map((node) => node.clause), ['7.1', '7.2']);
        assert.deepEqual(requirementsUnder(chapter).map((node) => node.clause), ['7.1.1', '7.1.5.2', '7.2']);
        assert.deepEqual(sectionIds(buildTree(NODES)), [1, 2, 4]);
    });

    test('the library shows relevance, never compliance figures — those are Etterlevelsesvurdering\'s', () => {
        const helpers = source('./complianceLibrary.js');

        for (const gone of ['sectionProgress', 'CARD_FIGURES', 'CONTROL_FIGURES', 'controlFigures', 'cardProgress', 'STAT_ORDER']) {
            assert.ok(! helpers.includes(gone), gone);
        }
    });
});

describe('search and filters', () => {
    test('a search keeps matching requirements and the sections above them', () => {
        const [chapter] = filterTree(buildTree(NODES), 'all', 'kompetanse');

        assert.deepEqual(requirementsUnder(chapter).map((node) => node.clause), ['7.2']);
    });

    test('a section that matches keeps all its requirements', () => {
        const [chapter] = filterTree(buildTree(NODES), 'all', 'ressurser');

        assert.deepEqual(requirementsUnder(chapter).map((node) => node.clause), ['7.1.1', '7.1.5.2']);
    });

    test('the filters read the server\'s relevance and never derive one', () => {
        const byClause = Object.fromEntries(NODES.filter((node) => node.requirement).map((node) => [node.clause, node]));

        assert.deepEqual(FILTERS, ['all', 'relevance_open', 'not_applicable']);
        assert.ok(matchesFilter(byClause['7.1.5.2'], 'not_applicable'));
        assert.ok(! matchesFilter(byClause['7.1.5.2'], 'relevance_open'));
        assert.ok(matchesFilter(byClause['7.2'], 'relevance_open'));
        assert.ok(! matchesFilter({ ...byClause['7.2'], requirement: null }, 'relevance_open'), 'without an activation only «all» matches');
        assert.deepEqual(requirementsUnder(filterTree(buildTree(NODES), 'relevance_open', '')[0]).map((node) => node.clause), ['7.2']);
    });

    test('relevance labels fall back to Norwegian', () => {
        assert.equal(applicabilityLabel('not_applicable'), 'Ikke relevant');
        assert.equal(applicabilityLabel('applies', { library: { applicability: { values: { applies: 'Applies' } } } }), 'Applies');
    });

    test('an Annex A control reads its Kontrollvalg, with the same stored values', () => {
        assert.equal(applicabilityLabel('applies', {}, 'control'), 'Inkludert');
        assert.equal(applicabilityLabel('not_applicable', {}, 'control'), 'Utelatt');
        assert.equal(applicabilityLabel('not_assessed', {}, 'control'), 'Ikke valgt');
        assert.equal(applicabilityLabel('not_applicable', { library: { applicability: { control_values: { not_applicable: 'Excluded' } } } }, 'control'), 'Excluded');
        assert.equal(applicabilityLabel('not_applicable', {}, 'conditional'), 'Ikke relevant');
    });

    test('the activation dialog says how many requirements, and controls where there are any, are added', () => {
        assert.equal(activationText({ requirement_count: 65, has_controls: false }), '65 krav legges til i virksomhetens kravregister. Eksisterende vurderinger og registreringer blir ikke endret.');
        assert.equal(activationText({ requirement_count: 123, has_controls: true }), '123 krav og kontroller legges til i virksomhetens kravregister. Eksisterende vurderinger og registreringer blir ikke endret.');
        assert.equal(activationText({ requirement_count: 35, has_controls: false }, { activate_dialog_text: ':count requirements are added.' }), '35 requirements are added.');
    });

    test('a framework has controls only when a requirement says so', () => {
        assert.equal(hasControls(NODES), false);
        assert.equal(hasControls([...NODES, { id: 99, parent_id: null, kind: 'requirement', clause: 'A.5.1', applicability_rule: 'control' }]), true);
    });

    test('a numbered chapter reads «4. Tittel»; Vedlegg A reads its title alone', () => {
        assert.equal(chapterHeading({ clause: '4', title: 'Organisasjonens kontekst' }), '4. Organisasjonens kontekst');
        assert.equal(chapterHeading({ clause: 'A', title: 'Vedlegg A – Informasjonssikkerhetskontroller' }), 'Vedlegg A – Informasjonssikkerhetskontroller');
    });
});

describe('the library pages', () => {
    test('no text below 16 px', () => {
        for (const file of readdirSync(here).filter((name) => name.endsWith('.jsx'))) {
            assert.doesNotMatch(source(`./${file}`), /\btext-(xs|sm)\b/, file);
        }
    });

    test('activation is on the library card, offered only when the server allows it, and confirmed in the shared dialog', () => {
        const index = source('./Index.jsx');

        assert.match(index, /import ActionDialog from '\.\.\/\.\.\/\.\.\/\.\.\/Components\/App\/ActionDialog'/);
        assert.match(index, /framework\.activated \? \(/, 'an activated card offers the register, not activation');
        assert.match(index, /permissions\.can_activate && \(/);
        assert.match(index, /router\.post\(confirming\.activate_url/);
        // Unavailable while the request runs, but focusable, so focus stays in the dialog (A11Y-196):
        // the confirm button says so and its handler refuses a second activation.
        assert.match(index, /ref=\{confirmRef\} type="button" onClick=\{activate\} aria-disabled=\{activating \|\| undefined\}/, 'the confirm button is unavailable while the request runs');
        assert.match(index, /if \(! confirming \|\| activating\) \{\s*return;/, 'a second activation is refused while one runs');
        assert.doesNotMatch(index, /[^-]disabled=\{activating\}/);
        assert.doesNotMatch(index, /window\.confirm/);
    });

    test('an activated card leads on to Etterlevelsesvurdering and the register, and shows no figures', () => {
        const index = source('./Index.jsx');
        const activatedBranch = index.slice(index.indexOf('{framework.activated ? (\n'), index.indexOf(') : (', index.indexOf('{framework.activated ? (\n')));

        assert.match(activatedBranch, /framework\.assessments_url/);
        assert.match(activatedBranch, /tl\.open_assessments \?\? 'Til etterlevelsesvurdering'/);
        assert.match(activatedBranch, /framework\.register_url/);
        assert.match(activatedBranch, /tl\.open_framework \?\? 'Se innholdet'/);
        assert.doesNotMatch(index, /stats|CardStatus|StatusBlock/, 'the library is not a second dashboard');
        assert.match(index, /framework\.register_count/);
    });

    test('a chapter heading is one button with aria-expanded and a chevron large enough to see', () => {
        const show = source('./Show.jsx');

        assert.match(show, /aria-expanded=\{isOpen\}/);
        assert.match(show, /aria-controls=\{panelId\}/);
        assert.match(show, /<SectionChevron open=\{isOpen\} chapter=\{chapter\} \/>/);
        assert.match(show, /chapter \? 'h-6 w-6' : 'h-5 w-5'/, '24 px on a chapter, 20 px on a sub-clause');
        assert.match(show, /open \? 'rotate-90' : ''/, 'right while closed, down while open');
        assert.match(show, /focus-visible:ring-2/);
        assert.match(show, /min-h-11/);
        assert.doesNotMatch(show, /[▸▾]/, 'no text glyph arrows');
    });

    test('the framework page explores the standard and no longer activates it', () => {
        const show = source('./Show.jsx');

        assert.doesNotMatch(show, /\/activate`|router\.post|compliance-library-activate"/);
        assert.match(show, /compliance-library-not-activated/);
    });
});
