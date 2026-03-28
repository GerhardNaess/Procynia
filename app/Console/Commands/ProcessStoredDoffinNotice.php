<?php

namespace App\Console\Commands;

use App\Services\Doffin\DoffinNoticePipelineService;
use Illuminate\Console\Command;
use Throwable;

class ProcessStoredDoffinNotice extends Command
{
    protected $signature = 'doffin:process-notice {noticeId}';

    protected $description = 'Run the stored Doffin notice through the full parse and scoring pipeline.';

    public function handle(DoffinNoticePipelineService $pipelineService): int
    {
        $noticeId = (string) $this->argument('noticeId');

        $this->line("Processing notice: {$noticeId}");
        $this->line('Step 1: parse notice');
        $this->line('Step 2: parse CPV codes');
        $this->line('Step 3: parse lots');
        $this->line('Step 4: score notice');

        try {
            $result = $pipelineService->process($noticeId);

            foreach ($result['completed_steps'] as $step) {
                $this->info("Completed: {$step}");
            }

            $this->info('Doffin notice pipeline completed successfully.');
            $this->line('parsed_notice_fields: '.($result['parsed_notice_fields'] === [] ? 'none' : implode(', ', $result['parsed_notice_fields'])));
            $this->line('parsed_cpv_codes: '.($result['parsed_cpv_codes'] === [] ? 'none' : implode(', ', $result['parsed_cpv_codes'])));
            $this->line('parsed_lots_count: '.$result['parsed_lots_count']);
            $this->line('relevance_score: '.$result['relevance_score']);
            $this->line('relevance_level: '.$result['relevance_level']);

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            report($throwable);

            $this->error('Doffin notice pipeline failed.');
            $this->line($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
