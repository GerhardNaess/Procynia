<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\EnterpriseWiki\GraphProjection\EnterpriseWikiGraphProjector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('wiki:graph-project {--customer= : Customer id to rebuild the Enterprise Wiki graph projection for}')]
#[Description('Rebuild the Enterprise Wiki graph projection for one customer from authoritative SQL data.')]
class EnterpriseWikiProjectGraph extends Command
{
    public function handle(EnterpriseWikiGraphProjector $projector): int
    {
        $customerId = (int) $this->option('customer');

        if ($customerId <= 0) {
            $this->error('--customer is required and must be a positive integer.');

            return self::FAILURE;
        }

        if (! Customer::query()->whereKey($customerId)->exists()) {
            $this->error("Customer [{$customerId}] not found.");

            return self::FAILURE;
        }

        try {
            $projector->rebuildCustomer($customerId);
        } catch (Throwable $e) {
            Log::error('[WIKI_GRAPH_PROJECTION] Customer rebuild failed.', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);

            $this->error('[WIKI_GRAPH_PROJECTION] Rebuild failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('[WIKI_GRAPH_PROJECTION] Customer graph projection rebuilt.');
        $this->line(sprintf('  Customer ID: %d', $customerId));

        return self::SUCCESS;
    }
}
