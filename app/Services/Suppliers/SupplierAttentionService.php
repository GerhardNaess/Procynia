<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierAssessment;
use App\Models\SupplierDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * «Trenger oppmerksomhet» — which suppliers have something concrete to follow up
 * (docs/supplier-management-v1-plan.md §8). The same form as RiskAttentionService and
 * ComplianceAttentionService: fixed rules read off rows that already exist, recomputed on every
 * read, never stored and never folded into a score. Fixing the supplier is the only way to clear a
 * finding.
 *
 *  - Ikke vurdert: active, Viktig or Kritisk, and never assessed. Standard raises nothing.
 *  - Vurdering forfalt: active, and the next review (SupplierReviewSchedule) has passed — due today
 *    is not overdue.
 *  - Mangler ansvarlig: not ended, and no intern ansvarlig.
 *  - Dokumentasjon utløpt / utløper snart: not ended, and a documentation row that is not replaced
 *    has «Gyldig til» before today, or within SupplierDocument::EXPIRING_SOON_DAYS. One finding per
 *    row, so the page can name the document.
 *
 * An ended supplier raises nothing: it is no longer followed up. A supplier under evaluation
 * (onboarding) is not expected to be assessed yet, so only the owner and documentation rules apply.
 *
 * ONLY SUPPLIER DATA. The rules read suppliers, supplier_assessments and supplier_documents, nothing
 * else — no risk, case, requirement or link to another module, so no finding can depend on, or
 * reveal, something the person cannot see there.
 *
 * ACCESS COMES FIRST. overview() takes its suppliers from SupplierAccessService::visibleSuppliers(),
 * and the assessments and documents are read by exactly those ids within the person's customer. A
 * supplier the person cannot see never enters the set, so it moves neither a total nor a category.
 */
class SupplierAttentionService
{
    public const NOT_ASSESSED = 'not_assessed';

    public const REVIEW_OVERDUE = 'review_overdue';

    public const MISSING_OWNER = 'missing_owner';

    public const DOCUMENT_EXPIRED = 'document_expired';

    public const DOCUMENT_EXPIRING = 'document_expiring';

    /** Display order. */
    public const CATEGORIES = [
        self::NOT_ASSESSED,
        self::REVIEW_OVERDUE,
        self::MISSING_OWNER,
        self::DOCUMENT_EXPIRED,
        self::DOCUMENT_EXPIRING,
    ];

    public function __construct(
        private readonly SupplierAccessService $access,
        private readonly SupplierReviewSchedule $schedule,
    ) {}

