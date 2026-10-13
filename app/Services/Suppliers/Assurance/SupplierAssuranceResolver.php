<?php

namespace App\Services\Suppliers\Assurance;

use App\Models\Supplier;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierDocument;
use App\Models\SupplierRequirementEvaluation;
use App\Models\SupplierRequirementEvaluationDocument;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Kontrolltilstand — what the requirements and controls show today (docs/supplier-assurance-v2-plan.md
 * §7.1, §8.3, §9.2–9.3). Computed on every read from the requirements that apply now
 * (SupplierRequirementApplicability) and each one's visningsstatus (SupplierRequirementStatus);
 * never stored. (The retired kontrollbeslutninger kept a snapshot of it; that is history, shown as
 * such, and never read back as the state now.)
 *
 *  - Obligatorisk krav åpent: an applying mandatory requirement is not accepted — accepted being
 *    Dokumentert or a Midlertidig akseptert that has not expired;
 *  - Krever oppfølging: no mandatory requirement is open, but at least one applying requirement is
 *    not Dokumentert (Midlertidig akseptert, Ikke vurdert and Må fornyes included);
 *  - I orden: every applying requirement is Dokumentert;
 *  - no state at all when nothing applies — Kontrollstatus is then not shown.
 *
 * The state is a fact about the requirements — Kravstatus — and never an approval of the supplier.
 * The general kontrollbeslutning that once stood beside it was retired on 2026-10-12: nothing here
 * reads or weighs a decision. Only Supplier data is read: no compliance status, no audit, no risk,
 * no case.
 */
class SupplierAssuranceResolver
{
    public const STATE_MANDATORY_OPEN = 'mandatory_open';

    public const STATE_FOLLOW_UP_REQUIRED = 'follow_up_required';

    public const STATE_IN_ORDER = 'in_order';

    /** Every visningsstatus, in the order the counts are kept. */
    public const DISPLAY_STATUSES = [
        SupplierRequirementEvaluation::STATUS_DOCUMENTED,
        SupplierRequirementEvaluation::STATUS_PARTIALLY_DOCUMENTED,
        SupplierRequirementEvaluation::STATUS_MISSING,
        SupplierRequirementStatus::NOT_EVALUATED,
        SupplierRequirementEvaluation::STATUS_TEMPORARILY_ACCEPTED,
        SupplierRequirementStatus::RENEWAL_DUE,
        SupplierRequirementStatus::ACCEPTANCE_EXPIRED,
    ];

    public function __construct(
        private readonly SupplierRequirementApplicability $applicability,
    ) {}

    /** Oppfylt (§8.3): only Dokumentert. */
    public static function fulfilled(string $displayStatus): bool
    {
        return $displayStatus === SupplierRequirementEvaluation::STATUS_DOCUMENTED;
    }

    /** Akseptert (§8.3): Dokumentert, or Midlertidig akseptert that has not expired. */
    public static function accepted(string $displayStatus): bool
    {
        return in_array($displayStatus, [SupplierRequirementEvaluation::STATUS_DOCUMENTED, SupplierRequirementEvaluation::STATUS_TEMPORARILY_ACCEPTED], true);
    }

    /**
     * The control state from the requirements that apply now, each with its visningsstatus. Null
     * when nothing applies. Pure.
     *
     * @param  iterable<array{id: int, title: string, level: string, display_status: string}>  $rows
     * @return array{state: string, applicable_count: int, counts: array<string, int>, open_mandatory: list<array<string, mixed>>, unmet: list<array<string, mixed>>}|null
     */
    public static function resolve(iterable $rows): ?array
    {
        $counts = array_fill_keys(self::DISPLAY_STATUSES, 0);
        $openMandatory = [];
        $unmet = [];
        $applicable = 0;
        $allDocumented = true;

        foreach ($rows as $row) {
            $entry = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'level' => (string) $row['level'],
                'display_status' => (string) $row['display_status'],
            ];
            $applicable++;
            $counts[$entry['display_status']] = ($counts[$entry['display_status']] ?? 0) + 1;
            $fulfilled = self::fulfilled($entry['display_status']);
            $allDocumented = $allDocumented && $fulfilled;
            $gate = in_array($entry['level'], [SupplierControlRequirement::LEVEL_MANDATORY, SupplierControlRequirement::LEVEL_IMPORTANT], true);

            if ($gate && ! $fulfilled) {
                $unmet[] = $entry;
            }

            if ($entry['level'] === SupplierControlRequirement::LEVEL_MANDATORY && ! self::accepted($entry['display_status'])) {
                $openMandatory[] = $entry;
            }
        }

        if ($applicable === 0) {
            return null;
        }

