<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('wiki:generate-applied-pages {--run-id=}')]
#[Description('Generate content_markdown and page versions for all page types (article, summary, concept, entity) linked to an applied maintainer decision run.')]
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

        $this->info(sprintf('[WIKI_GENERATE] Run [%d] complete.', $run->id));
        $this->line(sprintf('  Article:  %d generated', $result['article']));
        $this->line(sprintf('  Summary:  %d generated', $result['summary']));
        $this->line(sprintf('  Concept:  %d generated', $result['concept']));
        $this->line(sprintf('  Entity:   %d generated', $result['entity']));
        $this->line(sprintf('  Skipped:  %d', $result['skipped']));

        return self::SUCCESS;
    }
}
