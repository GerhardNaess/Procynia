<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('wiki:verify-page-claims {--run-id=}')]
#[Description('Verify claims extracted from wiki page versions against the originating source document and write supporting source references.')]
class EnterpriseWikiVerifyPageClaims extends Command
{
    public function handle(EnterpriseWikiVerifyPageClaimsService $service): int
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
            $result = $service->verify($run);
        } catch (\InvalidArgumentException $e) {
            $this->error('[WIKI_VERIFY] ' . $e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('[WIKI_VERIFY] Unexpected error: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('[WIKI_VERIFY] Run [%d] complete.', $run->id));
        $this->line(sprintf('  Pages checked:       %d', $result['pages']));
        $this->line(sprintf('  Claims checked:      %d', $result['claims']));
        $this->line(sprintf('  References created:  %d', $result['references']));
        $this->line(sprintf('  Skipped:             %d', $result['skipped']));
        $this->line(sprintf('  No support found:    %d', $result['no_support']));

        return self::SUCCESS;
    }
}
