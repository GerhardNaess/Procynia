<?php

namespace App\Services\EnterpriseWiki;

use App\Data\Ai\AiCallContext;
use App\Data\Ai\Capacity\AiCapacityPlan;
use App\Data\Ai\Capacity\AiTimeoutPlan;
use App\Exceptions\EnterpriseWikiAiOutputCapacityExceededException;
use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseIncompleteException;
use App\Services\OpenAi\OpenAiClient;
use Closure;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one AI call attempt, and — only when it comes back status=incomplete with
 * reason=max_output_tokens — exactly one further attempt at a higher, capacity-planner-chosen
 * budget. Never re-parses or reuses the first attempt's (possibly partial) response text; the
 * retry is a brand-new call with the identical semantic input and schema, just a bigger
 * max_output_tokens. Any other exception (schema violation, refusal, malformed envelope, a
 * non-token incomplete reason) propagates immediately, untouched — only this one specific signal
 * ever triggers a CAPACITY retry.
 *
 * Wiki run-592: a SEPARATE, orthogonal retry now exists for documented-transient HTTP/network
 * failures (cURL timeout, connection reset, 429/502/503/504 — see
 * EnterpriseWikiTransientFailureClassifier) — at most ONE automatic retry of the raw HTTP call
 * itself, inside attemptCall(), never touching or resetting the capacity-retry state above. The
 * two compose (a capacity-retry attempt gets its own independent chance at one network retry too),
 * but each is independently bounded, so the combination can never create an unbounded number of
 * attempts.
 *
 * Also replaces the previous hardcoded `timeoutSeconds: 60` with
 * EnterpriseWikiAiRequestTimeoutPolicy's operation-aware, job-budget-aware resolution, and adds
 * structured, per-attempt observability logging (see logAttempt()) — built after the Wiki run-592
 * incident, where a 60-second ceiling was far too short for a large global-plan call and the
 * failure left no way to tell where the time actually went.
 *
 * Wiki run-60: it also owns the ONE corrupt-response policy. A caller that passes $parse has its
 * response parsed inside the attempt, so a response that is unusable — control bytes, invalid
 * UTF-8, not JSON (EnterpriseWikiCorruptResponseClassifier) — gets exactly one fresh attempt and
 * then fails hard. That used to be a loop on the in-process candidate-batch path only, which is why
 * run 60 died on the queued one; a policy that depends on which caller you came through is not a
 * policy. Callers that do not pass $parse are unchanged and simply get the decoded body.
 *
 * Extracted from EnterpriseWikiMaintainerDecisionAiClient so the exact same bounded-retry-plus-
 * logging mechanics are reusable by EnterpriseWikiMaintainerDecisionSplitCoordinator's own calls
 * (global plan + each candidate batch) without duplicating this logic — the two callers differ
 * only in how they build a capacity plan for a given retry level ($planFor), how they build a
 * timeout plan ($timeoutPlanFor), and how they build the OpenAI payload for a given budget
 * ($buildPayload).
 */
