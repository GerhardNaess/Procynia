<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\BackupRecovery;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\UserResource;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The panel-level access gate on /admin — security finding F-03.
 *
 * Authorization in the Filament panel used to live entirely in the individual resources and pages,
 * each implementing canAccess()/canView() for itself. Every one of them does so correctly today, so
 * no customer data was ever exposed. But the panel door itself only checked is_active, which meant
 * the whole control was opt-in per file: a new resource added without canAccess() would have been
 * reachable by any active user, because Filament's default is to allow.
 *
 * These tests pin the door itself, so resource-level checks become the second layer rather than the
 * only one.
 *
 * The gate is isInternalAdmin(): super_admin with no customer_id. Filament is Procynia's internal
 * administration surface, not a customer portal. Customer administrators are a customer-scoped role
 * and administer their own users through the customer frontend (/app/users), which is tenant-scoped
 * by construction — see CustomerUserManagementTest.
 */
class AdminPanelAccessGateTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // A/D. Administrative roles keep their access
    // -----------------------------------------------------------------------

    public function test_an_active_internal_admin_can_open_the_panel(): void
    {
        $admin = $this->internalAdmin();

        $this->assertTrue($admin->canAccessPanel($this->panel()));
        $this->actingAs($admin)->get($this->dashboardUrl())->assertOk();
    }

    /**
     * The internal admin has no customer_id. That must not, by itself, block panel access — it is
     * precisely what distinguishes an internal operator from a customer user.
     */
    public function test_a_null_customer_id_does_not_block_a_legitimate_internal_admin(): void
    {
        $admin = $this->internalAdmin();

        $this->assertNull($admin->customer_id);
        $this->assertTrue($admin->canAccessPanel($this->panel()));
    }

    /**
     * The product decision this class encodes: a customer administrator is a customer-scoped role,
     * not an internal system administrator. Filament is closed to them.
     */
    public function test_an_active_customer_admin_cannot_open_the_panel(): void
    {
        $customerAdmin = $this->customerAdmin();

        $this->assertFalse(
            $customerAdmin->canAccessPanel($this->panel()),
            'Filament is the internal administration surface; customer admins belong in the customer frontend.',
        );

        $this->actingAs($customerAdmin)->get($this->dashboardUrl())->assertForbidden();
    }

    /**
     * A super_admin that carries a customer_id is not an internal administrator by Procynia's own
     * definition, and is refused at the door rather than only at each resource.
     */
    public function test_a_super_admin_with_a_customer_id_cannot_open_the_panel(): void
    {
        $hybrid = User::query()->create([
            'name' => 'Super Admin With Customer',
            'email' => 'super.with.customer+'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_SUPER_ADMIN,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $this->createCustomer()->id,
            'is_active' => true,
        ]);

        $this->assertFalse($hybrid->canAccessPanel($this->panel()));
        $this->actingAs($hybrid)->get($this->dashboardUrl())->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // B. Inactive accounts
    // -----------------------------------------------------------------------

    public function test_an_inactive_internal_admin_cannot_open_the_panel(): void
    {
        $admin = $this->internalAdmin(isActive: false);

        $this->assertFalse($admin->canAccessPanel($this->panel()));
        $this->actingAs($admin)->get($this->dashboardUrl())->assertForbidden();
    }

    public function test_an_inactive_customer_admin_cannot_open_the_panel(): void
    {
        $customerAdmin = $this->customerAdmin(isActive: false);

        $this->assertFalse($customerAdmin->canAccessPanel($this->panel()));
    }

    // -----------------------------------------------------------------------
    // C. The main regression: ordinary customer users are shut out at the door
    // -----------------------------------------------------------------------

    public function test_an_active_regular_customer_user_cannot_open_the_panel(): void
    {
        $user = $this->regularUser();

        $this->assertFalse(
            $user->canAccessPanel($this->panel()),
            'An ordinary customer user must not pass the panel gate.',
        );

        $this->actingAs($user)->get($this->dashboardUrl())->assertForbidden();
    }

    /**
     * Every non-administrative bid role is an ordinary customer user as far as the panel is
     * concerned. bid_role governs behaviour inside the customer frontend, never admin access.
     */
    #[DataProvider('nonAdministrativeBidRoles')]
    public function test_no_non_administrative_bid_role_can_open_the_panel(string $bidRole): void
    {
        $user = $this->regularUser($bidRole);

        $this->assertFalse($user->canAccessPanel($this->panel()));
        $this->actingAs($user)->get($this->dashboardUrl())->assertForbidden();
    }

    /** @return array<string, array{0: string}> */
    public static function nonAdministrativeBidRoles(): array
    {
        return [
            'system_owner' => [User::BID_ROLE_SYSTEM_OWNER],
            'bid_manager' => [User::BID_ROLE_BID_MANAGER],
            'contributor' => [User::BID_ROLE_CONTRIBUTOR],
            'viewer' => [User::BID_ROLE_VIEWER],
        ];
    }

    /**
     * A QA flag is an additive capability inside the customer frontend, not an admin grant.
     */
    public function test_the_qa_flag_does_not_grant_panel_access(): void
    {
        $user = $this->regularUser();
        $user->forceFill(['is_qa' => true])->save();

        $this->assertTrue($user->isQa());
        $this->assertFalse($user->canAccessPanel($this->panel()));
    }

    // -----------------------------------------------------------------------
    // E. Unauthenticated
    // -----------------------------------------------------------------------

    public function test_an_unauthenticated_visitor_is_not_given_the_panel(): void
    {
        $response = $this->get($this->dashboardUrl());

        $this->assertContains(
            $response->getStatusCode(),
            [302, 403],
            'An anonymous visitor must be redirected to login or refused, never served the panel.',
        );
        $response->assertDontSee('Backup');
    }

    // -----------------------------------------------------------------------
    // F. Defense in depth — the second layer is still doing work
    // -----------------------------------------------------------------------

    /**
     * Every resource-level check now refuses a customer admin too, so the two layers agree instead of
     * disagreeing. UserResource was the one that used to differ.
     */
    public function test_every_resource_level_check_also_refuses_a_customer_admin(): void
    {
        $customerAdmin = $this->customerAdmin();
        $this->actingAs($customerAdmin);

        $this->assertFalse(BackupRecovery::canAccess());
        $this->assertFalse(CustomerResource::canAccess());
        $this->assertFalse(
            UserResource::canAccess(),
            'UserResource must no longer be an alternative Filament route for customer admins.',
        );
    }

    /**
     * The gate is the first layer, not a replacement. Record-level rules still apply to an internal
     * admin who has passed the door — user deletion stays refused for everyone.
     */
    public function test_record_level_rules_still_apply_to_an_internal_admin_inside_the_panel(): void
    {
        $admin = $this->internalAdmin();
        $this->actingAs($admin);

        $this->assertTrue($admin->canAccessPanel($this->panel()), 'Precondition: passes the door.');
        $this->assertTrue(UserResource::canAccess(), 'And reaches the resource.');

        $this->assertFalse(
            UserResource::canDelete($this->regularUser()),
            'Record-level authorization is still evaluated beneath the panel gate.',
        );
    }

    public function test_an_internal_admin_still_passes_the_resource_level_checks(): void
    {
        $admin = $this->internalAdmin();
        $this->actingAs($admin);

        $this->assertTrue(BackupRecovery::canAccess());
        $this->assertTrue(CustomerResource::canAccess());
        $this->assertTrue(UserResource::canAccess());
    }

    // -----------------------------------------------------------------------
    // G. The point of the gate: a resource that forgot its own check
    // -----------------------------------------------------------------------

    /**
     * The regression this whole change exists to prevent.
     *
     * Filament allows access by default when a resource declares no canAccess(). Before the gate,
     * such a resource would have been reachable by any active user. Now the panel refuses them before
     * resource authorization is ever consulted — proven by the request never reaching a page at all.
     *
     * No fake resource is registered for this: panel access is the property being asserted, and a
     * refused request cannot reach any resource, declared check or not.
     */
    public function test_non_internal_users_are_stopped_before_resource_authorization_is_reached(): void
    {
        $urls = [
            $this->dashboardUrl(),
            UserResource::getUrl('index'),
            CustomerResource::getUrl('index'),
        ];

        foreach ([$this->regularUser(), $this->customerAdmin()] as $user) {
            foreach ($urls as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function panel(): Panel
    {
        return Filament::getPanel('admin');
    }

    private function dashboardUrl(): string
    {
        return route('filament.admin.pages.dashboard');
    }

    private function internalAdmin(bool $isActive = true): User
    {
        return User::query()->create([
            'name' => 'Procynia Internal Admin',
            'email' => 'internal.admin+'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_SUPER_ADMIN,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => null,
            'is_active' => $isActive,
        ]);
    }

    private function customerAdmin(bool $isActive = true): User
    {
        return User::query()->create([
            'name' => 'Kunde Admin',
            'email' => 'kunde.admin+'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $this->createCustomer()->id,
            'is_active' => $isActive,
        ]);
    }

    private function regularUser(string $bidRole = User::BID_ROLE_CONTRIBUTOR): User
    {
        return User::query()->create([
            'name' => 'Vanlig Kundebruker',
            'email' => 'vanlig+'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $this->createCustomer()->id,
            'is_active' => true,
        ]);
    }

    private function createCustomer(): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        $name = 'Panel Gate Test AS';

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }
}
