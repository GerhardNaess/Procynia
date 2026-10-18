<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * Arbeidspakke A5 (docs/enterprise-wiki-knowledge-integration-plan.md §5): a page without a
 * current version is shown as «Ufullstendig» with a short, user-facing reason — never as a
 * finished article, never with the raw generation error, and never deleted.
 */
class EnterpriseWikiIncompletePageTest extends TestCase
{
    use GrantsWikiPermissions;
    use RefreshDatabase;

    public function test_a_page_whose_generation_failed_on_a_plan_defect_explains_itself(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createViewer($customer);
        $page = $this->createPage($customer);
        $run = $this->planPage($customer, $page, EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED,
            '[EnterpriseWikiPlannedSectionEvidenceMissingException] planned_section_no_evidence before generation: Formål.');

        $incomplete = $this->incompleteProp($viewer, $page);

        $this->assertSame(['reason' => 'plan_defect', 'run_id' => $run->id], $incomplete);
        $this->assertStringNotContainsString('EnterpriseWikiPlannedSectionEvidenceMissingException', json_encode($incomplete));
    }

    public function test_a_page_still_being_generated_says_so(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createViewer($customer);
        $page = $this->createPage($customer);
        $run = $this->planPage($customer, $page, EnterpriseWikiIngestRunPage::GENERATION_STATUS_RUNNING);

        $this->assertSame(['reason' => 'in_progress', 'run_id' => $run->id], $this->incompleteProp($viewer, $page));
    }

    public function test_a_page_no_run_ever_planned_is_incomplete_without_a_reason(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createViewer($customer);
        $page = $this->createPage($customer);

        $this->assertSame(['reason' => null, 'run_id' => null], $this->incompleteProp($viewer, $page));
    }

    public function test_a_page_with_content_is_not_incomplete(): void
    {
        $customer = $this->createCustomer();
        $viewer = $this->createViewer($customer);
        $page = $this->createPage($customer);
        $this->planPage($customer, $page, EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Side',
        ]);

        $this->assertNull($this->incompleteProp($viewer, $page));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function incompleteProp(User $viewer, EnterpriseWikiPage $page): ?array
    {
        $response = $this->actingAs($viewer)->get("/app/wiki/{$page->slug}")->assertOk();

        return $response->viewData('page')['props']['page']['incomplete'];
    }

    private function planPage(Customer $customer, EnterpriseWikiPage $page, string $generationStatus, ?string $error = null): EnterpriseWikiIngestRun
    {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'kilde.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => $generationStatus,
            'generation_error' => $error,
        ]);

        return $run;
    }

    private function createPage(Customer $customer): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'side-'.Str::lower(Str::random(6)),
            'title' => 'Vurdering av betalingsbetingelser',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createCustomer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Ufullstendig AS',
            'slug' => 'ufullstendig-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createViewer(Customer $customer): User
    {
        return $this->grantWikiPermissions($customer, User::query()->create([
            'name' => 'Leser',
            'email' => Str::lower(Str::random(8)).'@test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]), [CustomerPermissionCatalog::WIKI_VIEW]);
    }
}
