<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The one-way door in 2026_10_01_000007_retire_quality_page_classifications.
 *
 * That migration drops the V1 tables, so whatever it fails to carry across is gone for good. This
 * test is the proof that it carries everything: it recreates the old tables through the migration's
 * own down(), fills them with a classification pair and the relation between them, runs up(), and
 * checks that each old row is still readable as the new thing it became — the item, the link back
 * to the page it was classified on, and the edge between the two items.
 *
 * Everything happens inside the test transaction, including the DDL, so the schema is restored on
 * rollback and later tests see the migrated database they expect.
 */
class QualityPageClassificationBackfillTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const MIGRATION_PATH = 'database/migrations/2026_10_01_000007_retire_quality_page_classifications.php';

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

    public function test_backfill_turns_classifications_and_relations_into_quality_items(): void
    {
        $migration = $this->migration();
        $migration->down();

        $customer = $this->customer();
        $classifier = $this->user($customer, 'classifier');
        $pageOwner = $this->user($customer, 'page-owner');

        $policyPage = $this->page($customer, 'Innkjøpspolicy', $pageOwner);
        $processPage = $this->page($customer, 'Innkjøpsprosess', null);

        $classifiedAt = now()->subMonths(3)->startOfSecond();

        $this->classify($customer, $policyPage, 'policy', 'P-03', $classifier, $classifiedAt);
        $this->classify($customer, $processPage, 'process', null, $classifier, $classifiedAt);

        DB::table('quality_relations')->insert([
            'customer_id' => $customer->id,
            'from_page_id' => $policyPage->id,
            'to_page_id' => $processPage->id,
            'relation_type' => 'governs',
            'source' => 'manual',
            'created_by_user_id' => $classifier->id,
            'created_at' => $classifiedAt,
            'updated_at' => $classifiedAt,
        ]);

        $migration->up();

        // The old model is gone, not dormant: two answers to "what are the styrende dokumenter"
        // would be one too many.
        $this->assertFalse(Schema::hasTable('quality_page_classifications'));
        $this->assertFalse(Schema::hasTable('quality_relations'));
        $this->assertFalse(Schema::hasTable('quality_process_definitions'));

        $items = DB::table('quality_items')
            ->where('customer_id', $customer->id)
            ->orderBy('id')
            ->get()
            ->keyBy('quality_type');

        $this->assertCount(2, $items);

        $policyItem = $items['policy'];
        $this->assertSame('Innkjøpspolicy', $policyItem->title);
        $this->assertSame('P-03', $policyItem->code);
        $this->assertSame('active', $policyItem->status);
        // The page's owner was V1's only notion of a document owner, so it carries over as a
        // starting point rather than being dropped.
        $this->assertSame($pageOwner->id, $policyItem->owner_user_id);
        $this->assertSame($classifier->id, $policyItem->created_by_user_id);
        $this->assertSame(
            $classifiedAt->toDateTimeString(),
            $this->asDateTimeString($policyItem->created_at),
        );

        $processItem = $items['process'];
        $this->assertSame('Innkjøpsprosess', $processItem->title);
        $this->assertNull($processItem->code);
        $this->assertNull($processItem->owner_user_id);

        // Each classification becomes an explicit link back to the page it was made on — the
        // relationship the classification was standing in for.
        $links = DB::table('quality_item_wiki_links')
            ->where('customer_id', $customer->id)
            ->orderBy('id')
            ->get()
            ->keyBy('quality_item_id');

        $this->assertCount(2, $links);

        $policyLink = $links[$policyItem->id];
        $this->assertSame($policyPage->id, $policyLink->enterprise_wiki_page_id);
        $this->assertSame('documents', $policyLink->link_type);
        $this->assertSame('migrated', $policyLink->source);
        $this->assertSame($classifier->id, $policyLink->created_by_user_id);

        $this->assertSame($processPage->id, $links[$processItem->id]->enterprise_wiki_page_id);

        // The edge moves off the pages and onto the items the pages produced.
        $relations = DB::table('quality_item_relations')
            ->where('customer_id', $customer->id)
            ->get();

        $this->assertCount(1, $relations);

        $relation = $relations->first();
        $this->assertSame($policyItem->id, $relation->from_item_id);
        $this->assertSame($processItem->id, $relation->to_item_id);
        $this->assertSame('governs', $relation->relation_type);
        $this->assertSame('migrated', $relation->source);
        $this->assertSame($classifier->id, $relation->created_by_user_id);
        $this->assertSame(
            $classifiedAt->toDateTimeString(),
            $this->asDateTimeString($relation->created_at),
        );
    }

    /**
     * A plain require(): PHP re-executes the file's `new class {...}` expression on every call, so
     * this is a fresh instance even though Laravel's migrator already requireOnce'd the same path.
     */
    private function migration(): object
    {
        return require base_path(self::MIGRATION_PATH);
    }

    private function classify(
        Customer $customer,
        EnterpriseWikiPage $page,
        string $type,
        ?string $code,
        User $classifier,
        Carbon $classifiedAt,
    ): void {
        DB::table('quality_page_classifications')->insert([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'quality_type' => $type,
            'quality_code' => $code,
            'source' => 'manual',
            'classified_by_user_id' => $classifier->id,
            'classified_at' => $classifiedAt,
            'created_at' => $classifiedAt,
            'updated_at' => $classifiedAt,
        ]);
    }

    private function customer(): Customer
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
            'name' => 'Backfill Test AS',
            'slug' => 'backfill-test-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }

    private function user(Customer $customer, string $label): User
    {
        return User::query()->create([
            'name' => ucfirst($label),
            'email' => $label.'-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function page(Customer $customer, string $title, ?User $owner): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(8)),
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'owner_user_id' => $owner?->id,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function asDateTimeString(string $value): string
    {
        return Carbon::parse($value)->toDateTimeString();
    }
}
