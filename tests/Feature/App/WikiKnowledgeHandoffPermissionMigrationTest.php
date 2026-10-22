<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Splitting wiki.knowledge.handoff out of wiki.source.manage takes nothing from anybody: every role
 * that could hand knowledge over keeps it, and no other role gains it.
 */
class WikiKnowledgeHandoffPermissionMigrationTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
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

    public function test_source_managers_keep_handing_knowledge_over_and_nobody_else_gains_it(): void
    {
        $customer = $this->customer();
        $sourceManager = $this->role($customer, [CustomerPermissionCatalog::WIKI_VIEW, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE]);
        $reader = $this->role($customer, [CustomerPermissionCatalog::WIKI_VIEW]);
        $alreadyBoth = $this->role($customer, [CustomerPermissionCatalog::WIKI_SOURCE_MANAGE, CustomerPermissionCatalog::WIKI_KNOWLEDGE_HANDOFF]);

        $migration = require database_path('migrations/2026_10_22_000002_grant_wiki_knowledge_handoff_to_source_managers.php');
        $migration->up();
        $migration->up();

        $this->assertContains(CustomerPermissionCatalog::WIKI_KNOWLEDGE_HANDOFF, $sourceManager->fresh()->permissionKeys());
        $this->assertNotContains(CustomerPermissionCatalog::WIKI_KNOWLEDGE_HANDOFF, $reader->fresh()->permissionKeys());
        $this->assertSame(1, DB::table('customer_role_permissions')
            ->where('customer_role_id', $alreadyBoth->id)
            ->where('permission_key', CustomerPermissionCatalog::WIKI_KNOWLEDGE_HANDOFF)
            ->count(), 'running it again adds nothing twice');
    }

    /** @param list<string> $permissions */
    private function role(Customer $customer, array $permissions): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);
        $role->syncPermissions($permissions);

        return $role;
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Rollemigrering AS',
            'slug' => 'rollemigrering-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
