import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { OBJECTIVE_HELP_PAGES, objectiveHelp } from './objectiveHelp.js';

const source = (file) => readFileSync(fileURLToPath(new URL(file, import.meta.url)), 'utf8');

const PAGE_FILES = { index: './Index.jsx', objective: './Show.jsx', kpi: './KpiShow.jsx' };

describe('Every Mål og KPI page carries the shared PageHelp', () => {
    test('each help page is rendered by its page through PageHelpButton', () => {
        assert.deepEqual(Object.keys(PAGE_FILES), OBJECTIVE_HELP_PAGES);

        for (const [page, file] of Object.entries(PAGE_FILES)) {
            const code = source(file);
            assert.match(code, /import PageHelpButton from '\.\.\/\.\.\/\.\.\/Components\/App\/PageHelpButton'/, file);
            assert.match(code, new RegExp(`<PageHelpButton \\{\\.\\.\\.objectiveHelp\\(\\w+, '${page}'\\)\\} />`), file);
            assert.ok(! code.includes('PageHelpPanel'), `${file} must not roll its own help panel`);
        }
    });
});

describe('objectiveHelp', () => {
    test('passes the page\'s sections from the translations through unchanged', () => {
        const sections = [{ title: 'Mål', items: [{ title: 'Hva er et mål?', text: 'Noe virksomheten skal oppnå.' }] }];
        const help = objectiveHelp({ help: { button: 'Hjelp', kpi: { title: 'Om KPI-en', intro: 'Intro', sections } } }, 'kpi');

        assert.deepEqual(help, { buttonLabel: 'Hjelp', title: 'Om KPI-en', intro: 'Intro', sections });
    });

    test('falls back to an empty panel rather than breaking the page', () => {
        const help = objectiveHelp({}, 'index');

        assert.equal(help.buttonLabel, 'Hjelp');
        assert.deepEqual(help.sections, []);
    });
});
