<?php

namespace App\Services\MyTasks\Sources;

use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Models\User;
use App\Services\MyTasks\MyTask;
use App\Services\MyTasks\MyTaskSource;
use App\Services\Suppliers\SupplierAccessService;
use App\Services\Suppliers\SupplierAttentionService as Attention;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Leverandøroppfølging: the suppliers this person is intern ansvarlig for that need follow-up.
 *
 * ONE TASK PER SUPPLIER. Whether a supplier needs follow-up, and why, is SupplierAttentionService's
 * — the same eleven signals the register's «Trenger oppmerksomhet» panel and the supplier page show,
 * with the same dates (SupplierReviewSchedule, SupplierDocument::EXPIRING_SOON_DAYS, the resolver's
 * follow-up dates). Nothing is decided again here: each finding becomes a reason on the supplier's
 * one task. A supplier with no finding has no task, so resolving the last one retires it.
 *
 * WHOSE. suppliers.owner_user_id, read live — reassigning the supplier moves the task with no write
 * here. An ended supplier is not followed up and has none.
 *
 * ACCESS. Only through SupplierAccessService: the customer holds the module and the person holds
 * supplier.view (canReadFromAnotherModule — Oppfølging is outside the module's route guard), and the
 * supplier is one visibleSuppliers() returns. Being intern ansvarlig grants nothing; a person who
 * lost supplier.view sees no task even if the field still names them.
 *
 * READ, NOT ACT. The person may lack the permission a reason asks for — controlling a requirement is
 * supplier.assure, assessing is supplier.assess. The task is still shown, each reason says whether
 * they can act on it, and nothing widens their rights.
 */
class SupplierTaskSource implements MyTaskSource
{
    /** A reason that is overdue by definition, whatever its date says. */
    private const OVERDUE_KEYS = [
        Attention::REVIEW_OVERDUE,
        Attention::CONTROL_OVERDUE,
        Attention::DOCUMENT_EXPIRED,
        Attention::DUE_DILIGENCE_OVERDUE,
    ];

    public function __construct(
        private readonly SupplierAccessService $access,
        private readonly Attention $attention,
    ) {}

    public function module(): string
    {
        return 'supplier';
    }

    public function isAvailableFor(User $user): bool
    {
        return $this->access->canReadFromAnotherModule($user);
    }

    public function openTasksFor(User $user, int $customerId, CarbonImmutable $today): Collection
    {
        if (! $this->isAvailableFor($user)) {
            return collect();
        }

        $suppliers = $this->access->visibleSuppliers($user)
            ->where('suppliers.owner_user_id', $user->id)
            ->where('suppliers.status', '!=', Supplier::STATUS_ENDED)
            ->orderBy('suppliers.id')
            ->get(['suppliers.*']);

        if ($suppliers->isEmpty()) {
            return collect();
        }

        $findings = $this->attention->findingsForSuppliers($suppliers, $today);
        $can = [
            'edit' => $this->access->canEdit($user),
            'assess' => $this->access->canAssess($user),
            'assure' => $this->access->canAssure($user),
        ];

        return $suppliers->toBase()
            ->filter(fn (Supplier $supplier): bool => ($findings[(int) $supplier->id] ?? []) !== [])
            ->map(fn (Supplier $supplier): MyTask => $this->task($supplier, $findings[(int) $supplier->id], $user, $can))
            ->values();
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @param  array{edit: bool, assess: bool, assure: bool}  $can
     */
    private function task(Supplier $supplier, array $findings, User $user, array $can): MyTask
    {
        $reasons = array_map(fn (array $finding): array => $this->reason($finding, $can), $findings);
        $dates = array_values(array_filter(array_column($reasons, 'due_on')));
        sort($dates);

        return new MyTask(
            id: 'supplier-'.$supplier->id,
            module: $this->module(),
            type: 'supplier_follow_up',
            title: $supplier->name,
            subjectTitle: $supplier->name,
            assigneeUserId: (int) $user->id,
            actionUrl: route('app.supplier-management.show', ['supplierId' => $supplier->id], false),
            dueOn: $dates !== [] ? CarbonImmutable::parse($dates[0]) : null,
            overdue: in_array(true, array_column($reasons, 'overdue'), true),
            reasons: $reasons,
            canAct: ! in_array(false, array_column($reasons, 'can_act'), true),
            details: [
                'supplier' => [
                    'id' => (int) $supplier->id,
                    'name' => $supplier->name,
                    'status' => $supplier->status,
                    'criticality' => $supplier->criticality,
                ],
            ],
            // Leverandøroppfølging warns about documentation 60 days ahead; that is its window.
            dueSoonDays: SupplierDocument::EXPIRING_SOON_DAYS,
            subject: ['prefix' => 'supplier', 'metadata' => ['supplier_id' => (int) $supplier->id]],
        );
    }

    /**
     * One finding as a reason: its key, the date it is about, whether it is overdue, and whether this
     * person may do what it asks.
     *
     * @param  array<string, mixed>  $finding
     * @param  array{edit: bool, assess: bool, assure: bool}  $can
     * @return array<string, mixed>
     */
    private function reason(array $finding, array $can): array
    {
        $key = (string) $finding['key'];
        $requirementDates = array_values(array_filter(array_column($finding['requirements'] ?? [], 'date')));
        sort($requirementDates);

        $dueOn = match ($key) {
            Attention::REVIEW_OVERDUE => $finding['next_review_on'] ?? null,
            Attention::DOCUMENT_EXPIRED, Attention::DOCUMENT_EXPIRING => $finding['valid_until'] ?? null,
            Attention::DUE_DILIGENCE_OVERDUE => $finding['next_on'] ?? null,
            Attention::CONTROL_OVERDUE => $requirementDates[0] ?? null,
            default => null,
        };

        return [
            'key' => $key,
            'due_on' => $dueOn,
            'overdue' => in_array($key, self::OVERDUE_KEYS, true),
            'can_act' => $this->canActOn($key, $can),
            'document_title' => $finding['title'] ?? null,
            'requirement_count' => isset($finding['requirements']) ? count($finding['requirements']) : null,
        ];
    }

    /**
     * The permission each reason's work asks for, as the module's own controllers check it.
     *
     * @param  array{edit: bool, assess: bool, assure: bool}  $can
     */
    private function canActOn(string $key, array $can): bool
    {
        return match ($key) {
            // Leverandørkontroll: controls, decisions and aktsomhetsvurdering.
            Attention::DECISION_REQUIRED,
            Attention::CONTROL_OVERDUE,
            Attention::REQUIREMENT_NOT_EVALUATED,
            Attention::DUE_DILIGENCE_MISSING,
            Attention::DUE_DILIGENCE_OVERDUE => $can['assure'],
            // Leverandørvurdering.
            Attention::NOT_ASSESSED,
            Attention::REVIEW_OVERDUE => $can['assess'],
            // Documentation is kept by either (SupplierDocumentController).
            Attention::DOCUMENT_EXPIRED,
            Attention::DOCUMENT_EXPIRING => $can['edit'] || $can['assure'],
            // Profile and the register itself.
            default => $can['edit'],
        };
    }
}
