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
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
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
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    /** The block a source_based claim anchors to — what verification resolves evidence through. */
    private const SOURCE_BLOCK_KEY = 'block-0001';

    private const SOURCE_BLOCK_MARKDOWN = 'Kunden har en dokumentert hendelseshåndtering.';

    /** The document element that block cites, and that verification must cite back. */
    private const SOURCE_ELEMENT_KEY = 'paragraph-1';

    /**
     * The claim-candidate block. Deliberately unsupported_generated_content rather than
     * best_practice: both are offered to the extraction AI, but only best_practice has a
     * deterministic fallback that manufactures a claim when the AI returns none — which would
     * make "the AI legitimately returned zero claims" untestable.
     */
    private const CANDIDATE_BLOCK_KEY = 'block-0002';

    private const CANDIDATE_BLOCK_MARKDOWN = 'Prosessen dekker også avvikshåndtering.';

    // =========================================================================
    // Extraction: partial across pages within one run
    // =========================================================================

    public function test_partial_claim_extraction_resumes_only_the_unfinished_page_without_duplicating_claims(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        [$doneRow, $doneVersion] = $this->addPage($run, 'Ferdig side');
        $this->createAnchoredClaim($doneRow, $doneVersion, 'Allerede ekstrahert påstand.');
        $doneRow->update(['claims_extracted_at' => now()]);

        [$pendingRow, $pendingVersion] = $this->addPage($run, 'Uferdig side');

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->once()
            ->withArgs(fn ($title) => $title === 'Uferdig side')
            ->andReturn(['claims' => [
                ['text' => 'Ny påstand 1', 'confidence' => 'high', 'excerpt' => self::CANDIDATE_BLOCK_MARKDOWN],
                ['text' => 'Ny påstand 2', 'confidence' => 'medium', 'excerpt' => self::CANDIDATE_BLOCK_MARKDOWN],
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

        $verifiedClaim = $this->createAnchoredClaim($row, $version, 'Allerede verifisert påstand.', [
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

        $unverifiedClaim = $this->createAnchoredClaim($row, $version, 'Uverifisert påstand.', [
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_MEDIUM,
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->withArgs(fn ($claimText) => $claimText === 'Uverifisert påstand.')
            ->andReturn($this->verificationResult(supportingSourceElementKeys: [self::SOURCE_ELEMENT_KEY]));

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

        $claim = $this->createAnchoredClaim($row, $version, 'Påstand uten dekning.', [
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_LOW,
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn($this->verificationResult(verdict: 'not_supported', reason: 'No candidate excerpt supports this claim.'));

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

        $document = EnterpriseWikiDocument::query()->findOrFail($run->source_id);

        // Both claim steps are block-driven. Extraction only offers best_practice /
        // unsupported_generated_content blocks to the AI, and verification anchors a claim to its
        // own content block to find the document evidence behind it. A version carrying only
        // content_markdown therefore skips the AI call entirely in both steps — which would leave
        // every checkpoint assertion below proving nothing.
        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# {$title}\n\n".self::SOURCE_BLOCK_MARKDOWN."\n\n".self::CANDIDATE_BLOCK_MARKDOWN,
            'content_blocks_json' => [
                [
                    'block_key' => self::SOURCE_BLOCK_KEY,
                    'position' => 0,
                    'markdown' => self::SOURCE_BLOCK_MARKDOWN,
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_elements' => [[
                        'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                        'source_id' => $document->id,
                        'source_label' => $document->original_filename,
                        'source_hash' => $document->file_hash_sha256,
                        'document_version_hash' => $document->file_hash_sha256,
                        'source_element_key' => self::SOURCE_ELEMENT_KEY,
                        'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
                        'source_row_key' => null,
                        'source_excerpt' => $document->extracted_text,
                        'page_reference' => 'Avsnitt 1',
                    ]],
                    'best_practice_reason' => null,
                ],
                [
                    'block_key' => self::CANDIDATE_BLOCK_KEY,
                    'position' => 1,
                    'markdown' => self::CANDIDATE_BLOCK_MARKDOWN,
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                    'source_elements' => [],
                    'best_practice_reason' => null,
                ],
            ],
        ]);

        return [$row, $version];
    }

    /**
     * A claim anchored to the page's source block, so verification can resolve the document
     * evidence behind it. An unanchored claim is classified internal_error and reported as
     * "no support" without any AI call, which would hide the checkpoint behaviour under test.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createAnchoredClaim(
        EnterpriseWikiIngestRunPage $row,
        EnterpriseWikiPageVersion $version,
        string $text,
        array $overrides = [],
    ): EnterpriseWikiClaim {
        return EnterpriseWikiClaim::query()->create(array_merge([
            'enterprise_wiki_page_id' => $row->enterprise_wiki_page_id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'page_excerpt' => self::SOURCE_BLOCK_MARKDOWN,
            'content_block_key' => self::SOURCE_BLOCK_KEY,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ], $overrides));
    }
}
