<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Data\Ai\AiCallContext;
use App\Data\Ai\Capacity\AiCapacityPlan;
use App\Data\Ai\Capacity\AiTimeoutPlan;
use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseInvalidJsonException;
use App\Services\EnterpriseWiki\EnterpriseWikiAiCapacityRetryExecutor;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Wiki run-592: EnterpriseWikiAiCapacityRetryExecutor now performs at most ONE automatic
 * network retry per sub-call, only for a documented-transient failure
 * (EnterpriseWikiTransientFailureClassifier), fully independent of the pre-existing
 * capacity-retry mechanism (triggered only by an incomplete/max_output_tokens response).
 *
 * OpenAiClient::createResponse() is mocked directly — these are unit tests of the executor's
 * own retry/logging logic, not of the real OpenAI HTTP call.
 */
class EnterpriseWikiAiCapacityRetryExecutorNetworkRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No real backoff delay in tests.
        config([
            'ai_request_timeout.network_retry_backoff_base_ms' => 0,
            'ai_request_timeout.network_retry_backoff_jitter_ms' => 0,
            'ai_request_timeout.min_seconds' => 30,
        ]);
    }

    private function executor(OpenAiClient $openAiClient): EnterpriseWikiAiCapacityRetryExecutor
    {
        return new EnterpriseWikiAiCapacityRetryExecutor($openAiClient, new EnterpriseWikiResponsesDecoder);
    }

    private function planFor(int $retryLevel): AiCapacityPlan
    {
        return new AiCapacityPlan(
            operationType: 'enterprise_wiki_maintainer_decision',
            model: 'gpt-5',
            chosenMaxOutputTokens: 1000 + $retryLevel,
            estimatedMinimumTokens: 10,
            estimatedNeedTokens: 10,
            maxAllowedTokens: 5000,
            wasClamped: false,
            basis: 'test-plan',
            retryLevel: $retryLevel,
        );
    }

    private function timeoutPlan(): AiTimeoutPlan
    {
        return new AiTimeoutPlan(
            operationType: 'enterprise_wiki_maintainer_decision',
            timeoutSeconds: 30,
            minSeconds: 20,
            maxSeconds: 120,
            wasClampedToRange: false,
            wasClampedToJobBudget: false,
            basis: 'test-timeout-plan',
        );
    }

    /** @return array<string, mixed> */
    private function completedResponse(string $json = '{"foo":"bar"}'): array
    {
        return [
            'status' => 'completed',
            'output' => [
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $json]]],
            ],
            '_meta' => ['request_id' => 'req_completed', 'http_status' => 200],
        ];
    }

    /** @return array<string, mixed> */
    private function incompleteMaxTokensResponse(): array
    {
        return [
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [],
            '_meta' => ['request_id' => 'req_incomplete', 'http_status' => 200],
        ];
    }

    private function execute(OpenAiClient $openAiClient, ?AiCallContext $context = null): array
    {
        return $this->executor($openAiClient)->execute(
            'test_operation',
            4000,
            fn (int $retryLevel) => $this->planFor($retryLevel),
            fn (int $maxTokens) => ['max_output_tokens' => $maxTokens],
            fn (AiCapacityPlan $plan, ?int $budget) => $this->timeoutPlan(),
            $context,
        );
    }

    // =========================================================================
    // Transient failures — one automatic retry
    // =========================================================================

    public function test_curl_timeout_error_28_triggers_exactly_one_retry_and_succeeds(): void
    {
        $calls = 0;
        $openAiClient = Mockery::mock(OpenAiClient::class);
        $openAiClient->shouldReceive('createResponse')
            ->twice()
            ->andReturnUsing(function () use (&$calls) {
                $calls++;

                if ($calls === 1) {
                    throw new ConnectionException('cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received');
                }

                return $this->completedResponse();
            });

        $result = $this->execute($openAiClient);

        $this->assertSame(2, $calls);
        $this->assertSame(['foo' => 'bar'], $result);
    }

    public function test_http_429_triggers_one_retry(): void
    {
        $calls = 0;
        $openAiClient = Mockery::mock(OpenAiClient::class);
        $openAiClient->shouldReceive('createResponse')
            ->twice()
            ->andReturnUsing(function () use (&$calls) {
                $calls++;

                if ($calls === 1) {
                    throw new RuntimeException('OpenAI request to [responses] failed with HTTP status [429]: rate_limit_exceeded');
                }

                return $this->completedResponse();
            });

        $result = $this->execute($openAiClient);

        $this->assertSame(2, $calls);
        $this->assertSame(['foo' => 'bar'], $result);
    }

    public function test_http_502_503_504_each_trigger_one_retry(): void
    {
        foreach ([502, 503, 504] as $status) {
            $calls = 0;
            $openAiClient = Mockery::mock(OpenAiClient::class);
            $openAiClient->shouldReceive('createResponse')
                ->twice()
                ->andReturnUsing(function () use (&$calls, $status) {
                    $calls++;

                    if ($calls === 1) {
                        throw new RuntimeException("OpenAI request to [responses] failed with HTTP status [{$status}]: server_error");
                    }

                    return $this->completedResponse();
                });

            $result = $this->execute($openAiClient);

            $this->assertSame(2, $calls, "expected exactly 2 calls for status {$status}");
            $this->assertSame(['foo' => 'bar'], $result);
        }
    }

    public function test_two_transient_failures_stop_after_a_total_of_two_attempts(): void
    {
        $calls = 0;
        $openAiClient = Mockery::mock(OpenAiClient::class);
        $openAiClient->shouldReceive('createResponse')
            ->twice()
            ->andReturnUsing(function () use (&$calls) {
                $calls++;

                throw new ConnectionException('cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received');
            });

        $this->expectException(ConnectionException::class);

        try {
            $this->execute($openAiClient);
        } finally {
            $this->assertSame(2, $calls);
        }
    }

    // =========================================================================
    // Non-transient failures — no network retry
    // =========================================================================

    public function test_http_401_does_not_retry(): void
    {
        $calls = 0;
        $openAiClient = Mockery::mock(OpenAiClient::class);
        $openAiClient->shouldReceive('createResponse')
            ->once()
            ->andReturnUsing(function () use (&$calls) {
                $calls++;

                throw new RuntimeException('OpenAI request to [responses] failed with HTTP status [401]: invalid_api_key');
            });

        $this->expectException(RuntimeException::class);

        try {
            $this->execute($openAiClient);
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function test_schema_error_after_a_received_response_is_not_network_retried(): void
    {
        // status=completed but the output text is not valid JSON — decode() throws AFTER
        // sendWithNetworkRetry() already returned successfully, so this path never sees a
        // network-retry decision at all: exactly one HTTP call is made.
        $calls = 0;
        $openAiClient = Mockery::mock(OpenAiClient::class);
        $openAiClient->shouldReceive('createResponse')
            ->once()
            ->andReturnUsing(function () use (&$calls) {
                $calls++;

                return $this->completedResponse('not valid json {{{');
            });

        $this->expectException(EnterpriseWikiResponseInvalidJsonException::class);

        try {
            $this->execute($openAiClient);
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    // =========================================================================
    // Job-budget guard
    // =========================================================================

    public function test_network_retry_is_skipped_when_remaining_job_budget_is_too_small(): void
    {
        $calls = 0;
        $openAiClient = Mockery::mock(OpenAiClient::class);
        $openAiClient->shouldReceive('createResponse')
            ->once()
            ->andReturnUsing(function () use (&$calls) {
                $calls++;

                throw new ConnectionException('cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received');
            });

        // min_seconds=30 (setUp); a remaining budget of 5s after the first attempt is far below
        // that, so the retry must be skipped and the original exception rethrown immediately.
        $context = new AiCallContext(runId: 1, documentId: 2, remainingJobBudgetSeconds: 5);

        $this->expectException(ConnectionException::class);

        try {
            $this->execute($openAiClient, $context);
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    // =========================================================================
    // Capacity retry vs. network retry — independently bounded, never unbounded combined
    // =========================================================================

    public function test_capacity_retry_and_network_retry_compose_but_stay_bounded_to_four_calls(): void
    {
        $calls = 0;
        $openAiClient = Mockery::mock(OpenAiClient::class);
        $openAiClient->shouldReceive('createResponse')
            ->times(4)
            ->andReturnUsing(function () use (&$calls) {
                $calls++;

                return match ($calls) {
                    // Capacity level 0: network-fails once, then succeeds but is itself
                    // "incomplete" (max_output_tokens) — triggers the SEPARATE capacity retry.
                    1 => throw new ConnectionException('cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received'),
                    2 => $this->incompleteMaxTokensResponse(),
                    // Capacity level 1: network-fails once, then succeeds with a real payload.
                    3 => throw new ConnectionException('cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received'),
                    default => $this->completedResponse(),
                };
            });

        $result = $this->execute($openAiClient);

        $this->assertSame(4, $calls);
        $this->assertSame(['foo' => 'bar'], $result);
    }

    // =========================================================================
    // Structured logging
    // =========================================================================

    public function test_attempt_logging_includes_operation_attempt_number_and_duration_without_sensitive_data(): void
    {
        $openAiClient = Mockery::mock(OpenAiClient::class);
        $openAiClient->shouldReceive('createResponse')->once()->andReturn($this->completedResponse());

        $loggedFieldKeys = [];
        Log::spy();

        $this->execute($openAiClient);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use (&$loggedFieldKeys): bool {
                if (! str_contains($message, 'AI call attempt succeeded')) {
                    return false;
                }

                $loggedFieldKeys = array_keys($context);

                return $context['operation'] === 'test_operation'
                    && $context['attempt_number'] === 1
                    && array_key_exists('duration_seconds', $context)
                    && array_key_exists('computed_timeout_seconds', $context);
            })
            ->atLeast()->once();

        foreach (['api_key', 'authorization', 'prompt', 'document_text', 'full_prompt'] as $forbiddenKey) {
            $this->assertNotContains($forbiddenKey, $loggedFieldKeys);
        }
    }

    public function test_failed_attempt_logging_never_claims_openai_was_the_cause_for_a_connection_timeout(): void
    {
        $openAiClient = Mockery::mock(OpenAiClient::class);
        $openAiClient->shouldReceive('createResponse')
            ->twice()
            ->andReturnUsing(function () {
                static $calls = 0;
                $calls++;

                if ($calls === 1) {
                    throw new ConnectionException('cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received');
                }

                return $this->completedResponse();
            });

        $failureContext = null;
        Log::shouldReceive('warning')
            ->withArgs(function (string $message, array $context) use (&$failureContext): bool {
                if (str_contains($message, 'AI call attempt failed')) {
                    $failureContext = $context;
                }

                return true;
            })
            ->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->execute($openAiClient);

        $this->assertNotNull($failureContext, 'Expected one "AI call attempt failed" warning to be logged.');
        // A connection-level timeout must be classified as network_timeout / before_response —
        // never a generic/OpenAI-attributed category — since zero bytes of a response were ever
        // received.
        $this->assertSame('network_timeout', $failureContext['failure_category']);
        $this->assertSame('before_response', $failureContext['phase']);
        $this->assertTrue($failureContext['is_transient']);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
