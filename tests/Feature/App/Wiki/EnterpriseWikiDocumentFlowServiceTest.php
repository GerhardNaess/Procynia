<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\KnowledgeItemVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionApplyService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnterpriseWikiDocumentFlowServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_run_executes_steps_in_order_and_completes(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->flowService()->prepareRunForDocument($customer->id, $document->id)['run'];

        $knowledgeItem = $this->createKnowledgeItem($customer);
        $knowledgeVersion = $this->createKnowledgeItemVersion($knowledgeItem, $customer);
        $knowledgeItemUpdatedAt = $knowledgeItem->fresh()->updated_at;
        $knowledgeVersionUpdatedAt = $knowledgeVersion->fresh()->updated_at;
        $knowledgeItemCount = KnowledgeItem::query()->count();
        $knowledgeVersionCount = KnowledgeItemVersion::query()->count();
        $knowledgeChunkCount = KnowledgeItemChunk::query()->count();

        $callOrder = [];
        $this->configureFlowMocks($customer, $document, $callOrder);

        $this->flowService()->run($run->id);

        $this->assertSame([
            'maintainer_decision',
            'apply',
            'generate',
            'extract',
            'verify',
            'build',
            'lint',
            'qa',
        ], $callOrder);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT, $run->source_type);
        $this->assertSame($document->id, $run->source_id);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED, $run->maintainer_decision_status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->error_message);
        $this->assertNull($run->qa_last_error);

        $this->assertSame($knowledgeItemCount, KnowledgeItem::query()->count());
        $this->assertSame($knowledgeVersionCount, KnowledgeItemVersion::query()->count());
        $this->assertSame($knowledgeChunkCount, KnowledgeItemChunk::query()->count());
        $this->assertSame($knowledgeItemUpdatedAt?->toDateTimeString(), $knowledgeItem->fresh()->updated_at?->toDateTimeString());
        $this->assertSame($knowledgeVersionUpdatedAt?->toDateTimeString(), $knowledgeVersion->fresh()->updated_at?->toDateTimeString());
    }

    #[DataProvider('failingStepProvider')]
    public function test_run_marks_run_failed_when_a_step_throws(string $failingStep, array $expectedCallOrder, bool $qaContext): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->flowService()->prepareRunForDocument($customer->id, $document->id)['run'];

        $callOrder = [];
        $this->configureFlowMocks($customer, $document, $callOrder, $failingStep);

        try {
            $this->flowService()->run($run->id);
            $this->fail('Expected the flow to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame(str_replace('_', ' ', $failingStep).' failed', $e->getMessage());
        }

        $this->assertSame($expectedCallOrder, $callOrder);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(str_replace('_', ' ', $failingStep).' failed', $run->error_message);

        if ($qaContext) {
            $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
            $this->assertSame("{$failingStep} failed", $run->qa_last_error);
            $this->assertNotNull($run->qa_completed_at);
        } else {
            $this->assertNotSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
        }
    }

    public static function failingStepProvider(): array
    {
        return [
            'maintainer decision' => ['maintainer_decision', ['maintainer_decision'], false],
            'apply' => ['apply', ['maintainer_decision', 'apply'], false],
            'generate pages' => ['generate', ['maintainer_decision', 'apply', 'generate'], false],
            'extract claims' => ['extract', ['maintainer_decision', 'apply', 'generate', 'extract'], false],
            'verify claims' => ['verify', ['maintainer_decision', 'apply', 'generate', 'extract', 'verify'], false],
            'build links' => ['build', ['maintainer_decision', 'apply', 'generate', 'extract', 'verify', 'build'], false],
            'lint' => ['lint', ['maintainer_decision', 'apply', 'generate', 'extract', 'verify', 'build', 'lint'], false],
            'qa' => ['qa', ['maintainer_decision', 'apply', 'generate', 'extract', 'verify', 'build', 'lint', 'qa'], true],
        ];
    }

    private function configureFlowMocks(
        Customer $customer,
        EnterpriseWikiDocument $document,
        array &$callOrder,
        ?string $failingStep = null,
    ): void {
        $decision = $this->baseDecision();
        $stages = [
            'maintainer_decision',
            'apply',
            'generate',
            'extract',
            'verify',
            'build',
            'lint',
            'qa',
        ];
        $failingStageIndex = $failingStep !== null ? array_search($failingStep, $stages, true) : false;
        $shouldExpect = function (string $stage) use ($failingStageIndex, $stages): bool {
            if ($failingStageIndex === false) {
                return true;
            }

            return array_search($stage, $stages, true) <= $failingStageIndex;
        };
        $shouldFail = function (string $stage) use ($failingStep): bool {
            return $failingStep === $stage;
        };

        if ($shouldExpect('maintainer_decision')) {
            $this->mock(EnterpriseWikiMaintainerDecisionService::class)
                ->shouldReceive('runForDocument')
                ->once()
                ->ordered('enterprise-wiki-document-flow')
                ->with($customer->id, $document->id, 'no')
                ->andReturnUsing(function () use (&$callOrder, $decision, $shouldFail) {
                    $callOrder[] = 'maintainer_decision';

                    if ($shouldFail('maintainer_decision')) {
                        throw new RuntimeException('maintainer decision failed');
                    }

                    return $decision;
                });
        } else {
            $this->mock(EnterpriseWikiMaintainerDecisionService::class)
                ->shouldNotReceive('runForDocument');
        }

        if ($shouldExpect('apply')) {
            $this->mock(EnterpriseWikiMaintainerDecisionApplyService::class)
                ->shouldReceive('apply')
                ->once()
                ->ordered('enterprise-wiki-document-flow')
                ->andReturnUsing(function (EnterpriseWikiIngestRun $run) use (&$callOrder, $shouldFail) {
                    $callOrder[] = 'apply';
                    $this->assertSame(EnterpriseWikiIngestRun::STATUS_APPLYING, $run->status);
                    $this->assertSame(EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING, $run->maintainer_decision_status);

                    if ($shouldFail('apply')) {
                        throw new RuntimeException('apply failed');
                    }

                    $run->update([
                        'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
                    ]);

                    return ['created' => 2, 'updated' => 0];
                });
        } else {
            $this->mock(EnterpriseWikiMaintainerDecisionApplyService::class)
                ->shouldNotReceive('apply');
        }

        if ($shouldExpect('generate')) {
            $this->mock(EnterpriseWikiGenerateAppliedPagesService::class)
                ->shouldReceive('generate')
                ->once()
                ->ordered('enterprise-wiki-document-flow')
                ->andReturnUsing(function (EnterpriseWikiIngestRun $run) use (&$callOrder, $shouldFail) {
                    $callOrder[] = 'generate';
                    $this->assertSame(EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES, $run->status);
                    $this->assertSame(EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED, $run->maintainer_decision_status);

                    if ($shouldFail('generate')) {
                        throw new RuntimeException('generate failed');
                    }

                    return ['article' => 1, 'summary' => 1, 'concept' => 0, 'entity' => 0, 'skipped' => 0];
                });
        } else {
            $this->mock(EnterpriseWikiGenerateAppliedPagesService::class)
                ->shouldNotReceive('generate');
        }

        if ($shouldExpect('extract')) {
            $this->mock(EnterpriseWikiExtractPageClaimsService::class)
                ->shouldReceive('extract')
                ->once()
                ->ordered('enterprise-wiki-document-flow')
                ->andReturnUsing(function (EnterpriseWikiIngestRun $run) use (&$callOrder, $shouldFail) {
                    $callOrder[] = 'extract';
                    $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $run->status);
                    $this->assertSame(EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED, $run->maintainer_decision_status);

                    if ($shouldFail('extract')) {
                        throw new RuntimeException('extract failed');
                    }

                    return ['pages' => 2, 'claims' => 2, 'skipped' => 0];
                });
        } else {
            $this->mock(EnterpriseWikiExtractPageClaimsService::class)
                ->shouldNotReceive('extract');
        }

        if ($shouldExpect('verify')) {
            $this->mock(EnterpriseWikiVerifyPageClaimsService::class)
                ->shouldReceive('verify')
                ->once()
                ->ordered('enterprise-wiki-document-flow')
                ->andReturnUsing(function (EnterpriseWikiIngestRun $run) use (&$callOrder, $shouldFail) {
                    $callOrder[] = 'verify';
                    $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $run->status);

                    if ($shouldFail('verify')) {
                        throw new RuntimeException('verify failed');
                    }

                    return ['pages' => 2, 'claims' => 2, 'references' => 2, 'skipped' => 0, 'no_support' => 0];
                });
        } else {
            $this->mock(EnterpriseWikiVerifyPageClaimsService::class)
                ->shouldNotReceive('verify');
        }

        if ($shouldExpect('build')) {
            $this->mock(EnterpriseWikiBuildPageLinksService::class)
                ->shouldReceive('build')
                ->once()
                ->ordered('enterprise-wiki-document-flow')
                ->andReturnUsing(function (EnterpriseWikiIngestRun $run) use (&$callOrder, $shouldFail) {
                    $callOrder[] = 'build';
                    $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $run->status);

                    if ($shouldFail('build')) {
                        throw new RuntimeException('build failed');
                    }

                    return ['pages_checked' => 2, 'links_created' => 4, 'links_skipped' => 0, 'missing_versions' => 0, 'failed' => 0];
                });
        } else {
            $this->mock(EnterpriseWikiBuildPageLinksService::class)
                ->shouldNotReceive('build');
        }

        if ($shouldExpect('lint')) {
            $this->mock(EnterpriseWikiAppliedRunLintService::class)
                ->shouldReceive('lint')
                ->once()
                ->ordered('enterprise-wiki-document-flow')
                ->andReturnUsing(function (EnterpriseWikiIngestRun $run) use (&$callOrder, $shouldFail) {
                    $callOrder[] = 'lint';
                    $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $run->status);

                    if ($shouldFail('lint')) {
                        throw new RuntimeException('lint failed');
                    }

                    return [
                        'pages_checked' => 2,
                        'claims_checked' => 2,
                        'source_refs_checked' => 2,
                        'links_checked' => 4,
                        'findings_created' => 0,
                        'findings_skipped' => 0,
                        'findings_resolved' => 0,
                        'errors' => 0,
                        'warnings' => 0,
                        'info' => 0,
                    ];
                });
        } else {
            $this->mock(EnterpriseWikiAppliedRunLintService::class)
                ->shouldNotReceive('lint');
        }

        if ($shouldExpect('qa')) {
            $this->mock(EnterpriseWikiPostIngestQaService::class)
                ->shouldReceive('runForRun')
                ->once()
                ->ordered('enterprise-wiki-document-flow')
                ->andReturnUsing(function (EnterpriseWikiIngestRun $run) use (&$callOrder, $shouldFail) {
                    $callOrder[] = 'qa';
                    $this->assertSame(EnterpriseWikiIngestRun::STATUS_QA, $run->status);
                    $this->assertSame(EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED, $run->maintainer_decision_status);

                    if ($shouldFail('qa')) {
                        throw new RuntimeException('qa failed');
                    }

                    $run->update([
                        'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
                        'qa_completed_at' => now(),
                        'qa_last_error' => null,
                        'qa_result' => [
                            'pass' => true,
                            'quality_score' => 1.0,
                        ],
                    ]);

                    return [
                        'pass' => true,
                        'quality_score' => 1.0,
                    ];
                });
        } else {
            $this->mock(EnterpriseWikiPostIngestQaService::class)
                ->shouldNotReceive('runForRun');
        }
    }

    private function flowService(): EnterpriseWikiDocumentFlowService
    {
        return app(EnterpriseWikiDocumentFlowService::class);
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
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createKnowledgeItem(Customer $customer): KnowledgeItem
    {
        return KnowledgeItem::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Irrelevant knowledge item',
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_COMPANY,
            'ai_usage_enabled' => true,
        ]);
    }

    private function createKnowledgeItemVersion(KnowledgeItem $item, Customer $customer): KnowledgeItemVersion
    {
        return KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $item->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
            'extracted_text' => "## Irrelevant\nInnhold som ikke skal brukes av wiki-dokumentflyten.",
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
            'file_hash_sha256' => str_pad('abc123', 64, '0'),
            'original_filename' => 'irrelevant.docx',
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'enterprise-wiki-source.pdf',
            'file_path' => 'customers/' . $customer->id . '/wiki/' . Str::random(8) . '.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => "## Kompetanse\nVi leverer kontrollert Enterprise Wiki-innhold.\n\n## Kvalitet\nVi bevarer sporbarhet og struktur.",
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function baseDecision(): array
    {
        return [
            'source_article' => [
                'action' => 'create',
                'title' => 'Enterprise Wiki-artikkel',
                'proposed_slug' => 'enterprise-wiki-artikkel-ab1c2d',
                'reason' => 'Source article.',
            ],
            'source_summary' => [
                'action' => 'create',
                'title' => 'Sammendrag: Enterprise Wiki',
                'proposed_slug' => 'sammendrag-enterprise-wiki-ab1c2d',
                'reason' => 'Summary page.',
            ],
            'concept_pages' => [],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }
}
