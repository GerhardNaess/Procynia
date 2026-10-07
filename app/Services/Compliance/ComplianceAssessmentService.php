<?php

namespace App\Services\Compliance;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceRequirement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vurder etterlevelse — the only way a ComplianceAssessment is written.
 *
 * The requirement row is locked and checked again inside the lock: only an active requirement is
 * assessed, so one retired in the meantime is refused. assessed_at is now(), never the caller's;
 * the requirement and its source are copied into the assessment as they read at that moment.
 *
 * Authorization is the caller's (compliance.assess through ComplianceAccessService). This only
 * guards the assessment itself.
 */
class ComplianceAssessmentService
{
    public function assess(ComplianceRequirement $requirement, User $actor, string $result, ?string $rationale): ComplianceAssessment
    {
        $rationale = trim((string) $rationale);

        if (! in_array($result, ComplianceAssessment::RESULTS, true)) {
            throw ValidationException::withMessages(['result' => __('procynia.compliance.validation.rules.choose', ['attribute' => __('procynia.compliance.validation.attributes.result')])]);
        }

        if ($rationale === '') {
            throw ValidationException::withMessages(['rationale' => __('procynia.compliance.validation.rationale_required')]);
        }

        return DB::transaction(function () use ($requirement, $actor, $result, $rationale): ComplianceAssessment {
            $locked = ComplianceRequirement::query()->whereKey($requirement->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['result' => __('procynia.compliance.validation.assess_retired')]);
            }

            $source = $locked->source()->firstOrFail(['id', 'name', 'version']);

            return ComplianceAssessment::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'requirement_id' => (int) $locked->id,
                'result' => $result,
                'rationale' => $rationale,
                'assessed_by_user_id' => (int) $actor->id,
                'assessed_at' => now(),
                'requirement_reference' => $locked->reference,
                'requirement_title' => $locked->title,
                'requirement_text' => $locked->requirement_text,
                'source_name' => $source->name,
                'source_version' => $source->version,
            ]);
        });
    }
}
