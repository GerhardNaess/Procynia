<?php

namespace App\Console\Commands;

use App\Services\Ai\Usage\AiUsageLedger;
use App\Support\Ai\AiOperationCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;

/**
 * "Do we have unattributed AI calls?" — answered from the attempt ledger in one command.
 *
 * Read-only. Exits non-zero when an unattributed call exists in the window, so it can gate the
 * switch to AI_CONTEXT_ENFORCEMENT=strict: strict is safe to turn on once this has stayed green
 * across normal traffic, because strict would have refused exactly the calls it lists.
 */
#[AsCommand(name: 'ai:usage-integrity')]
class AiUsageIntegrity extends Command
{
    protected $signature = 'ai:usage-integrity
                            {--days=7 : How far back to look for unattributed calls (0 = since the ledger boundary)}';

    protected $description = 'Report unattributed, trusted and legacy AI usage attempts.';

    public function handle(AiUsageLedger $ledger): int
    {
        $days = max(0, (int) $this->option('days'));
        $since = $days === 0 ? null : CarbonImmutable::now()->subDays($days);
        $unattributed = $ledger->unattributed($since);
        $integrity = $ledger->integrity();

        $this->line(sprintf('Context enforcement: %s', AiOperationCatalog::enforcementIsStrict() ? 'strict' : 'warn'));
        $this->line(sprintf('Attempts: %d trusted, %d unattributed, %d legacy (before the usage-integrity boundary).', $integrity['trusted'], $integrity['unattributed'], $integrity['legacy']));

        if ($unattributed['count'] === 0) {
            $this->info(sprintf('No unattributed AI calls %s.', $since === null ? 'since the ledger boundary' : "in the last {$days} day(s)"));

            return self::SUCCESS;
        }

        $this->error(sprintf('%d unattributed AI call(s); last at %s.', $unattributed['count'], $unattributed['last_at']));
        $this->table(
            ['Feature', 'Operation', 'Calls', 'Last at'],
            array_map(fn (array $row): array => [$row['feature'], $row['operation_key'], $row['count'], $row['last_at']], $unattributed['operations']),
        );

        return self::FAILURE;
    }
}
