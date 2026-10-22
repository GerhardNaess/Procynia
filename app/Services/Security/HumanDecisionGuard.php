<?php

namespace App\Services\Security;

use App\Exceptions\HumanDecisionRefused;
use App\Models\User;
use App\Support\HumanDecision;

/**
 * The single check that a decision reserved for a person is being taken by one.
 *
 * Called by every service that takes a HumanDecision, before it writes. It refuses:
 * - no actor at all — a decision nobody is answerable for;
 * - an inactive account;
 * - an integration identity (User::ROLE_INTEGRATION), whatever roles it holds;
 * - any request that came through the external API, delegated mode included.
 *
 * It never grants anything. Passing the guard says the actor is a person acting interactively; the
 * service still applies its own rule for whether this person may take this decision. A users.role
 * of NULL is a person — the column is nullable and older rows and fixtures carry NULL.
 *
 * Background work (jobs, commands) takes no human decisions and does not call the guard.
 */
class HumanDecisionGuard
{
    public const REFUSAL_NO_ACTOR = 'no_actor';

    public const REFUSAL_INACTIVE = 'inactive';

    public const REFUSAL_INTEGRATION = 'integration';

    public const REFUSAL_API = 'api';

    public function __construct(private readonly ActorChannel $channel) {}

    public function assert(?User $actor, HumanDecision $decision): User
    {
        $refusal = $this->refusal($actor);

        if ($refusal !== null) {
            throw new HumanDecisionRefused($decision, $refusal);
        }

        return $actor;
    }

    public function allows(?User $actor): bool
    {
        return $this->refusal($actor) === null;
    }

    private function refusal(?User $actor): ?string
    {
        return match (true) {
            $actor === null => self::REFUSAL_NO_ACTOR,
            ! $actor->is_active => self::REFUSAL_INACTIVE,
            $actor->isIntegration() => self::REFUSAL_INTEGRATION,
            $this->channel->isApi() => self::REFUSAL_API,
            default => null,
        };
    }
}
