<?php

namespace App\Services\Ai\Wiki\Responses\Exceptions;

class EnterpriseWikiResponseIncompleteException extends EnterpriseWikiResponseException
{
    public function incompleteReason(): ?string
    {
        $reason = $this->diagnostics['incomplete_reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    public function reachedMaxOutputTokens(): bool
    {
        return $this->incompleteReason() === 'max_output_tokens';
    }
}
