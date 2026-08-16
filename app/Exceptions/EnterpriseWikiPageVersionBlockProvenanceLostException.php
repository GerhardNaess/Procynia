<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A new current page version would have been promoted with NO content blocks while the version it
 * supersedes had them.
 *
 * `content_blocks_json` is not decoration on top of the markdown — it is where the page's image
 * figures, source-element provenance and claim anchors live. Promoting a blockless version silently
 * deletes all of that for the page while leaving prose that looks correct, and nothing downstream
 * can tell the difference between "this page never had provenance" and "this page just lost it".
 *
 * Run 54 is the observed case: a link-text repair wrote a markdown-only version for page 191, its
 * block reconstruction came back `skipped_ambiguous`, the blockless version was promoted anyway,
 * and QA reported the page's REQUIRED figure as missing — the figure block existed, in the version
 * that had just been demoted. A link-wording improvement is never worth a page's provenance.
 */
class EnterpriseWikiPageVersionBlockProvenanceLostException extends RuntimeException
{
    public function __construct(
        public readonly int $pageId,
        public readonly int $supersededVersionId,
        public readonly int $supersededBlockCount,
        public readonly string $reason = '',
    ) {
        parent::__construct(sprintf(
            'Refusing to promote a current version for page [%d] with no content blocks: the superseded '
            .'version [%d] carried %d block(s), including any image figures, source provenance and claim '
            .'anchors on them.%s',
            $pageId,
            $supersededVersionId,
            $supersededBlockCount,
            $reason !== '' ? ' '.$reason : '',
        ));
    }
}
