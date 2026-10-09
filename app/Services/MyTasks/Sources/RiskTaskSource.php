<?php

namespace App\Services\MyTasks\Sources;

use App\Models\Risk;
use App\Models\RiskTreatmentAction;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\MyTasks\MyTask;
use App\Services\MyTasks\MyTaskSource;
use App\Services\Risk\RiskAccessService;
use App\Services\Risk\RiskAttentionService as Attention;
use App\Support\CustomerPermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Risiko: two kinds of work, each on the person it is assigned to.
 *
 *  - The risks the person is risikoeier for that «Trenger oppmerksomhet» flags — one task per risk,
 *    each RiskAttentionService finding a reason (not assessed, residual not assessed, high residual,
 *    review overdue, acceptance expired, tiltak overdue). A risk with no finding is a register entry,
 *    not work, and has no task; a closed risk has none.
 *  - The open tiltak the person is ansvarlig for, one task each, due on the tiltak's own frist.
 *
 * ACCESS. RiskAccessService decides, as everywhere in Risiko: the customer holds the module, the
 * person holds risk.view, and the risk is in one of their fagområder (visibleRisks()). A tiltak is
 * shown only on a risk the person can see. Being ansvarlig grants nothing.
 *
 * READ, NOT ACT. Each reason says whether the person holds what doing it asks for — risk.assess to
 * assess or review, risk.accept for an acceptance, risk.edit for tiltak — in the risk's fagområde.
 */
class RiskTaskSource implements MyTaskSource
{
    private const OVERDUE_KEYS = [Attention::REVIEW_OVERDUE, Attention::ACCEPTANCE_EXPIRED, Attention::ACTIONS_OVERDUE];

    public function __construct(
        private readonly RiskAccessService $access,
        private readonly Attention $attention,
        private readonly ModuleEntitlementService $entitlements,
    ) {}

    public function module(): string
    {
        return 'risk';
    }

    public function isAvailableFor(User $user): bool
    {
        $customer = $user->customer;

        return $customer !== null && $this->entitlements->hasModule($customer, 'risk') && $this->access->canOpenModule($user);
    }

    public function openTasksFor(User $user, int $customerId, CarbonImmutable $today): Collection
    {
        if (! $this->isAvailableFor($user)) {
            return collect();
        }

        $can = new AreaPermissions($this->access, $user);

        return $this->ownerTasks($user, $today, $can)->merge($this->actionTasks($user, $today, $can))->values();
    }

    /** @return Collection<int, MyTask> */
    private function ownerTasks(User $user, CarbonImmutable $today, AreaPermissions $can): Collection
    {
        $risks = $this->access->visibleRisks($user)
            ->where('risks.owner_user_id', $user->id)
            ->where('risks.status', '!=', Risk::STATUS_CLOSED)
            ->orderBy('risks.id')
            ->get(['risks.*']);

        if ($risks->isEmpty()) {
            return collect();
        }

        $findings = $this->attention->findingsForRisks($risks, $today);

        return $risks->toBase()
            ->filter(fn (Risk $risk): bool => $findings[(int) $risk->id] !== [])
            ->map(function (Risk $risk) use ($findings, $user, $can): MyTask {
                $reasons = [];

                foreach ($findings[(int) $risk->id] as $key => $detail) {
                    $reasons[] = [
                        'key' => $key,
                        'due_on' => $detail['next_review_on'] ?? $detail['valid_until'] ?? $detail['earliest_due_on'] ?? null,
                        'overdue' => in_array($key, self::OVERDUE_KEYS, true),
                        'can_act' => $can->allows($this->permissionFor($key), (int) $risk->customer_id, (int) $risk->business_area_id),
                        'count' => $detail['count'] ?? null,
                    ];
                }

                return $this->task(
                    'risk-'.$risk->id,
                    'risk_follow_up',
                    $risk,
                    $risk->title,
                    route('app.risk.show', ['riskId' => $risk->id], false),
                    $reasons,
                    $user,
                );
            })
            ->values();
    }

    /** @return Collection<int, MyTask> */
    private function actionTasks(User $user, CarbonImmutable $today, AreaPermissions $can): Collection
    {
        $actions = RiskTreatmentAction::query()
            ->where('customer_id', (int) $user->customer_id)
            ->where('owner_user_id', $user->id)
            ->where('status', RiskTreatmentAction::STATUS_OPEN)
            ->whereIn('risk_id', $this->access->visibleRisks($user)->where('risks.status', '!=', Risk::STATUS_CLOSED)->select('risks.id'))
            ->with('risk')
            ->orderBy('id')
            ->get();

        return $actions->toBase()
            ->map(function (RiskTreatmentAction $action) use ($user, $today, $can): MyTask {
                $dueOn = $action->due_at?->toDateString();
                $overdue = $dueOn !== null && CarbonImmutable::parse($dueOn)->lt($today);

                return $this->task(
                    'risk-action-'.$action->id,
                    'risk_action',
                    $action->risk,
                    $action->title,
                    route('app.risk.show', ['riskId' => $action->risk_id], false),
                    [[
                        'key' => $overdue ? 'action_overdue' : 'action_open',
                        'due_on' => $dueOn,
                        'overdue' => $overdue,
                        'can_act' => $can->allows(CustomerPermissionCatalog::RISK_EDIT, (int) $action->risk->customer_id, (int) $action->risk->business_area_id),
                    ]],
                    $user,
                    ['action' => ['id' => (int) $action->id]],
                );
            })
            ->values();
    }

    /**
     * @param  list<array<string, mixed>>  $reasons
     * @param  array<string, mixed>  $extra
     */
    private function task(string $id, string $type, Risk $risk, string $title, string $url, array $reasons, User $user, array $extra = []): MyTask
    {
        $dates = array_values(array_filter(array_column($reasons, 'due_on')));
        sort($dates);

        return new MyTask(
            id: $id,
            module: $this->module(),
            type: $type,
            title: $title,
            subjectTitle: $risk->title,
            assigneeUserId: (int) $user->id,
            actionUrl: $url,
            dueOn: $dates !== [] ? CarbonImmutable::parse($dates[0]) : null,
            overdue: in_array(true, array_column($reasons, 'overdue'), true),
            reasons: $reasons,
            canAct: ! in_array(false, array_column($reasons, 'can_act'), true),
            details: ['risk' => ['id' => (int) $risk->id, 'title' => $risk->title]] + $extra,
            subject: ['prefix' => 'risk', 'metadata' => ['risk_id' => (int) $risk->id]],
        );
    }

    private function permissionFor(string $key): string
    {
        return match ($key) {
            Attention::ACCEPTANCE_EXPIRED => CustomerPermissionCatalog::RISK_ACCEPT,
            Attention::ACTIONS_OVERDUE => CustomerPermissionCatalog::RISK_EDIT,
            default => CustomerPermissionCatalog::RISK_ASSESS,
        };
    }
}
