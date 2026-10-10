<?php

namespace App\Services\ManagementReview;

use App\Models\ManagementReview;
use App\Models\ManagementReviewSection;
use App\Services\ManagementReview\ManagementReviewSectionCatalog as Catalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * «Klar for ferdigstilling» — computed, never stored (plan §6.3). For the person looking:
 *
 *  1. The meeting date is set and not in the future.
 *  2. At least one participant.
 *  3. Every section the person can see has a judgement. «Tidligere beslutninger» only once there is an
 *     earlier review to look back on. A section the person cannot see is not theirs to judge: it is
 *     listed as a warning, and frozen as not captured if they finalize.
 *  4. A conclusion.
 *
 * Every tiltak already has a follow-up (here or in Avvik og forbedringer) from the moment it is
 * registered, so there is no step for it.
 */
final class ManagementReviewReadiness
{
    /**
     * @param  array<string, array<string, mixed>>  $views  ManagementReviewBasisService::live() for the person
     * @param  Collection<string, ManagementReviewSection>  $sections  keyed by section_key
     * @return array{ready: bool, items: list<array{key: string, done: bool, sections?: list<string>}>, unavailable: list<string>}
     */
    public function evaluate(ManagementReview $review, array $views, Collection $sections, int $participantCount, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $missing = [];
        $unavailable = [];

        foreach ($views as $key => $view) {
            if ($view['state'] !== Catalog::STATE_AVAILABLE) {
                if ($view['state'] === Catalog::STATE_NO_ACCESS) {
                    $unavailable[] = $key;
                }

                continue;
            }

            if ($key === 'previous_decisions' && $this->previousReviews($view) === 0) {
                continue;
            }

            if ($sections->get($key)?->judgement === null) {
                $missing[] = $key;
            }
        }

        $items = [
            ['key' => 'meeting_date', 'done' => $review->meeting_date !== null && $review->meeting_date->toDateString() <= $today->toDateString()],
            ['key' => 'participants', 'done' => $participantCount > 0],
            ['key' => 'judgements', 'done' => $missing === [], 'sections' => $missing],
            ['key' => 'conclusion', 'done' => trim((string) $review->conclusion) !== ''],
        ];

        return [
            'ready' => ! in_array(false, array_column($items, 'done'), true),
            'items' => $items,
            'unavailable' => $unavailable,
        ];
    }

    /** @param  array<string, mixed>  $view */
    private function previousReviews(array $view): int
    {
        foreach ($view['basis']['groups'] ?? [] as $group) {
            foreach ($group['metrics'] as $metric) {
                if ($metric['key'] === 'previous_reviews') {
                    return (int) $metric['value'];
                }
            }
        }

        return 0;
    }
}
