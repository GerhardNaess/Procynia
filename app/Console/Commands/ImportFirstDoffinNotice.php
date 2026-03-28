<?php

namespace App\Console\Commands;

use App\Models\SyncLog;
use App\Services\Doffin\DoffinImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportFirstDoffinNotice extends Command
{
    protected $signature = 'doffin:import-first';

    protected $description = 'Import the first available Doffin notice and store its raw XML.';

    public function handle(DoffinImportService $importService): int
    {
        $startedAt = now();

        try {
            $result = $importService->importFirstNotice();
            $notice = $result['notice'];
            $noticeId = $result['notice_id'];
            $xmlStored = (bool) $result['xml_stored'];
            $finishedAt = now();

            SyncLog::query()->create([
                'job_type' => 'import_first',
                'status' => 'success',
                'notice_id' => $notice->id,
                'message' => "Imported notice {$noticeId}.",
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]);

            $this->info('Doffin import completed successfully.');
            $this->line("notice_id: {$noticeId}");
            $this->line('xml_stored: '.($xmlStored ? 'yes' : 'no'));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $finishedAt = now();

            SyncLog::query()->create([
                'job_type' => 'import_first',
                'status' => 'failed',
                'message' => $throwable->getMessage(),
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]);

            report($throwable);

            $this->error('Doffin import failed.');
            $this->line($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
