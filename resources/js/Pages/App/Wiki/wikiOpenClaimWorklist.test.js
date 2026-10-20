import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
    buildOpenClaimWorklist,
    claimFindingIsOpen,
    claimInlineNoteState,
    claimReasonHeadingKey,
    claimWorklistReasonKey,
    claimWorklistTitle,
    resolveDistinctPageExcerpt,
    worklistRangesByCard,
} from './wikiOpenClaimWorklist.js';

const here = dirname(fileURLToPath(import.meta.url));
const showSource = readFileSync(join(here, 'Show.jsx'), 'utf8');

const requiresAction = (claim) => claim.approval_status === 'pending'
    && (claim.content_origin === 'best_practice'
        || claim.content_origin === 'internal_error'
        || claim.content_origin === 'unsupported_generated_content'
        || claim.conflict_flag
        || claim.source_status === 'missing_source'
        || claim.source_status === 'missing_excerpt');

const bestPractice = (id, text, extra = {}) => ({
    id,
    claim_text: text,
    approval_status: 'pending',
    content_origin: 'best_practice',
    content_block_key: 'block-0012',
    ...extra,
});

/**
 * The reported bug: the header said "3 påstander krever behandling" while a single card rendered
 * below it, because three best-practice claims in one section collapse into one review unit. The
 * work list is what makes the number and the visible work agree again.
 */
