<?php

namespace App\Services\ManagementReview\Sections;

use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\QualityProcessRevision;
use App\Models\User;
use App\Services\ManagementReview\ReviewScope;
use App\Services\ManagementReview\SectionBuilder;
use App\Services\ManagementReview\SectionPayload;
use App\Services\Quality\QualityAttentionService;

/**
 * Kvalitet. Customer-wide, quality.view (the catalog's gate decides who gets here).
 *
 * In the period: process revisions approved, items reviewed (last_reviewed_at — only the latest
 * review is kept, so an item reviewed twice counts once), and evidence registered on controls. Now:
 * active processes, policies and controls, and what Trenger oppmerksomhet flags.
 *
 * Controls carry evidence but no pass/fail result in Kvalitet, so the section reports evidence, never
 * whether a control «passed» (limitation quality_controls_no_result).
 */
final class QualitySection implements SectionBuilder
{
    public function __construct(
        private readonly QualityAttentionService $attention,
    ) {}

    public function build(User $user, ReviewScope $scope, ?array $areaIds): array
    {
        $findings = $this->attention->findings($scope->customerId);
        $payload = (new SectionPayload(false, (int) config('management_review.list_limit', 50)))
            ->metrics('period', ['process_revisions_approved', 'items_reviewed', 'control_evidence'])
            ->metrics('status', ['processes_active', 'policies_active', 'controls_active', ...array_column($findings, 'key')])
            ->list('attention')
            ->note('quality_controls_no_result')
            ->note('quality_reviews_latest_only');

        $customerId = $scope->customerId;

        $payload->count('period', 'process_revisions_approved', QualityProcessRevision::query()
            ->where('customer_id', $customerId)
            ->where('approved_at', '>=', $scope->from())
            ->where('approved_at', '<', $scope->until())
            ->count());

        $payload->count('period', 'items_reviewed', QualityItem::query()
            ->where('customer_id', $customerId)
            ->where('last_reviewed_at', '>=', $scope->from())
            ->where('last_reviewed_at', '<', $scope->until())
            ->count());

        $payload->count('period', 'control_evidence', QualityItemDocument::query()
            ->where('customer_id', $customerId)
            ->where('relation_type', QualityItemDocument::RELATION_TYPE_EVIDENCE)
            ->where('created_at', '>=', $scope->from())
            ->where('created_at', '<', $scope->until())
            ->count());

        $inForce = QualityItem::query()
            ->where('customer_id', $customerId)
            ->where('status', '!=', QualityItem::STATUS_RETIRED)
            ->selectRaw('quality_type, count(*) as total')
            ->groupBy('quality_type')
            ->pluck('total', 'quality_type');

        $payload->count('status', 'processes_active', (int) ($inForce[QualityItem::TYPE_PROCESS] ?? 0));
        $payload->count('status', 'policies_active', (int) ($inForce[QualityItem::TYPE_POLICY] ?? 0));
        $payload->count('status', 'controls_active', (int) ($inForce[QualityItem::TYPE_CONTROL] ?? 0));

        foreach ($findings as $finding) {
            $payload->count('status', $finding['key'], count($finding['items']));

            foreach ($finding['items'] as $item) {
                $payload->item('attention', [
                    'id' => (int) $item['id'],
                    'title' => trim(($item['code'] ? $item['code'].' ' : '').$item['title']),
                    'url' => parse_url((string) $item['url'], PHP_URL_PATH) ?: null,
                    'fields' => SectionPayload::fields([
                        'finding' => ['enum', $finding['key']],
                    ]),
                ]);
            }
        }

        return $payload->toArray();
    }
}
