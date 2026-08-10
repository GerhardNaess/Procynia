<?php

namespace App\Services\EnterpriseWiki;

/**
 * Fase 8K-2: enforces the plan's two bindende produktregler on a maintainer decision, before
 * anything is applied.
 *
 *  1. `No duplicate canonical ownership on authoritative change` — if substance already has a
 *     canonical owner, `create` is illegal for that substance and the existing owner must be
 *     targeted instead.
 *
 *  2. `Canonical page granularity` — a new page is not justified by the topic being new. Only a
 *     topic that is BOTH new AND semantically independent earns its own canonical page; changed,
 *     extended, specialised and reference-only topics belong on the existing page.
 *
 * Both were prompt guidance nowhere and free text everywhere before this class. The run observed
 * after 8K-1 showed the cost precisely: the maintainer correctly identified an existing article as
 * outdated for two requirements and could only say so in `warnings`, because the contract had no
 * slot for it and nothing checked whether one was needed. The rules below are decidable because
 * 8K-2 added the two fields that make them decidable — `relationship` on a concept candidate, and
 * `patch_targets` on the decision.
 *
 * A sibling to EnterpriseWikiMaintainerDecisionConsistencyValidator (logical self-contradiction)
 * and EnterpriseWikiMaintainerDecisionHierarchyValidator (page breadth): same pure-array shape,
 * same "return human-readable issues, repair them elsewhere" contract, so the existing bounded
 * AI repair pass in EnterpriseWikiMaintainerDecisionService picks these up unchanged.
 *
 * DELIBERATELY NOT HERE — these belong to 8K-4, after a write:
 *  - whether a superseded value still sits current on some OTHER page nobody targeted
 *  - whether a patch removed unrelated substance, provenance or wikilinks
 *  - any cross-page semantic conflict or duplicate detection
 *
 * Everything below is decided from the decision itself (plus the existing wiki index for title
 * matching). No database access, no AI, no embeddings.
 */
class EnterpriseWikiCanonicalOwnershipValidator
{
    /**
     * `source_article`/`source_summary` are DOCUMENT representation, not canonical knowledge
     * ownership, and are therefore exempt from the create-gate: a change note legitimately gets its
     * own article and summary even when every factual change it carries belongs to an existing
     * canonical page. What the exemption does NOT license is those pages taking ownership of the
     * faglige substance — that is what the patch targets are for, and what the prompt says
     * explicitly.
     */
    private const CANONICAL_PAGE_KEYS = ['concept_pages', 'entity_pages'];

    /**
     * @param  array<string, mixed>  $decision
     * @param  array<int, array<string, mixed>>  $indexContext  Existing wiki pages (id/title/...).
     * @param  string[]  $validSourceElementKeys  Every addressable key in this document's catalog.
     * @return string[] Empty when the decision is sound; one human-readable issue per problem.
     */
    public function findIssues(array $decision, array $indexContext = [], array $validSourceElementKeys = []): array
    {
        return array_merge(
            $this->findUnknownSourceElementKeys($decision, $validSourceElementKeys),
            $this->findConflictingTargets($decision),
            $this->findCreateGateViolations($decision),
            $this->findUntargetedSubstanceChanges($decision),
            $this->findDuplicateCanonicalOwnership($decision, $indexContext),
            $this->findPatchTargetAlsoPlannedAsPage($decision),
        );
    }

