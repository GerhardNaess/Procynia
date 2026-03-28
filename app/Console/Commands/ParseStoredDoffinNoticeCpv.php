<?php

namespace App\Console\Commands;

use App\Services\Doffin\DoffinCpvParseService;
use Illuminate\Console\Command;
use Throwable;

class ParseStoredDoffinNoticeCpv extends Command
{
    protected $signature = 'doffin:parse-cpv {noticeId}';

    protected $description = 'Parse stored Doffin XML CPV codes for a notice and sync them to notice_cpv_codes.';

    public function handle(DoffinCpvParseService $parseService): int
    {
        $noticeId = (string) $this->argument('noticeId');

        try {
            $result = $parseService->parseStoredNotice($noticeId);
            $cpvCodes = $result['cpv_codes'];

            $this->info('Doffin CPV parse completed successfully.');
            $this->line("notice_id: {$noticeId}");
            $this->line('cpv_count: '.$result['cpv_count']);
            $this->line('cpv_codes: '.($cpvCodes === [] ? 'none' : implode(', ', $cpvCodes)));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            report($throwable);

            $this->error('Doffin CPV parse failed.');
            $this->line($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
