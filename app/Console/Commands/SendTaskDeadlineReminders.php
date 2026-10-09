<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\MyTasks\TaskDeadlineReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;
use Throwable;

/**
 * The one daily sweep for fristpåminnelser across every module (TaskDeadlineReminderService). Safe to
 * run again: every reminder is idempotent on its dedupe_key, so a rerun after a failure, or a second
 * run the same day, writes nothing the first did not.
 */
#[AsCommand(name: 'notifications:task-reminders')]
class SendTaskDeadlineReminders extends Command
{
    protected $signature = 'notifications:task-reminders
                            {--customer= : Limit the sweep to one customer}
                            {--date= : Run as of this day (YYYY-MM-DD), for verification}';

    protected $description = 'Remind people of tasks under «Mine oppgaver» whose deadline is near or has passed.';

    public function handle(TaskDeadlineReminderService $reminders): int
    {
        $today = $this->option('date') !== null ? CarbonImmutable::parse((string) $this->option('date')) : CarbonImmutable::today();
        $customerId = $this->option('customer');

        $customers = Customer::query()
            ->where('is_active', true)
            ->when($customerId !== null, fn ($query) => $query->whereKey((int) $customerId))
            ->orderBy('id')
            ->get();

        $totals = ['users' => 0, 'due_soon' => 0, 'overdue' => 0, 'failed' => 0];

        foreach ($customers as $customer) {
            // One customer's data problem must not stop the others.
            try {
                $counts = $reminders->remindCustomer($customer, $today);
            } catch (Throwable $e) {
                $this->error(sprintf('[%d] %s: %s', $customer->id, $customer->name, $e->getMessage()));
                $totals['failed']++;

                continue;
            }

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $counts[$key];
            }
        }

        $this->info(sprintf(
            'Done: %d customers, %d people, %d «due soon», %d «overdue», %d failed.',
            $customers->count(),
            $totals['users'],
            $totals['due_soon'],
            $totals['overdue'],
            $totals['failed'],
        ));

        return self::SUCCESS;
    }
}
