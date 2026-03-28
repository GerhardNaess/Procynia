<?php

namespace App\Console\Commands;

use App\Services\Doffin\DoffinParseService;
use Illuminate\Console\Command;
use Throwable;

class ParseStoredDoffinNotice extends Command
{
    protected $signature = 'doffin:parse-notice {noticeId}';

    protected $description = 'Parse stored Doffin XML for a notice and update the notice row.';

    public function handle(DoffinParseService $parseService): int
    {
        $noticeId = (string) $this->argument('noticeId');

        try {
            $result = $parseService->parseStoredNotice($noticeId);
            $updatedFields = $result['updated_fields'];

            $this->info('Doffin parse completed successfully.');
            $this->line("notice_id: {$noticeId}");
            $this->line('updated_fields: '.($updatedFields === [] ? 'none' : implode(', ', $updatedFields)));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            report($throwable);

            $this->error('Doffin parse failed.');
            $this->line($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
