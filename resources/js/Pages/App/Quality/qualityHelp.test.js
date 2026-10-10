import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { QUALITY_HELP_PAGES, qualityHelp, qualityItemHelpPage, qualityTabHelpPage } from './qualityHelp.js';

const source = (file) => readFileSync(fileURLToPath(new URL(file, import.meta.url)), 'utf8');

describe('Every Kvalitet page carries the shared PageHelp', () => {
    test('the Kvalitet page and an item page render it through PageHelpButton', () => {
        for (const [file, resolver] of [['./Index.jsx', 'qualityTabHelpPage\\(activeTab\\)'], ['./Item.jsx', 'qualityItemHelpPage\\(item\\.quality_type\\)']]) {
            const code = source(file);
            assert.match(code, /import PageHelpButton from '\.\.\/\.\.\/\.\.\/Components\/App\/PageHelpButton'/, file);
            assert.match(code, new RegExp(`<PageHelpButton \\{\\.\\.\\.qualityHelp\\(tq, ${resolver}\\)\\} />`), file);
            assert.ok(! code.includes('PageHelpPanel'), `${file} must not roll its own help panel`);
        }
    });
});

describe('which help a page shows', () => {
    test('each tab has its own help, and an unknown tab falls back to the overview', () => {
        for (const tab of ['overview', 'processes', 'controls', 'tools']) {
            assert.equal(qualityTabHelpPage(tab), tab);
        }

        assert.equal(qualityTabHelpPage(undefined), 'overview');
    });

    test('a process and a control have their own help, the governing document types share one', () => {
        assert.equal(qualityItemHelpPage('process'), 'process');
        assert.equal(qualityItemHelpPage('control'), 'control');

        for (const type of ['policy', 'procedure', 'work_instruction', 'checklist']) {
            assert.equal(qualityItemHelpPage(type), 'document');
        }
    });

    test('every page a resolver can return is a help page', () => {
        const resolved = new Set([
            ...['overview', 'processes', 'controls', 'tools'].map(qualityTabHelpPage),
            ...['process', 'control', 'policy'].map(qualityItemHelpPage),
        ]);

        assert.deepEqual([...resolved].sort(), [...QUALITY_HELP_PAGES].sort());
    });
});

describe('qualityHelp', () => {
    test('passes the page\'s sections from the translations through unchanged', () => {
        const sections = [{ title: 'Kontrollen', items: [{ title: 'Kriterium', text: 'Hva kontrollen skal verifisere.' }] }];
        const help = qualityHelp({ help: { button: 'Hjelp', control: { title: 'Om kontrollen', intro: 'Intro', sections } } }, 'control');

        assert.deepEqual(help, { buttonLabel: 'Hjelp', title: 'Om kontrollen', intro: 'Intro', sections });
    });

    test('falls back to an empty panel rather than breaking the page', () => {
        const help = qualityHelp({}, 'overview');

        assert.equal(help.buttonLabel, 'Hjelp');
        assert.deepEqual(help.sections, []);
    });
});