    /**
     * The panel on the register: every visible supplier that is not ended and has a finding, by name,
     * with its findings; the category counts (which may overlap) and the total of unique suppliers.
     * Only non-empty categories are returned, in display order.
     *
     * @return array{total: int, categories: list<array{key: string, count: int}>, suppliers: list<array{id: int, name: string, url: string, findings: list<array<string, mixed>>}>}
     */
    public function overview(User $user, ?CarbonInterface $today = null): array
    {
        $suppliers = $this->access->visibleSuppliers($user)
            ->where('suppliers.status', '!=', Supplier::STATUS_ENDED)
            ->orderByRaw('lower(suppliers.name)')
            ->orderBy('suppliers.id')
            ->get(['suppliers.*']);

        $findings = array_filter($this->findingsForSuppliers($suppliers, $today), fn (array $list): bool => $list !== []);
        $counts = array_fill_keys(self::CATEGORIES, 0);

        foreach ($findings as $list) {
            foreach (array_unique(array_column($list, 'key')) as $key) {
                $counts[$key]++;
            }
        }

        return [
            'total' => count($findings),
            'categories' => collect($counts)
                ->filter(fn (int $count): bool => $count > 0)
                ->map(fn (int $count, string $key): array => ['key' => $key, 'count' => $count])
                ->values()
                ->all(),
            'suppliers' => $suppliers
                ->filter(fn (Supplier $supplier): bool => isset($findings[(int) $supplier->id]))
                ->map(fn (Supplier $supplier): array => [
                    'id' => (int) $supplier->id,
                    'name' => $supplier->name,
                    'url' => route('app.supplier-management.show', ['supplierId' => $supplier->id]),
                    'findings' => $findings[(int) $supplier->id],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The findings for each of the given suppliers, keyed by supplier id, in display order. The
     * suppliers must all belong to one customer and already have been reached through
     * SupplierAccessService; their assessments and documents are read in one query each.
     *
     * @param  Collection<int, Supplier>  $suppliers
     * @return array<int, list<array<string, mixed>>>
     */
    public function findingsForSuppliers(Collection $suppliers, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse(($today ?? now())->toDateString());
        $open = $suppliers->reject(fn (Supplier $supplier): bool => $supplier->isEnded());
        $ids = $open->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
        $customerId = (int) ($open->first()?->customer_id ?? 0);

        $latest = $ids === [] ? collect() : SupplierAssessment::query()
            ->where('customer_id', $customerId)
            ->whereIn('supplier_id', $ids)
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, max(assessed_on) as latest_assessed_on')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->supplier_id => (string) $row->latest_assessed_on]);

        $documents = $ids === [] ? collect() : SupplierDocument::query()
            ->where('customer_id', $customerId)
            ->whereIn('supplier_id', $ids)
            ->whereNull('replaced_by_document_id')
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<=', $today->addDays(SupplierDocument::EXPIRING_SOON_DAYS)->toDateString())
            ->orderBy('valid_until')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (SupplierDocument $document): int => (int) $document->supplier_id);

        $findings = [];

        foreach ($suppliers as $supplier) {
            $id = (int) $supplier->id;
            $findings[$id] = $supplier->isEnded() ? [] : $this->findingsFor($supplier, $latest->get($id), $documents->get($id, collect()), $today);
        }

        return $findings;
    }

    /**
     * The findings for one supplier, already reached through SupplierAccessService.
     *
     * @return list<array<string, mixed>>
     */
    public function findingsForSupplier(Supplier $supplier, ?CarbonInterface $today = null): array
    {
        return $this->findingsForSuppliers(collect([$supplier]), $today)[(int) $supplier->id];
    }

    /**
     * @param  Collection<int, SupplierDocument>  $documents  not replaced, with «Gyldig til» no later than the window's end
     * @return list<array<string, mixed>>
     */
    private function findingsFor(Supplier $supplier, ?string $latestAssessedOn, Collection $documents, CarbonImmutable $today): array
    {
        $findings = [];
        $active = $supplier->status === Supplier::STATUS_ACTIVE;
        $important = in_array($supplier->criticality, [Supplier::CRITICALITY_IMPORTANT, Supplier::CRITICALITY_CRITICAL], true);

        if ($active && $important && $latestAssessedOn === null) {
            $findings[] = ['key' => self::NOT_ASSESSED, 'criticality' => $supplier->criticality];
        }

        $next = $this->schedule->nextReviewOn($supplier->review_interval_months, $latestAssessedOn !== null ? Carbon::parse($latestAssessedOn) : null);

        if ($active && $this->schedule->isOverdue($next, $today)) {
            $findings[] = ['key' => self::REVIEW_OVERDUE, 'next_review_on' => $next->toDateString()];
        }

        if ($supplier->owner_user_id === null) {
            $findings[] = ['key' => self::MISSING_OWNER];
        }

        foreach ([self::DOCUMENT_EXPIRED, self::DOCUMENT_EXPIRING] as $key) {
            foreach ($documents as $document) {
                $expired = $document->validityStatus($today) === SupplierDocument::STATUS_EXPIRED;

                if ($key === self::DOCUMENT_EXPIRED ? $expired : $document->isExpiringSoon($today)) {
                    $findings[] = [
                        'key' => $key,
                        'document_type' => $document->document_type,
                        'title' => $document->title,
                        'valid_until' => $document->valid_until->toDateString(),
                        'days' => $document->daysUntilExpiry($today),
                    ];
                }
            }
        }

        return $findings;
    }
}
