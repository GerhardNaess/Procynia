<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Auth\EntraAuthController;
use App\Models\Customer;
use App\Models\IdentityProvider;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Auth\EntraOidcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Microsoft Entra ID sign-in.
 *
 * Nothing here talks to Microsoft. EntraOidcClient is replaced with a double that returns whatever
 * claims a test wants, which is the point: the interesting behaviour is not "can we speak OIDC" but
 * what Procynia does with a set of claims once they are validated.
 *
 * The controls under test are Procynia's, not Entra's: which user a subject resolves to, which
 * customer that user must belong to, whether the account is still active, and the refusal to invent
 * a user or a role from a claim.
 */
class EntraAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = '11111111-1111-1111-1111-111111111111';

    private const TENANT_B = '22222222-2222-2222-2222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('procynia.auth.entra_enabled', true);
        Config::set('procynia.auth.local_login_enabled', true);
        Config::set('procynia.auth.entra.client_secret', 'test-client-secret');
        Config::set('procynia.auth.entra.redirect_uri', 'https://procynia.test/login/entra/callback');

        // The abuse limiter on the Entra routes is cache-backed and survives RefreshDatabase, so a
        // class with many callback tests would otherwise start returning 429.
        // The abuse limit on these routes is not what this class tests, and a shared cache-backed
        // limiter would make results depend on test order.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    private function customer(string $name): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function provider(?Customer $customer, string $tenantId, array $overrides = []): IdentityProvider
    {
        return IdentityProvider::query()->create(array_merge([
            'customer_id' => $customer?->id,
            'provider' => IdentityProvider::PROVIDER_ENTRA,
            'tenant_id' => $tenantId,
            'client_id' => 'client-'.$tenantId,
            'issuer' => 'https://login.microsoftonline.com/'.$tenantId.'/v2.0',
            'is_enabled' => true,
        ], $overrides));
    }

    private function user(?Customer $customer, string $email, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Test '.$email,
            'email' => $email,
            'password' => bcrypt('LocalOnly123!'),
            'role' => $customer === null ? User::ROLE_SUPER_ADMIN : User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer?->id,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * Replace the OIDC client. Everything up to "we have validated claims" is Microsoft's job and is
     * exercised by the library; what matters here is what happens after.
     *
     * @param  array<string, mixed>|null  $claims  null makes the exchange throw, as an invalid token would.
     */
    private function fakeOidc(?array $claims): void
    {
        $this->instance(EntraOidcClient::class, new class($claims) extends EntraOidcClient
        {
            public function __construct(private readonly ?array $claims)
            {
                // Intentionally no parent::__construct — the double never builds a real provider.
            }

            public function authorizationRequest(IdentityProvider $provider): array
            {
                return [
                    'url' => 'https://login.microsoftonline.com/'.$provider->tenant_id.'/oauth2/v2.0/authorize?fake=1',
                    'state' => 'test-state',
                    'nonce' => 'test-nonce',
                ];
            }

            public function exchangeCodeForClaims(IdentityProvider $provider, string $code, string $expectedNonce): array
            {
                if ($this->claims === null) {
                    throw new RuntimeException('invalid token');
                }

                return $this->claims;
            }
        });
    }

    /** Drive the callback with the session state a real redirect would have left behind. */
    private function driveCallback(IdentityProvider $provider, array $query = []): TestResponse
    {
        return $this->withSession([
            'entra.state' => 'test-state',
            'entra.nonce' => 'test-nonce',
            'entra.provider_id' => $provider->id,
        ])->get('/login/entra/callback?'.http_build_query(array_merge([
            'code' => 'test-code',
            'state' => 'test-state',
        ], $query)));
    }

    private function claimsFor(string $tenantId, string $subject, string $email): array
    {
        return ['tid' => $tenantId, 'oid' => $subject, 'email' => $email, 'exp' => time() + 300];
    }

    // -----------------------------------------------------------------------
    // Redirect
    // -----------------------------------------------------------------------

    public function test_the_sign_in_route_redirects_to_microsoft(): void
    {
        $this->provider($this->customer('Alfa AS'), self::TENANT_A);
        $this->fakeOidc(null);

        $response = $this->get('/login/entra');

        $response->assertRedirect();
        $this->assertStringStartsWith(
            'https://login.microsoftonline.com/'.self::TENANT_A,
            $response->headers->get('Location'),
        );
        $this->assertSame('test-state', session('entra.state'));
        $this->assertSame('test-nonce', session('entra.nonce'));
    }

    public function test_the_entra_routes_do_not_exist_when_entra_is_disabled(): void
    {
        Config::set('procynia.auth.entra_enabled', false);
        $this->provider($this->customer('Alfa AS'), self::TENANT_A);

        $this->get('/login/entra')->assertNotFound();
        $this->get('/login/entra/callback')->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Callback — the happy path
    // -----------------------------------------------------------------------

    public function test_a_valid_callback_links_and_signs_in_an_existing_user(): void
    {
        $customer = $this->customer('Alfa AS');
        $provider = $this->provider($customer, self::TENANT_A);
        $user = $this->user($customer, 'ansatt@alfa.test');

        $this->fakeOidc($this->claimsFor(self::TENANT_A, 'subject-a', 'ansatt@alfa.test'));

        $this->driveCallback($provider)->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('user_identities', [
            'user_id' => $user->id,
            'identity_provider_id' => $provider->id,
            'external_subject' => 'subject-a',
        ]);
    }

    public function test_a_returning_identity_signs_in_without_relinking(): void
    {
        $customer = $this->customer('Alfa AS');
        $provider = $this->provider($customer, self::TENANT_A);
        $user = $this->user($customer, 'ansatt@alfa.test');

        UserIdentity::query()->create([
            'user_id' => $user->id,
            'identity_provider_id' => $provider->id,
            'external_subject' => 'subject-a',
            'external_email' => 'gammel@alfa.test',
        ]);

        // A changed address must not matter: the subject is the identity, not the email.
        $this->fakeOidc($this->claimsFor(self::TENANT_A, 'subject-a', 'nytt-navn@alfa.test'));

        $this->driveCallback($provider)->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, UserIdentity::query()->count());
    }

    public function test_the_session_is_regenerated_on_sign_in(): void
    {
        $customer = $this->customer('Alfa AS');
        $provider = $this->provider($customer, self::TENANT_A);
        $this->user($customer, 'ansatt@alfa.test');

        $this->fakeOidc($this->claimsFor(self::TENANT_A, 'subject-a', 'ansatt@alfa.test'));

        $this->startSession();
        $before = session()->getId();

        $this->driveCallback($provider);

        // Session fixation: the id a caller may have planted must not survive authentication.
        $this->assertNotSame($before, session()->getId());
    }

    // -----------------------------------------------------------------------
    // Callback — refusals
    // -----------------------------------------------------------------------

    public function test_a_state_mismatch_is_refused(): void
    {
        $customer = $this->customer('Alfa AS');
        $provider = $this->provider($customer, self::TENANT_A);
        $this->user($customer, 'ansatt@alfa.test');
        $this->fakeOidc($this->claimsFor(self::TENANT_A, 'subject-a', 'ansatt@alfa.test'));

        $this->driveCallback($provider, ['state' => 'tampered'])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_token_that_fails_validation_is_refused(): void
    {
        $customer = $this->customer('Alfa AS');
        $provider = $this->provider($customer, self::TENANT_A);
        $this->user($customer, 'ansatt@alfa.test');

        $this->fakeOidc(null);

        $this->driveCallback($provider)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_unknown_identity_is_refused_rather_than_provisioned(): void
    {
        $customer = $this->customer('Alfa AS');
        $provider = $this->provider($customer, self::TENANT_A);

        // No Procynia user with this address. Creating one would mean inventing a role and a
        // customer from an external claim.
        $this->fakeOidc($this->claimsFor(self::TENANT_A, 'subject-x', 'ukjent@alfa.test'));

        $this->driveCallback($provider)->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, User::query()->where('email', 'ukjent@alfa.test')->count());
    }

    public function test_an_inactive_user_is_refused(): void
    {
        $customer = $this->customer('Alfa AS');
        $provider = $this->provider($customer, self::TENANT_A);
        $this->user($customer, 'sluttet@alfa.test', ['is_active' => false]);

        $this->fakeOidc($this->claimsFor(self::TENANT_A, 'subject-a', 'sluttet@alfa.test'));

        $this->driveCallback($provider)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_user_deactivated_after_linking_can_no_longer_sign_in(): void
    {
        $customer = $this->customer('Alfa AS');
        $provider = $this->provider($customer, self::TENANT_A);
        $user = $this->user($customer, 'ansatt@alfa.test');

        UserIdentity::query()->create([
            'user_id' => $user->id,
            'identity_provider_id' => $provider->id,
            'external_subject' => 'subject-a',
        ]);

        $user->forceFill(['is_active' => false])->save();

        $this->fakeOidc($this->claimsFor(self::TENANT_A, 'subject-a', 'ansatt@alfa.test'));

        $this->driveCallback($provider)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_ambiguous_match_guard_exists_even_though_the_schema_prevents_it(): void
    {
        // users.email carries a global unique index, so two active accounts cannot share an address
        // today and the ambiguity branch is unreachable through the database.
        //
        // The guard stays because that index is the only thing making it unreachable: were email
        // ever scoped per customer — a reasonable future change for a multi-customer product — a
        // resolver that picked the first match would silently choose between two identities. This
        // asserts the constraint the guard depends on, so removing the index fails here first.
        $indexes = collect(\DB::select(
            'select indexdef from pg_indexes where tablename = ? and indexname = ?',
            ['users', 'users_email_unique'],
        ));

        $this->assertCount(1, $indexes, 'users.email must stay globally unique, or linking becomes ambiguous.');
        $this->assertStringContainsString('UNIQUE', (string) $indexes->first()->indexdef);
    }

    public function test_a_disabled_provider_refuses_sign_in(): void
    {
        $customer = $this->customer('Alfa AS');
        $provider = $this->provider($customer, self::TENANT_A, ['is_enabled' => false]);
        $this->user($customer, 'ansatt@alfa.test');

        $this->fakeOidc($this->claimsFor(self::TENANT_A, 'subject-a', 'ansatt@alfa.test'));

        $this->driveCallback($provider)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_email_domain_outside_the_allow_list_is_refused(): void
    {
        $customer = $this->customer('Alfa AS');
        $provider = $this->provider($customer, self::TENANT_A, [
            'allowed_email_domains' => ['alfa.test'],
        ]);

        // A guest account inside the customer's own tenant, from another organisation.
        $this->user($customer, 'gjest@ekstern.test');

        $this->fakeOidc($this->claimsFor(self::TENANT_A, 'subject-a', 'gjest@ekstern.test'));

        $this->driveCallback($provider)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // -----------------------------------------------------------------------
    // Cross-tenant isolation — the critical part
    // -----------------------------------------------------------------------

    public function test_an_identity_from_one_tenant_cannot_reach_a_user_in_another_customer(): void
    {
        $alfa = $this->customer('Alfa AS');
        $beta = $this->customer('Beta AS');

        $providerA = $this->provider($alfa, self::TENANT_A);
        $this->provider($beta, self::TENANT_B);

        // The user exists only in Beta. Tenant A must not reach them.
        $betaUser = $this->user($beta, 'delt@felles.test');

        $this->fakeOidc($this->claimsFor(self::TENANT_A, 'subject-a', 'delt@felles.test'));

        $this->driveCallback($providerA)->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, UserIdentity::query()->where('user_id', $betaUser->id)->count());
    }

    public function test_a_tenant_identity_never_links_to_a_user_outside_its_own_customer(): void
    {
        // The reachable form of "same email, wrong customer": because users.email is globally
        // unique, the address exists exactly once — in Beta. An identity from Alfa's tenant carrying
        // that address must not find it.
        $alfa = $this->customer('Alfa AS');
        $beta = $this->customer('Beta AS');

        $providerA = $this->provider($alfa, self::TENANT_A);
        $this->provider($beta, self::TENANT_B);

        $betaUser = $this->user($beta, 'navn@felles.test');

        $this->fakeOidc($this->claimsFor(self::TENANT_A, 'subject-a', 'navn@felles.test'));

        $this->driveCallback($providerA)->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('user_identities', ['user_id' => $betaUser->id]);
    }

    public function test_a_token_whose_tenant_does_not_match_the_started_flow_is_refused(): void
    {
        $alfa = $this->customer('Alfa AS');
        $beta = $this->customer('Beta AS');

        $providerA = $this->provider($alfa, self::TENANT_A);
        $this->provider($beta, self::TENANT_B);
        $this->user($beta, 'ansatt@beta.test');

        // Flow started against tenant A, token claims tenant B.
        $this->fakeOidc($this->claimsFor(self::TENANT_B, 'subject-b', 'ansatt@beta.test'));

        $this->driveCallback($providerA)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_user_moved_to_another_customer_loses_the_existing_link(): void
    {
        $alfa = $this->customer('Alfa AS');
        $beta = $this->customer('Beta AS');
        $provider = $this->provider($alfa, self::TENANT_A);

        $user = $this->user($alfa, 'ansatt@alfa.test');

        UserIdentity::query()->create([
            'user_id' => $user->id,
            'identity_provider_id' => $provider->id,
            'external_subject' => 'subject-a',
        ]);

        // The link row still exists, but the user now belongs to Beta. The customer is re-verified
        // on every login, so a stale link cannot carry them across.
        $user->forceFill(['customer_id' => $beta->id])->save();

        $this->fakeOidc($this->claimsFor(self::TENANT_A, 'subject-a', 'ansatt@alfa.test'));

        $this->driveCallback($provider)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // -----------------------------------------------------------------------
    // Authorization stays Procynia's
    // -----------------------------------------------------------------------

    public function test_entra_claims_do_not_grant_procynia_roles(): void
    {
        $customer = $this->customer('Alfa AS');
        $provider = $this->provider($customer, self::TENANT_A);
        $user = $this->user($customer, 'ansatt@alfa.test');

        // Claims that would be a privilege escalation if anything mapped them.
        $claims = array_merge($this->claimsFor(self::TENANT_A, 'subject-a', 'ansatt@alfa.test'), [
            'roles' => ['super_admin', 'system_owner'],
            'groups' => ['procynia-admins'],
            'wids' => ['62e90394-69f5-4237-9190-012177145e10'],
        ]);

        $this->fakeOidc($claims);
        $this->driveCallback($provider);

        $user->refresh();

        $this->assertSame(User::ROLE_USER, $user->role);
        $this->assertSame(User::BID_ROLE_CONTRIBUTOR, $user->bid_role);
        $this->assertFalse(Auth::user()?->isSuperAdmin() ?? false);
    }

    public function test_an_internal_admin_cannot_sign_in_through_a_customer_tenant(): void
    {
        $customer = $this->customer('Alfa AS');
        $customerProvider = $this->provider($customer, self::TENANT_A);
        $this->user(null, 'intern@procynia.test');

        // Through a customer's tenant the internal admin is not even a candidate: the linking query
        // is restricted to that customer's users.
        $this->fakeOidc($this->claimsFor(self::TENANT_A, 'subject-i', 'intern@procynia.test'));

        $this->driveCallback($customerProvider)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_internal_admin_signs_in_through_the_internal_provider(): void
    {
        $internalProvider = $this->provider(null, self::TENANT_B);
        $admin = $this->user(null, 'intern@procynia.test');

        $this->fakeOidc($this->claimsFor(self::TENANT_B, 'subject-i', 'intern@procynia.test'));

        $this->driveCallback($internalProvider)->assertRedirect();

        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('user_identities', [
            'user_id' => $admin->id,
            'identity_provider_id' => $internalProvider->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Login mode matrix
    // -----------------------------------------------------------------------

    public function test_local_login_still_works_when_entra_is_off(): void
    {
        Config::set('procynia.auth.entra_enabled', false);
        Config::set('procynia.auth.local_login_enabled', true);

        $customer = $this->customer('Alfa AS');
        $this->user($customer, 'ansatt@alfa.test');

        $this->get('/login')->assertOk();

        $this->post('/login', [
            'email' => 'ansatt@alfa.test',
            'password' => 'LocalOnly123!',
        ])->assertRedirect();

        $this->assertAuthenticated();
    }

    public function test_local_login_is_refused_when_disabled(): void
    {
        Config::set('procynia.auth.local_login_enabled', false);

        $customer = $this->customer('Alfa AS');
        $this->user($customer, 'ansatt@alfa.test');

        // The route must refuse, not merely be hidden in the UI.
        $this->post('/login', [
            'email' => 'ansatt@alfa.test',
            'password' => 'LocalOnly123!',
        ])->assertNotFound();

        $this->assertGuest();
    }

    public function test_the_login_page_advertises_the_configured_methods(): void
    {
        Config::set('procynia.auth.local_login_enabled', false);
        $this->provider($this->customer('Alfa AS'), self::TENANT_A);

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('authOptions.entraEnabled', true)
                ->where('authOptions.localLoginEnabled', false));
    }

    // -----------------------------------------------------------------------
    // Enumeration safety
    // -----------------------------------------------------------------------

    public function test_every_refusal_returns_the_same_message(): void
    {
        $customer = $this->customer('Alfa AS');
        $provider = $this->provider($customer, self::TENANT_A);
        $this->user($customer, 'finnes@alfa.test', ['is_active' => false]);

        $expected = __('procynia.auth.entra_login_failed');

        // Two different internal reasons — an account that exists but is inactive, and one that does
        // not exist at all — must be indistinguishable from outside, or the login page becomes a
        // directory oracle.
        foreach ([
            ['subject-1', 'finnes@alfa.test'],
            ['subject-2', 'finnes-ikke@alfa.test'],
        ] as [$subject, $email]) {
            $this->fakeOidc($this->claimsFor(self::TENANT_A, $subject, $email));

            $this->driveCallback($provider)
                ->assertRedirect(route('login'))
                ->assertSessionHasErrors(['entra' => $expected]);
        }
    }

    public function test_the_security_log_events_are_defined(): void
    {
        $this->assertSame('entra_authentication_succeeded', EntraAuthController::EVENT_AUTH_SUCCEEDED);
        $this->assertSame('entra_authentication_failed', EntraAuthController::EVENT_AUTH_FAILED);
        $this->assertSame('entra_identity_linked', EntraAuthController::EVENT_IDENTITY_LINKED);
    }
}
