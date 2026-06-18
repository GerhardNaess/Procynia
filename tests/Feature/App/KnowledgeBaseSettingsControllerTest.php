<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\KnowledgeDocumentCategory;
use App\Models\KnowledgeDocumentTopic;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeBaseSettingsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_system_owner_can_access_knowledge_base_settings_with_scoped_catalogs(): void
    {
        $primary = $this->systemOwnerContext('Procynia AS');
        $secondary = $this->systemOwnerContext('Annen Kunde AS');

        KnowledgeDocumentCategory::query()->create([
            'customer_id' => $primary['customer']->id,
            'name' => 'Strategi',
            'description' => 'Synlig',
            'is_active' => true,
            'created_by_user_id' => $primary['owner']->id,
            'updated_by_user_id' => $primary['owner']->id,
        ]);

        KnowledgeDocumentCategory::query()->create([
            'customer_id' => $primary['customer']->id,
            'name' => 'Analyse',
            'description' => 'Skal komme først alfabetisk',
            'is_active' => true,
            'created_by_user_id' => $primary['owner']->id,
            'updated_by_user_id' => $primary['owner']->id,
        ]);

        KnowledgeDocumentCategory::query()->create([
            'customer_id' => $secondary['customer']->id,
            'name' => 'Fremmed kategori',
            'description' => null,
            'is_active' => true,
            'created_by_user_id' => $secondary['owner']->id,
            'updated_by_user_id' => $secondary['owner']->id,
        ]);

        KnowledgeDocumentTopic::query()->create([
            'customer_id' => $primary['customer']->id,
            'name' => 'Konkurransegrunnlag',
            'description' => 'Synlig',
            'is_active' => true,
            'created_by_user_id' => $primary['owner']->id,
            'updated_by_user_id' => $primary['owner']->id,
        ]);

        KnowledgeDocumentTopic::query()->create([
            'customer_id' => $primary['customer']->id,
            'name' => 'Anskaffelse',
            'description' => 'Skal komme først alfabetisk',
            'is_active' => true,
            'created_by_user_id' => $primary['owner']->id,
            'updated_by_user_id' => $primary['owner']->id,
        ]);

        KnowledgeDocumentTopic::query()->create([
            'customer_id' => $secondary['customer']->id,
            'name' => 'Fremmed tema',
            'description' => null,
            'is_active' => true,
            'created_by_user_id' => $secondary['owner']->id,
            'updated_by_user_id' => $secondary['owner']->id,
        ]);

        $response = $this->actingAs($primary['owner'])->get('/app/customer-environment/knowledge-base');

        $response->assertOk();
        $response->assertViewHas('page', function ($page): bool {
            return data_get($page, 'component') === 'App/CustomerEnvironment/KnowledgeBase'
                && collect(data_get($page, 'props.documentCategories', []))->pluck('name')->all() === ['Analyse', 'Strategi']
                && ! collect(data_get($page, 'props.documentCategories', []))->contains(fn (array $category): bool => $category['name'] === 'Fremmed kategori')
                && collect(data_get($page, 'props.documentTopics', []))->pluck('name')->all() === ['Anskaffelse', 'Konkurransegrunnlag']
                && ! collect(data_get($page, 'props.documentTopics', []))->contains(fn (array $topic): bool => $topic['name'] === 'Fremmed tema');
        });
    }

    public function test_contributor_cannot_access_knowledge_base_settings(): void
    {
        $context = $this->contributorContext();

        $this->actingAs($context['user'])->get('/app/customer-environment/knowledge-base')->assertForbidden();
    }

    public function test_system_owner_can_manage_categories_and_topics_for_own_customer(): void
    {
        $context = $this->systemOwnerContext();

        $categoryResponse = $this->actingAs($context['owner'])->post('/app/customer-environment/knowledge-base/categories', [
            'name' => 'Metode',
            'description' => 'Beskrivelse',
            'is_active' => true,
        ]);

        $categoryResponse->assertRedirect('/app/customer-environment/knowledge-base');

        $category = KnowledgeDocumentCategory::query()
            ->where('customer_id', $context['customer']->id)
            ->where('name', 'Metode')
            ->firstOrFail();

        $this->assertSame($context['owner']->id, $category->created_by_user_id);
        $this->assertSame($context['owner']->id, $category->updated_by_user_id);

        $topicResponse = $this->actingAs($context['owner'])->post('/app/customer-environment/knowledge-base/topics', [
            'name' => 'CV',
            'description' => 'Temagruppe',
            'is_active' => true,
        ]);

        $topicResponse->assertRedirect('/app/customer-environment/knowledge-base');

        $topic = KnowledgeDocumentTopic::query()
            ->where('customer_id', $context['customer']->id)
            ->where('name', 'CV')
            ->firstOrFail();

        $this->assertSame($context['owner']->id, $topic->created_by_user_id);
        $this->assertSame($context['owner']->id, $topic->updated_by_user_id);

        $this->actingAs($context['owner'])->patch(route('app.customer-environment.knowledge-base.categories.update', ['category' => $category->id]), [
            'name' => 'Metode oppdatert',
            'description' => 'Oppdatert beskrivelse',
            'is_active' => true,
        ])->assertRedirect('/app/customer-environment/knowledge-base');

        $this->actingAs($context['owner'])->patch(route('app.customer-environment.knowledge-base.topics.update', ['topic' => $topic->id]), [
            'name' => 'CV oppdatert',
            'description' => 'Oppdatert gruppe',
            'is_active' => true,
        ])->assertRedirect('/app/customer-environment/knowledge-base');

        $this->assertDatabaseHas('knowledge_document_categories', [
            'id' => $category->id,
            'name' => 'Metode oppdatert',
            'updated_by_user_id' => $context['owner']->id,
        ]);

        $this->assertDatabaseHas('knowledge_document_topics', [
            'id' => $topic->id,
            'name' => 'CV oppdatert',
            'updated_by_user_id' => $context['owner']->id,
        ]);

        $this->actingAs($context['owner'])->delete(route('app.customer-environment.knowledge-base.categories.destroy', ['category' => $category->id]))
            ->assertRedirect('/app/customer-environment/knowledge-base');
        $this->actingAs($context['owner'])->delete(route('app.customer-environment.knowledge-base.topics.destroy', ['topic' => $topic->id]))
            ->assertRedirect('/app/customer-environment/knowledge-base');

        $this->assertSoftDeleted('knowledge_document_categories', ['id' => $category->id]);
        $this->assertSoftDeleted('knowledge_document_topics', ['id' => $topic->id]);
    }

    public function test_system_owner_cannot_manage_foreign_customer_catalog_items(): void
    {
        $primary = $this->systemOwnerContext('Procynia AS');
        $secondary = $this->systemOwnerContext('Annen Kunde AS');

        $foreignCategory = KnowledgeDocumentCategory::query()->create([
            'customer_id' => $secondary['customer']->id,
            'name' => 'Fremmed kategori',
            'description' => null,
            'is_active' => true,
            'created_by_user_id' => $secondary['owner']->id,
            'updated_by_user_id' => $secondary['owner']->id,
        ]);

        $foreignTopic = KnowledgeDocumentTopic::query()->create([
            'customer_id' => $secondary['customer']->id,
            'name' => 'Fremmed tema',
            'description' => null,
            'is_active' => true,
            'created_by_user_id' => $secondary['owner']->id,
            'updated_by_user_id' => $secondary['owner']->id,
        ]);

        $this->actingAs($primary['owner'])
            ->patch(route('app.customer-environment.knowledge-base.categories.update', ['category' => $foreignCategory->id]), [
                'name' => 'Skal ikke virke',
                'description' => null,
                'is_active' => true,
            ])
            ->assertNotFound();

        $this->actingAs($primary['owner'])
            ->delete(route('app.customer-environment.knowledge-base.categories.destroy', ['category' => $foreignCategory->id]))
            ->assertNotFound();

        $this->actingAs($primary['owner'])
            ->patch(route('app.customer-environment.knowledge-base.topics.update', ['topic' => $foreignTopic->id]), [
                'name' => 'Skal ikke virke',
                'description' => null,
                'is_active' => true,
            ])
            ->assertNotFound();

        $this->actingAs($primary['owner'])
            ->delete(route('app.customer-environment.knowledge-base.topics.destroy', ['topic' => $foreignTopic->id]))
            ->assertNotFound();
    }

    private function systemOwnerContext(string $customerName = 'Procynia AS'): array
    {
        $customer = $this->createCustomer($customerName);

        $owner = User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'bid_manager_scope' => null,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return [
            'customer' => $customer,
            'owner' => $owner,
        ];
    }

    private function contributorContext(string $customerName = 'Procynia AS'): array
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

}
