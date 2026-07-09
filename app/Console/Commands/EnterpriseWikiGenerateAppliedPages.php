<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('wiki:generate-applied-pages {--run-id=}')]
#[Description('Generate content_markdown and page versions for article and summary pages linked to an applied maintainer decision run.')]
class EnterpriseWikiGenerateAppliedPages extends Command
{
    public function handle(EnterpriseWikiGenerateAppliedPagesService $service): int
    {
        $runId = (int) $this->option('run-id');

        if (! $runId) {
            $this->error('--run-id is required.');

            return self::FAILURE;
        }

        $run = EnterpriseWikiIngestRun::query()->find($runId);

        if ($run === null) {
            $this->error("Ingest run [{$runId}] not found.");

            return self::FAILURE;
        }

        try {
            $result = $service->generate($run);
        } catch (\InvalidArgumentException $e) {
            $this->error('[WIKI_GENERATE] ' . $e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('[WIKI_GENERATE] Unexpected error: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '[WIKI_GENERATE] Run [%d] — Generated: %d, Skipped: %d.',
            $run->id,
            $result['generated'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
