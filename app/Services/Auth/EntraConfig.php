<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * The two login switches, in one place.
 *
 * Kept out of the controllers because "is Entra on" and "is local login still allowed" are asked in
 * several places — the login page, the login POST, the OIDC routes and the runtime check — and they
 * must answer identically everywhere.
 */
class EntraConfig
{
    public function entraEnabled(): bool
    {
        return (bool) config('procynia.auth.entra_enabled', false);
    }

    public function localLoginEnabled(): bool
    {
        return (bool) config('procynia.auth.local_login_enabled', true);
    }

    public function clientSecret(): ?string
    {
        $secret = config('procynia.auth.entra.client_secret');

        return is_string($secret) && trim($secret) !== '' ? $secret : null;
    }

    public function redirectUri(): ?string
    {
        $uri = config('procynia.auth.entra.redirect_uri');

        return is_string($uri) && trim($uri) !== '' ? $uri : null;
    }

    /** @return array<int, string> */
    public function scopes(): array
    {
        return (array) config('procynia.auth.entra.scopes', ['openid', 'profile', 'email']);
    }

    public function leewaySeconds(): int
    {
        return (int) config('procynia.auth.entra.leeway_seconds', 60);
    }

    /**
     * Reasons this configuration cannot be served, as human-readable strings.
     *
     * Returned rather than thrown so the runtime check can list every problem at once instead of
     * surfacing them one deploy at a time.
     *
     * @return array<int, string>
     */
    public function problems(): array
    {
        $problems = [];

        // Both off locks every user out of a system that has no other door. Treated as a
        // misconfiguration rather than an exotic valid state.
        if (! $this->entraEnabled() && ! $this->localLoginEnabled()) {
            $problems[] = 'both AUTH_ENTRA_ENABLED and AUTH_LOCAL_LOGIN_ENABLED are off — no login method would remain';
        }

        if (! $this->entraEnabled()) {
            return $problems;
        }

        if ($this->clientSecret() === null) {
            $problems[] = 'AUTH_ENTRA_CLIENT_SECRET is missing';
        }

        $redirect = $this->redirectUri();

        if ($redirect === null) {
            $problems[] = 'AUTH_ENTRA_REDIRECT_URI is missing';
        } elseif (! filter_var($redirect, FILTER_VALIDATE_URL)) {
            $problems[] = 'AUTH_ENTRA_REDIRECT_URI is not a valid URL';
        } elseif (! app()->environment('local') && ! str_starts_with($redirect, 'https://')) {
            // An authorization code delivered over plain HTTP is a code an intermediary can read.
            $problems[] = 'AUTH_ENTRA_REDIRECT_URI must use HTTPS outside local development';
        }

        return $problems;
    }

    /**
     * Fail closed. Called before any OIDC route does work, so a half-configured deployment refuses
     * rather than falling back to whatever login happens to still be reachable.
     */
    public function assertUsable(): void
    {
        $problems = $this->problems();

        if ($problems !== []) {
            throw new RuntimeException('Entra authentication is misconfigured: '.implode('; ', $problems));
        }
    }
}
