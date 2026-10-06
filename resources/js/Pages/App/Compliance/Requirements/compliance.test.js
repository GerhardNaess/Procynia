import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { COMPLIANCE_HELP_PAGES, complianceHelp } from './complianceHelp.js';
import { REQUIREMENT_STATUS_TONES, countLabel, describeHistoryEntry, reviewIntervalLabel, sourceKindLabel } from './complianceRequirement.js';

const here = fileURLToPath(new URL('.', import.meta.url));
const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

const PAGE_FILES = { index: './Index.jsx', requirement: './Show.jsx' };

describe('Every Krav page carries the shared PageHelp', () => {
    test('each help page is rendered by its page through PageHelpButton', () => {
        assert.deepEqual(Object.keys(PAGE_FILES), COMPLIANCE_HELP_PAGES);

        for (const [page, file] of Object.entries(PAGE_FILES)) {
            const code = source(file);
            assert.match(code, /import PageHelpButton from '\.\.\/\.\.\/\.\.\/\.\.\/Components\/App\/PageHelpButton'/, file);
            assert.match(code, new RegExp(`<PageHelpButton \\{\\.\\.\\.complianceHelp\\(\\w+, '${page}'\\)\\} />`), file);
            assert.ok(! code.includes('PageHelpPanel'), `${file} must not roll its own help panel`);
        }
    });
});

describe('No text below 16 px in the module', () => {
    test('no component uses text-xs or text-sm', () => {
        for (const file of readdirSync(here).filter((name) => name.endsWith('.jsx'))) {
            assert.doesNotMatch(source(`./${file}`), /\btext-(xs|sm)\b/, file);
        }
    });
});

describe('complianceHelp', () => {
    test('passes the page\'s sections from the translations through unchanged', () => {
        const sections = [{ title: 'Status', items: [{ title: 'Utgått', text: 'Gjelder ikke lenger.' }] }];
        const help = complianceHelp({ help: { button: 'Hjelp', requirement: { title: 'Om kravet', intro: 'Intro', sections } } }, 'requirement');

        assert.deepEqual(help, { buttonLabel: 'Hjelp', title: 'Om kravet', intro: 'Intro', sections });
    });

    test('falls back to an empty panel rather than breaking the page', () => {
        const help = complianceHelp({}, 'index');

        assert.equal(help.buttonLabel, 'Hjelp');
        assert.deepEqual(help.sections, []);
    });
});

describe('complianceRequirement', () => {
    test('every status has a badge tone, and the two differ', () => {
        assert.deepEqual(Object.keys(REQUIREMENT_STATUS_TONES), ['active', 'retired']);
        assert.notEqual(REQUIREMENT_STATUS_TONES.active, REQUIREMENT_STATUS_TONES.retired);
    });

    test('the review interval reads as a word, with none as «Ingen fast intervall»', () => {
        assert.equal(reviewIntervalLabel(null), 'Ingen fast intervall');
        assert.equal(reviewIntervalLabel(''), 'Ingen fast intervall');
        assert.equal(reviewIntervalLabel(1), 'Månedlig');
        assert.equal(reviewIntervalLabel(3), 'Kvartalsvis');
        assert.equal(reviewIntervalLabel(6), 'Halvårlig');
        assert.equal(reviewIntervalLabel(12), 'Årlig');
        assert.equal(reviewIntervalLabel(12, { review_intervals: { 12: 'Yearly' } }), 'Yearly');
    });

    test('a source kind reads as its label', () => {
        assert.equal(sourceKindLabel('law'), 'Lov/forskrift');
        assert.equal(sourceKindLabel('internal'), 'Internt krav');
        assert.equal(sourceKindLabel('contract', { kinds: { contract: 'Contract' } }), 'Contract');
    });

    test('a history entry reads as a sentence, with a former user when the author is gone', () => {
        assert.equal(describeHistoryEntry({ to_status: 'retired', changed_by_name: 'Kari' }), 'Satt som utgått av Kari');
        assert.equal(describeHistoryEntry({ to_status: 'active', changed_by_name: null }), 'Gjenåpnet av en tidligere bruker');
        assert.equal(describeHistoryEntry({ to_status: 'retired', changed_by_name: 'Ola' }, { history: { retired: 'Retired by :name' } }), 'Retired by Ola');
    });

    test('a count is singular for one', () => {
        assert.equal(countLabel(1, '1 krav', ':count krav'), '1 krav');
        assert.equal(countLabel(0, '1 krav', ':count krav'), '0 krav');
        assert.equal(countLabel(12, '1 requirement', ':count requirements'), '12 requirements');
    });
});

describe('The requirement form and page say what a requirement is, and nothing about whether it is met', () => {
    test('the form asks for source, reference, title, text, owner and interval — never a status', () => {
        const code = source('./RequirementForm.jsx');
        const ids = [...code.matchAll(/id="(compliance-requirement-[a-z-]+)"/g)].map((match) => match[1]).filter((id) => ! id.endsWith('-hint'));

        assert.deepEqual(ids, [
            'compliance-requirement-source',
            'compliance-requirement-reference',
            'compliance-requirement-title',
            'compliance-requirement-text',
            'compliance-requirement-owner',
            'compliance-requirement-review',
        ]);
        assert.doesNotMatch(code, /setData\('status'|data\.status/);
    });

    test('the requirement page shows text, source and ownership, status and history — in that order', () => {
        const code = source('./Show.jsx');
        const order = ['compliance-text-heading', 'compliance-details-heading', 'compliance-status-heading', '<RequirementHistory'].map((needle) => code.indexOf(needle));

        assert.ok(order.every((index) => index > -1));
        for (let i = 1; i < order.length; i += 1) {
            assert.ok(order[i] > order[i - 1], `section ${i} is out of order`);
        }
    });

    test('no empty sections for what is not built yet', () => {
        // Code only: the doc comments may name what comes later.
        const code = (file) => source(file).replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

        for (const file of readdirSync(here).filter((name) => name.endsWith('.jsx'))) {
            assert.doesNotMatch(code(`./${file}`), /assessment|audit|control|Vurderinger|Etterlevelsesvurdering|Revisjoner|Kontroller|Funn/, file);
        }
    });

    test('sources are managed from the register, not a page of their own', () => {
        assert.match(source('./Index.jsx'), /<ComplianceSources/);
        assert.ok(! readdirSync(here).includes('Sources.jsx'));
    });
});
