<?php

namespace App\Services\ManagementReview;

use App\Models\ManagementReview;
use Carbon\CarbonImmutable;

/**
 * What one build of the basis covers: the review's period (inclusive days), its fagområde scope
 * (null = Hele virksomheten), and «today» — the moment «Status nå» is read at.
 */
final class ReviewScope
{
    /** @param  list<int>|null  $areaIds */
    public function __construct(
        public readonly int $customerId,
        public readonly int $reviewId,
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
        public readonly ?array $areaIds,
        public readonly CarbonImmutable $today,
        public readonly CarbonImmutable $capturedAt,
    ) {}

    public static function forReview(ManagementReview $review, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();

        return new self(
            (int) $review->customer_id,
            (int) $review->id,
            CarbonImmutable::parse($review->period_start->toDateString()),
            CarbonImmutable::parse($review->period_end->toDateString()),
            $review->scopeAreaIds(),
            CarbonImmutable::parse($now->toDateString()),
            $now,
        );
    }

    /** The first moment of the period. */
    public function from(): CarbonImmutable
    {
        return $this->periodStart->startOfDay();
    }

    /** The first moment after the period. */
    public function until(): CarbonImmutable
    {
        return $this->periodEnd->addDay()->startOfDay();
    }

    /** The period of the same length just before this one, for trends. */
    public function previousPeriod(): array
    {
        $days = (int) $this->periodStart->diffInDays($this->periodEnd) + 1;

        return [$this->periodStart->subDays($days)->startOfDay(), $this->periodStart->startOfDay()];
    }

    /**
     * The fagområder an area-scoped section reads: the user's own, narrowed to the review's scope.
     *
     * @param  list<int>  $userAreaIds
     * @return list<int>
     */
    public function narrow(array $userAreaIds): array
    {
        $ids = $this->areaIds === null ? $userAreaIds : array_values(array_intersect($userAreaIds, $this->areaIds));
        sort($ids);

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function inPeriod(mixed $moment): bool
    {
        if ($moment === null) {
            return false;
        }

        $moment = CarbonImmutable::parse($moment);

        return $moment->gte($this->from()) && $moment->lt($this->until());
    }
}
