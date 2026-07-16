<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use Illuminate\Support\Collection;

class EnterpriseWikiDocumentWikiAnswerStalenessService
{
    /**
     * Purpose: Count Wiki answers that would become stale if one Enterprise Wiki source document
     *          were deleted.
     * Inputs: The source document and the page ids that are going to be deleted with it.
     * Returns: A compact preview payload for the delete-confirmation UI.
     * Side effects: None.
     */
    public function previewDeletionImpact(
        EnterpriseWikiDocument $document,
        Collection $runIds,
        Collection $soleSourcePageIds,
    ): array {
        $impact = $this->buildImpact($document, $runIds, $soleSourcePageIds);

        return [
            'stale_wiki_answer_count' => count($impact['candidate_contexts']),
            'impacted_claim_count' => count($impact['impacted_claim_ids']),
            'impacted_source_reference_count' => $impact['impacted_source_reference_count'],
        ];
    }

    /**
     * Purpose: Mark already-generated Wiki answers as stale before the source document is deleted.
     * Inputs: The source document and the page ids that will be deleted with it.
     * Returns: A compact mutation summary for logging and tests.
     * Side effects: Updates saved_notice_ai_requirement_wiki_answers rows in place.
     */
    public function markAnswersStaleForDeletedDocument(
        EnterpriseWikiDocument $document,
        Collection $runIds,
        Collection $soleSourcePageIds,
    ): array {
        $contexts = $this->buildImpact($document, $runIds, $soleSourcePageIds)['candidate_contexts'];
        $now = now();
        $updated = 0;

        foreach ($contexts as $context) {
            $affected = SavedNoticeAiRequirementWikiAnswer::query()
                ->whereKey($context['answer_id'])
                ->whereNull('stale_at')
                ->update([
                    'stale_at' => $now,
                    'stale_reason' => SavedNoticeAiRequirementWikiAnswer::STALE_REASON_SOURCE_DOCUMENT_DELETED,
                    'stale_context' => $context['stale_context'],
                    'updated_at' => $now,
                ]);

            $updated += $affected;
        }

        return [
            'stale_wiki_answer_count' => $updated,
        ];
    }

