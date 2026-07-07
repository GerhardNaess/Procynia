<?php

namespace Tests\Feature\Console;

use App\Models\Customer;
use App\Models\KnowledgeDocumentCategory;
use App\Models\KnowledgeDocumentTopic;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SeedDefaultKnowledgeCatalogsCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_creates_default_categories_and_topics(): void
    {
        $customer = $this->makeCustomer('Alpha');

        $this->artisan('knowledge:seed-default-catalogs')
            ->expectsOutputToContain('[KNOWLEDGE][CATALOG]')
            ->assertSuccessful();

        $this->assertSame(5, KnowledgeDocumentCategory::query()
            ->where('customer_id', $customer->id)->count());

        $this->assertSame(14, KnowledgeDocumentTopic::query()
            ->where('customer_id', $customer->id)->count());

        $this->assertDatabaseHas('knowledge_document_categories', [
            'customer_id' => $customer->id,
            'name' => 'Generelt',
            'is_active' => true,
            'deleted_at' => null,
        ]);
    }

    public function test_categories_are_linked_to_their_topics_via_pivot(): void
    {
        $customer = $this->makeCustomer('Beta');

        $this->artisan('knowledge:seed-default-catalogs')->assertSuccessful();

        $generelt = KnowledgeDocumentCategory::query()
            ->where('customer_id', $customer->id)
            ->where('name', 'Generelt')
            ->first();

        $this->assertNotNull($generelt);
        $this->assertSame(2, $generelt->topics()->count());

        $sikkerhet = KnowledgeDocumentCategory::query()
            ->where('customer_id', $customer->id)
            ->where('name', 'Sikkerhet og compliance')
            ->first();

        $this->assertNotNull($sikkerhet);
        $this->assertSame(3, $sikkerhet->topics()->count());
    }

    public function test_command_is_idempotent(): void
    {
        $customer = $this->makeCustomer('Gamma');

        $this->artisan('knowledge:seed-default-catalogs')->assertSuccessful();
        $this->artisan('knowledge:seed-default-catalogs')->assertSuccessful();

        $this->assertSame(5, KnowledgeDocumentCategory::query()
            ->where('customer_id', $customer->id)->count());

        $this->assertSame(14, KnowledgeDocumentTopic::query()
            ->where('customer_id', $customer->id)->count());

        // Verify no duplicate pivot links
        $allTopicIds = KnowledgeDocumentTopic::query()
            ->where('customer_id', $customer->id)
            ->pluck('id');
        $pivotCount = \Illuminate\Support\Facades\DB::table('knowledge_document_category_topic')
            ->whereIn('knowledge_document_topic_id', $allTopicIds)
            ->count();
        $this->assertSame(14, $pivotCount);
    }

    public function test_command_seeds_each_customer_independently(): void
    {
        $alpha = $this->makeCustomer('Delta');
        $beta = $this->makeCustomer('Epsilon');

        $this->artisan('knowledge:seed-default-catalogs')->assertSuccessful();

        $this->assertSame(5, KnowledgeDocumentCategory::query()->where('customer_id', $alpha->id)->count());
        $this->assertSame(5, KnowledgeDocumentCategory::query()->where('customer_id', $beta->id)->count());

        // Topics are customer-scoped — no cross-contamination
        $alphaTopics = KnowledgeDocumentTopic::query()->where('customer_id', $alpha->id)->count();
        $betaTopics = KnowledgeDocumentTopic::query()->where('customer_id', $beta->id)->count();
        $this->assertSame(14, $alphaTopics);
        $this->assertSame(14, $betaTopics);
    }

    public function test_customer_id_option_scopes_to_single_customer(): void
    {
        $target = $this->makeCustomer('Zeta');
        $other = $this->makeCustomer('Eta');

        $this->artisan('knowledge:seed-default-catalogs', ['--customer-id' => $target->id])
            ->assertSuccessful();

        $this->assertSame(5, KnowledgeDocumentCategory::query()->where('customer_id', $target->id)->count());
        $this->assertSame(0, KnowledgeDocumentCategory::query()->where('customer_id', $other->id)->count());
    }

    public function test_inactive_customers_are_skipped(): void
    {
        $inactive = $this->makeCustomer('Theta', active: false);

        $this->artisan('knowledge:seed-default-catalogs')->assertSuccessful();

        $this->assertSame(0, KnowledgeDocumentCategory::query()
            ->where('customer_id', $inactive->id)->count());
    }

    public function test_existing_categories_are_not_duplicated(): void
    {
        $customer = $this->makeCustomer('Iota');

        KnowledgeDocumentCategory::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Generelt',
            'sort_order' => 99,
            'is_active' => true,
        ]);

        $this->artisan('knowledge:seed-default-catalogs')->assertSuccessful();

        $this->assertSame(5, KnowledgeDocumentCategory::query()
            ->where('customer_id', $customer->id)->count());
    }

    public function test_case_insensitive_name_match_prevents_duplicates(): void
    {
        $customer = $this->makeCustomer('Kappa');

        KnowledgeDocumentCategory::query()->create([
            'customer_id' => $customer->id,
            'name' => 'GENERELT',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->artisan('knowledge:seed-default-catalogs')->assertSuccessful();

        $this->assertSame(5, KnowledgeDocumentCategory::query()
            ->where('customer_id', $customer->id)->count());

        $genereltCount = KnowledgeDocumentCategory::query()
            ->where('customer_id', $customer->id)
            ->whereRaw('LOWER(name) = ?', ['generelt'])
            ->count();
        $this->assertSame(1, $genereltCount);
    }

    private function makeCustomer(string $tag, bool $active = true): Customer
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
            'name' => "Catalog Test {$tag}",
            'slug' => 'catalog-test-' . strtolower($tag),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => $active,
        ]);
    }
}
