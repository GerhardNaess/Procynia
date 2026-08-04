<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiMaintainerDecisionBatch;
use Illuminate\Support\Str;

class EnterpriseWikiMaintainerDecisionBatchStateService
{
    private const LEASE_SECONDS = 2100;

    /** @param list<array<string,mixed>> $inputs */
    public function createBatches(int $runId, array $inputs): void
    {
        $total = count($inputs);
        foreach ($inputs as $index => $input) {
            EnterpriseWikiMaintainerDecisionBatch::query()->firstOrCreate(
                ['enterprise_wiki_ingest_run_id' => $runId, 'batch_number' => $index + 1],
                ['total_batches' => $total, 'input_payload' => $input, 'status' => EnterpriseWikiMaintainerDecisionBatch::STATUS_PENDING],
            );
        }
    }

    /** @return array{batch: EnterpriseWikiMaintainerDecisionBatch, token: string}|null */
    public function reserve(int $runId, int $batchNumber): ?array
    {
        $token = (string) Str::uuid();
        $stale = now()->subSeconds(self::LEASE_SECONDS);
        $updated = EnterpriseWikiMaintainerDecisionBatch::query()
            ->where('enterprise_wiki_ingest_run_id', $runId)->where('batch_number', $batchNumber)
            ->whereIn('status', [EnterpriseWikiMaintainerDecisionBatch::STATUS_PENDING, EnterpriseWikiMaintainerDecisionBatch::STATUS_RUNNING])
            ->where(function ($query) use ($stale): void {
                $query->where('status', EnterpriseWikiMaintainerDecisionBatch::STATUS_PENDING)->orWhere('leased_at', '<', $stale);
            })
            ->update(['status' => EnterpriseWikiMaintainerDecisionBatch::STATUS_RUNNING, 'lease_token' => $token, 'leased_at' => now(), 'started_at' => now(), 'error_message' => null]);

        if ($updated !== 1) {
            return null;
        }

        return ['batch' => EnterpriseWikiMaintainerDecisionBatch::query()->where('enterprise_wiki_ingest_run_id', $runId)->where('batch_number', $batchNumber)->firstOrFail(), 'token' => $token];
    }

    public function complete(int $runId, int $batchNumber, string $token, array $result): bool
    {
        return EnterpriseWikiMaintainerDecisionBatch::query()->where('enterprise_wiki_ingest_run_id', $runId)->where('batch_number', $batchNumber)->where('status', EnterpriseWikiMaintainerDecisionBatch::STATUS_RUNNING)->where('lease_token', $token)->update(['status' => EnterpriseWikiMaintainerDecisionBatch::STATUS_COMPLETED, 'result_payload' => $result, 'lease_token' => null, 'leased_at' => null, 'completed_at' => now(), 'error_message' => null]) === 1;
    }

    public function fail(int $runId, int $batchNumber, string $token, string $message): bool
    {
        return EnterpriseWikiMaintainerDecisionBatch::query()->where('enterprise_wiki_ingest_run_id', $runId)->where('batch_number', $batchNumber)->where('status', EnterpriseWikiMaintainerDecisionBatch::STATUS_RUNNING)->where('lease_token', $token)->update(['status' => EnterpriseWikiMaintainerDecisionBatch::STATUS_FAILED, 'lease_token' => null, 'leased_at' => null, 'completed_at' => now(), 'error_message' => mb_substr($message, 0, 1000)]) === 1;
    }

    /** @return list<array<string,mixed>> */
    public function completedResults(int $runId): array
    {
        return EnterpriseWikiMaintainerDecisionBatch::query()->where('enterprise_wiki_ingest_run_id', $runId)->where('status', EnterpriseWikiMaintainerDecisionBatch::STATUS_COMPLETED)->orderBy('batch_number')->pluck('result_payload')->all();
    }
}
