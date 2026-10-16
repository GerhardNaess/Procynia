import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { transitionAnnouncement } from './statusAnnouncement.js';
import { focusIsLost, refocusIfLost } from './focusManagement.js';
import { extractionAnnouncement, extractionState } from '../Pages/App/AI/aiStatusAnnouncements.js';
import { businessReviewFocusCandidates } from '../Pages/App/Notices/businessReviewFocus.js';

const here = dirname(fileURLToPath(import.meta.url));
const read = (...parts) => readFileSync(join(here, '..', ...parts), 'utf8');
const lang = (code) => readFileSync(join(here, '..', '..', '..', 'lang', code, 'procynia.php'), 'utf8');

/**
 * Fase 4 of the WCAG plan (docs/wcag-accessibility-plan.md): tenders, Go/No-go, documents and the AI
 * workspace. The rules are pinned here; behaviour in a real browser is in tests/e2e/a11y-tenders-ai.spec.js.
 */

/** Source without comments, so a rule is not satisfied (or broken) by prose about it. */
function code(source) {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/\{\/\*[\s\S]*?\*\/\}/g, '');
}

function labelBlocks(source) {
    return code(source).match(/<label\b[\s\S]*?<\/label>/g) ?? [];
}

describe('a status is spoken when it changes, not when it is reloaded (A11Y-112)', () => {
    const describeAll = (previous, next) => ({ message: `${previous}→${next}` });

    test('the state the page opened in is never announced', () => {
        assert.equal(transitionAnnouncement(undefined, 'processing', describeAll), null);
    });

    test('a reload that leaves the state as it was says nothing', () => {
        assert.equal(transitionAnnouncement('processing', 'processing', describeAll), null);
    });

    test('a real change is described by its two ends', () => {
        assert.deepEqual(transitionAnnouncement('none', 'processing', describeAll), { message: 'none→processing' });
    });

    test('an empty message is not an announcement', () => {
        assert.equal(transitionAnnouncement('a', 'b', () => ({ message: '  ' })), null);
        assert.equal(transitionAnnouncement('a', 'b', () => null), null);
    });
});

