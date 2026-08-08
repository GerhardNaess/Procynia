import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import {
    groupContentBlocksBySection,
    bestPracticeSectionApprovalState,
    groupBestPracticeClaimsForReview,
    resolveBestPracticeSectionForBlock,
    hasInvalidBestPracticeMetadata,
    hasRenderableBestPracticeMetadata,
} from './wikiBestPracticeSectionLogic.js';

describe('groupContentBlocksBySection', () => {
    test('consecutive blocks sharing the same section_key become one section group', () => {
        const blocks = [
            { block_key: 'h1', section_key: '1|h1', section_heading: 'Begrepsramme: ITIL og Incident management' },
            { block_key: 'p1', section_key: '1|h1', section_heading: 'Begrepsramme: ITIL og Incident management' },
            { block_key: 'p2', section_key: '1|h1', section_heading: 'Begrepsramme: ITIL og Incident management' },
        ];

        const groups = groupContentBlocksBySection(blocks);

        assert.equal(groups.length, 1);
        assert.equal(groups[0].type, 'section');
        assert.equal(groups[0].blocks.length, 3);
        assert.equal(groups[0].headingText, 'Begrepsramme: ITIL og Incident management');
    });

    test('a different section_key starts a new group', () => {
        const blocks = [
            { block_key: 'h1', section_key: '1|h1', section_heading: 'Om illustrasjonen' },
            { block_key: 'p1', section_key: '1|h1', section_heading: 'Om illustrasjonen' },
            { block_key: 'h2', section_key: '1|h2', section_heading: 'Bruksområde' },
            { block_key: 'p2', section_key: '1|h2', section_heading: 'Bruksområde' },
        ];

        const groups = groupContentBlocksBySection(blocks);

        assert.equal(groups.length, 2);
        assert.equal(groups[0].sectionKey, '1|h1');
        assert.equal(groups[1].sectionKey, '1|h2');
    });

    test('a block with no section_key is its own single, ungrouped entry', () => {
        const blocks = [
            { block_key: 's1', section_key: null },
        ];

        const groups = groupContentBlocksBySection(blocks);

        assert.equal(groups.length, 1);
        assert.equal(groups[0].type, 'single');
        assert.equal(groups[0].block.block_key, 's1');
    });

    test('a source-based block between two sections keeps them as separate groups', () => {
        const blocks = [
            { block_key: 'h1', section_key: '1|h1', section_heading: 'Om illustrasjonen' },
            { block_key: 's1', section_key: null, content_origin: 'source_based' },
            { block_key: 'h2', section_key: '1|h2', section_heading: 'Bruksområde' },
        ];

        const groups = groupContentBlocksBySection(blocks);

        assert.equal(groups.length, 3);
        assert.deepEqual(groups.map((g) => g.type), ['section', 'single', 'section']);
    });

    test('two consecutive unrelated single blocks are never merged just because both lack a section_key', () => {
        const blocks = [
            { block_key: 't1', section_key: null, block_type: 'table' },
            { block_key: 'i1', section_key: null, block_type: 'image' },
        ];

        const groups = groupContentBlocksBySection(blocks);

        assert.equal(groups.length, 2);
        assert.equal(groups[0].block.block_key, 't1');
        assert.equal(groups[1].block.block_key, 'i1');
    });

    test('empty input returns an empty list', () => {
        assert.deepEqual(groupContentBlocksBySection([]), []);
        assert.deepEqual(groupContentBlocksBySection(undefined), []);
    });

    test('best-practice display requires persistent origin and reason', () => {
        assert.equal(hasRenderableBestPracticeMetadata({
            content_origin: 'best_practice',
            best_practice_reason: 'Procynia-generert faglig anbefaling.',
        }), true);

        assert.equal(hasRenderableBestPracticeMetadata({
            content_origin: 'source_based',
            best_practice_reason: 'Procynia-generert faglig anbefaling.',
        }), false);

        assert.equal(hasRenderableBestPracticeMetadata({
            content_origin: 'best_practice',
            best_practice_reason: '   ',
        }), false);
    });

    test('best-practice metadata issue is flagged only for blocks with best-practice origin but missing reason', () => {
        assert.equal(hasInvalidBestPracticeMetadata({
            content_origin: 'best_practice',
            best_practice_reason: '',
        }), true);

        assert.equal(hasInvalidBestPracticeMetadata({
            content_origin: 'source_based',
            best_practice_reason: '',
        }), false);
    });

    // Wiki run-5: a structural block (page title, section heading, "Se også" cross-reference)
    // must never render with the "Beste praksis" badge/frame — even if it happens to carry a
    // best_practice_reason-shaped string (e.g. leftover data), only the literal content_origin
    // value 'best_practice' can ever trigger it.
    test('a structural block never renders the best-practice badge, even with a reason-like string present', () => {
        assert.equal(hasRenderableBestPracticeMetadata({
            content_origin: 'structural',
            best_practice_reason: null,
        }), false);

        assert.equal(hasRenderableBestPracticeMetadata({
            content_origin: 'structural',
            best_practice_reason: 'Not actually a best-practice reason.',
        }), false);

        assert.equal(hasInvalidBestPracticeMetadata({
            content_origin: 'structural',
            best_practice_reason: null,
        }), false);
    });
});

