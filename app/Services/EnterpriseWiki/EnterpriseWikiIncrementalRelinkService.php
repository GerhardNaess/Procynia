<?php

namespace App\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageRelinkAttempt;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\Ai\Wiki\EnterpriseWikiIndexContextService;
use App\Services\Ai\Wiki\WikiInboundLinkProposalAiClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Inbound relinking: when a run creates a page, existing customer pages (outside this run) that
 * genuinely discuss its subject may get ONE inline link to it, so existing knowledge connects to
 * the new knowledge and not only the other way round.
 *
 * Relink v2 (D8, docs/enterprise-wiki-knowledge-integration-plan.md §6.4). The AI no longer
 * rewrites the existing page. WikiInboundLinkProposalAiClient is shown the page's content blocks
 * and returns a placement only — block, anchor words, reason — or declines. The backend inserts
 * the link into the unchanged text (EnterpriseWikiInboundLinkInserter) and proves mechanically
 * that only link markup was added. Nothing is truncated, nothing is paraphrased, and a proposal
 * that cannot be placed unambiguously is rejected rather than guessed at.
 *
 * Triggers: a concept/entity page this run touched (as before v2), and — since v2 — an article
 * this run created. Summaries are not triggers: a summary is paired with its article.
 *
 * Candidates are bounded and deterministic: the existing pages the maintainer decision pointed at
 * for the trigger page (validated by EnterpriseWikiRelatedPageReferenceResolver), then pages whose
 * current content mentions the trigger's title. Never the whole Wiki, never embeddings. Pages in
 * review, archived or superseded pages, pages without content and the run's own pages are never
 * candidates, and a candidate that already links to the trigger is left alone.
 *
 * Publication: a published candidate gets a new draft working version via the existing
 * afterAutomatedContentChange() rule (D4) — its published version keeps serving, and the change
 * goes through ordinary review. Nothing is published automatically.
 *
 * Idempotency: one EnterpriseWikiPageRelinkAttempt row per (run, trigger page, candidate page). A
 * repeat call for the same run — a retried continuation or a duplicate dispatch — finds the row
 * and makes no AI call, no version and no link.
 */
class EnterpriseWikiIncrementalRelinkService
{
    /**
     * Maximum number of existing customer pages considered per trigger page. Bounds AI calls per
     * run for customers with a large wiki; documented, not silent.
     */
    public const MAX_CANDIDATES_PER_TRIGGER = 5;

    /** Maximum number of candidates considered across all triggers of one run. */
    public const MAX_CANDIDATES_PER_RUN = 15;

    /** Characters of whole content blocks shown to the model per candidate. */
    public const MAX_PROMPT_BLOCK_CHARS = 20000;

    public const REASON_NO_ELIGIBLE_BLOCKS = 'no_eligible_blocks';

    public const REASON_STALE_CANDIDATE_VERSION = 'stale_candidate_version';

    public const REASON_CANDIDATE_NOT_ELIGIBLE = 'candidate_not_eligible';

    public const REASON_CANDIDATE_ALREADY_RELINKED = 'candidate_already_relinked_in_run';

    /** Candidate page statuses that never receive an automatic change. */
    private const EXCLUDED_CANDIDATE_STATUSES = [
        EnterpriseWikiPage::STATUS_PENDING_REVIEW,
        EnterpriseWikiPage::STATUS_ARCHIVED,
        EnterpriseWikiPage::STATUS_SUPERSEDED,
    ];

    /**
     * Ingest run ids collected during the current relinkForRun() call whose claims need
     * re-syncing (EnterpriseWikiPageVersionClaimSyncService::syncRuns()) because a candidate page
     * got a new current version. A candidate is, by definition, a page outside $run's own pivot,
     * so it is not assumed to belong to $run — every run id its own pivot row(s) reference is
     * collected instead. Reset at the start of every relinkForRun() call.
     *
     * @var list<int>
     */
    private array $pendingClaimResyncRunIds = [];

