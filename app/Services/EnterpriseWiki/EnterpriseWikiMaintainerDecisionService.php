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
 * the article/summary pointed the reader onward to a concept that concept_pages never created), and
 * EnterpriseWikiMaintainerDecisionHierarchyValidator checks it for overfragmentation (e.g. several
 * short practices under one framework each getting their own page). Both run on the same, complete
 * decision — for a split/batch decision this is always AFTER EnterpriseWikiMaintainerDecisionMerger
 * has combined every batch, so overfragmentation spanning multiple batches is still caught. When
 * either check finds issues, one bounded AI repair pass is attempted; if the repaired decision is
 * still inconsistent or overfragmented, this throws rather than silently applying it.
 *
 * Fase 8K-2 adds two more checks to that same loop, so a patch-contract violation is repaired by
 * the identical bounded pass rather than a parallel mechanism:
 *  - EnterpriseWikiCanonicalOwnershipValidator — canonical ownership, page granularity, the
 *    create-gate, patch-target coherence and the anti-shadow-channel rule. Pure array rules.
 *  - EnterpriseWikiPatchTargetResolver — the DB-authoritative half: a patch target must exist,
 *    belong to this customer, be live knowledge with a current version, and match the page_type
 *    and heading it claims. The row is the only authority on page_type; nothing here writes it.
 */
class EnterpriseWikiMaintainerDecisionService
{
    /**
     * How many bounded repair ROUNDS a decision may go through before it is rejected.
     *
     * Deliberately small and fixed. Each round is a handful of short delta calls (never a
     * regeneration), so a second round is affordable where the old whole-decision pass could only
     * ever be attempted once; more than that starts to look like negotiating with the model
     * instead of validating it, and fail-closed is the correct outcome for a decision this
     * pipeline cannot make sound in two attempts.
     */
    private const MAX_REPAIR_ROUNDS = 2;

