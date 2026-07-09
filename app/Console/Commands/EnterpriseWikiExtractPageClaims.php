<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('wiki:extract-page-claims {--run-id=}')]
#[Description('Extract claims from generated wiki page versions linked to an applied maintainer decision run.')]
class EnterpriseWikiExtractPageClaims extends Command
{
    public function handle(EnterpriseWikiExtractPageClaimsService $service): int
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
            $result = $service->extract($run);
        } catch (\InvalidArgumentException $e) {
            $this->error('[WIKI_CLAIMS] ' . $e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('[WIKI_CLAIMS] Unexpected error: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('[WIKI_CLAIMS] Run [%d] complete.', $run->id));
        $this->line(sprintf('  Pages processed:  %d', $result['pages']));
        $this->line(sprintf('  Claims created:   %d', $result['claims']));
        $this->line(sprintf('  Pages skipped:    %d', $result['skipped']));

        return self::SUCCESS;
    }
}
