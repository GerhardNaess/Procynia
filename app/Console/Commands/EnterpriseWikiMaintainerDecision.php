<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

#[Signature('wiki:maintainer-decision {--customer=} {--document-id=} {--persist}')]
#[Description('Maintainer decision for a wiki document. Default: dry-run only. Use --persist to store the decision on an ingest run without creating pages.')]
class EnterpriseWikiMaintainerDecision extends Command
{
    public function handle(EnterpriseWikiMaintainerDecisionService $service): int
    {
        if (! EnterpriseWikiMaintainerDecisionAiClient::isAvailable()) {
            $this->error('[WIKI_MAINTAINER] AI is not enabled. Set ENTERPRISE_WIKI_AI_ENABLED=true to run.');

            return self::FAILURE;
        }

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

        $languageCode = $customer->language?->code ?? 'no';

        try {
            $decision = $service->runForDocument($customerId, $documentId, $languageCode);
        } catch (\InvalidArgumentException $e) {
            $this->error('[WIKI_MAINTAINER] ' . $e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('[WIKI_MAINTAINER] Unexpected error: ' . $e->getMessage());

            return self::FAILURE;
        }

        $json = (string) json_encode($decision, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! (bool) $this->option('persist')) {
            $this->info('[WIKI_MAINTAINER][DRY-RUN] Decision (no pages written):');
            $this->line($json);

            return self::SUCCESS;
        }

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid'                              => Str::uuid()->toString(),
            'customer_id'                       => $customerId,
            'trigger_type'                      => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                       => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                         => $documentId,
            'status'                            => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_json'          => $decision,
            'maintainer_decision_status'        => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at'  => now(),
        ]);

        $this->info(sprintf(
            '[WIKI_MAINTAINER][PERSISTED] Decision stored on ingest run %d (status: decision_only, no pages written):',
            $run->id,
        ));
        $this->line($json);

        return self::SUCCESS;
    }
}
