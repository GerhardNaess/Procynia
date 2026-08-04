<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\EnterpriseWikiMaintainerDecisionInconsistentException;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery\MockInterface;
use Tests\TestCase;

class EnterpriseWikiMaintainerDecisionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_service_returns_decision_from_ai_client(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $expected = $this->validDecision();

        $this->mockAiClient($expected);

        $result = $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame($expected['source_article']['title'], $result['source_article']['title']);
    }

    public function test_service_enforces_customer_scoping(): void
    {
        $owner = $this->createCustomer('Owner');
        $other = $this->createCustomer('Other');
        $document = $this->createDocument($owner);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found for customer/');

        $this->service()->runForDocument($other->id, $document->id);
    }

    public function test_service_document_not_found_throws(): void
    {
        $customer = $this->createCustomer();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found for customer/');

        $this->service()->runForDocument($customer->id, 99999);
    }

    public function test_service_strips_extension_from_document_filename_for_source_meta(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, ['original_filename' => 'Masterdata Prosjekt.docx']);

        $captured = [];
        $this->mockAiClientCapturing($captured);

        $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame('Masterdata Prosjekt', $captured['sourceMeta']['title']);
        $this->assertSame('Masterdata Prosjekt.docx', $captured['sourceMeta']['filename']);
    }

    public function test_service_passes_extracted_text_to_ai_client(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, ['extracted_text' => 'Spesiell kildetekst.']);

        $captured = [];
        $this->mockAiClientCapturing($captured);

        $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame('Spesiell kildetekst.', $captured['sourceText']);
    }

    public function test_service_passes_empty_string_when_extracted_text_is_null(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, ['extracted_text' => null]);

        $captured = [];
        $this->mockAiClientCapturing($captured);

        $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame('', $captured['sourceText']);
    }

    public function test_service_includes_existing_wiki_pages_in_index_context(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'bestaende-side',
            'title' => 'Bestående Side',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
        ]);

        $captured = [];
        $this->mockAiClientCapturing($captured);

        $this->service()->runForDocument($customer->id, $document->id, 'no');

        $titles = array_column($captured['indexContext'], 'title');
        $this->assertContains('Bestående Side', $titles);
    }

    public function test_service_index_context_is_empty_when_no_pages_exist(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $captured = [];
        $this->mockAiClientCapturing($captured);

        $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame([], $captured['indexContext']);
    }

    public function test_service_index_context_excludes_pages_from_other_customers(): void
    {
        $customer = $this->createCustomer('Mine');
        $other = $this->createCustomer('Theirs');
        $document = $this->createDocument($customer);

        EnterpriseWikiPage::query()->create([
            'customer_id' => $other->id,
            'slug' => 'their-page',
            'title' => 'Other Customer Page',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
        ]);

        $captured = [];
        $this->mockAiClientCapturing($captured);

        $this->service()->runForDocument($customer->id, $document->id, 'no');

        $titles = array_column($captured['indexContext'], 'title');
        $this->assertNotContains('Other Customer Page', $titles);
    }

    public function test_service_passes_language_code_to_ai_client(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $captured = [];
        $this->mockAiClientCapturing($captured);

        $this->service()->runForDocument($customer->id, $document->id, 'en');

        $this->assertSame('en', $captured['languageCode']);
    }

    public function test_service_does_not_write_to_database(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $this->mockAiClient($this->validDecision());

        $pagesBefore = EnterpriseWikiPage::query()->count();
        $this->service()->runForDocument($customer->id, $document->id, 'no');
        $pagesAfter = EnterpriseWikiPage::query()->count();

        $this->assertSame($pagesBefore, $pagesAfter);
    }

    // =========================================================================
    // Consistency validation + bounded repair pass (Wiki run-581 fix: "ITIL Incident
    // Management" concept page never proposed even though the article/summary pointed the
    // reader onward to it).
    // =========================================================================

    public function test_consistent_decision_never_triggers_a_repair_call(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($this->validDecision());
        $mock->shouldNotReceive('repair');

        $this->service()->runForDocument($customer->id, $document->id, 'no');
    }

    public function test_inconsistent_decision_triggers_one_bounded_repair_pass(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $inconsistent = $this->validDecision();
        $inconsistent['source_article']['related_page_guidance'] = [
            ['page_title' => 'ITIL Incident Management', 'relationship' => 'See the concept page.'],
        ];

        $repaired = $this->validDecision();
        $repaired['source_article']['related_page_guidance'] = $inconsistent['source_article']['related_page_guidance'];
        $repaired['concept_pages'] = [[
            'action' => 'create',
            'page_id' => null,
            'title' => 'ITIL Incident Management',
            'proposed_slug' => 'itil-incident-management',
            'reason' => 'Central concept the article points to.',
        ]];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($inconsistent);
        $mock->shouldReceive('repair')
            ->once()
            ->withArgs(function (array $sourceMeta, string $sourceText, array $indexContext, string $languageCode, array $decision, array $issues) use ($inconsistent): bool {
                return $decision === $inconsistent
                    && $issues !== []
                    && str_contains(implode(' ', $issues), 'ITIL Incident Management');
            })
            ->andReturn($repaired);

        $result = $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertCount(1, $result['concept_pages']);
        $this->assertSame('ITIL Incident Management', $result['concept_pages'][0]['title']);
    }

    public function test_decision_still_inconsistent_after_repair_throws(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $inconsistent = $this->validDecision();
        $inconsistent['source_article']['related_page_guidance'] = [
            ['page_title' => 'ITIL Incident Management', 'relationship' => 'See the concept page.'],
        ];

        // Repair pass returns a decision with the exact same unresolved contradiction.
        $stillInconsistent = $inconsistent;

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($inconsistent);
        $mock->shouldReceive('repair')->once()->andReturn($stillInconsistent);

        $this->expectException(EnterpriseWikiMaintainerDecisionInconsistentException::class);

        $this->service()->runForDocument($customer->id, $document->id, 'no');
    }

    public function test_composed_decision_validation_does_not_call_decide_or_persist(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldNotReceive('decide');
        $mock->shouldNotReceive('repair');

        $result = $this->service()->validateAndRepairForDocument($customer->id, $document, 'no', $this->validDecision());

        $this->assertSame($this->validDecision()['source_article']['title'], $result['source_article']['title']);
    }

    public function test_composed_inconsistent_decision_uses_same_single_repair_pass(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $decision = $this->validDecision();
        $decision['source_article']['related_page_guidance'] = [['page_title' => 'ITIL Incident Management', 'relationship' => 'See']];
        $repaired = $decision;
        $repaired['concept_pages'] = [['action' => 'create', 'page_id' => null, 'title' => 'ITIL Incident Management', 'proposed_slug' => 'itil-incident-management', 'reason' => 'Required']];
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldNotReceive('decide');
        $mock->shouldReceive('repair')->once()->andReturn($repaired);

        $this->assertCount(1, $this->service()->validateAndRepairForDocument($customer->id, $document, 'no', $decision)['concept_pages']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): EnterpriseWikiMaintainerDecisionService
    {
        return app(EnterpriseWikiMaintainerDecisionService::class);
    }

    private function mockAiClient(array $decision): void
    {
        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($decision);
    }

    private function mockAiClientCapturing(array &$captured): void
    {
        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')
            ->once()
            ->andReturnUsing(
                function (
                    array $sourceMeta,
                    string $sourceText,
                    array $indexContext,
                    string $languageCode,
                ) use (&$captured): array {
                    $captured = compact('sourceMeta', 'sourceText', 'indexContext', 'languageCode');

                    return $this->validDecision();
                }
            );
    }

    private function validDecision(): array
    {
        return [
            'source_article' => [
                'action' => 'create',
                'title' => 'Test Artikkel',
                'proposed_slug' => 'test-artikkel-ab1c2d',
                'reason' => 'New.',
            ],
            'source_summary' => [
                'action' => 'create',
                'title' => 'Sammendrag: Test Artikkel',
                'proposed_slug' => 'sammendrag-test-artikkel-ab1c2d',
                'reason' => 'Companion.',
            ],
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

    private function createDocument(Customer $customer, array $overrides = []): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create(array_merge([
            'customer_id' => $customer->id,
            'original_filename' => 'selskapsinfo.docx',
            'file_path' => 'wiki/'.Str::random(12).'.docx',
            'file_hash_sha256' => Str::random(64),
            'extracted_text' => 'Standardinnhold.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ], $overrides));
    }
}
