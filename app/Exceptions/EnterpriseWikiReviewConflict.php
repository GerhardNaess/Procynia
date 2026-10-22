<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A review action found the page in a different state from the one it was offered in — somebody
 * else acted first, or the tab is older than the page. A 409, which the review panel answers by
 * re-reading the page (wikiReviewRefusal.js isStaleStateRefusal).
 */
class EnterpriseWikiReviewConflict extends ConflictHttpException {}
