import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { RISK_HELP_PAGES, riskHelp } from './riskHelp.js';

const here = fileURLToPath(new URL('.', import.meta.url));
const source = (file) => readFileSync(fileURLToPath(new URL(file, import.meta.url)), 'utf8');

const PAGE_FILES = { index: './Index.jsx' };

describe('No text below 16 px in the module', () => {
    test('no component uses text-xs, text-sm or a smaller fixed size', () => {
        for (const file of readdirSync(here).filter((name) => name.endsWith('.jsx'))) {
            assert.doesNotMatch(source(`./${file}`), /\btext-(xs|sm)\b|\btext-\[(\d|1[0-5])px\]/, file);
        }
    });
});

describe('Every Risiko page with help carries the shared PageHelp', () => {
    test('each help page is rendered by its page through PageHelpButton', () => {
        assert.deepEqual(Object.keys(PAGE_FILES), RISK_HELP_PAGES);

        for (const [page, file] of Object.entries(PAGE_FILES)) {
            const code = source(file);
            assert.match(code, /import PageHelpButton from '\.\.\/\.\.\/\.\.\/Components\/App\/PageHelpButton'/, file);
            assert.match(code, new RegExp(`<PageHelpButton \\{\\.\\.\\.riskHelp\\(\\w+, '${page}'\\)\\} />`), file);
            assert.ok(! code.includes('PageHelpPanel'), `${file} must not roll its own help panel`);
        }
    });
});

describe('riskHelp', () => {
    test('passes the page\'s sections from the translations through unchanged', () => {
        const sections = [{ title: 'Risiko', items: [{ title: 'Hva er en risiko?', text: 'Noe som kan skje.' }] }];
        const help = riskHelp({ help: { button: 'Hjelp', index: { title: 'Om risikoregisteret', intro: 'Intro', sections } } }, 'index');

        assert.deepEqual(help, { buttonLabel: 'Hjelp', title: 'Om risikoregisteret', intro: 'Intro', sections });
    });

    test('falls back to an empty panel rather than breaking the page', () => {
        const help = riskHelp({}, 'index');

        assert.equal(help.buttonLabel, 'Hjelp');
        assert.deepEqual(help.sections, []);
    });
});