    /**
     * @return array{
     *     candidate_contexts: list<array{answer_id: int, stale_context: array<string, mixed>}>,
     *     impacted_claim_ids: list<int>,
     *     impacted_source_reference_count: int
     * }
     */
    private function buildImpact(
        EnterpriseWikiDocument $document,
        Collection $runIds,
        Collection $soleSourcePageIds,
    ): array {
        $soleSourcePageIdSet = array_fill_keys(
            $soleSourcePageIds->map(static fn (mixed $value): int => (int) $value)->all(),
            true,
        );

        $deletedSourceReferenceQuery = EnterpriseWikiSourceReference::query()
            ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->where('source_id', $document->id);

        $impactedSourceReferenceCount = (clone $deletedSourceReferenceQuery)->count();
        $impactedClaimIds = (clone $deletedSourceReferenceQuery)
            ->whereNotNull('enterprise_wiki_claim_id')
            ->pluck('enterprise_wiki_claim_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
        $impactedClaimIdSet = array_fill_keys($impactedClaimIds, true);

        $supportedElsewhereClaimIds = $impactedClaimIds === []
            ? []
            : EnterpriseWikiSourceReference::query()
                ->whereIn('enterprise_wiki_claim_id', $impactedClaimIds)
                ->where(function ($query) use ($document): void {
                    $query->where('source_type', '!=', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                        ->orWhere('source_id', '!=', $document->id);
                })
                ->pluck('enterprise_wiki_claim_id')
                ->map(static fn (mixed $value): int => (int) $value)
                ->unique()
                ->values()
                ->all();
        $supportedElsewhereClaimIdSet = array_fill_keys($supportedElsewhereClaimIds, true);

        if ($soleSourcePageIdSet === [] && $impactedClaimIdSet === []) {
            return [
                'candidate_contexts' => [],
                'impacted_claim_ids' => $impactedClaimIds,
                'impacted_source_reference_count' => $impactedSourceReferenceCount,
            ];
        }

        $answers = SavedNoticeAiRequirementWikiAnswer::query()
            ->whereHas('requirement.savedNotice', function ($query) use ($document): void {
                $query->where('customer_id', $document->customer_id);
            })
            ->get(['id', 'saved_notice_ai_requirement_id', 'sources', 'research_trace', 'stale_at']);

        $contexts = [];

        foreach ($answers as $answer) {
            if ($answer->stale_at !== null) {
                continue;
            }

            $sources = is_array($answer->sources) ? $answer->sources : [];
            $matchedPageIds = [];
            $matchedClaimIds = [];
            $matchedUnsupportedClaimIds = [];

            foreach ($sources as $source) {
                if (! is_array($source)) {
                    continue;
                }

                $pageId = (int) ($source['enterprise_wiki_page_id'] ?? $source['page_id'] ?? 0);

                if ($pageId > 0 && isset($soleSourcePageIdSet[$pageId])) {
                    $matchedPageIds[$pageId] = true;
                }

                $supportingClaimIds = is_array($source['supporting_claim_ids'] ?? null)
                    ? $source['supporting_claim_ids']
                    : [];

                foreach ($supportingClaimIds as $claimId) {
                    $claimId = (int) $claimId;

                    if ($claimId > 0 && isset($impactedClaimIdSet[$claimId])) {
                        $matchedClaimIds[$claimId] = true;

                        if (! isset($supportedElsewhereClaimIdSet[$claimId])) {
                            $matchedUnsupportedClaimIds[$claimId] = true;
                        }
                    }
                }
            }

            if ($matchedPageIds === [] && $matchedUnsupportedClaimIds === []) {
                $researchTrace = is_array($answer->research_trace) ? $answer->research_trace : [];
                $answerSections = is_array($researchTrace['answer']['answer_sections'] ?? null)
                    ? $researchTrace['answer']['answer_sections']
                    : [];

                foreach ($answerSections as $section) {
                    if (! is_array($section)) {
                        continue;
                    }

                    $sectionPageIds = is_array($section['used_page_ids'] ?? null)
                        ? $section['used_page_ids']
                        : (is_array($section['page_ids'] ?? null) ? $section['page_ids'] : []);

                    foreach ($sectionPageIds as $pageId) {
                        $pageId = (int) $pageId;

                        if ($pageId > 0 && isset($soleSourcePageIdSet[$pageId])) {
                            $matchedPageIds[$pageId] = true;
                        }
                    }
                }
            }

            if ($matchedPageIds === [] && $matchedUnsupportedClaimIds === []) {
                continue;
            }

            $contexts[] = [
                'answer_id' => $answer->id,
                'stale_context' => [
                    'source_document_id' => $document->id,
                    'deleted_document_id' => $document->id,
                    'deleted_document_name' => $document->original_filename,
                    'source_document_name' => $document->original_filename,
                    'run_ids' => $runIds->map(static fn (mixed $value): int => (int) $value)->values()->all(),
                    'sole_source_page_ids' => array_values(array_map('intval', array_keys($soleSourcePageIdSet))),
                    'impacted_claim_ids' => $impactedClaimIds,
                    'impacted_source_reference_count' => $impactedSourceReferenceCount,
                    'supported_elsewhere_claim_ids' => $supportedElsewhereClaimIds,
                    'matched_source_page_ids' => array_values(array_map('intval', array_keys($matchedPageIds))),
                    'matched_claim_ids' => array_values(array_map('intval', array_keys($matchedClaimIds))),
                    'matched_unsupported_claim_ids' => array_values(array_map('intval', array_keys($matchedUnsupportedClaimIds))),
                ],
            ];
        }

        return [
            'candidate_contexts' => $contexts,
            'impacted_claim_ids' => $impactedClaimIds,
            'impacted_source_reference_count' => $impactedSourceReferenceCount,
        ];
    }
}
