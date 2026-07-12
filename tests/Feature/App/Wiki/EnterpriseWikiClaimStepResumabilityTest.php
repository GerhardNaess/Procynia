<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A step "not yet started", "partially done", and "fully done" must be distinguishable from
 * persisted state alone — row *existence* is not sufficient proof of completion when a page
 * can legitimately extract zero claims, or a claim can legitimately verify as unsupported
 * (never getting a source reference). These tests exercise the explicit checkpoints added for
 * that: EnterpriseWikiIngestRunPage.claims_extracted_at and EnterpriseWikiClaim.verified_at.
 */
class EnterpriseWikiClaimStepResumabilityTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Extraction: partial across pages within one run
    // =========================================================================

    public function test_partial_claim_extraction_resumes_only_the_unfinished_page_without_duplicating_claims(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        [$doneRow, $doneVersion] = $this->addPage($run, 'Ferdig side');
        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $doneRow->enterprise_wiki_page_id,
            'enterprise_wiki_page_version_id' => $doneVersion->id,
            'claim_text' => 'Allerede ekstrahert påstand.',
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);
        $doneRow->update(['claims_extracted_at' => now()]);

        [$pendingRow, $pendingVersion] = $this->addPage($run, 'Uferdig side');

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->once()
            ->withArgs(fn ($title) => $title === 'Uferdig side')
            ->andReturn(['claims' => [
                ['text' => 'Ny påstand 1', 'confidence' => 'high'],
                ['text' => 'Ny påstand 2', 'confidence' => 'medium'],
            ]]);

        $result = app(EnterpriseWikiExtractPageClaimsService::class)->extract($run->fresh());

        $this->assertSame(1, EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $doneVersion->id)->count());
        $this->assertSame(2, EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $pendingVersion->id)->count());
        $this->assertNotNull($pendingRow->fresh()->claims_extracted_at);
        $this->assertSame(1, $result['pages']);
        $this->assertSame(2, $result['claims']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_claim_extraction_records_checkpoint_even_when_ai_returns_zero_claims(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        [$row] = $this->addPage($run, 'Tom side');

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->once()
            ->andReturn(['claims' => []]);

        app(EnterpriseWikiExtractPageClaimsService::class)->extract($run->fresh());

        $this->assertNotNull($row->fresh()->claims_extracted_at);

        // A second pass must not call the AI client again — zero claims already means
        // "finished", not "not started".
        $this->mock(WikiPageClaimExtractionAiClient::class)->shouldNotReceive('extractClaims');

        $result = app(EnterpriseWikiExtractPageClaimsService::class)->extract($run->fresh());

        $this->assertSame(0, $result['pages']);
        $this->assertSame(1, $result['skipped']);
    }

    // =========================================================================
    // Verification: partial across claims within one page
    // =========================================================================

    public function test_partial_claim_verification_resumes_only_the_unverified_claim_without_duplicating_references(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        [$row, $version] = $this->addPage($run, 'Side med påstander');

        $verifiedClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $row->enterprise_wiki_page_id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Allerede verifisert påstand.',
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);
        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $verifiedClaim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $run->source_id,
            'source_label' => 'source.pdf',
            'excerpt' => 'Eksisterende utdrag.',
            'source_hash' => 'existinghash',
        ]);

        $unverifiedClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $row->enterprise_wiki_page_id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Uverifisert påstand.',
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_MEDIUM,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->withArgs(fn ($claimText) => $claimText === 'Uverifisert påstand.')
            ->andReturn(['supported' => true, 'excerpt' => 'Nytt utdrag.']);

        $result = app(EnterpriseWikiVerifyPageClaimsService::class)->verify($run->fresh());

        $this->assertSame(1, EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $verifiedClaim->id)->count());
        $this->assertSame(1, EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $unverifiedClaim->id)->count());
        $this->assertNotNull($unverifiedClaim->fresh()->verified_at);
        $this->assertSame(1, $result['claims']);
        $this->assertSame(1, $result['references']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_claim_verification_records_checkpoint_for_unsupported_claims_without_a_reference(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        [$row, $version] = $this->addPage($run, 'Side med uverifiserbar påstand');

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $row->enterprise_wiki_page_id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Påstand uten dekning.',
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_LOW,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn(['supported' => false, 'excerpt' => null]);

        $result = app(EnterpriseWikiVerifyPageClaimsService::class)->verify($run->fresh());

        $this->assertNotNull($claim->fresh()->verified_at);
        $this->assertSame(0, EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->count());
        $this->assertSame(1, $result['no_support']);

        // A second pass must not re-verify a claim already found unsupported — otherwise every
        // continuation retry would re-call AI for it indefinitely.
        $this->mock(WikiClaimVerificationAiClient::class)->shouldNotReceive('verifyClaim');

        $result = app(EnterpriseWikiVerifyPageClaimsService::class)->verify($run->fresh());

        $this->assertSame(0, $result['claims']);
        $this->assertSame(1, $result['skipped']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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

    private function createAppliedRun(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source text for claim step resumability tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    /**
     * @return array{0: EnterpriseWikiIngestRunPage, 1: EnterpriseWikiPageVersion}
     */
    private function addPage(EnterpriseWikiIngestRun $run, string $title): array
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $run->customer_id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $row = EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# {$title}\n\nInnhold.",
        ]);

        return [$row, $version];
    }
}
