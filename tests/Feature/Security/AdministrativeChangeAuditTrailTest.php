<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Characterisation of a known gap — docs/external-api-phase-0-plan.md, finding F4.
 *
 * Changes to who may do what — a user's anbudsrolle and QA flag, their customer roles, a role's
 * permissions, and the Tilganger matrix — are written without recording who made them. Phase 0
 * makes roles the route to human-only decisions, so this is the trail an auditor would ask for.
 *
 * The test does not look for a specific table. It finds every actor-reference column in the
 * schema and proves that none of them gains a row pointing at the System Owner who made the
 * changes. When an audit trail is introduced this test fails, and should be turned into an
 * assertion that the trail is written.
 */
class AdministrativeChangeAuditTrailTest extends TestCase
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

    public function test_role_qa_and_access_changes_record_no_acting_user(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $target = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Risikoansvarlig',
            'is_active' => true,
        ]);
        $role->syncPermissions([CustomerPermissionCatalog::RISK_VIEW]);

        $before = $this->actorReferences($owner);

        // Anbudsrolle, QA and customer roles: the "Rediger bruker" form.
        $this->actingAs($owner)->put("/app/users/{$target->id}", [
            'name' => $target->name,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
            'is_qa' => '1',
            'is_wiki_approver' => '1',
            'customer_role_ids' => [$role->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        // A role's permissions: Kundemiljø → Tilganger → Roller.
        $this->actingAs($owner)->patch("/app/customer-environment/roles/{$role->id}", [
            'permissions' => [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_ACCEPT],
        ])->assertRedirect()->assertSessionHasNoErrors();

        // The legacy Tilganger matrix.
        $this->actingAs($owner)->patch('/app/customer-environment/permissions', [
            'permission' => Customer::PERMISSION_APPROVE_WIKI_CLAIMS,
            'roles' => ['qa', User::BID_ROLE_CONTRIBUTOR],
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Every change took effect…
        $target->refresh();
        $this->assertSame(User::BID_ROLE_BID_MANAGER, $target->bid_role);
        $this->assertTrue((bool) $target->is_qa);
        $this->assertTrue((bool) $target->is_wiki_approver);
        $this->assertTrue($target->customerRoles()->whereKey($role->id)->exists());
        $this->assertContains(CustomerPermissionCatalog::RISK_ACCEPT, $role->fresh()->permissionKeys());
        $this->assertContains(
            User::BID_ROLE_CONTRIBUTOR,
            $customer->fresh()->resolvedPermissionSettings()[Customer::PERMISSION_APPROVE_WIKI_CLAIMS],
        );

        // …and not one actor-reference column anywhere names the person who made them.
        $this->assertSame($before, $this->actorReferences($owner), 'F4: no audit row records the acting System Owner');
    }

    /**
     * Rows per actor-reference column that point at $actor.
     *
     * An actor-reference column is any integer column named like the codebase names them:
     * *_by_user_id, *_by, actor_user_id, requested_by and the like.
     *
     * @return array<string, int>
     */
    private function actorReferences(User $actor): array
    {
        $columns = DB::select(<<<'SQL'
            SELECT table_name, column_name
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND data_type IN ('bigint', 'integer')
              AND (column_name LIKE '%\_by\_user\_id' OR column_name LIKE '%\_by' OR column_name = 'actor_user_id')
            ORDER BY table_name, column_name
        SQL);

        $this->assertNotEmpty($columns, 'the schema scan must find the actor columns other domains use');

        $counts = [];

        foreach ($columns as $column) {
            $counts[$column->table_name.'.'.$column->column_name] = DB::table($column->table_name)
                ->where($column->column_name, $actor->id)
                ->count();
        }

        return $counts;
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

        return Customer::query()->create([
            'name' => 'Revisjonsspor AS',
            'slug' => 'revisjonsspor-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_MAX,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'included_users' => 10,
            'included_ai_credits' => 20,
        ]);
    }

    private function createUser(Customer $customer, string $bidRole): User
    {
        return User::factory()->create([
            'role' => User::customerRoleForBidRole($bidRole),
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