describe('open claim work list', () => {
    const threeInOneSection = [
        bestPractice(244, 'Partene skal etablere en årlig møtekalender som angir faste møtenivåer.'),
        bestPractice(245, 'Partene skal etablere en felles beslutningslogg med ansvar og frist.'),
        bestPractice(246, 'Partene skal etablere tydelige eskaleringskriterier.'),
    ];
    const oneUnit = [{ claim: threeInOneSection[0], claimIds: [244, 245, 246] }];

    test('three open claims sharing one card still produce three work list entries', () => {
        const worklist = buildOpenClaimWorklist(oneUnit, threeInOneSection, requiresAction);

        assert.equal(worklist.length, 3);
        assert.deepEqual(worklist.map((e) => e.claimId), [244, 245, 246]);
        assert.deepEqual(worklist.map((e) => e.position), [1, 2, 3]);
        assert.deepEqual(worklist.map((e) => e.total), [3, 3, 3]);
    });

    test('each entry is named by its own claim text, never by an id', () => {
        const worklist = buildOpenClaimWorklist(oneUnit, threeInOneSection, requiresAction);

        assert.equal(worklist[0].title, 'Partene skal etablere en årlig møtekalender som angir faste møtenivåer.');
        assert.equal(worklist[1].title, 'Partene skal etablere en felles beslutningslogg med ansvar og frist.');
        assert.equal(worklist[2].title, 'Partene skal etablere tydelige eskaleringskriterier.');

        for (const entry of worklist) {
            assert.equal(entry.title.includes(String(entry.claimId)), false);
            assert.equal(/^\s*$/.test(entry.title), false);
        }
    });

    test('every entry points at the card that actually decides it', () => {
        const worklist = buildOpenClaimWorklist(oneUnit, threeInOneSection, requiresAction);

        assert.deepEqual(worklist.map((e) => e.cardClaimId), [244, 244, 244]);
        assert.deepEqual(worklist.map((e) => e.sharedDecisionCount), [3, 3, 3]);
    });

    test('claims decided on separate cards each get their own anchor', () => {
        const claims = [
            bestPractice(10, 'Forslag A', { content_block_key: 'block-1' }),
            bestPractice(20, 'Forslag B', { content_block_key: 'block-2' }),
        ];
        const units = [
            { claim: claims[0], claimIds: [10] },
            { claim: claims[1], claimIds: [20] },
        ];

        const worklist = buildOpenClaimWorklist(units, claims, requiresAction);

        assert.deepEqual(worklist.map((e) => e.cardClaimId), [10, 20]);
        assert.deepEqual(worklist.map((e) => e.sharedDecisionCount), [1, 1]);
    });

    test('a claim that no longer needs action leaves the list and the count drops', () => {
        const afterHandlingOne = [
            { ...threeInOneSection[0], approval_status: 'approved' },
            threeInOneSection[1],
            threeInOneSection[2],
        ];
        const unitAfter = [{ claim: afterHandlingOne[1], claimIds: [244, 245, 246] }];

        const worklist = buildOpenClaimWorklist(unitAfter, afterHandlingOne, requiresAction);

        assert.equal(worklist.length, 2);
        assert.deepEqual(worklist.map((e) => e.claimId), [245, 246]);
        assert.deepEqual(worklist.map((e) => e.position), [1, 2]);
        assert.deepEqual(worklist.map((e) => e.total), [2, 2]);
        assert.deepEqual(worklist.map((e) => e.sharedDecisionCount), [2, 2]);
    });

    test('no open claims produce an empty list rather than a placeholder row', () => {
        assert.deepEqual(buildOpenClaimWorklist([], [], requiresAction), []);
        assert.deepEqual(buildOpenClaimWorklist(undefined, undefined, requiresAction), []);
    });

    test('the reason for each claim is derived, not invented', () => {
        assert.equal(claimWorklistReasonKey({ content_origin: 'best_practice' }), 'best_practice');
        assert.equal(claimWorklistReasonKey({ content_origin: 'internal_error' }), 'defect');
        assert.equal(claimWorklistReasonKey({ content_origin: 'unsupported_generated_content' }), 'defect');
        assert.equal(claimWorklistReasonKey({ content_origin: 'source_based', conflict_flag: true }), 'conflict');
        assert.equal(claimWorklistReasonKey({ content_origin: 'source_based', source_status: 'missing_source' }), 'missing_source');
        assert.equal(claimWorklistReasonKey({ content_origin: 'source_based', source_status: 'missing_excerpt' }), 'missing_excerpt');
        assert.equal(claimWorklistReasonKey({ content_origin: 'source_based', source_status: 'ok' }), 'other');
    });

    test('a long claim is trimmed on a word boundary, not mid-word', () => {
        const long = `${'Leverandøren skal dokumentere '.repeat(12)}avslutning.`;
        const title = claimWorklistTitle({ claim_text: long });

        assert.ok(title.length <= 141, 'the excerpt stays short enough to scan');
        assert.ok(title.endsWith('…'));
        assert.equal(title.includes('  '), false);
        assert.ok(long.startsWith(title.slice(0, -1).trim()), 'the excerpt is a verbatim prefix');
    });

    test('a card deciding several claims names the whole range it covers', () => {
        const worklist = buildOpenClaimWorklist(oneUnit, threeInOneSection, requiresAction);
        const ranges = worklistRangesByCard(worklist);

        assert.deepEqual(ranges.get(244), { first: 1, last: 3 });
    });

    test('a card deciding one claim names a single position', () => {
        const claims = [bestPractice(10, 'Forslag A', { content_block_key: 'block-1' })];
        const ranges = worklistRangesByCard(
            buildOpenClaimWorklist([{ claim: claims[0], claimIds: [10] }], claims, requiresAction),
        );

        assert.deepEqual(ranges.get(10), { first: 1, last: 1 });
    });

    test('a page excerpt that only differs by punctuation is not repeated', () => {
        const text = 'Partene skal etablere en årlig møtekalender.';

        assert.equal(resolveDistinctPageExcerpt(text, 'Partene skal etablere en årlig møtekalender'), null);
        assert.equal(resolveDistinctPageExcerpt(text, '  Partene skal   etablere en årlig møtekalender.  '), null);
        assert.equal(resolveDistinctPageExcerpt(text, ''), null);
        assert.equal(resolveDistinctPageExcerpt(text, null), null);
    });

    test('a page excerpt that genuinely differs is kept verbatim', () => {
        const excerpt = 'Partene skal etablere en årlig møtekalender og publisere den internt.';

        assert.equal(
            resolveDistinctPageExcerpt('Partene skal etablere en årlig møtekalender.', excerpt),
            excerpt,
        );
    });

    test('a claim without text yields an empty title so the UI can fall back', () => {
        assert.equal(claimWorklistTitle({}), '');
        assert.equal(claimWorklistTitle({ claim_text: '   ' }), '');
        assert.equal(claimWorklistTitle({ page_excerpt: 'Fra siden' }), 'Fra siden');
    });
});

/**
 * Source-level guards: the project has no JSX renderer, and these are the exact regressions the
 * cleanup is meant to prevent.
 */
