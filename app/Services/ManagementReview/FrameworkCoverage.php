<?php

namespace App\Services\ManagementReview;

use App\Models\ManagementReview;
use App\Services\ManagementReview\ManagementReviewSectionCatalog as Catalog;
use App\Support\FrameworkCatalog;
use Illuminate\Support\Collection;

/**
 * Which inputs of the chosen frameworks the review has assessed (config/management_review.php
 * 'framework_coverage').
 *
 * A chosen framework without a mapping is listed with coverage 'none' and no inputs: it is in the
 * review's scope, and nothing more is said — no inputs, no states. An aid, never a verdict: an input is «vurdert» when every section it maps to has a judgement (or,
 * for improvement, when the review records a decision); «ikke tilgjengelig» when a section it maps to
 * is not part of this customer's basis. Nothing here says that the review complies with the standard,
 * and the mapping itself is marked as not professionally verified.
 */
final class FrameworkCoverage
{
    public function __construct(private readonly FrameworkCatalog $frameworks) {}

    /**
     * @param  array<string, array<string, mixed>>  $views
     * @param  Collection<string, mixed>  $sections  keyed by section_key, with judgement
     * @return list<array{key: string, label: string, version: string, coverage: string, verified: bool, inputs: list<array{key: string, clause: string, state: string}>}>
     */
    public function evaluate(ManagementReview $review, array $views, Collection $sections, int $decisionCount): array
    {
        $mappings = (array) config('management_review.framework_coverage', []);
        $versions = (array) ($review->framework_versions ?? []);
        $out = [];

        foreach ((array) $review->frameworks as $key) {
            $inputs = [];

            foreach ((array) ($mappings[$key]['inputs'] ?? []) as $input) {
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

            $coverage = $this->frameworks->coverage('management_review', $key);

            $out[] = [
                'key' => $key,
                'label' => $this->frameworks->label($key),
                'version' => (string) ($versions[$key] ?? $this->frameworks->version($key) ?? ''),
                'coverage' => $coverage,
                'verified' => $coverage === FrameworkCatalog::COVERAGE_VERIFIED,
                'inputs' => $inputs,
            ];
        }

        return $out;
    }
}
