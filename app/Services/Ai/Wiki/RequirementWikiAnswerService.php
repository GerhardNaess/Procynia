<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use Illuminate\Support\Facades\DB;

/**
 * Generates a Wiki-based answer for an already-extracted requirement (Fase 9 — "Generer
 * Wiki-svar"), correcting the original claim-summarizing design to the Karpathy query pattern:
 * search the customer's compiled Wiki → read the actually relevant pages' content_markdown →
 * follow their real wikilinks/backlinks when that adds knowledge → synthesize the answer from the
 * pages that were actually read → cite the pages the answer relies on.
 *
 * Orchestration only: RequirementWikiResearchService owns search/read/navigate/stop, and
 * RequirementWikiAnswerAiClient owns writing the final answer from an already-read page set. This
 * class combines the two and persists the result.
 *
 * Deliberately a fully separate flow from the existing answer-draft engine
 * (RequirementAnswerDraftService): no new requirement extraction, no parallel requirement table,
 * no dependency on KnowledgeItem/KnowledgeItemChunk, no reuse of the existing Knowledge Base/RAG
 * retrieval, and no writes to answer_draft_* — every Wiki answer is persisted on its own
 * SavedNoticeAiRequirementWikiAnswer row.
 *
 * Claims/source references keep their original role (see EnterpriseWikiClaim/
 * EnterpriseWikiSourceReference) — a documentation, quality, and anti-fabrication layer, no longer
 * the primary text the answer is built from. A read page's supporting_claim_ids (computed by
 * RequirementWikiResearchService) are surfaced to the answer AI client as "VERIFIED FACTS" that
 * concrete commitments (SLAs, response times, roles, tools, certifications, etc.) must be grounded
 * in — the page's own explanatory prose is otherwise trusted as the compiled, approved knowledge
 * it is.
 */
class RequirementWikiAnswerService
{
    public const ENGINE_VERSION = 'wiki_reader_v2';

    public function __construct(
        private readonly RequirementWikiResearchService $researchService,
        private readonly RequirementWikiAnswerAiClient $answerAiClient,
    ) {}

    /**
     * Purpose: Generate (or regenerate) the Wiki-based answer for one requirement.
     * Inputs: The requirement, the customer id it belongs to (never trusted from the requirement
     *         itself — always the owning SavedNotice's own customer_id, resolved by the caller),
     *         the language to answer in, and the user triggering generation.
     * Returns: The persisted (created or updated in place) Wiki-answer row.
     * Side effects: Writes exactly one row to saved_notice_ai_requirement_wiki_answers, upserted
     *               by saved_notice_ai_requirement_id. Never touches saved_notice_ai_requirements
     *               itself or any answer_draft_* column. May make OpenAI calls (research
     *               navigation + final answer) via the two collaborators above.
     */
    public function generate(
        SavedNoticeAiRequirement $requirement,
        int $customerId,
        string $languageCode,
        ?int $userId = null,
    ): SavedNoticeAiRequirementWikiAnswer {
        $context = $this->researchService->research($requirement, $customerId, $languageCode);

        if ($context['pages'] === []) {
            return $this->persist($requirement, [
                'coverage_status' => SavedNoticeAiRequirementWikiAnswer::COVERAGE_NONE,
                'answer_text' => null,
                'missing_summary' => $this->noPagesReadMessage($context),
                'sources' => [],
                'model' => null,
                'research_trace' => ['research' => $context, 'answer' => null],
                'engine_version' => self::ENGINE_VERSION,
            ], $userId);
        }

        $claimTextsByPageId = $this->claimTextsByPageId($context['pages']);

        $pagesForAnswer = array_map(
            static fn (array $page): array => [
                'page_id' => $page['page_id'],
                'title' => $page['title'],
                'page_type' => $page['page_type'],
                'content_mode' => $page['content_mode'],
                'content_markdown' => $page['content_markdown'],
                'selected_headings' => $page['selected_headings'],
                'claim_texts' => $claimTextsByPageId[$page['page_id']] ?? [],
            ],
            $context['pages'],
        );

        $result = $this->answerAiClient->generateAnswer(
            (string) ($requirement->requirement_identifier ?? ''),
            (string) $requirement->requirement_text,
            $pagesForAnswer,
            $languageCode,
        );

        $answerText = $result['coverage_status'] === SavedNoticeAiRequirementWikiAnswer::COVERAGE_NONE
            ? null
            : implode("\n\n", array_column($result['answer_sections'], 'text'));

        return $this->persist($requirement, [
            'coverage_status' => $result['coverage_status'],
            'answer_text' => $answerText,
            'missing_summary' => $result['missing_summary'],
            'sources' => $this->sourcesPayload($context['pages'], $result['used_page_ids']),
            'model' => 'gpt-4.1-mini',
            'research_trace' => ['research' => $context, 'answer' => $result],
            'engine_version' => self::ENGINE_VERSION,
        ], $userId);
    }

