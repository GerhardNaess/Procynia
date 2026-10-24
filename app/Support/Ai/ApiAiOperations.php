<?php

namespace App\Support\Ai;

/**
 * The AI operations the external API may spend, by the route that spends them (D19, replacing the
 * blanket refusal D11 for exactly these).
 *
 * Keyed by route name, not by operation: «Spør Wiki» through the API may run the shared Wiki
 * navigation and the two Q&A calls, and nothing else may — not the same operations from another
 * endpoint, and not any other operation from this one. A route that is not listed spends nothing,
 * which is every other API route. AiCallContextPolicy asks this before every provider call.
 */
final class ApiAiOperations
{
    /** @var array<string, list<string>> */
    private const BY_ROUTE = [
        'api.v1.wiki.ask' => ['wiki.navigation_plan', 'wiki.ask.retrieval_plan', 'wiki.ask.answer'],
    ];

    public static function allows(?string $routeName, ?string $operation): bool
    {
        if ($routeName === null || $operation === null) {
            return false;
        }

        return in_array($operation, self::BY_ROUTE[$routeName] ?? [], true);
    }

    /** @return array<string, list<string>> */
    public static function byRoute(): array
    {
        return self::BY_ROUTE;
    }
}