        $state = match (true) {
            $openMandatory !== [] => self::STATE_MANDATORY_OPEN,
            ! $allDocumented => self::STATE_FOLLOW_UP_REQUIRED,
            default => self::STATE_IN_ORDER,
        };

        $sort = fn (array $a, array $b): int => [array_search($a['level'], SupplierControlRequirement::LEVELS, true), mb_strtolower($a['title']), $a['id']]
            <=> [array_search($b['level'], SupplierControlRequirement::LEVELS, true), mb_strtolower($b['title']), $b['id']];
        usort($openMandatory, $sort);
        usort($unmet, $sort);

        return [
            'state' => $state,
            'applicable_count' => $applicable,
            'counts' => $counts,
            'open_mandatory' => $openMandatory,
            'unmet' => $unmet,
        ];
    }

    /**
     * The control state of one supplier now, read from the database. Null when nothing applies.
     *
     * @return array<string, mixed>|null
     */
    public function forSupplier(Supplier $supplier, ?CarbonInterface $today = null): ?array
    {
        return $this->forSuppliers(collect([$supplier]), $today)[(int) $supplier->id] ?? null;
    }

    /**
     * The same for many suppliers of one customer at once, keyed by supplier id — for the register.
     *
     * @param  Collection<int, Supplier>  $suppliers
     * @return array<int, array<string, mixed>|null>
     */
    public function forSuppliers(Collection $suppliers, ?CarbonInterface $today = null): array
    {
        $result = [];

        foreach ($this->rows($suppliers, $today) as $supplierId => $supplierRows) {
            $result[$supplierId] = self::resolve($supplierRows);
        }

        return $result;
    }

    /**
     * Each supplier's applying requirements with their visningsstatus now — the input of resolve() —
     * and, for the oppfølgingsplan and Trenger oppmerksomhet, the control point and the follow-up
     * dates (SupplierRequirementStatus::followUp()). The documents are the rows as they are now, never
     * a control's snapshot.
     *
     * One batch for all the suppliers, whatever their number: the applicability (requirements,
     * profiles, overrides), the controls with their documentation, and the documentation rows — each
     * read once, by these supplier ids within their customer.
     *
     * @param  Collection<int, Supplier>  $suppliers  of one customer, already reached through SupplierAccessService
     * @return array<int, list<array{id: int, title: string, level: string, control_point: string, control_interval_months: int|null, evaluated: bool, display_status: string, follow_up: array<string, mixed>}>>
     */
    public function rows(Collection $suppliers, ?CarbonInterface $today = null): array
    {
        if ($suppliers->isEmpty()) {
            return [];
        }

        $today ??= now();
        $ids = $suppliers->map(fn (Supplier $supplier): int => (int) $supplier->id)->all();
        $customerIds = $suppliers->map(fn (Supplier $supplier): int => (int) $supplier->customer_id)->unique()->values()->all();
        $decided = $this->applicability->forSuppliers($suppliers);
        $evaluations = SupplierRequirementEvaluation::query()
            ->with('documents')
            ->whereIn('customer_id', $customerIds)
            ->whereIn('supplier_id', $ids)
            ->get()
            ->toBase()
            ->groupBy(fn (SupplierRequirementEvaluation $evaluation): string => $evaluation->supplier_id.':'.$evaluation->requirement_id);
        $documents = SupplierDocument::query()->whereIn('customer_id', $customerIds)->whereIn('supplier_id', $ids)->get()->keyBy('id');

        $result = [];

        foreach ($ids as $supplierId) {
            $result[$supplierId] = [];

            foreach ($decided[$supplierId] ?? [] as $decision) {
                if (! $decision['applies']) {
                    continue;
                }

                $requirement = $decision['requirement'];
                $inForce = SupplierRequirementStatus::current($evaluations->get($supplierId.':'.$requirement->id) ?? []);
                $documentsNow = $inForce?->documents->map(fn (SupplierRequirementEvaluationDocument $used) => $documents->get($used->supplier_document_id))->filter() ?? collect();

                $result[$supplierId][] = [
                    'id' => (int) $requirement->id,
                    'title' => $requirement->title,
                    'level' => $requirement->level,
                    'control_point' => $requirement->control_point,
                    'control_interval_months' => $requirement->control_interval_months,
                    'evaluated' => $inForce !== null,
                    'display_status' => SupplierRequirementStatus::display($inForce, $requirement->control_interval_months, $documentsNow, $today),
                    'follow_up' => SupplierRequirementStatus::followUp($inForce, $requirement->control_interval_months, $documentsNow, $today),
                ];
            }
        }

        return $result;
    }
}
