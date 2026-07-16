<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
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
        $impact = $this->buildDocumentImpact($document, $runIds, $soleSourcePageIds);

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
        $contexts = $this->buildDocumentImpact($document, $runIds, $soleSourcePageIds)['candidate_contexts'];
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
     * Purpose: Count Wiki answers that would become stale if one published Wiki page changed.
     * Inputs: The page whose current content was just replaced.
     * Returns: A compact preview payload for logging/tests and for potential future UI use.
     * Side effects: None.
     */
    public function previewWikiPageChangeImpact(EnterpriseWikiPage|int $page): array
    {
        $resolvedPage = $this->resolveWikiPage($page);

        if (! $resolvedPage instanceof EnterpriseWikiPage) {
            return [
                'stale_wiki_answer_count' => 0,
                'changed_page_count' => 0,
            ];
        }

        $impact = $this->buildWikiPageImpact($resolvedPage);

        return [
            'stale_wiki_answer_count' => count($impact['candidate_contexts']),
            'changed_page_count' => count($impact['candidate_contexts']),
        ];
    }

    /**
     * Purpose: Mark already-generated Wiki answers as stale because a published Wiki page changed.
     * Inputs: The page whose current content was replaced.
     * Returns: A compact mutation summary for logging and tests.
     * Side effects: Updates saved_notice_ai_requirement_wiki_answers rows in place.
     */
    public function markAnswersStaleForWikiPageChange(EnterpriseWikiPage|int $page): array
    {
        $resolvedPage = $this->resolveWikiPage($page);

        if (! $resolvedPage instanceof EnterpriseWikiPage) {
            return [
                'stale_wiki_answer_count' => 0,
            ];
        }

        $contexts = $this->buildWikiPageImpact($resolvedPage)['candidate_contexts'];
        $now = now();
        $updated = 0;

        foreach ($contexts as $context) {
            $affected = SavedNoticeAiRequirementWikiAnswer::query()
                ->whereKey($context['answer_id'])
                ->whereNull('stale_at')
                ->update([
                    'stale_at' => $now,
                    'stale_reason' => $context['stale_reason'],
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
    private function buildDocumentImpact(
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
                    'stale_subject_type' => 'source_document',
                    'stale_subject_reason' => 'deleted',
                    'stale_subject_name' => $document->original_filename,
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

    /**
     * @return array{
     *     candidate_contexts: list<array{answer_id: int, stale_reason: string, stale_context: array<string, mixed>}>,
     *     changed_page_ids: list<int>
     * }
     */
    private function buildWikiPageImpact(EnterpriseWikiPage $page): array
    {
        $changedPageIds = [(int) $page->id];

        $currentPage = EnterpriseWikiPage::query()
            ->whereKey($page->id)
            ->first();

        if (! $currentPage instanceof EnterpriseWikiPage) {
            return [
                'candidate_contexts' => [],
                'changed_page_ids' => $changedPageIds,
            ];
        }

        $currentVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $currentPage->id)
            ->where('is_current', true)
            ->orderByDesc('version_number')
            ->first(['id', 'enterprise_wiki_page_id', 'version_number', 'content_markdown', 'is_current']);

        $answers = SavedNoticeAiRequirementWikiAnswer::query()
            ->whereHas('requirement.savedNotice', function ($query) use ($currentPage): void {
                $query->where('customer_id', $currentPage->customer_id);
            })
            ->get(['id', 'research_trace', 'sources', 'stale_at']);

        $contexts = [];

        foreach ($answers as $answer) {
            if ($answer->stale_at !== null) {
                continue;
            }

            $researchPages = $this->researchPagesForAnswer($answer);
            $matchedResearchPages = array_values(array_filter(
                $researchPages,
                static fn (array $researchPage): bool => (int) ($researchPage['page_id'] ?? 0) === $currentPage->id,
            ));

            if ($matchedResearchPages === []) {
                continue;
            }

            $matchedResearchPage = null;
            $snapshotHash = null;
            $currentHash = null;

            foreach ($matchedResearchPages as $researchPage) {
                $currentSnapshot = $currentVersion instanceof EnterpriseWikiPageVersion
                    ? $this->normalizeComparableMarkdown($this->extractComparableSnapshot(
                        (string) $currentVersion->content_markdown,
                        (string) ($researchPage['content_mode'] ?? 'full'),
                        is_array($researchPage['selected_headings'] ?? null) ? $researchPage['selected_headings'] : [],
                    ))
                    : null;

                $researchSnapshot = $this->normalizeComparableMarkdown($this->extractComparableSnapshot(
                    (string) ($researchPage['content_markdown'] ?? ''),
                    (string) ($researchPage['content_mode'] ?? 'full'),
                    is_array($researchPage['selected_headings'] ?? null) ? $researchPage['selected_headings'] : [],
                ));

                $snapshotHash = hash('sha256', $researchSnapshot);
                $currentHash = $currentSnapshot !== null ? hash('sha256', $currentSnapshot) : null;

                if ($currentSnapshot === null || $researchSnapshot !== $currentSnapshot) {
                    $matchedResearchPage = $researchPage;

                    break;
                }
            }

            if ($matchedResearchPage === null) {
                continue;
            }

            $staleReason = $currentSnapshot === null
                ? SavedNoticeAiRequirementWikiAnswer::STALE_REASON_WIKI_PAGE_DELETED
                : SavedNoticeAiRequirementWikiAnswer::STALE_REASON_WIKI_PAGE_UPDATED;

            $contexts[] = [
                'answer_id' => $answer->id,
                'stale_reason' => $staleReason,
                'stale_context' => [
                    'stale_subject_type' => 'wiki_page',
                    'stale_subject_reason' => $currentSnapshot === null ? 'deleted' : 'updated',
                    'stale_subject_name' => $currentPage->title,
                    'wiki_page_id' => $currentPage->id,
                    'wiki_page_title' => $currentPage->title,
                    'wiki_page_slug' => $currentPage->slug,
                    'changed_page_ids' => $changedPageIds,
                    'changed_page_titles' => [$currentPage->title],
                    'matched_page_ids' => [$currentPage->id],
                    'matched_page_titles' => [$currentPage->title],
                    'current_version_id' => $currentVersion?->id,
                    'current_version_number' => $currentVersion?->version_number,
                    'research_snapshot_content_mode' => $matchedResearchPage['content_mode'] ?? null,
                    'research_snapshot_selected_headings' => $matchedResearchPage['selected_headings'] ?? [],
                    'research_snapshot_hash' => $snapshotHash,
                    'current_snapshot_hash' => $currentHash,
                ],
            ];
        }

        return [
            'candidate_contexts' => $contexts,
            'changed_page_ids' => $changedPageIds,
        ];
    }

    private function resolveWikiPage(EnterpriseWikiPage|int $page): ?EnterpriseWikiPage
    {
        if ($page instanceof EnterpriseWikiPage) {
            return $page;
        }

        $resolved = EnterpriseWikiPage::query()->find($page);

        return $resolved instanceof EnterpriseWikiPage ? $resolved : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function researchPagesForAnswer(SavedNoticeAiRequirementWikiAnswer $answer): array
    {
        $researchTrace = is_array($answer->research_trace) ? $answer->research_trace : [];
        $researchPages = is_array($researchTrace['research']['pages'] ?? null)
            ? $researchTrace['research']['pages']
            : [];

        return array_values(array_filter(
            $researchPages,
            static fn (mixed $page): bool => is_array($page) && is_numeric($page['page_id'] ?? null),
        ));
    }

    private function extractComparableSnapshot(string $content, string $contentMode, array $selectedHeadings): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        if ($contentMode !== 'sections') {
            return $content;
        }

        $sections = $this->splitIntoHeadingSections($content);

        if ($sections === []) {
            return $content;
        }

        $selectedHeadingSet = array_fill_keys(
            array_map(
                static fn (mixed $heading): string => mb_strtolower(trim((string) $heading), 'UTF-8'),
                $selectedHeadings,
            ),
            true,
        );

        $kept = [];

        foreach ($sections as $index => $section) {
            if ($index === 0) {
                $kept[] = $section['text'];

                continue;
            }

            $heading = $section['heading'];

            if ($heading === null) {
                continue;
            }

            if (! isset($selectedHeadingSet[mb_strtolower(trim($heading), 'UTF-8')])) {
                continue;
            }

            $kept[] = $section['text'];
        }

        return implode("\n\n", $kept);
    }

    /**
     * @return list<array{heading: ?string, text: string}>
     */
    private function splitIntoHeadingSections(string $content): array
    {
        $sections = [];
        $current = null;

        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^\s{0,3}#{1,6}\s+(.+?)\s*$/u', $line, $matches) === 1) {
                if ($current !== null) {
                    $sections[] = $current;
                }

                $current = ['heading' => trim($matches[1]), 'lines' => [$line]];

                continue;
            }

            if ($current === null) {
                $current = ['heading' => null, 'lines' => []];
            }

            $current['lines'][] = $line;
        }

        if ($current !== null) {
            $sections[] = $current;
        }

        return array_map(
            static fn (array $section): array => [
                'heading' => $section['heading'],
                'text' => trim(implode("\n", $section['lines'])),
            ],
            array_values(array_filter($sections, static fn (array $section): bool => trim(implode("\n", $section['lines'])) !== '')),
        );
    }

    private function normalizeComparableMarkdown(string $content): string
    {
        $content = trim(str_replace(["\r\n", "\r"], "\n", $content));
        $content = preg_replace('/[ \t]+/u', ' ', $content) ?? $content;
        $content = preg_replace("/\n{3,}/u", "\n\n", $content) ?? $content;

        return trim($content);
    }
}
