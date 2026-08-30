<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\IdentityProvider;
use App\Models\User;
use App\Services\Auth\EntraConfig;
use App\Services\Auth\EntraIdentityResolver;
use App\Services\Auth\EntraOidcClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Microsoft Entra ID sign-in (OpenID Connect Authorization Code Flow).
 *
 * Entra authenticates the identity. Everything after that — which user, which customer, which role,
 * whether the account is active — is answered from Procynia's own tables. See
 * EntraIdentityResolver.
 *
 * The user-facing failure message is the same for every refusal. Telling a caller "no such user" or
 * "wrong customer" would turn the login page into a directory oracle, so the reason is logged and
 * the visitor is told only that sign-in failed.
 */
class EntraAuthController extends Controller
{
    public const EVENT_AUTH_SUCCEEDED = 'entra_authentication_succeeded';

    public const EVENT_AUTH_FAILED = 'entra_authentication_failed';

    public const EVENT_IDENTITY_LINKED = 'entra_identity_linked';

    private const SESSION_STATE = 'entra.state';

    private const SESSION_NONCE = 'entra.nonce';

    private const SESSION_PROVIDER = 'entra.provider_id';

    public function __construct(
        private readonly EntraConfig $config,
        private readonly EntraOidcClient $oidc,
        private readonly EntraIdentityResolver $resolver,
    ) {}

    /**
     * Begin sign-in.
     *
     * The provider is chosen server-side. A caller may hint which one with ?provider=, but the hint
     * only selects among enabled providers — it can never widen what is available, and the tenant is
     * re-derived from the signed token at the callback anyway.
     */
    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->config->entraEnabled()) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $this->config->assertUsable();

        $provider = $this->resolveProviderForRequest($request);

        if ($provider === null) {
            $this->logFailure('provider_unavailable', $request);

            return redirect()->route('login')->withErrors([
                'entra' => __('procynia.auth.entra_login_failed'),
            ]);
        }

        $authorization = $this->oidc->authorizationRequest($provider);

        $request->session()->put(self::SESSION_STATE, $authorization['state']);
        $request->session()->put(self::SESSION_NONCE, $authorization['nonce']);
        $request->session()->put(self::SESSION_PROVIDER, $provider->id);

        return redirect()->away($authorization['url']);
    }

    /**
     * Handle the redirect back from Microsoft.
     */
    public function callback(Request $request): RedirectResponse
    {
        if (! $this->config->entraEnabled()) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $state = $request->session()->pull(self::SESSION_STATE);
        $nonce = $request->session()->pull(self::SESSION_NONCE);
        $providerId = $request->session()->pull(self::SESSION_PROVIDER);

        // Entra reports user-facing errors (consent declined, account disabled) on the query string.
        if ($request->filled('error')) {
            return $this->fail('provider_error', $request);
        }

        // CSRF on the callback. A response without matching state did not originate from a request
        // this session started.
        if (! is_string($state) || $state === '' || ! hash_equals($state, (string) $request->query('state'))) {
            return $this->fail('state_mismatch', $request);
        }

        $code = (string) $request->query('code');

        if ($code === '' || ! is_string($nonce) || $nonce === '') {
            return $this->fail('missing_code', $request);
        }

        $provider = IdentityProvider::query()->find($providerId);

        if (! $provider instanceof IdentityProvider) {
            return $this->fail(EntraIdentityResolver::REASON_PROVIDER_UNKNOWN, $request);
        }

        try {
            $claims = $this->oidc->exchangeCodeForClaims($provider, $code, $nonce);
        } catch (Throwable $e) {
            // The message can contain token material, so it is never surfaced or logged verbatim.
            return $this->fail('token_validation_failed', $request, ['exception' => $e::class]);
        }

        // Re-derive the provider from the signed tenant claim rather than trusting the session. If a
        // token from another tenant somehow arrives, it resolves to that tenant's provider — and
        // therefore that tenant's customer — instead of the one the flow started with.
        $tenantId = (string) ($claims['tid'] ?? $provider->tenant_id);
        $tokenProvider = $this->resolver->providerForTenant($tenantId);

        if ($tokenProvider === null || $tokenProvider->id !== $provider->id) {
            return $this->fail(EntraIdentityResolver::REASON_CUSTOMER_MISMATCH, $request);
        }

        $result = $this->resolver->resolve($tokenProvider, $claims);
        $user = $result['user'];

        if (! $user instanceof User) {
            return $this->fail((string) $result['reason'], $request);
        }

        if ($result['outcome'] === EntraIdentityResolver::OUTCOME_LINKED_EXISTING) {
            Log::info('[PROCYNIA][AUTH] Entra identity linked to an existing user.', [
                'event' => self::EVENT_IDENTITY_LINKED,
                'user_id' => $user->id,
                'customer_id' => $user->customer_id,
                'identity_provider_id' => $tokenProvider->id,
            ]);
        }

        Auth::login($user);

        // Keeps the hardened session from F-06 and closes session fixation, exactly as the local
        // login does. No Entra token is kept: from here on the browser holds a Procynia session.
        $request->session()->regenerate();

        Log::info('[PROCYNIA][AUTH] Entra login succeeded.', [
            'event' => self::EVENT_AUTH_SUCCEEDED,
            'user_id' => $user->id,
            'customer_id' => $user->customer_id,
            'identity_provider_id' => $tokenProvider->id,
            'outcome' => $result['outcome'],
        ]);

        return redirect()->intended($this->destinationFor($user));
    }

    /**
     * Which provider starts the flow.
     *
     * With a single configured provider there is nothing to choose. With several, the caller may
     * name one; the value is matched against enabled providers only, so an unknown or disabled id
     * simply yields nothing.
     */
    private function resolveProviderForRequest(Request $request): ?IdentityProvider
    {
        $query = IdentityProvider::query()->enabled()->where('provider', IdentityProvider::PROVIDER_ENTRA);

        $hint = $request->query('provider');

        if (is_string($hint) && $hint !== '') {
            return (clone $query)->whereKey($hint)->first();
        }

        return $query->count() === 1 ? $query->first() : null;
    }

    private function destinationFor(User $user): string
    {
        return $user->canAccessCustomerFrontend()
            ? route('app.notices.index', ['mode' => 'saved'])
            : route('filament.admin.pages.dashboard');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fail(string $reason, Request $request, array $context = []): RedirectResponse
    {
        $this->logFailure($reason, $request, $context);

        return redirect()->route('login')->withErrors([
            'entra' => __('procynia.auth.entra_login_failed'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logFailure(string $reason, Request $request, array $context = []): void
    {
        // Reason and client IP only. No code, no token, no raw claims — an authorization code in a
        // log file is a credential in a log file.
        Log::warning('[PROCYNIA][AUTH] Entra login failed.', array_merge([
            'event' => self::EVENT_AUTH_FAILED,
            'reason' => $reason,
            'ip' => $request->ip(),
        ], $context));
    }
}
