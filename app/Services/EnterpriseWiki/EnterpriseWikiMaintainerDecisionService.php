<?php

namespace App\Services\EnterpriseWiki;

use App\Data\Ai\AiCallContext;
use App\Exceptions\EnterpriseWikiMaintainerDecisionInconsistentException;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiSourceReference;
use App\Services\Ai\Wiki\EnterpriseWikiIndexContextService;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates a dry-run maintainer decision for a single wiki document.
 *
 * Fetches the document (customer-scoped), builds the page index, calls the AI
 * client, and returns the validated decision array. Nothing is written to the DB.
 *
 * After the AI decision passes schema validation, EnterpriseWikiMaintainerDecisionConsistencyValidator
 * checks it for logical self-contradictions (e.g. the run-581 "ITIL Incident Management" incident:
 * the article/summary pointed the reader onward to a concept that concept_pages never created).
 * When found, one bounded AI repair pass is attempted; if the repaired decision is still
 * inconsistent, this throws rather than silently applying an unresolved contradiction.
 */
class EnterpriseWikiMaintainerDecisionService
{
    public function __construct(
        private readonly EnterpriseWikiIndexContextService $indexContextService,
        private readonly EnterpriseWikiMaintainerDecisionAiClient $aiClient,
        private readonly EnterpriseWikiMaintainerDecisionConsistencyValidator $consistencyValidator,
        private readonly EnterpriseWikiDocumentSourceElementService $sourceElementService,
    ) {}

    /**
     * Run a maintainer decision for the given document, scoped to the customer.
     * No pages, versions, claims, or pivot rows are created.
     *
     * @return array<string, mixed> Validated, internally-consistent maintainer decision.
     *
     * @throws \InvalidArgumentException If the document is not found for this customer.
     * @throws \RuntimeException If the AI call fails.
     * @throws EnterpriseWikiMaintainerDecisionInconsistentException If the decision is still
     *                                                               logically inconsistent after one bounded repair pass.
     */
    public function runForDocument(
        int $customerId,
        int $documentId,
        string $languageCode = 'no',
        ?AiCallContext $context = null,
    ): array {
        $context ??= AiCallContext::none();

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
            'title' => pathinfo((string) $document->original_filename, PATHINFO_FILENAME) ?: 'Unknown',
            'filename' => (string) $document->original_filename,
        ];

        $sourceText = (string) ($document->extracted_text ?? '');
        $indexContext = $this->indexContextService->buildForCustomer($customerId);
        $figureCandidates = $this->figureCandidatesForDocument($document);
        $validFigureKeys = array_column($figureCandidates, 'source_element_key');

        $decision = $this->aiClient->decide($sourceMeta, $sourceText, $indexContext, $languageCode, $figureCandidates, $context);

