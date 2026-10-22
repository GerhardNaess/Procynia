<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaimDecision;
use App\Models\User;

/**
 * Who changed a claim: a person, or Procynia applying one of its own rules (a named system
 * process), optionally set off by a person's action. There is no system user — a system event is
 * recorded as one, never under someone's name.
 */
final class EnterpriseWikiClaimActor
{
    private function __construct(
        public readonly ?int $userId,
        public readonly ?string $process,
        public readonly ?int $initiatedByUserId,
    ) {}

    public static function user(User|int $user): self
    {
        return new self($user instanceof User ? (int) $user->id : $user, null, null);
    }

    /** @param  string  $process  an EnterpriseWikiClaimDecision::PROCESS_* value */
    public static function system(string $process, ?User $initiatedBy = null): self
    {
        return new self(null, $process, $initiatedBy?->id);
    }

    public function isSystem(): bool
    {
        return $this->process !== null;
    }

    /** @return array{decided_by_user_id: ?int, actor_type: string, system_process: ?string, initiated_by_user_id: ?int} */
    public function columns(): array
    {
        return [
            'decided_by_user_id' => $this->userId,
            'actor_type' => $this->isSystem() ? EnterpriseWikiClaimDecision::ACTOR_SYSTEM : EnterpriseWikiClaimDecision::ACTOR_USER,
            'system_process' => $this->process,
            'initiated_by_user_id' => $this->initiatedByUserId,
        ];
    }
}
