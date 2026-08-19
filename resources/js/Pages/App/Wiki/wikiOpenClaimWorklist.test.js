import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
    buildOpenClaimWorklist,
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
