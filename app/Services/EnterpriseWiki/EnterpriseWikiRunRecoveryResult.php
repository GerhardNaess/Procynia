<?php

namespace App\Services\EnterpriseWiki;

use JsonSerializable;

/**
 * EnterpriseWikiEscalatedRunRecoveryService's decision for one recovery attempt/preview.
 *
 * `outcome` is always one of the six constants below — never a freeform string — so every caller
 * (the wiki:recover-document-flow command, EnterpriseWikiMaintenanceCycleService, and tests) can
 * branch on it exhaustively instead of string-matching `reason`.
 */
final readonly class EnterpriseWikiRunRecoveryResult implements JsonSerializable
{
    /** The run was transitioned back to an active status and a continuation job was dispatched. */
    public const OUTCOME_RESUMED = 'resumed';

    /** The run is already being worked on (an active status, or an active lease/job). */
    public const OUTCOME_ALREADY_RUNNING = 'already_running';

    /** The run has already reached a terminal, non-recoverable-by-design state. */
    public const OUTCOME_ALREADY_COMPLETE = 'already_complete';

    /** The run's status/error does not describe a state this service is willing to resume. */
    public const OUTCOME_NOT_RECOVERABLE = 'not_recoverable';

    /** Escalated/failed, but a fresh re-evaluation found nothing actually incomplete anymore. */
    public const OUTCOME_STALE_STATE = 'stale_state';

    /** The source document or its applied pages no longer exist. */
    public const OUTCOME_MISSING_DEPENDENCIES = 'missing_dependencies';

    /** @param  string[]  $incompleteSteps */
    public function __construct(
        public string $outcome,
        public string $reason,
        public array $incompleteSteps = [],
    ) {}

    public static function resumed(string $reason, array $incompleteSteps): self
    {
        return new self(self::OUTCOME_RESUMED, $reason, $incompleteSteps);
    }

    /** @param  string[]  $incompleteSteps */
    public static function alreadyRunning(string $reason, array $incompleteSteps = []): self
    {
        return new self(self::OUTCOME_ALREADY_RUNNING, $reason, $incompleteSteps);
    }

    public static function alreadyComplete(string $reason): self
    {
        return new self(self::OUTCOME_ALREADY_COMPLETE, $reason);
    }

    /** @param  string[]  $incompleteSteps */
    public static function notRecoverable(string $reason, array $incompleteSteps = []): self
    {
        return new self(self::OUTCOME_NOT_RECOVERABLE, $reason, $incompleteSteps);
    }

    public static function staleState(string $reason): self
    {
        return new self(self::OUTCOME_STALE_STATE, $reason);
    }

    public static function missingDependencies(string $reason): self
    {
        return new self(self::OUTCOME_MISSING_DEPENDENCIES, $reason);
    }

    public function isResumed(): bool
    {
        return $this->outcome === self::OUTCOME_RESUMED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'reason' => $this->reason,
            'incomplete_steps' => $this->incompleteSteps,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
