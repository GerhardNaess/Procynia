<?php

namespace App\Services\Compliance;

use App\Models\ComplianceSource;
use App\Models\User;
use App\Services\Security\HumanDecisionGuard;
use App\Support\CustomerPermissionCatalog;
use App\Support\HumanDecision;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kravkilder — the only way one is registered, changed or deleted.
 *
 * Registering and changing is ordinary register work: compliance.edit in the source's own customer
 * with the module, checked here (phase 0 step 13). An activated library standard's name and version
 * follow Kravbiblioteket and are not edited. Slett is a decision a person takes: HumanDecisionGuard,
 * then compliance.delete. Only a source no requirement uses; the requirement's foreign key refuses
 * the rest, including one registered under it in the meantime.
 */
class ComplianceSourceService
{
    public function __construct(
        private readonly HumanDecisionGuard $guard,
        private readonly ComplianceAccessService $access,
    ) {}

    /** @param  array<string, mixed>  $fields  validated by the controller */
    public function create(User $actor, array $fields): ComplianceSource
    {
        $customerId = (int) $actor->customer_id;
        $this->access->authorize($actor, CustomerPermissionCatalog::COMPLIANCE_EDIT, $customerId);

        return ComplianceSource::query()->create($fields + [
            'customer_id' => $customerId,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    /** @param  array<string, mixed>  $fields  validated by the controller */
    public function update(ComplianceSource $source, User $actor, array $fields): ComplianceSource
    {
        $this->access->authorize($actor, CustomerPermissionCatalog::COMPLIANCE_EDIT, (int) $source->customer_id);

        if ($source->isFromLibrary()) {
            throw ValidationException::withMessages(['source' => __('procynia.compliance.library.validation.source_is_library_edit')]);
        }

        $source->fill($fields + ['updated_by' => $actor->id])->save();

        return $source;
    }

    public function delete(ComplianceSource $source, User $actor): void
    {
        $actor = $this->guard->assert($actor, HumanDecision::COMPLIANCE_DELETE);
        $this->access->authorize($actor, CustomerPermissionCatalog::COMPLIANCE_DELETE, (int) $source->customer_id);

        try {
            DB::transaction(function () use ($source): void {
                $locked = ComplianceSource::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();

                if ($locked->isInUse()) {
                    throw ValidationException::withMessages(['source' => __('procynia.compliance.sources.in_use')]);
                }

                $locked->delete();
            });
        } catch (QueryException) {
            throw ValidationException::withMessages(['source' => __('procynia.compliance.sources.in_use')]);
        }
    }
}
