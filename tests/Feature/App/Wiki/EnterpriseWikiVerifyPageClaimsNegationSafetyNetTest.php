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
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Run-39 regression: EnterpriseWikiVerifyPageClaimsService::applyDeterministicSafetyNet() used
 * to fall back to comparing a claim against the whole source document (up to 8000 chars) whenever
 * a page's current version had no content_blocks_json at all (block === null, e.g. a version
 * written by EnterpriseWikiLinkSemanticRepairService::writeNewCurrentVersion(), which never sets
 * content_blocks_json). Since a document of any real length almost always contains a negation
 * marker somewhere unrelated to a given claim, this produced a near-universal false
 * negation_mismatch — 64 claims on run 39 alone.
 */
class EnterpriseWikiVerifyPageClaimsNegationSafetyNetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No real OpenAI calls in any test.
        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->andReturn($this->supportedVerdict())
            ->byDefault();
    }

    public function test_claim_with_no_content_block_is_not_downgraded_by_an_unrelated_document_negation(): void
    {
        $customer = $this->createCustomer();

        // The source document contains a negation ("ikke") entirely unrelated to the claim below.
        $document = $this->createDocument(
            $customer,
            'Leverandøren bruker ITIL som styringsverktøy. Dagens arbeidsmåter er ikke tilstrekkelige uten '
            .'denne innføringen. ITIL sikrer forutsigbar håndtering av henvendelser og god sporbarhet.',
        );

        $run = $this->createRunApplied($customer, $document);
        $page = $this->createPage($customer, 'ITIL-side');

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $claimText = 'ITIL sikrer forutsigbar håndtering av henvendelser og god sporbarhet.';

        // No content_blocks_json at all — mirrors EnterpriseWikiLinkSemanticRepairService's
        // writeNewCurrentVersion(), which never sets it.
        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => "# ITIL-side\n\n{$claimText}",
            'generated_by_model' => 'deterministic/link-semantic-repair',
        ]);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'content_block_key' => '',
            'claim_text' => $claimText,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $claim->refresh();

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claim->content_origin);
        $this->assertNotSame(
            'negation_mismatch',
            $claim->review_metadata['deterministic_reason'] ?? null,
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function supportedVerdict(): array
    {
        return [
            'verdict' => 'supported',
            'same_meaning_across_languages' => true,
            'claim_language' => 'no',
            'source_language' => 'no',
            'supporting_source_element_keys' => [],
            'reason' => 'Claim matches the cited source excerpt.',
            'unsupported_parts' => '',
            'checks' => [
                'actor' => 'match',
                'action' => 'match',
                'object' => 'match',
                'modality' => 'match',
                'negation' => 'match',
                'numbers_and_units' => 'not_applicable',
                'time_and_date' => 'not_applicable',
                'scope' => 'match',
                'conditions_and_exceptions' => 'not_applicable',
                'subject_entity' => 'match',
            ],
        ];
    }

    private function createCustomer(string $name = 'Negation Safety Net AS'): Customer
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
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer, string $extractedText): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => $extractedText,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createRunApplied(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function createPage(Customer $customer, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }
}
