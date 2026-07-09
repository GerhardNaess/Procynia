<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionApplyService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('wiki:apply-maintainer-decision {--run-id=}')]
#[Description('Apply a persisted maintainer decision to create stub wiki pages. No content is generated.')]
class EnterpriseWikiApplyMaintainerDecision extends Command
{
    public function handle(EnterpriseWikiMaintainerDecisionApplyService $service): int
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
            $result = $service->apply($run);
        } catch (\InvalidArgumentException $e) {
            $this->error('[WIKI_APPLY] ' . $e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('[WIKI_APPLY] Unexpected error: ' . $e->getMessage());

            return self::FAILURE;
        }

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page:id,title,slug,page_type')
            ->get();

        $this->info(sprintf(
            '[WIKI_APPLY] Run [%d] applied. Created: %d, Updated: %d.',
            $run->id,
            $result['created'],
            $result['updated'],
        ));

        $createdRows = $pivotRows->where('action', EnterpriseWikiIngestRunPage::ACTION_CREATED);

        if ($createdRows->isNotEmpty()) {
            $this->line('  Created pages:');
            foreach ($createdRows as $row) {
                $this->line(sprintf(
                    '    [%s] %s (%s)',
                    $row->page->page_type,
                    $row->page->title,
                    $row->page->slug,
                ));
            }
        }

        $updatedRows = $pivotRows->where('action', EnterpriseWikiIngestRunPage::ACTION_UPDATED);

        if ($updatedRows->isNotEmpty()) {
            $this->line('  Updated pages:');
            foreach ($updatedRows as $row) {
                $this->line(sprintf(
                    '    [%s] %s (%s)',
                    $row->page->page_type,
                    $row->page->title,
                    $row->page->slug,
                ));
            }
        }

        $this->info('[WIKI_APPLY] Run marked as applied.');

        return self::SUCCESS;
    }
}
