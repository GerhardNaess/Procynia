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
