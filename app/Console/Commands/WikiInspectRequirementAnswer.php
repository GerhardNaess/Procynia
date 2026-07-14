<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiClaim;
use App\Models\SavedNoticeAiRequirement;
use App\Services\Ai\Wiki\RequirementWikiAnswerAiClient;
use App\Services\Ai\Wiki\RequirementWikiResearchService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only diagnostics for the Fase 9 Wiki-research flow: runs (and prints, but never persists)
 * the exact research a real "Generer Wiki-svar" click would perform for one requirement — initial
 * candidate scores, round-by-round decisions, pages actually read (full or sectioned), pages
 * discovered via wikilinks/backlinks, pages rejected, and why research stopped.
 *
 * Read-only by design: never writes to saved_notice_ai_requirement_wiki_answers or any other
 * table, never deletes anything, never touches queues, never calls the existing Knowledge Base/RAG
 * pipeline. The research step itself does call the Enterprise Wiki AI (navigation decisions only)
 * unless --dry-run is passed; the final answer is only generated (still never persisted) when
 * --generate-answer is explicitly passed.
 */
#[Signature('wiki:inspect-requirement-answer {--requirement-id=} {--generate-answer} {--dry-run}')]
#[Description('Read-only: show the Wiki-research trace (and optionally the answer it would produce) for one requirement, without persisting anything.')]
class WikiInspectRequirementAnswer extends Command
{
    public function handle(
        RequirementWikiResearchService $researchService,
        RequirementWikiAnswerAiClient $answerAiClient,
    ): int {
        $requirementId = (int) $this->option('requirement-id');

        if (! $requirementId) {
            $this->error('--requirement-id is required.');

            return self::FAILURE;
        }

        $requirement = SavedNoticeAiRequirement::query()->with('savedNotice.customer.language')->find($requirementId);

        if ($requirement === null) {
            $this->error("Requirement [{$requirementId}] not found.");

            return self::FAILURE;
        }

        $customerId = (int) ($requirement->savedNotice?->customer_id ?? 0);

        if ($customerId === 0) {
            $this->error("Requirement [{$requirementId}] has no resolvable customer.");

            return self::FAILURE;
        }

        $this->line("Requirement #{$requirement->id} ({$requirement->requirement_identifier}):");
        $this->line($requirement->requirement_text);
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->line('--dry-run passed: not calling the Wiki AI. Nothing further to show.');

            return self::SUCCESS;
        }

        $languageCode = $requirement->savedNotice?->customer?->language?->code ?? 'no';

