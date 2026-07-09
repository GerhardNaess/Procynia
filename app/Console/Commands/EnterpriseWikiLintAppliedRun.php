<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use Illuminate\Console\Command;

#[\Illuminate\Console\Attribute\AsCommand(name: 'wiki:lint-applied-run')]
class EnterpriseWikiLintAppliedRun extends Command
{
    protected $signature = 'wiki:lint-applied-run {--run-id=}';

    protected $description = 'Lint an applied enterprise wiki ingest run for structural quality issues.';

    public function handle(EnterpriseWikiAppliedRunLintService $service): int
    {
        $runId = $this->option('run-id');

        if (! $runId) {
            $this->error('[WIKI_LINT] --run-id is required.');

            return self::FAILURE;
        }

        $run = EnterpriseWikiIngestRun::find($runId);

        if ($run === null) {
            $this->error("[WIKI_LINT] Run [{$runId}] not found.");

            return self::FAILURE;
        }

        try {
            $result = $service->lint($run);
        } catch (\InvalidArgumentException $e) {
            $this->error("[WIKI_LINT] {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->line("[WIKI_LINT] Run ID: {$run->id}");
        $this->line("[WIKI_LINT] Pages checked: {$result['pages_checked']}");
        $this->line("[WIKI_LINT] Claims checked: {$result['claims_checked']}");
        $this->line("[WIKI_LINT] Source refs checked: {$result['source_refs_checked']}");
        $this->line("[WIKI_LINT] Links checked: {$result['links_checked']}");
        $this->line("[WIKI_LINT] Findings created: {$result['findings_created']}");
        $this->line("[WIKI_LINT] Findings skipped: {$result['findings_skipped']}");
        $this->line("[WIKI_LINT] Findings resolved: {$result['findings_resolved']}");
        $this->line("[WIKI_LINT] Errors: {$result['errors']}");
        $this->line("[WIKI_LINT] Warnings: {$result['warnings']}");
        $this->line("[WIKI_LINT] Info: {$result['info']}");

        return self::SUCCESS;
    }
}
