<?php

namespace App\Services\Compliance;

use App\Models\ComplianceAudit;
use App\Models\User;
use App\Services\Security\HumanDecisionGuard;
use App\Support\CustomerPermissionCatalog;
use App\Support\HumanDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ny revisjon and Rediger — the only way an audit is registered or its fields change. Status moves
 * only through ComplianceAuditLifecycleService.
 *
 * Planning and running audits is a person's work (HumanDecision::COMPLIANCE_AUDIT, phase 0 step 13):
 * the guard, then compliance.audit in the audit's own customer with the module. The responsible
 * person must be someone of that customer who can open the module. Rediger writes only the fields
 * the audit's status leaves open (ComplianceAudit::editableFields()), read again under the row
 * lock, so a lifecycle step at the same moment cannot be slipped past. The controller validates the
 * input and asks the same first, for its own status codes and messages.
 */
class ComplianceAuditService
{
    public function __construct(
        private readonly HumanDecisionGuard $guard,
        private readonly ComplianceAccessService $access,
        private readonly ComplianceAuditScopeService $scope,
    ) {}

    /**
     * The audit and the scope it was planned with, or neither.
     *
     * @param  array<string, mixed>  $fields  validated by the controller
     * @param  list<int>  $requirementIds
     * @param  list<int>  $sourceIds
     */
    public function create(User $actor, array $fields, array $requirementIds = [], array $sourceIds = []): ComplianceAudit
    {
        $actor = $this->guard->assert($actor, HumanDecision::COMPLIANCE_AUDIT);
        $customerId = (int) $actor->customer_id;
        $this->access->authorize($actor, CustomerPermissionCatalog::COMPLIANCE_AUDIT, $customerId);
        $this->assertResponsible($fields, $customerId);

        return DB::transaction(function () use ($actor, $customerId, $fields, $requirementIds, $sourceIds): ComplianceAudit {
            $audit = ComplianceAudit::query()->create(array_intersect_key($fields, array_flip((new ComplianceAudit)->editableFields())) + [
                'customer_id' => $customerId,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->scope->addInitialRequirements($actor, $audit, $requirementIds, $sourceIds);

            return $audit;
        });
    }

    /** @param  array<string, mixed>  $fields  validated by the controller */
    public function update(User $actor, ComplianceAudit $audit, array $fields): ComplianceAudit
    {
        $actor = $this->guard->assert($actor, HumanDecision::COMPLIANCE_AUDIT);
        $this->access->authorize($actor, CustomerPermissionCatalog::COMPLIANCE_AUDIT, (int) $audit->customer_id);
        $this->assertResponsible($fields, (int) $audit->customer_id);

        return DB::transaction(function () use ($actor, $audit, $fields): ComplianceAudit {
            $locked = ComplianceAudit::query()->whereKey($audit->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isEditable()) {
                throw ValidationException::withMessages(['audit' => __($locked->status === ComplianceAudit::STATUS_COMPLETED
                    ? 'procynia.compliance.audits.validation.reopen_before_edit'
                    : 'procynia.compliance.audits.validation.cancelled_read_only')]);
            }

            $locked->fill(array_intersect_key($fields, array_flip($locked->editableFields())) + ['updated_by' => $actor->id])->save();

            return $locked;
        });
    }

    /** @param  array<string, mixed>  $fields */
    private function assertResponsible(array $fields, int $customerId): void
    {
        if (! array_key_exists('responsible_user_id', $fields)) {
            return;
        }

        $responsible = User::query()->where('customer_id', $customerId)->find((int) $fields['responsible_user_id']);

        if (! $this->access->isValidOwner($responsible, $customerId)) {
            throw ValidationException::withMessages(['responsible_user_id' => __('procynia.compliance.audits.validation.responsible_not_allowed')]);
        }
    }
}