describe('the claim card no longer leads with system terminology', () => {
    test('"Funn #" is not the card headline any more', () => {
        const headline = showSource.slice(
            showSource.indexOf('const renderClaimCard'),
            showSource.indexOf('const documentOwnerApprovalCardClass'),
        );

        assert.equal(
            headline.includes("runs_findings_id_label ?? 'Funn #:id'"),
            false,
            'the claim card must not print the Funn id as its title',
        );
    });

    test('the technical reference is still available, discreetly', () => {
        assert.ok(showSource.includes('claim_card_reference_label'));
        assert.ok(showSource.includes('findingIdForClaim(claim)'), 'the id itself is still derived');
    });

    test('the work list is rendered from the shared helper', () => {
        assert.ok(showSource.includes("from './wikiOpenClaimWorklist'"));
        assert.ok(showSource.includes('buildOpenClaimWorklist('));
        assert.ok(showSource.includes('data-testid="open-claim-worklist"'));
        assert.ok(showSource.includes('data-testid="open-claim-worklist-entry"'));
    });

    test('both decision actions are translated, not hardcoded', () => {
        assert.ok(showSource.includes('claim_card_keep_text'));
        assert.ok(showSource.includes('claim_card_remove_text'));
    });

    test('the duplicated badge row above the work list is gone', () => {
        const verificationBlock = showSource.slice(showSource.indexOf('{verificationOpen && ('));

        assert.equal(
            verificationBlock.includes('label={`${openClaims.length} ${tw.verification_basis_open_heading'),
            false,
            'the open-claim count must not be repeated as a badge inside the section it heads',
        );
    });

    test('page quality findings stay a separate section', () => {
        assert.ok(showSource.includes('verification_basis_structural_heading'));
        assert.ok(showSource.includes('data-testid="page-quality-finding"'));
    });
});

describe('claimReasonHeadingKey', () => {
    const unsupported = { id: 7, content_origin: 'unsupported_generated_content', finding_explanation: 'Kilden sier «bør».' };

    test('an open card asks the reader to decide, whatever its origin', () => {
        assert.equal(claimReasonHeadingKey({ ...unsupported, approval_status: 'pending' }, true), 'open');
        assert.equal(claimReasonHeadingKey(bestPractice(1, 'Tekst'), true), 'open');
    });

    test('a decided best-practice suggestion is a QA finding, so its reason is the finding\'s', () => {
        assert.equal(claimReasonHeadingKey({ ...bestPractice(1, 'Tekst'), approval_status: 'approved' }, false), 'decided_finding');
    });

    test('a decided unsupported claim may be diagnostic only, so the heading stays neutral', () => {
        assert.equal(claimReasonHeadingKey({ ...unsupported, approval_status: 'approved' }, false), 'neutral');
        assert.equal(claimReasonHeadingKey({ ...unsupported, approval_status: 'rejected' }, false), 'neutral');
    });

    test('the card heading is chosen by the helper and every heading is translated', () => {
        assert.ok(showSource.includes('claimReasonHeadingKey(claim, isOpenGroup)'));
        assert.ok(showSource.includes('tw.claim_card_why_label'));
        assert.ok(showSource.includes('tw.claim_card_decided_reason_label'));
        assert.ok(showSource.includes('tw.claim_card_reason_label'));
    });
});

describe('claimInlineNoteState', () => {
    const finding = (extra) => ({ id: 472, content_origin: 'unsupported_generated_content', finding_category_label: 'Endret normativ betydning', ...extra });

    test('a pending claim needs a review, and a handler is offered «Åpne og behandle»', () => {
        assert.deepEqual(
            claimInlineNoteState(finding({ approval_status: 'pending', can_handle: true })),
            { stateKey: 'requires_review', actionKey: 'open_and_handle' },
        );
    });

    test('a reader without access is only offered to view it', () => {
        assert.equal(claimInlineNoteState(finding({ approval_status: 'pending', can_handle: false })).actionKey, 'view');
    });

    test('a decided claim is never called open, whatever the system still recommends', () => {
        for (const status of ['approved', 'rejected']) {
            assert.deepEqual(
                claimInlineNoteState(finding({ approval_status: status, can_handle: true, requires_decision: true, system_recommends_blocking: true })),
                { stateKey: 'decided', actionKey: 'view' },
            );
        }
    });
});

