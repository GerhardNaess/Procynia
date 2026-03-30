<?php

namespace App\Console\Commands;

use App\Services\Doffin\DoffinSupplierHarvestService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('doffin:harvest-suppliers {--from=} {--to=} {--type=*}')]
#[Description('Queue an asynchronous Doffin supplier harvest for a date range.')]
class HarvestDoffinSuppliers extends Command
{
    /**
     * Purpose:
     * Queue a new asynchronous Doffin supplier harvest run.
     *
     * Inputs:
     * CLI options for date range and notice type filters.
     *
     * Returns:
     * int
     *
     * Side effects:
     * Creates a queued harvest run row and dispatches its prepare job.
     */
    public function handle(DoffinSupplierHarvestService $service): int
    {
        try {
            $payload = $this->payload();
            $run = $service->startRun($payload);

            $message = sprintf(
                '[PROCYNIA][SUPPLIER_HARVEST][START] Queued supplier harvest run. run_uuid=%s from=%s to=%s types=%s',
                $run->uuid,
                $payload['from'],
                $payload['to'],
                implode(',', $payload['types']),
            );

            $this->info($message);
            Log::info($message);

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $message = '[PROCYNIA][SUPPLIER_HARVEST][FAIL] Failed to queue supplier harvest run: '.$throwable->getMessage();

            $this->error($message);
            Log::error($message);

            return self::FAILURE;
        }
    }

    /**
     * Purpose:
     * Build and validate the supplier harvest payload from CLI options.
     *
     * Inputs:
     * Command options.
     *
     * Returns:
     * array<string, mixed>
     *
     * Side effects:
     * Throws if required options are missing or invalid.
     */
    private function payload(): array
    {
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));

        if ($from === '' || $to === '') {
            throw new \RuntimeException('Both --from and --to are required.');
        }

        $fromDate = Carbon::parse($from)->toDateString();
        $toDate = Carbon::parse($to)->toDateString();

        if ($fromDate > $toDate) {
            throw new \RuntimeException('The --from date must be before or equal to --to.');
        }

        $types = collect($this->option('type') ?? [])
            ->map(fn (mixed $type): string => trim((string) $type))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'from' => $fromDate,
            'to' => $toDate,
            'types' => $types === [] ? ['RESULT'] : $types,
        ];
    }
}
