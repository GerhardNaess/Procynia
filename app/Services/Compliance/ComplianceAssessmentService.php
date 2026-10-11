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
 * the requirement and its source are copied into the assessment as they read at that moment. A
 * library requirement is assessed only while it applies, and never as Ikke relevant.
 *
 * Authorization is the caller's (compliance.assess through ComplianceAccessService). This only
 * guards the assessment itself.
 */
class ComplianceAssessmentService
{
    /**
     * $documentation is optional: what the assessment points to — a Wiki article, a document
     * reference, a link — one per line. Blank is stored as null.
     */
    public function assess(ComplianceRequirement $requirement, User $actor, string $result, ?string $rationale, ?string $documentation = null): ComplianceAssessment
    {
        $rationale = trim((string) $rationale);
        $documentation = trim((string) $documentation);

        if (! in_array($result, ComplianceAssessment::RESULTS, true)) {
            throw ValidationException::withMessages(['result' => __('procynia.compliance.validation.rules.choose', ['attribute' => __('procynia.compliance.validation.attributes.result')])]);
        }

        if ($rationale === '') {
            throw ValidationException::withMessages(['rationale' => __('procynia.compliance.validation.rationale_required')]);
        }

        return DB::transaction(function () use ($requirement, $actor, $result, $rationale, $documentation): ComplianceAssessment {
            $locked = ComplianceRequirement::query()->whereKey($requirement->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['result' => __('procynia.compliance.validation.assess_retired')]);
            }

            // A library requirement keeps relevance and etterlevelse apart: Ikke relevant is a
            // relevance judgement (ComplianceApplicabilityService), and only a requirement that
            // applies is assessed.
            if ($locked->isFromLibrary()) {
                if ($result === ComplianceAssessment::RESULT_NOT_APPLICABLE) {
                    throw ValidationException::withMessages(['result' => __('procynia.compliance.library.validation.not_applicable_is_relevance')]);
                }

                if ($locked->applicability !== ComplianceRequirement::APPLICABILITY_APPLIES) {
                    throw ValidationException::withMessages(['result' => __('procynia.compliance.library.validation.assess_requires_applies')]);
                }
            }

            $source = $locked->source()->firstOrFail(['id', 'name', 'version']);

            return ComplianceAssessment::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'requirement_id' => (int) $locked->id,
                'result' => $result,
                'rationale' => $rationale,
                'documentation' => $documentation !== '' ? $documentation : null,
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
