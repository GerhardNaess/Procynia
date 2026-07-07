<?php

namespace App\Console\Commands;

use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiIngest;
use App\Models\Customer;
use App\Models\EnterpriseWikiIngestRun;
use App\Services\Ai\Wiki\EnterpriseWikiIngestService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

#[Signature('wiki:ingest-document {--customer=} {--document-id=} {--dry-run} {--force}')]
#[Description('Queue an Enterprise Wiki ingest run for an uploaded EnterpriseWikiDocument.')]
class IngestEnterpriseWikiDocument extends Command
{
    public function handle(EnterpriseWikiIngestService $service): int
    {
        $customerId = (int) $this->option('customer');
        $documentId = (int) $this->option('document-id');

        if (! $customerId || ! $documentId) {
            $this->error('Both --customer and --document-id are required.');

            return self::FAILURE;
        }

        $customer = Customer::query()->find($customerId);

        if ($customer === null) {
            $this->error("Customer [{$customerId}] not found.");

            return self::FAILURE;
        }

        try {
            $document = $service->resolveDocumentForIngest($customerId, $documentId);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            Log::error('[WIKI_INGEST_DOC] Validation failed: '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Unexpected error during source validation: '.$e->getMessage());
            Log::error('[WIKI_INGEST_DOC] Unexpected validation error: '.$e->getMessage());

            return self::FAILURE;
        }

        $isForce = (bool) $this->option('force');

        if (! $isForce) {
            $existingRun = $service->findCompletedRun(
                $customerId,
                EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                $documentId,
            );

            if ($existingRun !== null) {
                $message = sprintf(
                    '[WIKI_INGEST_DOC] Completed run already exists (run_id=%d). Use --force to re-ingest.',
                    $existingRun->id,
                );
                $this->warn($message);
                Log::info($message);

                return self::SUCCESS;
            }
        }

        if ((bool) $this->option('dry-run')) {
            $this->info(sprintf(
                '[DRY-RUN] Would queue wiki ingest for document [%d] (customer [%d]).',
                $documentId,
                $customerId,
            ));

            return self::SUCCESS;
        }

        $run = $service->createQueuedRunForDocument($customerId, $document);

        ProcessEnterpriseWikiIngest::dispatch($run->id);

        $message = sprintf(
            '[WIKI_INGEST_DOC][START] Queued ingest run. run_id=%d customer_id=%d document_id=%d',
            $run->id,
            $customerId,
            $documentId,
        );
        $this->info($message);
        Log::info($message);

        return self::SUCCESS;
    }
}
