<?php

namespace App\Data\Ai;

use App\Models\User;
use App\Support\Ai\AiOperationCatalog;
use Illuminate\Support\Facades\Auth;
use JsonSerializable;

/**
 * Who an AI provider call is for and what it is doing: customer, user, feature, operation and the
 * resource it serves, plus the cost-control flags (commercial credit, operator override) and the
 * owning job's remaining timeout budget.
 *
 * Contexts nest through AiCallContextScope. An entry point (controller, queue job, operator
 * command) establishes the owner; an AI client narrows it to the operation it performs. A nested
 * context inherits whatever it leaves open (inheritFrom()), so passing none() lower down never
 * erases the owner established above it.
 *
 * The provider boundary (AiCallContextPolicy) requires a well-formed `<feature>.<operation>` on
 * every call and a customer on every call that is not explicitly marked as system work.
 */
final readonly class AiCallContext implements JsonSerializable
{
    public const ATTRIBUTION_CUSTOMER = 'customer';

    public const ATTRIBUTION_SYSTEM = 'system';

    public const ATTRIBUTION_UNATTRIBUTED = 'unattributed';

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
        // Explicit system work: a call with no customer that is meant to have none. Never inferred
        // from a missing customer — see AiCallContextPolicy.
        public bool $systemWork = false,
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
        return $this->with([
            'remainingJobBudgetSeconds' => $this->remainingJobBudgetSeconds !== null
                ? max(0, (int) floor($this->remainingJobBudgetSeconds - $elapsedSeconds))
                : null,
        ]);
    }

    /** Returns a copy that knows which model and endpoint the imminent call will use. */
    public function forProviderCall(string $model, string $endpoint): self
    {
        return $this->with(['model' => $model, 'endpoint' => $endpoint]);
    }

    /**
     * Explicit work Procynia does for itself, with no customer behind it. The only kind of call
     * the provider boundary accepts without a customer, and it has to be said out loud.
     */
    public static function system(string $operation): self
    {
        return new self(
            feature: AiOperationCatalog::FEATURE_SYSTEM,
            operation: $operation,
            systemWork: true,
        );
    }

    /**
     * Returns a copy naming the AI operation actually performed. The feature always follows the
     * operation's first segment, so the two can never disagree on a usage row.
     */
    public function withOperation(string $operation): self
    {
        $feature = AiOperationCatalog::featureFor($operation) ?? $this->feature;

        return $this->with([
            'operation' => $operation,
            'feature' => $feature,
            // The `system` feature is reserved for system work, so naming it is the explicit marking.
            'systemWork' => $this->systemWork || $feature === AiOperationCatalog::FEATURE_SYSTEM,
        ]);
    }

    /**
     * Fill what this (inner) context leaves open from the context it runs inside.
     *
     * A nested scope narrows the outer one; it never erases it. Before this, a client that pushed
     * AiCallContext::none() — or a context naming only its operation — hid the job's customer, so
     * the call reached the provider as unattributed system work: no customer kill switch, no
     * quota, and an `unclassified` usage row. Flags that only ever add a guarantee
     * (commercial credit, operator override) are kept when either side sets them.
     */
    public function inheritFrom(self $outer): self
    {
        $values = get_object_vars($this);

        foreach (get_object_vars($outer) as $name => $outerValue) {
            if (is_bool($outerValue)) {
                $values[$name] = $values[$name] || $outerValue;

                continue;
            }

            if ($values[$name] === null) {
                $values[$name] = $outerValue;
            }
        }

        return new self(...$values);
    }

    /** customer | system | unattributed — what an `ai_usage_attempts` row can promise about its owner. */
    public function attribution(): string
    {
        return match (true) {
            ($this->customerId ?? 0) > 0 => self::ATTRIBUTION_CUSTOMER,
            $this->systemWork => self::ATTRIBUTION_SYSTEM,
            default => self::ATTRIBUTION_UNATTRIBUTED,
        };
    }

    /** @param array<string, mixed> $overrides */
    private function with(array $overrides): self
    {
        return new self(...array_merge(get_object_vars($this), $overrides));
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
            'system_work' => $this->systemWork,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
