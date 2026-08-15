<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\EnterpriseWikiInvalidUtf8Exception;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiMaintainerDecisionBatch;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseInvalidJsonException;
use App\Services\EnterpriseWiki\EnterpriseWikiCorruptResponseClassifier;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionBatchEvaluator;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionPrompt;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionSplitCoordinator;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery\MockInterface;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * ONE corrupt-response policy, in the shared capacity executor.
 *
 * Run 60 failed hard on raw control bytes in a queued candidate batch — and the retry for exactly
 * that fault already existed. It lived in the in-process batch loop, which production never uses.
 * Three defenses had grown for one question ("is this response usable?"): EnterpriseWikiUtf8Guard on
 * generation, validateNoControlCharacters on planning, and that loop. A policy that depends on which
 * caller you came through is not a policy.
 *
 * Now a caller hands its parser to EnterpriseWikiAiCapacityRetryExecutor, the parse happens inside
 * the attempt, and an unusable response gets exactly one fresh attempt before failing. The detectors
 * did not move — only the decision about what to do with them.
 */
class EnterpriseWikiCorruptResponsePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    // =========================================================================
    // What counts as unusable
    // =========================================================================

    public function test_unusable_responses_are_recognised_and_wrong_ones_are_not(): void
    {
        $marker = EnterpriseWikiMaintainerDecisionPrompt::CORRUPTED_TEXT_MARKER;

        $this->assertTrue(EnterpriseWikiCorruptResponseClassifier::isCorrupt(
            new InvalidArgumentException("concept_candidates[0].name {$marker}."),
        ));
        $this->assertTrue(EnterpriseWikiCorruptResponseClassifier::isCorrupt(
            new EnterpriseWikiResponseInvalidJsonException('response output was not valid JSON'),
        ));
        $this->assertTrue(EnterpriseWikiCorruptResponseClassifier::isCorrupt(
            new EnterpriseWikiInvalidUtf8Exception('enterprise_wiki_ai_response', 'page.blocks.0.markdown', 120, 47, 'e2 28 a1'),
        ));

        // A readable-but-wrong decision must NEVER be retried: the retry would repeat the mistake at
        // full cost, and the validators or a bounded semantic repair are what that failure is for.
        $this->assertFalse(EnterpriseWikiCorruptResponseClassifier::isCorrupt(
            new InvalidArgumentException('concept_pages[0].title is required and must be a non-empty string.'),
        ));
        $this->assertFalse(EnterpriseWikiCorruptResponseClassifier::isCorrupt(
            new RuntimeException('WikiPageContentAiClient: generated page blocks were empty.'),
        ));
    }

    public function test_the_marker_is_defined_once(): void
    {
        // The detector stays with the contract it validates; the string itself is shared, so the
        // classifier and the validator can never drift apart.
        $this->assertSame(
            EnterpriseWikiCorruptResponseClassifier::CONTROL_CHARACTER_MARKER,
            EnterpriseWikiMaintainerDecisionPrompt::CORRUPTED_TEXT_MARKER,
        );
        $this->assertFalse(
            method_exists(EnterpriseWikiMaintainerDecisionPrompt::class, 'isCorruptedTextFailure'),
            'the per-path corrupt helper is gone; the classifier answers this for every call',
        );
    }

    // =========================================================================
    // The run-60 path
    // =========================================================================

    public function test_the_queued_candidate_batch_retries_a_corrupt_response_once_and_succeeds(): void
    {
        [$run, $batch] = $this->queuedBatch();
        $calls = $this->mockOpenAiSequence([
            $this->corruptBatchResponse(),
            $this->validBatchResponse(),
        ]);

        $result = app(EnterpriseWikiMaintainerDecisionBatchEvaluator::class)->evaluate($run->id, $batch->fresh());

        $this->assertSame(2, $calls(), 'run 60 died here on one attempt; the queued path now gets the same retry');
        $this->assertSame('Hendelseshåndtering', $result['concept_candidates'][0]['name']);
    }

    public function test_the_queued_candidate_batch_fails_after_a_second_corrupt_response(): void
    {
        [$run, $batch] = $this->queuedBatch();
        $calls = $this->mockOpenAiSequence([
            $this->corruptBatchResponse(),
            $this->corruptBatchResponse(),
        ]);

        try {
            app(EnterpriseWikiMaintainerDecisionBatchEvaluator::class)->evaluate($run->id, $batch->fresh());
            $this->fail('a second unusable response must fail, not be retried again');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString(EnterpriseWikiMaintainerDecisionPrompt::CORRUPTED_TEXT_MARKER, $e->getMessage());
        }

        $this->assertSame(2, $calls(), 'exactly one retry — never a loop');
    }

    public function test_a_valid_queued_batch_response_costs_exactly_one_call(): void
    {
        [$run, $batch] = $this->queuedBatch();
        $calls = $this->mockOpenAiSequence([$this->validBatchResponse()]);

        app(EnterpriseWikiMaintainerDecisionBatchEvaluator::class)->evaluate($run->id, $batch->fresh());

        $this->assertSame(1, $calls());
    }

    public function test_a_readable_but_invalid_queued_batch_response_is_never_retried(): void
    {
        [$run, $batch] = $this->queuedBatch();
        $calls = $this->mockOpenAiSequence([
            $this->batchResponse([[
                'name' => 'Hendelseshåndtering',
                'concept_type' => 'process',
                'mentioned_context' => 'seksjon 1',
                // 'decision' missing entirely — a contract violation, not a transmission fault.
                'relationship' => 'independent_new_topic',
                'independent_reason' => 'Egen prosess.',
                'justification' => 'Egen side.',
            ]]),
        ]);

        try {
            app(EnterpriseWikiMaintainerDecisionBatchEvaluator::class)->evaluate($run->id, $batch->fresh());
            $this->fail('an invalid decision must propagate');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(1, $calls(), 'repeating a wrong decision only repeats the mistake');
    }

    // =========================================================================
    // The same policy everywhere
    // =========================================================================

    public function test_every_planning_call_hands_its_parser_to_the_shared_executor(): void
    {
        // Structural: a phase that parses AFTER execute() is a phase the policy cannot see.
        $coordinator = file_get_contents(app_path('Services/EnterpriseWiki/EnterpriseWikiMaintainerDecisionSplitCoordinator.php'));

        foreach (['parseDocumentPlan', 'parseCandidatePlan', 'parseCandidateBatch'] as $parser) {
            $this->assertStringContainsString(
                "EnterpriseWikiMaintainerDecisionPrompt::{$parser}(\$decoded)",
                $coordinator,
                $parser,
            );
        }

        $client = file_get_contents(app_path('Services/EnterpriseWiki/EnterpriseWikiMaintainerDecisionAiClient.php'));
        $this->assertStringContainsString('EnterpriseWikiMaintainerDecisionPrompt::parse($body)', $client);
        $this->assertStringContainsString('EnterpriseWikiMaintainerDecisionDeltaPrompt::parse($body)', $client);

        $generation = file_get_contents(app_path('Services/Ai/Wiki/WikiPageContentAiClient.php'));
        $this->assertSame(
            3,
            substr_count($generation, "\$this->utf8Guard->assertValid(\$body, 'enterprise_wiki_ai_response');"),
            'generation, section repair and figure repair all validate inside the attempt',
        );
        $this->assertStringNotContainsString(
            "\$this->utf8Guard->assertValid(\$decoded, 'enterprise_wiki_ai_response');",
            $generation,
            'the guard must not also run as a separate post-execute strategy',
        );
    }

    public function test_no_local_corrupt_retry_survives(): void
    {
        $coordinator = file_get_contents(app_path('Services/EnterpriseWiki/EnterpriseWikiMaintainerDecisionSplitCoordinator.php'));

        $this->assertStringNotContainsString('MAX_CORRUPTED_RESPONSE_ATTEMPTS', $coordinator);
        $this->assertStringNotContainsString('isCorruptedTextFailure', $coordinator);
        $this->assertFalse(
            (new ReflectionClass(EnterpriseWikiMaintainerDecisionSplitCoordinator::class))->hasMethod('decideAndParseCandidateBatch'),
        );
    }

    // =========================================================================
    // One phase-2 implementation
    // =========================================================================

    public function test_phase_two_has_a_single_implementation(): void
    {
        $coordinator = new ReflectionClass(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);

        $this->assertTrue($coordinator->hasMethod('decidePersistedCandidateBatch'));

        // The in-process loop and the queued worker both go through that one entrypoint, and the
        // parse happens once — inside the executor, where the policy is.
        $source = file_get_contents(app_path('Services/EnterpriseWiki/EnterpriseWikiMaintainerDecisionSplitCoordinator.php'));
        $this->assertSame(1, substr_count($source, 'private function decideCandidateBatch('), 'one batch call builder');
        $this->assertStringContainsString('$this->decidePersistedCandidateBatch(', $source);

        $evaluator = file_get_contents(app_path('Services/EnterpriseWiki/EnterpriseWikiMaintainerDecisionBatchEvaluator.php'));
        $this->assertStringContainsString('decidePersistedCandidateBatch(', $evaluator);
        $this->assertStringNotContainsString('parseCandidateBatch(', $evaluator, 'parsing happens once, inside the executor');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param  list<array<string, mixed>>  $responses
     * @return callable(): int how many calls were made
     */
    private function mockOpenAiSequence(array $responses): callable
    {
        $calls = 0;

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')->andReturnUsing(function () use (&$calls, $responses): array {
            $response = $responses[$calls] ?? end($responses);
            $calls++;

            return $response;
        });

        return function () use (&$calls): int {
            return $calls;
        };
    }

    /** @return array<string, mixed> */
    private function corruptBatchResponse(): array
    {
        // A raw control byte where a character belongs — the run-34/run-60 fault, verbatim.
        return $this->batchResponse([[
            'name' => "Hendelses\x08håndtering",
            'concept_type' => 'process',
            'mentioned_context' => 'seksjon 1',
            'decision' => 'exclude',
            'relationship' => 'independent_new_topic',
            'independent_reason' => 'Egen prosess.',
            'justification' => 'Ikke egen side.',
        ]]);
    }

    /** @return array<string, mixed> */
    private function validBatchResponse(): array
    {
        return $this->batchResponse([[
            'name' => 'Hendelseshåndtering',
            'concept_type' => 'process',
            'mentioned_context' => 'seksjon 1',
            'decision' => 'exclude',
            'relationship' => 'independent_new_topic',
            'independent_reason' => 'Egen prosess.',
            'justification' => 'Ikke egen side.',
        ]]);
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function batchResponse(array $candidates): array
    {
        return [
            'id' => 'resp_'.Str::lower(Str::random(6)),
            'status' => 'completed',
            'output_text' => json_encode([
                'concept_candidates' => $candidates,
                'concept_pages' => [],
                'entity_pages' => [],
                'patch_targets' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'output' => [],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150],
            '_meta' => ['request_id' => 'req_test', 'http_status' => 200, 'provider' => 'openai'],
        ];
    }

    /** @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiMaintainerDecisionBatch} */
    private function queuedBatch(): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create([
            'name' => 'Korrupt AS',
            'slug' => 'korrupt-as-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'kilde.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => str_repeat('Kildetekst om hendelseshåndtering i drift. ', 40),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION,
        ]);

        $globalPlan = [
            'source_article' => $this->page('Kilde', 'kilde-ab1c2d'),
            'source_summary' => $this->page('Kilde — Sammendrag', 'kilde-sammendrag-ab1c2d'),
            'entity_pages' => [],
            'patch_targets' => [],
            'concept_candidate_mentions' => [[
                'name' => 'Hendelseshåndtering',
                'concept_type' => 'process',
                'mentioned_context' => 'seksjon 1',
                'section_keys' => [],
            ]],
            'no_action_reason' => null,
            'warnings' => [],
        ];

        $batch = EnterpriseWikiMaintainerDecisionBatch::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'batch_number' => 1,
            'total_batches' => 1,
            'status' => EnterpriseWikiMaintainerDecisionBatch::STATUS_PENDING,
            'input_payload' => [
                'global_plan' => $globalPlan,
                'mentions' => $globalPlan['concept_candidate_mentions'],
                'batch_number' => 1,
                'total_batches' => 1,
            ],
        ]);

        return [$run, $batch];
    }

    /** @return array<string, mixed> */
    private function page(string $title, string $slug): array
    {
        return [
            'action' => 'create',
            'page_id' => null,
            'title' => $title,
            'proposed_slug' => $slug,
            'reason' => 'Ny side.',
            'owned_topics' => [],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ];
    }
}