        return $this->validateAndRepairForDocument($customerId, $document, $languageCode, $decision, $context);
    }

    /**
     * Validate and, when needed, repair an already-composed maintainer decision. This performs
     * no decision-generation call, persistence, or apply operation.
     *
     * @param  array<string,mixed>  $decision
     * @return array<string,mixed>
     */
    public function validateAndRepairForDocument(
        int $customerId,
        EnterpriseWikiDocument $document,
        string $languageCode,
        array $decision,
        ?AiCallContext $context = null,
    ): array {
        $context ??= AiCallContext::none();
        $sourceMeta = [
            'title' => pathinfo((string) $document->original_filename, PATHINFO_FILENAME) ?: 'Unknown',
            'filename' => (string) $document->original_filename,
        ];
        $sourceText = (string) ($document->extracted_text ?? '');
        $indexContext = $this->indexContextService->buildForCustomer($customerId);
        $figureCandidates = $this->figureCandidatesForDocument($document);
        $validFigureKeys = array_column($figureCandidates, 'source_element_key');
        $issues = $this->consistencyValidator->findIssues($decision, $indexContext, $validFigureKeys);

        if ($issues === []) {
            Log::info('[WIKI_MAINTAINER_DECISION] Consistency validation completed.', [
                'customer_id' => $customerId,
                'document_id' => $document->id,
                'validation_result' => 'valid_first_pass',
                'issues' => 0,
            ]);

            return $decision;
        }

        [$normalizedDecision, $normalizations] = $this->normalizeMaintainerDecisionStructure($decision);

        if ($normalizations !== []) {
            $normalizedIssues = $this->consistencyValidator->findIssues($normalizedDecision, $indexContext, $validFigureKeys);

            if ($normalizedIssues === []) {
                Log::info('[WIKI_MAINTAINER_DECISION] Consistency validation completed.', [
                    'customer_id' => $customerId,
                    'document_id' => $document->id,
                    'validation_result' => 'deterministic_normalization',
                    'normalizations' => $normalizations,
                    'issues_before' => count($issues),
                    'issues_after' => 0,
                ]);

                return $normalizedDecision;
            }

            $decision = $normalizedDecision;
            $issues = $normalizedIssues;
        }

        Log::warning('[WIKI_MAINTAINER_DECISION] Inconsistent decision detected — attempting one bounded repair pass.', [
            'customer_id' => $customerId,
            'document_id' => $document->id,
            'validation_result' => 'ai_repair_required',
            'normalizations' => $normalizations,
            'issues' => $issues,
        ]);

        $repaired = $this->aiClient->repair($sourceMeta, $sourceText, $indexContext, $languageCode, $decision, $issues, $figureCandidates, $context);
        $remainingIssues = $this->consistencyValidator->findIssues($repaired, $indexContext, $validFigureKeys);

        if ($remainingIssues !== []) {
            Log::error('[WIKI_MAINTAINER_DECISION] Decision still inconsistent after repair pass.', [
                'customer_id' => $customerId,
                'document_id' => $document->id,
                'issues' => $remainingIssues,
            ]);

            throw new EnterpriseWikiMaintainerDecisionInconsistentException($remainingIssues);
        }

        Log::info('[WIKI_MAINTAINER_DECISION] Repair pass resolved all detected inconsistencies.', [
            'customer_id' => $customerId,
            'document_id' => $document->id,
        ]);

        return $repaired;
    }

    /**
     * Structural cleanup only: canonicalize related_page_guidance.page_title to an already planned
     * run-local source_article/source_summary title when the model returned the same title with
     * harmless casing, punctuation, whitespace, or file-extension drift. No page choices, topic
     * ownership, relationships, or concept decisions are invented or removed.
     *
     * @param  array<string, mixed>  $decision
     * @return array{0: array<string, mixed>, 1: list<array{path: string, from: string, to: string}>}
     */
    private function normalizeMaintainerDecisionStructure(array $decision): array
    {
        $localSourceTitles = array_values(array_filter(array_map(
            fn (string $key): string => trim((string) data_get($decision, "{$key}.title", '')),
            ['source_article', 'source_summary'],
        )));

        if ($localSourceTitles === []) {
            return [$decision, []];
        }

        $normalizations = [];

        foreach (['source_article', 'source_summary'] as $entryKey) {
            $this->normalizeRelatedPageGuidanceTargets($decision, $entryKey, $localSourceTitles, $normalizations);
        }

        foreach (['concept_pages', 'entity_pages'] as $listKey) {
            foreach ((array) ($decision[$listKey] ?? []) as $index => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $this->normalizeRelatedPageGuidanceTargets($decision, "{$listKey}.{$index}", $localSourceTitles, $normalizations);
            }
        }

        return [$decision, $normalizations];
    }

    /**
     * @param  list<string>  $localSourceTitles
     * @param  list<array{path: string, from: string, to: string}>  $normalizations
     */
    private function normalizeRelatedPageGuidanceTargets(array &$decision, string $entryPath, array $localSourceTitles, array &$normalizations): void
    {
        $guidancePath = "{$entryPath}.related_page_guidance";
        $guidance = data_get($decision, $guidancePath);

        if (! is_array($guidance)) {
            return;
        }

        foreach ($guidance as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $pageTitle = trim((string) ($item['page_title'] ?? ''));
            $canonical = $this->canonicalLocalSourcePageTitle($pageTitle, $localSourceTitles);

            if ($canonical === null || $canonical === $pageTitle) {
                continue;
            }

            data_set($decision, "{$guidancePath}.{$index}.page_title", $canonical);
            $normalizations[] = [
                'path' => "{$guidancePath}.{$index}.page_title",
                'from' => $pageTitle,
                'to' => $canonical,
            ];
        }
    }

    /** @param  list<string>  $localSourceTitles */
    private function canonicalLocalSourcePageTitle(string $title, array $localSourceTitles): ?string
    {
        $normalizedTitle = $this->normalizeExactTitle($title);
        $normalizedWithoutExtension = $this->normalizeExactTitle($this->stripKnownFileExtension($title));

        foreach ($localSourceTitles as $localSourceTitle) {
            $normalizedLocal = $this->normalizeExactTitle($localSourceTitle);

            if ($normalizedLocal !== '' && in_array($normalizedLocal, [$normalizedTitle, $normalizedWithoutExtension], true)) {
                return $localSourceTitle;
            }
        }

        return null;
    }

    private function stripKnownFileExtension(string $title): string
    {
        $extension = mb_strtolower((string) pathinfo($title, PATHINFO_EXTENSION));

        if ($extension !== '' && in_array($extension, ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'md'], true)) {
            return (string) pathinfo($title, PATHINFO_FILENAME);
        }

        return $title;
    }

    private function normalizeExactTitle(string $title): string
    {
        $normalized = mb_strtolower($title);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized) ?? '';

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    /** @return array{global_plan: array<string,mixed>, batches: list<array<string,mixed>>}|null */
    public function preparePersistedCandidateBatchesForDocument(int $customerId, int $documentId, string $languageCode = 'no', ?AiCallContext $context = null): ?array
    {
        $document = EnterpriseWikiDocument::query()->where('customer_id', $customerId)->where('id', $documentId)->first();
        if ($document === null) {
            throw new \InvalidArgumentException("Document [{$documentId}] not found for customer [{$customerId}].");
        }

        $sourceText = (string) ($document->extracted_text ?? '');
        if (! $this->aiClient->requiresSplit($sourceText)) {
            return null;
        }

        return $this->aiClient->preparePersistedCandidateBatches(
            ['title' => pathinfo((string) $document->original_filename, PATHINFO_FILENAME) ?: 'Unknown', 'filename' => (string) $document->original_filename],
            $sourceText,
            $this->indexContextService->buildForCustomer($customerId),
            $languageCode,
            $this->figureCandidatesForDocument($document),
            $context,
        );
    }

    /** @param list<array<string,mixed>> $batchResults @return array<string,mixed> */
    public function mergePersistedCandidateBatchResults(array $globalPlan, array $batchResults): array
    {
        return $this->aiClient->mergePersistedBatchResults($globalPlan, $batchResults);
    }

    /**
     * Every showable (non-decorative/logo) figure already extracted and classified from this
     * document — EnterpriseWikiDocumentSourceElementService::inspect() has already excluded
     * decorative/logo images (isShowable()) before this ever sees them, so every candidate here is
     * a genuine planning candidate. Shape matches what
     * EnterpriseWikiMaintainerDecisionAiClient::figureCandidatesBlock() renders into the prompt.
     *
     * @return list<array<string, mixed>>
     */
    private function figureCandidatesForDocument(EnterpriseWikiDocument $document): array
    {
        $elements = $this->sourceElementService->inspect($document)['elements'];

        return array_values(array_filter(
            $elements,
            fn (array $element): bool => ($element['source_element_type'] ?? null) === EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_IMAGE,
        ));
    }
}
