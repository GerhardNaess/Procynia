<?php

namespace App\Jobs\Ai\Wiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestSection;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\KnowledgeItem;
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
            if ($run->source_type === EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
                $document = $service->resolveDocumentForIngest($run->customer_id, $run->source_id);
                $service->validateExtractedTextSize((string) $document->extracted_text);
                $sections = $parser->splitIntoSections((string) $document->extracted_text);
                $pageTitle = $document->original_filename ?? 'Wiki-side';
            } else {
                // knowledge_item_version path (legacy/bootstrap — Kunnskapsbase-import)
                $version = $service->resolveApprovedVersion($run->customer_id, $run->source_id);
                $service->validateExtractedTextSize((string) $version->extracted_text);
                $sections = $parser->splitIntoSections((string) $version->extracted_text);

                $knowledgeItem = KnowledgeItem::query()
                    ->where('id', $version->knowledge_item_id)
                    ->select(['id', 'title'])
                    ->first();

                $pageTitle = $knowledgeItem?->title ?? 'Wiki-side';
            }

            if (empty($sections)) {
                $run->update([
                    'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
                    'error_message' => 'No sections could be extracted from the document text.',
                    'finished_at' => now(),
                ]);

                return;
            }

            // Persist draft page/version, section plan, and advance run status atomically.
            // Claims require non-null page/version FKs, so the draft shell must exist
            // before any ProcessEnterpriseWikiSection job runs.
            $sectionIds = DB::transaction(function () use ($run, $sections, $pageTitle): array {
                $page = EnterpriseWikiPage::query()->create([
                    'customer_id' => $run->customer_id,
                    'slug' => 'wiki-draft-' . $run->id,
                    'title' => $pageTitle,
                    'status' => EnterpriseWikiPage::STATUS_DRAFT,
                    'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
                    'last_source_hash' => $run->source_hash,
                ]);

                EnterpriseWikiPageVersion::query()->create([
                    'enterprise_wiki_page_id' => $page->id,
                    'version_number' => 1,
                    'is_current' => false,
                ]);

                $run->update([
                    'enterprise_wiki_page_id' => $page->id,
                    'status' => EnterpriseWikiIngestRun::STATUS_SECTIONS_PLANNED,
                ]);

                $sectionIds = [];

                foreach ($sections as $index => $section) {
                    $row = EnterpriseWikiIngestSection::query()->create([
                        'enterprise_wiki_ingest_run_id' => $run->id,
                        'section_index' => $index,
                        'heading' => $section['heading'],
                        'status' => EnterpriseWikiIngestSection::STATUS_PENDING,
                    ]);

                    $sectionIds[] = $row->id;
                }

                return $sectionIds;
            });

            // Dispatch section jobs outside the transaction so DB rows are committed
            // before workers pick them up. Each job processes one section independently.
            foreach ($sectionIds as $sectionId) {
                ProcessEnterpriseWikiSection::dispatch($sectionId);
            }

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