describe('requirement extraction is announced at start, end and failure — once each (A11Y-045)', () => {
    const texts = {
        ai_extraction_started: 'Startet.',
        ai_extraction_completed: 'Ferdig: :count.',
        ai_extraction_failed: 'Feilet.',
    };

    test('the banner reduces to three states, whatever its progress text says', () => {
        assert.equal(extractionState(null), 'none');
        assert.equal(extractionState({ state: 'processing', count: 2 }), 'processing');
        assert.equal(extractionState({ state: 'failed' }), 'failed');
    });

    test('starting, finishing and failing each have their own message', () => {
        assert.deepEqual(extractionAnnouncement('none', 'processing', { texts }), { message: 'Startet.', politeness: 'polite' });
        assert.deepEqual(extractionAnnouncement('processing', 'none', { texts, extractedCount: 12 }), { message: 'Ferdig: 12.', politeness: 'polite' });
        assert.deepEqual(extractionAnnouncement('processing', 'failed', { texts }), { message: 'Feilet.', politeness: 'assertive' });
    });

    test('nothing else is spoken', () => {
        assert.equal(extractionAnnouncement('failed', 'none', { texts }), null);
        assert.equal(extractionAnnouncement('none', 'none', { texts }), null);
    });

    test('the workspace speaks through the shared regions; the banner is no longer a live region', () => {
        const source = code(read('Pages', 'App', 'AI', 'Show.jsx'));
        const banner = source.slice(source.indexOf('{requirementExtractionBanner ? ('), source.indexOf('{requirementExtractionBanner.state === \'failed\' ? ('));

        assert.ok(!banner.includes('aria-live'), 'the polled banner must not be a live region');
        assert.ok(!banner.includes("role={requirementExtractionBanner.state === 'failed' ? 'alert' : 'status'}"));
        assert.match(source, /useTransitionAnnouncement\(\s*extractionState\(requirementExtractionBanner\)/);
    });
});

describe('answer generation, saving and copying say what happened (A11Y-045, A11Y-116)', () => {
    const source = code(read('Pages', 'App', 'AI', 'Show.jsx'));

    test('start and finish of a generation are announced; a failure is the error box itself, as an alert', () => {
        assert.match(source, /announce\(a11y\.ai_answer_generating/);
        assert.match(source, /announce\(a11y\.ai_answer_ready/);
        assert.match(source, /<div role="alert" className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-base leading-6 text-rose-700">\s*\{wikiAnswerError\.message\}/);
    });

    test('a copy that worked is spoken; one that failed is an alert', () => {
        assert.match(source, /announce\(a11y\.ai_answer_copied/);
        assert.match(source, /data-testid="wiki-answer-copy-error"\s*role="alert"/);
        assert.match(source, /announce\(a11y\.ai_answer_saved/);
    });

    test('the buttons that start the work stay focusable while it runs (aria-disabled, not disabled)', () => {
        for (const id of ['wiki-answer-generate-button', 'wiki-answer-copy-button']) {
            const at = source.indexOf(id);
            const button = source.slice(at, source.indexOf('>', source.indexOf('className', at)));

            assert.match(button, /aria-disabled=/, `${id} must use aria-disabled while busy`);
            assert.ok(!/wikiAnswer(Generating|Copying)RequirementId === activeRequirement\.id\s*\n?\s*\|\|/.test(button.slice(0, button.indexOf('aria-disabled'))), `${id} must not be disabled while busy`);
        }
    });

    test('«Lag svar» is an action, not a toggle (A11Y-115)', () => {
        assert.ok(!source.includes('aria-pressed={isActiveRequirement}'));
    });
});

describe('the requirement card is not a button around buttons (A11Y-044)', () => {
    const source = code(read('Pages', 'App', 'AI', 'Show.jsx'));

    test('no role="button" on the card, and no keyboard handler standing in for one', () => {
        assert.ok(!source.includes("role={canOpenAnswerWorkspace ? 'button' : undefined}"));
        assert.ok(!/<article[^>]*onKeyDown/.test(source));
    });

    test('the requirement text is the button that opens the answer, and the open one is current', () => {
        assert.match(source, /id=\{`ai-req-\$\{requirement\.id\}-open`\}\s*type="button"/);
        assert.match(source, /aria-current=\{isActiveRequirement \? 'true' : undefined\}/);
        assert.match(source, /ring-2 ring-violet-500/, 'the active ring is at least 3:1, not violet-300');
    });
});

describe('no explanation button inside a label in the AI workspace (A11Y-135)', () => {
    for (const file of ['Pages/App/AI/Show.jsx', 'Pages/App/AI/Instructions.jsx']) {
        test(file, () => {
            for (const block of labelBlocks(read(...file.split('/')))) {
                assert.ok(!block.includes('<InfoHint'), `InfoHint inside a label:\n${block.slice(0, 200)}`);
            }
        });
    }

    test('Kravtype gets its «i» beside the label, through FormField', () => {
        const source = read('Pages', 'App', 'AI', 'Show.jsx');

        assert.equal((source.match(/info=\{<InfoHint size="sm" label="Vis forklaring for Kravtype"/g) ?? []).length, 2);
    });
});

describe('Go/No-go can be read and set without seeing colour (A11Y-003)', () => {
    const source = code(read('Pages', 'App', 'Notices', 'GoNoGoAssessment.jsx'));

    test('each criterion’s ratings are a group named by the criterion', () => {
        assert.match(source, /role="group" aria-labelledby=\{titleId\}/);
    });

    test('the chosen rating is pressed and carries a check mark, not only a colour', () => {
        assert.match(source, /aria-pressed=\{isSelected\}/);
        assert.match(source, /\{isSelected \? <CheckIcon/);
    });

    test('the reasoning field and the help button name their criterion; help gives focus back', () => {
        assert.match(source, /aria-label=\{\(a11y\.gonogo_comment/);
        assert.match(source, /aria-label=\{\(a11y\.gonogo_help/);
        assert.match(source, /returnFocusRef=\{helpTriggerRef\}/);
    });

    test('saving keeps focus on the button and says when it failed', () => {
        assert.match(source, /aria-disabled=\{saveUnavailable \|\| undefined\}/);
        assert.ok(!source.includes('disabled={isSaving || !hasPendingChanges}'));
        // The alert names the criteria to correct when the server says which (validation work package 2).
        assert.match(source, /role="alert"[^>]*>\s*\{saveError \?\? a11y\.gonogo_save_failed/);
    });

    test('a verdict that appears or changes is spoken once', () => {
        assert.match(source, /useTransitionAnnouncement\(verdict\?\.level \?\? 'none'/);
    });

    test('the verdict text is full strength, and the ratings can wrap on a phone (1.4.3, 1.4.10)', () => {
        assert.ok(!/opacity-(75|90)/.test(source), 'faded verdict text fell below 4.5:1');
        assert.ok(!source.includes('flex shrink-0 flex-wrap items-center gap-1'), 'a box that cannot shrink cannot wrap');
    });
});

describe('case and tender pages (Fase 4)', () => {
    test('the case page has one main landmark — the layout’s', () => {
        assert.ok(!/<main\b/.test(code(read('Pages', 'App', 'Notices', 'SavedShow.jsx'))));
    });

    test('the case progress says which steps are done, not only in colour (A11Y-024)', () => {
        const source = code(read('Components', 'App', 'BidStatusPipeline.jsx'));

        assert.match(source, /<ol aria-label=/);
        assert.match(source, /aria-current=\{isActive \? 'step' : undefined\}/);
        assert.match(source, /<span className="sr-only">, \{stateText\}<\/span>/);
        assert.ok(!source.includes('text-[11px]'));
    });

    test('live search is named by its visible title, not its placeholder (A11Y-040)', () => {
        const source = code(read('Pages', 'App', 'Notices', 'Index.jsx'));

        assert.match(source, /type="search"\s*aria-labelledby="live-search-title"/);
        assert.match(source, /id="live-search-title"/);
    });

    test('no label wraps another label, and every history field has a label', () => {
        const source = read('Pages', 'App', 'Notices', 'Index.jsx');

        for (const block of labelBlocks(source)) {
            assert.equal((block.match(/<label\b/g) ?? []).length, 1, `nested label:\n${block.slice(0, 200)}`);
        }

        for (const field of ['procurement_type', 'follow_up_mode', 'follow_up_offset_months']) {
            assert.match(source, new RegExp(`FormField id=\\{\`${field}_\\$\\{notice\\.id\\}\`\\}`), field);
        }
    });

    test('a rejected submit puts focus on the field in every Fase 4 form', () => {
        const savedShow = read('Pages', 'App', 'Notices', 'SavedShow.jsx');
        const index = read('Pages', 'App', 'Notices', 'Index.jsx');
        const ai = read('Pages', 'App', 'AI', 'Show.jsx');

        for (const handler of ['focusPhaseCommentError', 'focusInfoItemError', 'focusCloseInfoItemError', 'focusNoGoError', 'focusReopenNoGoError', 'focusArchiveError', 'focusCaseAccessError']) {
            assert.match(savedShow, new RegExp(`onError: ${handler}|${handler}\\(\\)`), handler);
        }

        for (const handler of ['focusPrivateRequestError', 'focusEditingCardError', 'focusArchiveError']) {
            assert.match(index, new RegExp(`onError: ${handler}|${handler}\\(\\)`), handler);
        }

        for (const handler of ['focusUploadError', 'focusManualRequirementError', 'focusRequirementEditError']) {
            assert.match(ai, new RegExp(`onError: ${handler}`), handler);
        }
    });

    test('the CPV options stay out of the Tab order, and the listbox holds only options', () => {
        const source = code(read('Pages', 'App', 'Notices', 'CpvSelector.jsx'));
        const listbox = source.slice(source.indexOf('role="listbox"'), source.indexOf(') : null}', source.indexOf('role="listbox"')));

        assert.match(source, /role="option"\s*tabIndex=\{-1\}/);
        assert.ok(!listbox.includes('Populære valg') && !listbox.includes('Laster CPV-treff') && !listbox.includes('Ingen CPV-treff'));
        assert.match(source, /aria-expanded=\{hasOptions\}/);
    });

    test('a saved notice keeps the focus that pressed «Lagre»', () => {
        const card = code(read('Components', 'App', 'DiscoveryNoticeCard.jsx'));
        const save = card.slice(card.indexOf('onClick={saveNoticeToWorklist}') - 200, card.indexOf('</button>', card.indexOf('onClick={saveNoticeToWorklist}')));

        assert.match(save, /aria-disabled=\{notice\.is_saved \|\| notice\.is_in_history \|\| undefined\}/);
        assert.ok(!/(?<!-)disabled=\{notice\.is_saved/.test(save));
        assert.match(save, /aria-describedby=\{titleId\}/);
    });
});

describe('dialogs and uploads in the AI workspace (A11Y-002, A11Y-011)', () => {
    const source = code(read('Pages', 'App', 'AI', 'Show.jsx'));

    test('«Velg ansvarlig» is an ActionDialog, not a hand-made overlay', () => {
        assert.ok(!source.includes('fixed inset-0'));
        assert.match(source, /<ActionDialog\s*isOpen=\{responsiblePickerRequirement !== null\}/);
        assert.match(source, /aria-label=\{a11y\.close_dialog \?\? 'Lukk dialog'\}/);
    });

    test('case documents are picked through FilePickerField, several at a time, focusable while uploading', () => {
        assert.match(source, /<FilePickerField\s*id="ai-documents"/);
        assert.match(source, /multiple\s*files=\{documentUploadForm\.data\.documents\}/);
        assert.match(source, /busy=\{documentUploadForm\.processing\}/);
        assert.match(source, /announceError=\{false\}/, 'focus moves to the field, so no second reading as an alert');

        const picker = read('Components', 'App', 'FilePickerField.jsx');
        assert.match(picker, /aria-disabled=\{busy \|\| undefined\}/);
        assert.match(picker, /onChange\(multiple \? Array\.from\(event\.target\.files \?\? \[\]\)/);
    });

    test('the two columns cannot be widened past a phone by their content (1.4.10)', () => {
        assert.match(source, /grid grid-cols-1 gap-5 lg:grid-cols-2/);
        assert.match(source, /min-w-0 flex-1 space-y-1 sm:min-w-\[16rem\]/);
    });

    test('a box that scrolls vertically is a named region only while it scrolls', () => {
        const region = read('Components', 'App', 'ScrollRegion.jsx');

        assert.match(region, /element\.scrollHeight > element\.clientHeight \+ 1/);
        assert.match(source, /<ScrollRegion\s*axis="y"\s*innerRef=\{wikiAnswerRenderedRef\}/);
    });
});

describe('focus comes back after a row action only if it was lost (A11Y-014)', () => {
    test('lost means nothing, <body>, or an element that left the document', () => {
        const body = {};

        assert.equal(focusIsLost(null, body), true);
        assert.equal(focusIsLost(body, body), true);
        assert.equal(focusIsLost({ isConnected: false }, body), true);
        assert.equal(focusIsLost({ isConnected: true }, body), false);
    });

    function fakeWindow() {
        return { requestAnimationFrame: (callback) => callback(), setTimeout: (callback) => callback() };
    }

    function fakeElement(id) {
        return {
            id,
            isConnected: true,
            focused: false,
            matches: () => true,
            hasAttribute: () => false,
            setAttribute() {},
            focus() { this.focused = true; },
        };
    }

    test('the first candidate still in the page gets focus; ids are looked up when it runs', () => {
        const body = {};
        const fallback = fakeElement('fallback');
        const doc = { body, activeElement: body, getElementById: (id) => (id === 'fallback' ? fallback : null) };

        refocusIfLost(['gone', 'fallback'], { doc, win: fakeWindow() });

        assert.equal(fallback.focused, true);
    });

    test('`always` places focus even when it was not lost (a re-used row button)', () => {
        const target = fakeElement('target');
        const doc = { body: {}, activeElement: { isConnected: true }, getElementById: () => target };

        refocusIfLost(['target'], { doc, win: fakeWindow(), always: true });

        assert.equal(target.focused, true);
    });

    test('a user who has moved on is left where they are', () => {
        const body = {};
        const target = fakeElement('target');
        const doc = { body, activeElement: { isConnected: true }, getElementById: () => target };

        refocusIfLost(['target'], { doc, win: fakeWindow() });

        assert.equal(target.focused, false);
    });
});

describe('deleting a business-review date keeps focus in the editor (A11Y-146, A11Y-147)', () => {
    test('next date first, then the previous one, then «Legg til»', () => {
        assert.deepEqual(businessReviewFocusCandidates(7, 1), ['business_review_7_1', 'business_review_7_0', 'business-review-add-7']);
        assert.deepEqual(businessReviewFocusCandidates(7, 0), ['business_review_7_0', 'business-review-add-7']);
    });

    test('the editor uses it, and «Legg til» has a label (it read an undefined key)', () => {
        const source = read('Pages', 'App', 'Notices', 'Index.jsx');

        assert.match(source, /refocusIfLost\(businessReviewFocusCandidates\(noticeId, index\), \{ always: true \}\)/);
        assert.match(source, /id=\{`business-review-add-\$\{notice\.id\}`\}\s*onClick=\{addBusinessReview\}/);
        assert.ok(!source.includes('noticesText.addBusinessReview'));
        assert.match(source, /\{noticesText\.businessReviewAdd\}/);
    });
});

describe('Fase 4 strings exist in both languages', () => {
    for (const key of ['pipeline_label', 'step_completed', 'step_current', 'step_upcoming', 'gonogo_comment', 'gonogo_help', 'gonogo_verdict', 'gonogo_save_failed', 'ai_extraction_started', 'ai_extraction_completed', 'ai_extraction_failed', 'ai_answer_generating', 'ai_answer_ready', 'ai_answer_saved', 'ai_answer_copied', 'pagination']) {
        test(key, () => {
            assert.match(lang('no'), new RegExp(`'${key}' => '`));
            assert.match(lang('en'), new RegExp(`'${key}' => '`));
        });
    }
});
