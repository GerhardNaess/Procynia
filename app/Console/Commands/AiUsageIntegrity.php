<?php

namespace App\Console\Commands;

use App\Services\Ai\Usage\AiUsageLedger;
use App\Support\Ai\AiOperationCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;

/**
 * Is the attempt ledger fit to be the economic source of truth — and is it safe to switch on
 * AI_CONTEXT_ENFORCEMENT=strict? Answered from the ledger in one command, usable as a deploy or
 * operational gate: exit 0 = ready, non-zero = not ready.
 *
 * Always checked, over the window:
 *  - no unattributed calls (strict would have refused exactly these);
 *  - every customer call has its customer, and every row a registered feature and operation;
 *  - system work is explicitly classified as `system.*`;
 *  - every row has a settlement.
 *
 * `--gate` adds the evidence the strict switch requires (docs/operations/ai-usage.md): the window
 * is covered by real traffic, and it contains an Anbud extraction and Wiki work. What the ledger
 * cannot prove — a completed `wiki:maintenance-cycle`, module usage per customer — is printed as
 * a manual confirmation and never reported as verified.
 */
#[AsCommand(name: 'ai:usage-integrity')]
class AiUsageIntegrity extends Command
{
    /** The minimum window before strict may be switched on. */
    public const STRICT_GATE_DAYS = 14;

    protected $signature = 'ai:usage-integrity
                            {--days=7 : How far back to check (0 = since the ledger boundary)}
                            {--gate : Strict-activation gate: also require traffic coverage, an Anbud extraction and Wiki work in the window}';

    protected $description = 'Verify AI usage attribution and ledger integrity; exits non-zero when not ready.';

    public function handle(AiUsageLedger $ledger): int
    {
        $days = max(0, (int) $this->option('days'));
        $gate = (bool) $this->option('gate');
        $since = $days === 0 ? null : CarbonImmutable::now()->subDays($days);
        $window = $since === null ? 'since the ledger boundary' : "in the last {$days} day(s)";
        $integrity = $ledger->integrity();
        $report = $ledger->attributionIntegrity($since);

        $this->line(sprintf('Context enforcement: %s', AiOperationCatalog::enforcementIsStrict() ? 'strict' : 'warn'));
        $this->line(sprintf('Attempts: %d trusted, %d unattributed, %d legacy (before the usage-integrity boundary).', $integrity['trusted'], $integrity['unattributed'], $integrity['legacy']));

        $checks = [
            ['No unattributed AI calls', $report['unattributed']['count'] === 0, $report['unattributed']['count'].' unattributed'],
            ['Customer calls carry their customer', $report['customer_without_customer_id'] === 0, $report['customer_without_customer_id'].' without customer_id'],
            ['Calls carry a feature', $report['missing_feature'] === 0, $report['missing_feature'].' without feature'],
            ['Calls carry a registered operation', $report['unregistered_operations'] === [], $this->operationList($report['unregistered_operations'])],
            ['System work is explicitly classified', $report['system_not_classified'] === 0, $report['system_not_classified'].' system call(s) outside system.*'],
            ['Every call has a settlement', $report['missing_settlement'] === 0, $report['missing_settlement'].' without settlement_status'],
        ];

        if ($gate) {
            $minimumDays = self::STRICT_GATE_DAYS;
            $coveredDays = $report['first_attempt_at'] === null ? 0 : (int) CarbonImmutable::parse($report['first_attempt_at'])->diffInDays(CarbonImmutable::now());

            $checks[] = ["Window is at least {$minimumDays} days", $days === 0 || $days >= $minimumDays, "--days={$days}"];
            $checks[] = ['Ledger has traffic for the whole window', $report['first_attempt_at'] !== null && ($days === 0 || $coveredDays >= $days), $report['first_attempt_at'] === null ? 'no post-boundary attempts' : "first attempt {$report['first_attempt_at']} ({$coveredDays} day(s) ago)"];
            $checks[] = ['At least one Anbud extraction', $report['tender_extraction_calls'] > 0, $report['tender_extraction_calls'].' extraction call(s)'];
            $checks[] = ['Wiki work in the window', $report['wiki_calls'] > 0, $report['wiki_calls'].' Wiki call(s)'];
        }

        $this->table(['Check', 'Result', 'Detail'], array_map(
            fn (array $check): array => [$check[0], $check[1] ? 'OK' : 'FAIL', $check[2]],
            $checks,
        ));

        if ($report['unattributed']['count'] > 0) {
            $this->error(sprintf('%d unattributed AI call(s) %s; last at %s.', $report['unattributed']['count'], $window, $report['unattributed']['last_at']));
            $this->table(
                ['Feature', 'Operation', 'Calls', 'Last at'],
                array_map(fn (array $row): array => [$row['feature'], $row['operation_key'], $row['count'], $row['last_at']], $report['unattributed']['operations']),
            );
        }

        if ($gate) {
            $this->line('Confirm manually before enabling strict (the ledger cannot prove these):');
            $this->line('  - at least one wiki:maintenance-cycle completed in the window (scheduler log: [WIKI_MAINTENANCE] Maintenance cycle complete);');
            $this->line('  - Wiki/Kvalitet/other modules were exercised by every customer that actually uses them.');
        }

        $ready = ! in_array(false, array_column($checks, 1), true);

        if ($ready) {
            $this->info(sprintf('AI usage ledger integrity OK %s.', $window));

            return self::SUCCESS;
        }

        $this->error(sprintf('AI usage ledger integrity NOT OK %s.', $window));

        return self::FAILURE;
    }

    /** @param list<array{operation_key: string, count: int}> $rows */
    private function operationList(array $rows): string
    {
        return $rows === []
            ? '0 unregistered'
            : implode(', ', array_map(static fn (array $row): string => $row['operation_key'].' ×'.$row['count'], $rows));
    }
}