class EnterpriseWikiAiCapacityRetryExecutor
{
    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly EnterpriseWikiResponsesDecoder $responsesDecoder,
    ) {}

    /**
     * @param  Closure(int): AiCapacityPlan  $planFor  fn(int $retryAttempt): AiCapacityPlan
     * @param  Closure(int): array  $buildPayload  fn(int $maxOutputTokens): array
     * @param  Closure(AiCapacityPlan, ?int): AiTimeoutPlan  $timeoutPlanFor  fn(AiCapacityPlan $plan,
     *                                                                        ?int $remainingJobBudgetSeconds): AiTimeoutPlan — lets each caller pick the right
     *                                                                        EnterpriseWikiAiRequestTimeoutPolicy profile (default/global_plan/batch) without this
     *                                                                        class needing to know which one applies.
     * @return array<string, mixed> The decoded (but not yet schema-parsed) response body.
     *
     * @throws EnterpriseWikiAiOutputCapacityExceededException when the response is still
     *                                                         incomplete/max_output_tokens after one capacity retry.
     */
    public function execute(
        string $operationLabel,
        int $inputSizeChars,
        Closure $planFor,
        Closure $buildPayload,
        Closure $timeoutPlanFor,
        ?AiCallContext $context = null,
        ?Closure $parse = null,
    ): array {
        $context ??= AiCallContext::none();
        $plan = $planFor(0);

        try {
            return $this->attemptCall($buildPayload($plan->chosenMaxOutputTokens), $operationLabel, $plan, $inputSizeChars, $timeoutPlanFor, $context, $parse);
        } catch (EnterpriseWikiResponseIncompleteException $e) {
            if (! $e->reachedMaxOutputTokens()) {
                throw $e;
            }

            $retryPlan = $planFor(1);

            // A retry only helps when it actually buys output tokens. When the first attempt's
            // budget was already clamped to the operation's ceiling, the retry plan clamps to the
            // very same number — so the second call is a guaranteed repeat of the first failure at
            // full cost and latency (run 51 spent ~95 seconds on exactly this). Fail precisely
            // instead, and let the caller pick a bounded/split strategy.
            if ($retryPlan->chosenMaxOutputTokens <= $plan->chosenMaxOutputTokens) {
                Log::warning('[PROCYNIA][WIKI_AI_CAPACITY] Skipping capacity retry — a retry cannot raise this call\'s budget.', [
                    'operation' => $operationLabel,
                    'operation_type' => $plan->operationType,
                    'first_attempt_max_output_tokens' => $plan->chosenMaxOutputTokens,
                    'retry_max_output_tokens' => $retryPlan->chosenMaxOutputTokens,
                    'strategy' => $retryPlan->strategy,
                    'input_size_chars' => $inputSizeChars,
                    'run_id' => $context->runId,
                ]);

                throw new EnterpriseWikiAiOutputCapacityExceededException(
                    operationLabel: $operationLabel,
                    lastPlan: $plan,
                    actualOutputTokens: $e->diagnostics['output_tokens'] ?? null,
                    responseId: $e->diagnostics['response_id'] ?? null,
                    retrySkippedAsPointless: true,
                );
            }

            try {
                return $this->attemptCall($buildPayload($retryPlan->chosenMaxOutputTokens), $operationLabel, $retryPlan, $inputSizeChars, $timeoutPlanFor, $context, $parse);
            } catch (EnterpriseWikiResponseIncompleteException $e2) {
                if (! $e2->reachedMaxOutputTokens()) {
                    throw $e2;
                }

                throw new EnterpriseWikiAiOutputCapacityExceededException(
                    $operationLabel,
                    $retryPlan,
                    $e2->diagnostics['output_tokens'] ?? null,
                    $e2->diagnostics['response_id'] ?? null,
                );
            }
        }
    }

    /**
     * One capacity attempt, plus the corrupt-response policy when the caller supplied a parser.
     *
     * The retry repeats the SAME call at the SAME budget: an unusable response says nothing about
     * the budget or the prompt, only that these particular bytes came back broken. Exactly one, then
     * the failure propagates untouched — a second corrupt response is a signal about the call, not
     * noise to keep paying for.
     *
     * @return array<string, mixed>
     */
    private function attemptCall(
        array $payload,
        string $operationLabel,
        AiCapacityPlan $plan,
        int $inputSizeChars,
        Closure $timeoutPlanFor,
        AiCallContext $context,
        ?Closure $parse = null,
    ): array {
        try {
            return $this->attemptOnce($payload, $operationLabel, $plan, $inputSizeChars, $timeoutPlanFor, $context, $parse);
        } catch (EnterpriseWikiResponseIncompleteException $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($parse === null || ! EnterpriseWikiCorruptResponseClassifier::isCorrupt($e)) {
                throw $e;
            }

            Log::warning('[PROCYNIA][WIKI_AI_CORRUPT_RESPONSE] Unusable AI response — retrying once.', [
                'operation' => $operationLabel,
                'operation_type' => $plan->operationType,
                'run_id' => $context->runId,
                'document_id' => $context->documentId,
                'exception_class' => get_class($e),
            ]);

            try {
                return $this->attemptOnce($payload, $operationLabel, $plan, $inputSizeChars, $timeoutPlanFor, $context, $parse);
            } catch (Throwable $retryFailure) {
                if (EnterpriseWikiCorruptResponseClassifier::isCorrupt($retryFailure)) {
                    Log::warning('[PROCYNIA][WIKI_AI_CORRUPT_RESPONSE] AI response was unusable twice — failing.', [
                        'operation' => $operationLabel,
                        'run_id' => $context->runId,
                        'document_id' => $context->documentId,
                    ]);
                }

                throw $retryFailure;
            }
        }
    }

    /** @return array<string, mixed> */
    private function attemptOnce(
        array $payload,
        string $operationLabel,
        AiCapacityPlan $plan,
        int $inputSizeChars,
        Closure $timeoutPlanFor,
        AiCallContext $context,
        ?Closure $parse,
    ): array {
        $timeoutPlan = $timeoutPlanFor($plan, $context->remainingJobBudgetSeconds);

        $response = $this->sendWithNetworkRetry($payload, $timeoutPlan, $operationLabel, $plan, $inputSizeChars, $context);

        $this->logCapacityDecision($operationLabel, $plan, $timeoutPlan, $inputSizeChars, $response, $context);

        $decoded = $this->responsesDecoder->decode($response, $operationLabel);

        return $parse === null ? $decoded : $parse($decoded);
    }

    /**
     * Wiki run-592: at most ONE automatic retry of the raw HTTP call, and only when the first
     * attempt's failure classifies as transient (EnterpriseWikiTransientFailureClassifier) — never
     * for a schema/permanent/auth error. Every attempt (success or failure) is logged separately
     * via logAttempt(). A retry is skipped outright when the context's remaining job budget,
     * reduced by the time the first attempt already consumed, would be too small to be worth
     * attempting (below the configured minimum timeout) — never risking pushing the owning queue
     * job past its own timeout.
     *
     * @return array<string, mixed>
     */
    private function sendWithNetworkRetry(
        array $payload,
        AiTimeoutPlan $timeoutPlan,
        string $operationLabel,
        AiCapacityPlan $plan,
        int $inputSizeChars,
        AiCallContext $context,
    ): array {
        [$response, $exception, $elapsedSeconds] = $this->sendOnce($payload, $timeoutPlan->timeoutSeconds, $operationLabel, $plan, $inputSizeChars, $timeoutPlan, $context, attemptNumber: 1);

        if ($exception === null) {
            return $response;
        }

        if (! EnterpriseWikiTransientFailureClassifier::isTransient($exception->getMessage())) {
            throw $exception;
        }

        $contextAfterFirstAttempt = $context->withElapsedSeconds($elapsedSeconds);
        $minRetryBudgetSeconds = (int) config('ai_request_timeout.min_seconds', 30);

        if (
            $contextAfterFirstAttempt->remainingJobBudgetSeconds !== null
            && $contextAfterFirstAttempt->remainingJobBudgetSeconds < $minRetryBudgetSeconds
        ) {
            Log::info('[PROCYNIA][WIKI_MAINTAINER_DECISION_CAPACITY] Network retry skipped — insufficient remaining job budget.', [
                'operation' => $operationLabel,
                'run_id' => $contextAfterFirstAttempt->runId,
                'document_id' => $contextAfterFirstAttempt->documentId,
                'remaining_job_budget_seconds' => $contextAfterFirstAttempt->remainingJobBudgetSeconds,
                'min_retry_budget_seconds' => $minRetryBudgetSeconds,
            ]);

            throw $exception;
        }

        $this->sleepBeforeRetry();

        $retryTimeoutSeconds = $contextAfterFirstAttempt->remainingJobBudgetSeconds !== null
            ? min($timeoutPlan->timeoutSeconds, $contextAfterFirstAttempt->remainingJobBudgetSeconds)
            : $timeoutPlan->timeoutSeconds;

        [$retryResponse, $retryException] = $this->sendOnce($payload, $retryTimeoutSeconds, $operationLabel, $plan, $inputSizeChars, $timeoutPlan, $contextAfterFirstAttempt, attemptNumber: 2);

        if ($retryException !== null) {
            throw $retryException;
        }

        return $retryResponse;
    }

    /**
     * One raw HTTP attempt, with Guzzle transfer-stats capture (namelookup/connect/appconnect/
     * starttransfer/total time, primary IP, response code where the underlying transport exposes
     * them — captured whether the call succeeds or throws) and structured per-attempt logging.
     *
     * @return array{0: array<string, mixed>, 1: ?Throwable, 2: float} [response-or-empty-array,
     *                                                                 exception-or-null, elapsedSeconds] — response is only meaningful when exception is
     *                                                                 null.
     */
    private function sendOnce(
        array $payload,
        int $timeoutSeconds,
        string $operationLabel,
        AiCapacityPlan $plan,
        int $inputSizeChars,
        AiTimeoutPlan $timeoutPlan,
        AiCallContext $context,
        int $attemptNumber,
    ): array {
        $transferStats = null;
        $onStats = function (TransferStats $stats) use (&$transferStats): void {
            $transferStats = $stats;
        };

        $startedAt = microtime(true);

        try {
            $response = $this->openAiClient->createResponse($payload, $timeoutSeconds, $onStats);
            $elapsedSeconds = microtime(true) - $startedAt;

            $this->logAttempt($operationLabel, $plan, $timeoutPlan, $timeoutSeconds, $inputSizeChars, $context, $attemptNumber, $elapsedSeconds, $transferStats, $response, null);

            return [$response, null, $elapsedSeconds];
        } catch (Throwable $e) {
            $elapsedSeconds = microtime(true) - $startedAt;

            $this->logAttempt($operationLabel, $plan, $timeoutPlan, $timeoutSeconds, $inputSizeChars, $context, $attemptNumber, $elapsedSeconds, $transferStats, null, $e);

            return [[], $e, $elapsedSeconds];
        }
    }

    /**
     * Short, bounded backoff with jitter before the one allowed network retry — never a long
     * backoff, since this happens within one queued job's own timeout budget, not a separately
     * scheduled resumption.
     */
    private function sleepBeforeRetry(): void
    {
        $baseMs = (int) config('ai_request_timeout.network_retry_backoff_base_ms', 250);
        $jitterMs = (int) config('ai_request_timeout.network_retry_backoff_jitter_ms', 250);

        $sleepMs = $baseMs + ($jitterMs > 0 ? random_int(0, $jitterMs) : 0);

        usleep(max(0, $sleepMs) * 1000);
    }

    /**
     * One structured log line per HTTP attempt — the Wiki run-592 observability requirement: enough
     * to tell whether a slow/failed call was OpenAI, the network, or Procynia's own configured
     * ceiling, without ever logging the API key, Authorization header, full prompt, or full
     * document text.
     *
     * `phase` distinguishes where a failure occurred: 'before_response' (a connection-level
     * failure — Illuminate\Http\Client\ConnectionException — no bytes of a response were ever
     * received), 'after_response' (a response was received, e.g. a non-2xx status or a decode/
     * schema failure), or 'success' (no exception at all).
     */
    private function logAttempt(
        string $operationLabel,
        AiCapacityPlan $plan,
        AiTimeoutPlan $timeoutPlan,
        int $actualTimeoutSeconds,
        int $inputSizeChars,
        AiCallContext $context,
        int $attemptNumber,
        float $elapsedSeconds,
        ?TransferStats $transferStats,
        ?array $response,
        ?Throwable $exception,
    ): void {
        $fields = [
            'operation' => $operationLabel,
            'operation_type' => $plan->operationType,
            'run_id' => $context->runId,
            'document_id' => $context->documentId,
            'attempt_number' => $attemptNumber,
            'model' => $plan->model,
            'input_size_chars' => $inputSizeChars,
            'chosen_max_output_tokens' => $plan->chosenMaxOutputTokens,
            'computed_timeout_seconds' => $timeoutPlan->timeoutSeconds,
            'actual_timeout_seconds' => $actualTimeoutSeconds,
            'timeout_basis' => $timeoutPlan->basis,
            'duration_seconds' => round($elapsedSeconds, 3),
            'transfer_stats' => $this->transferStatsSummary($transferStats),
        ];

        if ($exception === null) {
            Log::info('[PROCYNIA][WIKI_MAINTAINER_DECISION_CAPACITY] AI call attempt succeeded.', [
                ...$fields,
                'phase' => 'success',
                'http_status' => data_get($response, '_meta.http_status'),
                'openai_request_id' => data_get($response, '_meta.request_id'),
                'response_id' => $this->safeScalar($response['id'] ?? null),
            ]);

            return;
        }

        Log::warning('[PROCYNIA][WIKI_MAINTAINER_DECISION_CAPACITY] AI call attempt failed.', [
            ...$fields,
            'phase' => $exception instanceof ConnectionException ? 'before_response' : 'after_response',
            'exception_class' => get_class($exception),
            'failure_category' => $this->normalizedFailureCategory($exception),
            'is_transient' => EnterpriseWikiTransientFailureClassifier::isTransient($exception->getMessage()),
            'error_message' => $exception->getMessage(),
        ]);
    }

    /**
     * Extracts the subset of Guzzle's raw curl_getinfo()-backed handler stats this task asks for.
     * A field genuinely never reached by the transfer (e.g. a request that failed before any
     * connection attempt) is reported as the literal string 'unavailable' rather than a guessed
     * zero — this method never invents a duration/IP that cURL did not actually report.
     *
     * @return array<string, mixed>
     */
    private function transferStatsSummary(?TransferStats $stats): array
    {
        $fields = ['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'total_time', 'primary_ip'];

        if ($stats === null) {
            return array_merge(
                array_fill_keys($fields, 'unavailable'),
                ['response_code' => 'unavailable'],
            );
        }

        $handlerStats = $stats->getHandlerStats();
        $summary = [];

        foreach ($fields as $field) {
            $summary[$field] = array_key_exists($field, $handlerStats) ? $handlerStats[$field] : 'unavailable';
        }

        $summary['response_code'] = array_key_exists('http_code', $handlerStats) ? $handlerStats['http_code'] : 'unavailable';

        return $summary;
    }

    /**
     * A small, honest classification of WHY an attempt failed — never claims OpenAI was the cause
     * without a real HTTP status/response backing that claim (Wiki run-592: the system must never
     * assert "OpenAI hadde feil" from a connection-level timeout alone, since 0 bytes were ever
     * received and the request may never have reached OpenAI at all).
     */
    private function normalizedFailureCategory(Throwable $exception): string
    {
        $message = mb_strtolower($exception->getMessage());

        return match (true) {
            $exception instanceof ConnectionException && (str_contains($message, 'timed out') || str_contains($message, 'timeout')) => 'network_timeout',
            $exception instanceof ConnectionException => 'network_connection_error',
            str_contains($message, 'http status [429]') || str_contains($message, 'rate_limit') || str_contains($message, 'too many requests') => 'rate_limited',
            str_contains($message, 'http status [500]')
                || str_contains($message, 'http status [502]')
                || str_contains($message, 'http status [503]')
                || str_contains($message, 'http status [504]')
                || str_contains($message, 'server_error') => 'server_error_5xx',
            str_contains($message, 'http status [4') => 'client_error_4xx',
            $exception instanceof \InvalidArgumentException => 'schema_or_validation_error',
            default => 'unknown',
        };
    }

    private function safeScalar(mixed $value): mixed
    {
        return is_scalar($value) ? $value : null;
    }

    /**
     * One structured log line per attempt covering the capacity decision and the actual usage
     * OpenAI reports — reuses EnterpriseWikiResponsesDecoder::diagnostics() (the same data the
     * decoder's own "Response rejected" warning uses on failure) rather than duplicating a raw
     * response dump. Never logs document text, secrets, or full raw AI output.
     */
    private function logCapacityDecision(
        string $operationLabel,
        AiCapacityPlan $plan,
        AiTimeoutPlan $timeoutPlan,
        int $inputSizeChars,
        array $response,
        AiCallContext $context,
    ): void {
        $diagnostics = $this->responsesDecoder->diagnostics($response, $operationLabel);

        Log::info('[PROCYNIA][WIKI_MAINTAINER_DECISION_CAPACITY] Capacity decision for AI call.', [
            'operation' => $operationLabel,
            'operation_type' => $plan->operationType,
            'run_id' => $context->runId,
            'document_id' => $context->documentId,
            'model' => $plan->model,
            'input_size_chars' => $inputSizeChars,
            'strategy' => $plan->strategy,
            'retry_level' => $plan->retryLevel,
            'chosen_max_output_tokens' => $plan->chosenMaxOutputTokens,
            'estimated_minimum_tokens' => $plan->estimatedMinimumTokens,
            'estimated_need_tokens' => $plan->estimatedNeedTokens,
            'max_allowed_tokens' => $plan->maxAllowedTokens,
            'was_clamped' => $plan->wasClamped,
            'basis' => $plan->basis,
            'computed_timeout_seconds' => $timeoutPlan->timeoutSeconds,
            'timeout_was_clamped_to_range' => $timeoutPlan->wasClampedToRange,
            'timeout_was_clamped_to_job_budget' => $timeoutPlan->wasClampedToJobBudget,
            'response_id' => $diagnostics['response_id'] ?? null,
            'response_status' => $diagnostics['response_status'] ?? null,
            'incomplete_reason' => $diagnostics['incomplete_reason'] ?? null,
            'output_tokens' => $diagnostics['output_tokens'] ?? null,
            'reasoning_tokens' => $diagnostics['reasoning_tokens'] ?? null,
            'total_tokens' => $diagnostics['total_tokens'] ?? null,
        ]);
    }
}
