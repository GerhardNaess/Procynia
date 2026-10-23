<?php

namespace App\Services\Security;

use Illuminate\Http\Request;

/**
 * A request to the external API, authenticated or not, in any mode.
 *
 * Decided by the path, not by authentication: a service resolved before the API middleware has
 * run — a controller's constructor, say — must already see the API channel. Every request under
 * /api is one, so HumanDecisionGuard refuses every human decision there (B2), and no Procynia AI
 * call can be made from it (D11).
 */
final class ApiActorChannel implements ActorChannel
{
    public static function covers(Request $request): bool
    {
        return $request->is('api', 'api/*');
    }

    public function isApi(): bool
    {
        return true;
    }
}
