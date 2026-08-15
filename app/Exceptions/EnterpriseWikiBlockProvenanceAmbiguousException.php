<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A page version was about to be written with a source-based block whose provenance names more than
 * one document, or whose own source_id disagrees with the elements it cites.
 *
 * The write is rolled back and the page keeps its previous current version. A block is the unit the
 * Wiki can trace, update and withdraw; a block holding substance from two documents cannot be
 * withdrawn without either losing knowledge that belongs to the surviving document or keeping
 * knowledge that belongs to the removed one. Ambiguous provenance is never persisted — the operation
 * that produced it is wrong, and it fails rather than being resolved by guessing.
 */
class EnterpriseWikiBlockProvenanceAmbiguousException extends RuntimeException
{
    /** @param list<string> $documents */
    public function __construct(
        public readonly int $pageId,
        public readonly string $blockKey,
        public readonly array $documents,
        string $detail,
    ) {
        parent::__construct(sprintf(
            'Page [%d] block [%s]: %s A source-based block represents substance from exactly one document. '
            .'The write has been rolled back; the page keeps its previous current version.',
            $pageId,
            $blockKey !== '' ? $blockKey : '(unkeyed)',
            $detail,
        ));
    }
}