    public function __construct(
        private readonly EnterpriseWikiLinkParser $linkParser,
        private readonly EnterpriseWikiLinkResolver $linkResolver,
        private readonly EnterpriseWikiBuildPageLinksService $buildPageLinksService,
        private readonly WikiInboundLinkProposalAiClient $aiClient,
        private readonly EnterpriseWikiInboundLinkInserter $inserter,
        private readonly EnterpriseWikiRelatedPageReferenceResolver $referenceResolver,
        private readonly EnterpriseWikiDocumentWikiAnswerStalenessService $wikiAnswerStalenessService,
        private readonly EnterpriseWikiPageVersionClaimSyncService $claimSyncService,
        private readonly EnterpriseWikiPageVersionWriter $versionWriter,
        private readonly EnterpriseWikiPublicationSettlementService $publicationSettlement,
    ) {}

    /**
     * @return array{
     *     triggers_processed: int,
     *     candidates_considered: int,
     *     applied: int,
     *     skipped: int,
     *     failed: int,
     * }
     *
     * @throws InvalidArgumentException if the run is not applied
     */
    public function relinkForRun(EnterpriseWikiIngestRun $run): array
    {
        if (($run->fresh() ?? $run)->isTerminal()) {
            return ['triggers_processed' => 0, 'candidates_considered' => 0, 'applied' => 0, 'skipped' => 0, 'failed' => 0];
        }

        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can trigger incremental relinking."
            );
        }

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->orderBy('id')
            ->get();

        $runPageIds = $pivotRows->pluck('enterprise_wiki_page_id')->map(static fn ($id): int => (int) $id)->all();

        // Only a page this run actually generated can be a link target worth adding elsewhere: a
        // page whose generation failed in a partially completed run is an empty shell, and
        // relinking existing pages to it would point readers at content that does not exist.
        $triggers = $pivotRows
            ->filter(fn (EnterpriseWikiIngestRunPage $row) => $row->page !== null
                && self::isTrigger($row)
                && $row->generation_status !== EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED
                && EnterpriseWikiRelatedPageReferenceResolver::hasContent((int) $row->enterprise_wiki_page_id))
            ->pluck('page');

        $counts = [
            'triggers_processed' => 0,
            'candidates_considered' => 0,
            'applied' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $languageCode = $this->resolveLanguageCode($run->customer_id);

        $this->pendingClaimResyncRunIds = [];

        foreach ($triggers as $trigger) {
            if (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal()) {
                break;
            }

            $counts['triggers_processed']++;

            foreach ($this->findCandidates($run, $trigger, $runPageIds) as $candidate) {
                if ($counts['candidates_considered'] >= self::MAX_CANDIDATES_PER_RUN) {
                    Log::info('[WIKI_INCREMENTAL_RELINK] Run candidate cap reached.', [
                        'run_id' => $run->id,
                        'trigger_page_id' => $trigger->id,
                        'cap' => self::MAX_CANDIDATES_PER_RUN,
                    ]);

                    break 2;
                }

                $counts['candidates_considered']++;

                $status = $this->attemptRelink($run, $trigger, $candidate, $languageCode);

                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
        }

        if ($counts['applied'] > 0 && ! (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal() ?? true)) {
            // A relinked candidate is a brand-new EnterpriseWikiPageVersion — its claims must be
            // re-extracted/verified against this version (see
            // EnterpriseWikiPageVersionClaimSyncService), not left pointing at the superseded one.
            $this->claimSyncService->syncRuns($this->pendingClaimResyncRunIds);
        }

        Log::info('[WIKI_INCREMENTAL_RELINK] Incremental relinking completed.', [
            'run_id' => $run->id,
            'triggers_processed' => $counts['triggers_processed'],
            'candidates_considered' => $counts['candidates_considered'],
            'applied' => $counts['applied'],
            'skipped' => $counts['skipped'],
            'failed' => $counts['failed'],
        ]);

        return $counts;
    }

    public static function isTrigger(EnterpriseWikiIngestRunPage $row): bool
    {
        $pageType = $row->page?->page_type;

        if (in_array($pageType, [EnterpriseWikiPage::PAGE_TYPE_CONCEPT, EnterpriseWikiPage::PAGE_TYPE_ENTITY], true)) {
            return in_array($row->action, EnterpriseWikiIngestRunPage::ACTIONS, true);
        }

        // An article the run CREATED is new knowledge older pages may point to. An updated or
        // patched article already had its inbound links.
        return $pageType === EnterpriseWikiPage::PAGE_TYPE_ARTICLE
            && $row->action === EnterpriseWikiIngestRunPage::ACTION_CREATED;
    }

    /**
     * Bounded, deterministic, customer-scoped candidates: the planner's validated references for
     * the trigger page first, then pages whose current content mentions the trigger's title. No
     * RAG, no embeddings.
     *
     * @param  list<int>  $runPageIds
     * @return Collection<int, EnterpriseWikiPage>
     */
    private function findCandidates(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $trigger, array $runPageIds): Collection
    {
        $excluded = [...$runPageIds, (int) $trigger->id];
        $planned = array_values(array_diff($this->referenceResolver->resolveForPage($run, $trigger)['page_ids'], $excluded));

        $mentioning = [];
        $term = trim($trigger->title);

        if ($term !== '') {
            $mentioning = EnterpriseWikiPageVersion::query()
                ->where('is_current', true)
                ->whereIn('enterprise_wiki_page_id', $this->eligibleCandidates($run)->select('id'))
                ->where('content_markdown', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->pluck('enterprise_wiki_page_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        $plannedPages = $planned === [] ? collect() : $this->eligibleCandidates($run)
            ->whereIn('id', $planned)
            ->whereNotIn('id', $excluded)
            ->get()
            ->sortBy(fn (EnterpriseWikiPage $page): int => array_search((int) $page->id, $planned, true))
            ->values();

        $mentioningPages = $mentioning === [] ? collect() : $this->eligibleCandidates($run)
            ->whereIn('id', $mentioning)
            ->whereNotIn('id', [...$excluded, ...$plannedPages->pluck('id')->all()])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        return $plannedPages->concat($mentioningPages)->take(self::MAX_CANDIDATES_PER_TRIGGER)->values();
    }

    private function eligibleCandidates(EnterpriseWikiIngestRun $run): Builder
    {
        return EnterpriseWikiPage::query()
            ->where('customer_id', $run->customer_id)
            ->whereNotIn('status', self::EXCLUDED_CANDIDATE_STATUSES)
            ->whereExists(fn ($versions) => $versions
                ->selectRaw('1')
                ->from('enterprise_wiki_page_versions')
                ->whereColumn('enterprise_wiki_page_versions.enterprise_wiki_page_id', 'enterprise_wiki_pages.id')
                ->where('enterprise_wiki_page_versions.is_current', true));
    }

    /**
     * @return string one of EnterpriseWikiPageRelinkAttempt::STATUSES
     */
    private function attemptRelink(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $trigger,
        EnterpriseWikiPage $candidate,
        string $languageCode,
    ): string {
        $claimed = DB::transaction(function () use ($run, $trigger, $candidate): bool {
            $lockedRun = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($run->id);

            if (! $lockedRun instanceof EnterpriseWikiIngestRun || $lockedRun->isTerminal()) {
                return false;
            }

            $exists = EnterpriseWikiPageRelinkAttempt::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('trigger_page_id', $trigger->id)
                ->where('candidate_page_id', $candidate->id)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                return false;
            }

            // Reserve the slot immediately — this row is what makes a duplicate/concurrent
            // dispatch for the same run/trigger/candidate a no-op instead of a second AI call.
            EnterpriseWikiPageRelinkAttempt::query()->create([
                'customer_id' => $run->customer_id,
                'enterprise_wiki_ingest_run_id' => $run->id,
                'trigger_page_id' => $trigger->id,
                'candidate_page_id' => $candidate->id,
                'status' => EnterpriseWikiPageRelinkAttempt::STATUS_SKIPPED,
                'reason' => 'reserved',
                'attempted_at' => now(),
            ]);

            return true;
        });

        if (! $claimed) {
            return $this->existingAttemptStatus($run, $trigger, $candidate);
        }

        // At most one new inbound link per candidate per run.
        $alreadyRelinked = EnterpriseWikiPageRelinkAttempt::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('candidate_page_id', $candidate->id)
            ->where('status', EnterpriseWikiPageRelinkAttempt::STATUS_APPLIED)
            ->exists();

        if ($alreadyRelinked) {
            return $this->finalize($run, $trigger, $candidate, EnterpriseWikiPageRelinkAttempt::STATUS_SKIPPED, self::REASON_CANDIDATE_ALREADY_RELINKED);
        }

        $currentVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $candidate->id)
            ->where('is_current', true)
            ->first();

        $markdown = (string) ($currentVersion?->content_markdown ?? '');

        if (! $currentVersion instanceof EnterpriseWikiPageVersion || trim($markdown) === '') {
            return $this->finalize($run, $trigger, $candidate, EnterpriseWikiPageRelinkAttempt::STATUS_SKIPPED, EnterpriseWikiPageRelinkAttempt::REASON_NO_MENTION);
        }

        $existingResolution = $this->linkResolver->resolve($run->customer_id, $candidate, $this->linkParser->parse($markdown));
        $existingValidTargetIds = collect($existingResolution['resolved'])->map(fn (array $t) => $t['to_page']->id)->all();

        if (in_array($trigger->id, $existingValidTargetIds, true)) {
            return $this->finalize($run, $trigger, $candidate, EnterpriseWikiPageRelinkAttempt::STATUS_SKIPPED, EnterpriseWikiPageRelinkAttempt::REASON_ALREADY_LINKED);
        }

        [$blocks, $hasStoredBlocks] = $this->blocksOf($currentVersion);
        $promptBlocks = $this->promptBlocks($blocks);

        if ($promptBlocks === []) {
            return $this->finalize($run, $trigger, $candidate, EnterpriseWikiPageRelinkAttempt::STATUS_SKIPPED, self::REASON_NO_ELIGIBLE_BLOCKS);
        }

        try {
            if (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal()) {
                return EnterpriseWikiPageRelinkAttempt::STATUS_SKIPPED;
            }

            $proposal = $this->aiClient->propose(
                existingPage: ['title' => $candidate->title, 'page_type' => $candidate->page_type],
                blocks: $promptBlocks,
                newPage: [
                    'page_id' => (int) $trigger->id,
                    'title' => $trigger->title,
                    'page_type' => $trigger->page_type,
                    'excerpt' => EnterpriseWikiIndexContextService::excerpt(
                        EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $trigger->id)->where('is_current', true)->value('content_markdown'),
                        EnterpriseWikiLinkCatalogService::EXCERPT_MAX_LENGTH,
                    ),
                ],
                languageCode: $languageCode,
            );
        } catch (Throwable $e) {
            Log::error('[WIKI_INCREMENTAL_RELINK] Relink attempt failed.', [
                'run_id' => $run->id,
                'trigger_page_id' => $trigger->id,
                'candidate_page_id' => $candidate->id,
                'error' => $e->getMessage(),
            ]);

            return $this->finalize($run, $trigger, $candidate, EnterpriseWikiPageRelinkAttempt::STATUS_FAILED, 'ai_error');
        }

        if ($proposal['decision'] !== WikiInboundLinkProposalAiClient::DECISION_LINK) {
            return $this->finalize($run, $trigger, $candidate, EnterpriseWikiPageRelinkAttempt::STATUS_SKIPPED, EnterpriseWikiPageRelinkAttempt::REASON_AI_DECLINED_LINK);
        }

        $sentKeys = array_column($promptBlocks, 'block_key');

        if ($proposal['block_key'] === null || $proposal['anchor_text'] === null || ! in_array($proposal['block_key'], $sentKeys, true)) {
            return $this->rejectProposal($run, $trigger, $candidate, EnterpriseWikiInboundLinkInserter::REJECT_UNKNOWN_BLOCK);
        }

        $insertion = $this->inserter->insert(
            $markdown,
            $blocks,
            $proposal['block_key'],
            $proposal['anchor_text'],
            (string) $trigger->slug,
            [
                'intent_id' => "inbound-{$run->id}-{$trigger->id}",
                'target_page_id' => (int) $trigger->id,
                'anchor_text' => $proposal['anchor_text'],
                'reason' => $proposal['reason'],
                'origin' => 'inbound_relink',
            ],
        );

        if (! $insertion['ok']) {
            return $this->rejectProposal($run, $trigger, $candidate, $insertion['reason']);
        }

        // The inserted link must resolve to the trigger and nothing previously linked may be lost —
        // the inserter proves the text, this proves the link graph.
        $newResolution = $this->linkResolver->resolve($run->customer_id, $candidate, $this->linkParser->parse($insertion['content_markdown']));
        $newValidTargetIds = collect($newResolution['resolved'])->map(fn (array $t) => $t['to_page']->id)->all();

        if (! in_array($trigger->id, $newValidTargetIds, true) || array_diff($existingValidTargetIds, $newValidTargetIds) !== []) {
            return $this->rejectProposal($run, $trigger, $candidate, EnterpriseWikiInboundLinkInserter::REJECT_INVARIANT_VIOLATION);
        }

        try {
            $outcome = DB::transaction(function () use ($run, $candidate, $currentVersion, $insertion, $hasStoredBlocks): EnterpriseWikiPageVersion|string {
                $lockedRun = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($run->id);

                if (! $lockedRun instanceof EnterpriseWikiIngestRun || $lockedRun->isTerminal()) {
                    return 'terminal';
                }

                $lockedCandidate = EnterpriseWikiPage::query()->lockForUpdate()->find($candidate->id);

                if (! $lockedCandidate instanceof EnterpriseWikiPage || in_array($lockedCandidate->status, self::EXCLUDED_CANDIDATE_STATUSES, true)) {
                    return self::REASON_CANDIDATE_NOT_ELIGIBLE;
                }

                // The proposal was made against a specific version. If anyone — a person editing,
                // another step — wrote a newer one meanwhile, that work is never overwritten.
                $stillCurrentId = EnterpriseWikiPageVersion::query()
                    ->where('enterprise_wiki_page_id', $candidate->id)
                    ->where('is_current', true)
                    ->value('id');

                if ((int) $stillCurrentId !== (int) $currentVersion->id) {
                    return self::REASON_STALE_CANDIDATE_VERSION;
                }

                $version = $this->versionWriter->writeNewCurrentVersion($candidate->id, array_filter([
                    'content_markdown' => $insertion['content_markdown'],
                    'content_blocks_json' => $hasStoredBlocks ? $insertion['content_blocks_json'] : null,
                    'generated_by_model' => WikiInboundLinkProposalAiClient::model().'/inbound-relink',
                ], static fn (mixed $value): bool => $value !== null));

                // Candidates are ordinary customer pages picked outside this run, so a published one
                // is the normal case. The link is a change the reader sees: the page returns to
                // draft for ordinary review while its approved version keeps serving (D4).
                $this->publicationSettlement->afterAutomatedContentChange((int) $candidate->id);
                $this->wikiAnswerStalenessService->markAnswersStaleForWikiPageChange($candidate->id);
                $this->buildPageLinksService->materializeWikilinksForPage($candidate, $run->id);
                $this->pendingClaimResyncRunIds = array_merge(
                    $this->pendingClaimResyncRunIds,
                    $this->claimSyncService->markPageForResync($candidate),
                );

                return $version;
            });
        } catch (Throwable $e) {
            Log::error('[WIKI_INCREMENTAL_RELINK] Relink write failed.', [
                'run_id' => $run->id,
                'trigger_page_id' => $trigger->id,
                'candidate_page_id' => $candidate->id,
                'error' => $e->getMessage(),
            ]);

            return $this->finalize($run, $trigger, $candidate, EnterpriseWikiPageRelinkAttempt::STATUS_FAILED, 'write_failed');
        }

        if ($outcome === 'terminal') {
            return EnterpriseWikiPageRelinkAttempt::STATUS_SKIPPED;
        }

        if (is_string($outcome)) {
            return $this->finalize($run, $trigger, $candidate, EnterpriseWikiPageRelinkAttempt::STATUS_SKIPPED, $outcome);
        }

        return $this->finalize(
            $run,
            $trigger,
            $candidate,
            EnterpriseWikiPageRelinkAttempt::STATUS_APPLIED,
            null,
            $outcome->id,
        );
    }

    private function rejectProposal(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $trigger, EnterpriseWikiPage $candidate, string $reason): string
    {
        Log::warning('[WIKI_INCREMENTAL_RELINK] Inbound link proposal rejected.', [
            'run_id' => $run->id,
            'trigger_page_id' => $trigger->id,
            'candidate_page_id' => $candidate->id,
            'reason' => $reason,
        ]);

        return $this->finalize($run, $trigger, $candidate, EnterpriseWikiPageRelinkAttempt::STATUS_FAILED, EnterpriseWikiPageRelinkAttempt::REASON_INVALID_REVISION.':'.$reason);
    }

    /**
     * The candidate's blocks. A legacy version without stored blocks is addressed by its
     * paragraphs instead; the edit is then made to its markdown only, and no block JSON is invented.
     *
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function blocksOf(EnterpriseWikiPageVersion $version): array
    {
        $stored = array_values(array_filter((array) ($version->content_blocks_json ?? []), 'is_array'));

        if ($stored !== []) {
            return [$stored, true];
        }

        $paragraphs = preg_split("/\n{2,}/", trim((string) $version->content_markdown)) ?: [];
        $blocks = [];

        foreach ($paragraphs as $index => $paragraph) {
            $blocks[] = ['block_key' => 'paragraph-'.($index + 1), 'markdown' => $paragraph];
        }

        return [$blocks, false];
    }

    /**
     * Whole eligible blocks, in page order, until the character budget is spent. A block is never
     * cut; blocks past the budget are simply not offered for an anchor.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array{block_key: string, markdown: string}>
     */
    private function promptBlocks(array $blocks): array
    {
        $selected = [];
        $chars = 0;

        foreach ($blocks as $block) {
            if (! EnterpriseWikiInboundLinkInserter::isEligibleBlock($block) || trim((string) ($block['block_key'] ?? '')) === '') {
                continue;
            }

            $markdown = (string) $block['markdown'];

            if ($chars + mb_strlen($markdown) > self::MAX_PROMPT_BLOCK_CHARS) {
                break;
            }

            $chars += mb_strlen($markdown);
            $selected[] = ['block_key' => (string) $block['block_key'], 'markdown' => $markdown];
        }

        return $selected;
    }

    private function finalize(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $trigger,
        EnterpriseWikiPage $candidate,
        string $status,
        ?string $reason,
        ?int $createdPageVersionId = null,
    ): string {
        $updated = DB::transaction(function () use ($run, $trigger, $candidate, $status, $reason, $createdPageVersionId): bool {
            $lockedRun = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($run->id);

            if (! $lockedRun instanceof EnterpriseWikiIngestRun || $lockedRun->isTerminal()) {
                return false;
            }

            return EnterpriseWikiPageRelinkAttempt::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('trigger_page_id', $trigger->id)
                ->where('candidate_page_id', $candidate->id)
                ->update([
                    'status' => $status,
                    'reason' => $reason,
                    'created_page_version_id' => $createdPageVersionId,
                    'attempted_at' => now(),
                ]) > 0;
        });

        return $updated ? $status : EnterpriseWikiPageRelinkAttempt::STATUS_SKIPPED;
    }

    private function existingAttemptStatus(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $trigger, EnterpriseWikiPage $candidate): string
    {
        $attempt = EnterpriseWikiPageRelinkAttempt::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('trigger_page_id', $trigger->id)
            ->where('candidate_page_id', $candidate->id)
            ->first();

        return $attempt?->status ?? EnterpriseWikiPageRelinkAttempt::STATUS_SKIPPED;
    }

    private function resolveLanguageCode(int $customerId): string
    {
        $customer = Customer::query()->with('language')->find($customerId);

        return $customer?->language?->code ?? 'no';
    }
}