/**
 * One best_practice block legitimately yields several atomic claims (extraction is unchanged),
 * but every review action already applies to the whole block on the backend — cascadeBlockDecision()
 * propagates approve/reject to all pending siblings on the same (page version, block key), and
 * both the text edit and the removal rewrite the block's markdown. Showing one card per claim
 * therefore asked the reviewer to decide the same thing repeatedly.
 */
describe('groupBestPracticeClaimsForReview — one review card per best-practice block', () => {
    const bp = (id, blockKey, extra = {}) => ({
        id,
        content_origin: 'best_practice',
        content_block_key: blockKey,
        enterprise_wiki_page_version_id: 18,
        approval_status: 'pending',
        ...extra,
    });

    test('3 claims from the same block collapse into 1 review unit that counts them', () => {
        const units = groupBestPracticeClaimsForReview([
            bp(1, 'block-0002'),
            bp(2, 'block-0002'),
            bp(3, 'block-0002'),
        ]);

        assert.equal(units.length, 1);
        assert.equal(units[0].claimCount, 3);
        assert.deepEqual(units[0].claimIds, [1, 2, 3]);
        assert.equal(units[0].claim.id, 1, 'the first claim represents the block');
    });

    test('2 distinct best-practice blocks stay 2 review units', () => {
        const units = groupBestPracticeClaimsForReview([
            bp(1, 'block-0002'),
            bp(2, 'block-0005'),
            bp(3, 'block-0002'),
        ]);

        assert.equal(units.length, 2);
        assert.deepEqual(units.map((u) => u.claimCount), [2, 1]);
        assert.deepEqual(units.map((u) => u.claim.id), [1, 2], 'input order is preserved');
    });

    test('the same block key on a different page version is never merged', () => {
        const units = groupBestPracticeClaimsForReview([
            bp(1, 'block-0002', { enterprise_wiki_page_version_id: 18 }),
            bp(2, 'block-0002', { enterprise_wiki_page_version_id: 19 }),
        ]);

        assert.equal(units.length, 2);
    });

    test('non-best-practice claims are never grouped — each keeps its own card', () => {
        const units = groupBestPracticeClaimsForReview([
            { id: 1, content_origin: 'internal_error', content_block_key: 'block-0002', enterprise_wiki_page_version_id: 18 },
            { id: 2, content_origin: 'unsupported_generated_content', content_block_key: 'block-0002', enterprise_wiki_page_version_id: 18 },
        ]);

        assert.equal(units.length, 2);
        assert.deepEqual(units.map((u) => u.claimCount), [1, 1]);
    });

    test('a best-practice claim without a stable block anchor stays its own card', () => {
        const units = groupBestPracticeClaimsForReview([
            bp(1, null),
            bp(2, '   '),
            bp(3, 'block-0002'),
        ]);

        assert.equal(units.length, 3);
    });

    test('empty and missing input are handled without throwing', () => {
        assert.deepEqual(groupBestPracticeClaimsForReview([]), []);
        assert.deepEqual(groupBestPracticeClaimsForReview(undefined), []);
    });
});

