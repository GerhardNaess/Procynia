<?php

namespace App\Services\Compliance;

use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditStatusChange;
use App\Models\User;
use App\Services\Security\HumanDecisionGuard;
use App\Support\CustomerPermissionCatalog;
use App\Support\HumanDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Start, Fullfør, Avbryt and Gjenåpne — the only way an audit's status changes.
 *
 *   planned     → in_progress   start()      begrunnelse optional
 *   in_progress → completed     complete()   konklusjon required; begrunnelse optional
 *   planned     → cancelled     cancel()     begrunnelse required
 *   in_progress → cancelled     cancel()     begrunnelse required
 *   completed   → in_progress   reopen()     begrunnelse required
 *
 * Nothing else: no jump from planned to completed, nothing out of cancelled. Each writes the audit's
 * status and one immutable ComplianceAuditStatusChange in one transaction, with the audit row locked
 * and its status checked again inside the lock, so a double submit or two people at once cannot
 * write the same change twice.
 *
 * Completing never requires findings and never creates anything: an audit can legitimately end with
 * none. The conclusion it requires is written in the same transaction.
 *
 * Each step, and Slett, is a decision a person takes (phase 0 step 13): HumanDecisionGuard first,
 * then the key — compliance.audit, or compliance.delete — in the audit's own customer with the
 * module, before the row is locked. The controller asks the same first, for its own status codes.
 */
class ComplianceAuditLifecycleService
{
    public function __construct(
        private readonly HumanDecisionGuard $guard,
        private readonly ComplianceAccessService $access,
    ) {}

    public function start(ComplianceAudit $audit, User $actor, ?string $reason = null): ComplianceAudit
    {
        return $this->transition($audit, $actor, [ComplianceAudit::STATUS_PLANNED], ComplianceAudit::STATUS_IN_PROGRESS, $this->optional($reason));
    }

    public function complete(ComplianceAudit $audit, User $actor, ?string $conclusion, ?string $reason = null): ComplianceAudit
    {
        $actor = $this->authorizeAudit($actor, $audit);
        $text = trim((string) $conclusion);

        if ($text === '') {
            throw ValidationException::withMessages([
                'conclusion' => __('procynia.compliance.audits.validation.conclusion_required'),
            ]);
        }

        return $this->transition($audit, $actor, [ComplianceAudit::STATUS_IN_PROGRESS], ComplianceAudit::STATUS_COMPLETED, $this->optional($reason), ['conclusion' => $text]);
    }

    public function cancel(ComplianceAudit $audit, User $actor, ?string $reason): ComplianceAudit
    {
        return $this->transition($audit, $actor, [ComplianceAudit::STATUS_PLANNED, ComplianceAudit::STATUS_IN_PROGRESS], ComplianceAudit::STATUS_CANCELLED, $this->required($reason));
    }

    public function reopen(ComplianceAudit $audit, User $actor, ?string $reason): ComplianceAudit
    {
        return $this->transition($audit, $actor, [ComplianceAudit::STATUS_COMPLETED], ComplianceAudit::STATUS_IN_PROGRESS, $this->required($reason));
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $fields  written to the audit with the status
     */
    private function transition(ComplianceAudit $audit, User $actor, array $from, string $to, ?string $reason, array $fields = []): ComplianceAudit
    {
        $actor = $this->authorizeAudit($actor, $audit);

        return DB::transaction(function () use ($audit, $actor, $from, $to, $reason, $fields): ComplianceAudit {
            $locked = ComplianceAudit::query()->whereKey($audit->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages([
                    'status' => __('procynia.compliance.audits.validation.transition_not_allowed'),
                ]);
            }

            ComplianceAuditStatusChange::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'audit_id' => (int) $locked->id,
                'from_status' => $locked->status,
                'to_status' => $to,
                'reason' => $reason,
                'changed_by_user_id' => (int) $actor->id,
                'changed_at' => now(),
            ]);

            $locked->forceFill($fields + [
                'status' => $to,
                'updated_by' => (int) $actor->id,
            ])->save();

            return $locked;
        });
    }

    /**
     * Slett: an audit registered by mistake — still planned and never moved (isDeletable(), read again
     * under the lock). Anything else is cancelled instead; the history trigger refuses the delete too.
     */
    public function delete(ComplianceAudit $audit, User $actor): void
    {
        $actor = $this->guard->assert($actor, HumanDecision::COMPLIANCE_DELETE);
        $this->access->authorize($actor, CustomerPermissionCatalog::COMPLIANCE_DELETE, (int) $audit->customer_id);

        DB::transaction(function () use ($audit): void {
            $locked = ComplianceAudit::query()->whereKey($audit->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDeletable()) {
                throw ValidationException::withMessages([
                    'audit' => __('procynia.compliance.audits.validation.not_deletable'),
                ]);
            }

            $locked->delete();
        });
    }

    private function authorizeAudit(User $actor, ComplianceAudit $audit): User
    {
        $actor = $this->guard->assert($actor, HumanDecision::COMPLIANCE_AUDIT);
        $this->access->authorize($actor, CustomerPermissionCatalog::COMPLIANCE_AUDIT, (int) $audit->customer_id);

        return $actor;
    }

    private function required(?string $reason): string
    {
        $note = trim((string) $reason);

        if ($note === '') {
            throw ValidationException::withMessages([
                'reason' => __('procynia.compliance.validation.reason_required'),
            ]);
        }

        return $note;
    }

    private function optional(?string $reason): ?string
    {
        $note = trim((string) $reason);

        return $note !== '' ? $note : null;
    }
}
