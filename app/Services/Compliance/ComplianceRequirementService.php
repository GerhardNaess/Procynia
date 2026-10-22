<?php

namespace App\Services\Compliance;

use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Nytt krav and Rediger — the only way a requirement is registered or its fields change. Status
 * moves only through ComplianceRequirementLifecycleService.
 *
 * Ordinary register work: compliance.edit, in the requirement's own customer with the module,
 * checked here (phase 0 step 13). The source must be one of the customer's own and not a library
 * standard, whose requirements come from Kravbiblioteket; on a library requirement only what the
 * customer owns changes — the person responsible and the review interval. A retired requirement is
 * reopened before it is edited, read again under the row lock. The controller validates the input,
 * checks the reference and asks the same first, for its own status codes and messages.
 */
class ComplianceRequirementService
{
    private const LIBRARY_FIELDS = ['owner_user_id', 'review_interval_months'];

    public function __construct(
        private readonly ComplianceAccessService $access,
    ) {}

    /** @param  array<string, mixed>  $fields  validated by the controller */
    public function create(User $actor, array $fields): ComplianceRequirement
    {
        $customerId = (int) $actor->customer_id;
        $this->access->authorize($actor, CustomerPermissionCatalog::COMPLIANCE_EDIT, $customerId);
        $this->assertSource($fields, $customerId);
        $this->assertOwner($fields, $customerId);

        return ComplianceRequirement::query()->create($fields + [
            'customer_id' => $customerId,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    /** @param  array<string, mixed>  $fields  validated by the controller */
    public function update(User $actor, ComplianceRequirement $requirement, array $fields): ComplianceRequirement
    {
        $customerId = (int) $requirement->customer_id;
        $this->access->authorize($actor, CustomerPermissionCatalog::COMPLIANCE_EDIT, $customerId);
        $this->assertOwner($fields, $customerId);

        return DB::transaction(function () use ($actor, $requirement, $fields, $customerId): ComplianceRequirement {
            $locked = ComplianceRequirement::query()->whereKey($requirement->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['requirement' => __('procynia.compliance.validation.reopen_before_edit')]);
            }

            if ($locked->isFromLibrary()) {
                $fields = array_intersect_key($fields, array_flip(self::LIBRARY_FIELDS));
            } else {
                $this->assertSource($fields, $customerId);
            }

            $locked->fill($fields + ['updated_by' => $actor->id])->save();

            return $locked;
        });
    }

    /** @param  array<string, mixed>  $fields */
    private function assertSource(array $fields, int $customerId): void
    {
        $source = ComplianceSource::query()->where('customer_id', $customerId)->find((int) ($fields['source_id'] ?? 0));

        if ($source === null) {
            throw ValidationException::withMessages(['source_id' => __('procynia.compliance.validation.source_not_allowed')]);
        }

        if ($source->isFromLibrary()) {
            throw ValidationException::withMessages(['source_id' => __('procynia.compliance.library.validation.source_is_library')]);
        }
    }

    /** Ansvarlig is optional; chosen, it is someone of the customer who can open the module. @param  array<string, mixed>  $fields */
    private function assertOwner(array $fields, int $customerId): void
    {
        $ownerId = $fields['owner_user_id'] ?? null;

        if ($ownerId === null) {
            return;
        }

        $owner = User::query()->where('customer_id', $customerId)->find((int) $ownerId);

        if (! $this->access->isValidOwner($owner, $customerId)) {
            throw ValidationException::withMessages(['owner_user_id' => __('procynia.compliance.validation.owner_not_allowed')]);
        }
    }
}
