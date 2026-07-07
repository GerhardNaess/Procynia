<?php

namespace App\Console\Commands;

use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiIngest;
use App\Models\Customer;
use App\Services\Ai\Wiki\EnterpriseWikiIngestService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

#[Signature('wiki:ingest {--customer=} {--version-id=} {--dry-run} {--force}')]
#[Description('Queue an Enterprise Wiki ingest run for a specific KnowledgeItemVersion.')]
class IngestEnterpriseWikiVersion extends Command
{
    public function handle(EnterpriseWikiIngestService $service): int
    {
        $customerId = (int) $this->option('customer');
        $versionId = (int) $this->option('version-id');

        if (! $customerId || ! $versionId) {
            $this->error('Both --customer and --version are required.');

            return self::FAILURE;
        }

        $customer = Customer::query()->find($customerId);

        if ($customer === null) {
            $this->error("Customer [{$customerId}] not found.");

            return self::FAILURE;
        }

        try {
            $version = $service->resolveApprovedVersion($customerId, $versionId);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            Log::error('[WIKI_INGEST] Validation failed: '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Unexpected error during source validation: '.$e->getMessage());
            Log::error('[WIKI_INGEST] Unexpected validation error: '.$e->getMessage());

            return self::FAILURE;
        }

        $isForce = (bool) $this->option('force');

        if (! $isForce) {
            $existingRun = $service->findCompletedRun(
                $customerId,
                'knowledge_item_version',
                $versionId,
            );

            if ($existingRun !== null) {
                $message = sprintf(
                    '[WIKI_INGEST] Completed run already exists (run_id=%d). Use --force to re-ingest.',
                    $existingRun->id,
                );
                $this->warn($message);
                Log::info($message);

                return self::SUCCESS;
            }
        }

        if ((bool) $this->option('dry-run')) {
            $this->info(sprintf(
                '[DRY-RUN] Would queue wiki ingest for version [%d] (customer [%d]).',
                $versionId,
                $customerId,
            ));

            return self::SUCCESS;
        }

        $run = $service->createQueuedRun($customerId, $version);

        ProcessEnterpriseWikiIngest::dispatch($run->id);

        $message = sprintf(
            '[WIKI_INGEST][START] Queued ingest run. run_id=%d customer_id=%d version_id=%d',
            $run->id,
            $customerId,
            $versionId,
        );
        $this->info($message);
        Log::info($message);

        return self::SUCCESS;
    }
}
