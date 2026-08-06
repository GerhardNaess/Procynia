<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageVersionClaimSyncService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiPageVersionClaimSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_sync_runs_skips_runs_waiting_for_owner_approval_and_processes_applied_runs(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $waitingRun = $this->createRun($customer, $document, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);
        $appliedRun = $this->createRun($customer, $document, EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING);

        $this->mock(EnterpriseWikiExtractPageClaimsService::class)
            ->shouldReceive('extract')
            ->once()
            ->withArgs(fn (EnterpriseWikiIngestRun $givenRun): bool => $givenRun->is($appliedRun))
            ->andReturn(['pages' => 0, 'claims' => 0, 'skipped' => 0, 'busy' => 0, 'capped_pages' => 0]);

        $this->mock(EnterpriseWikiVerifyPageClaimsService::class)
            ->shouldReceive('verify')
            ->once()
            ->withArgs(fn (EnterpriseWikiIngestRun $givenRun): bool => $givenRun->is($appliedRun))
            ->andReturn(['pages' => 0, 'claims' => 0, 'references' => 0, 'skipped' => 0, 'no_support' => 0, 'busy' => 0, 'reused' => 0]);

        $service = app(EnterpriseWikiPageVersionClaimSyncService::class);
        $service->syncRuns([$waitingRun->id, $appliedRun->id, $waitingRun->id]);

        $this->assertSame(0, EnterpriseWikiPageVersion::query()->count());
        $this->assertSame(0, EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_ingest_run_id', $waitingRun->id)->count());
    }

    public function test_sync_run_returns_noop_for_runs_waiting_for_owner_approval(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRun($customer, $document, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);
        $page = $this->createPage($customer, 'Waiting Approval Page');
        $version = $this->createVersion($page, 'Current content that should not be touched.');
        $pivot = $this->attachPageToRun($run, $page, $version);
        $versionCountBefore = EnterpriseWikiPageVersion::query()->count();
        $claimCountBefore = EnterpriseWikiClaim::query()->count();

        $this->mock(EnterpriseWikiExtractPageClaimsService::class)->shouldNotReceive('extract');
        $this->mock(EnterpriseWikiVerifyPageClaimsService::class)->shouldNotReceive('verify');

        $service = app(EnterpriseWikiPageVersionClaimSyncService::class);
        $result = $service->syncRun($run->fresh());

        $this->assertSame([
            'extraction' => [
                'pages' => 0,
                'claims' => 0,
                'skipped' => 0,
                'busy' => 0,
                'capped_pages' => 0,
            ],
            'verification' => [
                'pages' => 0,
                'claims' => 0,
                'references' => 0,
                'skipped' => 0,
                'no_support' => 0,
                'busy' => 0,
                'reused' => 0,
            ],
        ], $result);

        $this->assertSame($versionCountBefore, EnterpriseWikiPageVersion::query()->count());
        $this->assertSame($claimCountBefore, EnterpriseWikiClaim::query()->count());
        $this->assertNull($pivot->fresh()->claims_extracted_at);
    }

    public function test_sync_run_still_processes_an_applied_run(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRun($customer, $document, EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING);
        $page = $this->createPage($customer, 'Applied Run Page');
        $version = $this->createVersion($page, 'Current content for claim sync.');
        $this->attachPageToRun($run, $page, $version);

        $this->mock(EnterpriseWikiExtractPageClaimsService::class)
            ->shouldReceive('extract')
            ->once()
            ->withArgs(fn (EnterpriseWikiIngestRun $givenRun): bool => $givenRun->is($run))
            ->andReturn(['pages' => 0, 'claims' => 0, 'skipped' => 0, 'busy' => 0, 'capped_pages' => 0]);

        $this->mock(EnterpriseWikiVerifyPageClaimsService::class)
            ->shouldReceive('verify')
            ->once()
            ->withArgs(fn (EnterpriseWikiIngestRun $givenRun): bool => $givenRun->is($run))
            ->andReturn(['pages' => 0, 'claims' => 0, 'references' => 0, 'skipped' => 0, 'no_support' => 0, 'busy' => 0, 'reused' => 0]);

        $service = app(EnterpriseWikiPageVersionClaimSyncService::class);
        $result = $service->syncRun($run->fresh());

        $this->assertSame(0, $result['extraction']['claims']);
        $this->assertSame(0, $result['verification']['claims']);
    }

    private function createCustomer(string $name = 'Claim Sync Guard Test AS'): Customer
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
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source document text for claim sync guard tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createRun(Customer $customer, EnterpriseWikiDocument $document, string $status): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => $status,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            'qa_started_at' => now()->subMinute(),
            'qa_completed_at' => now(),
            'qa_attempt_count' => 1,
            'started_at' => now()->subMinutes(2),
        ]);
    }

    private function createPage(Customer $customer, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createVersion(EnterpriseWikiPage $page, string $markdown): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown,
            'generated_by_model' => 'gpt-5',
        ]);
    }

    private function attachPageToRun(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
    ): EnterpriseWikiIngestRunPage {
        return EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'generated_page_version_id' => $version->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'generation_started_at' => now()->subMinute(),
            'generation_completed_at' => now(),
        ]);
    }
}
