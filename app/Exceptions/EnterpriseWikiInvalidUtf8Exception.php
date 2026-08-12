<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised before invalid text can be serialized into an Enterprise Wiki AI request or reused from
 * an AI response. The exception intentionally carries diagnostics only: no source text is put in
 * the message or logs.
 */
class EnterpriseWikiInvalidUtf8Exception extends RuntimeException
{
    public function __construct(
        public readonly string $scope,
        public readonly string $fieldPath,
        public readonly int $byteLength,
        public readonly ?int $invalidByteOffset,
        public readonly ?string $hexWindow,
        public readonly ?string $sourceElementKey = null,
    ) {
        parent::__construct(sprintf(
            'enterprise_wiki_invalid_utf8: invalid UTF-8 at [%s] in %s.',
            $fieldPath,
            $scope,
        ));
    }
}