        try {
            $context = $researchService->research($requirement, $customerId, $languageCode);
        } catch (Throwable $exception) {
            $this->error('Research failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->renderInitialCandidates($context['initial_candidates']);
        $this->renderRounds($context['research_rounds']);
        $this->renderReadPages($context['pages']);
        $this->renderLimits($context['limits']);

        if (! $this->option('generate-answer')) {
            $this->newLine();
            $this->line('Skipping final answer generation (pass --generate-answer to also preview it). Nothing was persisted.');

            return self::SUCCESS;
        }

        if ($context['pages'] === []) {
            $this->newLine();
            $this->line('No pages were read — coverage would be "none", answer_text would be null.');

            return self::SUCCESS;
        }

        $this->renderAnswerPreview($requirement, $context, $answerAiClient, $languageCode);

        return self::SUCCESS;
    }

    private function renderInitialCandidates(array $candidates): void
    {
        $this->line('=== Initial candidates (direct search, ranked) ===');

        if ($candidates === []) {
            $this->line('(none scored above zero)');

            return;
        }

        $this->table(
            ['page_id', 'title', 'score', 'title_hit', 'heading_hits', 'content_hits', 'ratio', 'claim_hits'],
            array_map(static fn (array $candidate): array => [
                $candidate['page_id'],
                $candidate['title'],
                $candidate['score'],
                $candidate['score_breakdown']['title_hit'] ? 'yes' : 'no',
                $candidate['score_breakdown']['heading_overlap_count'],
                $candidate['score_breakdown']['content_overlap_count'],
                $candidate['score_breakdown']['content_overlap_ratio'],
                $candidate['score_breakdown']['claim_hit_count'],
            ], $candidates),
        );
    }

    private function renderRounds(array $rounds): void
    {
        $this->newLine();
        $this->line('=== Research rounds ===');

        if ($rounds === []) {
            $this->line('(no rounds run — stopped before the first AI navigation decision)');

            return;
        }

        foreach ($rounds as $round) {
            $selected = implode(', ', $round['selected_page_ids'] ?? []) ?: '(none)';
            $searchTerms = isset($round['search_terms']) ? implode(', ', $round['search_terms']) : null;
            $this->line("Round {$round['round']}: action={$round['action']} selected_page_ids=[{$selected}]".($searchTerms !== null ? " search_terms=[{$searchTerms}]" : ''));
            $this->line("  reason: {$round['reason']}");
        }
    }

    private function renderReadPages(array $pages): void
    {
        $this->newLine();
        $this->line('=== Pages read ===');

        if ($pages === []) {
            $this->line('(none)');

            return;
        }

        foreach ($pages as $page) {
            $discovery = $page['selection_type'] === 'direct_search'
                ? 'direct search'
                : "wikilink ({$page['link_direction']}) from #{$page['discovered_from_page_id']} {$page['discovered_from_title']}";

            $this->line("#{$page['page_id']} {$page['title']} [{$page['page_type']}] — round {$page['round_read']}, discovered via: {$discovery}");
            $this->line('  content_mode: '.$page['content_mode'].($page['content_mode'] === 'sections' ? ' — sections used: '.implode(', ', $page['selected_headings']) : ' (whole page)'));
            $this->line('  content length: '.mb_strlen($page['content_markdown'], 'UTF-8').' chars, supporting claims: '.count($page['supporting_claim_ids']));
        }
    }

    private function renderLimits(array $limits): void
    {
        $this->newLine();
        $this->line('=== Limits ===');
        $this->line("catalog_size={$limits['catalog_size']} rounds_used={$limits['rounds_used']}/{$limits['max_rounds']} pages_read={$limits['pages_read']}/{$limits['max_pages']} context_size={$limits['context_size']}/{$limits['max_context_size']}");
        $this->line("stop_reason: {$limits['stop_reason']}");
    }

    private function renderAnswerPreview(
        SavedNoticeAiRequirement $requirement,
        array $context,
        RequirementWikiAnswerAiClient $answerAiClient,
        string $languageCode,
    ): void {
        $claimTextById = EnterpriseWikiClaim::query()
            ->whereIn('id', array_values(array_unique(array_merge([], ...array_map(
                static fn (array $page): array => $page['supporting_claim_ids'],
                $context['pages'],
            )))))
            ->pluck('claim_text', 'id');

        $pagesForAnswer = array_map(
            static fn (array $page): array => [
                'page_id' => $page['page_id'],
                'title' => $page['title'],
                'page_type' => $page['page_type'],
                'content_mode' => $page['content_mode'],
                'content_markdown' => $page['content_markdown'],
                'selected_headings' => $page['selected_headings'],
                'claim_texts' => array_values(array_filter(array_map(
                    static fn (int $claimId) => $claimTextById[$claimId] ?? null,
                    $page['supporting_claim_ids'],
                ))),
            ],
            $context['pages'],
        );

        try {
            $result = $answerAiClient->generateAnswer(
                (string) ($requirement->requirement_identifier ?? ''),
                (string) $requirement->requirement_text,
                $pagesForAnswer,
                $languageCode,
            );
        } catch (Throwable $exception) {
            $this->newLine();
            $this->error('Answer generation failed: '.$exception->getMessage());

            return;
        }

        $this->newLine();
        $this->line('=== Answer preview (NOT persisted) ===');
        $this->line("coverage_status: {$result['coverage_status']}");
        $this->line('used_page_ids: '.implode(', ', $result['used_page_ids']));

        if ($result['missing_summary'] !== null) {
            $this->line("missing_summary: {$result['missing_summary']}");
        }

        foreach ($result['answer_sections'] as $index => $section) {
            $this->newLine();
            $this->line('--- Section '.($index + 1).' (pages: '.implode(', ', $section['page_ids']).') ---');
            $this->line($section['text']);
        }
    }
}
