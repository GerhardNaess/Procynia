<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiInvalidWikilinksException;
use App\Models\Customer;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageRelinkAttempt;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\Ai\Wiki\WikiLinkRevisionAiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Incremental relinking (8I-5): when a run creates or updates a concept/entity page, finds
 * existing customer pages (outside this run) that plausibly already discuss that concept, and
 * — only when a semantic reviser confirms a natural improvement — adds a single inline
 * [[wikilink]] to it, creating a new immutable page version and re-materializing that page's
 * canonical wikilink relations.
 *
 * This is deliberately NOT a full-wiki rewrite: candidate selection is a simple, deterministic,
 * customer-scoped, capped text-mention search — not RAG/embeddings. The AI is given exactly one
 * allowed target (the trigger page) per candidate and decides, per candidate, whether a change
 * is justified; it never sees or touches unrelated pages.
 *
 * Idempotency: one EnterpriseWikiPageRelinkAttempt row per (run, trigger page, candidate page).
 * A repeat call for the same run — including a duplicate dispatch — finds the existing row and
 * does not repeat the AI call or create another page version.
 */
class EnterpriseWikiIncrementalRelinkService
{
    /**
     * Maximum number of existing customer pages considered per concept/entity trigger page.
     * Bounds AI calls per run for customers with a large wiki; documented, not silent.
     */
    public const MAX_CANDIDATES_PER_TRIGGER = 10;

