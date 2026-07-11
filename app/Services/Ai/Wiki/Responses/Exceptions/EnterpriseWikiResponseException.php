<?php

namespace App\Services\Ai\Wiki\Responses\Exceptions;

use RuntimeException;

class EnterpriseWikiResponseException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly array $diagnostics = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