    /**
     * Purpose: Fetch the claim text for every claim id referenced by any read page, in one query.
     * Inputs: The research context's read pages (each carrying supporting_claim_ids).
     * Returns: page_id => list of claim texts for that page.
     * Side effects: None.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return array<int, list<string>>
     */
    private function claimTextsByPageId(array $pages): array
    {
        $allClaimIds = array_values(array_unique(array_merge(
            [],
            ...array_map(static fn (array $page): array => $page['supporting_claim_ids'], $pages),
        )));

        if ($allClaimIds === []) {
            return [];
        }

        $claimTextById = EnterpriseWikiClaim::query()
            ->whereIn('id', $allClaimIds)
            ->pluck('claim_text', 'id');

        $byPageId = [];

        foreach ($pages as $page) {
            $byPageId[$page['page_id']] = array_values(array_filter(array_map(
                static fn (int $claimId): ?string => $claimTextById[$claimId] ?? null,
                $page['supporting_claim_ids'],
            )));
        }

        return $byPageId;
    }

    /**
     * Purpose: Convert the pages actually cited in the answer into the persisted sources payload.
     * Inputs: All read pages from the research context and the answer's validated used_page_ids.
     * Returns: One entry per cited page (already unique — a page is read at most once per run),
     *          carrying its discovery provenance so the UI can distinguish direct-search hits from
     *          pages found by following Wiki links/backlinks.
     * Side effects: None.
     *
     * @param  list<array<string, mixed>>  $pages
     * @param  list<int>  $usedPageIds
     * @return list<array{enterprise_wiki_page_id: int, page_title: string, page_slug: string, page_type: string, selection_type: string, discovered_from_page_id: ?int, discovered_from_title: ?string, link_direction: ?string, supporting_claim_ids: list<int>}>
     */
    private function sourcesPayload(array $pages, array $usedPageIds): array
    {
        if ($usedPageIds === []) {
            return [];
        }

        $usedPageIds = array_flip($usedPageIds);

        return array_values(array_map(
            static fn (array $page): array => [
                'enterprise_wiki_page_id' => $page['page_id'],
                'page_title' => $page['title'],
                'page_slug' => $page['slug'],
                'page_type' => $page['page_type'],
                'selection_type' => $page['selection_type'],
                'discovered_from_page_id' => $page['discovered_from_page_id'],
                'discovered_from_title' => $page['discovered_from_title'],
                'link_direction' => $page['link_direction'],
                'supporting_claim_ids' => $page['supporting_claim_ids'],
            ],
            array_values(array_filter($pages, static fn (array $page): bool => isset($usedPageIds[$page['page_id']]))),
        ));
    }

    private function noPagesReadMessage(array $context): string
    {
        $stopReason = $context['limits']['stop_reason'] ?? null;

        return $stopReason === 'no_relevant_candidates'
            ? 'Ingen godkjent Wiki-informasjon er tilgjengelig for dette kravet i kundemiljøet.'
            : 'Wiki-forskningen fant ingen sider som kunne besvare dette kravet.';
    }

    private function persist(SavedNoticeAiRequirement $requirement, array $attributes, ?int $userId): SavedNoticeAiRequirementWikiAnswer
    {
        return DB::transaction(function () use ($requirement, $attributes, $userId): SavedNoticeAiRequirementWikiAnswer {
            return SavedNoticeAiRequirementWikiAnswer::query()->updateOrCreate(
                ['saved_notice_ai_requirement_id' => $requirement->id],
                array_merge($attributes, [
                    'generated_by_user_id' => $userId,
                    'generated_at' => now(),
                ]),
            );
        });
    }
}
