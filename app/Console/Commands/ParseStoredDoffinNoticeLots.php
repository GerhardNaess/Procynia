<?php

namespace App\Console\Commands;

use App\Services\Doffin\DoffinLotParseService;
use Illuminate\Console\Command;
use Throwable;

class ParseStoredDoffinNoticeLots extends Command
{
    protected $signature = 'doffin:parse-lots {noticeId}';

    protected $description = 'Parse stored Doffin XML lots for a notice and sync them to notice_lots.';

    public function handle(DoffinLotParseService $parseService): int
    {
        $noticeId = (string) $this->argument('noticeId');

        try {
            $result = $parseService->parseStoredNotice($noticeId);
            $lots = $result['lots'];

            $this->info('Doffin lot parse completed successfully.');
            $this->line("notice_id: {$noticeId}");
            $this->line('lot_count: '.$result['lot_count']);

            if ($lots === []) {
                $this->line('lots: none');
            } else {
                foreach ($lots as $index => $lot) {
                    $title = $lot['lot_title'] ?? 'null';
                    $description = $lot['lot_description'] ?? 'null';

                    $this->line('lot_'.($index + 1).": title={$title}; description={$description}");
                }
            }

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            report($throwable);

            $this->error('Doffin lot parse failed.');
            $this->line($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