/**
 * A visual "Beste praksis" section spans SEVERAL content blocks (a heading block plus its
 * paragraphs). Grouping review by block key alone still produced one card per block, and — because
 * the panel was rendered from inside the matching block — physically split the section's own
 * paragraphs apart, leaving everything after the first outside the review and out of the edit
 * field. The review unit is now the section, using the same boundaries the article view draws.
 */
describe('resolveBestPracticeSectionForBlock / section-level review units', () => {
    // One visual section: 3 best_practice blocks sharing a server-stamped section_key.
    const sectionBlocks = [
        { block_key: 'block-0002', section_key: 'bp-1', section_heading: 'Styring', content_origin: 'best_practice', markdown: '## Styring' },
        { block_key: 'block-0003', section_key: 'bp-1', content_origin: 'best_practice', markdown: 'Leverandøren skal etablere rutiner.' },
        { block_key: 'block-0004', section_key: 'bp-1', content_origin: 'best_practice', markdown: 'Leverandøren skal dokumentere avvik.' },
        { block_key: 'block-0009', content_origin: 'source_based', markdown: 'Kildeinnhold.' },
    ];

    const bpClaim = (id, blockKey) => ({
        id,
        content_origin: 'best_practice',
        content_block_key: blockKey,
        enterprise_wiki_page_version_id: 18,
        approval_status: 'pending',
    });

    test('the section is resolved from any of its blocks, in order, with the full combined text', () => {
        for (const key of ['block-0002', 'block-0003', 'block-0004']) {
            const section = resolveBestPracticeSectionForBlock(sectionBlocks, key);

            assert.equal(section.sectionKey, 'bp-1');
            assert.deepEqual(section.blockKeys, ['block-0002', 'block-0003', 'block-0004']);
            assert.equal(
                section.markdown,
                '## Styring\n\nLeverandøren skal etablere rutiner.\n\nLeverandøren skal dokumentere avvik.',
            );
        }
    });

    test('a block outside any section resolves to null', () => {
        assert.equal(resolveBestPracticeSectionForBlock(sectionBlocks, 'block-0009'), null);
        assert.equal(resolveBestPracticeSectionForBlock(sectionBlocks, 'missing'), null);
        assert.equal(resolveBestPracticeSectionForBlock(sectionBlocks, null), null);
    });

    // Required scenario: 3 best_practice paragraphs in one visual section -> 1 review panel.
    test('claims spread across a section\'s 3 blocks collapse into one review unit', () => {
        const units = groupBestPracticeClaimsForReview(
            [bpClaim(1, 'block-0002'), bpClaim(2, 'block-0003'), bpClaim(3, 'block-0004')],
            sectionBlocks,
        );

        assert.equal(units.length, 1);
        assert.equal(units[0].sectionKey, 'bp-1');
        assert.equal(units[0].claimCount, 3);
        // Required scenario: approve/reject must cover every claim of the section.
        assert.deepEqual(units[0].claimIds, [1, 2, 3]);
    });

    test('two separate sections stay two review units', () => {
        const blocks = [
            ...sectionBlocks,
            { block_key: 'block-0011', section_key: 'bp-2', content_origin: 'best_practice', markdown: 'Annen anbefaling.' },
        ];

        const units = groupBestPracticeClaimsForReview(
            [bpClaim(1, 'block-0002'), bpClaim(2, 'block-0004'), bpClaim(3, 'block-0011')],
            blocks,
        );

        assert.equal(units.length, 2);
        assert.deepEqual(units.map((u) => u.sectionKey), ['bp-1', 'bp-2']);
        assert.deepEqual(units.map((u) => u.claimCount), [2, 1]);
    });

    test('a standalone best_practice block (no section) still gets its own unit', () => {
        const blocks = [{ block_key: 'block-0020', content_origin: 'best_practice', markdown: 'Enkeltstående anbefaling.' }];
        const units = groupBestPracticeClaimsForReview([bpClaim(1, 'block-0020'), bpClaim(2, 'block-0020')], blocks);

        assert.equal(units.length, 1);
        assert.equal(units[0].sectionKey, null);
        assert.equal(units[0].claimCount, 2);
    });

    test('without content blocks it degrades to block-level grouping rather than throwing', () => {
        const units = groupBestPracticeClaimsForReview([bpClaim(1, 'block-0002'), bpClaim(2, 'block-0003')]);

        assert.equal(units.length, 2, 'no section data available -> each block is its own unit');
    });
});

