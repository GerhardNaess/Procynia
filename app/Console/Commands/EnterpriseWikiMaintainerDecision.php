<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('wiki:maintainer-decision {--customer=} {--document-id=}')]
#[Description('Dry-run maintainer decision for a wiki document. No pages or versions are written.')]
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

        $this->info('[WIKI_MAINTAINER][DRY-RUN] Decision (no pages written):');
        $this->line(
            (string) json_encode($decision, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return self::SUCCESS;
    }
}
