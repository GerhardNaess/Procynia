<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a persisted maintainer decision to create or update stub wiki pages.
 *
 * Idempotent across runs for the same customer + canonical slug: an existing
 * EnterpriseWikiPage always wins over the decision's create/update assumption. The
 * `source_article`/`source_summary` schema carries no `page_id` field at all (only
 * concept/entity entries do — see EnterpriseWikiMaintainerDecisionPrompt), so an
 * `action=update` decision for the source article/summary can never be resolved by page_id;
 * the canonical (customer_id, slug) lookup is what makes a re-applied decision for a document
 * whose pages were already created by an earlier, partially-completed run reuse those pages
 * instead of attempting a duplicate INSERT.
 *
 * Constraints:
 *  - No content_markdown generation; no page_versions created.
 *  - No claims or source references written.
 *  - No OpenAI calls.
 *  - Customer-scoped: every lookup (by page_id or by slug) is gated to run->customer_id.
 *  - Idempotency guard: throws if maintainer_decision_status is already 'applied'.
 *  - Hierarchy guard: refuses to apply at all if EnterpriseWikiMaintainerDecisionHierarchyValidator
 *    still finds overfragmentation issues in the stored decision — apply() never persists a page
 *    the combined hierarchy validation would have rejected or consolidated, even if some upstream
 *    caller stored an unvalidated/unrepaired decision on the run. This is a defensive re-check, not
 *    a substitute for EnterpriseWikiMaintainerDecisionService::validateAndRepairForDocument(), which
 *    is still the normal place a decision gets repaired before ever reaching a run.
 */
class EnterpriseWikiMaintainerDecisionApplyService
{
    public function __construct(
        private readonly EnterpriseWikiMaintainerDecisionHierarchyValidator $hierarchyValidator,
    ) {}