describe('the article shows a note, not the handling card', () => {
    test('the focused paragraph renders the compact note, never a claim card', () => {
        assert.ok(showSource.includes('renderInlineQaNote(focusedReviewClaims[0])'));
        assert.equal(showSource.includes('inlineReview'), false);
        assert.equal(showSource.includes('Vurdering for dette avsnittet'), false);
    });

    test('the note moves to the claim card in the verification basis', () => {
        assert.ok(showSource.includes('const verificationClaims = claims;'));
        assert.ok(showSource.includes('onClick={() => openClaimInVerificationBasis(claim)}'));
    });

    test('the note strings are translated', () => {
        for (const key of ['inline_qa_note_label', 'inline_qa_note_requires_review', 'inline_qa_note_decided', 'inline_qa_note_open_and_handle', 'inline_qa_note_view']) {
            assert.ok(showSource.includes(`tw.${key}`), key);
        }
    });
});

describe('claimFindingIsOpen — the rule the Kjøringer list uses', () => {
    test('a claim finding follows the backend finding_resolution, not approval_status alone', () => {
        assert.equal(claimFindingIsOpen({ approval_status: 'pending', finding_resolution: 'open' }), true);
        // An explicitly accepted deviation on a pending claim is handled: the QA gate releases it.
        assert.equal(claimFindingIsOpen({ approval_status: 'pending', finding_resolution: 'accepted_deviation' }), false);
        for (const resolution of ['approved', 'approved_edited', 'rejected']) {
            assert.equal(claimFindingIsOpen({ approval_status: 'approved', finding_resolution: resolution }), false, resolution);
        }
    });

    test('a claim without a finding resolution is open while it waits for a decision', () => {
        assert.equal(claimFindingIsOpen({ content_origin: 'best_practice', approval_status: 'pending', finding_resolution: null }), true);
        assert.equal(claimFindingIsOpen({ content_origin: 'best_practice', approval_status: 'approved' }), false);
    });

    test('the note calls an accepted deviation handled, as Kjøringer does', () => {
        assert.deepEqual(
            claimInlineNoteState({ approval_status: 'pending', finding_resolution: 'accepted_deviation', can_handle: true }),
            { stateKey: 'decided', actionKey: 'view' },
        );
    });

    test('the work list reads the same rule', () => {
        assert.ok(showSource.includes('claimFindingIsOpen(claim)'));
    });
});

describe('claim decisions: refusals and drafts', () => {
    test('a refused decision is said on the card and never opens the raw error overlay', () => {
        assert.ok(showSource.includes('onHttpException: (response) => {'));
        assert.ok(showSource.includes('describeClaimActionRefusal(response?.status, response?.data)'));
        assert.ok(showSource.includes('data-testid="claim-action-error"'));
        const start = showSource.indexOf('onHttpException: (response) => {');
        assert.match(showSource.slice(start, start + 300), /return false;/);
    });

    test('the edited text and comment are dropped only once the server stored the decision', () => {
        const options = showSource.slice(showSource.indexOf('const claimActionOptions = (claim) => ({'), showSource.indexOf('const sendClaimAction = '));
        const onSuccess = options.slice(options.indexOf('onSuccess:'), options.indexOf('onError:'));
        assert.ok(onSuccess.includes('setClaimTextEdits'));
        assert.ok(onSuccess.includes('setApprovalComments'));
        assert.equal(options.slice(options.indexOf('onError:')).includes('setClaimTextEdits'), false);
    });

    test('every decision goes through one guarded dispatch', () => {
        for (const action of ['approve', 'reject', 'unapprove', 'blocking']) {
            assert.ok(showSource.includes(`/claims/\${claim.id}/${action}\``), action);
        }
        assert.equal((showSource.match(/router\.patch\(url, data, claimActionOptions\(claim\)\)/g) ?? []).length, 1);
        assert.equal(showSource.includes('claimActionOptions(claim),'), false);
    });

    test('saving the article carries the finding it was opened from', () => {
        const save = showSource.slice(showSource.indexOf('const saveArticleEditing = () => {'), showSource.indexOf('const targetReviewSection'));
        assert.ok(save.includes('claim_id: reviewReference?.back_url ? (reviewReference.claim_id ?? undefined) : undefined'));
        assert.ok(save.includes('back_url: reviewReference?.back_url ?? undefined'));
    });

    test('unsaved text asks once before the page is left', () => {
        assert.ok(showSource.includes("router.on('before'"));
        assert.ok(showSource.includes("window.addEventListener('beforeunload', onBeforeUnload)"));
        assert.ok(showSource.includes('tw.unsaved_changes_confirm'));
    });
});
