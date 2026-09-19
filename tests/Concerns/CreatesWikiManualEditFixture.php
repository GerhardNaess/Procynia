<?php

namespace Tests\Concerns;

use App\Models\Customer;
use App\Models\EnterpriseWikiCanonicalFact;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use Illuminate\Support\Str;

/**
 * The shared fixture for a page whose current version can be manually edited: an applied document
 * run, a three-block current version with real per-block provenance, and claims bound to those
 * blocks.
 *
 * Extracted from WikiManualMixedBlockEditControllerTest when general working-version editing
 * gained its own endpoint. Both paths must be exercised against the SAME fixture — they share one
 * service, and a fixture that drifted between them would let one path's tests pass on a shape the
 * other can no longer produce.
 *
 * Requires Tests\Concerns\CreatesEnterpriseWikiFixtures for verificationResult().
 */
trait CreatesWikiManualEditFixture
{
    protected function expectManualExtractionAndVerification(array $edits, array $fixture): void
    {
        $sourceKeyByBlock = [
            // block-0001 is the source_based block. Claim repair can never edit it, but ordinary
            // working-version editing can — so its source key belongs in this map too.
            'block-0001' => 'source-unchanged-1',
            'block-0002' => 'source-edited-1',
            'block-0003' => 'source-edited-2',
        ];

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaimsForManualMixedBlock')
            ->times(count($edits))
            ->andReturnUsing(function (
                string $pageTitle,
                string $pageType,
                string $blockMarkdown,
                string $contentBlockKey,
                array $sourceElements,
            ) use ($edits, $fixture, $sourceKeyByBlock): array {
                $this->assertSame($fixture['page']->title, $pageTitle);
                $this->assertSame(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, $pageType);
                $this->assertArrayHasKey($contentBlockKey, $edits);
                $this->assertSame($edits[$contentBlockKey], $blockMarkdown);
                $this->assertSame([$sourceKeyByBlock[$contentBlockKey]], array_column($sourceElements, 'key'));

                return ['claims' => [[
                    'text' => $blockMarkdown,
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                    'excerpt' => $blockMarkdown,
                    // Since "Implement source-based Wiki claim extraction" (ba41a58) extraction may
                    // not assert source grounding: persistManualMixedBlockClaims() skips a
                    // source_based claim outright, and validatedManualMixedBlockClaim() rejects
                    // source_element_keys on any other origin. A manual edit therefore yields
                    // generated content pending verification, and it is the verifyClaim mock below
                    // — supported, citing this block's element — that promotes it to source_based
                    // and gives it its source reference and canonical fact.
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                    'source_element_keys' => [],
                    'best_practice_reason' => null,
                    'conflict_note' => null,
                ]]];
            });

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->times(count($edits))
            ->andReturnUsing(function (
                string $claimText,
                array $sourceElements,
                string $fallbackSourceText,
                string $languageCode,
                ?string $blockMarkdown,
            ) use ($edits, $sourceKeyByBlock): array {
                $contentBlockKey = array_search($claimText, $edits, true);
                $this->assertIsString($contentBlockKey);
                $this->assertSame([$sourceKeyByBlock[$contentBlockKey]], array_column($sourceElements, 'key'));
                $this->assertSame('', $fallbackSourceText);
                $this->assertSame('no', $languageCode);
                $this->assertNull($blockMarkdown);

                return $this->verificationResult(
                    supportingSourceElementKeys: [$sourceKeyByBlock[$contentBlockKey]],
                    reason: 'Påstanden er støttet av kildereferansen.',
                );
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function createManualEditFixture(string $customerName = 'Manual Edit Controller AS'): array
    {
        $customer = $this->createCustomer($customerName);
        $document = $this->createDocument($customer);
        $run = $this->createRun($customer, $document);
        $page = $this->createPage($customer);
        $actor = User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'is_active' => true,
        ]);

        $blocks = [
            $this->block('block-0001', 'Uendret kildebasert tekst.', EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $document, 0, 'source-unchanged-1'),
            $this->block('block-0002', 'Kunden sikrer dokumentert kontroll.', 'mixed', $document, 1, 'source-edited-1'),
            $this->block('block-0003', 'Kunden beskriver gammel risiko.', 'mixed', $document, 2, 'source-edited-2'),
        ];
        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'is_staged' => false,
            'content_markdown' => implode("\n\n", array_column($blocks, 'markdown')),
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'gpt-5',
        ]);
        $pivot = EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'generated_page_version_id' => $version->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'claims_extracted_at' => now(),
        ]);

        $canonicalFact = EnterpriseWikiCanonicalFact::query()->create([
            'customer_id' => $customer->id,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => $document->file_hash_sha256,
            'source_element_keys' => ['source-unchanged-1'],
            'source_element_keys_hash' => hash('sha256', json_encode(['source-unchanged-1'], JSON_THROW_ON_ERROR)),
            'normalized_fingerprint' => hash('sha256', 'uendret-kildebasert-tekst'),
            'canonical_text' => 'Uendret kildebasert tekst.',
            'verification_status' => EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_SUPPORTED,
            'verification_reason' => 'Eksisterende verifisert claim.',
            'verified_at' => now(),
        ]);
        $unchangedClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Uendret kildebasert tekst.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'page_excerpt' => 'Uendret kildebasert tekst.',
            'content_block_key' => 'block-0001',
            'canonical_fact_id' => $canonicalFact->id,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            'verified_at' => now(),
        ]);
        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $unchangedClaim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_element_key' => 'source-unchanged-1',
            'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
            'source_label' => $document->original_filename,
            'excerpt' => 'Uendret kildebasert tekst.',
            'source_hash' => $document->file_hash_sha256,
        ]);
        $reviewClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Kunden sikrer dokumentert kontroll.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'page_excerpt' => 'Kunden sikrer dokumentert kontroll.',
            'content_block_key' => 'block-0002',
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);
        $secondReviewClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Kunden beskriver gammel risiko.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'page_excerpt' => 'Kunden beskriver gammel risiko.',
            'content_block_key' => 'block-0003',
            'position_order' => 2,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        return compact(
            'customer',
            'document',
            'run',
            'page',
            'actor',
            'blocks',
            'version',
            'pivot',
            'unchangedClaim',
            'reviewClaim',
            'secondReviewClaim',
        );
    }

    protected function createCustomer(string $name): Customer
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
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    protected function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Authoritative source document text.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    protected function createRun(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', 'enterprise_wiki_document:'.$document->id),
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
        ]);
    }

    protected function createPage(Customer $customer): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'manual-edit-'.Str::lower(Str::random(8)),
            'title' => 'Manual Edit Article',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    protected function block(
        string $blockKey,
        string $markdown,
        string $contentOrigin,
        EnterpriseWikiDocument $document,
        int $position,
        string $sourceElementKey,
    ): array {
        return [
            'block_key' => $blockKey,
            'position' => $position,
            'markdown' => $markdown,
            'content_origin' => $contentOrigin,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_hash' => $document->file_hash_sha256,
            'document_version_hash' => $document->file_hash_sha256,
            'source_element_key' => $sourceElementKey,
            'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
            'source_row_key' => null,
            'source_excerpt' => $markdown,
            'page_reference' => 'Avsnitt '.($position + 1),
            'source_elements' => [[
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_label' => $document->original_filename,
                'source_hash' => $document->file_hash_sha256,
                'document_version_hash' => $document->file_hash_sha256,
                'source_element_key' => $sourceElementKey,
                'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
                'source_row_key' => null,
                'source_excerpt' => $markdown,
                'page_reference' => 'Avsnitt '.($position + 1),
            ]],
            'best_practice_reason' => null,
            'link_intents' => [],
        ];
    }
}
