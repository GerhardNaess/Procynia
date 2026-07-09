<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('wiki:build-page-links {--run-id=}')]
#[Description('Build deterministic forward and backlinks between wiki pages linked to an applied maintainer decision run.')]
class EnterpriseWikiBuildPageLinks extends Command
{
    public function handle(EnterpriseWikiBuildPageLinksService $service): int
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
            $result = $service->build($run);
        } catch (\InvalidArgumentException $e) {
            $this->error('[WIKI_LINKS] ' . $e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('[WIKI_LINKS] Unexpected error: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('[WIKI_LINKS] Enterprise Wiki page links built.');
        $this->line(sprintf('  Run ID:           %d', $run->id));
        $this->line(sprintf('  Pages checked:    %d', $result['pages_checked']));
        $this->line(sprintf('  Links created:    %d', $result['links_created']));
        $this->line(sprintf('  Links skipped:    %d', $result['links_skipped']));
        $this->line(sprintf('  Missing versions: %d', $result['missing_versions']));
        $this->line(sprintf('  Failed:           %d', $result['failed']));

        return self::SUCCESS;
    }
}
