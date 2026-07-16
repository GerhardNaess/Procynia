<?php

namespace App\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentWikiAnswerStalenessService;
use App\Services\Ai\Wiki\WikiSemanticReviserAiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Targeted semantic repair service for Enterprise Wiki ingest runs (8G-5).
 *
 * Receives a semantic QA diagnosis and attempts a targeted revision of the article page:
 * - Retrieves the authoritative source text (EnterpriseWikiDocument.extracted_text)
 * - Retrieves the current article page version
 * - Delegates to WikiSemanticReviserAiClient with source + existing content + diagnosis
 * - Creates a new immutable EnterpriseWikiPageVersion with the revised content
 * - Makes the new version current, marks the old version as non-current
 *
 * This service does NOT modify claims, source references, or page links.
 * It does NOT re-run QA — that is the orchestrator's responsibility.
 * Maximum one repair attempt per QA cycle is enforced by the orchestrator.
 */
class EnterpriseWikiSemanticRepairService
{
    public function __construct(
        private readonly WikiSemanticReviserAiClient $aiClient,
        private readonly EnterpriseWikiDocumentWikiAnswerStalenessService $wikiAnswerStalenessService,
    ) {}

    /**
     * Attempt a targeted semantic revision of the article page based on a QA diagnosis.
     *
     * Returns a result array with keys:
     * - success (bool)
     * - page_id (int|null)
     * - page_version_id (int|null) — the newly created version ID
     * - previous_version_id (int|null) — the superseded version ID
     * - model (string|null)
     * - reason (string|null) — graceful-failure reason code
     *
     * Graceful failures (missing source, missing version, unsupported repair action) return
     * success=false with a reason code. The orchestrator maps these to 'escalated'.
     *
     * Unexpected AI errors propagate as RuntimeException and are mapped to 'failed' by the
     * outer try/catch in EnterpriseWikiPostIngestQaService::runForRun().
     */
    public function repair(EnterpriseWikiIngestRun $run, array $semanticQaDiagnosis): array
    {
        $action = $semanticQaDiagnosis['recommended_repair_action'] ?? '';

        if (! in_array($action, ['targeted_revision', 'full_regeneration'], true)) {
            return $this->skippedResult('repair_action_not_repairable');
        }

        if ($run->source_type !== EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
            return $this->skippedResult('source_type_not_supported');
        }

        $document = EnterpriseWikiDocument::find($run->source_id);

        if (! $document) {
            return $this->skippedResult('source_document_not_found');
        }

        $sourceText = trim((string) $document->extracted_text);

        if ($sourceText === '') {
            return $this->skippedResult('source_text_empty');
        }

        $diagnosedVersionId = $semanticQaDiagnosis['page_version_id'] ?? null;

        if (! $diagnosedVersionId) {
            return $this->skippedResult('diagnosed_version_id_missing');
        }

        $articleVersion = DB::table('enterprise_wiki_page_versions')
            ->where('id', $diagnosedVersionId)
            ->whereNotNull('content_markdown')
            ->where('content_markdown', '!=', '')
            ->select(['id', 'enterprise_wiki_page_id', 'version_number', 'content_markdown'])
            ->first();

        if (! $articleVersion) {
            return $this->skippedResult('diagnosed_version_not_found');
        }

        $existingContent = trim((string) $articleVersion->content_markdown);

        if ($existingContent === '') {
            return $this->skippedResult('article_content_empty');
        }

        $languageCode = $this->resolveLanguageCode($run->customer_id);

        Log::info('[WIKI_QA] Attempting semantic repair (8G-5)', [
            'run_id'              => $run->id,
            'action'              => $action,
            'document_id'         => $document->id,
            'previous_version_id' => $articleVersion->id,
        ]);

        $revisedMarkdown = $this->aiClient->revise(
            $sourceText,
            $existingContent,
            'article',
            $semanticQaDiagnosis,
            $languageCode,
        );

        $newVersion = $this->createRevisedVersion(
            (int) $articleVersion->enterprise_wiki_page_id,
            (int) $articleVersion->version_number + 1,
            $revisedMarkdown,
        );

        Log::info('[WIKI_QA] Semantic repair completed — new version created', [
            'run_id'         => $run->id,
            'page_id'        => $articleVersion->enterprise_wiki_page_id,
            'new_version_id' => $newVersion->id,
        ]);

        return [
            'success'             => true,
            'page_id'             => $articleVersion->enterprise_wiki_page_id,
            'page_version_id'     => $newVersion->id,
            'previous_version_id' => $articleVersion->id,
            'model'               => WikiSemanticReviserAiClient::MODEL . '/' . WikiSemanticReviserAiClient::PROMPT_VERSION,
            'reason'              => null,
        ];
    }

    private function createRevisedVersion(int $pageId, int $versionNumber, string $content): EnterpriseWikiPageVersion
    {
        return DB::transaction(function () use ($pageId, $versionNumber, $content): EnterpriseWikiPageVersion {
            DB::table('enterprise_wiki_page_versions')
                ->where('enterprise_wiki_page_id', $pageId)
                ->where('is_current', true)
                ->update(['is_current' => false, 'updated_at' => now()]);

            $version = EnterpriseWikiPageVersion::create([
                'enterprise_wiki_page_id' => $pageId,
                'version_number'          => $versionNumber,
                'is_current'              => true,
                'content_markdown'        => $content,
                'generated_by_model'      => WikiSemanticReviserAiClient::MODEL . '/semantic-repair',
            ]);

            $this->wikiAnswerStalenessService->markAnswersStaleForWikiPageChange($pageId);

            return $version;
        });
    }

    private function resolveLanguageCode(int $customerId): string
    {
        $customer = Customer::query()->with('language')->find($customerId);

        return $customer?->language?->code ?? 'no';
    }

    private function skippedResult(string $reason): array
    {
        return [
            'success'             => false,
            'page_id'             => null,
            'page_version_id'     => null,
            'previous_version_id' => null,
            'model'               => null,
            'reason'              => $reason,
        ];
    }
}
