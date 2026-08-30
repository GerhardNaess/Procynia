<?php

namespace App\Data\Ai;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
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
        public ?int $customerId = null,
        public ?int $userId = null,
        public ?string $feature = null,
        public ?string $operation = null,
        public ?string $resourceType = null,
        public ?int $resourceId = null,
        public ?string $jobId = null,
        public ?string $requestCorrelationId = null,
        public ?string $provider = null,
        public ?int $savedNoticeId = null,
        public bool $commercialCredit = false,
        // Set only by an internal operator running a recovery command with an explicit flag. It
        // relaxes the commercial guards (entitlement, quota, customer suspension) and never the
        // global emergency stop — see AiCostControlService::authorize().
        public bool $operatorOverride = false,
        public ?int $operatorActorUserId = null,
        public ?string $operatorOverrideReason = null,
        // Set by the provider boundary just before authorising: the guard cannot price a call it
        // does not know the model of, and an unpriceable model is a hard stop in its own right.
        public ?string $model = null,
        public ?string $endpoint = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /** Build the safe ambient context for an authenticated HTTP request, when one exists. */
    public static function fromAuthenticatedUser(): self
    {
        $user = Auth::user();

        return $user instanceof User
            ? new self(customerId: $user->customer_id, userId: $user->id)
            : new self;
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
            customerId: $this->customerId,
            userId: $this->userId,
            feature: $this->feature,
            operation: $this->operation,
            resourceType: $this->resourceType,
            resourceId: $this->resourceId,
            jobId: $this->jobId,
            requestCorrelationId: $this->requestCorrelationId,
            provider: $this->provider,
            savedNoticeId: $this->savedNoticeId,
            commercialCredit: $this->commercialCredit,
            operatorOverride: $this->operatorOverride,
            operatorActorUserId: $this->operatorActorUserId,
            operatorOverrideReason: $this->operatorOverrideReason,
            model: $this->model,
            endpoint: $this->endpoint,
        );
    }

    /** Returns a copy that knows which model and endpoint the imminent call will use. */
    public function forProviderCall(string $model, string $endpoint): self
    {
        return new self(
            runId: $this->runId,
            documentId: $this->documentId,
            remainingJobBudgetSeconds: $this->remainingJobBudgetSeconds,
            customerId: $this->customerId,
            userId: $this->userId,
            feature: $this->feature,
            operation: $this->operation,
            resourceType: $this->resourceType,
            resourceId: $this->resourceId,
            jobId: $this->jobId,
            requestCorrelationId: $this->requestCorrelationId,
            provider: $this->provider,
            savedNoticeId: $this->savedNoticeId,
            commercialCredit: $this->commercialCredit,
            operatorOverride: $this->operatorOverride,
            operatorActorUserId: $this->operatorActorUserId,
            operatorOverrideReason: $this->operatorOverrideReason,
            model: $model,
            endpoint: $endpoint,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'document_id' => $this->documentId,
            'remaining_job_budget_seconds' => $this->remainingJobBudgetSeconds,
            'customer_id' => $this->customerId,
            'user_id' => $this->userId,
            'feature' => $this->feature,
            'operation' => $this->operation,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'job_id' => $this->jobId,
            'request_correlation_id' => $this->requestCorrelationId,
            'saved_notice_id' => $this->savedNoticeId,
            'commercial_credit' => $this->commercialCredit,
            'operator_override' => $this->operatorOverride,
            'operator_actor_user_id' => $this->operatorActorUserId,
            'model' => $this->model,
            'endpoint' => $this->endpoint,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
