<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A permission can be taken back, including from the last role that holds it.
 *
 * The access grid sends the full list of ticked roles on every change, so unticking the final box
 * sends an empty array. Laravel's 'required' rule rejects an empty array, which meant that request
 * failed: a permission could be widened but never narrowed all the way back. 'present' accepts it,
 * and System Owner is re-added unconditionally afterwards, so "nobody" still means "System Owner
 * only" rather than literally nobody.
 */
class CustomerPermissionClearingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_the_last_role_can_be_unticked(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $colleague = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($owner)->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_VIEW_ALL_CASES,
            'roles' => ['contributor'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($this->fresh($colleague)->canViewAllCasesViaSettings());

        $this->actingAs($owner)->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_VIEW_ALL_CASES,
            'roles' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($this->fresh($colleague)->canViewAllCasesViaSettings());
    }

    public function test_clearing_a_permission_leaves_system_owner_holding_it(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($owner)->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_VIEW_ALL_CASES,
            'roles' => [],
        ])->assertRedirect();

        $roles = $customer->fresh()->resolvedPermissionSettings()[Customer::PERMISSION_VIEW_ALL_CASES];

        $this->assertSame(['system_owner'], $roles);
        $this->assertTrue($this->fresh($owner)->canViewAllCasesViaSettings());
    }

    /** Clearing one permission must not disturb the others. */
    public function test_clearing_one_permission_leaves_the_rest_alone(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $before = $customer->resolvedPermissionSettings()[Customer::PERMISSION_CREATE_USERS];

        $this->actingAs($owner)->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_VIEW_ALL_CASES,
            'roles' => [],
        ])->assertRedirect();

        $this->assertSame(
            $before,
            $customer->fresh()->resolvedPermissionSettings()[Customer::PERMISSION_CREATE_USERS],
        );
    }

    public function test_the_roles_key_must_still_be_sent(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        // 'present' accepts an empty list, not a missing one — omitting it is a malformed request,
        // not an instruction to clear.
        $this->actingAs($owner)
            ->patch('/app/customer-environment/permissions', ['permission' => Customer::PERMISSION_VIEW_ALL_CASES])
            ->assertSessionHasErrors('roles');
    }

    private function fresh(User $user): User
    {
        return User::query()->with('customer')->findOrFail($user->id);
    }

    private function user(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Tilgang '.Str::random(4),
            'email' => Str::lower(Str::random(10)).'@permission-clearing.invalid',
            'password' => bcrypt('secret'),
            'role' => $bidRole === User::BID_ROLE_SYSTEM_OWNER ? User::ROLE_CUSTOMER_ADMIN : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Permission Clearing AS',
            'slug' => 'permission-clearing-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
