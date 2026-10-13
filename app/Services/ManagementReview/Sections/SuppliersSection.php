<?php

namespace App\Services\ManagementReview\Sections;

use App\Models\Supplier;
use App\Models\SupplierAssessment;
use App\Models\SupplierCriticalityChange;
use App\Models\SupplierDueDiligenceAssessment;
use App\Models\SupplierStatusChange;
use App\Models\User;
use App\Services\ManagementReview\ReviewScope;
use App\Services\ManagementReview\SectionBuilder;
use App\Services\ManagementReview\SectionPayload;
use App\Services\Suppliers\SupplierAccessService;
use App\Services\Suppliers\SupplierAttentionService;

/**
 * Leverandøroppfølging. Customer-wide, read through SupplierAccessService (an explicit-grant domain).
 *
 * In the period: assessments by result, criticality changes, aktsomhetsvurderinger and suppliers
 * ended. Now: suppliers by criticality, and Trenger oppmerksomhet's signals.
 *
 * The kontrollbeslutninger (period count and «gjeldende beslutning») left with the decision itself
 * on 2026-10-12. Nothing replaces them: the open requirements are in the signals, under their own
 * name, never as an approval. Reviews finalized before keep the numbers in their snapshot, and the
 * labels stay so those still read.
 *
 * Open avvik at suppliers are deliberately not here: they are counted in Avvik og forbedringer under
 * that module's own gate (docs/supplier-assurance-v2-plan.md §15.2).
 */
final class SuppliersSection implements SectionBuilder
{
    public function __construct(
        private readonly SupplierAccessService $access,
        private readonly SupplierAttentionService $attention,
    ) {}

    public function build(User $user, ReviewScope $scope, ?array $areaIds): array
    {
        $payload = (new SectionPayload(false, (int) config('management_review.list_limit', 50)))
            ->metrics('period', ['assessments_satisfactory', 'assessments_partially_satisfactory', 'assessments_unsatisfactory', 'criticality_changes', 'due_diligence', 'suppliers_ended'])
            ->metrics('status', ['suppliers_active', 'criticality_critical', 'criticality_important', 'criticality_standard', 'suppliers_with_findings'])
            ->list('suppliers_attention');

        $visible = $this->access->visibleSuppliers($user)->select('suppliers.id');
        $between = fn ($query, string $column) => $query->where($column, '>=', $scope->from())->where($column, '<', $scope->until());
        $betweenDays = fn ($query, string $column) => $query->whereBetween($column, [$scope->periodStart->toDateString(), $scope->periodEnd->toDateString()]);

        $betweenDays(SupplierAssessment::query()->whereIn('supplier_id', $visible), 'assessed_on')
            ->get(['overall_result'])
            ->each(fn ($row) => $payload->count('period', 'assessments_'.$row->overall_result));

        $payload->count('period', 'criticality_changes', $between(SupplierCriticalityChange::query()->whereIn('supplier_id', $visible), 'changed_at')->count());
        $payload->count('period', 'due_diligence', $betweenDays(SupplierDueDiligenceAssessment::query()->whereIn('supplier_id', $visible), 'assessed_on')->count());
        $payload->count('period', 'suppliers_ended', $between(SupplierStatusChange::query()->whereIn('supplier_id', $visible), 'changed_at')
            ->where('to_status', Supplier::STATUS_ENDED)->count());

        $suppliers = $this->access->visibleSuppliers($user)
            ->where('suppliers.status', '!=', Supplier::STATUS_ENDED)
            ->get(['suppliers.*']);

        foreach ($suppliers as $supplier) {
            $payload->count('status', 'suppliers_active');
            $payload->count('status', 'criticality_'.($supplier->criticality ?? Supplier::CRITICALITY_STANDARD));
        }

        $overview = $this->attention->overview($user, $scope->today);
        $payload->metrics('status', SupplierAttentionService::CATEGORIES);
        $payload->count('status', 'suppliers_with_findings', (int) $overview['total']);

        foreach ($overview['categories'] as $category) {
            $payload->count('status', $category['key'], (int) $category['count']);
        }

        $criticality = $suppliers->keyBy('id');
        $rank = [Supplier::CRITICALITY_CRITICAL => 0, Supplier::CRITICALITY_IMPORTANT => 1, Supplier::CRITICALITY_STANDARD => 2];
        $rows = collect($overview['suppliers'])
            ->sortBy(fn (array $row): string => ($rank[$criticality->get($row['id'])?->criticality] ?? 3).'|'.mb_strtolower($row['name']))
            ->values();

        foreach ($rows as $row) {
            $payload->item('suppliers_attention', [
                'id' => (int) $row['id'],
                'title' => $row['name'],
                'url' => parse_url((string) $row['url'], PHP_URL_PATH) ?: null,
                'fields' => SectionPayload::fields([
                    'criticality' => ['enum', 'criticality_'.($criticality->get($row['id'])?->criticality ?? Supplier::CRITICALITY_STANDARD)],
                    'signals' => ['enum_list', array_values(array_unique(array_column($row['findings'], 'key')))],
                ]),
            ]);
        }

        return $payload->toArray();
    }
}
