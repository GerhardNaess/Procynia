<?php

namespace App\Services\EnterpriseWiki\Knowledge\Sources;

use App\Data\EnterpriseWiki\WikiKnowledgeDraft;
use App\Data\EnterpriseWiki\WikiKnowledgeDraftSection;
use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditFinding;
use App\Models\ComplianceAuditRequirement;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\EnterpriseWiki\Knowledge\WikiKnowledgeSource;
use Illuminate\Database\Eloquent\Model;

/**
 * Etterlevelse og revisjon → Wiki. An audit is where an organisation learns what holds and what
 * does not, so it is the record that hands over: its scope, conclusion, findings and the
 * requirements it covered. Gated on compliance.audit — the permission that works on audits.
 */
class ComplianceAuditKnowledgeSource implements WikiKnowledgeSource
{
    public const MODEL = ComplianceAudit::class;

    public function __construct(private readonly ComplianceAccessService $access) {}

    public function sourceType(): string
    {
        return 'compliance_audit';
    }

    public function sourceModule(): string
    {
        return 'compliance';
    }

    public function modelClass(): string
    {
        return self::MODEL;
    }

    public function canOpenModule(User $user): bool
    {
        return $this->access->canOpenModule($user);
    }

    public function findForUser(User $user, int $sourceId): ?Model
    {
        return $this->canOpenModule($user) ? $this->access->findVisibleAudit($user, $sourceId) : null;
    }

    public function canHandOff(User $user, Model $source): bool
    {
        return $source instanceof ComplianceAudit && $this->access->canAudit($user);
    }

    public function draft(User $user, Model $source): WikiKnowledgeDraft
    {
        /** @var ComplianceAudit $source */
        $t = 'procynia.knowledge_handoff.sources.compliance_audit';

        $about = array_values(array_filter([
            __("{$t}.type").': '.__("{$t}.types.{$source->audit_type}"),
            filled($source->scope_description) ? __("{$t}.scope").': '.$source->scope_description : null,
        ]));

        $findings = $source->findings()->orderBy('id')->get()
            ->map(fn (ComplianceAuditFinding $finding): string => __("{$t}.finding_types.{$finding->finding_type}").': '.$finding->title
                .(filled($finding->description) ? ' — '.$finding->description : ''))
            ->all();

        $requirements = $source->requirementLinks()->with('requirement:id,reference,title')->get()
            ->map(fn (ComplianceAuditRequirement $link): ?string => $link->requirement === null ? null
                : trim(($link->requirement->reference ? $link->requirement->reference.' ' : '').$link->requirement->title))
            ->filter()
            ->values()
            ->all();

        return new WikiKnowledgeDraft($source->title, [
            new WikiKnowledgeDraftSection('about', __("{$t}.about_heading"), $about),
            new WikiKnowledgeDraftSection('conclusion', __("{$t}.conclusion_heading"), filled($source->conclusion) ? [(string) $source->conclusion] : [], asList: false),
            new WikiKnowledgeDraftSection('findings', __("{$t}.findings_heading"), $findings),
            new WikiKnowledgeDraftSection('requirements', __("{$t}.requirements_heading"), $requirements),
        ]);
    }

    public function storeUrl(Model $source): string
    {
        return route('app.compliance.audits.knowledge-handoff.store', ['sourceId' => $source->getKey()], false);
    }
}
