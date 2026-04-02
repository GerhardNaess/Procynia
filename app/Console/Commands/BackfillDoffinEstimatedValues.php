<?php

namespace App\Console\Commands;

use App\Models\DoffinNotice;
use App\Services\Doffin\DoffinPersistenceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('doffin:backfill-estimated-values {--force : Recalculate rows even when numeric fields are already present.}')]
#[Description('Backfill numeric estimated value fields for persisted Doffin notices.')]
class BackfillDoffinEstimatedValues extends Command
{
    /**
     * Purpose:
     * Backfill numeric estimated value fields for persisted Doffin notices.
     *
     * Inputs:
     * The optional --force flag.
     *
     * Returns:
     * int
     *
     * Side effects:
     * Updates estimated value amount and currency code in doffin_notices.
     */
    public function handle(DoffinPersistenceService $service): int
    {
        $force = (bool) $this->option('force');
        $summary = [
            'processed' => 0,
            'parsed_ok' => 0,
            'updated' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        DoffinNotice::query()
            ->whereNotNull('estimated_value_display')
            ->orderBy('id')
            ->chunkById(200, function ($notices) use (&$summary, $service, $force): void {
                foreach ($notices as $notice) {
                    $summary['processed']++;

                    if (
                        ! $force
                        && $notice->estimated_value_amount !== null
                        && $notice->estimated_value_currency_code !== null
                    ) {
                        $summary['skipped']++;

                        continue;
                    }

                    $fields = $service->extractEstimatedValueFields([
                        'estimated_value_display' => $notice->estimated_value_display,
                        'estimated_value_amount' => $notice->estimated_value_amount,
                        'estimated_value_currency_code' => $notice->estimated_value_currency_code,
                    ]);

                    if ($fields['amount'] === null) {
                        $summary['failed']++;

                        continue;
                    }

                    $summary['parsed_ok']++;

                    $updates = [];

                    if ($notice->estimated_value_amount !== $fields['amount']) {
                        $updates['estimated_value_amount'] = $fields['amount'];
                    }

                    if ($notice->estimated_value_currency_code !== $fields['currency_code']) {
                        $updates['estimated_value_currency_code'] = $fields['currency_code'];
                    }

                    if ($updates === []) {
                        continue;
                    }

                    DB::table('doffin_notices')
                        ->where('id', $notice->id)
                        ->update($updates);

                    $summary['updated']++;
                }
            });

        $message = sprintf(
            '[PROCYNIA][DOFFIN][ESTIMATED_VALUE_BACKFILL] processed=%d parsed_ok=%d updated=%d failed=%d skipped=%d',
            $summary['processed'],
            $summary['parsed_ok'],
            $summary['updated'],
            $summary['failed'],
            $summary['skipped'],
        );

        $this->info($message);
        Log::info($message, $summary);

        return self::SUCCESS;
    }
}