    /**
     * @return array{created: int, updated: int, patch_targets_deferred: int}
     *
     * @throws \InvalidArgumentException
     */
    public function apply(EnterpriseWikiIngestRun $run): array
    {
        $allowedStatuses = [
            EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION,
            EnterpriseWikiIngestRun::STATUS_APPLYING,
        ];

        if (! in_array($run->status, $allowedStatuses, true)) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has status [{$run->status}], expected [decision_only, maintainer_decision, applying]."
            );
        }

        if ($run->source_type !== EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has source_type [{$run->source_type}], expected [enterprise_wiki_document]."
            );
        }

        if (empty($run->maintainer_decision_json)) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has no maintainer_decision_json."
            );
        }

        if ($run->maintainer_decision_status === EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has already been applied."
            );
        }

        $decision = $run->maintainer_decision_json;

        $hierarchyIssues = $this->hierarchyValidator->findIssues($decision);

        if ($hierarchyIssues !== []) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] maintainer decision still has unresolved overfragmentation issues: ".
                implode(' | ', $hierarchyIssues)
            );
        }

        $customerId = $run->customer_id;
        $created = 0;
        $updated = 0;

        // Fase 8K-2: pages this decision patches rather than generates. They deliberately get NO
        // pivot row — see deferPatchTargets() for why that IS the safety mechanism.
        $patchTargetPageIds = EnterpriseWikiPatchTargetResolver::targetPageIds($decision);

        DB::transaction(function () use ($run, $decision, $customerId, $patchTargetPageIds, &$created, &$updated): void {
            $lockedRun = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($run->id);

            if (! $lockedRun instanceof EnterpriseWikiIngestRun || $lockedRun->isTerminal()) {
                return;
            }

            foreach ($this->collectEntries($decision) as [$entry, $pageType]) {
                $wasCreated = $this->applyEntry($lockedRun, $entry, $pageType, $customerId, $patchTargetPageIds);

                $wasCreated ? $created++ : $updated++;
            }

            $lockedRun->maintainer_decision_status = EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED;
            $lockedRun->save();
        });

        $deferred = $this->deferPatchTargets($run, $decision, $patchTargetPageIds);

        return ['created' => $created, 'updated' => $updated, 'patch_targets_deferred' => $deferred];
    }

    /**
     * Record that this run carries structured patch intent. Apply deliberately does NOT act on it.
     *
     * The patch targets stay where the decision put them — in `maintainer_decision_json`, already
     * persisted, already validated. No GENERATION pivot row is created for a patch target here, and
     * that absence is a safety mechanism rather than an omission: page generation is driven entirely
     * by `enterprise_wiki_ingest_run_pages` (see
     * EnterpriseWikiDocumentFlowService::beginGeneratingPages() and
     * FinalizeEnterpriseWikiPageGeneration), so a page with no pivot is never dispatched, never
     * reaches EnterpriseWikiGenerateAppliedPagesService::generatePageForRun(), and therefore cannot
     * be regenerated from the new source document alone — which is exactly the destructive behaviour
     * observed after 8K-1, where an existing page lost unrelated substance, its provenance and two
     * wikilinks to a full-page rewrite triggered by `action=update`.
     *
     * It also means the run does not stall: the finalizer only ever counts pivots, so a target that is
     * invisible here lets the flow continue exactly as before.
     *
     * Fase 8K-3 now consumes these targets — EnterpriseWikiPatchApplicationService::applyForRun(),
     * called from EnterpriseWikiDocumentFlowService::performApplyPatchTargets() once the run's own
     * pages are generated. It reads this same `maintainer_decision_json['patch_targets']`, patches the
     * bounded area each target authorizes, and writes at most one new current version per page —
     * creating its own pivot row with `action=patched` and the version id already set, which is why
     * that row can never be mistaken for pending generation work.
     *
     * No new table, column or status is introduced by either phase.
     *
     * @param  array<string, mixed>  $decision
     * @param  list<int>  $patchTargetPageIds
     * @return int number of patch targets handed to 8K-3
     */
    private function deferPatchTargets(EnterpriseWikiIngestRun $run, array $decision, array $patchTargetPageIds): int
    {
        $targets = (array) ($decision['patch_targets'] ?? []);

        if ($targets === []) {
            return 0;
        }

        Log::info('[WIKI_DOCUMENT_FLOW] Patch targets recorded by apply — applied later by the patch step, never by page generation.', [
            'run_id' => $run->id,
            'customer_id' => $run->customer_id,
            'patch_targets' => count($targets),
            'target_page_ids' => $patchTargetPageIds,
            'operations' => array_count_values(array_values(array_filter(array_map(
                static fn (mixed $target): string => is_array($target) ? (string) ($target['operation'] ?? '') : '',
                $targets,
            ), static fn (string $operation): bool => $operation !== ''))),
        ]);

        return count($targets);
    }

    /**
     * Resolve (reuse or create) the page for one decision entry and register its pivot row.
     *
     * @return bool true if this call created the page, false if an existing page was reused.
     */
    private function applyEntry(
        EnterpriseWikiIngestRun $run,
        array $entry,
        string $pageType,
        int $customerId,
        array $patchTargetPageIds = [],
    ): bool {
        $requestedAction = $entry['action'] ?? 'create';
        $pageIdHint = $entry['page_id'] ?? null;

        [$page, $wasCreated] = $this->resolvePage($run, $entry, $pageType, $customerId, $requestedAction, $pageIdHint);

        // Fase 8K-2 destructive-update guard. A page the decision patches must never also become a
        // generated page for this run: the pivot row created below is what dispatches full page
        // generation from the new source document alone, which would discard the substance the patch
        // target exists to preserve. EnterpriseWikiCanonicalOwnershipValidator already rejects this
        // combination before apply, so reaching here means an unvalidated decision was stored —
        // fail loudly rather than write the page and lose content.
        if (in_array((int) $page->id, array_map('intval', $patchTargetPageIds), true)) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] plans page [{$page->id}] as a generated page while patch_targets also targets it — "
                .'a page is either regenerated as this run\'s own page or patched as existing knowledge, never both.'
            );
        }

        if (! $wasCreated) {
            $this->syncReusedPage($page, $pageType, $run, $pageIdHint !== null && $requestedAction === 'update');

            Log::info('[WIKI_DOCUMENT_FLOW] Existing page reused during maintainer apply.', [
                'run_id' => $run->id,
                'page_id' => $page->id,
                'customer_id' => $customerId,
                'slug' => $page->slug,
                'page_type' => $pageType,
                'requested_action' => $requestedAction,
                'applied_action' => EnterpriseWikiIngestRunPage::ACTION_UPDATED,
            ]);
        }

        EnterpriseWikiIngestRunPage::query()->firstOrCreate(
            [
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
            ],
            [
                'action' => $wasCreated
                    ? EnterpriseWikiIngestRunPage::ACTION_CREATED
                    : EnterpriseWikiIngestRunPage::ACTION_UPDATED,
            ],
        );

        return $wasCreated;
    }

    /**
     * Resolution order:
     *  1. An explicit page_id hint (concept/entity `update`) — the only case the decision
     *     schema can supply one at all; still validated to belong to this customer.
     *  2. Canonical (customer_id, slug) lookup — wins over the decision's own action, since
     *     `source_article`/`source_summary` never carry a page_id and an earlier,
     *     partially-completed run may already have created this exact page.
     *  3. Create — guarded by a savepoint so a genuine concurrent-create race (two runs
     *     resolving the same new slug at once) is resolved deterministically by re-reading
     *     the row the unique constraint let win, rather than by inventing a new slug.
     *
     * @return array{0: EnterpriseWikiPage, 1: bool} [page, wasCreated]
     */
    private function resolvePage(
        EnterpriseWikiIngestRun $run,
        array $entry,
        string $pageType,
        int $customerId,
        string $requestedAction,
        ?int $pageIdHint,
    ): array {
        if ($requestedAction === 'update' && $pageIdHint !== null) {
            $page = EnterpriseWikiPage::query()
                ->where('customer_id', $customerId)
                ->where('id', $pageIdHint)
                ->lockForUpdate()
                ->first();

            if ($page === null) {
                throw new \InvalidArgumentException(
                    "Page [{$pageIdHint}] not found for customer [{$customerId}]."
                );
            }

            return [$page, false];
        }

        $slug = $entry['proposed_slug'];

        $existing = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', $slug)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            $page = DB::transaction(fn () => EnterpriseWikiPage::query()->create([
                'customer_id' => $customerId,
                // Only on this branch: the reuse path above returns an existing page untouched, so a
                // later run enriching it never takes ownership away from whoever created it.
                'owner_user_id' => app(EnterpriseWikiPageOwnerService::class)->ownerUserIdForRun($run),
                'slug' => $slug,
                'title' => $entry['title'],
                'page_type' => $pageType,
                'status' => EnterpriseWikiPage::STATUS_DRAFT,
                'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
                'last_source_hash' => $this->sourceHash($run),
            ]));

            return [$page, true];
        } catch (UniqueConstraintViolationException) {
            // A concurrent apply() for another run won the race on this exact
            // (customer_id, slug) between our lookup and our insert. Never invent a new slug
            // to dodge the collision — deterministically continue as a reuse of that row.
            $page = EnterpriseWikiPage::query()
                ->where('customer_id', $customerId)
                ->where('slug', $slug)
                ->lockForUpdate()
                ->firstOrFail();

            return [$page, false];
        }
    }

    /**
     * Only the fields the apply contract allows on reuse: last_source_hash (kept fresh for this
     * run's source document) and, on the slug-resolution path only, page_type. Title, slug, status,
     * and current page version are never touched — no history is rewritten or deleted.
     *
     * Fase 8K-2 page_type safety. page_type is repaired ONLY when the page was resolved by canonical
     * (customer_id, slug) — the earlier-partially-completed-run case this behaviour exists for. When
     * the decision named the page by an explicit page_id, a page_type mismatch means the model put
     * an existing page into the wrong typed slot, and silently retyping it would corrupt real data:
     * an article addressed through `entity_pages` would simply become an entity. That is refused
     * loudly instead. Patch targets are immune by construction — they name a page by id and never by
     * slot, and never reach this method at all.
     */
    private function syncReusedPage(
        EnterpriseWikiPage $page,
        string $pageType,
        EnterpriseWikiIngestRun $run,
        bool $resolvedByExplicitPageId = false,
    ): void {
        $expectedHash = $this->sourceHash($run);

        if ($page->page_type !== $pageType && $resolvedByExplicitPageId) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] targets existing page [{$page->id}] of type [{$page->page_type}] through a [{$pageType}] slot — "
                .'a page\'s type is never changed by the slot a decision names it in. Use patch_targets to address an existing page of any type.'
            );
        }

        $attributes = ['last_source_hash' => $expectedHash];

        if ($page->page_type !== $pageType) {
            $attributes['page_type'] = $pageType;
        }

        if ($page->page_type !== $pageType || $page->last_source_hash !== $expectedHash) {
            $page->update($attributes);
        }
    }

    private function sourceHash(EnterpriseWikiIngestRun $run): string
    {
        return hash('sha256', "enterprise_wiki_document:{$run->source_id}");
    }

    /** @return list<array{0: array<string, mixed>, 1: string}> */
    private function collectEntries(array $decision): array
    {
        $entries = [];

        if (! empty($decision['source_article'])) {
            $entries[] = [$decision['source_article'], EnterpriseWikiPage::PAGE_TYPE_ARTICLE];
        }

        if (! empty($decision['source_summary'])) {
            $entries[] = [$decision['source_summary'], EnterpriseWikiPage::PAGE_TYPE_SUMMARY];
        }

        foreach ($decision['concept_pages'] ?? [] as $entry) {
            $entries[] = [$entry, EnterpriseWikiPage::PAGE_TYPE_CONCEPT];
        }

        foreach ($decision['entity_pages'] ?? [] as $entry) {
            $entries[] = [$entry, EnterpriseWikiPage::PAGE_TYPE_ENTITY];
        }

        return $entries;
    }
}
