<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierAssessment;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierDocument;
use App\Models\SupplierDueDiligenceAssessment;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Services\Suppliers\Assurance\SupplierAssuranceResolver;
use App\Services\Suppliers\Assurance\SupplierDueDiligenceService;
use App\Services\Suppliers\Assurance\SupplierProfilePredicates;
use App\Services\Suppliers\Assurance\SupplierRequirementStatus;
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
 * Leverandørkontroll adds four (docs/supplier-assurance-v2-plan.md §15.1, signals 6–9), each read
 * off what SupplierAssuranceResolver already computes — the requirements that apply now, their
 * visningsstatus and follow-up dates, and «Krever beslutning». No rule is decided again here:
 *
 *  - Krever beslutning (6): the resolver's signal is on. Names the open mandatory requirements.
 *  - Kontroll forfalt (8): active, and an applying requirement is Må fornyes or Aksept utløpt — each
 *    named with why: the control interval ran out, a document it rests on expired or was replaced,
 *    or the acceptance ran out.
 *  - Krav ikke vurdert (7): an applying Obligatorisk or Viktig requirement has no control yet — for a
 *    supplier under evaluation only those controlled before the contract. No grace period: the plan
 *    has none. Oppfølging-level requirements never raise it.
 *  - Profil ikke fylt ut (9): Viktig or Kritisk, the profile not complete, and the customer has active
 *    catalogue requirements.
 *
 * One finding per rule and supplier, listing its requirements. A requirement already named under
 * Krever beslutning is not named again under Kontroll forfalt or Krav ikke vurdert: the decision is
 * what it needs. A customer without control requirements gets none of the four.
 *
 * Phase 7 adds Aktsomhetsvurdering mangler (10) and Aktsomhetsvurdering forfalt (11) — see
 * dueDiligenceFindingsFor().
 *
 * An ended supplier raises nothing: it is no longer followed up. A supplier under evaluation
 * (onboarding) is not expected to be assessed yet, so only the owner and documentation rules apply
 * from v1, and Kontroll forfalt (active only) is not raised.
 *
 * Nothing is written, ever: no evaluation when a control falls due, no decision when an acceptance
 * runs out, no stored flag. The decision in force is never touched — «Godkjent» can stand next to
 * «Kontroll forfalt».
 *
 * ONLY SUPPLIER DATA. The rules read suppliers, supplier_assessments, supplier_documents and the
 * Leverandørkontroll tables, nothing else — no risk, case, compliance requirement or link to another
 * module, so no finding can depend on, or reveal, something the person cannot see there.
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

    public const DECISION_REQUIRED = 'decision_required';

    public const CONTROL_OVERDUE = 'control_overdue';

    public const REQUIREMENT_NOT_EVALUATED = 'requirement_not_evaluated';

    public const PROFILE_INCOMPLETE = 'profile_incomplete';

    public const DUE_DILIGENCE_MISSING = 'due_diligence_missing';

    public const DUE_DILIGENCE_OVERDUE = 'due_diligence_overdue';

    /** Why a requirement is under Kontroll forfalt. */
    public const OVERDUE_ACCEPTANCE = 'acceptance_expired';

    public const OVERDUE_DOCUMENT_EXPIRED = 'document_expired';

    public const OVERDUE_DOCUMENT_REPLACED = 'document_replaced';

    public const OVERDUE_CONTROL_INTERVAL = 'control_interval';

    /** Display order: the decision a mandatory requirement asks for first. */
    public const CATEGORIES = [
        self::DECISION_REQUIRED,
        self::CONTROL_OVERDUE,
        self::REQUIREMENT_NOT_EVALUATED,
        self::PROFILE_INCOMPLETE,
        self::DUE_DILIGENCE_MISSING,
        self::DUE_DILIGENCE_OVERDUE,
        self::NOT_ASSESSED,
        self::REVIEW_OVERDUE,
        self::MISSING_OWNER,
        self::DOCUMENT_EXPIRED,
        self::DOCUMENT_EXPIRING,
    ];

    public function __construct(
        private readonly SupplierAccessService $access,
        private readonly SupplierReviewSchedule $schedule,
        private readonly SupplierAssuranceResolver $assurance,
        private readonly SupplierDueDiligenceService $dueDiligence,
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
     * SupplierAccessService. One batch whatever their number: assessments, documents, profiles and
     * the resolver's rows and decisions are each read once, by these ids within the customer.
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

        $assuranceRows = $this->assurance->rows($open, $today);
        $decisions = $this->assurance->decisionsInForce($ids);
        $hasCatalogue = $ids !== [] && SupplierControlRequirement::query()
            ->where('customer_id', $customerId)
            ->whereNull('supplier_id')
            ->where('status', SupplierControlRequirement::STATUS_ACTIVE)
            ->exists();
        $dueDiligence = $this->dueDiligence->inForce($customerId, $ids);
        // Signal 9 needs the catalogue; signal 10 any applying requirement, a supplier's own included.
        $profiles = $hasCatalogue || array_filter($assuranceRows) !== [] ? SupplierProfile::query()
            ->where('customer_id', $customerId)
            ->whereIn('supplier_id', $ids)
            ->get()
            ->keyBy('supplier_id') : collect();

        $findings = [];

        foreach ($suppliers as $supplier) {
            $id = (int) $supplier->id;
            $findings[$id] = $supplier->isEnded() ? [] : [
                ...$this->assuranceFindingsFor($supplier, $assuranceRows[$id] ?? [], ($decisions[$id] ?? null)?->decision, $hasCatalogue, $profiles->get($id)),
                ...$this->dueDiligenceFindingsFor($supplier, $assuranceRows[$id] ?? [], $profiles->get($id), $dueDiligence[$id] ?? null, $today),
                ...$this->findingsFor($supplier, $latest->get($id), $documents->get($id, collect()), $today),
            ];
        }

        return $findings;
    }

    /**
     * Signals 6–9 for one supplier that is not ended, from the resolver's rows. Each requirement
     * listed carries what the page needs to name it and say why; the dates are the resolver's.
     *
     * @param  list<array<string, mixed>>  $rows  SupplierAssuranceResolver::rows() for this supplier
     * @return list<array<string, mixed>>
     */
    private function assuranceFindingsFor(Supplier $supplier, array $rows, ?string $decisionInForce, bool $hasCatalogue, ?SupplierProfile $profile): array
    {
        $findings = [];
        $state = SupplierAssuranceResolver::resolve($rows, $decisionInForce);
        $named = [];

        if ($state !== null && $state['decision_required']) {
            $findings[] = [
                'key' => self::DECISION_REQUIRED,
                'requirements' => array_map(fn (array $row): array => [
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'display_status' => $row['display_status'],
                ], $state['open_mandatory']),
            ];
            $named = array_fill_keys(array_column($state['open_mandatory'], 'id'), true);
        }

        $sorted = $rows;
        usort($sorted, fn (array $a, array $b): int => [array_search($a['level'], SupplierControlRequirement::LEVELS, true), mb_strtolower($a['title']), $a['id']]
            <=> [array_search($b['level'], SupplierControlRequirement::LEVELS, true), mb_strtolower($b['title']), $b['id']]);
        $unnamed = array_values(array_filter($sorted, fn (array $row): bool => ! isset($named[$row['id']])));

        if ($supplier->status === Supplier::STATUS_ACTIVE) {
            $overdue = array_values(array_filter(array_map(fn (array $row): ?array => $this->overdue($row), $unnamed)));

            if ($overdue !== []) {
                $findings[] = ['key' => self::CONTROL_OVERDUE, 'requirements' => $overdue];
            }
        }

        $notEvaluated = array_values(array_filter($unnamed, fn (array $row): bool => $row['display_status'] === SupplierRequirementStatus::NOT_EVALUATED
            && in_array($row['level'], [SupplierControlRequirement::LEVEL_MANDATORY, SupplierControlRequirement::LEVEL_IMPORTANT], true)
            && ($supplier->status !== Supplier::STATUS_ONBOARDING || $row['control_point'] === 'before_contract')));

        if ($notEvaluated !== []) {
            $findings[] = [
                'key' => self::REQUIREMENT_NOT_EVALUATED,
                'requirements' => array_map(fn (array $row): array => ['id' => $row['id'], 'title' => $row['title'], 'level' => $row['level']], $notEvaluated),
            ];
        }

        $important = in_array($supplier->criticality, [Supplier::CRITICALITY_IMPORTANT, Supplier::CRITICALITY_CRITICAL], true);

        if ($hasCatalogue && $important && ($profile === null || ! SupplierProfile::isComplete($supplier, $profile->answers()))) {
            $findings[] = ['key' => self::PROFILE_INCOMPLETE, 'criticality' => $supplier->criticality];
        }

        return $findings;
    }

    /**
     * Signals 10–11 (docs/supplier-assurance-v2-plan.md §15.1), for a supplier that is not ended and
     * has at least one requirement applying — like signals 6–9, a customer that has not started
     * Leverandørkontroll gets neither.
     *
     *  - Aktsomhetsvurdering mangler (10): due_diligence_relevant from the profile, and no assessment.
     *  - Aktsomhetsvurdering forfalt (11): active, and the next assessment (assessed_on +
     *    review_interval_months) has passed — due today is not overdue.
     *
     * The two never meet: one needs no assessment, the other one. Neither reads the areas or the
     * conclusion — «Høy» is not a signal; the person's follow-up of it is in Avvik og Risiko.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function dueDiligenceFindingsFor(Supplier $supplier, array $rows, ?SupplierProfile $profile, ?SupplierDueDiligenceAssessment $current, CarbonImmutable $today): array
    {
        if ($rows === []) {
            return [];
        }

        if ($current === null) {
            $relevant = SupplierProfilePredicates::dueDiligenceRelevant(SupplierProfilePredicates::for($supplier, $profile));

            return $relevant ? [['key' => self::DUE_DILIGENCE_MISSING]] : [];
        }

        if ($supplier->status === Supplier::STATUS_ACTIVE && $this->dueDiligence->isOverdue($current, $today)) {
            return [['key' => self::DUE_DILIGENCE_OVERDUE, 'next_on' => $this->dueDiligence->nextOn($current)?->toDateString()]];
        }

        return [];
    }

    /**
     * One requirement under Kontroll forfalt, with why and since when — or null when it is not
     * overdue. Aksept utløpt goes first, then a document that no longer holds, then the interval: the
     * order of what a person has to do about it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function overdue(array $row): ?array
    {
        $followUp = $row['follow_up'];
        $base = ['id' => $row['id'], 'title' => $row['title'], 'level' => $row['level']];

        return match ($row['display_status']) {
            SupplierRequirementStatus::ACCEPTANCE_EXPIRED => $base + ['reason' => self::OVERDUE_ACCEPTANCE, 'date' => $followUp['accepted_until']],
            SupplierRequirementStatus::RENEWAL_DUE => $followUp['document_renewal_due']
                ? $base + [
                    'reason' => $followUp['document']['replaced'] ? self::OVERDUE_DOCUMENT_REPLACED : self::OVERDUE_DOCUMENT_EXPIRED,
                    'date' => $followUp['document']['replaced'] ? null : $followUp['document']['valid_until'],
                    'document_title' => $followUp['document']['title'],
                ]
                : $base + ['reason' => self::OVERDUE_CONTROL_INTERVAL, 'date' => $followUp['next_control_on']],
            default => null,
        };
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
