<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\IdentityProvider;
use App\Models\Risk;
use App\Models\User;
use App\Models\UserIdentity;
use App\Models\UserNotification;
use App\Services\Auth\EntraIdentityResolver;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Services\ManagementReview\ManagementReviewAccessService;
use App\Services\Notifications\UserNotificationWriter;
use App\Services\Permissions\CustomerPermissionService;
use App\Services\Risk\RiskCreator;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesApiCustomerGrants;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * An integration identity is a users row, never a person (docs/external-api-architecture.md §4.3).
 *
 * It cannot sign in, is never listed or chosen as an employee, owner or reviewer, receives no
 * notification, is no licence, and holds read keys only — never a human decision, never the anbud
 * matrix, never System Owner — whatever its row or roles say. Its access is administered only
 * through Kundemiljø → Integrasjoner, never through Brukere.
 */
class IntegrationIdentityProtectionTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_an_integration_identity_is_left_out_of_every_ordinary_read_of_users(): void
    {
        $customer = $this->apiCustomer();
        $person = $this->person($customer);
        $identity = $this->integrationIdentity($customer);

        $this->assertNull(User::query()->find($identity->id));
        $this->assertSame([$person->id], $customer->users()->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertFalse(User::query()->where('email', $identity->email)->exists());

        // Only the integration's own administration reads it, and says so.
        $this->assertSame([$identity->id], User::integrationIdentities()->where('customer_id', $customer->id)->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_it_cannot_sign_in_with_a_password_a_session_or_entra(): void
    {
        $customer = $this->apiCustomer();
        $identity = $this->integrationIdentity($customer, ['password' => bcrypt('known-password-1!')]);

        $this->assertFalse(Auth::validate(['email' => $identity->email, 'password' => 'known-password-1!']));
        $this->post('/login', ['email' => $identity->email, 'password' => 'known-password-1!'])->assertSessionHasErrors('email');
        $this->assertGuest();

        // A session naming it resolves to nobody.
        $this->assertNull(Auth::guard('web')->getProvider()->retrieveById($identity->id));

        $provider = IdentityProvider::query()->create([
            'customer_id' => $customer->id,
            'provider' => IdentityProvider::PROVIDER_ENTRA,
            'tenant_id' => (string) Str::uuid(),
            'client_id' => (string) Str::uuid(),
            'issuer' => 'https://login.microsoftonline.com/x/v2.0',
            'is_enabled' => true,
        ]);
        UserIdentity::query()->create(['user_id' => $identity->id, 'identity_provider_id' => $provider->id, 'external_subject' => 'linked-subject']);
        $resolver = app(EntraIdentityResolver::class);

        $linked = $resolver->resolve($provider, ['oid' => 'linked-subject', 'tid' => $provider->tenant_id, 'email' => $identity->email]);
        $this->assertNull($linked['user']);
        $this->assertSame(EntraIdentityResolver::REASON_NO_MATCHING_USER, $linked['reason']);

        $firstTime = $resolver->resolve($provider, ['oid' => 'new-subject', 'tid' => $provider->tenant_id, 'email' => $identity->email, 'preferred_username' => $identity->email]);
        $this->assertNull($firstTime['user']);

        $this->assertFalse($identity->canAccessCustomerFrontend());
        $this->assertFalse($identity->canAccessPanel(filament()->getPanel('admin')));
    }

    public function test_it_is_never_listed_or_chosen_as_an_owner(): void
    {
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Beredskap');
        $systemOwner = $this->person($customer, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER);
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, CustomerPermissionCatalog::integrationPermissions());
        $editor = $this->person($customer);
        $this->giveRole($editor, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_CREATE]);

        // Pickers.
        foreach ([
            app(ComplianceAccessService::class)->ownerCandidates($systemOwner),
            app(SupplierAccessService::class)->ownerCandidates($systemOwner),
            app(ManagementReviewAccessService::class)->ownerCandidates($systemOwner),
            app(ImprovementCaseAccessService::class)->ownerCandidates($systemOwner, [(int) $area->id]),
        ] as $candidates) {
            $this->assertNotContains($identity->id, array_column($candidates, 'id'));
        }

        // Validators, even when handed the row itself.
        $this->assertFalse(app(ComplianceAccessService::class)->isValidOwner($identity, (int) $customer->id));
        $this->assertFalse(app(SupplierAccessService::class)->isValidOwner($identity, (int) $customer->id));
        $this->assertFalse(app(ManagementReviewAccessService::class)->isValidOwner($identity, (int) $customer->id));
        $this->assertFalse(app(ImprovementCaseAccessService::class)->isValidOwner($identity, (int) $customer->id, (int) $area->id));

        // A service given its id refuses it.
        try {
            app(RiskCreator::class)->create($editor, [
                'title' => 'Ny risiko', 'business_area_id' => $area->id, 'owner_user_id' => $identity->id, 'status' => Risk::STATUS_IDENTIFIED,
            ]);
            $this->fail('An integration identity was accepted as risk owner.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('owner_user_id', $e->errors());
        }

        // Brukere never lists it, and the user screens do not reach it.
        $this->actingAs($systemOwner)
            ->get('/app/customer-environment?tab=users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('users', fn ($users) => collect($users)->pluck('id')->doesntContain($identity->id)));
        $this->actingAs($systemOwner)->get("/app/users/{$identity->id}/edit")->assertNotFound();
        $this->actingAs($systemOwner)->put("/app/users/{$identity->id}", ['name' => 'Kapret'])->assertNotFound();
        $this->actingAs($systemOwner)->patch("/app/users/{$identity->id}/toggle-active")->assertNotFound();
        $this->assertTrue((bool) User::integrationIdentities()->find($identity->id)->is_active);
    }

    public function test_it_receives_no_notification_and_no_mail(): void
    {
        $customer = $this->apiCustomer();
        $identity = $this->integrationIdentity($customer);

        $written = app(UserNotificationWriter::class)->notify(
            (int) $customer->id, $identity, 'test.event', 'dedupe-'.Str::random(8), 'Tittel', 'Melding', '/app',
        );
        $this->assertFalse($written);
        $this->assertFalse(app(UserNotificationWriter::class)->notify(
            (int) $customer->id, (int) $identity->id, 'test.event', 'dedupe-'.Str::random(8), 'Tittel', 'Melding', '/app',
        ));
        $this->assertSame(0, UserNotification::query()->where('user_id', $identity->id)->count());

        Mail::fake();
        $identity->notify(new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage)->line('Hei');
            }
        });

        $this->assertNull($identity->routeNotificationForMail());
        Mail::assertNothingOutgoing();
    }

    public function test_it_is_no_licence(): void
    {
        $customer = $this->apiCustomer();
        $this->person($customer);
        $billing = app(BillingEntitlementService::class);
        $before = $billing->currentBillableUsers($customer);

        $this->integrationIdentity($customer);
        $this->integrationIdentity($customer);

        $this->assertSame($before, $billing->currentBillableUsers($customer));
        $this->assertSame(1, $before);
    }

    public function test_it_holds_read_keys_only_and_never_the_rights_of_a_person(): void
    {
        $customer = $this->apiCustomer();
        // The row says System Owner, QA and Wiki approver — none of it counts for an integration.
        $identity = $this->integrationIdentity($customer, [
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'is_qa' => true,
            'is_wiki_approver' => true,
        ]);
        $this->giveRole($identity, CustomerPermissionCatalog::all());
        $permissions = app(CustomerPermissionService::class);

        $this->assertSame(
            CustomerPermissionCatalog::integrationPermissions(),
            array_values(array_intersect(CustomerPermissionCatalog::integrationPermissions(), $permissions->effectivePermissions($identity))),
        );
        $this->assertSame([], array_values(array_diff($permissions->effectivePermissions($identity), CustomerPermissionCatalog::integrationPermissions())));

        foreach (CustomerPermissionCatalog::humanOnlyPermissions() as $key) {
            $this->assertFalse($permissions->has($identity, $key), $key);
        }

        $this->assertFalse($identity->isSystemOwner());
        $this->assertFalse($identity->isQa());
        $this->assertFalse($identity->isWikiApprover());

        // The anbud matrix, even where it would let «all» in.
        $customer->forceFill(['permission_settings' => array_fill_keys(array_keys($customer->resolvedPermissionSettings()), ['all'])])->save();
        $identity->setRelation('customer', $customer->fresh());

        $this->assertFalse($identity->canViewAllCasesViaSettings());
        $this->assertFalse($identity->canDecideBidRequirements());
        $this->assertFalse($identity->canApproveWikiClaims());
        $this->assertFalse($identity->canApproveWikiPages());
        $this->assertFalse($identity->canBeEnterpriseWikiDocumentOwner());
        $this->assertFalse($identity->canManageCustomerUsers());
        $this->assertFalse(app(CustomerContext::class)->canCreateCustomerDepartments($identity));
    }

    public function test_only_the_integrations_own_administration_reads_past_the_scope(): void
    {
        $allowed = [
            'app/Models/Scopes/HumanUsersScope.php',
            'app/Models/User.php',
            'app/Models/ApiClientCustomerGrant.php',
        ];
        $found = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($source, 'HumanUsersScope') || str_contains($source, 'withoutGlobalScopes(')) {
                $relative = str_replace(base_path().'/', '', $file->getPathname());

                if (str_contains($source, 'withoutGlobalScopes(') && str_contains($source, 'User')) {
                    $found[] = $relative.' (withoutGlobalScopes)';
                } elseif (! in_array($relative, $allowed, true)) {
                    $found[] = $relative;
                }
            }
        }

        $this->assertSame([], $found, 'Only the integration administration may read integration identities.');
    }

    private function person(Customer $customer, string $role = User::ROLE_USER, string $bidRole = User::BID_ROLE_CONTRIBUTOR): User
    {
        return User::query()->create([
            'name' => 'Person '.Str::upper(Str::random(4)),
            'email' => 'person-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
