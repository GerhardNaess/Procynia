<?php

namespace App\Services\Compliance;

use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditFinding;
use App\Models\ImprovementCase;
use App\Models\User;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Services\Improvements\ImprovementCaseCreator;
use App\Services\Improvements\ImprovementCaseQualityContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Følg opp i Avvik og forbedringer»: a revisjonsfunn handed off, explicitly, to one new
 * ImprovementCase — and what each side may learn about the other afterwards.
 *
 * Revisjoner never grows a follow-up system of its own. The case is registered through
 * ImprovementCaseCreator, exactly like one registered in Avvik og forbedringer, and from then on it
 * is that module's: its status, tiltak and verification are never shown on the audit.
 *
 * WHO. compliance.audit (403 otherwise) and improvement.edit in the chosen fagområde, with an owner
 * who can read cases there — both checked by the creator as for any new case. Nothing is guessed:
 * the person chooses the area, the owner and the frist. The type follows the finding — avvik becomes
 * an avvik, observasjon and forbedringsmulighet a forbedring — and is not a choice in v1. Title and
 * description start as the finding's and may be edited; the finding keeps its own.
 *
 * WHEN. While the audit is in progress and after it is completed; never from a cancelled audit.
 *
 * ATOMIC. One transaction: the audit row is share-locked against a lifecycle step, the finding row
 * locked, its improvement_case_id checked to still be empty, the case created, and the finding
 * marked. A double submit waits for the first and is then refused; the unique index on
 * improvement_case_id is the last line.
 *
 * KVALITET. When the finding names a process and the person can read Kvalitet, the form proposes it
 * as the case's context; they may leave it out. It is written the same way the case page writes it.
 *
 * PROVENANCE is the finding → case relation and nothing else. The case page shows where it came
 * from (provenanceFor()) only to someone who can read compliance — never the audit or the finding's
 * title otherwise.
 */
class ComplianceAuditFindingHandoffService
{
    public function __construct(
        private readonly ComplianceAccessService $access,
        private readonly ImprovementCaseAccessService $improvements,
        private readonly ImprovementCaseCreator $creator,
        private readonly ImprovementCaseQualityContextService $qualityContext,
    ) {}

    /** compliance.audit on an audit in progress or completed. Area permission is checked on submit. */
    public function canHandOff(User $user, ComplianceAudit $audit): bool
    {
        return $audit->canHandOffFindings() && $this->access->canAudit($user);
    }

    /**
     * What the hand-off form needs from Avvik og forbedringer: the areas the person may register
     * cases in, and who could be responsible in each. Empty when they may register in none.
     *
     * @return array{area_options: list<array{id: int, name: string}>, owner_options: list<array{id: int, name: string, area_ids: list<int>}>, can_link_process: bool}
     */
    public function formOptions(User $user): array
    {
        $areas = $this->improvements->canOpenModule($user) ? $this->improvements->editableAreas($user) : collect();
        $areaIds = $areas->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        return [
            'area_options' => $areas->map(fn ($area): array => ['id' => (int) $area->id, 'name' => $area->name])->values()->all(),
            'owner_options' => $this->improvements->ownerCandidates($user, $areaIds),
            'can_link_process' => $this->qualityContext->canReadQuality($user),
        ];
    }

    /**
     * Hand the finding off. $validated holds title, description, business_area_id, owner_user_id,
     * due_date and link_process, checked against ImprovementCaseCreator::rules() by the caller.
     *
     * @param  array<string, mixed>  $validated
     */
    public function handOff(User $user, ComplianceAudit $audit, ComplianceAuditFinding $finding, array $validated): ImprovementCase
    {
        abort_unless($this->access->canAudit($user), 403);

        return DB::transaction(function () use ($user, $audit, $finding, $validated): ImprovementCase {
            $lockedAudit = ComplianceAudit::query()->whereKey($audit->id)->sharedLock()->firstOrFail();

            if (! $lockedAudit->canHandOffFindings()) {
                throw ValidationException::withMessages(['finding' => __('procynia.compliance.audits.findings.validation.handoff_not_allowed')]);
            }

            $locked = ComplianceAuditFinding::query()->whereKey($finding->id)->lockForUpdate()->firstOrFail();

            if ($locked->isHandedOff()) {
                throw ValidationException::withMessages(['finding' => __('procynia.compliance.audits.findings.validation.already_handed_off')]);
            }

            $case = $this->creator->create($user, [
                'type' => $locked->improvementCaseType(),
                'title' => $validated['title'],
                'description' => $validated['description'],
                'business_area_id' => $validated['business_area_id'],
                'owner_user_id' => $validated['owner_user_id'],
                'occurred_at' => null,
                'due_date' => $validated['due_date'] ?? null,
            ]);

            if (($validated['link_process'] ?? false) && $locked->quality_process_id !== null && $this->qualityContext->canReadQuality($user)) {
                $this->qualityContext->syncProcess($user, $case, (int) $locked->quality_process_id, true, []);
            }

            $locked->forceFill([
                'improvement_case_id' => $case->id,
                'handed_off_at' => now(),
                'handed_off_by_user_id' => $user->id,
            ])->save();

            return $case;
        });
    }

    /**
     * «Fra revisjonsfunn i …» on the case page — only for someone who can read the audit in
     * Etterlevelse og revisjon. Null for everyone else, and for a case no finding was handed off to.
     *
     * @return array{audit_title: string, audit_url: string, finding_title: string}|null
     */
    public function provenanceFor(User $user, ImprovementCase $case): ?array
    {
        if (! $this->access->canOpenModule($user)) {
            return null;
        }

        $finding = ComplianceAuditFinding::query()
            ->where('customer_id', $case->customer_id)
            ->where('improvement_case_id', $case->id)
            ->first();

        $audit = $finding !== null ? $this->access->findVisibleAudit($user, (int) $finding->audit_id) : null;

        if ($audit === null) {
            return null;
        }

        return [
            'audit_title' => $audit->title,
            'audit_url' => route('app.compliance.audits.show', ['auditId' => $audit->id]).'#finding-'.$finding->id,
            'finding_title' => $finding->title,
        ];
    }
}
