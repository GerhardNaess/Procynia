<?php

namespace App\Jobs\Ai\Wiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestSection;
use App\Services\Ai\Wiki\EnterpriseWikiIngestService;
use App\Services\Ai\Wiki\EnterpriseWikiSectionParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ProcessEnterpriseWikiIngest implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId)
    {
        $this->queue = 'enterprise-wiki';
    }

    public function handle(EnterpriseWikiIngestService $service, EnterpriseWikiSectionParser $parser): void
    {
        // Claim the run atomically: only one worker can transition a queued run to running.
        $run = DB::transaction(function (): ?EnterpriseWikiIngestRun {
            $run = EnterpriseWikiIngestRun::query()
                ->lockForUpdate()
                ->find($this->runId);

            if (! $run instanceof EnterpriseWikiIngestRun || $run->status !== EnterpriseWikiIngestRun::STATUS_QUEUED) {
                return null;
            }

            $run->update([
                'status' => EnterpriseWikiIngestRun::STATUS_RUNNING,
                'started_at' => now(),
            ]);

            return $run;
        });

        if ($run === null) {
            return;
        }

        try {
            // Read-only access to KnowledgeItemVersion via the ingest service.
            // No writes to knowledge_items, knowledge_item_versions, or knowledge_item_chunks.
            $version = $service->resolveApprovedVersion($run->customer_id, $run->source_id);
            $service->validateExtractedTextSize((string) $version->extracted_text);

            $sections = $parser->splitIntoSections((string) $version->extracted_text);

            if (empty($sections)) {
                $run->update([
                    'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
                    'error_message' => 'No sections could be extracted from the document text.',
                    'finished_at' => now(),
                ]);

                return;
            }

            // Persist the section plan and advance run status atomically.
            // Section AI processing (phase 1E) will pick up runs in STATUS_SECTIONS_PLANNED.
            DB::transaction(function () use ($run, $sections): void {
                foreach ($sections as $index => $section) {
                    EnterpriseWikiIngestSection::query()->create([
                        'enterprise_wiki_ingest_run_id' => $run->id,
                        'section_index' => $index,
                        'heading' => $section['heading'],
                        'status' => EnterpriseWikiIngestSection::STATUS_PENDING,
                    ]);
                }

                $run->update(['status' => EnterpriseWikiIngestRun::STATUS_SECTIONS_PLANNED]);
            });

            Log::info(sprintf(
                '[WIKI_INGEST][SECTIONS_PLANNED] run_id=%d sections=%d customer_id=%d source_id=%d',
                $run->id,
                count($sections),
                $run->customer_id,
                $run->source_id,
            ));
        } catch (InvalidArgumentException $e) {
            // Validation failures are deterministic — mark failed without re-throw.
            $run->update([
                'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $run->update([
                'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $run = EnterpriseWikiIngestRun::query()->find($this->runId);

        if ($run && ! $run->isTerminal()) {
            $run->update([
                'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);
        }
    }
}
