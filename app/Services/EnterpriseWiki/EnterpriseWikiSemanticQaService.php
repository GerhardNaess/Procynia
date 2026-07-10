<?php

namespace App\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Services\Ai\Wiki\WikiSemanticQaAiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Semantic QA service for Enterprise Wiki ingest runs (8G-4).
 *
 * Retrieves the authoritative source text (EnterpriseWikiDocument.extracted_text)
 * and the current article page version, then delegates to WikiSemanticQaAiClient
 * for a structured content review.
 *
 * This service does NOT modify any EnterpriseWikiPageVersion or trigger generation.
 * Repair and revision are handled separately in 8G-5.
 */
class EnterpriseWikiSemanticQaService
{
    public function __construct(
        private readonly WikiSemanticQaAiClient $aiClient,
    ) {}

    /**
     * Run semantic QA for a given applied ingest run.
     *
     * Returns a result array containing all fields from the 8G-4 plan schema,
     * plus source_hash and page_version_id for traceability.
     *
     * Special result keys:
     * - skipped=true  : semantic QA not applicable (source type unsupported, AI disabled)
     * - escalated=true: semantic QA cannot proceed (source or content unavailable)
     *
     * @throws \RuntimeException when the AI call fails (propagated for the caller to handle as failed)
     */
    public function review(EnterpriseWikiIngestRun $run): array
    {
        if ($run->source_type !== EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
            return $this->skippedResult('source_type_not_supported');
        }

        $document = EnterpriseWikiDocument::find($run->source_id);

        if ($document === null) {
            return $this->escalatedResult('source_document_not_found');
        }

        $sourceText = trim((string) $document->extracted_text);

        if ($sourceText === '') {
            return $this->escalatedResult('source_text_empty');
        }

        $articleVersion = $this->currentArticleVersion($run);

        if ($articleVersion === null) {
            return $this->escalatedResult('article_version_not_found');
        }

        $generatedContent = trim((string) $articleVersion->content_markdown);

        if ($generatedContent === '') {
            return $this->escalatedResult('article_content_empty');
        }

        $languageCode = $this->resolveLanguageCode($run->customer_id);

        Log::info('[WIKI_QA] Running semantic QA', [
            'run_id' => $run->id,
            'document_id' => $document->id,
            'page_version_id' => $articleVersion->id,
            'source_hash' => $document->file_hash_sha256,
        ]);

        $aiResult = $this->aiClient->review($sourceText, $generatedContent, $languageCode);

        return array_merge($aiResult, [
            'source_hash' => $document->file_hash_sha256,
            'page_version_id' => $articleVersion->id,
        ]);
    }

    private function currentArticleVersion(EnterpriseWikiIngestRun $run): ?object
    {
        $pivotPageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->pluck('enterprise_wiki_page_id');

        if ($pivotPageIds->isEmpty()) {
            return null;
        }

        $articlePageIds = EnterpriseWikiPage::query()
            ->whereIn('id', $pivotPageIds)
            ->where('page_type', EnterpriseWikiPage::PAGE_TYPE_ARTICLE)
            ->pluck('id');

        if ($articlePageIds->isEmpty()) {
            return null;
        }

        return DB::table('enterprise_wiki_page_versions')
            ->whereIn('enterprise_wiki_page_id', $articlePageIds)
            ->where('is_current', true)
            ->whereNotNull('content_markdown')
            ->where('content_markdown', '!=', '')
            ->select(['id', 'enterprise_wiki_page_id', 'content_markdown'])
            ->first();
    }

    private function resolveLanguageCode(int $customerId): string
    {
        $customer = Customer::query()->with('language')->find($customerId);

        return $customer?->language?->code ?? 'no';
    }

    /**
     * Skipped: semantic QA is not applicable for this run (e.g. source type not supported).
     * The run is treated as passing the semantic QA layer.
     */
    private function skippedResult(string $reason): array
    {
        return [
            'skipped' => true,
            'reason' => $reason,
            'pass' => true,
            'quality_score' => null,
            'coverage_score' => null,
            'factual_consistency_score' => null,
            'unsupported_claims' => [],
            'missing_topics' => [],
            'missing_key_facts' => [],
            'critique' => '',
            'recommended_repair_action' => 'none',
            'confidence' => null,
            'model' => null,
            'source_hash' => null,
            'page_version_id' => null,
        ];
    }

    /**
     * Escalated: semantic QA cannot be performed because the source or content is unavailable.
     * The run must be escalated — it cannot be approved without a source to verify against.
     */
    private function escalatedResult(string $reason): array
    {
        return [
            'skipped' => false,
            'escalated' => true,
            'reason' => $reason,
            'pass' => false,
            'quality_score' => null,
            'coverage_score' => null,
            'factual_consistency_score' => null,
            'unsupported_claims' => [],
            'missing_topics' => [],
            'missing_key_facts' => [],
            'critique' => "Semantic QA cannot proceed: {$reason}",
            'recommended_repair_action' => 'escalate',
            'confidence' => null,
            'model' => null,
            'source_hash' => null,
            'page_version_id' => null,
        ];
    }
}
