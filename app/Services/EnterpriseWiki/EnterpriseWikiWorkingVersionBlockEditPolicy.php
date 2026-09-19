<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;

/**
 * Which content blocks ordinary manual editing (WikiController::updateWorkingVersion) may touch.
 *
 * The rule is about ROUND-TRIP SAFETY, not about content. An authorized page owner is authoritative
 * for the words on their own working version, so nothing here asks whether an AI or a source
 * document agrees with the text — the only question is whether a block's meaning survives being
 * represented as, and edited as, plain Markdown.
 *
 *  source_based / best_practice / unsupported_generated_content / human_authored
 *      editable. All four are ordinary prose. A source_based block that a person rewrites becomes
 *      human_authored (EnterpriseWikiClaimContentRepairService::applyWorkingVersionBlockEdits()),
 *      because the document no longer backs those words — but that is a matter of recording the
 *      truth afterwards, never a reason to refuse the edit.
 *
 *  structural      read-only. The page heading; it mirrors the page title rather than standing on
 *                  its own, so editing it here would let the two drift apart.
 *  internal_error  read-only. A technical failure state, not content.
 *  unclassified    read-only. The column default, meaning nothing was decided; no documented
 *                  transition out of it exists, so this version leaves it alone.
 *  mixed           read-only. Claim repair's own construct, absent from
 *                  EnterpriseWikiClaim::CONTENT_ORIGINS and never written by production code.
 *
 * Independently of origin, a block carrying structured data (a deterministic table or image, where
 * table_data/image_data is the real content and the Markdown is a fallback rendering) is read-only:
 * editing the fallback alone would leave the two describing different things.
 */
class EnterpriseWikiWorkingVersionBlockEditPolicy
{
    /** Ordinary prose the page owner may rewrite. */
    public const HANDLING_EDITABLE_TEXT = 'editable_text';

    /** Not editable in this version. */
    public const HANDLING_READ_ONLY = 'read_only';

    /**
     * @return self::HANDLING_*
     */
    public static function handlingFor(array $block): string
    {
        if (self::carriesStructuredData($block)) {
            return self::HANDLING_READ_ONLY;
        }

        return match ((string) ($block['content_origin'] ?? '')) {
            EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            EnterpriseWikiClaim::CONTENT_ORIGIN_HUMAN_AUTHORED => self::HANDLING_EDITABLE_TEXT,
            default => self::HANDLING_READ_ONLY,
        };
    }

    public static function isEditable(array $block): bool
    {
        return self::handlingFor($block) !== self::HANDLING_READ_ONLY;
    }

    /**
     * A deterministic table (EnterpriseWikiTableBlockBuilder) or image
     * (EnterpriseWikiImageBlockBuilder) keeps its real content in table_data/image_data and only a
     * fallback rendering in Markdown.
     */
    public static function carriesStructuredData(array $block): bool
    {
        return trim((string) ($block['block_type'] ?? '')) !== ''
            || isset($block['table_data'])
            || isset($block['image_data']);
    }

    /**
     * Throws unless the block may be edited through working-version editing. Used as the
     * entry-point-specific predicate EnterpriseWikiClaimContentRepairService::applyBlockEdits()
     * takes, so the service refuses independently of the controller.
     */
    public static function assertEditable(array $block, string $blockKey): void
    {
        if (self::carriesStructuredData($block)) {
            throw new \InvalidArgumentException("Content block [{$blockKey}] carries structured data and cannot be edited as plain text.");
        }

        if (! self::isEditable($block)) {
            $origin = (string) ($block['content_origin'] ?? '');

            throw new \InvalidArgumentException("Content block [{$blockKey}] has content origin [{$origin}], which is not editable as working-version text.");
        }
    }
}
