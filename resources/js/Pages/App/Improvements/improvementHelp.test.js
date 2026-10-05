import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { IMPROVEMENT_HELP_PAGES, improvementHelp } from './improvementHelp.js';
import { ACTION_STATUS_TONES, VERIFICATION_RESULT_TONES, actionIndicator, attentionSummary, cancellationReason, describeActionHistoryEntry, describeHistoryEntry, descriptionHint, formatDay } from './improvementStatus.js';

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

describe('Effektverifisering and Trenger oppmerksomhet', () => {
    test('the two results have their own badge tones', () => {
        assert.deepEqual(Object.keys(VERIFICATION_RESULT_TONES), ['effective', 'not_effective']);
        assert.notEqual(VERIFICATION_RESULT_TONES.effective, VERIFICATION_RESULT_TONES.not_effective);
    });

    test('cases and tiltak are counted apart in the headline, never summed', () => {
        assert.equal(attentionSummary(1, 3), '1 sak og 3 tiltak trenger oppmerksomhet');
        assert.equal(attentionSummary(2, 0), '2 saker trenger oppmerksomhet');
        assert.equal(attentionSummary(0, 1), '1 tiltak trenger oppmerksomhet');
        assert.equal(
            attentionSummary(1, 2, { cases_one: '1 case', actions_many: ':count actions', summary: ':cases and :actions need attention' }),
            '1 case and 2 actions need attention',
        );
    });

    test('the register indicator names the tiltak and how many are still open', () => {
        assert.equal(actionIndicator(null), null);
        assert.equal(actionIndicator({ total: 0, open: 0 }), null);
        assert.equal(actionIndicator({ total: 3, open: 1 }), '3 tiltak · 1 åpent');
        assert.equal(actionIndicator({ total: 3, open: 2 }), '3 tiltak · 2 åpne');
        assert.equal(actionIndicator({ total: 1, open: 0 }), '1 tiltak');
        assert.equal(actionIndicator({ total: 2, open: 1 }, { total_many: ':count actions', open_one: '1 open' }), '2 actions · 1 open');
    });

    test('the verification form asks for a result and a comment only', () => {
        const code = source('./ImprovementActions.jsx');
        const form = code.slice(code.indexOf('function VerifyForm'), code.indexOf('function VerificationEntry'));
        assert.deepEqual([...form.matchAll(/useForm\(\{([^}]*)\}\)/g)].map((match) => match[1].trim()), ["result: '', note: ''"]);
        assert.deepEqual([...form.matchAll(/\['effective', 'not_effective'\]/g)].length, 1);
    });
});
