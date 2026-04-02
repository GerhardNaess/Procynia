<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Department;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerUserManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
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

    public function test_customer_admin_can_access_users_index(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->actingAs($context['admin'])->get('/app/users');

        $response->assertOk();
    }

    public function test_customer_admin_can_access_create_user_page(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->actingAs($context['admin'])->get('/app/users/create');

        $response->assertOk();
        $response->assertViewHas('page', function ($page): bool {
            $roleValues = collect(data_get($page, 'props.bidRoleOptions', []))
                ->pluck('value')
                ->all();

            return data_get($page, 'props.canEditRole') === true
                && data_get($page, 'props.canEditBidManagerScope') === true
                && $roleValues === User::BID_ROLES;
        });
    }

    public function test_customer_admin_can_access_edit_user_page(): void
    {
        $context = $this->customerAdminContext();
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($context['admin'])->get("/app/users/{$target->id}/edit");

        $response->assertOk();
    }

    public function test_customer_user_cannot_access_users_index(): void
    {
        $context = $this->customerUserContext();

        $response = $this->actingAs($context['user'])->get('/app/users');

        $response->assertForbidden();
    }

    public function test_customer_user_cannot_access_create_or_store_user_routes(): void
    {
        $context = $this->customerUserContext();

        $this->actingAs($context['user'])->get('/app/users/create')->assertForbidden();
        $this->postWithCsrf($context['user'], '/app/users', [
            'name' => 'Skal Ikke Virke',
            'email' => 'skal.ikke.virke@example.test',
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ])->assertForbidden();
    }

    public function test_customer_user_cannot_access_edit_route(): void
    {
        $context = $this->customerUserContext();
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $this->actingAs($context['user'])->get("/app/users/{$target->id}/edit")->assertForbidden();
    }

    public function test_bid_manager_create_page_hides_role_and_scope_controls(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $manager = $this->companyWideBidManager($customer);

        $response = $this->actingAs($manager)->get('/app/users/create');

        $response->assertOk();
        $response->assertViewHas('page', function ($page): bool {
            $roleValues = collect(data_get($page, 'props.bidRoleOptions', []))
                ->pluck('value')
                ->all();

            return data_get($page, 'props.canEditRole') === false
                && data_get($page, 'props.canEditBidManagerScope') === false
                && ! in_array(User::BID_ROLE_SYSTEM_OWNER, $roleValues, true)
                && ! in_array(User::BID_ROLE_BID_MANAGER, $roleValues, true);
        });
    }

    public function test_department_membership_does_not_grant_user_management_access(): void
    {
        $context = $this->customerUserContext();
        $department = $this->createDepartment($context['customer']->id, 'Salg');

        $context['user']->forceFill([
            'department_id' => $department->id,
        ])->save();
        $context['user']->departments()->sync([$department->id]);

        $this->actingAs($context['user'])->get('/app/users')->assertForbidden();
    }

    public function test_customer_admin_only_sees_users_from_the_same_customer(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');

        User::factory()->create([
            'name' => 'Synlig Bruker',
            'email' => 'synlig@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $primary['customer']->id,
            'is_active' => true,
        ]);

        User::factory()->create([
            'name' => 'Skjult Bruker',
            'email' => 'skjult@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $secondary['customer']->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($primary['admin'])->get('/app/users');

        $response->assertOk();
        $response->assertSee('Synlig Bruker');
        $response->assertDontSee('Skjult Bruker');
    }

    public function test_customer_admin_can_create_a_new_user_for_own_customer(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->postWithCsrf($context['admin'], '/app/users', [
            'name' => 'Ny Bruker',
            'email' => 'ny.bruker@example.test',
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ]);

        $response->assertRedirect('/app/users');
        $response->assertSessionHas('success', 'Brukeren ble opprettet.');

        $user = User::query()->where('email', 'ny.bruker@example.test')->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame($context['customer']->id, $user->customer_id);
        $this->assertSame(User::ROLE_CUSTOMER_ADMIN, $user->role);
        $this->assertSame(User::BID_ROLE_BID_MANAGER, $user->bid_role);
        $this->assertSame(User::BID_MANAGER_SCOPE_COMPANY, $user->bid_manager_scope);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('SecretPass123!', (string) $user->password));
    }

    public function test_customer_admin_can_create_a_user_with_explicit_bid_role(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->postWithCsrf($context['admin'], '/app/users', [
            'name' => 'Bid Manager User',
            'email' => 'bid.manager.user@example.test',
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ]);

        $response->assertRedirect('/app/users');
        $this->assertDatabaseHas('users', [
            'email' => 'bid.manager.user@example.test',
            'customer_id' => $context['customer']->id,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
        ]);
    }

    public function test_customer_admin_must_provide_bid_role_when_creating_user(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->postWithCsrf($context['admin'], '/app/users', [
            'name' => 'Default Bid Role',
            'email' => 'default.bid.role@example.test',
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ]);

        $response->assertSessionHasErrors('bid_role');
        $this->assertDatabaseMissing('users', [
            'email' => 'default.bid.role@example.test',
        ]);
    }

    public function test_customer_admin_can_assign_department_to_user_on_create(): void
    {
        $context = $this->customerAdminContext();
        $department = $this->createDepartment($context['customer']->id, 'Salg');

        $response = $this->postWithCsrf($context['admin'], '/app/users', [
            'name' => 'Bruker Med Avdeling',
            'email' => 'bruker.med.avdeling@example.test',
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'department_id' => $department->id,
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ]);

        $response->assertRedirect('/app/users');
        $this->assertDatabaseHas('users', [
            'email' => 'bruker.med.avdeling@example.test',
            'customer_id' => $context['customer']->id,
            'department_id' => $department->id,
        ]);
    }

    public function test_customer_admin_can_assign_multiple_departments_to_user_on_create(): void
    {
        $context = $this->customerAdminContext();
        $sales = $this->createDepartment($context['customer']->id, 'Salg');
        $delivery = $this->createDepartment($context['customer']->id, 'Leveranse');

        $response = $this->postWithCsrf($context['admin'], '/app/users', [
            'name' => 'Bruker Med Flere Avdelinger',
            'email' => 'bruker.med.flere.avdelinger@example.test',
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'department_ids' => [$sales->id, $delivery->id],
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ]);

        $response->assertRedirect('/app/users');

        $user = User::query()->where('email', 'bruker.med.flere.avdelinger@example.test')->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame($sales->id, $user->department_id);
        $this->assertDatabaseHas('department_user', [
            'department_id' => $sales->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('department_user', [
            'department_id' => $delivery->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_customer_admin_cannot_create_a_user_with_another_customer_id_from_payload(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');

        $response = $this->postWithCsrf($primary['admin'], '/app/users', [
            'name' => 'Ny Bruker',
            'email' => 'kunde.avvik@example.test',
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $secondary['customer']->id,
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ]);

        $response->assertSessionHasErrors('customer_id');
        $this->assertDatabaseMissing('users', [
            'email' => 'kunde.avvik@example.test',
        ]);
    }

    public function test_customer_admin_cannot_assign_department_from_another_customer(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');
        $foreignDepartment = $this->createDepartment($secondary['customer']->id, 'Fremmed avdeling');

        $response = $this->postWithCsrf($primary['admin'], '/app/users', [
            'name' => 'Feil Avdeling',
            'email' => 'feil.avdeling@example.test',
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'department_id' => $foreignDepartment->id,
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ]);

        $response->assertSessionHasErrors('department_id');
        $this->assertDatabaseMissing('users', [
            'email' => 'feil.avdeling@example.test',
        ]);
    }

    public function test_customer_admin_cannot_assign_inactive_department_to_new_user(): void
    {
        $context = $this->customerAdminContext();
        $department = $this->createDepartment($context['customer']->id, 'Inaktiv avdeling');
        $department->forceFill(['is_active' => false])->save();

        $response = $this->postWithCsrf($context['admin'], '/app/users', [
            'name' => 'Feil Inaktiv Avdeling',
            'email' => 'feil.inaktiv.avdeling@example.test',
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'department_ids' => [$department->id],
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ]);

        $response->assertSessionHasErrors('department_ids');
        $this->assertDatabaseMissing('users', [
            'email' => 'feil.inaktiv.avdeling@example.test',
        ]);
    }

    public function test_customer_admin_cannot_set_technical_role_through_customer_app_flow(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->postWithCsrf($context['admin'], '/app/users', [
            'name' => 'Feil Rolle',
            'email' => 'superadmin.forsok@example.test',
            'role' => User::ROLE_SUPER_ADMIN,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', [
            'email' => 'superadmin.forsok@example.test',
        ]);
    }

    public function test_created_user_gets_the_correct_allowed_role(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->postWithCsrf($context['admin'], '/app/users', [
            'name' => 'Vanlig Bruker',
            'email' => 'vanlig.bruker@example.test',
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ]);

        $response->assertRedirect('/app/users');
        $this->assertDatabaseHas('users', [
            'email' => 'vanlig.bruker@example.test',
            'customer_id' => $context['customer']->id,
            'role' => User::ROLE_USER,
        ]);
    }

    public function test_customer_admin_can_change_bid_role_from_contributor_to_bid_manager(): void
    {
        $context = $this->customerAdminContext();
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $response = $this->putWithCsrf($context['admin'], "/app/users/{$target->id}", [
            'name' => 'Oppgradert Bruker',
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
        ]);

        $response->assertRedirect('/app/users');
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'Oppgradert Bruker',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
        ]);
    }

    public function test_customer_admin_can_update_bid_role(): void
    {
        $context = $this->customerAdminContext();
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $response = $this->putWithCsrf($context['admin'], "/app/users/{$target->id}", [
            'name' => $target->name,
            'bid_role' => User::BID_ROLE_VIEWER,
        ]);

        $response->assertRedirect('/app/users');
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'bid_role' => User::BID_ROLE_VIEWER,
        ]);
    }

    public function test_customer_admin_must_provide_password_when_creating_user(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->postWithCsrf($context['admin'], '/app/users', [
            'name' => 'Mangler Passord',
            'email' => 'mangler.passord@example.test',
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', [
            'email' => 'mangler.passord@example.test',
        ]);
    }

    public function test_customer_admin_can_update_users_password(): void
    {
        $context = $this->customerAdminContext();
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'password' => 'OldPassword123!',
            'is_active' => true,
        ]);

        $response = $this->putWithCsrf($context['admin'], "/app/users/{$target->id}", [
            'name' => $target->name,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'password' => 'UpdatedPass456!',
            'password_confirmation' => 'UpdatedPass456!',
        ]);

        $response->assertRedirect('/app/users');
        $target->refresh();

        $this->assertTrue(Hash::check('UpdatedPass456!', (string) $target->password));
    }

    public function test_customer_admin_cannot_store_invalid_bid_role(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->postWithCsrf($context['admin'], '/app/users', [
            'name' => 'Invalid Bid Role',
            'email' => 'invalid.bid.role@example.test',
            'bid_role' => 'sales_lead',
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ]);

        $response->assertSessionHasErrors('bid_role');
        $this->assertDatabaseMissing('users', [
            'email' => 'invalid.bid.role@example.test',
        ]);
    }

    public function test_customer_admin_can_update_user_department_within_own_customer(): void
    {
        $context = $this->customerAdminContext();
        $department = $this->createDepartment($context['customer']->id, 'Leveranse');
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'department_id' => null,
            'is_active' => true,
        ]);

        $response = $this->putWithCsrf($context['admin'], "/app/users/{$target->id}", [
            'name' => 'Oppdatert Bruker',
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'department_id' => $department->id,
        ]);

        $response->assertRedirect('/app/users');
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'Oppdatert Bruker',
            'department_id' => $department->id,
        ]);
    }

    public function test_customer_admin_can_update_users_department_memberships(): void
    {
        $context = $this->customerAdminContext();
        $sales = $this->createDepartment($context['customer']->id, 'Salg');
        $delivery = $this->createDepartment($context['customer']->id, 'Leveranse');
        $support = $this->createDepartment($context['customer']->id, 'Support');
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'department_id' => $sales->id,
            'is_active' => true,
        ]);

        $target->departments()->attach([$sales->id, $delivery->id]);

        $response = $this->putWithCsrf($context['admin'], "/app/users/{$target->id}", [
            'name' => 'Oppdatert Medlemskap',
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'department_ids' => [$support->id],
        ]);

        $response->assertRedirect('/app/users');
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'Oppdatert Medlemskap',
            'department_id' => $support->id,
        ]);
        $this->assertDatabaseHas('department_user', [
            'department_id' => $support->id,
            'user_id' => $target->id,
        ]);
        $this->assertDatabaseMissing('department_user', [
            'department_id' => $sales->id,
            'user_id' => $target->id,
        ]);
        $this->assertDatabaseMissing('department_user', [
            'department_id' => $delivery->id,
            'user_id' => $target->id,
        ]);
    }

    public function test_user_create_and_edit_pages_only_list_departments_from_same_customer(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');
        $ownDepartment = $this->createDepartment($primary['customer']->id, 'Salg');
        $foreignDepartment = $this->createDepartment($secondary['customer']->id, 'Skjult avdeling');
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $primary['customer']->id,
            'department_id' => $ownDepartment->id,
            'is_active' => true,
        ]);

        $createResponse = $this->actingAs($primary['admin'])->get('/app/users/create');
        $editResponse = $this->actingAs($primary['admin'])->get("/app/users/{$target->id}/edit");

        $createResponse->assertOk();
        $createResponse->assertSee('Salg');
        $createResponse->assertDontSee('Skjult avdeling');
        $editResponse->assertOk();
        $editResponse->assertSee('Salg');
        $editResponse->assertDontSee('Skjult avdeling');
    }

    public function test_role_mapping_works_correctly_from_bid_manager_to_contributor(): void
    {
        $context = $this->customerAdminContext();
        $otherAdmin = User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $response = $this->putWithCsrf($context['admin'], "/app/users/{$otherAdmin->id}", [
            'name' => $otherAdmin->name,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
        ]);

        $response->assertRedirect('/app/users');
        $this->assertDatabaseHas('users', [
            'id' => $otherAdmin->id,
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
        ]);
    }

    public function test_customer_admin_cannot_assign_technical_role_via_update(): void
    {
        $context = $this->customerAdminContext();
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $response = $this->putWithCsrf($context['admin'], "/app/users/{$target->id}", [
            'name' => $target->name,
            'role' => User::ROLE_SUPER_ADMIN,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
        ]);

        $response->assertSessionHasErrors(['role']);
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role' => User::ROLE_USER,
        ]);
    }

    public function test_customer_admin_cannot_edit_user_from_another_customer(): void
    {
        $primary = $this->customerAdminContext('Procynia AS');
        $secondary = $this->customerAdminContext('Annen Kunde AS');
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $secondary['customer']->id,
            'is_active' => true,
        ]);

        $this->actingAs($primary['admin'])->get("/app/users/{$target->id}/edit")->assertNotFound();
        $this->putWithCsrf($primary['admin'], "/app/users/{$target->id}", [
            'name' => 'Skal Ikke Virke',
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
        ])->assertNotFound();
        $this->patchWithCsrf($primary['admin'], "/app/users/{$target->id}/toggle-active")->assertNotFound();
    }

    public function test_customer_admin_cannot_deactivate_self(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->patchWithCsrf($context['admin'], "/app/users/{$context['admin']->id}/toggle-active");

        $response->assertSessionHas('error', 'Du kan ikke deaktivere din egen bruker.');
        $this->assertDatabaseHas('users', [
            'id' => $context['admin']->id,
            'is_active' => true,
        ]);
    }

    public function test_customer_admin_cannot_remove_last_bid_manager(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->putWithCsrf($context['admin'], "/app/users/{$context['admin']->id}", [
            'name' => $context['admin']->name,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
        ]);

        $response->assertSessionHasErrors('bid_role');
        $this->assertDatabaseHas('users', [
            'id' => $context['admin']->id,
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
        ]);
    }

    public function test_customer_admin_can_deactivate_another_user(): void
    {
        $context = $this->customerAdminContext();
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $response = $this->patchWithCsrf($context['admin'], "/app/users/{$target->id}/toggle-active");

        $response->assertSessionHas('success', 'Brukeren ble deaktivert.');
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'is_active' => false,
        ]);
    }

    public function test_customer_admin_can_reactivate_user(): void
    {
        $context = $this->customerAdminContext();
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => false,
        ]);

        $response = $this->patchWithCsrf($context['admin'], "/app/users/{$target->id}/toggle-active");

        $response->assertSessionHas('success', 'Brukeren ble aktivert.');
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'is_active' => true,
        ]);
    }

    public function test_customer_admin_can_create_department_scoped_bid_manager(): void
    {
        $context = $this->customerAdminContext();
        $sales = $this->createDepartment($context['customer']->id, 'Salg');
        $delivery = $this->createDepartment($context['customer']->id, 'Leveranse');

        $response = $this->postWithCsrf($context['admin'], '/app/users', [
            'name' => 'Avdelingsstyrt Bid-manager',
            'email' => 'scoped.bid.manager@example.test',
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_DEPARTMENTS,
            'managed_department_ids' => [$sales->id, $delivery->id],
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ]);

        $response->assertRedirect('/app/users');

        $user = User::query()->where('email', 'scoped.bid.manager@example.test')->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(User::BID_MANAGER_SCOPE_DEPARTMENTS, $user->bid_manager_scope);
        $this->assertDatabaseHas('bid_manager_departments', [
            'user_id' => $user->id,
            'department_id' => $sales->id,
        ]);
        $this->assertDatabaseHas('bid_manager_departments', [
            'user_id' => $user->id,
            'department_id' => $delivery->id,
        ]);
    }

    public function test_customer_admin_can_switch_bid_manager_scope_between_company_and_departments(): void
    {
        $context = $this->customerAdminContext();
        $sales = $this->createDepartment($context['customer']->id, 'Salg');
        $delivery = $this->createDepartment($context['customer']->id, 'Leveranse');
        $target = User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $this->putWithCsrf($context['admin'], "/app/users/{$target->id}", [
            'name' => $target->name,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_DEPARTMENTS,
            'managed_department_ids' => [$sales->id, $delivery->id],
        ])->assertRedirect('/app/users');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_DEPARTMENTS,
        ]);
        $this->assertDatabaseHas('bid_manager_departments', [
            'user_id' => $target->id,
            'department_id' => $sales->id,
        ]);

        $this->putWithCsrf($context['admin'], "/app/users/{$target->id}", [
            'name' => $target->name,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
        ])->assertRedirect('/app/users');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
        ]);
        $this->assertDatabaseMissing('bid_manager_departments', [
            'user_id' => $target->id,
            'department_id' => $sales->id,
        ]);
        $this->assertDatabaseMissing('bid_manager_departments', [
            'user_id' => $target->id,
            'department_id' => $delivery->id,
        ]);
    }

    public function test_department_scoped_bid_manager_can_only_manage_users_within_managed_departments(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $sales = $this->createDepartment($customer->id, 'Salg');
        $delivery = $this->createDepartment($customer->id, 'Leveranse');
        $manager = $this->departmentScopedBidManager($customer, [$sales->id]);

        $visibleUser = User::factory()->create([
            'name' => 'Synlig Bruker',
            'email' => 'synlig.scoped@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'department_id' => $sales->id,
            'is_active' => true,
        ]);
        $visibleUser->departments()->attach($sales->id);

        $hiddenUser = User::factory()->create([
            'name' => 'Skjult Bruker',
            'email' => 'skjult.scoped@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'department_id' => $delivery->id,
            'is_active' => true,
        ]);
        $hiddenUser->departments()->attach($delivery->id);

        $indexResponse = $this->actingAs($manager)->get('/app/users');

        $indexResponse->assertOk();
        $indexResponse->assertSee('Synlig Bruker');
        $indexResponse->assertDontSee('Skjult Bruker');
        $this->actingAs($manager)->get("/app/users/{$visibleUser->id}/edit")->assertOk();
        $this->actingAs($manager)->get("/app/users/{$hiddenUser->id}/edit")->assertNotFound();

        $this->postWithCsrf($manager, '/app/users', [
            'name' => 'Feil Scope',
            'email' => 'feil.scope@example.test',
            'department_ids' => [$delivery->id],
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ])->assertSessionHasErrors('department_ids');
    }

    public function test_department_scoped_bid_manager_can_expand_own_scope_again(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $sales = $this->createDepartment($customer->id, 'Salg');
        $delivery = $this->createDepartment($customer->id, 'Leveranse');
        $manager = $this->departmentScopedBidManager($customer, [$sales->id]);

        $manager->forceFill([
            'department_id' => $delivery->id,
        ])->save();
        $manager->departments()->sync([$delivery->id]);

        $indexResponse = $this->actingAs($manager)->get('/app/users');

        $indexResponse->assertOk();
        $indexResponse->assertSee($manager->name);
        $this->actingAs($manager)->get("/app/users/{$manager->id}/edit")->assertOk();

        $this->putWithCsrf($manager, "/app/users/{$manager->id}", [
            'name' => $manager->name,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
            'department_ids' => [$delivery->id],
        ])->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $manager->id,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_DEPARTMENTS,
        ]);
        $this->assertDatabaseHas('bid_manager_departments', [
            'user_id' => $manager->id,
            'department_id' => $sales->id,
        ]);
    }

    public function test_bid_manager_can_create_contributor_without_role_controls(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $manager = $this->companyWideBidManager($customer);

        $this->postWithCsrf($manager, '/app/users', [
            'name' => 'Ny Bidragsyter',
            'email' => 'ny.bidragsyter@example.test',
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ])->assertRedirect('/app/users');

        $this->assertDatabaseHas('users', [
            'email' => 'ny.bidragsyter@example.test',
            'customer_id' => $customer->id,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
        ]);
    }

    public function test_bid_manager_cannot_submit_protected_role_fields_when_creating_user(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $manager = $this->companyWideBidManager($customer);

        $this->postWithCsrf($manager, '/app/users', [
            'name' => 'Feil Bid-manager',
            'email' => 'feil.bid.manager@example.test',
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ])->assertForbidden();
    }

    public function test_bid_manager_cannot_change_existing_users_role(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $manager = $this->companyWideBidManager($customer);
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $this->putWithCsrf($manager, "/app/users/{$target->id}", [
            'name' => $target->name,
            'bid_role' => User::BID_ROLE_VIEWER,
        ])->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
        ]);
    }

    public function test_bid_manager_cannot_change_own_role(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $manager = $this->companyWideBidManager($customer);

        $this->putWithCsrf($manager, "/app/users/{$manager->id}", [
            'name' => $manager->name,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
        ])->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $manager->id,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
        ]);
    }

    public function test_bid_manager_cannot_change_existing_users_scope_fields(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $manager = $this->companyWideBidManager($customer);
        $sales = $this->createDepartment($customer->id, 'Salg');
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $this->putWithCsrf($manager, "/app/users/{$target->id}", [
            'name' => $target->name,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_DEPARTMENTS,
            'managed_department_ids' => [$sales->id],
        ])->assertForbidden();
    }

    private function customerAdminContext(string $customerName = 'Procynia AS'): array
    {
        $customer = $this->createCustomer($customerName);

        $admin = User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'bid_manager_scope' => null,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return [
            'customer' => $customer,
            'admin' => $admin,
        ];
    }

    private function departmentScopedBidManager(Customer $customer, array $managedDepartmentIds): User
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_DEPARTMENTS,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $user->managedDepartments()->sync($managedDepartmentIds);

        return $user;
    }

    private function companyWideBidManager(Customer $customer): User
    {
        return User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function customerUserContext(string $customerName = 'Procynia AS'): array
    {
        $customer = $this->createCustomer($customerName);

        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return [
            'customer' => $customer,
            'user' => $user,
        ];
    }

    private function createCustomer(string $name): Customer
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
            'is_active' => true,
        ]);
    }

    private function createDepartment(int $customerId, string $name): Department
    {
        return Department::query()->create([
            'customer_id' => $customerId,
            'name' => $name,
            'description' => null,
            'is_active' => true,
        ]);
    }

    private function postWithCsrf(User $user, string $uri, array $data = [])
    {
        return $this->actingAs($user)
            ->withSession(['_token' => 'test-token'])
            ->post($uri, ['_token' => 'test-token', ...$data]);
    }

    private function putWithCsrf(User $user, string $uri, array $data = [])
    {
        return $this->actingAs($user)
            ->withSession(['_token' => 'test-token'])
            ->put($uri, ['_token' => 'test-token', ...$data]);
    }

    private function patchWithCsrf(User $user, string $uri, array $data = [])
    {
        return $this->actingAs($user)
            ->withSession(['_token' => 'test-token'])
            ->patch($uri, ['_token' => 'test-token', ...$data]);
    }

    private function useProjectPostgresConnection(): void
    {
        $connectionName = 'feature_pgsql';

        config([
            "database.connections.{$connectionName}" => [
                'driver' => 'pgsql',
                'host' => $this->projectEnv('DB_HOST', '127.0.0.1'),
                'port' => $this->projectEnv('DB_PORT', '5432'),
                'database' => $this->projectEnv('DB_DATABASE', 'procynia'),
                'username' => $this->projectEnv('DB_USERNAME', 'gehard'),
                'password' => $this->projectEnv('DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'database.default' => $connectionName,
        ]);

        DB::purge($connectionName);
        DB::setDefaultConnection($connectionName);
        DB::reconnect($connectionName);
    }

    private function projectEnv(string $key, string $default): string
    {
        static $values = null;

        if (! is_array($values)) {
            $values = [];

            foreach (file(base_path('.env'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $trimmed = trim($line);

                if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
                    continue;
                }

                [$envKey, $envValue] = explode('=', $trimmed, 2);
                $values[trim($envKey)] = trim($envValue, " \t\n\r\0\x0B\"'");
            }
        }

        return (string) ($values[$key] ?? $default);
    }
}
