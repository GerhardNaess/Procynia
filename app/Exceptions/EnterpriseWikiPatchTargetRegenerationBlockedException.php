<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Fase 8K-2 safety gate: something tried to run ordinary page generation for a page the maintainer
 * decision names as a patch target.
 *
 * Ordinary generation writes a page from the NEW source document plus the decision's owned_topics
 * alone — the existing page's own content is never an input (see
 * EnterpriseWikiGenerateAppliedPagesService::generatePageForRun()). For a page that only needs one
 * topic corrected, that is a full-page rewrite: the run observed after 8K-1 lost the target page's
 * definition, an entire unrelated section, all of its original provenance and two wikilinks that
 * way, and QA passed it.
 *
 * Until 8K-3 implements a real section patch, the correct outcome is a loud, controlled stop — not a
 * silent skip (which reads as "handled") and not a rewrite (which destroys content). The normal path
 * never reaches this: EnterpriseWikiMaintainerDecisionApplyService deliberately creates no pivot row
 * for a patch target, and generation is driven entirely by pivot rows. Seeing this exception means a
 * pivot exists for a patch-targeted page anyway — a stored decision from another code path, a
 * manually dispatched job, or a bug — all cases where stopping is right and guessing is not.
 */
class EnterpriseWikiPatchTargetRegenerationBlockedException extends RuntimeException
{
    public function __construct(
        public readonly int $runId,
        public readonly int $pageId,
    ) {
        parent::__construct(
            "Run [{$runId}] names page [{$pageId}] as a patch target, so it must not be regenerated from source. "
            .'Full-page generation would discard the existing content this patch is meant to preserve. '
            .'Patch application is Fase 8K-3 and is not implemented yet.'
        );
    }
}
