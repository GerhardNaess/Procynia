<?php

namespace App\Services\Notifications;

use App\Models\ComplianceAudit;
use App\Models\ComplianceRequirement;
use App\Models\ImprovementAction;
use App\Models\ImprovementCase;
use App\Models\Kpi;
use App\Models\ManagementReview;
use App\Models\ManagementReviewDecision;
use App\Models\Objective;
use App\Models\QualityItem;
use App\Models\Risk;
use App\Models\RiskTreatmentAction;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Services\ManagementReview\ManagementReviewAccessService;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Objectives\ObjectiveAccessService;
use App\Services\Permissions\CustomerPermissionService;
use App\Services\Risk\RiskAccessService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * «Du er tildelt ansvar» for the styringsmoduler — the bell's one assignment event per owner field.
 *
 * Driven by AssignmentObserver on `saved`, so every write path that sets an owner — the module's own
 * forms, a handoff from another module, a creator service — announces the handover the same way,
 * and none can forget to. Only a real change counts: a new object with an owner, or an owner field
 * that changed. An unchanged owner, an owner removed, an object that is closed, retired or finished,
 * and the person who made the choice themselves are never told.
 *
 * The recipient must be active, of the object's customer (UserNotificationWriter), and able to read
 * the object through its module's own access service — being named ansvarlig grants nothing, and a
 * person who cannot open the risk is not told about it. Written after commit, rescued, and keyed on
 * the object, the recipient and the save's time, so a replayed save is one notification and a real
 * second handover is another.
 *
 * Not here: ordinary edits, status changes, comments — those are not news to anybody in the bell.
 */
class AssignmentNotifier
{
    public function __construct(
        private readonly UserNotificationWriter $writer,
        private readonly CustomerContext $customerContext,
        private readonly ModuleEntitlementService $entitlements,
        private readonly CustomerPermissionService $permissions,
        private readonly RiskAccessService $riskAccess,
        private readonly ImprovementCaseAccessService $improvementAccess,
        private readonly ComplianceAccessService $complianceAccess,
        private readonly ObjectiveAccessService $objectiveAccess,
        private readonly ManagementReviewAccessService $managementReviewAccess,
    ) {}

    /** The models whose owner field is announced. */
    public const MODELS = [
        Risk::class,
        RiskTreatmentAction::class,
        ImprovementCase::class,
        ImprovementAction::class,
        ComplianceRequirement::class,
        ComplianceAudit::class,
        QualityItem::class,
        Objective::class,
        Kpi::class,
        ManagementReview::class,
        ManagementReviewDecision::class,
    ];

    /**
     * A saved change of owner. `$created` is the model's own `created` event; on `updated` only a
     * change of the owner field counts. (wasRecentlyCreated cannot tell the two apart: it stays true
     * on an instance for the rest of its life, so a later edit through it would look like a new
     * assignment.) Called before the original is synced, so the raw original is the previous owner.
     */
    public function announce(Model $model, bool $created): void
    {
        // The cheap part first: most saves change nothing about who is responsible.
        $field = $model instanceof ComplianceAudit ? 'responsible_user_id' : 'owner_user_id';

        if (! in_array($model::class, self::MODELS, true) || (! $created && ! $model->wasChanged($field))) {
            return;
        }

        $recipientId = $model->getAttribute($field) !== null ? (int) $model->getAttribute($field) : null;
        $previousId = $created ? null : $model->getRawOriginal($field);

        if ($recipientId === null || ($previousId !== null && (int) $previousId === $recipientId)) {
            return;
        }

        $definition = $this->definition($model);

        if ($definition === null || ! $definition['open']) {
            return;
        }

        $recipient = User::query()->find($recipientId);

        if (! $recipient instanceof User
            || ! $recipient->is_active
            || (int) $recipient->customer_id !== (int) $model->getAttribute('customer_id')
            || ! ($definition['visible'])($recipient)) {
            return;
        }

        $locale = $this->customerContext->resolveLanguageCode($recipient);
        $actor = Auth::user();
        $params = ['title' => $definition['title'], 'context' => $definition['context'] ?? ''];

        $this->writer->notify(
            (int) $model->getAttribute('customer_id'),
            $recipient,
            $definition['event'],
            sprintf('%s:%d:%d:%d', $definition['event'], $model->getKey(), $recipientId, optional($model->getAttribute('updated_at'))?->getTimestamp() ?? 0),
            __('procynia.task_notifications.assigned.'.$definition['key'].'.title', [], $locale),
            __('procynia.task_notifications.assigned.'.$definition['key'].'.message', $params, $locale),
            $definition['url'],
            $definition['metadata'] + [
                'previous_owner_user_id' => $previousId !== null ? (int) $previousId : null,
                'assigned_by_user_id' => $actor instanceof User ? (int) $actor->id : null,
            ],
            actor: $actor instanceof User ? $actor : null,
        );
    }

