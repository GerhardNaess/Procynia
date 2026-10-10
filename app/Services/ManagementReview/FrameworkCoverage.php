<?php

namespace App\Services\ManagementReview;

use App\Models\ManagementReview;
use App\Services\ManagementReview\ManagementReviewSectionCatalog as Catalog;
use Illuminate\Support\Collection;

/**
 * Which inputs of the chosen frameworks the review has assessed (config/management_review.php).
 *
 * An aid, never a verdict: an input is «vurdert» when every section it maps to has a judgement (or,
 * for improvement, when the review records a decision); «ikke tilgjengelig» when a section it maps to
 * is not part of this customer's basis. Nothing here says that the review complies with the standard,
 * and the mapping itself is marked as not professionally verified.
 */
final class FrameworkCoverage
{
    /**
     * @param  array<string, array<string, mixed>>  $views
     * @param  Collection<string, mixed>  $sections  keyed by section_key, with judgement
     * @return list<array{key: string, version: string, verified: bool, inputs: list<array{key: string, clause: string, state: string}>}>
     */
    public function evaluate(ManagementReview $review, array $views, Collection $sections, int $decisionCount): array
    {
        $catalog = (array) config('management_review.frameworks', []);
        $versions = (array) ($review->framework_versions ?? []);
        $out = [];

        foreach ((array) $review->frameworks as $key) {
            if (! isset($catalog[$key])) {
                continue;
            }

            $inputs = [];

            foreach ($catalog[$key]['inputs'] as $input) {
                $state = 'assessed';

                foreach ($input['sections'] as $section) {
                    if ($section === Catalog::DECISIONS) {
                        $state = $decisionCount > 0 ? $state : 'not_assessed';

                        continue;
                    }

                    $viewState = $views[$section]['state'] ?? Catalog::STATE_MODULE_UNAVAILABLE;

                    if ($viewState === Catalog::STATE_MODULE_UNAVAILABLE) {
                        $state = 'not_available';

                        break;
                    }

                    if ($sections->get($section)?->judgement === null) {
                        $state = 'not_assessed';
                    }
                }

                $inputs[] = ['key' => $input['key'], 'clause' => $input['clause'], 'state' => $state];
            }

            $out[] = [
                'key' => $key,
                'version' => (string) ($versions[$key] ?? $catalog[$key]['version']),
                'verified' => (bool) ($catalog[$key]['verified'] ?? false),
                'inputs' => $inputs,
            ];
        }

        return $out;
    }
}
