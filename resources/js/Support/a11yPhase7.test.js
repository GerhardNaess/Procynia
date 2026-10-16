import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { BUSY_STYLE, submitWhenIdle } from './focusManagement.js';
import { resetRowActionError, rowErrorFor, trackRowAction } from './rowActionError.js';
import { dateTag, formatRiskDay, formatRiskLongDay } from '../Pages/App/Risk/riskDates.js';

const here = dirname(fileURLToPath(import.meta.url));
const read = (...parts) => readFileSync(join(here, '..', ...parts), 'utf8');
const root = join(here, '..', '..', '..');
const lang = (code) => readFileSync(join(root, 'lang', code, 'procynia.php'), 'utf8');

/**
 * Fase 7A of the WCAG plan (docs/wcag-accessibility-plan.md §21): the remaining findings that were
 * fixed in the final round. Behaviour in a real browser is in the E2E specs named in §21.
 */

/** Source without comments, so a rule is not satisfied (or broken) by prose about it. */
function code(source) {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/\{\/\*[\s\S]*?\*\/\}/g, '');
}

/** Every .jsx file below a directory. */
function jsxFiles(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        return statSync(path).isDirectory() ? jsxFiles(path) : (name.endsWith('.jsx') ? [path] : []);
    });
}

// `disabled={…busy…}`, not `aria-disabled={…}`.
const BUSY_DISABLED = /(?<![-\w])disabled=\{[^}]*(processing|busy|activating)\b/;

