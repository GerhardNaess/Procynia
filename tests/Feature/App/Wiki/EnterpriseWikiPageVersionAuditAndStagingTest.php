<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiPageVersionAuditAndStagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ordinary_page_version_defaults_to_not_staged_with_nullable_creator(): void
    {
        $page = $this->createPage();

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Kundens medvirkning',
            'generated_by_model' => 'gpt-5',
        ])->refresh();

        $this->assertFalse($version->is_staged);
        $this->assertNull($version->created_by_user_id);
        $this->assertNull($version->createdBy);
        $this->assertTrue($version->is_current);
        $this->assertTrue($page->fresh()->currentVersion->is($version));
    }

    public function test_staged_manual_page_version_records_actor_without_becoming_current(): void
    {
        $page = $this->createPage();
        $actor = User::factory()->create(['role' => User::ROLE_USER]);

        $current = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Kundens medvirkning',
            'generated_by_model' => 'gpt-5',
        ]);

        $staged = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => false,
            'is_staged' => true,
            'content_markdown' => '# Kundens medvirkning oppdatert',
            'generated_by_model' => null,
            'created_by_user_id' => $actor->id,
        ])->refresh();

        $this->assertTrue($staged->is_staged);
        $this->assertFalse($staged->is_current);
        $this->assertNull($staged->generated_by_model);
        $this->assertSame($actor->id, $staged->created_by_user_id);
        $this->assertTrue($staged->createdBy->is($actor));
        $this->assertSame(
            $current->id,
            EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->where('is_current', true)
                ->sole()
                ->id,
        );
    }

    public function test_deleting_creator_nulls_page_version_creator(): void
    {
        $page = $this->createPage();
        $actor = User::factory()->create(['role' => User::ROLE_USER]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => false,
            'is_staged' => true,
            'content_markdown' => '# Kundens medvirkning',
            'generated_by_model' => null,
            'created_by_user_id' => $actor->id,
        ]);

        $actor->delete();

        $version->refresh();
        $this->assertNull($version->created_by_user_id);
        $this->assertNull($version->createdBy);
    }

    private function createPage(): EnterpriseWikiPage
    {
        $customer = $this->createCustomer();

        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'kundens-medvirkning-'.Str::lower(Str::random(6)),
            'title' => 'Kundens medvirkning',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
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

        return Customer::query()->create([
            'name' => 'Test AS',
            'slug' => 'test-as-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }
}
