<?php

namespace App\Services\Security;

/**
 * How the current request reached Procynia.
 *
 * Bound per request in the container. The web application binds InteractiveActorChannel; the
 * external API (phase 1) binds its request context, which answers isApi() with true in both
 * delegated and application mode. HumanDecisionGuard refuses every human decision when isApi() is
 * true: an agent acting on a person's behalf does not take that person's decisions.
 */
interface ActorChannel
{
    public function isApi(): bool;
}
