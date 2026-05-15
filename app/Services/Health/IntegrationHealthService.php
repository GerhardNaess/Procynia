<?php

namespace App\Services\Health;

use App\Models\BillingEvent;
use App\Models\DoffinImportRun;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class IntegrationHealthService
{
    /**
     * Purpose: Create the integration health service.
     * Inputs: The existing OpenAI client.
     * Returns: A ready-to-use service instance.
     * Side effects: None.
     */
    public function __construct(private readonly OpenAiClient $openAiClient)
    {
    }

    /**
     * Purpose: Check whether the latest successful Doffin import is fresh enough for monitoring.
     * Inputs: None.
     * Returns: A normalized health payload for the Doffin import freshness endpoint.
     * Side effects: Reads the canonical Doffin import run table.
     *
     * @return array<string, mixed>
     */
    public function doffinImportFreshness(): array
    {
        $checkedAt = now();

        try {
            $latestSuccessfulRun = DoffinImportRun::query()
                ->whereIn('status', ['success', 'partial', 'completed'])
                ->orderByDesc('finished_at')
                ->orderByDesc('started_at')
                ->first();

            $lastSuccessAt = $latestSuccessfulRun?->finished_at ?? $latestSuccessfulRun?->started_at;

            if (! $lastSuccessAt instanceof Carbon) {
                return [
                    'status' => 'fail',
                    'service' => 'doffin_import_freshness',
                    'checked_at' => $checkedAt->toIso8601String(),
                    'last_success_at' => null,
                    'threshold_minutes' => 90,
                    'message' => 'No successful Doffin import found.',
                ];
            }

            $freshnessThreshold = $checkedAt->copy()->subMinutes(90);
            $isFresh = $lastSuccessAt->greaterThan($freshnessThreshold);

            return [
                'status' => $isFresh ? 'ok' : 'fail',
                'service' => 'doffin_import_freshness',
                'checked_at' => $checkedAt->toIso8601String(),
                'last_success_at' => $lastSuccessAt->toIso8601String(),
                'threshold_minutes' => 90,
                'message' => $isFresh
                    ? 'Last successful Doffin import is within 90 minutes.'
                    : 'Last successful Doffin import is older than 90 minutes.',
            ];
        } catch (Throwable) {
            return [
                'status' => 'fail',
                'service' => 'doffin_import_freshness',
                'checked_at' => $checkedAt->toIso8601String(),
                'last_success_at' => null,
                'threshold_minutes' => 90,
                'message' => 'Doffin import status source is not available',
            ];
        }
    }

    /**
     * Purpose: Perform a minimal connectivity check against the OpenAI API.
     * Inputs: None.
     * Returns: A normalized health payload for the OpenAI connectivity endpoint.
     * Side effects: Issues one small GET request to the OpenAI models endpoint.
     *
     * @return array<string, mixed>
     */
    public function openAiConnectivity(): array
    {
        $checkedAt = now();
        $startedAt = microtime(true);

        try {
            $response = $this->openAiClient->get('models', 10);
            $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);

            if (! $response->successful()) {
                return [
                    'status' => 'fail',
                    'service' => 'openai_connectivity',
                    'checked_at' => $checkedAt->toIso8601String(),
                    'response_time_ms' => $responseTimeMs,
                    'message' => 'OpenAI connectivity check failed.',
                ];
            }

            return [
                'status' => 'ok',
                'service' => 'openai_connectivity',
                'checked_at' => $checkedAt->toIso8601String(),
                'response_time_ms' => $responseTimeMs,
                'message' => 'OpenAI connectivity is healthy.',
            ];
        } catch (Throwable $throwable) {
            $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);
            $message = Str::contains(Str::lower($throwable->getMessage()), ['api key', 'not configured'])
                ? 'OpenAI API key is not configured.'
                : 'OpenAI connectivity check failed.';

            return [
                'status' => 'fail',
                'service' => 'openai_connectivity',
                'checked_at' => $checkedAt->toIso8601String(),
                'response_time_ms' => $responseTimeMs,
                'message' => $message,
            ];
        }
    }

    /**
     * Purpose: Check whether Stripe webhook processing is producing local evidence of successful handling.
     * Inputs: None.
     * Returns: A normalized health payload for the Stripe webhook endpoint.
     * Side effects: Reads local billing events and failed-job rows only.
     *
     * @return array<string, mixed>
     */
    public function stripeWebhooks(): array
    {
        $checkedAt = now();

        try {
            $latestProcessedEvent = BillingEvent::query()
                ->where('source', 'webhook')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if (! $latestProcessedEvent) {
                return [
                    'status' => 'fail',
                    'service' => 'stripe_webhooks',
                    'checked_at' => $checkedAt->toIso8601String(),
                    'last_processed_at' => null,
                    'failed_count' => 0,
                    'pending_count' => 0,
                    'message' => 'Stripe webhook status source is not available',
                ];
            }

            $failedCount = $this->failedStripeWebhookCount();

            if ($failedCount > 0) {
                return [
                    'status' => 'fail',
                    'service' => 'stripe_webhooks',
                    'checked_at' => $checkedAt->toIso8601String(),
                    'last_processed_at' => $latestProcessedEvent->created_at?->toIso8601String(),
                    'failed_count' => $failedCount,
                    'pending_count' => 0,
                    'message' => 'One or more Stripe webhooks failed.',
                ];
            }

            return [
                'status' => 'ok',
                'service' => 'stripe_webhooks',
                'checked_at' => $checkedAt->toIso8601String(),
                'last_processed_at' => $latestProcessedEvent->created_at?->toIso8601String(),
                'failed_count' => 0,
                'pending_count' => 0,
                'message' => 'Stripe webhook processing is healthy.',
            ];
        } catch (Throwable) {
            return [
                'status' => 'fail',
                'service' => 'stripe_webhooks',
                'checked_at' => $checkedAt->toIso8601String(),
                'last_processed_at' => null,
                'failed_count' => 0,
                'pending_count' => 0,
                'message' => 'Stripe webhook status source is not available',
            ];
        }
    }

    /**
     * Purpose: Count failed job rows that look like failed Stripe webhook handling.
     * Inputs: None.
     * Returns: The number of relevant failed-job rows.
     * Side effects: Reads the canonical failed jobs table.
     */
    private function failedStripeWebhookCount(): int
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');

        return (int) DB::table($table)
            ->where(function ($query): void {
                $query->where('payload', 'like', '%StripeWebhookController%')
                    ->orWhere('payload', 'like', '%cashier.webhook%')
                    ->orWhere('payload', 'like', '%StripeWebhook%')
                    ->orWhere('exception', 'like', '%StripeWebhookController%')
                    ->orWhere('exception', 'like', '%stripe%');
            })
            ->count();
    }
}
