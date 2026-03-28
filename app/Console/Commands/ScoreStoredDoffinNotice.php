<?php

namespace App\Console\Commands;

use App\Models\Notice;
use App\Services\Doffin\DoffinImportService;
use App\Services\Doffin\DoffinRelevanceService;
use Illuminate\Console\Command;
use Throwable;

class ScoreStoredDoffinNotice extends Command
{
    protected $signature = 'doffin:score-notice {noticeId}';

    protected $description = 'Score a stored Doffin notice using active watch profiles and parsed notice data.';

    public function handle(
        DoffinRelevanceService $relevanceService,
        DoffinImportService $importService,
    ): int
    {
        $noticeId = (string) $this->argument('noticeId');

        try {
            $result = $relevanceService->scoreNotice($noticeId);
            $notice = Notice::query()->with('cpvCodes')->where('notice_id', $noticeId)->firstOrFail();
            $importService->updateDepartmentVisibility($notice);

            $this->info('Doffin notice scoring completed successfully.');
            $this->line("notice_id: {$result['notice_id']}");
            $this->line('relevance_score: '.$result['relevance_score']);
            $this->line('relevance_level: '.$result['relevance_level']);
            $this->line('matched_cpv_codes: '.($result['matched_cpv_codes'] === [] ? 'none' : implode(', ', $result['matched_cpv_codes'])));
            $this->line('applied_rules: '.($result['applied_rules'] === [] ? 'none' : implode(', ', $result['applied_rules'])));
            $this->line('department_visibility_refreshed: yes');

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            report($throwable);

            $this->error('Doffin notice scoring failed.');
            $this->line($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