    /**
     * A replace/amend must be authorised by source elements that actually exist in THIS document's
     * catalog. An invented or cross-document key would let a patch claim authority it does not
     * have — the same rule planned_figures already lives under, applied to substance.
     *
     * Multi-element semantics are preserved on purpose: a target may name any number of keys, and
     * the same key may authorise more than one target. There is no one-element-one-owner rule.
     *
     * @param  array<string, mixed>  $decision
     * @param  string[]  $validSourceElementKeys
     * @return string[]
     */
    private function findUnknownSourceElementKeys(array $decision, array $validSourceElementKeys): array
    {
        if ($validSourceElementKeys === []) {
            // No catalog available (unstructured document, or a caller that cannot supply one):
            // schema validation still requires at least one key for a substantive operation, but
            // there is nothing to check them against. Never invent a failure from missing context.
            return [];
        }

        $known = array_flip(array_map('strval', $validSourceElementKeys));
        $issues = [];

        foreach ($this->patchTargets($decision) as $i => $target) {
            foreach ((array) ($target['source_element_keys'] ?? []) as $key) {
                if (! is_string($key) || trim($key) === '') {
                    continue;
                }

                if (! array_key_exists(trim($key), $known)) {
                    $issues[] = "patch_targets[{$i}].source_element_keys contains [{$key}], which is not an element of this document's source catalog — "
                        .'reference only keys the catalog lists, and never invent one.';
                }
            }
        }

        return $issues;
    }

    /**
     * Multiple targets on the SAME page are legitimate and expected — one authoritative change can
     * touch several distinct topics on one page, AND the same topic can legitimately appear under
     * more than one heading (run 27: an existing page stated the same superseded requirement in two
     * duplicated sections, and needed one target per occurrence).
     *
     * Identity is therefore (page, topic, heading) via
     * EnterpriseWikiMaintainerDecisionPrompt::patchTargetIdentity() — the same definition the merger
     * dedupes on. What is never legitimate is two targets that disagree about the same
     * page+topic+heading, or a page asserted as untouched (`preserve`) while another target changes
     * it.
     *
     * @param  array<string, mixed>  $decision
     * @return string[]
     */
    private function findConflictingTargets(array $decision): array
    {
        $issues = [];
        /** @var array<string, array{index: int, operation: string}> $seenIdentities */
        $seenIdentities = [];
        /** @var array<int, list<string>> $operationsByPage */
        $operationsByPage = [];

        foreach ($this->patchTargets($decision) as $i => $target) {
            $pageId = $target['target_page_id'] ?? null;

            if (! is_int($pageId)) {
                continue;
            }

            $operation = (string) ($target['operation'] ?? '');
            $identity = EnterpriseWikiMaintainerDecisionPrompt::patchTargetIdentity($target);
            $where = is_string($target['target_heading'] ?? null) && trim((string) $target['target_heading']) !== ''
                ? 'target_topic and target_heading'
                : 'target_topic (both without a target_heading)';

            if (isset($seenIdentities[$identity])) {
                $previous = $seenIdentities[$identity];

                $issues[] = $previous['operation'] === $operation
                    ? "patch_targets[{$i}] repeats page [{$pageId}] with the same {$where} as patch_targets[{$previous['index']}] — "
                        .'name each affected occurrence once. Two targets for the same topic are fine when they name DIFFERENT headings.'
                    : "patch_targets[{$i}] gives page [{$pageId}] operation \"{$operation}\" for the same {$where} that patch_targets[{$previous['index']}] "
                        ."gives \"{$previous['operation']}\" — one occurrence on one page cannot have two different operations.";
            } else {
                $seenIdentities[$identity] = ['index' => $i, 'operation' => $operation];
            }

            $operationsByPage[$pageId][] = $operation;
        }

        foreach ($operationsByPage as $pageId => $operations) {
            $unique = array_values(array_unique($operations));

            if (in_array('preserve', $unique, true) && count($unique) > 1) {
                $issues[] = "patch_targets assert page [{$pageId}] is preserved (left untouched) while also changing it — "
                    .'drop the preserve target, or drop the change.';
            }
        }

        return $issues;
    }