    /**
     * What the model's assignment is: which field, whether the object is still something to work on,
     * who may read it, and where the notification leads.
     *
     * @return array{key: string, event: string, field: string, open: bool, visible: callable(User): bool, title: string, context?: string, url: string, metadata: array<string, int>}|null
     */
    private function definition(Model $model): ?array
    {
        return match (true) {
            $model instanceof Risk => [
                'key' => 'risk_owner', 'event' => 'risk.owner_assigned', 'field' => 'owner_user_id',
                'open' => $model->status !== Risk::STATUS_CLOSED,
                'visible' => fn (User $user): bool => $this->moduleHeld($user, 'risk') && $this->riskAccess->findVisible($user, (int) $model->id) !== null,
                'title' => (string) $model->title,
                'url' => route('app.risk.show', ['riskId' => $model->id], false),
                'metadata' => ['risk_id' => (int) $model->id],
            ],
            $model instanceof RiskTreatmentAction => [
                'key' => 'risk_action', 'event' => 'risk.action_assigned', 'field' => 'owner_user_id',
                'open' => $model->status === RiskTreatmentAction::STATUS_OPEN,
                'visible' => fn (User $user): bool => $this->moduleHeld($user, 'risk') && $this->riskAccess->findVisible($user, (int) $model->risk_id) !== null,
                'title' => (string) $model->title,
                'context' => (string) $model->risk?->title,
                'url' => route('app.risk.show', ['riskId' => $model->risk_id], false),
                'metadata' => ['risk_id' => (int) $model->risk_id, 'risk_action_id' => (int) $model->id],
            ],
            $model instanceof ImprovementCase => [
                'key' => 'improvement_case', 'event' => 'improvement.case_assigned', 'field' => 'owner_user_id',
                'open' => $model->isActive(),
                'visible' => fn (User $user): bool => $this->moduleHeld($user, 'improvements') && $this->improvementAccess->findVisible($user, (int) $model->id) !== null,
                'title' => (string) $model->title,
                'url' => route('app.improvements.show', ['caseId' => $model->id], false),
                'metadata' => ['improvement_case_id' => (int) $model->id],
            ],
            $model instanceof ImprovementAction => [
                'key' => 'improvement_action', 'event' => 'improvement.action_assigned', 'field' => 'owner_user_id',
                'open' => $model->isActive() && (bool) $model->improvementCase?->isActive(),
                'visible' => fn (User $user): bool => $this->moduleHeld($user, 'improvements') && $this->improvementAccess->findVisible($user, (int) $model->improvement_case_id) !== null,
                'title' => (string) $model->title,
                'context' => (string) $model->improvementCase?->title,
                'url' => route('app.improvements.show', ['caseId' => $model->improvement_case_id], false).'#improvement-action-'.$model->id,
                'metadata' => ['improvement_case_id' => (int) $model->improvement_case_id, 'improvement_action_id' => (int) $model->id],
            ],
            $model instanceof ComplianceRequirement => [
                'key' => 'compliance_requirement', 'event' => 'compliance.requirement_assigned', 'field' => 'owner_user_id',
                'open' => $model->isActive(),
                'visible' => fn (User $user): bool => $this->complianceAccess->canReadFromAnotherModule($user) && $this->complianceAccess->findVisibleRequirement($user, (int) $model->id) !== null,
                'title' => (string) $model->title,
                'url' => route('app.compliance.requirements.show', ['requirementId' => $model->id], false),
                'metadata' => ['compliance_requirement_id' => (int) $model->id],
            ],
            $model instanceof ComplianceAudit => [
                'key' => 'compliance_audit', 'event' => 'compliance.audit_assigned', 'field' => 'responsible_user_id',
                'open' => in_array($model->status, [ComplianceAudit::STATUS_PLANNED, ComplianceAudit::STATUS_IN_PROGRESS], true),
                'visible' => fn (User $user): bool => $this->complianceAccess->canReadFromAnotherModule($user) && $this->complianceAccess->findVisibleAudit($user, (int) $model->id) !== null,
                'title' => (string) $model->title,
                'url' => route('app.compliance.audits.show', ['auditId' => $model->id], false),
                'metadata' => ['compliance_audit_id' => (int) $model->id],
            ],
            $model instanceof QualityItem => [
                'key' => 'quality_item', 'event' => 'quality.item_assigned', 'field' => 'owner_user_id',
                'open' => $model->status !== QualityItem::STATUS_RETIRED,
                'visible' => fn (User $user): bool => $this->moduleHeld($user, 'quality') && $this->permissions->has($user, CustomerPermissionCatalog::QUALITY_VIEW),
                'title' => (string) $model->title,
                'url' => route('app.quality.items.show', ['item' => $model->id], false),
                'metadata' => ['quality_item_id' => (int) $model->id],
            ],
            $model instanceof Objective => [
                'key' => 'objective', 'event' => 'objective.objective_assigned', 'field' => 'owner_user_id',
                'open' => $model->isActive(),
                'visible' => fn (User $user): bool => $this->moduleHeld($user, 'objectives') && $this->objectiveAccess->findVisible($user, (int) $model->id) !== null,
                'title' => (string) $model->title,
                'url' => route('app.objectives.show', ['objectiveId' => $model->id], false),
                'metadata' => ['objective_id' => (int) $model->id],
            ],
            $model instanceof Kpi => [
                'key' => 'kpi', 'event' => 'objective.kpi_assigned', 'field' => 'owner_user_id',
                'open' => $model->isActive() && (bool) $model->objective?->isActive(),
                'visible' => fn (User $user): bool => $this->moduleHeld($user, 'objectives') && $this->objectiveAccess->findVisible($user, (int) $model->objective_id) !== null,
                'title' => (string) $model->title,
                'context' => (string) $model->objective?->title,
                'url' => route('app.objectives.kpis.show', ['objectiveId' => $model->objective_id, 'kpiId' => $model->id], false),
                'metadata' => ['objective_id' => (int) $model->objective_id, 'kpi_id' => (int) $model->id],
            ],
            $model instanceof ManagementReview => [
                'key' => 'management_review', 'event' => 'management_review.owner_assigned', 'field' => 'owner_user_id',
                'open' => $model->isDraft(),
                'visible' => fn (User $user): bool => $this->managementReviewAccess->findVisible($user, (int) $model->id) !== null,
                'title' => (string) $model->title,
                'url' => route('app.management-review.show', ['reviewId' => $model->id], false),
                'metadata' => ['management_review_id' => (int) $model->id],
            ],
            // A tiltak followed up here. One handed to Avvik og forbedringer is announced by the case.
            $model instanceof ManagementReviewDecision => [
                'key' => 'management_review_action', 'event' => 'management_review.action_assigned', 'field' => 'owner_user_id',
                'open' => $model->isOpen(),
                'visible' => fn (User $user): bool => $this->managementReviewAccess->findVisible($user, (int) $model->management_review_id) !== null,
                'title' => (string) $model->text,
                'context' => (string) $model->review?->title,
                'url' => route('app.management-review.show', ['reviewId' => $model->management_review_id], false).'#decision-'.$model->id,
                'metadata' => ['management_review_id' => (int) $model->management_review_id, 'management_review_decision_id' => (int) $model->id],
            ],
            default => null,
        };
    }

    private function moduleHeld(User $user, string $module): bool
    {
        $customer = $user->customer;

        return $customer !== null && $this->entitlements->hasModule($customer, $module);
    }
}
