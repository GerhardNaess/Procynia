<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\KnowledgeDocumentCategory;
use App\Models\KnowledgeDocumentTopic;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeDocumentCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_categories_are_customer_scoped_and_support_auditing_relations(): void
    {
        $firstCustomer = $this->createCustomer('Catalog Customer One AS');
        $secondCustomer = $this->createCustomer('Catalog Customer Two AS');
        $creator = $this->createUser($firstCustomer);
        $updater = $this->createUser($firstCustomer);

        $laterCategory = KnowledgeDocumentCategory::query()->create([
            'customer_id' => $firstCustomer->id,
            'name' => 'Tjenestebeskrivelse',
            'description' => 'Kundens kategorisering.',
            'sort_order' => 20,
            'is_active' => true,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $updater->id,
        ]);

        $firstCategory = KnowledgeDocumentCategory::query()->create([
            'customer_id' => $firstCustomer->id,
            'name' => 'Administrasjon',
            'description' => 'Skal komme først alfabetisk.',
            'sort_order' => 5,
            'is_active' => true,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $updater->id,
        ]);

        $otherCustomerCategory = KnowledgeDocumentCategory::query()->create([
            'customer_id' => $secondCustomer->id,
            'name' => 'Tjenestebeskrivelse',
            'description' => 'Samme navn i annen kunde.',
            'sort_order' => 5,
            'is_active' => true,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ]);

        $customerCategories = KnowledgeDocumentCategory::query()
            ->forCustomer($firstCustomer)
            ->ordered()
            ->get();

        $this->assertCount(2, $customerCategories);
        $this->assertSame([$firstCategory->id, $laterCategory->id], $customerCategories->pluck('id')->all());
        $this->assertSame($firstCustomer->id, $laterCategory->customer?->id);
        $this->assertSame($creator->id, $laterCategory->createdBy?->id);
        $this->assertSame($updater->id, $laterCategory->updatedBy?->id);
        $this->assertSame($secondCustomer->id, $otherCustomerCategory->customer?->id);
    }

    public function test_document_categories_reject_duplicate_active_names_for_same_customer(): void
    {
        $customer = $this->createCustomer('Catalog Duplicate Customer AS');

        KnowledgeDocumentCategory::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Tjenestebeskrivelse',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        KnowledgeDocumentCategory::query()->create([
            'customer_id' => $customer->id,
            'name' => 'tjenestebeskrivelse',
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }

    public function test_document_categories_allow_reuse_after_soft_delete(): void
    {
        $customer = $this->createCustomer('Catalog Soft Delete Customer AS');

        $category = KnowledgeDocumentCategory::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Tjenestebeskrivelse',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $category->delete();

        $replacement = KnowledgeDocumentCategory::query()->create([
            'customer_id' => $customer->id,
            'name' => 'tjenestebeskrivelse',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->assertNotNull($replacement->id);
        $this->assertSoftDeleted('knowledge_document_categories', [
            'id' => $category->id,
        ]);
    }

    public function test_document_topics_are_customer_scoped_active_and_ordered(): void
    {
        $firstCustomer = $this->createCustomer('Topic Customer One AS');
        $secondCustomer = $this->createCustomer('Topic Customer Two AS');
        $creator = $this->createUser($firstCustomer);

        $laterTopic = KnowledgeDocumentTopic::query()->create([
            'customer_id' => $firstCustomer->id,
            'name' => 'Sikkerhet',
            'description' => 'Tema 1',
            'sort_order' => 10,
            'is_active' => true,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
        ]);

        $firstTopic = KnowledgeDocumentTopic::query()->create([
            'customer_id' => $firstCustomer->id,
            'name' => 'Applikasjonsdrift',
            'description' => 'Tema 2',
            'sort_order' => 5,
            'is_active' => true,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
        ]);

        KnowledgeDocumentTopic::query()->create([
            'customer_id' => $firstCustomer->id,
            'name' => 'Skjult tema',
            'description' => 'Inaktivt tema',
            'sort_order' => 1,
            'is_active' => false,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
        ]);

        KnowledgeDocumentTopic::query()->create([
            'customer_id' => $secondCustomer->id,
            'name' => 'Sikkerhet',
            'description' => 'Samme navn i annen kunde.',
            'sort_order' => 1,
            'is_active' => true,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ]);

        $orderedTopics = KnowledgeDocumentTopic::query()
            ->forCustomer($firstCustomer)
            ->active()
            ->ordered()
            ->get();

        $this->assertCount(2, $orderedTopics);
        $this->assertSame([$firstTopic->id, $laterTopic->id], $orderedTopics->pluck('id')->all());
        $this->assertSame($firstCustomer->id, $laterTopic->customer?->id);
        $this->assertSame($creator->id, $laterTopic->createdBy?->id);
        $this->assertSame($creator->id, $firstTopic->updatedBy?->id);
    }

    public function test_document_topics_reject_duplicate_active_names_for_same_customer(): void
    {
        $customer = $this->createCustomer('Topic Duplicate Customer AS');

        KnowledgeDocumentTopic::query()->create([
            'customer_id' => $customer->id,
            'name' => 'SLA',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        KnowledgeDocumentTopic::query()->create([
            'customer_id' => $customer->id,
            'name' => 'sla',
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }

    public function test_document_topics_allow_reuse_after_soft_delete(): void
    {
        $customer = $this->createCustomer('Topic Soft Delete Customer AS');

        $topic = KnowledgeDocumentTopic::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Support',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $topic->delete();

        $replacement = KnowledgeDocumentTopic::query()->create([
            'customer_id' => $customer->id,
            'name' => 'support',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->assertNotNull($replacement->id);
        $this->assertSoftDeleted('knowledge_document_topics', [
            'id' => $topic->id,
        ]);
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

    private function createUser(Customer $customer): User
    {
        return User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
    }
}
