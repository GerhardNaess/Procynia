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

    /**
     * Run 963, page 2586, claim 477: every cited excerpt supports the claim, but paragraph-22's
     * second sentence («… dersom det ikke skyldes …») — which the claim never restates — used to
     * decide the negation check for the whole claim.
     */
    public function test_a_negation_in_a_cited_sentence_the_claim_does_not_restate_no_longer_blocks_it(): void
    {
        $claim = $this->blockClaim(
            'Læringen omsettes til tiltak og varig forbedring: observerte teknikker føres i dekningsmatrisen, tiltak eiersettes og lukkes først når effekten er påvist gjennom klare akseptkriterier, og nye metoder kan utløse retrospektiv trusseljakt i hele miljøet. Forbedringssløyfen følges opp med indikatorer i tjenesterapporteringen, herunder andel gjennomganger innen frist, andel tiltak lukket med verifisert effekt, alder på åpne tiltak, gjentatte rotårsaker og utviklingen i deteksjons- og responsgap, vurdert i sammenheng med kvitteringstid og falske positiver.',
            [
                'paragraph-22' => 'Den siste indikatoren må ses sammen med kvitteringstid og andelen falske positiver. Et kortere responsgap er bare en forbedring dersom det ikke skyldes at flere reelle hendelser er klassifisert lavere.',
                'paragraph-23' => 'Indikatorene rapporteres i den ordinære tjenesterapporteringen.',
            ],
        );

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $claim->getAttribute('test_run_id')]);
        $claim = EnterpriseWikiClaim::query()->findOrFail($claim->id);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claim->content_origin);
        $this->assertNotSame('negation_mismatch', $claim->review_metadata['deterministic_reason'] ?? null);
    }

    public function test_a_real_negation_reversal_is_still_blocked_and_names_the_source_clause(): void
    {
        $claim = $this->blockClaim(
            'Leverandøren skal ikke utføre sikkerhetskopiering av kundens data.',
            [
                'paragraph-1' => 'Rapporten sendes månedlig.',
                'paragraph-2' => 'Leverandøren skal utføre sikkerhetskopiering av kundens data.',
            ],
        );

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $claim->getAttribute('test_run_id')]);
        $claim = EnterpriseWikiClaim::query()->findOrFail($claim->id);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $claim->content_origin);
        $this->assertSame('negation_mismatch', $claim->review_metadata['deterministic_reason'] ?? null);
        $this->assertSame('conflict', $claim->review_metadata['negation_check']['status'] ?? null);
        $this->assertSame(
            'Leverandøren skal utføre sikkerhetskopiering av kundens data.',
            $claim->review_metadata['negation_check']['source_clause'] ?? null,
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * A source_based block citing $elements, its claim, and a verifier that calls the claim
     * supported by every element.
     *
     * @param  array<string, string>  $elements
     */
    private function blockClaim(string $claimText, array $elements): EnterpriseWikiClaim
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, implode("\n\n", $elements));
        $run = $this->createRunApplied($customer, $document);
        $page = $this->createPage($customer, 'Forbedringssløyfe');

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $sourceElements = [];

        foreach ($elements as $key => $excerpt) {
            $sourceElements[] = [
                'source_type' => 'enterprise_wiki_document',
                'source_id' => $document->id,
                'source_label' => $document->original_filename,
                'source_element_key' => $key,
                'source_element_type' => 'paragraph',
                'source_excerpt' => $excerpt,
            ];
        }

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Forbedringssløyfe\n\n{$claimText}",
            'content_blocks_json' => [[
                'block_key' => 'block-0001',
                'position' => 0,
                'markdown' => $claimText,
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                'source_type' => 'enterprise_wiki_document',
                'source_id' => $document->id,
                'source_label' => $document->original_filename,
                'source_element_key' => array_key_first($elements),
                'source_excerpt' => reset($elements),
                'source_elements' => $sourceElements,
            ]],
            'generated_by_model' => 'gpt-5',
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->andReturn(['supporting_source_element_keys' => array_keys($elements)] + $this->supportedVerdict());

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'content_block_key' => 'block-0001',
            'claim_text' => $claimText,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);
        $claim->setAttribute('test_run_id', $run->id);

        return $claim;
    }

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
