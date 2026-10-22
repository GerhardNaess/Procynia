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
 * Kravkilder — the only way one is deleted.
 *
 * Slett is a decision a person takes (phase 0 step 13): HumanDecisionGuard, then compliance.delete in
 * the source's own customer with the module. Only a source no requirement uses; the requirement's
 * foreign key refuses the rest, including one registered under it in the meantime.
 */
class ComplianceSourceService
{
    public function __construct(
        private readonly HumanDecisionGuard $guard,
        private readonly ComplianceAccessService $access,
    ) {}

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
