<?php

namespace Tests\Feature\App\Wiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimFindingExplainer;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * Plan §18, P0-2 through the real extraction → verification path: the AI verifier said
 * "supported, modality: match" for run 962's «bør» → «skal» blocks. The sentence-level check must
 * override that, record why, and turn it into the one deterministic finding the user sees and
 * publication waits for — while a faithful recommendation stays plain source_based content.
 */
class EnterpriseWikiNormativeStrengtheningVerificationTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    private const SOURCE_SENTENCE = 'Forbedringssløyfen bør måles som en egen prosess, ikke bare gjennom de operative responstidene.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(WikiPageClaimExtractionAiClient::class)->shouldNotReceive('extractClaims');
    }

    public function test_recommendation_turned_into_requirement_becomes_a_visible_blocking_finding(): void
    {
        $scenario = $this->scenario('Forbedringssløyfen skal måles som en egen prosess, ikke kun gjennom operative responstider.');

        $this->verifierSaysSupported();
        $this->extractAndVerify($scenario['run']);

        $claim = EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $scenario['version']->id)->sole();
        $explainer = app(EnterpriseWikiClaimFindingExplainer::class);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $claim->content_origin);
        $this->assertSame('normative_strengthening', $claim->review_metadata['deterministic_reason']);
        $this->assertSame('recommendation', $claim->review_metadata['normative_check']['source_class']);
        $this->assertSame('obligation', $claim->review_metadata['normative_check']['claim_class']);

        $this->assertTrue($explainer->isUserFacingAddition($claim));
        $this->assertTrue($explainer->blocksPublication($claim, $scenario['version']->fresh()));

        $finding = $explainer->explain($claim);
        $this->assertSame(__('procynia.wiki.claim_finding.normative_strengthening.title'), $finding['title']);
        $this->assertStringContainsString('bør måles', $finding['explanation']);
        $this->assertTrue($finding['suggested_blocking']);
    }

    public function test_a_faithful_recommendation_stays_source_based(): void
    {
        $scenario = $this->scenario('Det anbefales at forbedringssløyfen måles som en egen prosess, ikke bare gjennom responstidene.');

        $this->verifierSaysSupported();
        $this->extractAndVerify($scenario['run']);

        $claim = EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $scenario['version']->id)->sole();

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claim->content_origin);
        $this->assertNull($claim->review_metadata['deterministic_reason'] ?? null);
    }

    private function verifierSaysSupported(): void
    {
        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn($this->verificationResult(supportingSourceElementKeys: ['paragraph-21']));
    }

    private function extractAndVerify(EnterpriseWikiIngestRun $run): void
    {
        app(EnterpriseWikiExtractPageClaimsService::class)->extract($run->fresh());
        app(EnterpriseWikiVerifyPageClaimsService::class)->verify($run->fresh());
    }

    /**
     * @return array{run: EnterpriseWikiIngestRun, version: EnterpriseWikiPageVersion}
     */
    private function scenario(string $blockMarkdown): array
    {
        $customer = $this->createWikiCustomer('Normativ AS');

        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'fagnotat.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => self::SOURCE_SENTENCE,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);

        $page = $this->createWikiPage($customer, 'Forbedringssløyfe');

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $blockMarkdown,
            'content_blocks_json' => [[
                'block_key' => 'block-0001',
                'position' => 0,
                'markdown' => $blockMarkdown,
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_elements' => [[
                    'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                    'source_id' => $document->id,
                    'source_label' => 'fagnotat.docx',
                    'source_element_key' => 'paragraph-21',
                    'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
                    'source_excerpt' => self::SOURCE_SENTENCE,
                    'page_reference' => 'Avsnitt 21',
                ]],
            ]],
            'generated_by_model' => 'gpt-5',
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generated_page_version_id' => $version->id,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        return compact('run', 'version');
    }
}
