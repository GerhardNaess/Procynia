<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * An Entra tenant's published signing keys, shared by sign-in (ID tokens) and the external API
 * (access tokens).
 *
 * Cached for an hour: Microsoft rotates these, so pinning them would break validation, and fetching
 * them on every request would put an external dependency in the request path. Microsoft publishes a
 * new key well before it signs with it, so an hour-old set already holds it.
 */
class EntraJwksProvider
{
    /**
     * @return array<string, mixed>
     */
    public function forTenant(string $tenantId): array
    {
        return Cache::remember(
            'entra:jwks:'.$tenantId,
            now()->addHour(),
            function () use ($tenantId): array {
                $url = 'https://login.microsoftonline.com/'.$tenantId.'/discovery/v2.0/keys';

                $response = Http::timeout(10)->get($url);

                if (! $response->successful()) {
                    throw new RuntimeException('Could not retrieve the signing keys for the identity provider.');
                }

                return (array) $response->json();
            },
        );
    }
}
