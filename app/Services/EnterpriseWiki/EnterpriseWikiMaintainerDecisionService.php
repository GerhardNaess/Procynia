<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiDocument;
use App\Services\Ai\Wiki\EnterpriseWikiIndexContextService;

/**
 * Orchestrates a dry-run maintainer decision for a single wiki document.
 *
 * Fetches the document (customer-scoped), builds the page index, calls the AI
 * client, and returns the validated decision array. Nothing is written to the DB.
 */
class EnterpriseWikiMaintainerDecisionService
{
    public function __construct(
        private readonly EnterpriseWikiIndexContextService $indexContextService,
        private readonly EnterpriseWikiMaintainerDecisionAiClient $aiClient,
    ) {}

    /**
     * Run a maintainer decision for the given document, scoped to the customer.
     * No pages, versions, claims, or pivot rows are created.
     *
     * @return array<string, mixed>  Validated maintainer decision.
     * @throws \InvalidArgumentException  If the document is not found for this customer.
     * @throws \RuntimeException          If the AI call fails.
     */
    public function runForDocument(int $customerId, int $documentId, string $languageCode = 'no'): array
    {
        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->where('id', $documentId)
            ->first();

        if ($document === null) {
            throw new \InvalidArgumentException(
                "Document [{$documentId}] not found for customer [{$customerId}]."
            );
        }

        $sourceMeta = [
            'title'    => pathinfo((string) $document->original_filename, PATHINFO_FILENAME) ?: 'Unknown',
            'filename' => (string) $document->original_filename,
        ];

        $sourceText   = (string) ($document->extracted_text ?? '');
        $indexContext = $this->indexContextService->buildForCustomer($customerId);

        return $this->aiClient->decide($sourceMeta, $sourceText, $indexContext, $languageCode);
    }
}