    private const TRIGGER_PAGE_TYPES = [
        EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
        EnterpriseWikiPage::PAGE_TYPE_ENTITY,
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
        private readonly WikiLinkRevisionAiClient $aiClient,
        private readonly EnterpriseWikiDocumentWikiAnswerStalenessService $wikiAnswerStalenessService,
        private readonly EnterpriseWikiPageVersionClaimSyncService $claimSyncService,
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
        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can trigger incremental relinking."
            );
        }

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        $runPageIds = $pivotRows->pluck('enterprise_wiki_page_id')->all();

        $triggers = $pivotRows
            ->filter(fn (EnterpriseWikiIngestRunPage $row) => $row->page !== null
                && in_array($row->page->page_type, self::TRIGGER_PAGE_TYPES, true)
                && in_array($row->action, EnterpriseWikiIngestRunPage::ACTIONS, true))
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
            $counts['triggers_processed']++;

            $candidates = $this->findCandidates($run, $trigger, $runPageIds);

            foreach ($candidates as $candidate) {
                $counts['candidates_considered']++;

                $status = $this->attemptRelink($run, $trigger, $candidate, $languageCode);

                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
        }

        if ($counts['applied'] > 0) {
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

    /**
     * Deterministic, capped, customer-scoped candidate selection: existing pages (outside this
     * run) whose current content_markdown plainly mentions the trigger page's title. No RAG, no
     * embeddings — a page either textually mentions the concept or it does not.
     */
    private function findCandidates(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $trigger, array $excludePageIds): Collection
    {
        $term = trim($trigger->title);

        if ($term === '') {
            return collect();
        }

        $matchingVersionPageIds = EnterpriseWikiPageVersion::query()
            ->where('is_current', true)
            ->where('content_markdown', 'ilike', '%'.addcslashes($term, '%_\\').'%')
            ->pluck('enterprise_wiki_page_id');

        if ($matchingVersionPageIds->isEmpty()) {
            return collect();
        }

        return EnterpriseWikiPage::query()
            ->where('customer_id', $run->customer_id)
            ->whereIn('id', $matchingVersionPageIds)
            ->where('id', '!=', $trigger->id)
            ->whereNotIn('id', $excludePageIds)
            ->where('status', '!=', EnterpriseWikiPage::STATUS_ARCHIVED)
            ->orderByDesc('updated_at')
            ->limit(self::MAX_CANDIDATES_PER_TRIGGER)
            ->get();
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

        $currentVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $candidate->id)
            ->where('is_current', true)
            ->first();

        $markdown = trim((string) ($currentVersion?->content_markdown ?? ''));

        if ($markdown === '' || ! str_contains(mb_strtolower($markdown), mb_strtolower($trigger->title))) {
            return $this->finalize($run, $trigger, $candidate, EnterpriseWikiPageRelinkAttempt::STATUS_SKIPPED, EnterpriseWikiPageRelinkAttempt::REASON_NO_MENTION);
        }

        $parsed = $this->linkParser->parse($markdown);
        $existingResolution = $this->linkResolver->resolve($run->customer_id, $candidate, $parsed);
        $existingValidTargetIds = collect($existingResolution['resolved'])->map(fn (array $t) => $t['to_page']->id)->all();

        if (in_array($trigger->id, $existingValidTargetIds, true)) {
            return $this->finalize($run, $trigger, $candidate, EnterpriseWikiPageRelinkAttempt::STATUS_SKIPPED, EnterpriseWikiPageRelinkAttempt::REASON_ALREADY_LINKED);
        }

        try {
            $catalog = [[
                'slug' => $trigger->slug,
                'title' => $trigger->title,
                'page_type' => $trigger->page_type,
            ]];

            $revision = $this->aiClient->reviseLinks(
                existingContent: $markdown,
                pageType: $candidate->page_type,
                linkCatalog: $catalog,
                instructions: $this->relinkInstructions($trigger),
                languageCode: $languageCode,
            );

            if (! $revision['changed'] || trim($revision['markdown']) === $markdown) {
                return $this->finalize($run, $trigger, $candidate, EnterpriseWikiPageRelinkAttempt::STATUS_SKIPPED, EnterpriseWikiPageRelinkAttempt::REASON_NO_SEMANTIC_IMPROVEMENT);
            }

            $revisedMarkdown = $revision['markdown'];

            $this->validateRevisedMarkdown($run, $candidate, $revisedMarkdown, $existingValidTargetIds);

            $newResolution = $this->linkResolver->resolve(
                $run->customer_id,
                $candidate,
                $this->linkParser->parse($revisedMarkdown),
            );
            $newValidTargetIds = collect($newResolution['resolved'])->map(fn (array $t) => $t['to_page']->id)->all();

            if (! in_array($trigger->id, $newValidTargetIds, true)) {
                return $this->finalize($run, $trigger, $candidate, EnterpriseWikiPageRelinkAttempt::STATUS_SKIPPED, EnterpriseWikiPageRelinkAttempt::REASON_AI_DECLINED_LINK);
            }

            $newVersion = $this->writeNewCurrentVersion($candidate->id, $revisedMarkdown);
            $this->wikiAnswerStalenessService->markAnswersStaleForWikiPageChange($candidate->id);
            $this->buildPageLinksService->materializeWikilinksForPage($candidate, $run->id);
            $this->pendingClaimResyncRunIds = array_merge(
                $this->pendingClaimResyncRunIds,
                $this->claimSyncService->markPageForResync($candidate),
            );

            return $this->finalize(
                $run,
                $trigger,
                $candidate,
                EnterpriseWikiPageRelinkAttempt::STATUS_APPLIED,
                null,
                $newVersion->id,
            );
        } catch (EnterpriseWikiInvalidWikilinksException $e) {
            Log::warning('[WIKI_INCREMENTAL_RELINK] Revision rejected.', [
                'run_id' => $run->id,
                'trigger_page_id' => $trigger->id,
                'candidate_page_id' => $candidate->id,
                'reason' => $e->getMessage(),
            ]);

            return $this->finalize($run, $trigger, $candidate, EnterpriseWikiPageRelinkAttempt::STATUS_FAILED, EnterpriseWikiPageRelinkAttempt::REASON_INVALID_REVISION);
        } catch (Throwable $e) {
            Log::error('[WIKI_INCREMENTAL_RELINK] Relink attempt failed.', [
                'run_id' => $run->id,
                'trigger_page_id' => $trigger->id,
                'candidate_page_id' => $candidate->id,
                'error' => $e->getMessage(),
            ]);

            return $this->finalize($run, $trigger, $candidate, EnterpriseWikiPageRelinkAttempt::STATUS_FAILED, 'ai_error');
        }
    }

    /**
     * Reject a revision that introduces any broken/self/cross-customer wikilink, contains a
     * malformed-but-attempted wikilink, or drops a wikilink target that was valid before the
     * revision. Never repairs — an invalid revision is rejected outright and the candidate page
     * is left untouched.
     *
     * @param  list<int>  $previousValidTargetIds
     *
     * @throws EnterpriseWikiInvalidWikilinksException
     */
    private function validateRevisedMarkdown(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $candidate,
        string $revisedMarkdown,
        array $previousValidTargetIds,
    ): void {
        $parsed = $this->linkParser->parse($revisedMarkdown);
        $rawOccurrences = $this->linkParser->countRawOccurrences($revisedMarkdown);

        if ($rawOccurrences > count($parsed)) {
            throw new EnterpriseWikiInvalidWikilinksException(sprintf(
                'Run [%d] candidate page [%d]: revised content contains %d malformed wikilink attempt(s).',
                $run->id,
                $candidate->id,
                $rawOccurrences - count($parsed),
            ));
        }

        $occurrences = $this->linkResolver->resolveOccurrences($run->customer_id, $candidate, $parsed);
        $invalidSlugs = [];

        foreach ($occurrences as $occurrence) {
            if ($occurrence['status'] !== EnterpriseWikiLinkResolver::STATUS_VALID) {
                $invalidSlugs[] = $occurrence['link']['target_slug'];
            }
        }

        if ($invalidSlugs !== []) {
            throw new EnterpriseWikiInvalidWikilinksException(sprintf(
                'Run [%d] candidate page [%d]: %d invalid wikilink slug(s) in revision: %s.',
                $run->id,
                $candidate->id,
                count($invalidSlugs),
                implode(', ', array_values(array_unique($invalidSlugs))),
            ));
        }

        $resolution = $this->linkResolver->resolve($run->customer_id, $candidate, $parsed);
        $newValidTargetIds = collect($resolution['resolved'])->map(fn (array $t) => $t['to_page']->id)->all();
        $droppedTargetIds = array_diff($previousValidTargetIds, $newValidTargetIds);

        if ($droppedTargetIds !== []) {
            throw new EnterpriseWikiInvalidWikilinksException(sprintf(
                'Run [%d] candidate page [%d]: revision dropped %d previously valid wikilink(s).',
                $run->id,
                $candidate->id,
                count($droppedTargetIds),
            ));
        }
    }

    private function relinkInstructions(EnterpriseWikiPage $trigger): string
    {
        return implode("\n", [
            "A new or updated page now exists in this customer's wiki: \"{$trigger->title}\" (page type: {$trigger->page_type}, slug: {$trigger->slug}).",
            '',
            'If this content naturally and meaningfully discusses that specific concept or entity, add exactly',
            "one natural inline wikilink to it — [[{$trigger->slug}|natural visible text]] or [[{$trigger->slug}]] —",
            'at its first or most natural mention. Do not add more than one link to this target, and do not',
            'change anything else in the content.',
            '',
            "If this content does not meaningfully discuss \"{$trigger->title}\" beyond an incidental mention,",
            'make no change.',
        ]);
    }

    private function writeNewCurrentVersion(int $pageId, string $markdown): EnterpriseWikiPageVersion
    {
        $next = ((int) EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $pageId)
            ->max('version_number')) + 1;

        EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $pageId)
            ->where('is_current', true)
            ->update(['is_current' => false]);

        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $pageId,
            'version_number' => $next,
            'is_current' => true,
            'content_markdown' => $markdown,
            'generated_by_model' => WikiLinkRevisionAiClient::MODEL.'/incremental-relink',
        ]);
    }

    private function finalize(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $trigger,
        EnterpriseWikiPage $candidate,
        string $status,
        ?string $reason,
        ?int $createdPageVersionId = null,
    ): string {
        EnterpriseWikiPageRelinkAttempt::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('trigger_page_id', $trigger->id)
            ->where('candidate_page_id', $candidate->id)
            ->update([
                'status' => $status,
                'reason' => $reason,
                'created_page_version_id' => $createdPageVersionId,
                'attempted_at' => now(),
            ]);

        return $status;
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
