<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\EnterpriseWikiMaintainerDecisionInconsistentException;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionApplyService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * End-to-end fix for the Wiki run-581 incident: "ITIL Incident Management" was never proposed as
 * a concept page even though the article and summary both pointed the reader onward to it, and no
 * existing page covered it. Only EnterpriseWikiMaintainerDecisionAiClient is mocked — the real
 * EnterpriseWikiMaintainerDecisionService (with its real
 * EnterpriseWikiMaintainerDecisionConsistencyValidator) and the real
 * EnterpriseWikiMaintainerDecisionApplyService both run, proving the fix through the actual
 * decision -> validation/repair -> apply pipeline, not just a unit in isolation.
 */
class EnterpriseWikiMaintainerDecisionConceptPageStabilityIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_dangling_reference_to_a_missing_concept_is_repaired_and_the_concept_page_is_applied(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $inconsistent = $this->baseDecision();
        $inconsistent['source_article']['related_page_guidance'] = [
            ['page_title' => 'ITIL Incident Management', 'relationship' => 'See the concept page for the detailed process.'],
        ];
        $inconsistent['source_summary']['related_page_guidance'] = [
            ['page_title' => 'ITIL Incident Management', 'relationship' => 'See the concept page for the detailed process.'],
        ];
        $inconsistent['concept_candidates'] = [[
            'name' => 'ITIL Incident Management',
            'concept_type' => 'framework process',
            'independent_reason' => 'A named ITIL process independent of the illustration.',
            'mentioned_context' => 'Named throughout the source document.',
            'existing_page_title' => null,
            'decision' => 'reference_only',
            'justification' => 'Detailed process belongs on its own page.',
            'owning_page_title' => null,
            'necessary_for_article' => true,
        ]];

        $repaired = $inconsistent;
        $repaired['concept_candidates'][0]['decision'] = 'create';
        $repaired['concept_pages'] = [[
            'action' => 'create',
            'page_id' => null,
            'title' => 'ITIL Incident Management',
            'proposed_slug' => 'itil-incident-management',
            'reason' => 'Central concept the article and summary both point to.',
            'owned_topics' => ['Definer ITIL Incident Management-prosessen.'],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
        ]];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($inconsistent);
        $mock->shouldReceive('repair')->once()->andReturn($repaired);

        $decision = app(EnterpriseWikiMaintainerDecisionService::class)
            ->runForDocument($customer->id, $document->id, 'no');

        $this->assertCount(1, $decision['concept_pages']);
        $this->assertSame('ITIL Incident Management', $decision['concept_pages'][0]['title']);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_json' => $decision,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at' => now(),
        ]);

        $result = app(EnterpriseWikiMaintainerDecisionApplyService::class)->apply($run);

        $this->assertSame(['created' => 3, 'updated' => 0], $result);

        $pages = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->get();
        $this->assertCount(3, $pages);

        $conceptPage = $pages->firstWhere('page_type', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $this->assertNotNull($conceptPage, 'The concept page must have been created.');
        $this->assertSame('ITIL Incident Management', $conceptPage->title);

        $runPages = EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_ingest_run_id', $run->id)->get();
        $this->assertCount(3, $runPages);
        $this->assertTrue($runPages->pluck('enterprise_wiki_page_id')->contains($conceptPage->id));
    }

    /**
     * When the repair pass fails to resolve the contradiction, the decision must never reach
     * apply — no page skeleton is created, no run row exists, and the failure is loud and
     * traceable (a typed exception), not a silently-empty concept_pages list.
     */
    public function test_unresolvable_inconsistency_never_reaches_apply(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $inconsistent = $this->baseDecision();
        $inconsistent['source_article']['related_page_guidance'] = [
            ['page_title' => 'ITIL Incident Management', 'relationship' => 'See the concept page.'],
        ];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($inconsistent);
        $mock->shouldReceive('repair')->once()->andReturn($inconsistent);

        $pagesBefore = EnterpriseWikiPage::query()->count();
        $runsBefore = EnterpriseWikiIngestRun::query()->count();

        try {
            app(EnterpriseWikiMaintainerDecisionService::class)
                ->runForDocument($customer->id, $document->id, 'no');
            $this->fail('Expected EnterpriseWikiMaintainerDecisionInconsistentException.');
        } catch (EnterpriseWikiMaintainerDecisionInconsistentException $e) {
            $this->assertStringContainsString('ITIL Incident Management', implode(' ', $e->issues));
        }

        $this->assertSame($pagesBefore, EnterpriseWikiPage::query()->count());
        $this->assertSame($runsBefore, EnterpriseWikiIngestRun::query()->count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function baseDecision(): array
    {
        return [
            'source_article' => [
                'action' => 'create',
                'title' => 'Illustrasjon av Incident Management',
                'proposed_slug' => 'illustrasjon-incident-management-ab12cd',
                'reason' => 'New source document.',
            ],
            'source_summary' => [
                'action' => 'create',
                'title' => 'Sammendrag: Illustrasjon av Incident Management',
                'proposed_slug' => 'sammendrag-illustrasjon-incident-management-ab12cd',
                'reason' => 'Companion summary.',
            ],
            'concept_candidates' => [],
            'concept_pages' => [],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }

    private function createCustomer(string $name = 'Test AS'): Customer
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

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'Incident Management Illustration.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst om Incident Management-illustrasjonen.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }
}
