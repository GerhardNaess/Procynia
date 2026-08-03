<?php

namespace App\Data\Ai;

use JsonSerializable;

/**
 * Optional observability/budget context for one Enterprise Wiki maintainer-decision AI call chain
 * — bundles run_id/document_id (for structured logging) and the owning queue job's remaining
 * timeout budget (for EnterpriseWikiAiRequestTimeoutPolicy) into one value, instead of adding three
 * separate trailing parameters to every method along the call chain
 * (EnterpriseWikiMaintainerDecisionService::runForDocument() ->
 * EnterpriseWikiMaintainerDecisionAiClient::decide()/repair() ->
 * EnterpriseWikiMaintainerDecisionSplitCoordinator::decide() ->
 * EnterpriseWikiAiCapacityRetryExecutor::execute()).
 *
 * Always optional (every call site defaults to none()) — a caller with no owning run (e.g. the
 * `wiki:maintainer-decision` dry-run command) or no known job budget simply logs less context and
 * lets the timeout policy fall back to its configured range without a job-budget clamp.
 */
final readonly class AiCallContext implements JsonSerializable
{
    public function __construct(
        public ?int $runId = null,
        public ?int $documentId = null,
        public ?int $remainingJobBudgetSeconds = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * Returns a copy with the remaining job budget reduced by $elapsedSeconds — used between
     * successive AI call attempts (capacity retry, network retry) so each one sees a truthful,
     * shrinking budget rather than the same static figure computed once at the top of the job.
     * A null budget (unknown) stays null.
     */
    public function withElapsedSeconds(float $elapsedSeconds): self
    {
        return new self(
            runId: $this->runId,
            documentId: $this->documentId,
            remainingJobBudgetSeconds: $this->remainingJobBudgetSeconds !== null
                ? max(0, (int) floor($this->remainingJobBudgetSeconds - $elapsedSeconds))
                : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'document_id' => $this->documentId,
            'remaining_job_budget_seconds' => $this->remainingJobBudgetSeconds,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
