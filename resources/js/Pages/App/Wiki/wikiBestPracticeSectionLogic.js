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
 * Resolves the BEST-PRACTICE SECTION a block belongs to, reusing the exact section boundaries the
 * Wiki view itself renders (groupContentBlocksBySection above, over the server-computed
 * section_key from EnterpriseWikiBestPracticeSectionService). Returns null for a block that is not
 * part of a multi-block section — a standalone best_practice block is its own unit and needs no
 * section handling.
 *
 * @returns {{sectionKey: string, blocks: Array<object>, blockKeys: Array<string>, markdown: string}|null}
 */
export function resolveBestPracticeSectionForBlock(contentBlocks, blockKey) {
    const key = typeof blockKey === 'string' ? blockKey.trim() : '';

    if (key === '') {
        return null;
    }

    const group = groupContentBlocksBySection(contentBlocks ?? [])
        .find((entry) => entry.type === 'section' && entry.blocks.some((block) => block?.block_key === key));

    if (!group) {
        return null;
    }

    return {
        sectionKey: group.sectionKey,
        blocks: group.blocks,
        blockKeys: group.blocks.map((block) => block?.block_key).filter(Boolean),
        markdown: group.blocks
            .map((block) => String(block?.markdown ?? '').trim())
            .filter((markdown) => markdown !== '')
            .join('\n\n'),
    };
}

/**
 * Collapses the review list into one card per best-practice REVIEW UNIT — a whole visual "Beste
 * praksis" section when the claim's block belongs to one, otherwise the single block.
 *
 * Claim extraction deliberately produces atomic claims, and one visual section spans several
 * content blocks (a heading block plus its paragraphs), each of which can carry its own claims.
 * Grouping by block key alone therefore still split one section into several review cards, and —
 * because the panel was rendered from inside the matching block — physically injected the review
 * box between the section's own paragraphs, leaving the rest of the same section outside the
 * review and only the first paragraph in the edit field.
 *
 * The section boundary is never re-derived here: it comes from groupContentBlocksBySection(), the
 * same helper the article view uses to draw the amber section frame, so the review unit and the
 * visible section can never disagree. Only best_practice claims with a stable block anchor group;
 * internal_error, unsupported_generated_content, and unanchored best_practice claims each keep
 * their own card, since none of them share a section-wide decision.
 *
 * @param {Array<object>} claims
 * @param {Array<object>} contentBlocks used only to look up section membership
 * @returns {Array<{key: string, claim: object, claimIds: Array<number>, claimCount: number, sectionKey: ?string}>}
 */
export function groupBestPracticeClaimsForReview(claims, contentBlocks = []) {
    const units = [];
    const unitIndexByIdentity = new Map();

    for (const claim of claims ?? []) {
        const blockKey = typeof claim?.content_block_key === 'string' ? claim.content_block_key.trim() : '';
        const groupable = claim?.content_origin === 'best_practice' && blockKey !== '';

        if (!groupable) {
            units.push({ key: `claim-${claim?.id}`, claim, claimIds: [claim?.id], claimCount: 1, sectionKey: null });
            continue;
        }

        const section = resolveBestPracticeSectionForBlock(contentBlocks, blockKey);
        const versionId = claim?.enterprise_wiki_page_version_id ?? '';
        const identity = section !== null
            ? `${versionId}::section::${section.sectionKey}`
            : `${versionId}::block::${blockKey}`;
        const existingIndex = unitIndexByIdentity.get(identity);

        if (existingIndex === undefined) {
            unitIndexByIdentity.set(identity, units.length);
            units.push({
                key: identity,
                claim,
                claimIds: [claim.id],
                claimCount: 1,
                sectionKey: section?.sectionKey ?? null,
            });
            continue;
        }

        const unit = units[existingIndex];
        unit.claimIds.push(claim.id);
        unit.claimCount += 1;
    }

    return units;
}
