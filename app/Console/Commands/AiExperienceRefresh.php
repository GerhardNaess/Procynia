<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Ai\Experience\AiExperienceSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;

/**
 * Refreshes the AI experience snapshots (one per customer and billing period) from the trusted
 * ledger. Analysis data only — it never changes a weight, tier, override or price.
 *
 *   ai:experience-refresh                                  every customer, open and missing periods
 *   ai:experience-refresh --customer=12                    one customer
 *   ai:experience-refresh --customer=12 --period=2026-11-03 the period containing that date
 *   ai:experience-refresh --recheck-final                  also recompute final periods (changes are revisioned)
 *
 * Missing periods since the trusted-ledger boundary are created on the way, so the first run is
 * the backfill. Final periods are skipped unless --recheck-final or --period asks for them.
 */
#[AsCommand(name: 'ai:experience-refresh')]
class AiExperienceRefresh extends Command
{
    protected $signature = 'ai:experience-refresh
                            {--customer= : Customer id}
                            {--period= : A date inside the billing period to refresh (requires --customer)}
                            {--recheck-final : Recompute final periods too; every change is written as a revision}';

    protected $description = 'Refresh the internal AI experience snapshots per customer and billing period from the trusted ledger.';

    public function handle(AiExperienceSnapshotService $snapshots): int
    {
        if ($snapshots->trustedBoundary() === null) {
            $this->warn('No trusted ledger data yet: there is nothing to snapshot.');

            return self::SUCCESS;
        }

        $customer = null;

        if ($this->option('customer') !== null) {
            $customer = Customer::query()->find((int) $this->option('customer'));

            if (! $customer instanceof Customer) {
                $this->error('Unknown customer.');

                return self::FAILURE;
            }
        }

        if ($this->option('period') !== null) {
            if ($customer === null) {
                $this->error('--period requires --customer.');

                return self::FAILURE;
            }

            $result = $snapshots->refreshPeriod($customer, CarbonImmutable::parse((string) $this->option('period'), 'UTC'));
            $this->info("Period: {$result}");

            return self::SUCCESS;
        }

        $recheck = (bool) $this->option('recheck-final');
        $stats = $customer === null ? $snapshots->refreshAll($recheck) : $snapshots->refreshCustomer($customer, $recheck);

        $this->info(sprintf(
            'Created %d · updated %d · finalized %d · revised %d · skipped %d (trusted since %s)',
            $stats['created'], $stats['updated'], $stats['finalized'], $stats['revised'], $stats['skipped'],
            $snapshots->trustedBoundary()->toDateTimeString(),
        ));

        return self::SUCCESS;
    }
}