describe('a request in progress keeps focus on the control (A11Y-156, -196)', () => {
    test('submitWhenIdle refuses a second submit while the first runs', () => {
        const calls = [];
        let prevented = 0;
        const form = { processing: false };
        const submit = submitWhenIdle(form, (event) => calls.push(event));

        submit({ preventDefault: () => { prevented += 1; } });
        form.processing = true;
        submit({ preventDefault: () => { prevented += 1; } });

        assert.equal(calls.length, 1);
        assert.equal(prevented, 1, 'the native submit is stopped while busy');
        assert.match(BUSY_STYLE, /aria-disabled:/);
    });

    test('the Wiki review panel, upload field and block editor are aria-disabled while busy', () => {
        const panel = code(read('Pages', 'App', 'Wiki', 'WikiReviewPanel.jsx'));
        const index = code(read('Pages', 'App', 'Wiki', 'Index.jsx'));
        const show = code(read('Pages', 'App', 'Wiki', 'Show.jsx'));

        assert.equal(BUSY_DISABLED.test(panel), false);
        assert.ok((panel.match(/aria-disabled=\{busy \|\| undefined\}/g) ?? []).length >= 9);
        assert.equal(/(?<![-\w])disabled=\{uploadForm\.processing\}/.test(index), false);
        assert.match(index, /aria-disabled=\{uploadForm\.processing \|\| undefined\}/);
        assert.equal(/!wikiBlockSaveProcessingKey,/.test(show), false, 'a save in progress is not a precondition');
        assert.ok((show.match(/aria-disabled=\{isSavingBlockEdit \|\| undefined\}/g) ?? []).length === 2);
        assert.match(show, /if \(wikiBlockSaveProcessingKey !== null\) \{\s*return;/, 'the handler refuses a second save');
    });

    test('no save button in Etterlevelse og revisjon is disabled while it saves', () => {
        for (const file of jsxFiles(join(here, '..', 'Pages', 'App', 'Compliance'))) {
            const source = code(readFileSync(file, 'utf8'));

            assert.equal(BUSY_DISABLED.test(source), false, file);

            if (/aria-disabled=\{(form|pick|fromSource)\.processing/.test(source)) {
                assert.match(source, /onSubmit=\{submitWhenIdle\(/, `${file} must refuse a second submit`);
            }
        }
    });

    test('«Ferdigstill» says why it is unavailable in text, and its confirm keeps focus (A11Y-184)', () => {
        const show = code(read('Pages', 'App', 'ManagementReview', 'Show.jsx'));
        const button = show.slice(show.indexOf('id="mr-action-finalize"'), show.indexOf('data-testid="mr-finalize"'));

        assert.equal(/title=/.test(button), false, 'not only in a hover title');
        assert.match(button, /aria-describedby=\{ready \? undefined : 'mr-finalize-not-ready'\}/);
        assert.match(show, /id="mr-finalize-not-ready"/);
        assert.match(show, /onClick=\{confirm\} aria-disabled=\{processing \|\| undefined\}/);
        assert.match(show, /const confirm = \(\) => \{\s*if \(processing\) \{\s*return;/);
    });
});

describe('refusals are shown where the person acted (A11Y-161, -193)', () => {
    test('trackRowAction records a validation refusal on the named key', () => {
        resetRowActionError();
        const seen = [];
        const options = trackRowAction('scope-remove-4', { onError: (errors) => seen.push(errors) }, { errorKey: 'audit' });

        options.onError({ audit: 'Kravet har kontrollhandlinger.' });
        assert.equal(seen.length, 1, 'the caller\'s own onError still runs');
        assert.equal(rowErrorFor({ key: 'scope-remove-4', message: 'Kravet har kontrollhandlinger.' }, 'scope-remove-4', 'Kravet har kontrollhandlinger.'), 'Kravet har kontrollhandlinger.');

        // Without an errorKey a validation error is not taken for a row refusal.
        trackRowAction('other', {}).onError({ audit: 'X' });
        resetRowActionError();
    });

    test('removing a requirement from the audit scope says a refusal by the button', () => {
        const scope = code(read('Pages', 'App', 'Compliance', 'Audits', 'AuditScope.jsx'));

        assert.match(scope, /useRowActionError\(errors\.audit \?\? null\)/);
        assert.match(scope, /trackRowAction\(scopeRemoveId\('requirement', requirement\.id\), \{[^}]*\}, \{ errorKey: 'audit' \}\)/);
        assert.match(scope, /id=\{rowErrorId\(scopeRemoveId\('requirement', requirement\.id\)\)\}/);
    });

    test('«Legg artikkelen inn i Wiki» shows the server\'s answer and keeps the button focusable', () => {
        const panel = code(read('Components', 'App', 'ProcessFlowPanel.jsx'));

        assert.match(panel, /onError: \(errors\) => \{\s*setArticleErrors\(errors \?\? \{\}\);\s*focusFirstArticleError\(\);/);
        assert.match(panel, /error=\{articleErrors\.title\}/);
        assert.match(panel, /error=\{articleErrors\.markdown\}/);
        assert.match(panel, /data-form-error-summary/);
        assert.match(panel, /const canSubmit = title\.trim\(\) !== '' && markdown\.trim\(\) !== '';/);
    });

    test('«Registrer funn» on an open Nytt funn moves focus into the restarted form (A11Y-195)', () => {
        const findings = code(read('Pages', 'App', 'Compliance', 'Audits', 'AuditFindings.jsx'));

        assert.match(findings, /if \(panel === 'create'\) \{\s*setRefocusDraft/);
        assert.match(findings, /querySelector\?\.\('\[data-inline-panel="create"\]'\)/);
    });
});

describe('the relation tooltip on the graph is dismissible and hoverable (A11Y-153)', () => {
    const graph = read('Pages', 'App', 'Wiki', 'Graph.jsx');
    const tooltip = graph.slice(graph.indexOf('function EdgeTooltip'), graph.indexOf('function RelationPanel'));

    test('the pointer can move onto it', () => {
        assert.equal(/pointer-events-none/.test(tooltip), false);
        assert.match(tooltip, /onPointerEnter=\{onPointerEnter\}/);
        assert.match(code(graph), /edgeTooltipHideRef\.current = window\.setTimeout\(hideEdgeTooltip, EDGE_TOOLTIP_GRACE_MS\)/);
        assert.equal(/renderer\.on\('moveBody'/.test(code(graph)), false, 'it no longer runs ahead of the pointer');
    });

    test('Escape closes it, only while it is shown', () => {
        const effect = graph.slice(graph.indexOf('// Escape closes the hover tooltip'), graph.indexOf('// The relation being examined'));

        assert.match(effect, /if \(! hoveredEdge\) \{\s*return undefined;/);
        assert.match(effect, /event\.key === 'Escape'/);
        assert.match(effect, /removeEventListener\('keydown'/);
    });
});

describe('text in the page\'s language (A11Y-105, -155, -172)', () => {
    test('Risk dates follow the app locale', () => {
        assert.equal(dateTag('no'), 'nb-NO');
        assert.equal(dateTag('en'), 'en-GB');
        assert.equal(dateTag(undefined), 'nb-NO');
        assert.equal(formatRiskDay(null, 'en'), '—');
        assert.equal(formatRiskLongDay('2026-10-04', 'en'), '4 October 2026');
        assert.equal(formatRiskLongDay('2026-10-04', 'no'), '4. oktober 2026');

        for (const file of readdirSync(join(here, '..', 'Pages', 'App', 'Risk')).filter((name) => name.endsWith('.jsx'))) {
            assert.equal(/toLocale(Date)?String\('nb-NO'/.test(read('Pages', 'App', 'Risk', file)), false, file);
        }
    });

    test('the remaining Wiki strings are translated', () => {
        const index = code(read('Pages', 'App', 'Wiki', 'Index.jsx'));
        const show = code(read('Pages', 'App', 'Wiki', 'Show.jsx'));

        for (const literal of ['>Tittel</th>', '>Type</th>', '>Status</th>', 'aria-label="Fjern kildefilter"', '`I kø siden', '>Beslutning</div>', '>Fullført</div>']) {
            assert.equal(index.includes(literal), false, literal);
        }

        assert.equal(show.includes("? 'Lagrer...' : 'Lagre endring'"), false, 'the block editor\'s buttons');
        assert.equal(/>\s*Avbryt\s*</.test(show), false);
    });

    test('the admin notifications page is translated, without the «varseler» misspelling', () => {
        const view = readFileSync(join(root, 'resources', 'views', 'filament', 'pages', 'admin-notifications.blade.php'), 'utf8');

        assert.equal(view.includes("varsel{{ $unreadCount !== 1 ? 'er'"), false);
        assert.match(view, /admin_a11y\.notifications_unread_many/);
        assert.match(view, /admin_a11y\.notifications_mark_read/);
    });

    test('every new key exists in both languages', () => {
        const keys = [
            'wiki_block_edit_save', 'wiki_block_edit_saving', 'wiki_block_edit_cancel', 'wiki_block_edit_missing_context',
            'wiki_block_edit_empty', 'wiki_block_edit_failed', 'wiki_block_edit_requires_run', 'ingest_activity_reload',
            'ingest_activity_queued_since', 'pages_col_title', 'pages_col_type', 'pages_col_status', 'runs_filter_src_clear',
            'sources_delete_preview_failed', 'notifications_mark_all', 'notifications_mark_read', 'notifications_none_unread',
            'notifications_read_heading', 'notifications_unread_many', 'notifications_unread_one',
        ];

        for (const code of ['no', 'en']) {
            const file = lang(code);

            for (const key of keys) {
                assert.ok(file.includes(`'${key}' =>`), `${code}: ${key}`);
            }
        }
    });
});
