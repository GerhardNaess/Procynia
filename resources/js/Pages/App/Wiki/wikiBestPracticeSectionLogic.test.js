import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import {
    groupContentBlocksBySection,
    groupBestPracticeClaimsForReview,
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
