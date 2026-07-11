<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a generated Enterprise Wiki page's inline wikilinks fail deterministic
 * validation before the page version is persisted (8I-4). The message is built only from
 * run_id/page_id/page_type/slug-level detail — never markdown, prompt, or source content.
 */
class EnterpriseWikiInvalidWikilinksException extends RuntimeException {}
