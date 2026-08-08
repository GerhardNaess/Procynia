/**
 * Pure, dependency-free helper that groups a Wiki page's already-ordered content_blocks_json for
 * display, so the page can render ONE "Beste praksis" frame per faglig seksjon instead of one per
 * individual content block (a heading and its following paragraph are separate content blocks —
 * see EnterpriseWikiPageContentBlockService/WikiPageContentAiClient — which previously rendered as
 * separate, disconnected amber frames).
 *
 * This module never re-derives section boundaries itself (no heading-level detection, no
 * content_origin walking) — that logic lives once, server-side, in
 * EnterpriseWikiBestPracticeSectionService, which stamps every block's `section_key`/
 * `section_heading` before it ever reaches the frontend (see WikiController::renderedContentBlocks()).
 * This is purely a "group consecutive blocks sharing the same already-computed section_key"
 * operation, so the boundary rules are never duplicated between PHP and JS.
 */

/**
 * @param {Array<object>} blocks - ordered content blocks, each optionally carrying `section_key`/
 *   `section_heading` (null/undefined for a block outside any best-practice section).
 * @returns {Array<{type: 'section', sectionKey: string, headingText: string|null, blocks: Array<object>} | {type: 'single', block: object}>}
 */
export function groupContentBlocksBySection(blocks) {
    const groups = [];

    for (const block of blocks ?? []) {
        const sectionKey = block?.section_key ?? null;
        const previous = groups[groups.length - 1];

        if (sectionKey !== null && previous?.type === 'section' && previous.sectionKey === sectionKey) {
            previous.blocks.push(block);
            continue;
        }

        if (sectionKey !== null) {
            groups.push({
                type: 'section',
                sectionKey,
                headingText: block?.section_heading ?? null,
                blocks: [block],
            });
            continue;
        }

        groups.push({ type: 'single', block });
    }

    return groups;
}

export function hasRenderableBestPracticeMetadata(block) {
    return block?.content_origin === 'best_practice'
        && typeof block?.best_practice_reason === 'string'
        && block.best_practice_reason.trim() !== '';
}

export function hasInvalidBestPracticeMetadata(block) {
    return block?.content_origin === 'best_practice'
        && !hasRenderableBestPracticeMetadata(block);
}

/**
 * Collapses the review list into one card per best-practice BLOCK instead of one per claim.
 *
 * Claim extraction deliberately produces atomic claims, so a single best_practice block can yield
 * several of them — and the reviewer was shown each fragment as its own separate "finding" to
 * approve or reject, even though every action already applies to the whole block on the backend:
 * WikiClaimController::cascadeBlockDecision() propagates the decision to every other pending
 * best_practice claim on the same (page version, block key), and both
 * applyBestPracticeTextEdit() and removeBestPracticeText() rewrite the block's own markdown, not a
 * fragment. Deciding one card therefore already decided the rest, which is exactly why showing
 * them separately was misleading.
 *
 * Grouping key is the pair the block is actually identified by — (page version, block key) —
 * matching cascadeBlockDecision()'s own query. Only best_practice claims with a stable block key
 * group; every other claim (internal_error, unsupported_generated_content, a best_practice claim
 * that never got a block anchor) stays its own unit, since none of them share a block-wide
 * decision. Input order is preserved and the first claim of a block becomes its representative,
 * so the card keeps the position the reviewer already expects.
 *
 * @param {Array<object>} claims
 * @returns {Array<{key: string, claim: object, claimIds: Array<number>, claimCount: number}>}
 */
export function groupBestPracticeClaimsForReview(claims) {
    const units = [];
    const unitIndexByBlock = new Map();

    for (const claim of claims ?? []) {
        const blockKey = typeof claim?.content_block_key === 'string' ? claim.content_block_key.trim() : '';
        const groupable = claim?.content_origin === 'best_practice' && blockKey !== '';

        if (!groupable) {
            units.push({ key: `claim-${claim?.id}`, claim, claimIds: [claim?.id], claimCount: 1 });
            continue;
        }

        const blockIdentity = `${claim?.enterprise_wiki_page_version_id ?? ''}::${blockKey}`;
        const existingIndex = unitIndexByBlock.get(blockIdentity);

        if (existingIndex === undefined) {
            unitIndexByBlock.set(blockIdentity, units.length);
            units.push({ key: `block-${blockIdentity}`, claim, claimIds: [claim.id], claimCount: 1 });
            continue;
        }

        const unit = units[existingIndex];
        unit.claimIds.push(claim.id);
        unit.claimCount += 1;
    }

    return units;
}
