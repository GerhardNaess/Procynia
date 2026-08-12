<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use Illuminate\Support\Facades\Log;

/**
 * Reconciles dependent current assertions exposed by an authoritative change before final QA.
 *
 * This is deliberately a narrow execution-plan expansion, not a second page-generation path and
 * not a search-and-replace facility. Discovery is seeded only by already-authorised primary
 * `replace` targets; the cross-page classifier must call an occurrence a high-confidence current
 * assertion; then the normal patch target resolver must accept the exact, heading-bounded target.
 * Only after all three gates does the existing deterministic patch engine write a version.
 *
 * Any uncertain, ambiguous, historically-worded or structurally invalid occurrence is retained as
 * unresolved observability and left to the final strict cross-page check/QA. That is fail-closed.
 */
class EnterpriseWikiCrossPageReconciliationService
{
    public function __construct(
        private readonly EnterpriseWikiCrossPageConsistencyService $consistencyService,
        private readonly EnterpriseWikiPatchTargetResolver $targetResolver,
        private readonly EnterpriseWikiPatchApplicationService $patchApplicationService,
    ) {}

    /**
     * @return array{
     *   discovered: int, validated: int, rejected: int, unresolved: int,
     *   pages_patched: int, pages_skipped: int, targets_applied: int, failures: list<string>
     * }
     */
    public function reconcileForRun(EnterpriseWikiIngestRun $run): array
    {
        $discovery = $this->consistencyService->discoverAdditionalPatchTargetsForRun($run);
        $validTargets = [];
        $unresolved = $discovery['unresolved'];

        foreach ($discovery['targets'] as $target) {
            $resolution = $this->targetResolver->resolveForCustomer(
                (int) $run->customer_id,
                ['patch_targets' => [$target]],
                (int) $run->id,
            );

            if ($resolution['errors'] !== []) {
                $unresolved[] = [
                    'page_id' => $target['target_page_id'] ?? null,
                    'topic' => $target['target_topic'] ?? '',
                    'prefilter_signal' => 'validated_target_rejected',
                    'reason' => implode(' | ', $resolution['errors']),
                ];

                continue;
            }

            $validTargets[] = $target;
        }

        $this->persistExecutionRecord($run, $validTargets, $unresolved);

        if ($validTargets === []) {
            return [
                'discovered' => count($discovery['targets']),
                'validated' => 0,
                'rejected' => count($discovery['targets']),
                'unresolved' => count($unresolved),
                'pages_patched' => 0,
                'pages_skipped' => 0,
                'targets_applied' => 0,
                'failures' => [],
            ];
        }

        $applied = $this->patchApplicationService->applyAdditionalTargetsForRun($run->fresh() ?? $run, $validTargets);

        Log::info('[WIKI_CROSS_PAGE_RECONCILIATION] Additional current-state targets processed.', [
            'run_id' => $run->id,
            'discovered' => count($discovery['targets']),
            'validated' => count($validTargets),
            'unresolved' => count($unresolved),
            'pages_patched' => $applied['pages_patched'],
            'targets_applied' => $applied['targets_applied'],
            'failures' => count($applied['failures']),
        ]);

        return [
            'discovered' => count($discovery['targets']),
            'validated' => count($validTargets),
            'rejected' => count($discovery['targets']) - count($validTargets),
            'unresolved' => count($unresolved),
            ...$applied,
        ];
    }

    /**
     * Keep an auditable execution record separate from maintainer `patch_targets`: primary decision
     * authority must not be rewritten merely because a dependent assertion was reconciled later.
     *
     * @param  list<array<string, mixed>>  $targets
     * @param  list<array<string, mixed>>  $unresolved
     */
    private function persistExecutionRecord(EnterpriseWikiIngestRun $run, array $targets, array $unresolved): void
    {
        $decision = (array) ($run->maintainer_decision_json ?? []);
        $decision['cross_page_reconciliation'] = [
            'derived_patch_targets' => $targets,
            'unresolved' => $unresolved,
        ];

        $run->update(['maintainer_decision_json' => $decision]);
    }
}
