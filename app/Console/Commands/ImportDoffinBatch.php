<?php

namespace App\Console\Commands;

use App\Services\Doffin\DoffinBatchImportService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ImportDoffinBatch extends Command
{
    protected $signature = 'doffin:import-batch {--limit=} {--trigger=manual}';

    protected $description = 'Import and process a batch of Doffin notices.';

    public function handle(DoffinBatchImportService $batchImportService): int
    {
        $limit = $this->resolveLimit();
        $trigger = $this->resolveTrigger();

        $this->line('Starting Doffin batch import.');
        $this->line("limit: {$limit}");
        $this->line("trigger: {$trigger}");

        try {
            $result = $batchImportService->importBatch($limit, function (string $type, array $payload): void {
                match ($type) {
                    'notice_ids_found' => $this->line('found_notice_ids: '.count($payload['notice_ids'] ?? []).' ['.implode(', ', $payload['notice_ids'] ?? []).']'),
                    'processing_notice' => $this->line('processing_notice: '.$payload['notice_id']),
                    'notice_processed' => $this->info('processed_notice: '.$payload['notice_id'].' score='.($payload['relevance_score'] ?? 'n/a').' level='.($payload['relevance_level'] ?? 'n/a')),
                    'notice_failed' => $this->error('failed_notice: '.$payload['notice_id'].' error='.$payload['error']),
                    default => null,
                };
            }, $trigger);

            $this->info('Doffin batch import completed.');
            $this->line('found_count: '.$result['found_count']);
            $this->line('processed_count: '.$result['processed_count']);
            $this->line('fetched_count: '.$result['fetched_count']);
            $this->line('created_count: '.$result['created_count']);
            $this->line('updated_count: '.$result['updated_count']);
            $this->line('skipped_count: '.$result['skipped_count']);
            $this->line('success_count: '.$result['success_count']);
            $this->line('failed_count: '.$result['failed_count']);
            $this->line('processed_notice_ids: '.($result['processed_notice_ids'] === [] ? 'none' : implode(', ', $result['processed_notice_ids'])));

            if ($result['failed_notice_ids'] !== []) {
                $this->line('failed_notice_ids: '.implode(', ', $result['failed_notice_ids']));
            }

            return $result['success_count'] > 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $throwable) {
            report($throwable);

            $this->error('Doffin batch import failed.');
            $this->line($throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveLimit(): int
    {
        $option = $this->option('limit');
        $resolved = $option === null ? config('doffin.batch_limit', 10) : $option;

        if (! is_numeric($resolved) || (int) $resolved < 1) {
            throw new RuntimeException('The --limit option must be a positive integer.');
        }

        return (int) $resolved;
    }

    private function resolveTrigger(): string
    {
        $trigger = (string) $this->option('trigger');

        if (! in_array($trigger, ['manual', 'scheduler'], true)) {
            throw new RuntimeException('The --trigger option must be either manual or scheduler.');
        }

        return $trigger;
    }
}
