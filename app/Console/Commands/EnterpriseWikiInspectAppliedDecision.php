<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('wiki:inspect-applied-decision {--run-id=}')]
#[Description('Read-only inspection of page skeletons linked to an applied maintainer decision run.')]
class EnterpriseWikiInspectAppliedDecision extends Command
{
    public function handle(): int
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

        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            $this->warn(sprintf(
                '[WIKI_INSPECT] Run [%d] has maintainer_decision_status [%s] — not applied yet. Nothing to inspect.',
                $run->id,
                $run->maintainer_decision_status ?? 'null',
            ));

            return self::SUCCESS;
        }

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        if ($pivotRows->isEmpty()) {
            $this->info(sprintf('[WIKI_INSPECT] Run [%d] is applied but has no linked pages.', $run->id));

            return self::SUCCESS;
        }

        $mismatch = $pivotRows->first(fn($row) => $row->page !== null && $row->page->customer_id !== $run->customer_id);

        if ($mismatch !== null) {
            $this->error(sprintf(
                '[WIKI_INSPECT] Customer mismatch: page [%d] belongs to customer [%d], but run belongs to customer [%d].',
                $mismatch->enterprise_wiki_page_id,
                $mismatch->page->customer_id,
                $run->customer_id,
            ));

            return self::FAILURE;
        }

        $this->info(sprintf('[WIKI_INSPECT] Run [%d] — applied maintainer decision pages:', $run->id));
        $this->newLine();

        $counts = [
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE => 0,
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY  => 0,
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT  => 0,
            EnterpriseWikiPage::PAGE_TYPE_ENTITY   => 0,
        ];

        foreach ($pivotRows as $row) {
            $page = $row->page;

            if ($page === null) {
                $this->warn(sprintf('  [?] Page [%d] no longer exists (deleted).', $row->enterprise_wiki_page_id));
                continue;
            }

            $this->line(sprintf(
                '  [%s] %s / %s | action: %s | status: %s | id: %d',
                str_pad($page->page_type ?? '?', 7),
                $page->title,
                $page->slug,
                $row->action,
                $page->status,
                $page->id,
            ));

            if (isset($counts[$page->page_type])) {
                $counts[$page->page_type]++;
            }
        }

        $this->newLine();
        $this->line(sprintf('  Article:  %d', $counts[EnterpriseWikiPage::PAGE_TYPE_ARTICLE]));
        $this->line(sprintf('  Summary:  %d', $counts[EnterpriseWikiPage::PAGE_TYPE_SUMMARY]));
        $this->line(sprintf('  Concept:  %d', $counts[EnterpriseWikiPage::PAGE_TYPE_CONCEPT]));
        $this->line(sprintf('  Entity:   %d', $counts[EnterpriseWikiPage::PAGE_TYPE_ENTITY]));
        $this->line(sprintf('  Total:    %d', $pivotRows->count()));

        return self::SUCCESS;
    }
}
