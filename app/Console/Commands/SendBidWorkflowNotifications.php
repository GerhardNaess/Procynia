<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\BidWorkflowNotificationService;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;

/**
 * The daily sweep for the two notifications nothing else can trigger.
 *
 * A task assignment and a watch-profile match happen when somebody does something, so they notify
 * from the action itself. A deadline approaching and a case going quiet are the passage of time —
 * nobody performs them, so something has to look.
 *
 * One command for both, because they ask the same question of the same rows. Running it twice in a
 * day writes nothing the first run did not: every notification is idempotent on its dedupe_key.
 */
#[AsCommand(name: 'notifications:bid-workflow')]
class SendBidWorkflowNotifications extends Command
{
    protected $signature = 'notifications:bid-workflow
                            {--customer= : Limit the sweep to one customer}';

    protected $description = 'Notify bid managers and commercial owners about approaching deadlines and cases without progress.';

    public function handle(BidWorkflowNotificationService $notifications): int
    {
        $customerId = $this->option('customer');

        $customers = Customer::query()
            ->where('is_active', true)
            ->when($customerId !== null, fn ($query) => $query->whereKey((int) $customerId))
            ->orderBy('id')
            ->pluck('name', 'id');

        if ($customers->isEmpty()) {
            $this->warn('No active customers to sweep.');

            return self::SUCCESS;
        }

        $totals = ['deadline' => 0, 'inactive' => 0];

        foreach ($customers as $id => $name) {
            // One customer's data problem must not stop the others from being told about theirs.
            try {
                $counts = $notifications->sweepCustomer((int) $id);
            } catch (\Throwable $e) {
                $this->error(sprintf('[%d] %s: %s', $id, $name, $e->getMessage()));

                continue;
            }

            $totals['deadline'] += $counts['deadline'];
            $totals['inactive'] += $counts['inactive'];

            if ($counts['deadline'] > 0 || $counts['inactive'] > 0) {
                $this->line(sprintf(
                    '[%d] %s — frister: %d, uten fremdrift: %d',
                    $id,
                    $name,
                    $counts['deadline'],
                    $counts['inactive'],
                ));
            }
        }

        $this->info(sprintf(
            'Ferdig: %d kunder, %d fristvarsler, %d fremdriftsvarsler.',
            $customers->count(),
            $totals['deadline'],
            $totals['inactive'],
        ));

        return self::SUCCESS;
    }
}
