<?php

namespace App\Console\Commands;

use App\Services\Doffin\DoffinWatchProfileMatchService;
use Illuminate\Console\Command;

class MatchStoredDoffinWatchProfiles extends Command
{
    protected $signature = 'doffin:watch-match {--customer=} {--watch-profile=}';

    protected $description = 'Match active watch profiles against stored Doffin notices and persist match results.';

    public function handle(DoffinWatchProfileMatchService $service): int
    {
        $customerId = $this->validatedIntegerOption('customer');

        if ($customerId === false) {
            return self::INVALID;
        }

        $watchProfileId = $this->validatedIntegerOption('watch-profile');

        if ($watchProfileId === false) {
            return self::INVALID;
        }

        $summary = $service->run($customerId, $watchProfileId);

        $this->info('Watch profile matching completed.');
        $this->line('profiles_processed: '.$summary['profiles_processed']);
        $this->line('notices_evaluated: '.$summary['notices_evaluated']);
        $this->line('matches_created: '.$summary['matches_created']);
        $this->line('matches_updated: '.$summary['matches_updated']);

        return self::SUCCESS;
    }

    private function validatedIntegerOption(string $name): int|false|null
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_scalar($value) || ! ctype_digit((string) $value) || (int) $value <= 0) {
            $this->error(sprintf('The --%s option must be a positive integer.', $name));

            return false;
        }

        return (int) $value;
    }
}
