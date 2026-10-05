import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { IMPROVEMENT_HELP_PAGES, improvementHelp } from './improvementHelp.js';
import { ACTION_STATUS_TONES, cancellationReason, describeActionHistoryEntry, describeHistoryEntry, descriptionHint, formatDay } from './improvementStatus.js';

const here = fileURLToPath(new URL('.', import.meta.url));
const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

const PAGE_FILES = { index: './Index.jsx', case: './Show.jsx' };

describe('Every Avvik og forbedringer page carries the shared PageHelp', () => {
    test('each help page is rendered by its page through PageHelpButton', () => {
        assert.deepEqual(Object.keys(PAGE_FILES), IMPROVEMENT_HELP_PAGES);

        for (const [page, file] of Object.entries(PAGE_FILES)) {
            const code = source(file);
            assert.match(code, /import PageHelpButton from '\.\.\/\.\.\/\.\.\/Components\/App\/PageHelpButton'/, file);
            assert.match(code, new RegExp(`<PageHelpButton \\{\\.\\.\\.improvementHelp\\(\\w+, '${page}'\\)\\} />`), file);
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

describe('improvementHelp', () => {
    test('passes the page\'s sections from the translations through unchanged', () => {
        const sections = [{ title: 'Status', items: [{ title: 'Åpen', text: 'Registrert, ikke startet.' }] }];
        const help = improvementHelp({ help: { button: 'Hjelp', case: { title: 'Om saken', intro: 'Intro', sections } } }, 'case');

        assert.deepEqual(help, { buttonLabel: 'Hjelp', title: 'Om saken', intro: 'Intro', sections });
    });

    test('falls back to an empty panel rather than breaking the page', () => {
        const help = improvementHelp({}, 'index');

        assert.equal(help.buttonLabel, 'Hjelp');
        assert.deepEqual(help.sections, []);
    });
});

describe('improvementStatus', () => {
    test('the description hint follows the chosen type', () => {
        assert.equal(descriptionHint('deviation'), 'Beskriv hva som skjedde, og hva som var forventet.');
        assert.equal(descriptionHint(''), 'Beskriv hva som skjedde, og hva som var forventet.');
        assert.equal(descriptionHint('improvement'), 'Beskriv hva som kan forbedres, og hvorfor det vil være nyttig.');
    });

    test('a history entry reads as a sentence, with a former user when the author is gone', () => {
        assert.equal(describeHistoryEntry({ to_status: 'in_progress', changed_by_name: 'Kari' }), 'Behandling startet av Kari');
        assert.equal(describeHistoryEntry({ to_status: 'open', changed_by_name: null }), 'Gjenåpnet av en tidligere bruker');
        assert.equal(
            describeHistoryEntry({ to_status: 'closed', changed_by_name: 'Ola' }, { history: { closed: 'Closed by :name' } }),
            'Closed by Ola',
        );
    });

    test('a day is shown as dd.mm.yyyy, or the fallback', () => {
        assert.equal(formatDay('2026-12-31'), '31.12.2026');
        assert.equal(formatDay(null, 'Ingen frist'), 'Ingen frist');
    });
});

describe('Tiltak on the case page', () => {
    test('the case page shows Årsak og bakgrunn and Tiltak, in that order, before Behandling', () => {
        const code = source('./Show.jsx');
        const cause = code.indexOf('<ImprovementCause');
        const actions = code.indexOf('<ImprovementActions');
        const handling = code.indexOf('improvement-handling-heading');

        assert.ok(cause > 0 && cause < actions && actions < handling);
    });

    test('the tiltak form asks for title, description, owner and frist only — never a status', () => {
        const code = source('./ImprovementActionForm.jsx');
        const ids = [...code.matchAll(/id="(improvement-action-[a-z-]+)"/g)].map((match) => match[1]).filter((id) => ! id.endsWith('-hint'));

        assert.deepEqual(ids, ['improvement-action-title', 'improvement-action-description', 'improvement-action-owner', 'improvement-action-due-date']);
        assert.doesNotMatch(code, /setData\('status'|data\.status/);
    });

    test('every tiltak status has a badge tone', () => {
        assert.deepEqual(Object.keys(ACTION_STATUS_TONES), ['planned', 'in_progress', 'completed', 'cancelled']);
    });

    test('a tiltak history entry reads as a sentence, with a former user when the author is gone', () => {
        assert.equal(describeActionHistoryEntry({ to_status: 'in_progress', changed_by_name: 'Ola' }), 'Startet av Ola');
        assert.equal(describeActionHistoryEntry({ to_status: 'completed', changed_by_name: 'Kari' }), 'Fullført av Kari');
        assert.equal(describeActionHistoryEntry({ to_status: 'planned', changed_by_name: null }), 'Gjenåpnet av en tidligere bruker');
        assert.equal(
            describeActionHistoryEntry({ to_status: 'cancelled', changed_by_name: 'Ola' }, { actions: { history: { cancelled: 'Cancelled by :name' } } }),
            'Cancelled by Ola',
        );
    });

    test('the cancellation reason comes from the latest history entry, and only while cancelled', () => {
        const history = [{ to_status: 'cancelled', note: 'Dekkes av et annet tiltak.' }, { to_status: 'in_progress', note: null }];

        assert.equal(cancellationReason({ status: 'cancelled', history }), 'Dekkes av et annet tiltak.');
        assert.equal(cancellationReason({ status: 'planned', history: [{ to_status: 'planned', note: 'Igjen' }, ...history] }), null);
        assert.equal(cancellationReason({ status: 'cancelled', history: [] }), null);
    });
});
