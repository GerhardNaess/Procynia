<?php

namespace App\Data\Ai\Experience;

use Carbon\CarbonImmutable;

/**
 * Which experience periods an analysis is about.
 *
 *  - from/to:            billing periods that START in [from, to]
 *  - feature:            an attribution key (tender, wiki, wiki.supplier, …): usage figures become that
 *                        key's usage, and only periods where it was used are counted
 *  - operation:          narrows the operation table and the trend (ledger level only; snapshots hold
 *                        no per-operation figures)
 *  - includeIncomplete:  also open periods and periods only partly covered by trusted data. Off by
 *                        default: a decision rests on final, fully covered periods.
 */
final readonly class AiExperienceFilter
{
    public function __construct(
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public ?int $customerId = null,
        public ?string $feature = null,
        public ?string $operation = null,
        public ?string $tier = null,
        public ?string $package = null,
        public ?int $usersMin = null,
        public ?int $usersMax = null,
        public ?string $interval = null,
        public bool $includeIncomplete = false,
    ) {}
}
