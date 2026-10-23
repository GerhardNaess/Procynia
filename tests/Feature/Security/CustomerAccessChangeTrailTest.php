<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\CustomerAccessChange;
use App\Models\CustomerRole;
use App\Models\Department;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Security\CustomerAccessChangeRecorder as Recorder;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * F4 (phase 0, step 18): every change to who may do what writes one row to customer_access_changes
 * with the person who made it — creating, changing, activating and deactivating a person; creating,
 * changing and deleting a customer role; the Tilganger matrix. Only access-relevant fields are kept,
 * a write that changes no access records nothing, and the trail cannot be rewritten.
 */
class CustomerAccessChangeTrailTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
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

    public function test_creating_changing_and_deactivating_a_person_is_recorded_with_its_actor(): void
    {
        $world = $this->world();

        $this->actingAs($world['owner'])->post('/app/users', [
            'name' => 'Ny Person',
            'email' => 'ny.'.Str::lower(Str::random(6)).'@example.test',
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'is_qa' => '1',
            'department_ids' => [(string) $world['department']->id],
            'password' => 'Hemmelig-passord-123',
            'password_confirmation' => 'Hemmelig-passord-123',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $created = User::query()->where('name', 'Ny Person')->sole();

        $row = $this->latest($world, CustomerAccessChange::SUBJECT_USER, $created->id);
        $this->assertSame(Recorder::USER_CREATED, $row->change_type);
        $this->assertSame($world['owner']->id, (int) $row->actor_user_id);
        $this->assertNull($row->before);
        $this->assertSame(User::BID_ROLE_CONTRIBUTOR, $row->after['bid_role']);
        $this->assertTrue($row->after['is_qa']);
        // Never personal data or secrets.
        foreach (['email', 'password', 'name'] as $field) {
            $this->assertArrayNotHasKey($field, $row->after);
        }

        // A new name changes no one's access: nothing is recorded.
        $count = $this->trailSize($world);
        $this->update($world['owner'], $created, ['name' => 'Nytt Navn', 'is_qa' => '1', 'bid_role' => User::BID_ROLE_CONTRIBUTOR, 'department_ids' => [(string) $world['department']->id]]);
        $this->assertSame($count, $this->trailSize($world));

        // A new role and a customer role are.
        $this->update($world['owner'], $created, ['bid_role' => User::BID_ROLE_BID_MANAGER, 'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY, 'customer_role_ids' => [$world['role']->id], 'department_ids' => [(string) $world['department']->id]]);
        $row = $this->latest($world, CustomerAccessChange::SUBJECT_USER, $created->id);
        $this->assertSame(Recorder::USER_ACCESS_CHANGED, $row->change_type);
        $this->assertSame(User::BID_ROLE_CONTRIBUTOR, $row->before['bid_role']);
        $this->assertSame(User::BID_ROLE_BID_MANAGER, $row->after['bid_role']);
        $this->assertTrue($row->before['is_qa']);
        $this->assertFalse($row->after['is_qa']);
        $this->assertSame([], $row->before['customer_role_ids']);
        $this->assertSame([$world['role']->id], $row->after['customer_role_ids']);

        $this->actingAs($world['owner'])->patch("/app/users/{$created->id}/toggle-active")->assertRedirect();
        $row = $this->latest($world, CustomerAccessChange::SUBJECT_USER, $created->id);
        $this->assertSame(Recorder::USER_DEACTIVATED, $row->change_type);
        $this->assertTrue($row->before['is_active']);
        $this->assertFalse($row->after['is_active']);

        $this->actingAs($world['owner'])->patch("/app/users/{$created->id}/toggle-active")->assertRedirect();
        $this->assertSame(Recorder::USER_ACTIVATED, $this->latest($world, CustomerAccessChange::SUBJECT_USER, $created->id)->change_type);
    }

    public function test_creating_changing_and_deleting_a_role_is_recorded(): void
    {
        $world = $this->world();

        $this->actingAs($world['owner'])->post('/app/customer-environment/roles', [
            'name' => 'Risikoleser',
            'permissions' => [CustomerPermissionCatalog::RISK_VIEW],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $role = CustomerRole::query()->where('customer_id', $world['customer']->id)->where('name', 'Risikoleser')->sole();
        $created = $this->latest($world, CustomerAccessChange::SUBJECT_CUSTOMER_ROLE, $role->id);
        $this->assertSame(Recorder::ROLE_CREATED, $created->change_type);
        $this->assertSame([CustomerPermissionCatalog::RISK_VIEW], $created->after['permissions']);

        // The description alone grants nothing: not recorded.
        $count = $this->trailSize($world);
        $this->actingAs($world['owner'])->patch("/app/customer-environment/roles/{$role->id}", ['description' => 'Leser risiko.'])->assertRedirect();
        $this->assertSame($count, $this->trailSize($world));

        $this->actingAs($world['owner'])->patch("/app/customer-environment/roles/{$role->id}", [
            'permissions' => [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_ACCEPT],
        ])->assertRedirect();
        $changed = $this->latest($world, CustomerAccessChange::SUBJECT_CUSTOMER_ROLE, $role->id);
        $this->assertSame(Recorder::ROLE_CHANGED, $changed->change_type);
        $this->assertNotContains(CustomerPermissionCatalog::RISK_ACCEPT, $changed->before['permissions']);
        $this->assertContains(CustomerPermissionCatalog::RISK_ACCEPT, $changed->after['permissions']);

        $world['member']->customerRoles()->attach($role->id, ['customer_id' => $world['customer']->id]);
        $this->actingAs($world['owner'])->delete("/app/customer-environment/roles/{$role->id}")->assertRedirect();
        $deleted = $this->latest($world, CustomerAccessChange::SUBJECT_CUSTOMER_ROLE, $role->id);
        $this->assertSame(Recorder::ROLE_DELETED, $deleted->change_type);
        $this->assertSame([$world['member']->id], $deleted->before['user_ids']);
        $this->assertNull($deleted->after);
        $this->assertNull(CustomerRole::query()->find($role->id));
    }

    public function test_the_access_matrix_row_is_recorded_before_and_after(): void
    {
        $world = $this->world();

        $this->actingAs($world['owner'])->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_VIEW_ALL_CASES,
            'roles' => [User::BID_ROLE_BID_MANAGER],
        ])->assertRedirect();

        $row = $this->latest($world, CustomerAccessChange::SUBJECT_PERMISSION_SETTINGS, null);
        $this->assertSame(Recorder::PERMISSION_SETTINGS_CHANGED, $row->change_type);
        $this->assertSame([Customer::PERMISSION_VIEW_ALL_CASES => ['system_owner', 'bid_manager', 'contributor']], $row->before);
        $this->assertSame([Customer::PERMISSION_VIEW_ALL_CASES => ['system_owner', 'bid_manager']], $row->after);
    }

    /** One customer's changes are never recorded in, or visible from, another's trail. */
    public function test_the_trail_is_kept_per_customer(): void
    {
        $world = $this->world();
        $other = $this->world('Annen kunde AS');

        $this->update($world['owner'], $world['member'], ['bid_role' => User::BID_ROLE_VIEWER]);

        $this->assertSame(1, CustomerAccessChange::query()->where('customer_id', $world['customer']->id)->count());
        $this->assertSame(0, CustomerAccessChange::query()->where('customer_id', $other['customer']->id)->count());

        // Another customer's System Owner cannot change this customer's people at all.
        $this->actingAs($other['owner'])->put("/app/users/{$world['member']->id}", ['name' => 'X', 'bid_role' => User::BID_ROLE_SYSTEM_OWNER])->assertNotFound();
        $this->assertSame(1, CustomerAccessChange::query()->where('customer_id', $world['customer']->id)->count());
    }

    /** The trail cannot be rewritten or removed; a deleted actor is nulled; the customer takes it along. */
    public function test_the_trail_is_append_only(): void
    {
        $world = $this->world();
        $this->update($world['owner'], $world['member'], ['bid_role' => User::BID_ROLE_VIEWER]);
        $row = $this->latest($world, CustomerAccessChange::SUBJECT_USER, $world['member']->id);

        foreach ([
            'changing what was recorded' => fn () => DB::table('customer_access_changes')->where('id', $row->id)->update(['after' => json_encode(['bid_role' => 'system_owner'])]),
            'changing the actor' => fn () => DB::table('customer_access_changes')->where('id', $row->id)->update(['actor_user_id' => $world['member']->id]),
            'deleting a row' => fn () => DB::table('customer_access_changes')->where('id', $row->id)->delete(),
        ] as $what => $statement) {
            DB::beginTransaction();

            try {
                $statement();
                $this->fail("The database accepted {$what}");
            } catch (QueryException) {
                // refused
            } finally {
                DB::rollBack();
            }
        }

        $secondOwner = User::factory()->create(['role' => User::ROLE_CUSTOMER_ADMIN, 'bid_role' => User::BID_ROLE_SYSTEM_OWNER, 'customer_id' => $world['customer']->id, 'is_active' => true]);
        $this->update($secondOwner, $world['member'], ['bid_role' => User::BID_ROLE_CONTRIBUTOR]);
        $byOwner = $this->latest($world, CustomerAccessChange::SUBJECT_USER, $world['member']->id);
        $secondOwner->delete();
        $this->assertNull($byOwner->fresh()->actor_user_id);
        $this->assertSame(User::BID_ROLE_CONTRIBUTOR, $byOwner->fresh()->after['bid_role']);

        DB::table('customers')->where('id', $world['customer']->id)->delete();
        $this->assertSame(0, CustomerAccessChange::query()->where('customer_id', $world['customer']->id)->count());
    }

    /** @param array<string, mixed> $fields */
    private function update(User $actor, User $target, array $fields): void
    {
        $this->actingAs($actor)->put("/app/users/{$target->id}", array_merge([
            'name' => $target->name,
            'bid_role' => $target->bid_role,
        ], $fields))->assertRedirect()->assertSessionHasNoErrors();
    }

    private function latest(array $world, string $subjectType, ?int $subjectId): CustomerAccessChange
    {
        return CustomerAccessChange::query()
            ->where('customer_id', $world['customer']->id)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    private function trailSize(array $world): int
    {
        return CustomerAccessChange::query()->where('customer_id', $world['customer']->id)->count();
    }

    /** @return array{customer: Customer, owner: User, member: User, role: CustomerRole, department: Department} */
    private function world(string $name = 'Tilgangsspor AS'): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_MAX,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'included_users' => 20,
            'included_ai_credits' => 20,
        ]);

        $owner = User::factory()->create(['role' => User::ROLE_CUSTOMER_ADMIN, 'bid_role' => User::BID_ROLE_SYSTEM_OWNER, 'customer_id' => $customer->id, 'is_active' => true]);
        $member = User::factory()->create(['role' => User::ROLE_USER, 'bid_role' => User::BID_ROLE_CONTRIBUTOR, 'customer_id' => $customer->id, 'is_active' => true]);
        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => 'Kvalitetsleser', 'is_active' => true]);
        $department = Department::query()->create(['customer_id' => $customer->id, 'name' => 'Salg', 'is_active' => true]);

        return compact('customer', 'owner', 'member', 'role', 'department');
    }
}
