<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Fase 8K-3: a patch could not be applied safely, so it was not applied at all.
 *
 * Every constructor here is a controlled stop. There is deliberately no "best effort" branch and no
 * fallback to full page regeneration anywhere in the patch path: regenerating the page from the new
 * source document is precisely the destructive behaviour Fase 8K exists to remove, so a patch that
 * cannot be located or verified must fail loudly and leave the existing current version untouched.
 *
 * Every one of these is raised BEFORE any version is written.
 */
class EnterpriseWikiPatchApplicationException extends RuntimeException
{
    public static function noContentBlocks(string $context): self
    {
        return new self("{$context}: the target page's current version has no content blocks to patch.");
    }

    public static function headingNotFound(string $context, string $heading): self
    {
        return new self("{$context}: target_heading [{$heading}] is not a heading on the target page's current version.");
    }

    public static function headingAmbiguous(string $context, string $heading, int $count): self
    {
        return new self(
            "{$context}: target_heading [{$heading}] matches {$count} headings on the target page — "
            .'the section cannot be identified unambiguously, and guessing could leave the other occurrence stale.'
        );
    }

    public static function areaNotLocatable(string $context, string $topic): self
    {
        return new self(
            "{$context}: no bounded section could be located for target_topic [{$topic}] without a target_heading. "
            .'A patch never widens its own scope to the whole page.'
        );
    }

    public static function supersededSubstanceNotFound(string $context, string $substance): self
    {
        return new self(
            "{$context}: superseded_substance was not found inside the target section — ["
            .self::excerpt($substance).']. A replace never guesses which text to remove.'
        );
    }

    public static function supersededSubstanceAmbiguous(string $context, string $substance, int $count): self
    {
        return new self(
            "{$context}: superseded_substance occurs in {$count} separate blocks inside the target section — ["
            .self::excerpt($substance).']. Which occurrence to replace is not determined by the decision.'
        );
    }

    public static function severalReplacesInOneBlock(string $context, int $blockIndex, int $count): self
    {
        return new self(
            "{$context}: [{$count}] replace targets address the same content block [{$blockIndex}]. A replace splits "
            .'its block into provenance atoms, so two of them in one block would have to be planned as a single '
            .'multi-way split with every offset re-based after each cut. Express them as separate targets on '
            .'separate paragraphs instead — this is refused rather than guessed at.'
        );
    }

    public static function supersededBlockNotSourceBased(string $context, string $origin): self
    {
        return new self(
            "{$context}: superseded_substance names a [{$origin}] block. Only a source_based block "
            .'carries document substance that can be superseded; a heading or a Procynia recommendation '
            .'is not source content and is never replaced by a patch.'
        );
    }

    public static function missingReplacementSubstance(string $context, string $operation): self
    {
        return new self("{$context}: operation [{$operation}] requires replacement_substance, which is empty.");
    }

    public static function unknownSourceElementKey(string $context, string $key): self
    {
        return new self(
            "{$context}: source_element_keys names [{$key}], which is not an element of the patch document's "
            .'source catalog. New substance must be traceable to the source that authorises it.'
        );
    }

    public static function unresolvableWikilink(string $context, string $slug): self
    {
        return new self(
            "{$context}: the new substance contains a wikilink to [{$slug}], which is not a live page for this "
            .'customer. A patch never introduces a broken link.'
        );
    }

    public static function preserveInvariantViolated(string $context, string $detail): self
    {
        return new self("{$context}: the patch result violates the preserve invariant — {$detail}");
    }

    private static function excerpt(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strlen($value) > 120 ? mb_substr($value, 0, 117).'...' : $value;
    }
}
