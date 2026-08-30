<?php

namespace App\Services\Auth;

use App\Models\IdentityProvider;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\DB;

/**
 * Turns validated Entra claims into a Procynia user — or refuses.
 *
 * This is where authentication stops and Procynia's own model begins. Entra says *who* signed in.
 * It does not say what they may do, which customer they belong to, or whether their account is still
 * active. Those are Procynia's answers, read from Procynia's tables.
 *
 * No claim is mapped to a role. There is no group-to-role, no app-role-to-bid_role. A directory
 * administrator in a customer's tenant can create groups all day without gaining anything here.
 *
 * Resolution order, and why:
 *
 *   1. Known identity — (provider, subject) has been linked before. Straight through.
 *   2. First linking — no link yet, so match a *verified* email inside the provider's own customer,
 *      and only if exactly one active candidate exists.
 *   3. Otherwise refuse. No user is created, no role assumed.
 *
 * The provider carries the customer, so step 2 can never look outside it. That is the guard that
 * makes "same email in two customers" safe.
 */
class EntraIdentityResolver
{
    public const OUTCOME_LINKED_EXISTING = 'linked_existing';

    public const OUTCOME_KNOWN_IDENTITY = 'known_identity';

    public const REASON_PROVIDER_UNKNOWN = 'provider_unknown';

    public const REASON_PROVIDER_DISABLED = 'provider_disabled';

    public const REASON_EMAIL_DOMAIN_NOT_ALLOWED = 'email_domain_not_allowed';

    public const REASON_NO_MATCHING_USER = 'no_matching_user';

    public const REASON_AMBIGUOUS_MATCH = 'ambiguous_match';

    public const REASON_USER_INACTIVE = 'user_inactive';

    public const REASON_CUSTOMER_MISMATCH = 'customer_mismatch';

    public const REASON_NO_FRONTEND_ACCESS = 'no_frontend_access';

    /**
     * Find the enabled provider for a tenant.
     *
     * The tenant comes from the validated token, never from anything the browser sent, so a caller
     * cannot choose which customer to be resolved against.
     */
    public function providerForTenant(string $tenantId): ?IdentityProvider
    {
        return IdentityProvider::query()
            ->enabled()
            ->where('provider', IdentityProvider::PROVIDER_ENTRA)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array{user: User|null, identity: UserIdentity|null, outcome: string|null, reason: string|null}
     */
    public function resolve(IdentityProvider $provider, array $claims): array
    {
        if (! $provider->is_enabled) {
            return $this->refuse(self::REASON_PROVIDER_DISABLED);
        }

        $subject = $this->subjectFrom($claims);
        $email = $this->emailFrom($claims);

        if ($subject === null) {
            return $this->refuse(self::REASON_NO_MATCHING_USER);
        }

        if (! $provider->allowsEmailDomain($email)) {
            return $this->refuse(self::REASON_EMAIL_DOMAIN_NOT_ALLOWED);
        }

        $identity = UserIdentity::query()
            ->where('identity_provider_id', $provider->id)
            ->where('external_subject', $subject)
            ->with('user')
            ->first();

        if ($identity !== null) {
            $user = $identity->user;

            if ($user === null) {
                return $this->refuse(self::REASON_NO_MATCHING_USER);
            }

            // Re-checked on every login rather than trusted from linking time: a user may have been
            // moved or deactivated since.
            $guard = $this->guardUser($user, $provider);

            if ($guard !== null) {
                return $this->refuse($guard);
            }

            $identity->forceFill([
                'external_email' => $email,
                'last_login_at' => now(),
            ])->save();

            return [
                'user' => $user,
                'identity' => $identity,
                'outcome' => self::OUTCOME_KNOWN_IDENTITY,
                'reason' => null,
            ];
        }

        return $this->linkForFirstTime($provider, $subject, $email);
    }

    /**
     * @return array{user: User|null, identity: UserIdentity|null, outcome: string|null, reason: string|null}
     */
    private function linkForFirstTime(IdentityProvider $provider, string $subject, ?string $email): array
    {
        // Without a verified address there is nothing to match on, and guessing is exactly what this
        // method must not do.
        if ($email === null) {
            return $this->refuse(self::REASON_NO_MATCHING_USER);
        }

        $query = User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->where('is_active', true);

        // An internal provider matches internal users; a customer provider matches only that
        // customer's users. This is the line that stops tenant A reaching a user in customer B.
        if ($provider->isInternal()) {
            $query->whereNull('customer_id');
        } else {
            $query->where('customer_id', $provider->customer_id);
        }

        $candidates = $query->limit(2)->get();

        if ($candidates->isEmpty()) {
            // Deliberately not auto-provisioning. Creating a user here would mean inventing a
            // customer and a role from an external claim.
            return $this->refuse(self::REASON_NO_MATCHING_USER);
        }

        if ($candidates->count() > 1) {
            // Two active accounts with the same address in the same customer. Picking one would be
            // a coin flip over an identity, so an administrator links it explicitly instead.
            return $this->refuse(self::REASON_AMBIGUOUS_MATCH);
        }

        $user = $candidates->first();

        $guard = $this->guardUser($user, $provider);

        if ($guard !== null) {
            return $this->refuse($guard);
        }

        $identity = DB::transaction(fn (): UserIdentity => UserIdentity::query()->create([
            'user_id' => $user->id,
            'identity_provider_id' => $provider->id,
            'external_subject' => $subject,
            'external_email' => $email,
            'last_login_at' => now(),
        ]));

        return [
            'user' => $user,
            'identity' => $identity,
            'outcome' => self::OUTCOME_LINKED_EXISTING,
            'reason' => null,
        ];
    }

    /**
     * Procynia's own conditions, applied identically to a first link and to every later login.
     */
    private function guardUser(User $user, IdentityProvider $provider): ?string
    {
        if (! $user->is_active) {
            return self::REASON_USER_INACTIVE;
        }

        // The decisive isolation check. Even a previously linked identity is re-verified, so moving
        // a user to another customer breaks the link rather than silently carrying it over.
        $expectedCustomerId = $provider->isInternal() ? null : $provider->customer_id;

        if ($user->customer_id !== $expectedCustomerId) {
            return self::REASON_CUSTOMER_MISMATCH;
        }

        // An internal administrator has no customer frontend; their destination is Filament, and
        // canAccessPanel() remains the authority there (finding F-03).
        if (! $provider->isInternal() && ! $user->canAccessCustomerFrontend()) {
            return self::REASON_NO_FRONTEND_ACCESS;
        }

        return null;
    }

    /** @param array<string, mixed> $claims */
    private function subjectFrom(array $claims): ?string
    {
        // oid is stable for a user within a tenant; sub is stable only per application. Prefer oid,
        // fall back to sub.
        foreach (['oid', 'sub'] as $key) {
            $value = $claims[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $claims */
    private function emailFrom(array $claims): ?string
    {
        foreach (['email', 'preferred_username', 'upn'] as $key) {
            $value = $claims[$key] ?? null;

            if (is_string($value) && str_contains($value, '@')) {
                return mb_strtolower(trim($value));
            }
        }

        return null;
    }

    /**
     * @return array{user: null, identity: null, outcome: null, reason: string}
     */
    private function refuse(string $reason): array
    {
        return ['user' => null, 'identity' => null, 'outcome' => null, 'reason' => $reason];
    }
}
