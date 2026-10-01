<?php

namespace App\Services\OpportunitySources;

class OpportunitySourceSearchResult
{
    /**
     * @param  array<int, NormalizedNotice>  $notices
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $notices,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $numHitsTotal,
        public readonly int $numHitsAccessible,
        public readonly bool $fallbackUsed = false,
        public readonly ?string $errorType = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $userMessage = null,
        public readonly mixed $upstreamStatus = null,
    ) {}
}
