<?php

namespace App\Support\Ai;

use App\Data\Ai\AiCallContext;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestSection;
use App\Models\KnowledgeItem;
use App\Models\RequirementExtractionCall;
use App\Models\RequirementExtractionRun;
use App\Models\SavedNotice;
use Closure;

/**
 * Establishes the owning customer for every provider call a queue job makes.
 *
 * A job runs long after it was dispatched, on a worker with no authenticated user. Without an
 * explicit context the provider boundary sees no customer, so the customer kill switch and the
 * commercial quota cannot be applied and the attempt is only ever recorded as `unclassified`.
 * Resolving the owner once, at the job boundary, makes every nested provider call inherit it —
 * including AI clients that never received a context of their own.
 */
trait RunsInAiCallContext
{
    protected function withinAiCallContext(AiCallContext $context, Closure $callback): mixed
    {
        return app(AiCallContextScope::class)->within($context, $callback);
    }

    /**
     * A cost-control block is permanent for the work this job was dispatched to do: the customer is
     * out of quota, suspended, or the platform is stopped. Re-running the job only produces more
     * blocked attempts, so a job with a retry budget must fail immediately instead of consuming it.
     */
    protected function failWithoutRetryOnCostControlBlock(Closure $callback): void
    {
        try {
            $callback();
        } catch (AiCostControlException $exception) {
            $this->fail($exception);
        }
    }

    protected function enterpriseWikiRunAiCallContext(?int $runId, string $operation): AiCallContext
    {
        $run = $runId === null ? null : EnterpriseWikiIngestRun::query()->find($runId);

        return new AiCallContext(
            runId: $run?->id,
            documentId: $run?->source_id,
            customerId: $run?->customer_id,
            feature: 'enterprise_wiki',
            operation: $operation,
            resourceType: 'enterprise_wiki_document',
            resourceId: $run?->source_id,
            jobId: $run?->uuid,
        );
    }

    protected function enterpriseWikiSectionAiCallContext(int $sectionId, string $operation): AiCallContext
    {
        $runId = EnterpriseWikiIngestSection::query()
            ->whereKey($sectionId)
            ->value('enterprise_wiki_ingest_run_id');

        return $this->enterpriseWikiRunAiCallContext(
            is_numeric($runId) ? (int) $runId : null,
            $operation,
        );
    }

    protected function enterpriseWikiDocumentAiCallContext(int $documentId, string $operation): AiCallContext
    {
        $customerId = EnterpriseWikiDocument::query()->whereKey($documentId)->value('customer_id');

        return new AiCallContext(
            documentId: $documentId,
            customerId: is_numeric($customerId) ? (int) $customerId : null,
            feature: 'enterprise_wiki',
            operation: $operation,
            resourceType: 'enterprise_wiki_document',
            resourceId: $documentId,
        );
    }

    /**
     * Requirement extraction is chargeable case work: any provider call it makes activates the
     * SavedNotice for the period, exactly like an answer draft does.
     */
    protected function requirementExtractionRunAiCallContext(?int $runId, string $operation): AiCallContext
    {
        $savedNoticeId = $runId === null
            ? null
            : RequirementExtractionRun::query()->whereKey($runId)->value('saved_notice_id');

        return $this->savedNoticeAiCallContext(
            is_numeric($savedNoticeId) ? (int) $savedNoticeId : null,
            $operation,
            $runId,
        );
    }

    protected function requirementExtractionCallAiCallContext(int $callId, string $operation, ?int $runId = null): AiCallContext
    {
        $savedNoticeId = RequirementExtractionCall::query()->whereKey($callId)->value('saved_notice_id');

        return $this->savedNoticeAiCallContext(
            is_numeric($savedNoticeId) ? (int) $savedNoticeId : null,
            $operation,
            $runId,
        );
    }

    protected function knowledgeItemAiCallContext(int $knowledgeItemId, string $operation): AiCallContext
    {
        $customerId = KnowledgeItem::query()->whereKey($knowledgeItemId)->value('customer_id');

        return new AiCallContext(
            customerId: is_numeric($customerId) ? (int) $customerId : null,
            feature: 'knowledge',
            operation: $operation,
            resourceType: 'knowledge_item',
            resourceId: $knowledgeItemId,
        );
    }

    private function savedNoticeAiCallContext(?int $savedNoticeId, string $operation, ?int $runId): AiCallContext
    {
        $customerId = $savedNoticeId === null
            ? null
            : SavedNotice::query()->whereKey($savedNoticeId)->value('customer_id');

        return new AiCallContext(
            customerId: is_numeric($customerId) ? (int) $customerId : null,
            feature: 'saved_notice',
            operation: $operation,
            resourceType: 'requirement_extraction_run',
            resourceId: $runId,
            savedNoticeId: $savedNoticeId,
            commercialCredit: $savedNoticeId !== null,
        );
    }
}