/**
 * An approved best-practice addition has been accepted into the agreement, so it should read as
 * ordinary contract text rather than staying flagged forever. The label is dropped ONLY on a fully
 * approved section — the decision itself remains reconstructable from the claim fields
 * (page/version, block key, origin, reason, approval_status, approved_by, approved_at, comment,
 * final text, source references) plus the append-only enterprise_wiki_claim_decisions log.
 */
describe('bestPracticeSectionApprovalState — only a fully approved section drops its label', () => {
    const keys = ['block-0002', 'block-0003', 'block-0004'];
    const claim = (id, blockKey, approval_status) => ({
        id,
        content_origin: 'best_practice',
        content_block_key: blockKey,
        approval_status,
    });

    test('pending best practice keeps the label', () => {
        const state = bestPracticeSectionApprovalState(keys, [
            claim(1, 'block-0002', 'pending'),
            claim(2, 'block-0003', 'pending'),
        ]);

        assert.equal(state, 'pending');
        assert.notEqual(state, 'approved');
    });

    test('a fully approved section hides the label', () => {
        const state = bestPracticeSectionApprovalState(keys, [
            claim(1, 'block-0002', 'approved'),
            claim(2, 'block-0003', 'approved'),
            claim(3, 'block-0004', 'approved'),
        ]);

        assert.equal(state, 'approved');
    });

    test('a partially approved section still shows the label', () => {
        const state = bestPracticeSectionApprovalState(keys, [
            claim(1, 'block-0002', 'approved'),
            claim(2, 'block-0003', 'approved'),
            claim(3, 'block-0004', 'pending'),
        ]);

        assert.equal(state, 'pending', 'not every claim is approved -> still marked');
    });

    test('approved mixed with rejected is not treated as ordinary approved content', () => {
        const state = bestPracticeSectionApprovalState(keys, [
            claim(1, 'block-0002', 'approved'),
            claim(2, 'block-0003', 'rejected'),
        ]);

        assert.notEqual(state, 'approved');
        assert.equal(state, 'pending');
    });

    test('a fully rejected section is reported as rejected, never as approved', () => {
        const state = bestPracticeSectionApprovalState(keys, [
            claim(1, 'block-0002', 'rejected'),
            claim(2, 'block-0003', 'rejected'),
        ]);

        assert.equal(state, 'rejected');
        assert.notEqual(state, 'approved');
    });

    test('a section with no best_practice claims is unreviewed — never silently unlabelled', () => {
        assert.equal(bestPracticeSectionApprovalState(keys, []), 'unreviewed');
        assert.equal(bestPracticeSectionApprovalState(keys, undefined), 'unreviewed');
    });

    test('claims from other blocks or other origins never affect the section state', () => {
        const state = bestPracticeSectionApprovalState(keys, [
            claim(1, 'block-0002', 'approved'),
            claim(2, 'block-0099', 'pending'),
            { id: 3, content_origin: 'internal_error', content_block_key: 'block-0003', approval_status: 'pending' },
        ]);

        assert.equal(state, 'approved', 'only best_practice claims inside this section count');
    });

    // The audit trail behind the decision must survive the label being hidden.
    test('the approving claim keeps its full audit data after approval', () => {
        const approved = {
            id: 1,
            enterprise_wiki_page_version_id: 18,
            content_block_key: 'block-0002',
            content_origin: 'best_practice',
            review_reason: 'Identifisert svakhet: uklart prosesseierskap.',
            approval_status: 'approved',
            approved_by_name: 'Kari Nordmann',
            approved_at: '2026-08-08T15:00:00+00:00',
            approval_comment: 'Beholdt som avtaletekst.',
            claim_text: 'Leverandøren skal dokumentere prosesseierskap.',
        };

        assert.equal(bestPracticeSectionApprovalState(['block-0002'], [approved]), 'approved');

        for (const field of [
            'enterprise_wiki_page_version_id', 'content_block_key', 'content_origin', 'review_reason',
            'approval_status', 'approved_by_name', 'approved_at', 'approval_comment', 'claim_text',
        ]) {
            assert.ok(approved[field] !== undefined && approved[field] !== null, `audit field kept: ${field}`);
        }
    });
});
