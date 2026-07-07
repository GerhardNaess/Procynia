<?php

namespace App\Jobs\Ai\Wiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestSection;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Services\Ai\Wiki\EnterpriseWikiIngestService;
use App\Services\Ai\Wiki\EnterpriseWikiSectionParser;
use App\Services\Ai\Wiki\WikiSectionAiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ProcessEnterpriseWikiSection implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $sectionId)
    {
        $this->queue = 'enterprise-wiki';
    }

    public function handle(
        EnterpriseWikiIngestService $ingestService,
        EnterpriseWikiSectionParser $parser,
        WikiSectionAiClient $aiClient,
    ): void {
        // Claim the section atomically: only one worker processes each section.
        $section = DB::transaction(function (): ?EnterpriseWikiIngestSection {
            $section = EnterpriseWikiIngestSection::query()
                ->lockForUpdate()
                ->find($this->sectionId);

            if (! $section instanceof EnterpriseWikiIngestSection) {
                return null;
            }

            if ($section->status !== EnterpriseWikiIngestSection::STATUS_PENDING) {
                return null;
            }

            $section->update(['status' => EnterpriseWikiIngestSection::STATUS_RUNNING]);

            return $section;
        });

        if ($section === null) {
            return;
        }

        try {
            $run = EnterpriseWikiIngestRun::query()->find($section->enterprise_wiki_ingest_run_id);

            if (! $run instanceof EnterpriseWikiIngestRun || ! $run->enterprise_wiki_page_id) {
                $this->failSection($section, 'Associated ingest run or draft wiki page not found.');

                return;
            }

            $pageVersion = EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $run->enterprise_wiki_page_id)
                ->where('version_number', 1)
                ->first();

            if (! $pageVersion instanceof EnterpriseWikiPageVersion) {
                $this->failSection($section, 'Draft wiki page version not found.');

                return;
            }

            if ($run->source_type === EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
                $document = $ingestService->resolveDocumentForIngest($run->customer_id, $run->source_id);
                $allSections = $parser->splitIntoSections((string) $document->extracted_text);
                $sourceLabel = $document->original_filename ?? sprintf('enterprise_wiki_document:%d', $document->id);
                $refSourceType = EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT;
            } else {
                // knowledge_item_version path (legacy/bootstrap — Kunnskapsbase-import)
                $version = $ingestService->resolveApprovedVersion($run->customer_id, $run->source_id);
                $allSections = $parser->splitIntoSections((string) $version->extracted_text);
                $sourceLabel = $version->original_filename ?? sprintf('knowledge_item_version:%d', $version->id);
                $refSourceType = EnterpriseWikiSourceReference::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION;
            }

            $sectionData = $allSections[$section->section_index] ?? null;

            if ($sectionData === null) {
                $this->failSection($section, sprintf(
                    'Section index [%d] not found after re-parsing (total: %d).',
                    $section->section_index,
                    count($allSections),
                ));

                return;
            }

            // Fetch claims from AI. WikiSectionAiClient is mocked in tests.
            // Language code is resolved from customer in Phase 1F; default to 'no' for now.
            $aiResponse = $aiClient->fetchClaims(
                $sectionData['content'],
                $sectionData['heading'],
                'no',
            );

            $parsed = $parser->parseClaimsFromResponse($aiResponse);

            // Reject claims without an excerpt — both text and excerpt are required for
            // a traceable source reference. Claims with empty excerpt are dropped silently.
            $validClaims = array_values(array_filter(
                $parsed,
                fn(array $c) => $c['excerpt'] !== '',
            ));

            DB::transaction(function () use ($run, $pageVersion, $section, $validClaims, $sourceLabel, $refSourceType): void {
                $order = 0;

                foreach ($validClaims as $claim) {
                    $claimRecord = EnterpriseWikiClaim::query()->create([
                        'enterprise_wiki_page_id' => $pageVersion->enterprise_wiki_page_id,
                        'enterprise_wiki_page_version_id' => $pageVersion->id,
                        'claim_text' => $claim['text'],
                        'confidence' => $claim['confidence'],
                        'conflict_flag' => $claim['conflict_flag'],
                        'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                        'position_order' => $order++,
                    ]);

                    EnterpriseWikiSourceReference::query()->create([
                        'enterprise_wiki_claim_id' => $claimRecord->id,
                        'source_type' => $refSourceType,
                        'source_id' => $run->source_id,
                        'source_label' => $sourceLabel,
                        'source_hash' => $run->source_hash ?? '',
                        'excerpt' => $claim['excerpt'],
                    ]);
                }

                $section->update(['status' => EnterpriseWikiIngestSection::STATUS_COMPLETED]);
            });

            Log::info(sprintf(
                '[WIKI_SECTION][COMPLETED] section_id=%d section_index=%d claims=%d run_id=%d',
                $section->id,
                $section->section_index,
                count($validClaims),
                $run->id,
            ));

            FinalizeEnterpriseWikiIngest::dispatch($run->id);
        } catch (InvalidArgumentException $e) {
            $this->failSection($section, $e->getMessage());
        } catch (Throwable $e) {
            $this->failSection($section, mb_substr($e->getMessage(), 0, 1000));

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $section = EnterpriseWikiIngestSection::query()->find($this->sectionId);

        if (! $section instanceof EnterpriseWikiIngestSection) {
            return;
        }

        if (! $section->isTerminal()) {
            $section->update([
                'status' => EnterpriseWikiIngestSection::STATUS_FAILED,
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
        }

        // Safety net: if the section was already marked failed before the rethrow (Throwable
        // catch path), a finalize was already dispatched. This covers any edge case where
        // the handle() method exits abnormally without reaching the catch blocks.
        FinalizeEnterpriseWikiIngest::dispatch($section->enterprise_wiki_ingest_run_id);
    }

    private function failSection(EnterpriseWikiIngestSection $section, string $message): void
    {
        $section->update([
            'status' => EnterpriseWikiIngestSection::STATUS_FAILED,
            'error_message' => $message,
        ]);

        FinalizeEnterpriseWikiIngest::dispatch($section->enterprise_wiki_ingest_run_id);
    }
}