    /**
     * THE CREATE-GATE. `create` is only sound when both hold:
     *   1. no existing canonical page owns the substance, and
     *   2. the topic cannot naturally sit as a section/topic on an existing canonical page.
     *
     * `relationship` is what expresses both: only `independent_new_topic` asserts them. Every other
     * value asserts the opposite — that an existing page is the natural home — so pairing it with
     * `create` is a self-contradiction, not a judgement call.
     *
     * @param  array<string, mixed>  $decision
     * @return string[]
     */
    private function findCreateGateViolations(array $decision): array
    {
        $issues = [];

        foreach ((array) ($decision['concept_candidates'] ?? []) as $i => $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $relationship = $candidate['relationship'] ?? null;

            // Absent relationship = a stored decision predating 8K-2. Never retroactively flagged.
            if (! is_string($relationship) || $relationship === '') {
                continue;
            }

            $name = trim((string) ($candidate['name'] ?? ''));
            $decisionValue = (string) ($candidate['decision'] ?? '');
            $ctx = "concept_candidates[{$i}]";

            if ($decisionValue === 'create' && $relationship !== 'independent_new_topic') {
                $issues[] = "{$ctx} decides \"create\" for [{$name}] while classifying it as \"{$relationship}\" — "
                    .'a new canonical page requires relationship "independent_new_topic". '
                    .$this->remedyFor($relationship);
            }

            if (
                in_array($relationship, EnterpriseWikiMaintainerDecisionPrompt::EXISTING_OWNER_RELATIONSHIPS, true)
                && ! is_int($candidate['existing_owner_page_id'] ?? null)
            ) {
                $issues[] = "{$ctx} classifies [{$name}] as \"{$relationship}\", which asserts that an existing page already owns this topic — "
                    .'name that page in existing_owner_page_id, or reclassify the candidate.';
            }

            if ($relationship === 'independent_new_topic' && is_int($candidate['existing_owner_page_id'] ?? null)) {
                $issues[] = "{$ctx} classifies [{$name}] as \"independent_new_topic\" but also names existing_owner_page_id "
                    ."[{$candidate['existing_owner_page_id']}] — a topic an existing page owns is not independent.";
            }
        }

        return $issues;
    }

    /**
     * The anti-shadow-channel rule.
     *
     * Classifying a candidate as `substance_changed` is exactly the finding that, in the run
     * observed after 8K-1, existed only as a sentence in `warnings`. If the maintainer sees that
     * new substance supersedes what an existing page states, that must arrive as a structured
     * patch target for that page — otherwise the finding is once again unreadable to every
     * downstream step, and 8K-3 has nothing to act on.
     *
     * `warnings` keeps its job (genuine non-actionable concerns). It just stops being the only
     * place an actionable patch need can live.
     *
     * @param  array<string, mixed>  $decision
     * @return string[]
     */
    private function findUntargetedSubstanceChanges(array $decision): array
    {
        $targetedPageIds = array_flip(EnterpriseWikiPatchTargetResolver::targetPageIds($decision));
        $issues = [];

        foreach ((array) ($decision['concept_candidates'] ?? []) as $i => $candidate) {
            if (! is_array($candidate) || ($candidate['relationship'] ?? null) !== 'substance_changed') {
                continue;
            }

            $ownerId = $candidate['existing_owner_page_id'] ?? null;

            if (! is_int($ownerId) || array_key_exists($ownerId, $targetedPageIds)) {
                continue;
            }

            $name = trim((string) ($candidate['name'] ?? ''));

            $issues[] = "concept_candidates[{$i}] classifies [{$name}] as \"substance_changed\" on existing page [{$ownerId}], but patch_targets has no target for that page — "
                .'an identified change to existing substance must be a structured patch target, not a warning or a comment.';
        }

        return $issues;
    }