    public function __construct(
        private readonly EnterpriseWikiIndexContextService $indexContextService,
        private readonly EnterpriseWikiPatchCandidateService $patchCandidateService,
        private readonly EnterpriseWikiMaintainerDecisionAiClient $aiClient,
        private readonly EnterpriseWikiMaintainerDecisionConsistencyValidator $consistencyValidator,
        private readonly EnterpriseWikiMaintainerDecisionHierarchyValidator $hierarchyValidator,
        private readonly EnterpriseWikiDocumentSourceElementService $sourceElementService,
        private readonly EnterpriseWikiCanonicalOwnershipValidator $canonicalOwnershipValidator,
        private readonly EnterpriseWikiPatchTargetResolver $patchTargetResolver,
        private readonly EnterpriseWikiPlannedTopicEvidenceValidator $plannedTopicEvidenceValidator,
        private readonly EnterpriseWikiPlannedPageSlotValidator $plannedPageSlotValidator,
        private readonly EnterpriseWikiMaintainerDecisionIssueAttributor $issueAttributor,
        private readonly EnterpriseWikiMaintainerDecisionDeltaMerger $deltaMerger,
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

        // ONE inspect() per decision — the document is parsed once and split into the two
        // contracts the maintainer prompt needs (Fase 8J-1B): images stay their own
        // FIGURE CANDIDATES block, prose/table elements become the addressable SOURCE ELEMENTS
        // catalog. Previously this same call was made and everything except the images was
        // discarded, which is exactly why the maintainer never saw addressable source provenance.
        $elements = $this->sourceElementService->inspect($document)['elements'];
        $figureCandidates = $this->figureCandidatesFromElements($elements);
        $sourceElements = EnterpriseWikiMaintainerDecisionAiClient::sourceCatalogElements($elements);
        $validFigureKeys = array_column($figureCandidates, 'source_element_key');

        // Fase 8K-1: the few existing pages this document plausibly revises, with their real
        // current content. The Wiki index above only carries a 200-character excerpt per page, so
        // a concrete threshold or deadline already recorded in the Wiki is invisible to the
        // decision without this. Read-only — nothing here patches anything (that is 8K-3).
        $existingPageCandidates = $this->patchCandidateService->findForDocument($document);

        $decision = $this->aiClient->decide($sourceMeta, $sourceText, $indexContext, $languageCode, $figureCandidates, $context, $sourceElements, $existingPageCandidates);

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

        // Same one-parse split as runForDocument(): the repair pass must see the same addressable
        // source elements the original decision was made against, or it would reason about the
        // document less precisely than the call it is correcting.
        $elements = $this->sourceElementService->inspect($document)['elements'];
        $figureCandidates = $this->figureCandidatesFromElements($elements);
        $sourceElements = EnterpriseWikiMaintainerDecisionAiClient::sourceCatalogElements($elements);
        $validFigureKeys = array_column($figureCandidates, 'source_element_key');
        // Every addressable element of this document, not just its images: a patch target's
        // source_element_keys authorise a substance change, which is normally prose or a table row.
        $validSourceElementKeys = array_values(array_filter(array_map(
            static fn (array $element): string => (string) ($element['source_element_key'] ?? ''),
            $sourceElements,
        ), static fn (string $key): bool => $key !== ''));
        $issues = $this->findAllIssues($decision, $indexContext, $validFigureKeys, $customerId, $validSourceElementKeys, $context->runId);

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
            $normalizedIssues = $this->findAllIssues($normalizedDecision, $indexContext, $validFigureKeys, $customerId, $validSourceElementKeys, $context->runId);

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

        $originalDecision = $decision;
        $remainingIssues = $issues;

        // Bounded ROUNDS, not one pass. A delta repair is small enough that a second round costs a
        // couple of short calls, and the first runtime verification showed why it is needed: a
        // correct fix inside one group can leave an object OUTSIDE that group dangling (a page
        // whose related_page_guidance pointed at a concept the repair demoted). The merge refuses
        // to touch such an object — correctly — so the follow-up has to be its own attributed
        // repair. Rounds are capped, every round must make progress, and anything left after the
        // cap still fails closed.
        for ($round = 1; $round <= self::MAX_REPAIR_ROUNDS; $round++) {
            $attribution = $this->issueAttributor->attribute(
                $decision,
                $remainingIssues,
                fn (array $candidateDecision): array => $this->findPureIssues($candidateDecision, $indexContext, $validFigureKeys, $validSourceElementKeys),
            );

            // An issue nobody can attribute to a specific object cannot be repaired within a
            // bounded contract, and the alternative — handing the model the whole decision again —
            // is the unbounded pass this flow exists to remove. Fail closed with the issue intact.
            if ($attribution['unattributed'] !== []) {
                Log::error('[WIKI_MAINTAINER_DECISION] Issue could not be attributed to a decision object — no bounded repair possible.', [
                    'customer_id' => $customerId,
                    'document_id' => $document->id,
                    'round' => $round,
                    'unattributed_issues' => $attribution['unattributed'],
                ]);

                throw new EnterpriseWikiMaintainerDecisionInconsistentException($remainingIssues);
            }

            $calls = $this->packRepairGroups($attribution['groups']);

            Log::warning('[WIKI_MAINTAINER_DECISION] Inconsistent decision detected — attempting bounded delta repair.', [
                'customer_id' => $customerId,
                'document_id' => $document->id,
                'validation_result' => 'ai_repair_required',
                'round' => $round,
                'normalizations' => $round === 1 ? $normalizations : [],
                'issues' => $remainingIssues,
                'decision_objects' => count(EnterpriseWikiMaintainerDecisionObjectIndex::objectIds($decision)),
                'repair_groups' => count($attribution['groups']),
                'repair_calls' => count($calls),
            ]);

            $repairs = [];

            foreach ($calls as $callIndex => $group) {
                $repairs[] = [
                    'group' => $group,
                    'delta' => $this->aiClient->repairGroup(
                        $sourceMeta,
                        $sourceText,
                        $indexContext,
                        $languageCode,
                        $decision,
                        $group,
                        $figureCandidates,
                        $context,
                        $sourceElements,
                    ),
                ];

                Log::info('[WIKI_MAINTAINER_DECISION] Bounded repair call completed.', [
                    'customer_id' => $customerId,
                    'document_id' => $document->id,
                    'round' => $round,
                    'call' => $callIndex + 1,
                    'of' => count($calls),
                    'objects' => $group['object_ids'],
                    'issues' => count($group['issues']),
                ]);
            }

            $merge = $this->deltaMerger->merge($decision, $repairs);
            $decision = EnterpriseWikiMaintainerDecisionPrompt::parse($merge['decision']);
            $issuesBefore = $remainingIssues;
            $remainingIssues = $this->findAllIssues($decision, $indexContext, $validFigureKeys, $customerId, $validSourceElementKeys, $context->runId);

            Log::info('[WIKI_MAINTAINER_DECISION] Repair delta merged.', [
                'customer_id' => $customerId,
                'document_id' => $document->id,
                'round' => $round,
                'applied_operations' => $merge['applied'],
                'issues_before' => count($issuesBefore),
                'issues_after' => count($remainingIssues),
            ]);

            if ($remainingIssues === []) {
                break;
            }

            // No progress means another round would ask the same model the same thing about the
            // same objects. Stop rather than spend calls on a fixpoint that will not move.
            if ($merge['applied'] === [] || $remainingIssues === $issuesBefore) {
                Log::error('[WIKI_MAINTAINER_DECISION] Bounded repair made no progress — stopping.', [
                    'customer_id' => $customerId,
                    'document_id' => $document->id,
                    'round' => $round,
                    'issues' => $remainingIssues,
                ]);

                break;
            }
        }

        $repaired = $decision;
        $this->logHeadingRepairOutcome($context->runId, $originalDecision, $repaired, $remainingIssues);

        if ($remainingIssues !== []) {
            Log::error('[WIKI_MAINTAINER_DECISION] Decision still inconsistent after bounded repair rounds.', [
                'customer_id' => $customerId,
                'document_id' => $document->id,
                'rounds' => self::MAX_REPAIR_ROUNDS,
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
     * @param  array<string, mixed>  $decision
     * @param  array<int, array<string, mixed>>  $indexContext
     * @param  string[]  $validFigureKeys
     * @return string[]
     */
    private function findAllIssues(
        array $decision,
        array $indexContext,
        array $validFigureKeys,
        int $customerId = 0,
        array $validSourceElementKeys = [],
        ?int $runId = null,
    ): array {
        return array_merge(
            $this->consistencyValidator->findIssues($decision, $indexContext, $validFigureKeys),
            $this->hierarchyValidator->findIssues($decision),
            // Fase 8K-2: canonical ownership + page granularity + patch-target coherence. Pure
            // array rules, so it joins the existing bounded AI repair loop unchanged.
            $this->canonicalOwnershipValidator->findIssues($decision, $indexContext, $validSourceElementKeys),
            // Every owned topic must be bound to real source evidence — the plan-to-evidence
            // contract page generation already consumes but nothing used to produce. This is the
            // cheapest place it can be checked: here an ungrounded planned section costs a few
            // hundred repair tokens, at generation time it costs one failed page per section and
            // takes the run down with it (run 53: five concept pages, five failed generations).
            $this->plannedTopicEvidenceValidator->findIssues($decision, $validSourceElementKeys),
            // Fase 8K-2: the DB-authoritative half — target exists, belongs to this customer, is
            // live, has a current version, and its real page_type/heading match what was claimed.
            // customerId 0 means a caller with no tenant context (never the document flow); skip
            // rather than invent a failure from missing context.
            $customerId > 0
                ? $this->patchTargetResolver->resolveForCustomer($customerId, $decision, $runId)['errors']
                : [],
            // An existing page must be named through the slot matching the type it already has.
            // Apply refuses to retype it (and still does) — but that refusal used to be the first
            // check anywhere, so run 55 died mid-apply on something knowable at decision time.
            $this->plannedPageSlotValidator->findIssues($decision, $customerId),
        );
    }

    /**
     * The deterministic, pure-array half of validation — no database, no customer context, safe to
     * run many times. Issue attribution re-runs this once per decision object to find out which
     * object an issue actually depends on, so it must stay side-effect free and cheap.
     *
     * @param  array<string, mixed>  $decision
     * @param  array<int, array<string, mixed>>  $indexContext
     * @param  string[]  $validFigureKeys
     * @param  string[]  $validSourceElementKeys
     * @return string[]
     */
    private function findPureIssues(
        array $decision,
        array $indexContext,
        array $validFigureKeys,
        array $validSourceElementKeys,
    ): array {
        return array_merge(
            $this->consistencyValidator->findIssues($decision, $indexContext, $validFigureKeys),
            $this->hierarchyValidator->findIssues($decision),
            $this->canonicalOwnershipValidator->findIssues($decision, $indexContext, $validSourceElementKeys),
            // Every owned topic must be bound to real source evidence before any page is generated.
            // Cheapest possible place to catch an ungrounded planned section: here it is a few
            // hundred repair tokens, at generation time it is a failed page per topic (run 53).
            $this->plannedTopicEvidenceValidator->findIssues($decision, $validSourceElementKeys),
        );
    }

    /**
     * Packs attributed repair groups into calls, filling each call up to the delta-repair profile's
     * own object budget.
     *
     * A group is kept whole whenever it fits, because objects that share an issue have to be
     * decided together. When a single group is larger than one bounded call can answer — a
     * consolidation finding naming seven candidates plus the seven pages created for them is the
     * ordinary case, not a pathological one — it is CHUNKED rather than refused: each chunk may
     * edit only its own objects, but every chunk is shown the whole cluster and the same issues as
     * read-only context, so each one decides against the same picture. A chunk that gets this wrong
     * is caught by full revalidation and handled in the next bounded round; refusing outright would
     * make an everyday fault unrepairable, which is the failure mode this whole design exists to
     * remove.
     *
     * @param  list<array{object_ids: list<string>, issues: list<string>}>  $groups
     * @return list<array{object_ids: list<string>, issues: list<string>, context_object_ids: list<string>}>
     */
    private function packRepairGroups(array $groups): array
    {
        $maxObjects = max(1, $this->aiClient->maxObjectsPerRepairCall());
        $calls = [];
        $current = ['object_ids' => [], 'issues' => [], 'context_object_ids' => []];

        foreach ($groups as $group) {
            if (! $this->aiClient->repairGroupFitsOneCall($group)) {
                if ($current['object_ids'] !== []) {
                    $calls[] = $current;
                    $current = ['object_ids' => [], 'issues' => [], 'context_object_ids' => []];
                }

                foreach (array_chunk($group['object_ids'], $maxObjects) as $chunk) {
                    $calls[] = [
                        'object_ids' => $chunk,
                        'issues' => $group['issues'],
                        'context_object_ids' => $group['object_ids'],
                    ];
                }

                Log::warning('[WIKI_MAINTAINER_DECISION] Repair group chunked across calls.', [
                    'group_objects' => count($group['object_ids']),
                    'max_objects_per_call' => $maxObjects,
                    'issues' => count($group['issues']),
                ]);

                continue;
            }

            if ($current['object_ids'] !== [] && count($current['object_ids']) + count($group['object_ids']) > $maxObjects) {
                $calls[] = $current;
                $current = ['object_ids' => [], 'issues' => [], 'context_object_ids' => []];
            }

            $current['object_ids'] = array_merge($current['object_ids'], $group['object_ids']);
            $current['issues'] = array_merge($current['issues'], $group['issues']);
        }

        if ($current['object_ids'] !== []) {
            $calls[] = $current;
        }

        return $calls;
    }

    /**
     * @param  array<string, mixed>  $originalDecision
     * @param  array<string, mixed>  $repairedDecision
     * @param  string[]  $remainingIssues
     */
    private function logHeadingRepairOutcome(?int $runId, array $originalDecision, array $repairedDecision, array $remainingIssues): void
    {
        if ($runId === null) {
            return;
        }

        $headingIssue = null;

        foreach ($remainingIssues as $issue) {
            if (str_contains($issue, 'issue_code=invalid_target_heading') || str_contains($issue, 'issue_code=missing_target_heading')) {
                $headingIssue = $issue;

                break;
            }
        }

        if ($headingIssue === null) {
            return;
        }

        $parsed = $this->parseHeadingIssue($headingIssue);

        if ($parsed === null) {
            return;
        }

        $originalTarget = (array) ($originalDecision['patch_targets'][$parsed['target_index']] ?? []);
        $repairedTarget = (array) ($repairedDecision['patch_targets'][$parsed['target_index']] ?? []);
        $resolution = 'still_invalid';

        if ($originalTarget !== [] && $repairedTarget !== []) {
            $originalPageId = (int) ($originalTarget['target_page_id'] ?? 0);
            $repairedPageId = (int) ($repairedTarget['target_page_id'] ?? 0);
            $originalHeading = $originalTarget['target_heading'] ?? null;
            $repairedHeading = $repairedTarget['target_heading'] ?? null;

            if ($repairedPageId !== $originalPageId) {
                $resolution = 'target_changed';
            } elseif ($repairedHeading === null && $originalHeading !== null) {
                $resolution = 'set_null';
            } elseif ($repairedHeading !== $originalHeading) {
                $resolution = 'corrected';
            }
        }

        Log::info('[WIKI_MAINTAINER_DECISION] Heading repair outcome.', [
            'run_id' => $runId,
            'target_page_id' => $parsed['target_page_id'],
            'current_version_id' => $parsed['current_version_id'],
            'invalid_heading' => $parsed['invalid_heading'],
            'valid_heading_count' => $parsed['valid_heading_count'],
            'issue_code' => $parsed['issue_code'],
            'repair_resolution' => $resolution,
        ]);
    }

    /**
     * @return array{target_index: int, target_page_id: int, current_version_id: int, invalid_heading: ?string, valid_heading_count: int, issue_code: string}|null
     */
    private function parseHeadingIssue(string $issue): ?array
    {
        if (! preg_match('/issue_code=(?<code>[a-z_]+); target_page_id=(?<page>\d+); .* current_version_id=(?<version>\d+); .* valid_target_headings=(?<headings>\[[^\]]*\])/u', $issue, $matches)) {
            return null;
        }

        if (! preg_match('/patch_targets\[(?<index>\d+)\]/u', $issue, $indexMatches)) {
            return null;
        }

        $invalidHeading = null;

        if (preg_match('/target_heading \[(?<heading>.*?)\]/u', $issue, $headingMatches) === 1) {
            $invalidHeading = $headingMatches['heading'] !== '' ? $headingMatches['heading'] : null;
        }

        return [
            'target_index' => (int) $indexMatches['index'],
            'target_page_id' => (int) $matches['page'],
            'current_version_id' => (int) $matches['version'],
            'invalid_heading' => $invalidHeading,
            'valid_heading_count' => count(json_decode($matches['headings'], true) ?: []),
            'issue_code' => (string) $matches['code'],
        ];
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
        return $this->figureCandidatesFromElements($this->sourceElementService->inspect($document)['elements']);
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @return list<array<string, mixed>>
     */
    private function figureCandidatesFromElements(array $elements): array
    {
        return array_values(array_filter(
            $elements,
            fn (array $element): bool => ($element['source_element_type'] ?? null) === EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_IMAGE,
        ));
    }
}