    /**
     * Rule 1 as a standalone check, independent of the candidate list: a canonical page must not be
     * CREATED under a title an existing page already carries. This catches the entity_pages route
     * too, which has no candidate enumeration to hang the create-gate on.
     *
     * Exact normalized-title matching only — case, whitespace and punctuation drift, nothing
     * semantic. Detecting that two differently-named pages own the same substance is a semantic
     * problem and belongs to 8K-4, not here.
     *
     * @param  array<string, mixed>  $decision
     * @param  array<int, array<string, mixed>>  $indexContext
     * @return string[]
     */
    private function findDuplicateCanonicalOwnership(array $decision, array $indexContext): array
    {
        if ($indexContext === []) {
            return [];
        }

        $existing = [];

        foreach ($indexContext as $page) {
            $title = $this->normalize((string) ($page['title'] ?? ''));

            if ($title !== '') {
                $existing[$title] = (int) ($page['id'] ?? 0);
            }
        }

        $issues = [];

        foreach (self::CANONICAL_PAGE_KEYS as $key) {
            foreach ((array) ($decision[$key] ?? []) as $i => $entry) {
                if (! is_array($entry) || ($entry['action'] ?? 'create') !== 'create') {
                    continue;
                }

                $title = $this->normalize((string) ($entry['title'] ?? ''));

                if ($title === '' || ! array_key_exists($title, $existing)) {
                    continue;
                }

                $issues[] = "{$key}[{$i}] creates a new canonical page titled [{$entry['title']}] while page [{$existing[$title]}] already carries that title — "
                    .'reuse the existing page (action "update" with its page_id) or patch it; never create a second owner for the same topic.';
            }
        }

        return $issues;
    }

    /**
     * A page cannot simultaneously be a structured patch target and a planned page entry for this
     * run. That combination is the exact ambiguity that produced the destructive rewrite observed
     * after 8K-1: the page-entry route dispatches full page generation from the new source document
     * alone, which would discard the very content the patch target is trying to preserve.
     *
     * Rejecting it here means the apply layer never has to choose between two contradictory
     * intents for one page.
     *
     * @param  array<string, mixed>  $decision
     * @return string[]
     */
    private function findPatchTargetAlsoPlannedAsPage(array $decision): array
    {
        $targetedPageIds = EnterpriseWikiPatchTargetResolver::targetPageIds($decision);

        if ($targetedPageIds === []) {
            return [];
        }

        $targeted = array_flip($targetedPageIds);
        $issues = [];

        foreach (self::CANONICAL_PAGE_KEYS as $key) {
            foreach ((array) ($decision[$key] ?? []) as $i => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $pageId = $entry['page_id'] ?? null;

                if (! is_int($pageId) || ! array_key_exists($pageId, $targeted)) {
                    continue;
                }

                $issues[] = "{$key}[{$i}] plans page [{$pageId}] as a generated page for this run while patch_targets also targets it — "
                    .'a page is either regenerated as this run\'s own page or patched as existing knowledge, never both.';
            }
        }

        return $issues;
    }

    /**
     * The decision's patch targets, keyed by their original index so every issue can point at the
     * exact entry. Non-array entries are dropped — schema validation has already reported those.
     *
     * @param  array<string, mixed>  $decision
     * @return array<int, array<string, mixed>>
     */
    private function patchTargets(array $decision): array
    {
        $targets = [];

        foreach ((array) ($decision['patch_targets'] ?? []) as $i => $target) {
            if (is_array($target)) {
                $targets[(int) $i] = $target;
            }
        }

        return $targets;
    }

    private function remedyFor(string $relationship): string
    {
        return match ($relationship) {
            'substance_changed' => 'Add a patch target with operation "replace" on the page that owns the substance instead.',
            'topic_extended' => 'Add a patch target with operation "amend" on the page that owns the topic instead.',
            'topic_specialized' => 'Add a patch target with operation "amend" on the page that owns the broader topic instead — a variant or sub-topic belongs there.',
            'reference_only' => 'Decide "reference_only" and link to the owning page instead.',
            default => 'Reclassify the candidate, or target the existing owner.',
        };
    }

    private function normalize(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $value = preg_replace('/[^\p{L}\p{N} ]+/u', '', $value) ?? $value;

        return mb_strtolower(trim($value));
    }
}
